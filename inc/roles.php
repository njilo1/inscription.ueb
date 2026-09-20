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
const UEB_ROLE_CELLULE   = 'ueb_cellule';
const UEB_CAP_GESTION    = 'ueb_gerer_quitus';
const UEB_CAP_COMPTES    = 'ueb_gerer_comptes';
const UEB_ROLES_VERSION  = '2';

/* Création du rôle, et capacité donnée aux administrateurs. */
add_action( 'init', function () {
	$version = get_option( 'ueb_insc_roles_version' );
	/* Répare aussi une installation où l'option a été enregistrée alors que
	   le rôle n'a pas été créé (import SQL, cache ou activation interrompue). */
	if ( $version !== UEB_ROLES_VERSION || ! get_role( UEB_ROLE_SCOLARITE ) || ! get_role( UEB_ROLE_CELLULE ) ) {
		remove_role( UEB_ROLE_SCOLARITE );
		remove_role( UEB_ROLE_CELLULE );
		add_role( UEB_ROLE_SCOLARITE, 'Scolarité UEb', array( 'read' => true, UEB_CAP_GESTION => true, UEB_CAP_COMPTES => true ) );
		add_role( UEB_ROLE_CELLULE, 'Cellule informatique UEb', array( 'read' => true, UEB_CAP_COMPTES => true ) );
	}
	$scolarite = get_role( UEB_ROLE_SCOLARITE );
	$cellule   = get_role( UEB_ROLE_CELLULE );
	if ( $scolarite ) {
		$scolarite->add_cap( 'read' );
		$scolarite->add_cap( UEB_CAP_GESTION );
		$scolarite->add_cap( UEB_CAP_COMPTES );
	}
	if ( $cellule ) {
		$cellule->add_cap( 'read' );
		$cellule->add_cap( UEB_CAP_COMPTES );
	}
	$admin = get_role( 'administrator' );
	if ( $admin ) {
		$admin->add_cap( UEB_CAP_GESTION );
		$admin->add_cap( UEB_CAP_COMPTES );
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

function ueb_agents_cellule() {
	return get_users( array( 'role' => UEB_ROLE_CELLULE, 'orderby' => 'display_name' ) );
}

/**
 * Crée un compte de scolarité rattaché à un établissement.
 *
 * @return array{0:int,1:string}|WP_Error identifiant du compte et mot de passe provisoire
 */
function ueb_creer_agent( $login, $nom, $email, $etab, $mot_de_passe = '' ) {
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
	$mot_de_passe = (string) $mot_de_passe;
	if ( strlen( $mot_de_passe ) < 8 || ! preg_match( '/[A-Za-z]/', $mot_de_passe ) || ! preg_match( '/[0-9]/', $mot_de_passe ) ) {
		return new WP_Error( 'ueb_agent', 'Le mot de passe doit contenir au moins 8 caractères, une lettre et un chiffre.' );
	}
	$provisoire = $mot_de_passe;
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

/** Crée un compte de cellule informatique rattaché à l'établissement courant. */
function ueb_creer_cellule( $login, $nom, $email, $etab ) {
	$login = sanitize_user( $login, true );
	$etab  = strtoupper( (string) $etab );
	if ( '' === $login || ! ueb_etablissement( $etab ) ) {
		return new WP_Error( 'ueb_cellule', 'Identifiant ou établissement invalide.' );
	}
	if ( username_exists( $login ) ) {
		return new WP_Error( 'ueb_cellule', 'Cet identifiant est déjà pris.' );
	}
	if ( $email && ( ! is_email( $email ) || email_exists( $email ) ) ) {
		return new WP_Error( 'ueb_cellule', 'Adresse e-mail invalide ou déjà utilisée.' );
	}
	$provisoire = ueb_mot_de_passe_provisoire();
	$id = wp_insert_user( array(
		'user_login'   => $login,
		'user_pass'    => $provisoire,
		'user_email'   => $email ?: '',
		'display_name' => $nom ?: $login,
		'first_name'   => $nom ?: '',
		'role'         => UEB_ROLE_CELLULE,
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

/** Adresse de l'espace cellule informatique. */
function ueb_url_cellule() {
	return ueb_url( 'cellule-informatique' );
}

/** Adresse de l'espace administration. */
function ueb_url_administration() {
	$id = ueb_page_par_gabarit( 'page-administration.php' );
	return $id ? get_permalink( $id ) : home_url( '/administration/' );
}

/** L'espace qui correspond au compte connecté. */
function ueb_url_espace() {
	if ( ueb_est_admin_ueb() ) {
		return ueb_url_administration();
	}
	return ueb_est_cellule() ? ueb_url_cellule() : ueb_url_scolarite();
}

/** URL de retour de la gestion des comptes étudiants. */
function ueb_url_comptes() {
	return ueb_est_cellule() ? ueb_url_cellule() : ueb_url_scolarite();
}

/** Vrai si ce compte WordPress est bien un agent de scolarité. */
function ueb_est_agent( $user_id ) {
	$user = get_userdata( $user_id );
	return $user && in_array( UEB_ROLE_SCOLARITE, (array) $user->roles, true );
}

/** Vrai si le compte est un agent de scolarité (hors administrateur). */
function ueb_est_scolarite( $user_id = 0 ) {
	$user = get_userdata( $user_id ?: get_current_user_id() );
	if ( ! $user || user_can( $user, 'manage_options' ) || ueb_est_cellule( $user->ID ) ) {
		return false;
	}
	/* Le rôle reste le signal principal. La capacité couvre les comptes
	   créés avant la séparation des rôles ou restaurés depuis une sauvegarde. */
	return in_array( UEB_ROLE_SCOLARITE, (array) $user->roles, true ) || user_can( $user, UEB_CAP_GESTION );
}

/** Vrai si le compte est rattaché à la cellule informatique d'un établissement. */
function ueb_est_cellule( $user_id = 0 ) {
	$user = get_userdata( $user_id ?: get_current_user_id() );
	return $user && in_array( UEB_ROLE_CELLULE, (array) $user->roles, true );
}

/** Vrai si le compte peut gérer les comptes étudiants. */
function ueb_est_gestionnaire_comptes() {
	return is_user_logged_in()
		&& ( current_user_can( 'manage_options' ) || ( current_user_can( UEB_CAP_COMPTES ) && ueb_est_cellule() ) )
		&& ! ueb_agent_suspendu();
}
