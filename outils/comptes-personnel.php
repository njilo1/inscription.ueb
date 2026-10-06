<?php
/**
 * Comptes du personnel : création en lot.
 *
 * Lit la liste outils/comptes-personnel.csv (identifiant;role;etablissement;
 * modules), crée ou met à jour les rôles qu'elle cite, puis crée les comptes.
 * Chaque compte reçoit un mot de passe aléatoire (10 caractères, lettres et
 * chiffres). Il n'est écrit que dans credentials_temp.csv : ce fichier est
 * ignoré par git et lisible par son seul propriétaire. Le distribuer, puis le
 * supprimer.
 *
 * --mots-de-passe=FICHIER : reprend les mots de passe d'une liste déjà
 * distribuée (identifiant;mot_de_passe;…), pour qu'un autre site (local, prod)
 * ait les mêmes accès ; un compte absent de cette liste en reçoit un aléatoire.
 *
 * Le script ne supprime jamais rien : les anciens comptes se retirent un par
 * un depuis l'onglet Personnel de l'Administration.
 *
 * Un identifiant déjà pris est laissé tel quel (signalé), sans nouveau mot de passe.
 *
 * Usage, depuis le dossier du thème (sous XAMPP : /opt/lampp/bin/php) :
 *   php outils/comptes-personnel.php --simulation
 *   php outils/comptes-personnel.php --mots-de-passe=/chemin/credentials_temp.csv
 * Autres options : --liste=FICHIER  --sortie=FICHIER (défaut : credentials_temp.csv)
 *
 * @package Inscription_UEB
 */

if ( PHP_SAPI !== 'cli' ) {
	exit;
}
umask( 0077 ); // sauvegarde et mots de passe : lisibles par le seul propriétaire

