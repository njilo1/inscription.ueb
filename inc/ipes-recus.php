<?php
/**
 * Reçus bancaires d'un bordereau d'IPES : la preuve du virement à la tutelle.
 *
 * Un bordereau ne part pas sans au moins un reçu (UEB_IPES_RECUS_MAX au plus).
 * Tant qu'il est modifiable (brouillon, ou rejeté), l'IPES ajoute et retire
 * ses reçus ; envoyé, ils sont figés avec lui.
 *
 * Mêmes garanties que les reçus des étudiants (inc/recus.php) :
 *   - type contrôlé sur le contenu du fichier (finfo), pas sur l'extension ;
 *   - photos réencodées en JPEG (métadonnées et contenu caché éliminés), PDF
 *     vérifiés puis compressés par Ghostscript s'il est présent ;
 *   - stockage dans le dossier fermé au web (uploads/ueb-recus/ipes/{année}),
 *     sous un nom construit par le serveur (REC-{bordereau}-{horodatage}) ;
 *   - lecture uniquement via /recu-ipes/{id}, pour l'IPES propriétaire,
 *     l'administration de l'UEb et la tutelle destinataire (bordereau envoyé).
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

const UEB_IPES_RECUS_MAX = 3;

/* ---------- Lecture ---------- */

/** Reçus d'un bordereau de CET IPES, dans l'ordre d'envoi. */
function ueb_ipes_recus( $ipes_id, $bordereau_id ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare(
		'SELECT * FROM ueb_insc_ipes_recus WHERE ipes_id = %d AND bordereau_id = %d ORDER BY date_envoi, id',
		$ipes_id, $bordereau_id
	) );
}

/** Nombre de reçus d'un bordereau. */
function ueb_ipes_nb_recus( $ipes_id, $bordereau_id ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare(
		'SELECT COUNT(*) FROM ueb_insc_ipes_recus WHERE ipes_id = %d AND bordereau_id = %d',
		$ipes_id, $bordereau_id
	) );
}

/** Dossier des reçus des IPES (dans le dossier fermé des reçus), créé au besoin. */
function ueb_ipes_dossier_recus() {
	$dossier = ueb_dossier_recus() . '/ipes';
	if ( ! is_dir( $dossier ) ) {
		wp_mkdir_p( $dossier );
	}
	return $dossier;
}

/* ---------- Écriture ---------- */

/**
 * Enregistre un fichier comme reçu d'un bordereau modifiable. Le fichier
 * source a déjà été reçu par PHP (l'action vérifie is_uploaded_file) : il est
 * contrôlé, réencodé ou copié, jamais déplacé tel quel.
 *
 * @param string $source Chemin du fichier reçu.
 * @param string $nom    Nom d'origine, pour les messages.
 * @param int    $taille Taille annoncée, en octets.
 * @return int|WP_Error Identifiant du reçu.
 */
