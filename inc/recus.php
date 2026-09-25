<?php
/**
 * Reçus de paiement bancaire : envoi par l'étudiant, consultation protégée.
 *
 * Après avoir payé, l'étudiant photographie ou scanne ses reçus et les
 * envoie depuis son espace, avant la vérification physique à la scolarité.
 *
 * Sécurité :
 *   - type contrôlé sur le contenu du fichier (finfo), pas sur l'extension ;
 *   - les photos sont réencodées en JPEG (orientation corrigée, métadonnées
 *     et contenu caché éliminés), les PDF compressés par Ghostscript ;
 *   - chaque fichier est renommé CODE-JJ-MM-AAAA-HH-MM-SS-prenom (DU1, DU2,
 *     DU pour la totalité, FM pour les frais médicaux) ;
 *   - stockage hors de portée directe (uploads/ueb-recus, accès web refusé),
 *     affichage uniquement via /recu/{id} pour le propriétaire ou un admin.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

const UEB_RECUS_MAX_FICHIERS = 3;
const UEB_RECUS_MAX_OCTETS   = 5 * MB_IN_BYTES;
const UEB_RECUS_TYPES        = array( 'image/jpeg', 'image/png', 'application/pdf' );

function ueb_dossier_recus() {
	$base    = wp_upload_dir( null, false )['basedir'] . '/ueb-recus';
	$garde   = $base . '/.htaccess';
	if ( ! is_dir( $base ) ) {
		wp_mkdir_p( $base );
	}
	if ( ! file_exists( $garde ) ) {
		file_put_contents( $garde, "Options -Indexes\nRequire all denied\nDeny from all\n" );
		file_put_contents( $base . '/index.php', "<?php // Silence.\n" );
	}
	return $base;
}

/* Code du reçu dans le nom du fichier : DU1 / DU2 (tranches), DU (totalité), FM (frais médicaux). */
const UEB_CODES_OBJET_RECU = array( 'tranche1' => 'DU1', 'tranche2' => 'DU2', 'totalite' => 'DU', 'medicaux' => 'FM' );

/**
 * Nom d'un reçu enregistré : CODE-JJ-MM-AAAA-HH-MM-SS-prenom.ext, par exemple
 * « DU1-12-11-2026-12-13-32-neo.jpg ». Les « / » et « : » d'une date ne sont
 * pas permis dans un nom de fichier : tirets partout. Si deux reçus tombent
 * dans la même seconde, un suffixe -2, -3… les départage.
 */
function ueb_nom_fichier_recu( $objet, $prenom, $extension, $dossier ) {
	$code   = UEB_CODES_OBJET_RECU[ $objet ] ?? 'RECU';
	$prenom = strtolower( preg_replace( '/[^A-Za-z0-9]+/', '', remove_accents( (string) strtok( trim( (string) $prenom ), ' ' ) ) ) );
	/* Heure du Cameroun, quel que soit le fuseau réglé dans WordPress. */
	$horodatage = ( new DateTimeImmutable( 'now', new DateTimeZone( 'Africa/Douala' ) ) )->format( 'd-m-Y-H-i-s' );
	$base   = $code . '-' . $horodatage . ( '' !== $prenom ? '-' . substr( $prenom, 0, 30 ) : '' );
	$nom    = $base . '.' . $extension;
	for ( $n = 2; file_exists( $dossier . '/' . $nom ); $n++ ) {
		$nom = $base . '-' . $n . '.' . $extension;
	}
	return $nom;
}

/**
 * Compresse un PDF avec Ghostscript (qualité « ebook », 150 dpi), en mode
 * -dSAFER et limité à 30 s. Garde le résultat seulement s'il est un PDF
 * valide et plus léger ; sinon (Ghostscript absent, échec) le fichier
 * d'origine est conservé tel quel.
 */
