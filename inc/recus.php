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
 *     et contenu caché éliminés) ;
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

function ueb_recus_du_quitus( $quitus_id ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ueb_insc_recus WHERE quitus_id = %d ORDER BY date_envoi', $quitus_id ) );
}

/** Tous les reçus d'un étudiant, avec leur quitus et leur statut de suivi. */
function ueb_recus_du_compte( $compte_id ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare(
		"SELECT r.*, q.numero, q.type AS type_quitus, q.montant AS montant_quitus,
		        q.annee_academique, q.etablissement, q.statut AS statut_quitus,
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

		$base   = $quitus->numero . '-' . bin2hex( random_bytes( 8 ) );
		$chemin = $dossier . '/' . $base . ( 'application/pdf' === $type ? '.pdf' : '.jpg' );
		if ( 'application/pdf' === $type ) {
			$debut = (string) file_get_contents( $f['tmp'], false, null, 0, 5 );
			if ( '%PDF-' !== $debut || ! move_uploaded_file( $f['tmp'], $chemin ) ) {
				$refus[] = "$nom : PDF illisible";
				continue;
			}
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
			'nom_original' => mb_substr( $nom, 0, 255 ),
			'type_mime'    => $type,
			'taille'       => (int) filesize( $chemin ),
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
function ueb_servir_recu( $compte, $id ) {
	global $wpdb;
	$recu = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ueb_insc_recus WHERE id = %d', $id ) );
	$autorise = $recu && ( ( $compte && (int) $recu->compte_id === (int) $compte->id ) || current_user_can( 'manage_options' ) );
	$base     = realpath( ueb_dossier_recus() );
	$nom      = $recu ? basename( (string) $recu->fichier ) : '';
	$chemin   = $base && $recu && $nom === (string) $recu->fichier ? $base . DIRECTORY_SEPARATOR . $nom : '';
	$chemin_reel = $chemin ? realpath( $chemin ) : false;
	if ( ! $autorise || ! $chemin_reel || dirname( $chemin_reel ) !== $base || ! is_file( $chemin_reel ) ) {
		status_header( 404 );
		wp_die( 'Reçu introuvable.', 'Reçu introuvable', array( 'response' => 404 ) );
	}
	$type_mime = in_array( (string) $recu->type_mime, UEB_RECUS_TYPES, true ) ? (string) $recu->type_mime : 'application/octet-stream';
	nocache_headers();
	header( 'Content-Type: ' . $type_mime );
	header( 'Content-Length: ' . filesize( $chemin_reel ) );
	header( 'Content-Disposition: inline; filename="recu-' . (int) $recu->id . ( 'application/pdf' === $type_mime ? '.pdf' : '.jpg' ) . '"' );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'X-Download-Options: noopen' );
	header( "Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox" );
	readfile( $chemin_reel );
}