function ueb_ipes_recu_ajouter( $ipes_id, $bordereau_id, $source, $nom, $taille ) {
	global $wpdb;
	$bordereau = ueb_ipes_bordereau( $ipes_id, $bordereau_id );
	if ( ! $bordereau ) {
		return new WP_Error( 'ueb_ipes_recu', 'Ce bordereau n’existe pas.' );
	}
	if ( ! in_array( $bordereau->statut, UEB_IPES_BORDEREAU_MODIFIABLE, true ) ) {
		return new WP_Error( 'ueb_ipes_recu', 'Ce bordereau a été envoyé : ses reçus ne changent plus.' );
	}
	if ( ueb_ipes_nb_recus( $ipes_id, $bordereau_id ) >= UEB_IPES_RECUS_MAX ) {
		return new WP_Error( 'ueb_ipes_recu', sprintf( '%s : %d reçus au plus par bordereau.', $nom, UEB_IPES_RECUS_MAX ) );
	}
	if ( $taille > UEB_RECUS_MAX_OCTETS || filesize( $source ) > UEB_RECUS_MAX_OCTETS ) {
		return new WP_Error( 'ueb_ipes_recu', "$nom : plus de 5 Mo." );
	}
	$type = ( new finfo( FILEINFO_MIME_TYPE ) )->file( $source );
	if ( ! in_array( $type, UEB_RECUS_TYPES, true ) ) {
		return new WP_Error( 'ueb_ipes_recu', "$nom : format non accepté (JPG, PNG ou PDF)." );
	}

	$annee   = $bordereau->annee_academique;
	$dossier = ueb_ipes_dossier_recus() . '/' . $annee;
	wp_mkdir_p( $dossier );
	$pdf        = 'application/pdf' === $type;
	$extension  = $pdf ? 'pdf' : 'jpg';
	$horodatage = ( new DateTimeImmutable( 'now', new DateTimeZone( 'Africa/Douala' ) ) )->format( 'd-m-Y-H-i-s' );
	$base       = 'REC-' . (int) $bordereau_id . '-' . $horodatage;
	$fichier    = $base . '.' . $extension;
	for ( $n = 2; file_exists( $dossier . '/' . $fichier ); $n++ ) {
		$fichier = $base . '-' . $n . '.' . $extension;
	}
	$chemin = $dossier . '/' . $fichier;

	if ( $pdf ) {
		if ( '%PDF-' !== (string) file_get_contents( $source, false, null, 0, 5 ) || ! copy( $source, $chemin ) ) {
			return new WP_Error( 'ueb_ipes_recu', "$nom : PDF illisible." );
		}
		ueb_compresser_pdf( $chemin );
	} elseif ( ! ueb_reencoder_photo( $source, $type, $chemin ) ) {
		return new WP_Error( 'ueb_ipes_recu', "$nom : image illisible." );
	} else {
		$type = 'image/jpeg';
	}

	/* Verrou sur le bordereau : un envoi simultané, ou un autre ajout, attend ;
	   statut et plafond sont revérifiés sous ce verrou. */
	$wpdb->query( 'START TRANSACTION' );
	$statut = $wpdb->get_var( $wpdb->prepare( 'SELECT statut FROM ueb_insc_ipes_bordereaux WHERE id = %d AND ipes_id = %d FOR UPDATE', $bordereau_id, $ipes_id ) );
	$rang   = ueb_ipes_nb_recus( $ipes_id, $bordereau_id ) + 1;
	if ( ! in_array( $statut, UEB_IPES_BORDEREAU_MODIFIABLE, true ) || $rang > UEB_IPES_RECUS_MAX ) {
		$wpdb->query( 'ROLLBACK' );
		wp_delete_file( $chemin );
		return new WP_Error( 'ueb_ipes_recu', $rang > UEB_IPES_RECUS_MAX ? sprintf( '%s : %d reçus au plus par bordereau.', $nom, UEB_IPES_RECUS_MAX ) : 'Ce bordereau a été envoyé : ses reçus ne changent plus.' );
	}
	/* Nom affiché : le numéro du bordereau (ou son brouillon) et le rang du reçu. */
	$affiche = sanitize_file_name( ( str_starts_with( $bordereau->numero, 'BROUILLON-' ) ? 'brouillon-' . (int) $bordereau->id : $bordereau->numero ) . '-recu-' . $rang ) . '.' . $extension;
	$ok      = $wpdb->insert( 'ueb_insc_ipes_recus', array(
		'bordereau_id' => (int) $bordereau_id,
		'ipes_id'      => (int) $ipes_id,
		'fichier'      => $annee . '/' . $fichier,
		'nom_original' => $affiche,
		'type_mime'    => $type,
		'taille'       => (int) filesize( $chemin ),
		'envoye_par'   => get_current_user_id() ?: null,
	) );
	if ( ! $ok ) {
		$wpdb->query( 'ROLLBACK' );
		wp_delete_file( $chemin );
		return new WP_Error( 'ueb_ipes_recu', "$nom : le reçu n’a pas pu être enregistré. Réessaie dans un instant." );
	}
	$recu_id = (int) $wpdb->insert_id;
	$wpdb->query( 'COMMIT' );
	return $recu_id;
}

/** Supprime le fichier d'un reçu (ligne déjà lue), s'il est bien dans le dossier des IPES. */
function ueb_ipes_recu_effacer_fichier( $recu ) {
	$chemin = ueb_ipes_chemin_recu( $recu );
	if ( $chemin ) {
		wp_delete_file( $chemin );
	}
}

