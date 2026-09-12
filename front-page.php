<?php
/**
 * Page d'accueil (templates/landing/accueil.php), avec la fiche
 * établissement partagée et l'animation Remotion du parcours.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

$donnees = ueb_landing_donnees();

ueb_page_debut( array( 'variante' => 'accueil', 'classe' => 'landing-accueil' ) );
get_template_part( 'templates/landing/accueil', null, $donnees );
get_template_part( 'templates/landing/fiche', 'etab', $donnees );
ueb_page_fin( 'landing' );