$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? 'localhost';
require dirname( __DIR__, 4 ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$theme      = dirname( __DIR__ );
$options    = getopt( '', array( 'liste:', 'sortie:', 'mots-de-passe:', 'simulation' ) );
$liste      = $options['liste'] ?? __DIR__ . '/comptes-personnel.csv';
$sortie     = $options['sortie'] ?? $theme . '/credentials_temp.csv';
$connus     = array(); // identifiant => mot de passe déjà distribué (--mots-de-passe)
$simulation = isset( $options['simulation'] );

function arreter( $message ) {
	fwrite( STDERR, "Arrêt : $message\n" );
	exit( 1 );
}

/* ---------- Rôles ----------
   D'après la liste des utilisateurs de la plateforme. Portée et permissions de
   la liste blanche (inc/roles.php) ; les modules (droits universitaires,
   visite médicale) sont ensuite filtrés compte par compte
   (inc/acces-modules.php), statistiques comprises. Les statistiques
   (ueb_voir_stats, ueb_voir_stats_cms) sont en lecture seule, sans les reçus. */
$stats = array( 'ueb_voir_stats', 'ueb_voir_stats_cms', 'ueb_voir_paiements' );
$roles   = array(
	/* Statistiques de son établissement, reçus des droits universitaires (consulter, valider, rejeter), ses étudiants. */
	'Scolarité'       => array( 'un', array( 'ueb_voir_stats', 'ueb_gerer_quitus', 'ueb_decider_quitus', 'ueb_voir_paiements', 'ueb_voir_etudiants' ) ),
	/* Cellule informatique : la seule réinitialisation des mots de passe des étudiants. */
	'Celinfo'         => array( 'un', array( 'ueb_reinit_mdp' ) ),
	/* Statistiques et étudiants de son établissement, IPES sous tutelle et validation de leurs bordereaux. */
	'Doyen/Directeur' => array( 'un', array( 'ueb_voir_stats', 'ueb_voir_paiements', 'ueb_voir_etudiants', 'ueb_voir_ipes', 'ueb_verifier_ipes' ), array( 'FS' => 'Doyen', 'FSJP' => 'Doyen', 'FSEG' => 'Doyen', 'FALSH' => 'Doyen', 'defaut' => 'Directeur' ) ),
	/* Régie CMS : reçus des frais médicaux (consulter, accepter, refuser) et leurs statistiques, tous établissements. */
	'Régie CMS'       => array( 'tous', array( 'ueb_voir_cms', 'ueb_decider_cms', 'ueb_voir_stats_cms' ) ),
	/* Chef CMS : les seules statistiques des frais médicaux. */
	'Chef CMS'        => array( 'tous', array( 'ueb_voir_stats_cms' ) ),
	/* Recteur : tout voir dans l'UEb, en lecture seule, sans les reçus. */
	'Recteur'         => array( 'tous', array_merge( $stats, array( 'ueb_voir_etudiants', 'ueb_voir_ipes' ) ) ),
	/* Contrôle financier : toutes les statistiques, en lecture seule. */
	'CF'              => array( 'tous', $stats ),
	/* DAAF : rôle encore à préciser ; en attendant, les statistiques en lecture seule. */
	'DAAF'            => array( 'tous', $stats ),
);
/* Mots de passe déjà distribués : identifiant;mot_de_passe;… */
if ( isset( $options['mots-de-passe'] ) ) {
	if ( ! is_readable( $options['mots-de-passe'] ) ) {
		arreter( "liste des mots de passe introuvable ({$options['mots-de-passe']})." );
	}
	foreach ( array_slice( file( $options['mots-de-passe'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ), 1 ) as $ligne ) {
		list( $login, $mdp ) = array_map( 'trim', array_pad( explode( ';', $ligne ), 2, '' ) );
		if ( '' !== $login && '' !== $mdp ) {
			$connus[ $login ] = $mdp;
		}
	}
	echo count( $connus ) . " mot(s) de passe repris de la liste distribuée.\n";
}

/* ---------- Liste des comptes ---------- */
if ( ! is_readable( $liste ) ) {
	arreter( "liste introuvable ($liste)." );
}
$comptes = array();
foreach ( array_slice( file( $liste, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ), 1 ) as $n => $ligne ) {
	list( $login, $role, $etab, $modules ) = array_map( 'trim', array_pad( explode( ';', $ligne ), 4, '' ) );
	$etab = strtoupper( $etab );
	if ( ! isset( $roles[ $role ] ) ) {
		arreter( "ligne " . ( $n + 2 ) . " : rôle inconnu « $role »." );
	}
	if ( 'un' === $roles[ $role ][0] && ! ueb_etablissement( $etab ) ) {
		arreter( "ligne " . ( $n + 2 ) . " : établissement inconnu « $etab »." );
	}
	if ( ! isset( UEB_ACCES_MODULES[ $modules ] ) ) {
		arreter( "ligne " . ( $n + 2 ) . " : accès aux modules « $modules » (du, vm, tous ou aucun)." );
	}
	if ( sanitize_user( $login, true ) !== $login ) {
		arreter( "ligne " . ( $n + 2 ) . " : identifiant invalide « $login »." );
	}
	$comptes[] = compact( 'login', 'role', 'etab', 'modules' );
}

/* ---------- Compte qui agit : le premier administrateur ---------- */
$admins = get_users( array( 'role' => 'administrator', 'orderby' => 'ID' ) );
if ( ! $admins ) {
	arreter( 'aucun administrateur sur ce site.' );
}
$acteur = $admins[0];
wp_set_current_user( $acteur->ID );
/* Son éventuel profil simulé (inc/profil-simule.php) ne doit pas brider le script. */
add_filter( 'get_user_metadata', static fn( $v, $id, $cle ) => 'ueb_profil_simule' === $cle ? array( '' ) : $v, 1, 3 );
echo "Agit en tant que : {$acteur->user_login}" . ( $simulation ? " (SIMULATION : rien n'est écrit)" : '' ) . "\n";

/* ---------- Rôles : créés, ou remis à la définition ci-dessus s'ils existent (même nom) ---------- */
echo "\nRôles :\n";
$slugs = array();
foreach ( array_unique( array_column( $comptes, 'role' ) ) as $nom ) {
	list( $portee, $permissions ) = $roles[ $nom ];
	$intitules = $roles[ $nom ][2] ?? array(); // Doyen ou Directeur selon l'établissement
	$slug = '';
	foreach ( ueb_roles() as $s => $def ) {
		if ( $def['nom'] === $nom ) {
			$slug = $s;
		}
	}
	$existant = '' !== $slug;
	$slug     = $existant ? $slug : ueb_nouveau_slug_role();
	echo '  ' . ( $existant ? 'mis à jour' : 'créé' ) . " : $nom ($portee) — " . implode( ', ', $permissions ) . "\n";
	$slugs[ $nom ] = $slug;
	if ( ! $simulation ) {
		ueb_enregistrer_role( $slug, array(
			'nom'            => $nom,
			'portee'         => $portee,
			'etablissements' => array(),
			'permissions'    => $permissions,
			'intitules'      => $intitules,
			'historique'     => false,
			'cree_le'        => $existant ? ueb_role( $slug )['cree_le'] : current_time( 'mysql' ),
			'modifie_le'     => current_time( 'mysql' ),
			'modifie_par'    => $acteur->ID,
		) );
	}
}

/* ---------- Comptes ---------- */
function mot_de_passe_aleatoire( $longueur = 10 ) {
	$signes = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // sans 0, O, 1, l, I
	do {
		$mdp = '';
		for ( $i = 0; $i < $longueur; $i++ ) {
			$mdp .= $signes[ random_int( 0, strlen( $signes ) - 1 ) ];
		}
	} while ( ! preg_match( '/[A-Za-z]/', $mdp ) || ! preg_match( '/[0-9]/', $mdp ) );
	return $mdp;
}

/* Nom affiché : la fonction (Doyen ou Directeur d'après l'établissement, Régie CMS…) et l'établissement. */
$fonctions = array( 'doyen' => 'Doyen', 'directeur' => 'Directeur', 'regie' => 'Régie CMS', 'chef' => 'Chef CMS' );

echo "\nComptes :\n";
$crees = array();
foreach ( $comptes as $c ) {
	if ( username_exists( $c['login'] ) ) {
		echo "  déjà pris, laissé tel quel : {$c['login']}\n";
		continue;
	}
	$un  = 'un' === $roles[ $c['role'] ][0];
	$nom = ( isset( $roles[ $c['role'] ][2] ) ? ueb_intitule_role( array( 'nom' => $c['role'], 'intitules' => $roles[ $c['role'] ][2] ), $c['etab'] ) : ( $fonctions[ strtok( $c['login'], '.@' ) ] ?? $c['role'] ) ) . ( $un ? ' ' . $c['etab'] : '' );
	if ( $simulation ) {
		echo "  à créer : {$c['login']} — $nom — {$c['modules']}\n";
		continue;
	}
	$mdp      = $connus[ $c['login'] ] ?? mot_de_passe_aleatoire();
	$resultat =ueb_creer_compte_agent( array(
		'login'         => $c['login'],
		'nom'           => $nom,
		'email'         => '',
		'role'          => $slugs[ $c['role'] ],
		'etablissement' => $un ? $c['etab'] : '',
		'mot_de_passe'  => $mdp,
	) );
	if ( is_wp_error( $resultat ) ) {
		echo "  ÉCHEC {$c['login']} : " . $resultat->get_error_message() . "\n";
		continue;
	}
	ueb_definir_acces_modules( $resultat[0], $c['modules'] );
	$crees[] = array( $c['login'], $mdp, $c['role'], $un ? $c['etab'] : 'tous', $c['modules'] );
	echo "  créé : {$c['login']} — $nom — {$c['modules']}\n";
}

/* ---------- Mots de passe : ajoutés au fichier de sortie, jamais affichés ---------- */
if ( $crees ) {
	$nouveau = ! file_exists( $sortie );
	$f       = fopen( $sortie, 'a' );
	if ( $nouveau ) {
		fwrite( $f, "identifiant;mot_de_passe;role;etablissement;modules\n" );
	}
	foreach ( $crees as $ligne ) {
		fwrite( $f, implode( ';', $ligne ) . "\n" );
	}
	fclose( $f );
	chmod( $sortie, 0600 );
}
echo "\n" . count( $crees ) . ' compte(s) créé(s) sur ' . count( $comptes ) . ( $crees ? ". Mots de passe : $sortie (à distribuer puis supprimer)" : '' ) . ".\n";
