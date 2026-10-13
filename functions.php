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
 *   inc/profil.php        fiche de l'étudiant (Mon compte), reprise par ses quitus
 *   inc/etudiants.php     liste des étudiants inscrits (Étudiants UEB), selon la portée
 *   inc/quitus-pdf.php    génération du PDF (modèle A, 4 coupons)
 *   inc/recus.php         envoi et consultation des reçus bancaires
 *   inc/gestion.php       espace d'administration
 *   inc/suggestions.php   recherche intelligente : suggestions dès la première lettre
 *   inc/acces-modules.php modules visibles par compte : droits universitaires (du), visite médicale (vm)
 *   inc/direction.php     rôles dynamiques et personnel (espace Direction)
 *   inc/comptes-plateforme.php rôles et comptes de la liste des utilisateurs (bouton du Personnel, outils/)
 *   inc/ipes.php          établissements privés sous tutelle (IPES)
 *   inc/ipes-filieres.php filières des IPES
 *   inc/ipes-etudiants.php étudiants des IPES et leurs versements de pension
 *   inc/ipes-bordereaux.php bordereaux de reversement des IPES à leur tutelle
 *   inc/ipes-recus.php    reçus bancaires des bordereaux (dépôt et lecture protégés)
 *   inc/ipes-bordereau-pdf.php PDF d'un bordereau (en-tête officiel de la tutelle)
 *   inc/ipes-espace.php   espace de l'administrateur d'un IPES : page et accès
 *   inc/ipes-tutelle.php  IPES vus par leur établissement de tutelle (espace scolarité)
 *   inc/filieres-catalogue.php catalogue des filières (onglet Filières de l'administration)
 *   inc/administration.php composants de l'espace Administration
 *   inc/attente-recus.php reçus en attente de validation : carte rouge, file, actualisation
 *   inc/cms-tableau.php   tableau de bord du Centre médico-social (frais médicaux)
 *   inc/ipes-vues.php     composants d'affichage des IPES (trois espaces)
 *   inc/assets.php       feuilles de style et scripts
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

define( 'UEB_INSC_VERSION', '1.0.0' );
define( 'UEB_INSC_DIR', get_template_directory() );
define( 'UEB_INSC_URI', get_template_directory_uri() );

foreach ( array( 'config', 'db-schema', 'session', 'routes', 'roles', 'acces-modules', 'espaces', 'profil-simule', 'comptes', 'nombres', 'inscription', 'quitus', 'profil', 'etudiants', 'quitus-pdf', 'recus', 'gestion', 'suggestions', 'direction', 'comptes-plateforme', 'ipes', 'ipes-filieres', 'ipes-etudiants', 'ipes-bordereaux', 'ipes-recus', 'ipes-bordereau-pdf', 'ipes-espace', 'ipes-tutelle', 'filieres-catalogue', 'assets', 'vues', 'bord', 'bord-graphes', 'administration', 'attente-recus', 'cms-tableau', 'ipes-vues', 'landing' ) as $ueb_module ) {
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
	/* Caméra permise pour ce site seul (photo du reçu bancaire, templates/recus.php). */
	header( 'Permissions-Policy: camera=(self), microphone=(), geolocation=()' );
} );
