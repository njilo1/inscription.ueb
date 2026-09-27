<?php
/**
 * Filières d'un IPES, saisies par l'administration d'après la liste que
 * l'IPES communique.
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

/** Filières d'un IPES par ordre alphabétique, éventuellement les seules actives. */
function ueb_ipes_filieres( $ipes_id, $actives_seulement = false ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare(
		'SELECT * FROM ueb_insc_ipes_filieres WHERE ipes_id = %d' . ( $actives_seulement ? ' AND actif = 1' : '' ) . ' ORDER BY libelle',
		$ipes_id
	) );
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

/** @return int|WP_Error Identifiant de la filière ajoutée. */
function ueb_ipes_filiere_ajouter( $ipes_id, $libelle ) {
	global $wpdb;
	if ( ! ueb_ipes( $ipes_id ) ) {
		return new WP_Error( 'ueb_ipes_filiere', 'Cet IPES n’existe pas.' );
	}
	$libelle = ueb_ipes_filiere_normaliser( $libelle );
	$erreur  = ueb_ipes_filiere_valider( $ipes_id, $libelle );
	if ( $erreur ) {
		return new WP_Error( 'ueb_ipes_filiere', $erreur );
	}
	if ( ! $wpdb->insert( 'ueb_insc_ipes_filieres', array( 'ipes_id' => (int) $ipes_id, 'libelle' => $libelle ) ) ) {
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
	$resultat = ueb_ipes_filiere_ajouter( $ipes->id, $libelle );
	if ( is_wp_error( $resultat ) ) {
		/* L'erreur s'affiche sous le champ, dans le bloc Filières. */
		ueb_memoriser_saisie( array( 'libelle' => $libelle ), array( 'libelle' => $resultat->get_error_message() ) );
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
