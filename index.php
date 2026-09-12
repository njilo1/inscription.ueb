<?php
/**
 * Gabarit de repli : ce site n'affiche pas d'articles, on renvoie à l'accueil.
 *
 * @package Inscription_UEB
 */

wp_safe_redirect( home_url( '/' ) );
exit;