/** Retire un reçu d'un bordereau encore modifiable. @return true|WP_Error */
function ueb_ipes_recu_supprimer( $ipes_id, $id ) {
	global $wpdb;
	$recu      = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ueb_insc_ipes_recus WHERE id = %d AND ipes_id = %d', $id, $ipes_id ) );
	$bordereau = $recu ? ueb_ipes_bordereau( $ipes_id, $recu->bordereau_id ) : null;
	if ( ! $recu || ! $bordereau ) {
		return new WP_Error( 'ueb_ipes_recu', 'Ce reçu n’existe pas.' );
	}
	if ( ! in_array( $bordereau->statut, UEB_IPES_BORDEREAU_MODIFIABLE, true ) ) {
		return new WP_Error( 'ueb_ipes_recu', 'Ce bordereau a été envoyé : ses reçus ne changent plus.' );
	}
	/* Condition sur le statut dans la même requête : un envoi arrivé entre-temps l'emporte. */
	$supprime = $wpdb->query( $wpdb->prepare(
		"DELETE r FROM ueb_insc_ipes_recus r
		JOIN ueb_insc_ipes_bordereaux b ON b.id = r.bordereau_id AND b.ipes_id = r.ipes_id
		WHERE r.id = %d AND r.ipes_id = %d AND b.statut IN ('brouillon','rejete')",
		$recu->id, $ipes_id
	) );
	if ( 1 !== (int) $supprime ) {
		return new WP_Error( 'ueb_ipes_recu', 'Ce bordereau a été envoyé : ses reçus ne changent plus.' );
	}
	ueb_ipes_recu_effacer_fichier( $recu );
	return true;
}

/* ---------- Lecture protégée : /recu-ipes/{id} ---------- */

/** Adresse d'un reçu d'IPES : affichage, ou téléchargement. */
function ueb_url_recu_ipes( $id, $telecharger = false ) {
	$url = ueb_url( 'recu-ipes/' . (int) $id );
	return $telecharger ? add_query_arg( 'telecharger', '1', $url ) : $url;
}

/**
 * Qui voit un reçu : l'administrateur de l'IPES propriétaire, l'administration
 * de l'UEb, et une scolarité autorisée à voir les IPES pour la tutelle du
 * bordereau, une fois celui-ci envoyé (un brouillon reste chez l'IPES).
 */
function ueb_ipes_peut_voir_recu( $recu, $bordereau ) {
	if ( ! $recu || ! $bordereau || ! is_user_logged_in() ) {
		return false;
	}
	$ipes = ueb_ipes_du_compte();
	if ( $ipes && (int) $ipes->id === (int) $recu->ipes_id ) {
		return true;
	}
	if ( ueb_est_admin_ueb() ) {
		return true;
	}
	return 'brouillon' !== $bordereau->statut && ueb_est_scolarite() && ! ueb_agent_suspendu()
		&& ueb_peut( 'ueb_voir_ipes', $bordereau->etablissement );
}

/**
 * Chemin réel d'un reçu, seulement s'il a le format attendu
 * (« 2026-2027/REC-12-30-09-2026-10-15-00.jpg ») et reste dans le dossier.
 */
function ueb_ipes_chemin_recu( $recu ) {
	$base    = realpath( ueb_ipes_dossier_recus() );
	$fichier = (string) ( $recu->fichier ?? '' );
	if ( ! $base || ! preg_match( '~^\d{4}-\d{4}/REC-[0-9-]+\.(jpg|pdf)$~', $fichier ) ) {
		return false;
	}
	$chemin = realpath( $base . DIRECTORY_SEPARATOR . $fichier );
	return $chemin && str_starts_with( $chemin, $base . DIRECTORY_SEPARATOR ) && is_file( $chemin ) ? $chemin : false;
}