function ueb_compresser_pdf( $chemin ) {
	if ( ! function_exists( 'exec' ) ) {
		return false;
	}
	$gs = '';
	foreach ( array( '/usr/bin/gs', '/usr/local/bin/gs' ) as $candidat ) {
		if ( is_executable( $candidat ) ) {
			$gs = $candidat;
			break;
		}
	}
	if ( ! $gs ) {
		return false;
	}
	$sortie  = $chemin . '.compresse.pdf';
	$limite  = is_executable( '/usr/bin/timeout' ) ? '/usr/bin/timeout 30 ' : '';
	/* XAMPP impose ses propres bibliothèques (LD_LIBRARY_PATH=/opt/lampp/lib),
	   trop anciennes pour le Ghostscript du système : on les retire pour lui. */
	$environnement = is_executable( '/usr/bin/env' ) ? '/usr/bin/env -u LD_LIBRARY_PATH ' : '';
	$commande = $environnement . $limite . escapeshellarg( $gs ) . ' -q -dSAFER -dBATCH -dNOPAUSE -sDEVICE=pdfwrite -dCompatibilityLevel=1.5 -dPDFSETTINGS=/ebook'
		. ' -sOutputFile=' . escapeshellarg( $sortie ) . ' ' . escapeshellarg( $chemin ) . ' 2>/dev/null';
	exec( $commande, $lignes, $code );
	$valide = 0 === $code && is_file( $sortie ) && filesize( $sortie ) > 0 && filesize( $sortie ) < filesize( $chemin )
		&& '%PDF-' === (string) file_get_contents( $sortie, false, null, 0, 5 );
	if ( $valide ) {
		return rename( $sortie, $chemin );
	}
	if ( is_file( $sortie ) ) {
		wp_delete_file( $sortie );
	}
	return false;
}

/** Ce qu'un reçu peut payer. */
const UEB_OBJETS_RECU = array(
	'tranche1' => array( 'libelle' => 'Première tranche', 'aide' => 'Le reçu de ton premier versement.' ),
	'tranche2' => array( 'libelle' => 'Deuxième tranche', 'aide' => 'Le reçu de ton second versement.' ),
	'totalite' => array( 'libelle' => 'Totalité', 'aide' => 'Un seul reçu pour les deux tranches.' ),
	'medicaux' => array( 'libelle' => 'Frais médicaux', 'aide' => 'Le reçu de la visite médicale.' ),
);

/**
 * Objets possibles pour un reçu de ce quitus : un quitus « deux tranches »
 * se paie en une fois (totalité) ou en deux reçus ; les autres n'ont qu'un
 * objet, affiché présélectionné.
 */
function ueb_objets_recu( $quitus ) {
	if ( 'medicaux' === ( $quitus->type ?? 'droits' ) ) {
		$cles = array( 'medicaux' );
	} else {
		$cles = array( 1 => array( 'tranche1' ), 2 => array( 'tranche2' ), 3 => array( 'tranche1', 'tranche2', 'totalite' ) )[ (int) $quitus->tranche ] ?? array( 'tranche1' );
	}
	return array_intersect_key( UEB_OBJETS_RECU, array_flip( $cles ) );
}

/** Libellé de l'objet d'un reçu ; les reçus antérieurs au choix affichent le type du quitus. */
function ueb_libelle_objet_recu( $recu, $type_quitus = 'droits' ) {
	return UEB_OBJETS_RECU[ $recu->objet ?? '' ]['libelle'] ?? ueb_libelle_type_quitus( $type_quitus );
}

function ueb_recus_du_quitus( $quitus_id ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ueb_insc_recus WHERE quitus_id = %d ORDER BY date_envoi', $quitus_id ) );
}

/** Tous les reçus d'un étudiant, avec leur quitus et leur statut de suivi. */
function ueb_recus_du_compte( $compte_id ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare(
		"SELECT r.*, q.numero, q.type AS type_quitus, q.montant AS montant_quitus,
		        q.annee_academique, q.etablissement, q.statut AS statut_quitus, q.motif_rejet,
		        q.parcours, q.departement
		   FROM ueb_insc_recus r
		   JOIN ueb_insc_quitus q ON q.id = r.quitus_id
		  WHERE r.compte_id = %d
		  ORDER BY q.annee_academique DESC, r.date_envoi DESC, r.id DESC",
		$compte_id
	) );
}

function ueb_quitus_accepte_recus( $quitus ) {
	return in_array( $quitus->statut, array( 'genere', 'recu_envoye', 'rejete' ), true );
}

