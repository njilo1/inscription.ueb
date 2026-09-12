<?php
/** Aperçu autonome : aucune modification de l’accueil ni des options WordPress. */

if ( ! in_array( $_SERVER['REQUEST_METHOD'] ?? 'GET', array( 'GET', 'HEAD' ), true ) ) {
	header( 'Allow: GET, HEAD' );
	http_response_code( 405 );
	exit;
}

require_once dirname( __DIR__, 4 ) . '/wp-load.php';

if ( ! function_exists( 'ueb_landing_donnees' ) ) {
	wp_die( 'Cet aperçu nécessite le thème Inscription UEb.', 'Aperçu indisponible', array( 'response' => 503 ) );
}

status_header( 200 );
nocache_headers();
header( 'X-Robots-Tag: noindex, nofollow', true );
require __DIR__ . '/modele.php';
