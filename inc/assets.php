<?php
/**
 * Feuilles de style et scripts. Chaque page ne charge que ce qu'elle utilise :
 * GSAP et ScrollTrigger pour la landing, le lecteur Remotion pour les pages
 * qui affichent une animation.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

/** Version d'un fichier du thème : sa date de modification (cache busting). */
function ueb_version_fichier( $chemin ) {
	$fichier = UEB_INSC_DIR . '/' . $chemin;
	return file_exists( $fichier ) ? (string) filemtime( $fichier ) : UEB_INSC_VERSION;
}

function ueb_style( $poignee, $chemin, $deps = array() ) {
	wp_enqueue_style( $poignee, UEB_INSC_URI . '/' . $chemin, $deps, ueb_version_fichier( $chemin ) );
}

function ueb_script( $poignee, $chemin, $deps = array() ) {
	wp_enqueue_script( $poignee, UEB_INSC_URI . '/' . $chemin, $deps, ueb_version_fichier( $chemin ), array( 'in_footer' => true, 'strategy' => 'defer' ) );
}

add_action( 'wp_enqueue_scripts', function () {
	$page = get_query_var( 'ueb_page' );

	ueb_style( 'ueb-polices', 'assets/css/polices.css' );
	ueb_style( 'ueb-app', 'assets/css/app.css', array( 'ueb-polices' ) );
	ueb_style( 'ueb-pages', 'assets/css/pages.css', array( 'ueb-app' ) );
	ueb_script( 'ueb-app', 'assets/js/app.js' );

	if ( is_front_page() && ! $page ) {
		ueb_style( 'ueb-landing', 'assets/css/landing.css', array( 'ueb-app' ) );
		ueb_script( 'gsap', 'assets/js/vendor/gsap.min.js' );
		ueb_script( 'gsap-scrolltrigger', 'assets/js/vendor/ScrollTrigger.min.js', array( 'gsap' ) );
		ueb_script( 'ueb-landing', 'assets/js/landing.js', array( 'gsap', 'gsap-scrolltrigger' ) );
	}

	/* Emblème animé aussi sur les écrans de connexion des espaces scolarité et Direction. */
	$connexion_scolarite = ( is_page_template( 'page-scolarite.php' ) && ! ( is_user_logged_in() && function_exists( 'ueb_est_scolarite' ) && ueb_est_scolarite() ) )
		|| ( is_page_template( 'page-direction.php' ) && ! ( function_exists( 'ueb_peut' ) && ueb_peut( UEB_CAP_DIRECTION ) ) );
	/* Vues de travail de la scolarité (tableau de bord, quitus, dossier, paiements)
	   et suivi des paiements de l'administration, qui partage le même rendu :
	   graphiques, infobulles, jauge et suivi animés. */
	$vue_bo         = sanitize_key( $_GET['vue'] ?? 'bord' ); // phpcs:ignore -- lecture seule
	$bord_scolarite = ( is_page_template( 'page-scolarite.php' ) && is_user_logged_in() && function_exists( 'ueb_est_scolarite' ) && ueb_est_scolarite()
			&& ( in_array( $vue_bo, array( 'bord', 'quitus', 'paiements' ), true ) || isset( $_GET['quitus'] ) ) ) // phpcs:ignore
		|| ( is_page_template( 'page-administration.php' ) && function_exists( 'ueb_est_admin_ueb' ) && ueb_est_admin_ueb() && 'paiements' === $vue_bo );
	if ( $bord_scolarite ) {
		ueb_style( 'ueb-bord', 'assets/css/bord.css', array( 'ueb-pages' ) );
		ueb_style( 'ueb-bord-graphes', 'assets/css/bord-graphes.css', array( 'ueb-bord' ) );
		ueb_script( 'ueb-bord', 'assets/js/bord.js' );
	}
	/* Espace Direction : assistant de rôle et aperçu en direct. */
	if ( is_page_template( 'page-direction.php' ) ) {
		ueb_script( 'ueb-direction', 'assets/js/direction.js', array( 'ueb-app' ) );
	}
	if ( ( is_front_page() && ! $page ) || in_array( $page, array( 'connexion', 'creer-compte' ), true ) || $connexion_scolarite || $bord_scolarite ) {
		ueb_script( 'ueb-remotion', 'assets/js/remotion-ueb.js' );
	}
} );

/* Allègement : ni emojis ni styles de blocs sur ce site sans articles. */
add_action( 'init', function () {
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
} );
add_action( 'wp_enqueue_scripts', function () {
	wp_dequeue_style( 'wp-block-library' );
	wp_dequeue_style( 'global-styles' );
	wp_dequeue_style( 'classic-theme-styles' );
}, 100 );

/** Données de l'animation « parcours » : trois établissements et leurs logos. */
function ueb_props_parcours() {
	$etablissements = array();
	foreach ( array( 'FS', 'FMSP', 'ENSTMO' ) as $sigle ) {
		$e                = ueb_etablissement( $sigle );
		$etablissements[] = array( 'sigle' => $sigle, 'fr' => $e['fr'], 'en' => $e['en'], 'couleur' => $e['couleur'], 'logo' => ueb_logo_url( $sigle ) );
	}
	return array(
		'etablissements' => $etablissements,
		'logoUniversite' => ueb_logo_url( 'UEB' ),
		'annee'          => ueb_annee_academique()['libelle'],
	);
}

/** Données de l'emblème des pages de connexion : le sceau de l'université. */
function ueb_props_embleme() {
	return array( 'logo' => ueb_logo_url( 'UEB' ) );
}