/** Liste « à plat » des fichiers d'un champ <input type="file" multiple>. */
function ueb_fichiers_envoyes( $champ ) {
	$f = $_FILES[ $champ ] ?? null;
	if ( ! $f || ! is_array( $f['name'] ) ) {
		return array();
	}
	$liste = array();
	foreach ( $f['name'] as $i => $nom ) {
		if ( UPLOAD_ERR_NO_FILE === $f['error'][ $i ] ) {
			continue;
		}
		$liste[] = array( 'nom' => $nom, 'tmp' => $f['tmp_name'][ $i ], 'erreur' => $f['error'][ $i ], 'taille' => $f['size'][ $i ] );
	}
	return $liste;
}

/**
 * Réencode une photo en JPEG (2 200 px au plus, orientation EXIF appliquée).
 * @return bool
 */
function ueb_reencoder_photo( $source, $type, $destination ) {
	$image = 'image/png' === $type ? @imagecreatefrompng( $source ) : @imagecreatefromjpeg( $source );
	if ( ! $image ) {
		return false;
	}
	if ( 'image/jpeg' === $type && function_exists( 'exif_read_data' ) ) {
		$exif = @exif_read_data( $source );
		$rotations = array( 3 => 180, 6 => -90, 8 => 90 );
		if ( ! empty( $exif['Orientation'] ) && isset( $rotations[ $exif['Orientation'] ] ) ) {
			$image = imagerotate( $image, $rotations[ $exif['Orientation'] ], 0 );
		}
	}
	$l = imagesx( $image );
	$h = imagesy( $image );
	$max = 2200;
	if ( max( $l, $h ) > $max ) {
		$ratio = $max / max( $l, $h );
		$image = imagescale( $image, (int) round( $l * $ratio ), (int) round( $h * $ratio ), IMG_BICUBIC );
	}
	/* Fond blanc pour les PNG transparents. */
	$final = imagecreatetruecolor( imagesx( $image ), imagesy( $image ) );
	imagefill( $final, 0, 0, imagecolorallocate( $final, 255, 255, 255 ) );
	imagecopy( $final, $image, 0, 0, 0, 0, imagesx( $image ), imagesy( $image ) );
	$ok = imagejpeg( $final, $destination, 85 );
	imagedestroy( $image );
	imagedestroy( $final );
	return $ok;
}

