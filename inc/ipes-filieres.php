<?php
/**
 * Filières d'un IPES, saisies par l'administration d'après la liste que
 * l'IPES communique, TUTELLE PAR TUTELLE : chaque filière dépend d'un des
 * établissements de tutelle de l'IPES (colonne « etablissement »), et ses
 * étudiants sont reversés à cet établissement. Par exemple, pour Siantou :
 * Droit → FSJP, Physique → FS.
 *
 * Une filière retirée est désactivée, jamais supprimée : les étudiants de
 * l'IPES y seront rattachés, et l'historique doit rester lisible.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

const UEB_IPES_FILIERE_MIN = 3;
const UEB_IPES_FILIERE_MAX = 150;

/* ---------- Lecture ---------- */

/**
 * Filières d'un IPES, triées par tutelle puis par libellé.
 *
 * @param bool   $actives_seulement Seulement les filières non retirées.
 * @param string $etablissement     Seulement celles de cette tutelle (sigle).
 */
function ueb_ipes_filieres( $ipes_id, $actives_seulement = false, $etablissement = '' ) {
	global $wpdb;
	$sql    = 'SELECT * FROM ueb_insc_ipes_filieres WHERE ipes_id = %d';
	$params = array( $ipes_id );
	if ( $actives_seulement ) {
		$sql .= ' AND actif = 1';
	}
	if ( '' !== (string) $etablissement ) {
		$sql     .= ' AND etablissement = %s';
		$params[] = strtoupper( (string) $etablissement );
	}
	return $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY etablissement, libelle', $params ) );
}

/**
 * Filières d'un IPES regroupées par tutelle : sigle => filières, pour chaque
 * tutelle de l'IPES (tableau vide si elle n'a pas encore de filière).
 */
function ueb_ipes_filieres_par_tutelle( $ipes, $actives_seulement = false ) {
	$groupes = array_fill_keys( (array) $ipes->tutelles, array() );
	foreach ( ueb_ipes_filieres( $ipes->id, $actives_seulement ) as $f ) {
		$groupes[ $f->etablissement ][] = $f;
	}
	return $groupes;
}

/** Une filière, ou null. */
function ueb_ipes_filiere( $id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ueb_insc_ipes_filieres WHERE id = %d', $id ) );
}

/* ---------- Validation ---------- */

/** Libellé rogné, espaces multiples réduits à un seul. */
function ueb_ipes_filiere_normaliser( $libelle ) {
	return trim( preg_replace( '/\s+/u', ' ', (string) $libelle ) );
}

/**
 * Message d'erreur pour ce libellé (normalisé), ou chaîne vide s'il convient.
 * L'unicité ignore majuscules et accents, comme la collation de la table :
 * « Génie logiciel » et « genie LOGICIEL » sont la même filière.
 *
 * @param int $id Filière renommée (0 pour un ajout), exclue du contrôle d'unicité.
 */
function ueb_ipes_filiere_valider( $ipes_id, $libelle, $id = 0 ) {
	global $wpdb;
	$longueur = mb_strlen( $libelle );
	if ( $longueur < UEB_IPES_FILIERE_MIN || $longueur > UEB_IPES_FILIERE_MAX ) {
		return sprintf( 'Le nom de la filière compte %d à %d caractères.', UEB_IPES_FILIERE_MIN, UEB_IPES_FILIERE_MAX );
	}
	if ( $wpdb->get_var( $wpdb->prepare(
		'SELECT id FROM ueb_insc_ipes_filieres WHERE ipes_id = %d AND libelle = %s AND id <> %d',
		$ipes_id, $libelle, $id
	) ) ) {
		return 'Cette filière existe déjà pour cet IPES.';
	}
	return '';
}

/* ---------- Écriture ---------- */

/**
 * Ajoute une filière sous l'une des tutelles de l'IPES. Sans tutelle
 * précisée, celle de l'IPES est prise s'il n'en a qu'une.
 *
 * @return int|WP_Error Identifiant de la filière ajoutée.
 */
function ueb_ipes_filiere_ajouter( $ipes_id, $libelle, $etablissement = '' ) {
	global $wpdb;
	$ipes = ueb_ipes( $ipes_id );
	if ( ! $ipes ) {
		return new WP_Error( 'ueb_ipes_filiere', 'Cet IPES n’existe pas.' );
	}
	$etablissement = strtoupper( trim( (string) $etablissement ) );
	if ( '' === $etablissement && 1 === count( $ipes->tutelles ) ) {
		$etablissement = $ipes->tutelles[0];
	}
	if ( ! in_array( $etablissement, $ipes->tutelles, true ) ) {
		return new WP_Error( 'ueb_ipes_filiere', 'Choisis l’établissement de tutelle de cette filière, parmi ceux de l’IPES.' );
	}
	$libelle = ueb_ipes_filiere_normaliser( $libelle );
	$erreur  = ueb_ipes_filiere_valider( $ipes_id, $libelle );
	if ( $erreur ) {
		return new WP_Error( 'ueb_ipes_filiere', $erreur );
	}
	if ( ! $wpdb->insert( 'ueb_insc_ipes_filieres', array( 'ipes_id' => (int) $ipes_id, 'etablissement' => $etablissement, 'libelle' => $libelle ) ) ) {
		/* Deux ajouts simultanés du même libellé : la clé unique tranche. */
		return new WP_Error( 'ueb_ipes_filiere', 'Cette filière existe déjà pour cet IPES.' );
	}
	return (int) $wpdb->insert_id;
}

