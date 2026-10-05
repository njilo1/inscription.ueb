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

	if ( 'support' === $page ) {
		ueb_style( 'ueb-support', 'assets/css/support.css', array( 'ueb-pages' ) );
		ueb_script( 'ueb-support', 'assets/js/support.js' );
	}

	if ( is_front_page() && ! $page ) {
		ueb_style( 'ueb-landing', 'assets/css/landing.css', array( 'ueb-app' ) );
		ueb_script( 'gsap', 'assets/js/vendor/gsap.min.js' );
		ueb_script( 'gsap-scrolltrigger', 'assets/js/vendor/ScrollTrigger.min.js', array( 'gsap' ) );
		ueb_script( 'ueb-landing', 'assets/js/landing.js', array( 'gsap', 'gsap-scrolltrigger' ) );
	}

	$page_admin = is_page_template( 'page-administration.php' );
	$admin      = $page_admin && function_exists( 'ueb_est_admin_ueb' ) && ueb_est_admin_ueb();
	/* Emblème animé aussi sur les écrans de connexion des espaces scolarité, Direction et Administration. */
	$connexion_scolarite = ( is_page_template( 'page-scolarite.php' ) && ! ( is_user_logged_in() && function_exists( 'ueb_est_scolarite' ) && ueb_est_scolarite() ) )
		|| ( is_page_template( 'page-direction.php' ) && ! ( function_exists( 'ueb_peut' ) && ueb_peut( UEB_CAP_DIRECTION ) ) )
		|| ( is_page_template( 'page-ipes.php' ) && ! ( function_exists( 'ueb_ipes_du_compte' ) && ueb_ipes_du_compte() ) )
		|| ( $page_admin && ! $admin );
	/* Vues de travail de la scolarité (tableau de bord, quitus, dossier, paiements)
	   et tableau de bord + suivi des paiements de l'administration, qui partagent
	   le même rendu : graphiques, infobulles, jauge, anneau et suivi animés. */
	$vue_bo         = sanitize_key( $_GET['vue'] ?? 'bord' ); // phpcs:ignore -- lecture seule
	$bord_scolarite = ( is_page_template( 'page-scolarite.php' ) && is_user_logged_in() && function_exists( 'ueb_est_scolarite' ) && ueb_est_scolarite()
			&& ( in_array( $vue_bo, array( 'bord', 'quitus', 'paiements' ), true ) || isset( $_GET['quitus'] ) ) ) // phpcs:ignore
		|| ( $admin && in_array( $vue_bo, array( 'bord', 'paiements' ), true ) );
	if ( $bord_scolarite ) {
		ueb_style( 'ueb-bord', 'assets/css/bord.css', array( 'ueb-pages' ) );
		ueb_style( 'ueb-bord-graphes', 'assets/css/bord-graphes.css', array( 'ueb-bord' ) );
		if ( 'paiements' === $vue_bo ) {
			ueb_style( 'ueb-scolarite-dashboard', 'assets/css/scolarite-dashboard.css', array( 'ueb-bord-graphes' ) );
		}
		if ( 'paiements' === $vue_bo ) {
			ueb_style( 'ueb-paiements', 'assets/css/paiements.css', array( 'ueb-scolarite-dashboard' ) );
			ueb_script( 'ueb-paiements', 'assets/js/paiements.js' );
		}
		ueb_script( 'ueb-bord', 'assets/js/bord.js' );
		/* Registre des quitus : compteurs, dossiers et validation depuis la ligne. */
		if ( is_page_template( 'page-scolarite.php' ) && 'quitus' === $vue_bo && ! isset( $_GET['quitus'] ) ) { // phpcs:ignore -- lecture seule
			ueb_style( 'ueb-quitus-registre', 'assets/css/quitus-registre.css', array( 'ueb-bord' ) );
			ueb_script( 'ueb-quitus-registre', 'assets/js/quitus-registre.js', array( 'ueb-app', 'ueb-remotion' ) );
		}
	}
	/* Tableau de bord de la scolarité : le même que celui de l'administration
	   (cartes à mini-courbes, anneau, évolution, mouvement), sans la bascule de thème. */
	$tableau_scolarite = is_page_template( 'page-scolarite.php' ) && 'bord' === $vue_bo && ! isset( $_GET['quitus'] ) // phpcs:ignore -- lecture seule
		&& is_user_logged_in() && function_exists( 'ueb_est_scolarite' ) && ueb_est_scolarite() && ueb_peut( UEB_CAP_GESTION );
	if ( $tableau_scolarite ) {
		ueb_style( 'ueb-administration', 'assets/css/administration.css', array( 'ueb-pages', 'ueb-bord-graphes' ) );
		ueb_style( 'ueb-administration-dashboard', 'assets/css/administration-dashboard.css', array( 'ueb-administration' ) );
		ueb_style( 'ueb-administration-analytics', 'assets/css/administration-analytics.css', array( 'ueb-administration-dashboard' ) );
		ueb_script( 'ueb-administration-sparklines', 'assets/js/administration-sparklines.js' );
		ueb_script( 'gsap', 'assets/js/vendor/gsap.min.js' );
		ueb_script( 'ueb-administration-mouvement', 'assets/js/administration-mouvement.js', array( 'gsap', 'ueb-remotion' ) );
	}
	/* Administration : coque, composants et thème clair / sombre, chargés en
	   dernier pour habiller aussi les composants partagés. */
	if ( $admin ) {
		$deps = array( 'ueb-pages' );
		if ( $bord_scolarite ) {
			$deps[] = 'ueb-bord-graphes';
		}
		if ( 'paiements' === $vue_bo ) {
			$deps[] = 'ueb-paiements';
		}
		ueb_style( 'ueb-administration', 'assets/css/administration.css', $deps );
		if ( 'paiements' === $vue_bo ) {
			ueb_style( 'ueb-administration-paiements', 'assets/css/administration-paiements.css', array( 'ueb-administration' ) );
			ueb_script( 'ueb-administration-paiements', 'assets/js/administration-paiements.js' );
		}
		if ( 'bord' === $vue_bo ) {
			ueb_style( 'ueb-administration-dashboard', 'assets/css/administration-dashboard.css', array( 'ueb-administration' ) );
			ueb_style( 'ueb-administration-analytics', 'assets/css/administration-analytics.css', array( 'ueb-administration-dashboard' ) );
			ueb_script( 'ueb-administration-sparklines', 'assets/js/administration-sparklines.js' );
		}
		if ( in_array( $vue_bo, array( 'bord', 'paiements' ), true ) ) {
			/* Mouvement du tableau de bord et du suivi des paiements : GSAP (livré
			   avec le thème) et les anneaux Remotion, montés à leur entrée à l'écran. */
			ueb_script( 'gsap', 'assets/js/vendor/gsap.min.js' );
			ueb_script( 'ueb-administration-mouvement', 'assets/js/administration-mouvement.js', array( 'gsap', 'ueb-remotion' ) );
		}
		ueb_script( 'ueb-administration', 'assets/js/administration.js' );
	}
	/* IPES : onglet de l'administration, espace de l'IPES connecté et vue de la
	   scolarité. Même couche que l'administration (panneaux, boutons, héros ;
	   thème clair / sombre hors scolarité), plus ipes.css. La jauge Remotion ne
	   sert qu'aux écrans qui portent le héros des reversements. */
	$ipes_espace    = is_page_template( 'page-ipes.php' ) && function_exists( 'ueb_ipes_du_compte' ) && ueb_ipes_du_compte();
	$ipes_scolarite = is_page_template( 'page-scolarite.php' ) && 'ipes' === $vue_bo && is_user_logged_in() && function_exists( 'ueb_est_scolarite' ) && ueb_est_scolarite();
	/* L'onglet Filières de l'administration reprend les composants de l'onglet IPES. */
	$ipes_admin     = $admin && in_array( $vue_bo, array( 'ipes', 'filieres' ), true );
	/* Fiche d’\un quitus de la scolarité : mêmes panneaux que la fiche d’\un IPES. */
	$quitus_scolarite = is_page_template( 'page-scolarite.php' ) && ctype_digit( (string) ( $_GET['quitus'] ?? '' ) ) && is_user_logged_in() && function_exists( 'ueb_est_scolarite' ) && ueb_est_scolarite(); // phpcs:ignore -- lecture seule
	if ( $ipes_espace || $ipes_scolarite || $ipes_admin || $quitus_scolarite ) {
		if ( ! $admin ) {
			ueb_style( 'ueb-administration', 'assets/css/administration.css', array( 'ueb-pages' ) );
		}
		if ( $ipes_espace ) {
			ueb_script( 'ueb-administration', 'assets/js/administration.js' );
		}
		ueb_style( 'ueb-ipes', 'assets/css/ipes.css', array( 'ueb-administration' ) );
		if ( $quitus_scolarite ) {
			ueb_style( 'ueb-quitus-fiche', 'assets/css/quitus-fiche.css', array( 'ueb-ipes' ) );
			ueb_script( 'ueb-quitus-fiche', 'assets/js/quitus-fiche.js' );
		}
	}
	$ipes_heros = ( ( $ipes_admin || $ipes_scolarite ) && ctype_digit( (string) ( $_GET['ipes'] ?? '' ) ) && ! isset( $_GET['etudiant'] ) ) // phpcs:ignore -- lecture seule
		|| ( $ipes_espace && ! isset( $_GET['vue'] ) ) || ( $ipes_espace && 'bord' === $vue_bo );
	/* Espace de gestion (Direction) : même rendu que celui de la préinscription.
	   Connecté, il reprend la couche de l'administration (boutons, jetons,
	   thème clair / sombre) ; l'écran de connexion n'a que direction.css. */
	if ( is_page_template( 'page-direction.php' ) ) {
		$direction = function_exists( 'ueb_peut' ) && ueb_peut( UEB_CAP_DIRECTION );
		if ( $direction ) {
			ueb_style( 'ueb-administration', 'assets/css/administration.css', array( 'ueb-pages' ) );
			ueb_script( 'ueb-administration', 'assets/js/administration.js' );
		}
		ueb_style( 'ueb-direction', 'assets/css/direction.css', array( $direction ? 'ueb-administration' : 'ueb-pages' ) );
		ueb_script( 'ueb-direction', 'assets/js/direction.js', array( 'ueb-app' ) );
	}
	if ( ( is_front_page() && ! $page ) || in_array( $page, array( 'connexion', 'creer-compte', 'mdp-oublie' ), true ) || $connexion_scolarite || $bord_scolarite || $ipes_heros ) {
		ueb_script( 'ueb-remotion', 'assets/js/remotion-ueb.js' );
	}
	/* Étudiants UEB : registre de l'administration, de l'espace de gestion et de
	   l'espace scolarité ; couche de l'administration et listes en pilule des IPES. */
	$vue_etudiants = 'etudiants' === $vue_bo && is_user_logged_in() && (
		$admin
		|| is_page_template( 'page-direction.php' )
		|| ( is_page_template( 'page-scolarite.php' ) && function_exists( 'ueb_est_scolarite' ) && ueb_est_scolarite() )
	);
	if ( $vue_etudiants ) {
		ueb_style( 'ueb-administration', 'assets/css/administration.css', array( 'ueb-pages' ) );
		ueb_style( 'ueb-ipes', 'assets/css/ipes.css', array( 'ueb-administration' ) );
		ueb_style( 'ueb-etudiants', 'assets/css/etudiants.css', array( 'ueb-ipes' ) );
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
