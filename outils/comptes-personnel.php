<?php
/**
 * Comptes du personnel : remise à zéro et création en lot.
 *
 * Lit la liste outils/comptes-personnel.csv (identifiant;role;etablissement;
 * modules), crée ou met à jour les rôles qu'elle cite, puis crée les comptes.
 * Chaque compte reçoit un mot de passe aléatoire (10 caractères, lettres et
 * chiffres). Il n'est écrit que dans credentials_temp.csv : ce fichier est
 * ignoré par git et lisible par son seul propriétaire. Le distribuer, puis le
 * supprimer.
 *
 * --purger : avant la création, supprime tous les comptes du Personnel (rôles
 * du registre) et tous les rôles du registre. Les administrateurs et les
 * comptes de --garder ne sont jamais supprimés. Une sauvegarde JSON (comptes,
 * métas, rôles) est d'abord écrite dans sauvegardes/. Un compte qui a aussi
 * un rôle sur la préinscription (table des comptes partagée) n'est pas
 * supprimé : il perd seulement son rôle ici. Étudiants, quitus et paiements ne
 * sont pas touchés.
 *
 * Un identifiant déjà pris est laissé tel quel (signalé), sans nouveau mot de passe.
 *
 * Usage, depuis le dossier du thème (sous XAMPP : /opt/lampp/bin/php) :
 *   php outils/comptes-personnel.php --simulation --purger --garder=oriol
 *   php outils/comptes-personnel.php --purger --garder=oriol
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
$options    = getopt( '', array( 'liste:', 'sortie:', 'garder:', 'purger', 'simulation' ) );
$liste      = $options['liste'] ?? __DIR__ . '/comptes-personnel.csv';
$sortie     = $options['sortie'] ?? $theme . '/credentials_temp.csv';
$garder     = array_filter( array_map( 'trim', explode( ',', (string) ( $options['garder'] ?? '' ) ) ) );
$purger     = isset( $options['purger'] );
$simulation = isset( $options['simulation'] );

function arreter( $message ) {
	fwrite( STDERR, "Arrêt : $message\n" );
	exit( 1 );
}

/* ---------- Rôles ----------
   Portée et permissions de la liste blanche (inc/roles.php). Lecture seule :
   aucune permission de décision ni de gestion. Les modules (droits
   universitaires, visite médicale) sont ensuite filtrés compte par compte
   (inc/acces-modules.php) : un rôle porte les deux quand ses comptes peuvent
   voir l'un ou l'autre. */
