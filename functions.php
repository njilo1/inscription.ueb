<?php
/**
 * Point d'entrée du thème Inscription UEb.
 *
 * Organisation :
 *   inc/config.php        établissements, année académique, constantes
 *   inc/db-schema.php     tables ueb_insc_* (création versionnée)
 *   inc/session.php       session PHP, jeton CSRF, messages flash
 *   inc/routes.php        adresses propres (/connexion, /mon-espace…)
 *   inc/comptes.php       comptes étudiants et authentification
 *   inc/nombres.php       montant en lettres
 *   inc/quitus.php        formulaire et enregistrement des quitus
 *   inc/quitus-pdf.php    génération du PDF (modèle A, 4 coupons)
 *   inc/recus.php         envoi et consultation des reçus bancaires
 *   inc/gestion.php       espace d'administration
 *   inc/assets.php        feuilles de style et scripts
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

define( 'UEB_INSC_VERSION', '1.0.0' );
define( 'UEB_INSC_DIR', get_template_directory() );
define( 'UEB_INSC_URI', get_template_directory_uri() );

foreach ( array( 'config', 'db-schema', 'session', 'routes', 'roles', 'comptes', 'nombres', 'inscription', 'quitus', 'quitus-pdf', 'recus', 'gestion', 'assets', 'vues', 'landing' ) as $ueb_module ) {
	require_once UEB_INSC_DIR . '/inc/' . $ueb_module . '.php';
}

add_action( 'after_setup_theme', function () {
	add_theme_support( 'title-tag' );
	add_theme_support( 'html5', array( 'style', 'script' ) );
} );

/* Aucune page de ce site ne doit être indexée, sauf l'accueil. */
add_action( 'wp_head', function () {
	if ( get_query_var( 'ueb_page' ) ) {
		echo '<meta name="robots" content="noindex,nofollow">' . "\n";
	}
}, 1 );

add_action( 'send_headers', function () {
	header( 'X-Content-Type-Options: nosniff' );
	header( 'X-Frame-Options: SAMEORIGIN' );
	header( 'Referrer-Policy: strict-origin-when-cross-origin' );
	header( 'Permissions-Policy: camera=(), microphone=(), geolocation=()' );
} );