function ueb_action_envoyer_recus() {
	global $wpdb;
	$compte = ueb_compte_courant();
	if ( ! $compte ) {
		ueb_rediriger( ueb_url( 'connexion' ) );
	}
	$quitus = ueb_quitus_du_compte_par_numero( $compte->id, sanitize_text_field( wp_unslash( $_POST['numero'] ?? '' ) ) );
	if ( ! $quitus ) {
		ueb_rediriger( ueb_url( 'mon-espace' ) );
	}
	$retour = ueb_url( 'mon-espace/recus/' . $quitus->numero );
	if ( ! ueb_quitus_accepte_recus( $quitus ) ) {
		ueb_flash( 'erreur', 'Ce quitus a déjà été vérifié : plus aucun reçu ne peut être ajouté.' );
		ueb_rediriger( $retour );
	}

	$objets = ueb_objets_recu( $quitus );
	$objet  = sanitize_key( $_POST['objet'] ?? '' );
	if ( 1 === count( $objets ) ) {
		$objet = array_key_first( $objets );
	}
	if ( ! isset( $objets[ $objet ] ) ) {
		ueb_flash( 'erreur', 'Indique ce que paie ce reçu : ' . mb_strtolower( implode( ', ', array_column( $objets, 'libelle' ) ) ) . '.' );
		ueb_rediriger( $retour );
	}

	$fichiers = ueb_fichiers_envoyes( 'recus' );
	$deja     = count( ueb_recus_du_quitus( $quitus->id ) );
	if ( ! $fichiers ) {
		ueb_flash( 'erreur', 'Choisis au moins une photo ou un scan de ton reçu.' );
		ueb_rediriger( $retour );
	}
	if ( $deja + count( $fichiers ) > UEB_RECUS_MAX_FICHIERS ) {
		ueb_flash( 'erreur', sprintf( '%d fichiers au maximum par quitus. Il te reste %d envoi(s) possible(s).', UEB_RECUS_MAX_FICHIERS, max( 0, UEB_RECUS_MAX_FICHIERS - $deja ) ) );
		ueb_rediriger( $retour );
	}

	$dossier = ueb_dossier_recus() . '/' . $quitus->annee_academique;
	wp_mkdir_p( $dossier );
	$finfo    = new finfo( FILEINFO_MIME_TYPE );
	$acceptes = 0;
	$refus    = array();

	foreach ( $fichiers as $f ) {
		$nom = sanitize_file_name( $f['nom'] );
		if ( UPLOAD_ERR_OK !== $f['erreur'] || ! is_uploaded_file( $f['tmp'] ) ) {
			$refus[] = "$nom : envoi interrompu";
			continue;
		}
		if ( $f['taille'] > UEB_RECUS_MAX_OCTETS ) {
			$refus[] = "$nom : plus de 5 Mo";
			continue;
		}
		$type = $finfo->file( $f['tmp'] );
		if ( ! in_array( $type, UEB_RECUS_TYPES, true ) ) {
			$refus[] = "$nom : format non accepté (JPG, PNG ou PDF)";
			continue;
		}

		/* Nom normalisé : code du reçu, date et heure d'envoi, prénom de l'étudiant. */
		$nom_final = ueb_nom_fichier_recu( $objet, $quitus->prenom, 'application/pdf' === $type ? 'pdf' : 'jpg', $dossier );
		$chemin    = $dossier . '/' . $nom_final;
		if ( 'application/pdf' === $type ) {
			$debut = (string) file_get_contents( $f['tmp'], false, null, 0, 5 );
			if ( '%PDF-' !== $debut || ! move_uploaded_file( $f['tmp'], $chemin ) ) {
				$refus[] = "$nom : PDF illisible";
				continue;
			}
			ueb_compresser_pdf( $chemin );
		} elseif ( ! ueb_reencoder_photo( $f['tmp'], $type, $chemin ) ) {
			$refus[] = "$nom : image illisible";
			continue;
		} else {
			$type = 'image/jpeg';
		}

		$wpdb->insert( 'ueb_insc_recus', array(
			'quitus_id'    => $quitus->id,
			'compte_id'    => $compte->id,
			'fichier'      => $quitus->annee_academique . '/' . basename( $chemin ),
			'nom_original' => $nom_final,
			'type_mime'    => $type,
			'taille'       => (int) filesize( $chemin ),
			'objet'        => $objet,
		) );
		$acceptes++;
	}

	if ( $acceptes ) {
		$wpdb->update( 'ueb_insc_quitus', array( 'statut' => 'recu_envoye', 'motif_rejet' => null ), array( 'id' => $quitus->id ) );
		ueb_flash( 'succes', sprintf( '%d reçu(s) envoyé(s). Présente-toi à la scolarité avec les originaux pour la vérification.', $acceptes ) );
	}
	if ( $refus ) {
		ueb_flash( 'erreur', 'Fichier(s) refusé(s) — ' . implode( ' ; ', $refus ) . '.' );
	}
	ueb_rediriger( $retour );
}

function ueb_action_supprimer_recu() {
	global $wpdb;
	$compte = ueb_compte_courant();
	if ( ! $compte ) {
		ueb_rediriger( ueb_url( 'connexion' ) );
	}
	$recu   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ueb_insc_recus WHERE id = %d AND compte_id = %d', (int) ( $_POST['recu_id'] ?? 0 ), $compte->id ) );
	$quitus = $recu ? ueb_quitus_par_id( $recu->quitus_id ) : null;
	if ( ! $recu || ! $quitus ) {
		ueb_rediriger( ueb_url( 'mon-espace' ) );
	}
	$retour = ueb_url( 'mon-espace/recus/' . $quitus->numero );
	if ( 'verifie' === $quitus->statut ) {
		ueb_flash( 'erreur', 'Ce quitus est vérifié : ses reçus ne peuvent plus être supprimés.' );
		ueb_rediriger( $retour );
	}
	$chemin = ueb_dossier_recus() . '/' . $recu->fichier;
	if ( is_file( $chemin ) ) {
		wp_delete_file( $chemin );
	}
	$wpdb->delete( 'ueb_insc_recus', array( 'id' => $recu->id ) );
	if ( ! ueb_recus_du_quitus( $quitus->id ) && 'recu_envoye' === $quitus->statut ) {
		$wpdb->update( 'ueb_insc_quitus', array( 'statut' => 'genere' ), array( 'id' => $quitus->id ) );
	}
	ueb_flash( 'succes', 'Reçu supprimé.' );
	ueb_rediriger( $retour );
}