$lecture = array( 'ueb_gerer_quitus', 'ueb_voir_cms', 'ueb_voir_paiements', 'ueb_voir_etudiants', 'ueb_voir_ipes' );
$roles   = array(
	'Scolarité'       => array( 'un', null ), // permissions de l'ancien rôle Scolarité, lues ci-dessous
	'Celinfo'         => array( 'un', array( 'ueb_gerer_comptes', 'ueb_gerer_quitus' ) ),
	'Doyen/Directeur' => array( 'un', $lecture ),
	'Régie'           => array( 'tous', array( 'ueb_voir_cms', 'ueb_decider_cms', 'ueb_voir_etudiants' ) ),
	'CMS'             => array( 'tous', array( 'ueb_voir_cms', 'ueb_decider_cms', 'ueb_voir_etudiants' ) ),
	'Recteur'         => array( 'tous', $lecture ),
	'CF'              => array( 'tous', $lecture ),
	'DAAF'            => array( 'tous', $lecture ),
);
/* Scolarité : les permissions de l'ancien rôle de ce nom s'il existe encore, sinon celles du modèle historique. */
$roles['Scolarité'][1] = array( 'ueb_gerer_quitus', 'ueb_decider_quitus', 'ueb_voir_paiements', 'ueb_creer_agents', 'ueb_gerer_comptes' );
foreach ( ueb_roles() as $def ) {
	if ( 0 === strpos( strtolower( remove_accents( $def['nom'] ) ), 'scolarite' ) ) {
		$roles['Scolarité'][1] = array_values( array_intersect( (array) $def['permissions'], array_keys( ueb_permissions() ) ) );
		echo "Scolarité : permissions reprises de l'ancien rôle « {$def['nom']} » (" . implode( ', ', $roles['Scolarité'][1] ) . ")\n";
		break;
	}
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

/* ---------- Compte qui agit : un administrateur (de préférence un compte gardé) ---------- */
$admins = get_users( array( 'role' => 'administrator', 'orderby' => 'ID' ) );
if ( ! $admins ) {
	arreter( 'aucun administrateur sur ce site.' );
}
$acteur = $admins[0];
foreach ( $admins as $a ) {
	if ( in_array( $a->user_login, $garder, true ) ) {
		$acteur = $a;
		break;
	}
}
wp_set_current_user( $acteur->ID );
/* Son éventuel profil simulé (inc/profil-simule.php) ne doit pas brider le script. */
add_filter( 'get_user_metadata', static fn( $v, $id, $cle ) => 'ueb_profil_simule' === $cle ? array( '' ) : $v, 1, 3 );
echo "Agit en tant que : {$acteur->user_login}" . ( $simulation ? " (SIMULATION : rien n'est écrit)" : '' ) . "\n";

/* ---------- Purge, après sauvegarde ---------- */
if ( $purger ) {
	global $wpdb;
	$cible = array_values( array_filter( ueb_agents(), static fn( $u ) => ! user_can( $u, 'manage_options' ) && ! in_array( $u->user_login, $garder, true ) ) );
	echo "\nComptes du Personnel à retirer : " . count( $cible ) . "\n";

	if ( ! $simulation ) {
		$dossier = $theme . '/sauvegardes';
		wp_mkdir_p( $dossier );
		file_put_contents( $dossier . '/.htaccess', "Require all denied\n" );
		$copie = array(
			'date'               => current_time( 'mysql' ),
			'site'               => home_url(),
			'ueb_roles_registre' => get_option( 'ueb_roles_registre' ),
			'roles_wordpress'    => get_option( $wpdb->get_blog_prefix() . 'user_roles' ),
			'comptes'            => array(),
		);
		foreach ( $cible as $u ) {
			$copie['comptes'][] = array(
				'compte' => $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->users} WHERE ID = %d", $u->ID ), ARRAY_A ),
				'metas'  => $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d", $u->ID ), ARRAY_A ),
			);
		}
		$fichier = $dossier . '/personnel-' . gmdate( 'Ymd-His' ) . '.json';
		if ( ! file_put_contents( $fichier, wp_json_encode( $copie, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) ) {
			arreter( "sauvegarde impossible ($fichier) : rien n'a été supprimé." );
		}
		echo "Sauvegarde : $fichier\n";
	}

	$cle_ici = $wpdb->get_blog_prefix() . 'capabilities';
	foreach ( $cible as $u ) {
		/* Table des comptes partagée : un rôle sur un autre site (la préinscription) protège le compte. */
		$ailleurs = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s AND meta_key <> %s AND meta_value NOT IN ('', 'a:0:{}')",
			$u->ID,
			'%\_capabilities',
			$cle_ici
		) );
		echo ( $ailleurs ? '  rôle retiré (compte de la préinscription gardé) : ' : '  supprimé : ' ) . $u->user_login . "\n";
		if ( $simulation ) {
			continue;
		}
		if ( $ailleurs ) {
			$u->set_role( '' );
			foreach ( array( 'ueb_etablissement', 'ueb_etab_courant', 'ueb_acces_modules', 'ueb_agent_suspendu' ) as $cle ) {
				delete_user_meta( $u->ID, $cle );
			}
		} else {
			wp_delete_user( $u->ID, $acteur->ID ); // contenus éventuels réattribués ; les décisions déjà prises restent en base
		}
	}

	echo 'Rôles à retirer : ' . implode( ', ', array_map( static fn( $d ) => $d['nom'], ueb_roles() ) ) . "\n";
	if ( ! $simulation ) {
		foreach ( array_keys( ueb_roles() ) as $slug ) {
			ueb_retirer_role( $slug );
		}
	}
}

/* ---------- Rôles : créés, ou remis à la définition ci-dessus s'ils existent (même nom) ---------- */
echo "\nRôles :\n";
$slugs = array();
foreach ( array_unique( array_column( $comptes, 'role' ) ) as $nom ) {
	list( $portee, $permissions ) = $roles[ $nom ];
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

/* Nom affiché : la fonction (Doyen ou Directeur, d'après l'identifiant) et l'établissement. */
$fonctions = array( 'doyen' => 'Doyen', 'directeur' => 'Directeur', 'regie' => 'Régie CMS', 'chef' => 'Chef CMS' );

echo "\nComptes :\n";
$crees = array();
foreach ( $comptes as $c ) {
	if ( username_exists( $c['login'] ) ) {
		echo "  déjà pris, laissé tel quel : {$c['login']}\n";
		continue;
	}
	$un  = 'un' === $roles[ $c['role'] ][0];
	$nom = ( $fonctions[ strtok( $c['login'], '.@' ) ] ?? $c['role'] ) . ( $un ? ' ' . $c['etab'] : '' );
	if ( $simulation ) {
		echo "  à créer : {$c['login']} — $nom — {$c['modules']}\n";
		continue;
	}
	$mdp      = mot_de_passe_aleatoire();
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