/** @return true|WP_Error */
function ueb_ipes_filiere_renommer( $id, $libelle ) {
	global $wpdb;
	$filiere = ueb_ipes_filiere( $id );
	if ( ! $filiere ) {
		return new WP_Error( 'ueb_ipes_filiere', 'Cette filière n’existe pas.' );
	}
	$libelle = ueb_ipes_filiere_normaliser( $libelle );
	$erreur  = ueb_ipes_filiere_valider( $filiere->ipes_id, $libelle, $filiere->id );
	if ( $erreur ) {
		return new WP_Error( 'ueb_ipes_filiere', $erreur );
	}
	if ( false === $wpdb->update( 'ueb_insc_ipes_filieres', array( 'libelle' => $libelle ), array( 'id' => (int) $id ) ) ) {
		return new WP_Error( 'ueb_ipes_filiere', 'Cette filière existe déjà pour cet IPES.' );
	}
	return true;
}

/** Retire (désactive) ou rétablit une filière. Vrai si elle existe. */
function ueb_ipes_filiere_changer_etat( $id, $actif ) {
	global $wpdb;
	if ( ! ueb_ipes_filiere( $id ) ) {
		return false;
	}
	return false !== $wpdb->update( 'ueb_insc_ipes_filieres', array( 'actif' => $actif ? 1 : 0 ), array( 'id' => (int) $id ) );
}

/* ---------- Actions de l'administration ----------
   Bloc « Filières » de la fiche d'un IPES (?vue=ipes&ipes={id}#filieres). */

/** Adresse du bloc Filières de la fiche d'un IPES. */
function ueb_url_ipes_filieres( $ipes_id ) {
	return ueb_url_ipes( $ipes_id ) . '#filieres';
}

/** Filière désignée par « filiere_id », si elle appartient bien à cet IPES. */
function ueb_ipes_filiere_du_formulaire( $ipes ) {
	$filiere = ueb_ipes_filiere( (int) ( $_POST['filiere_id'] ?? 0 ) );
	if ( ! $filiere || (int) $filiere->ipes_id !== (int) $ipes->id ) {
		ueb_flash( 'erreur', 'Cette filière n’appartient pas à cet IPES.' );
		ueb_rediriger( ueb_url_ipes_filieres( $ipes->id ) );
	}
	return $filiere;
}

function ueb_action_ipes_filiere_ajouter() {
	ueb_exiger_admin();
	ueb_ipes_retour_bloc( 'filieres' );
	$ipes     = ueb_ipes_du_formulaire();
	$libelle  = sanitize_text_field( wp_unslash( $_POST['libelle'] ?? '' ) );
	$tutelle  = sanitize_text_field( wp_unslash( $_POST['etablissement'] ?? '' ) );
	$resultat = ueb_ipes_filiere_ajouter( $ipes->id, $libelle, $tutelle );
	if ( is_wp_error( $resultat ) ) {
		/* L'erreur s'affiche sous le champ de sa tutelle, dans le bloc Filières. */
		ueb_memoriser_saisie( array( 'libelle' => $libelle, 'etablissement' => strtoupper( $tutelle ) ), array( 'libelle' => $resultat->get_error_message() ) );
	} else {
		ueb_flash( 'succes', 'Filière ajoutée.' );
	}
	ueb_rediriger( ueb_url_ipes_filieres( $ipes->id ) );
}

function ueb_action_ipes_filiere_renommer() {
	ueb_exiger_admin();
	ueb_ipes_retour_bloc( 'filieres' );
	$ipes     = ueb_ipes_du_formulaire();
	$filiere  = ueb_ipes_filiere_du_formulaire( $ipes );
	$resultat = ueb_ipes_filiere_renommer( $filiere->id, sanitize_text_field( wp_unslash( $_POST['libelle'] ?? '' ) ) );
	if ( is_wp_error( $resultat ) ) {
		ueb_flash( 'erreur', $resultat->get_error_message() );
	} else {
		ueb_flash( 'succes', 'Filière renommée.' );
	}
	ueb_rediriger( ueb_url_ipes_filieres( $ipes->id ) );
}

/** Retire une filière active, rétablit une filière retirée. */
function ueb_action_ipes_filiere_etat() {
	ueb_exiger_admin();
	ueb_ipes_retour_bloc( 'filieres' );
	$ipes    = ueb_ipes_du_formulaire();
	$filiere = ueb_ipes_filiere_du_formulaire( $ipes );
	$active  = ! (int) $filiere->actif;
	if ( ueb_ipes_filiere_changer_etat( $filiere->id, $active ) ) {
		ueb_flash( 'succes', $active ? 'Filière rétablie.' : 'Filière retirée. Elle reste dans l’historique.' );
	} else {
		ueb_flash( 'erreur', 'La filière n’a pas pu être modifiée. Réessaie dans un instant.' );
	}
	ueb_rediriger( ueb_url_ipes_filieres( $ipes->id ) );
}
