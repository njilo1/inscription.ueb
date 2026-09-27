<?php
/**
 * Espace de l'administrateur d'un IPES : accès et page.
 *
 * L'espace est une Page WordPress (gabarit page-ipes.php), créée une fois
 * si elle manque, comme l'espace Direction. Le compte y voit son IPES et
 * rien d'autre : l'IPES vient toujours de la méta « ueb_ipes_id » du compte
 * connecté, jamais d'un paramètre envoyé par le navigateur.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

/* ---------- Page de l'espace, créée une fois si elle manque ---------- */

add_action( 'init', function () {
	if ( get_option( 'ueb_page_ipes_creee' ) || ueb_page_par_gabarit( 'page-ipes.php' ) || ! ueb_insc_verrouiller( 'page_ipes' ) ) {
		return;
	}
	/* Une autre requête a pu créer la Page pendant qu'on attendait. */
	if ( ueb_insc_option_en_base( 'ueb_page_ipes_creee' ) ) {
		ueb_insc_deverrouiller( 'page_ipes' );
		return;
	}
	$id = wp_insert_post( array(
		'post_title'  => 'Espace IPES',
		'post_name'   => 'espace-ipes',
		'post_status' => 'publish',
		'post_type'   => 'page',
		'meta_input'  => array( '_wp_page_template' => 'page-ipes.php' ),
	) );
	if ( $id && ! is_wp_error( $id ) ) {
		update_option( 'ueb_page_ipes_creee', (int) $id );
		delete_option( 'ueb_page_page-ipes' ); // recalcul de ueb_page_par_gabarit()
	}
	ueb_insc_deverrouiller( 'page_ipes' );
}, 30 );

/** Adresse de l'espace IPES. */
function ueb_url_espace_ipes() {
	$id = ueb_page_par_gabarit( 'page-ipes.php' );
	return $id ? get_permalink( $id ) : home_url( '/espace-ipes/' );
}

/* La barre d'outils de WordPress ne mène qu'à wp-admin, fermé à ces comptes. */
add_filter( 'show_admin_bar', function ( $afficher ) {
	return is_user_logged_in() && ueb_est_admin_ipes( get_current_user_id() ) ? false : $afficher;
} );

/* ---------- Accès ---------- */

/**
 * IPES administré par ce compte, s'il peut y accéder : compte d'administrateur
 * d'IPES non suspendu, IPES existant et actif. Null sinon.
 */
function ueb_ipes_du_compte( $user_id = 0 ) {
	$user_id = $user_id ?: get_current_user_id();
	if ( ! $user_id || ! user_can( $user_id, UEB_CAP_IPES ) || ! ueb_est_admin_ipes( $user_id ) || ueb_agent_suspendu( $user_id ) ) {
		return null;
	}
	$ipes = ueb_ipes( (int) get_user_meta( $user_id, 'ueb_ipes_id', true ) );
	return ( $ipes && (int) $ipes->actif ) ? $ipes : null;
}

/** Pour les actions de l'espace : l'IPES du compte connecté, ou refus (403). */
function ueb_exiger_admin_ipes() {
	$ipes = is_user_logged_in() ? ueb_ipes_du_compte() : null;
	if ( ! $ipes ) {
		wp_die( 'Action réservée à l’administrateur d’un IPES actif.', 'Accès refusé', array( 'response' => 403 ) );
	}
	return $ipes;
}
