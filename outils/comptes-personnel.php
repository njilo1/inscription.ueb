<?php
/**
 * Comptes du personnel : création en lot, en ligne de commande.
 *
 * Même travail que le bouton « Installer les comptes de la plateforme » de
 * l'onglet Personnel (inc/comptes-plateforme.php) : lit la liste
 * outils/comptes-personnel.csv, crée ou met à jour les rôles qu'elle cite,
 * puis crée les comptes absents. Rien n'est jamais supprimé.
 *
 * --mots-de-passe=FICHIER : reprend les mots de passe d'une liste déjà
 * distribuée (identifiant;mot_de_passe;…) ; un compte absent de cette liste en
 * reçoit un aléatoire, écrit dans credentials_temp.csv (ignoré par git,
 * lisible par son seul propriétaire) : le distribuer, puis le supprimer.
 *
 * Usage, depuis le dossier du thème :
 *   php outils/comptes-personnel.php --simulation --mots-de-passe=/chemin/credentials_temp.csv
 *   php outils/comptes-personnel.php --mots-de-passe=/chemin/credentials_temp.csv
 * Autre option : --sortie=FICHIER (défaut : credentials_temp.csv)
 *
 * @package Inscription_UEB
 */

if ( PHP_SAPI !== 'cli' ) {
	exit;
}
umask( 0077 ); // mots de passe : lisibles par le seul propriétaire

$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? 'localhost';
require dirname( __DIR__, 4 ) . '/wp-load.php';

$options    = getopt( '', array( 'sortie:', 'mots-de-passe:', 'simulation' ) );
$sortie     = $options['sortie'] ?? dirname( __DIR__ ) . '/credentials_temp.csv';
$simulation = isset( $options['simulation'] );
$connus     = array();
if ( isset( $options['mots-de-passe'] ) ) {
	if ( ! is_readable( $options['mots-de-passe'] ) ) {
		fwrite( STDERR, "Arrêt : liste des mots de passe introuvable ({$options['mots-de-passe']}).\n" );
		exit( 1 );
	}
	$connus = ueb_plateforme_mots_de_passe( $options['mots-de-passe'] );
	if ( is_wp_error( $connus ) ) {
		fwrite( STDERR, 'Arrêt : ' . $connus->get_error_message() . "
" );
		exit( 1 );
	}
	echo count( $connus ) . " mot(s) de passe repris de la liste distribuée.\n";
}

/* Compte qui agit : le premier administrateur, sans son éventuel profil simulé (inc/profil-simule.php). */
$admins = get_users( array( 'role' => 'administrator', 'orderby' => 'ID' ) );
if ( ! $admins ) {
	fwrite( STDERR, "Arrêt : aucun administrateur sur ce site.\n" );
	exit( 1 );
}
wp_set_current_user( $admins[0]->ID );
add_filter( 'get_user_metadata', static fn( $v, $id, $cle ) => 'ueb_profil_simule' === $cle ? array( '' ) : $v, 1, 3 );
echo "Agit en tant que : {$admins[0]->user_login}" . ( $simulation ? " (SIMULATION : rien n'est écrit)" : '' ) . "\n\n";

$resultat = ueb_plateforme_installer( $connus, $simulation );
if ( is_wp_error( $resultat ) ) {
	fwrite( STDERR, 'Arrêt : ' . $resultat->get_error_message() . "\n" );
	exit( 1 );
}
echo implode( "\n", $resultat['journal'] ) . "\n";

/* Mots de passe : ajoutés au fichier de sortie, jamais affichés. */
if ( $resultat['crees'] ) {
	$nouveau = ! file_exists( $sortie );
	$f       = fopen( $sortie, 'a' );
	if ( $nouveau ) {
		fwrite( $f, "identifiant;mot_de_passe\n" );
	}
	foreach ( $resultat['crees'] as $c ) {
		fwrite( $f, $c[0] . ';' . $c[1] . "\n" );
	}
	fclose( $f );
	chmod( $sortie, 0600 );
}
echo "\n" . count( $resultat['crees'] ) . ' compte(s) créé(s) sur ' . $resultat['total'] . ( $resultat['crees'] ? ". Mots de passe : $sortie (à distribuer puis supprimer)" : '' ) . ".\n";
