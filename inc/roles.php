<?php
/**
 * Rôles du back-office.
 *
 *   Administrateur WordPress : voit tous les établissements et crée les
 *   comptes de scolarité.
 *   Scolarité (rôle « ueb_scolarite ») : ne voit et ne décide que pour
 *   l'établissement inscrit dans sa méta « ueb_etablissement ».
 *
 * Un agent suspendu garde son compte (les décisions déjà prises restent
 * tracées) mais perd l'accès au back-office.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

const UEB_ROLE_SCOLARITE = 'ueb_scolarite';
const UEB_CAP_GESTION    = 'ueb_gerer_quitus';
const UEB_ROLES_VERSION  = '1';

/* Création du rôle, et capacité donnée aux administrateurs. */
add_action( 'init', function () {
	if ( get_option( 'ueb_insc_roles_version' ) === UEB_ROLES_VERSION ) {
		return;
	}
	remove_role( UEB_ROLE_SCOLARITE );
	add_role( UEB_ROLE_SCOLARITE, 'Scolarité UEb', array( 'read' => true, UEB_CAP_GESTION => true ) );
	$admin = get_role( 'administrator' );
	if ( $admin ) {
		$admin->add_cap( UEB_CAP_GESTION );
	}
	update_option( 'ueb_insc_roles_version', UEB_ROLES_VERSION );
}, 4 );

/* ---------- Qui est qui ---------- */

function ueb_est_admin_ueb() {
	return is_user_logged_in() && current_user_can( 'manage_options' );
}

/** Vrai si le compte a été suspendu par un administrateur. */
function ueb_agent_suspendu( $user_id = 0 ) {
	return (bool) get_user_meta( $user_id ?: get_current_user_id(), 'ueb_agent_suspendu', true );
}

/**
 * Établissement auquel un agent est rattaché ; chaîne vide pour un
 * administrateur, qui n'est limité à aucun établissement.
 */
function ueb_etab_agent( $user_id = 0 ) {
	if ( ! $user_id && ueb_est_admin_ueb() ) {
		return '';
	}
	$sigle = strtoupper( (string) get_user_meta( $user_id ?: get_current_user_id(), 'ueb_etablissement', true ) );
	return ueb_etablissement( $sigle ) ? $sigle : '';
}

/** Vrai si l'utilisateur courant peut agir sur cet établissement. */
function ueb_peut_gerer_etab( $sigle ) {
	$limite = ueb_etab_agent();
	return '' === $limite || strtoupper( (string) $sigle ) === $limite;
}

/* ---------- Comptes de scolarité ---------- */

function ueb_agents_scolarite() {
	return get_users( array( 'role' => UEB_ROLE_SCOLARITE, 'orderby' => 'display_name' ) );
}

/**
 * Crée un compte de scolarité rattaché à un établissement.
 *
 * @return array{0:int,1:string}|WP_Error identifiant du compte et mot de passe provisoire
 */
function ueb_creer_agent( $login, $nom, $email, $etab ) {
	$login = sanitize_user( $login, true );
	$etab  = strtoupper( (string) $etab );
	if ( '' === $login ) {
		return new WP_Error( 'ueb_agent', 'Saisis un identifiant de connexion.' );
	}
	if ( ! ueb_etablissement( $etab ) ) {
		return new WP_Error( 'ueb_agent', 'Choisis l’établissement de cet agent.' );
	}
	if ( username_exists( $login ) ) {
		return new WP_Error( 'ueb_agent', 'Cet identifiant est déjà pris.' );
	}
	if ( $email && ! is_email( $email ) ) {
		return new WP_Error( 'ueb_agent', 'Adresse e-mail invalide.' );
	}
	if ( $email && email_exists( $email ) ) {
		return new WP_Error( 'ueb_agent', 'Cette adresse e-mail est déjà utilisée par un autre compte.' );
	}
	$provisoire = ueb_mot_de_passe_provisoire();
	$id         = wp_insert_user( array(
		'user_login'   => $login,
		'user_pass'    => $provisoire,
		'user_email'   => $email ?: '',
		'display_name' => $nom ?: $login,
		'first_name'   => $nom ?: '',
		'role'         => UEB_ROLE_SCOLARITE,
	) );
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	update_user_meta( $id, 'ueb_etablissement', $etab );
	return array( (int) $id, $provisoire );
}

/* ---------- Les deux espaces, qui sont des Pages WordPress ---------- */

/**
 * Identifiant de la Page portant ce gabarit (page-scolarite.php,
 * page-administration.php). Mémorisé en option pour éviter une requête
 * à chaque appel ; recalculé si la Page a changé.
 */
function ueb_page_par_gabarit( $gabarit ) {
	$cle = 'ueb_page_' . sanitize_key( str_replace( '.php', '', $gabarit ) );
	$id  = (int) get_option( $cle );
	if ( $id && 'publish' === get_post_status( $id ) && get_page_template_slug( $id ) === $gabarit ) {
		return $id;
	}
	$pages = get_posts( array(
		'post_type'   => 'page',
		'post_status' => 'publish',
		'numberposts' => 1,
		'fields'      => 'ids',
		'meta_key'    => '_wp_page_template', // phpcs:ignore WordPress.DB.SlowDBQuery
		'meta_value'  => $gabarit,            // phpcs:ignore WordPress.DB.SlowDBQuery
	) );
	$id = $pages ? (int) $pages[0] : 0;
	update_option( $cle, $id );
	return $id;
}

/** Adresse de l'espace scolarité. */
function ueb_url_scolarite() {
	$id = ueb_page_par_gabarit( 'page-scolarite.php' );
	return $id ? get_permalink( $id ) : home_url( '/scolarite/' );
}

/** Adresse de l'espace administration. */
function ueb_url_administration() {
	$id = ueb_page_par_gabarit( 'page-administration.php' );
	return $id ? get_permalink( $id ) : home_url( '/administration/' );
}

/** L'espace qui correspond au compte connecté. */
function ueb_url_espace() {
	return ueb_est_admin_ueb() ? ueb_url_administration() : ueb_url_scolarite();
}

/** Vrai si ce compte WordPress est bien un agent de scolarité. */
function ueb_est_agent( $user_id ) {
	$user = get_userdata( $user_id );
	return $user && in_array( UEB_ROLE_SCOLARITE, (array) $user->roles, true );
}