/** Affiche un reçu à son propriétaire ou à un administrateur. */
/**
 * Qui peut voir un reçu : l'étudiant qui l'a envoyé, un administrateur, ou
 * un agent de scolarité actif de l'établissement du quitus concerné.
 */
function ueb_peut_voir_recu( $recu, $compte ) {
	if ( ! $recu ) {
		return false;
	}
	if ( $compte && (int) $recu->compte_id === (int) $compte->id ) {
		return true;
	}
	if ( current_user_can( 'manage_options' ) ) {
		return true;
	}
	/* Agent : capacité d'examiner les quitus ET portée couvrant l'établissement du quitus. */
	$quitus = ueb_quitus_par_id( (int) $recu->quitus_id );
	if ( $quitus && ueb_peut( UEB_CAP_GESTION, $quitus->etablissement ) ) {
		return true;
	}
	return false;
}

/** Adresse d'un reçu : affichage dans le navigateur, ou téléchargement. */
function ueb_url_recu( $id, $telecharger = false ) {
	$url = ueb_url( 'recu/' . (int) $id );
	return $telecharger ? add_query_arg( 'telecharger', '1', $url ) : $url;
}

/** Affiche (ou fait télécharger, avec ?telecharger=1) un reçu à qui peut le voir. */
function ueb_servir_recu( $compte, $id ) {
	global $wpdb;
	$recu = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ueb_insc_recus WHERE id = %d', $id ) );
	$autorise = ueb_peut_voir_recu( $recu, $compte );
	/* Les reçus sont rangés par année (« 2026-2027/FS-2627-000001-….jpg ») :
	   on n'accepte que ce format exact, puis on vérifie que le chemin réel
	   reste dans le dossier des reçus. */
	$base        = realpath( ueb_dossier_recus() );
	$fichier     = $recu ? (string) $recu->fichier : '';
	$format_ok   = (bool) preg_match( '~^(\d{4}-\d{4}/)?[A-Za-z0-9-]+\.(jpg|pdf)$~', $fichier );
	$chemin_reel = $base && $format_ok ? realpath( $base . DIRECTORY_SEPARATOR . $fichier ) : false;
	if ( ! $autorise || ! $chemin_reel || ! str_starts_with( $chemin_reel, $base . DIRECTORY_SEPARATOR ) || ! is_file( $chemin_reel ) ) {
		status_header( 404 );
		wp_die( 'Reçu introuvable.', 'Reçu introuvable', array( 'response' => 404 ) );
	}
	$type_mime = in_array( (string) $recu->type_mime, UEB_RECUS_TYPES, true ) ? (string) $recu->type_mime : 'application/octet-stream';
	nocache_headers();
	header( 'Content-Type: ' . $type_mime );
	header( 'Content-Length: ' . filesize( $chemin_reel ) );
	/* Nom de fichier : celui choisi par l'étudiant, avec l'extension du fichier réellement stocké. */
	$extension = 'application/pdf' === $type_mime ? 'pdf' : 'jpg';
	$base_nom  = sanitize_file_name( (string) pathinfo( (string) $recu->nom_original, PATHINFO_FILENAME ) ) ?: 'recu-' . (int) $recu->id;
	$mode      = empty( $_GET['telecharger'] ) ? 'inline' : 'attachment';
	header( 'Content-Disposition: ' . $mode . '; filename="' . $base_nom . '.' . $extension . '"; filename*=UTF-8\'\'' . rawurlencode( $base_nom . '.' . $extension ) );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'X-Download-Options: noopen' );
	header( "Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox" );
	readfile( $chemin_reel );
}