/** Affiche (ou fait télécharger, avec ?telecharger=1) un reçu d'IPES à qui peut le voir. */
function ueb_ipes_servir_recu( $id ) {
	global $wpdb;
	$recu      = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ueb_insc_ipes_recus WHERE id = %d', $id ) );
	$bordereau = $recu ? ueb_ipes_bordereau( (int) $recu->ipes_id, (int) $recu->bordereau_id ) : null;
	$chemin    = ueb_ipes_peut_voir_recu( $recu, $bordereau ) ? ueb_ipes_chemin_recu( $recu ) : false;
	if ( ! $chemin ) {
		status_header( 404 );
		wp_die( 'Reçu introuvable.', 'Reçu introuvable', array( 'response' => 404 ) );
	}
	$type_mime = in_array( (string) $recu->type_mime, UEB_RECUS_TYPES, true ) ? (string) $recu->type_mime : 'application/octet-stream';
	$nom       = sanitize_file_name( (string) $recu->nom_original ) ?: 'recu-' . (int) $recu->id;
	nocache_headers();
	header( 'Content-Type: ' . $type_mime );
	header( 'Content-Length: ' . filesize( $chemin ) );
	header( 'Content-Disposition: ' . ( empty( $_GET['telecharger'] ) ? 'inline' : 'attachment' ) . '; filename="' . $nom . '"; filename*=UTF-8\'\'' . rawurlencode( $nom ) );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'X-Download-Options: noopen' );
	header( "Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox" );
	readfile( $chemin );
}

/* ---------- Actions de l'espace IPES ---------- */

/**
 * Sélection d'étudiants cochée mais pas encore enregistrée, emportée par un
 * formulaire de reçus (data-garder-selection) : enregistrée d'abord, pour que
 * joindre un reçu ne fasse pas perdre les cases cochées.
 */
function ueb_ipes_enregistrer_selection_emportee( $ipes, $bordereau_id ) {
	if ( empty( $_POST['selection_etudiants'] ) ) {
		return;
	}
	$resultat = ueb_ipes_bordereau_definir_etudiants( $ipes->id, $bordereau_id, array_map( 'intval', (array) wp_unslash( $_POST['etudiants'] ?? array() ) ) );
	if ( is_wp_error( $resultat ) ) {
		ueb_flash( 'erreur', 'Sélection d’étudiants non enregistrée — ' . $resultat->get_error_message() );
	}
}


/** Ajoute les reçus choisis (champ « recus[] ») à un bordereau modifiable. */
function ueb_action_ipes_recus_envoyer() {
	$ipes     = ueb_exiger_admin_ipes();
	$id       = (int) ( $_POST['bordereau_id'] ?? 0 );
	$retour   = ueb_url_espace_ipes_vue( 'bordereaux', array( 'bordereau' => $id ) ) . '#recus';
	ueb_ipes_enregistrer_selection_emportee( $ipes, $id );
	$fichiers = ueb_fichiers_envoyes( 'recus' );
	if ( ! $fichiers ) {
		ueb_flash( 'erreur', 'Choisis au moins une photo ou un scan du reçu bancaire.' );
		ueb_rediriger( $retour );
	}
	$acceptes = 0;
	$refus    = array();
	foreach ( $fichiers as $f ) {
		$nom = sanitize_file_name( $f['nom'] );
		if ( UPLOAD_ERR_OK !== $f['erreur'] || ! is_uploaded_file( $f['tmp'] ) ) {
			$refus[] = "$nom : envoi interrompu.";
			continue;
		}
		$resultat = ueb_ipes_recu_ajouter( $ipes->id, $id, $f['tmp'], $nom, (int) $f['taille'] );
		if ( is_wp_error( $resultat ) ) {
			$refus[] = $resultat->get_error_message();
		} else {
			$acceptes++;
		}
	}
	if ( $acceptes ) {
		ueb_flash( 'succes', 1 === $acceptes ? 'Reçu ajouté au bordereau.' : "$acceptes reçus ajoutés au bordereau." );
	}
	if ( $refus ) {
		ueb_flash( 'erreur', 'Fichier(s) refusé(s) — ' . implode( ' ', $refus ) );
	}
	ueb_rediriger( $retour );
}

function ueb_action_ipes_recu_supprimer() {
	$ipes     = ueb_exiger_admin_ipes();
	ueb_ipes_enregistrer_selection_emportee( $ipes, (int) ( $_POST['bordereau_id'] ?? 0 ) );
	$resultat = ueb_ipes_recu_supprimer( $ipes->id, (int) ( $_POST['recu_id'] ?? 0 ) );
	ueb_flash( is_wp_error( $resultat ) ? 'erreur' : 'succes', is_wp_error( $resultat ) ? $resultat->get_error_message() : 'Reçu retiré du bordereau.' );
	ueb_rediriger( ueb_url_espace_ipes_vue( 'bordereaux', array( 'bordereau' => (int) ( $_POST['bordereau_id'] ?? 0 ) ) ) . '#recus' );
}
