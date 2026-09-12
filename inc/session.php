<?php
/**
 * Session étudiante, jeton CSRF et messages flash.
 *
 * Les étudiants n'ont pas de compte WordPress : leur connexion vit dans une
 * session PHP dédiée (cookie « ueb_insc », HttpOnly, SameSite=Lax).
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

function ueb_demarrer_session() {
	if ( PHP_SESSION_ACTIVE === session_status() || headers_sent() ) {
		return;
	}
	session_name( 'ueb_insc' );
	session_set_cookie_params( array(
		'lifetime' => 0,
		'path'     => COOKIEPATH ?: '/',
		'secure'   => is_ssl(),
		'httponly' => true,
		'samesite' => 'Lax',
	) );
	session_start();

	/* Déconnexion après 2 h d'inactivité. */
	$maintenant = time();
	if ( isset( $_SESSION['ueb_activite'] ) && $maintenant - $_SESSION['ueb_activite'] > 2 * HOUR_IN_SECONDS ) {
		unset( $_SESSION['ueb_compte_id'], $_SESSION['ueb_version_session'] );
	}
	$_SESSION['ueb_activite'] = $maintenant;
}
add_action( 'init', 'ueb_demarrer_session', 1 );

/* ---------- CSRF ---------- */

function ueb_jeton_csrf() {
	if ( empty( $_SESSION['ueb_csrf'] ) ) {
		$_SESSION['ueb_csrf'] = bin2hex( random_bytes( 32 ) );
	}
	return $_SESSION['ueb_csrf'];
}

function ueb_champ_csrf() {
	echo '<input type="hidden" name="ueb_csrf" value="' . esc_attr( ueb_jeton_csrf() ) . '">';
}

function ueb_verifier_csrf() {
	$recu = isset( $_POST['ueb_csrf'] ) ? (string) $_POST['ueb_csrf'] : '';
	return '' !== $recu && ! empty( $_SESSION['ueb_csrf'] ) && hash_equals( $_SESSION['ueb_csrf'], $recu );
}

/* ---------- Messages flash (affichés une fois, après redirection) ---------- */

function ueb_flash( $type, $message ) {
	$_SESSION['ueb_flash'][] = array( 'type' => $type, 'message' => $message );
}

function ueb_lire_flash() {
	$messages = $_SESSION['ueb_flash'] ?? array();
	unset( $_SESSION['ueb_flash'] );
	return $messages;
}

/* Valeurs saisies à réafficher après une erreur de formulaire. */
function ueb_memoriser_saisie( array $valeurs, array $erreurs ) {
	$_SESSION['ueb_saisie']  = $valeurs;
	$_SESSION['ueb_erreurs'] = $erreurs;
}

function ueb_reprendre_saisie() {
	$saisie  = $_SESSION['ueb_saisie'] ?? array();
	$erreurs = $_SESSION['ueb_erreurs'] ?? array();
	unset( $_SESSION['ueb_saisie'], $_SESSION['ueb_erreurs'] );
	return array( $saisie, $erreurs );
}

/** Redirige puis s'arrête (motif Post/Redirect/Get). */
function ueb_rediriger( $url ) {
	wp_safe_redirect( $url, 303 );
	exit;
}
