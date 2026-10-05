<?php
/**
 * Régressions du partage des reçus entre la scolarité et le CMS
 * (inc/gestion.php, « Qui voit et qui valide quel reçu ») : le reçu des
 * droits universitaires va à la scolarité, celui des frais médicaux au
 * Régisseur CMS ; chacun ne voit et ne valide que les siens, dans sa portée.
 *
 * Charge WordPress COMPLET et travaille sur la base locale : rôles, comptes
 * et quitus temporaires, préfixés « test-cms » / « TEST-CMS », supprimés à la
 * fin même en cas d'échec, et au début s'il en reste. Les listes ne sont
 * comparées que sur ces données de test. Données exclusivement fictives.
 *
 * Usage : php tests/regisseur-cms.php (PHP de XAMPP ; sous Linux /opt/lampp/bin/php)
 */
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? 'localhost';
require dirname( __DIR__, 4 ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

function verifier( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( 'ÉCHEC : ' . $message );
	}
	$GLOBALS['assertions'] = ( $GLOBALS['assertions'] ?? 0 ) + 1;
}

/** Supprime les comptes du personnel, rôles, étudiants et quitus de test. */
function nettoyer( array $agents, array $roles ) {
	global $wpdb;
	foreach ( $agents as $id ) {
		wp_delete_user( $id );
	}
	foreach ( $roles as $slug ) {
		ueb_retirer_role( $slug );
	}
	foreach ( array_map( 'intval', $wpdb->get_col( "SELECT id FROM ueb_insc_comptes WHERE matricule LIKE 'TEST-CMS-%'" ) ) as $id ) {
		foreach ( array( 'ueb_insc_recus', 'ueb_insc_quitus', 'ueb_insc_profils' ) as $table ) {
			$wpdb->delete( $table, array( 'compte_id' => $id ) );
		}
		$wpdb->delete( 'ueb_insc_comptes', array( 'id' => $id ) );
	}
	$wpdb->query( "DELETE FROM ueb_insc_sequence WHERE etablissement = 'TCMS'" );
}

/** Types des paiements des dossiers de test d'une liste du registre. */
function types_registre( array $liste ) {
	$types = array();
	foreach ( $liste['lignes'] as $ligne ) {
		foreach ( $ligne->paiements as $q ) {
			if ( str_starts_with( $q->identifiant, 'TEST-CMS-' ) ) {
				$types[] = $q->etablissement . ':' . $q->type;
			}
		}
	}
	sort( $types );
	return $types;
}

global $wpdb;
$crees = array( 'agents' => array(), 'roles' => array() );
$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );

try {
	verifier( ! empty( $admin ), 'un administrateur existe pour préparer les données' );
	wp_set_current_user( (int) $admin[0] );
	nettoyer(
		get_users( array( 'search' => 'test-cms.*', 'search_columns' => array( 'user_login' ), 'fields' => 'ID' ) ),
		array_filter( array_keys( ueb_roles() ), static fn( $slug ) => str_starts_with( $slug, 'test-cms-' ) )
	);
	$m     = current_time( 'mysql' );
	$annee = ueb_annee_academique();

	/* ---------- Permissions proposées à la création des rôles ---------- */
	$permissions = ueb_permissions();
	verifier( isset( $permissions['ueb_voir_cms'], $permissions['ueb_decider_cms'] ), 'les deux permissions du CMS sont proposées dans la création des rôles' );
	verifier( 'ueb_voir_cms' === ( $permissions['ueb_decider_cms']['requiert'] ?? '' ), 'valider les reçus CMS requiert de les consulter' );
	verifier( isset( ueb_modeles_roles()['cms'] ) && array( 'ueb_voir_cms', 'ueb_decider_cms' ) === ueb_modeles_roles()['cms']['permissions'], 'modèle « Régisseur CMS » dans l’assistant' );
	verifier( user_can( (int) $admin[0], 'ueb_voir_cms' ) && user_can( (int) $admin[0], 'ueb_decider_cms' ), 'l’administrateur reçoit les permissions du CMS' );

	/* ---------- Rôles, comme la Direction les créerait ---------- */
	$roles = array(
		'test-cms-scolarite' => array( 'un', array( UEB_CAP_GESTION, 'ueb_decider_quitus' ) ),
		'test-cms-regisseur' => array( 'tous', array( 'ueb_voir_cms', 'ueb_decider_cms' ) ),
		'test-cms-lecteur'   => array( 'un', array( 'ueb_voir_cms' ) ),
	);
	foreach ( $roles as $slug => $r ) {
		ueb_enregistrer_role( $slug, array( 'nom' => $slug, 'portee' => $r[0], 'etablissements' => array(), 'permissions' => $r[1], 'historique' => false, 'cree_le' => $m, 'modifie_le' => $m, 'modifie_par' => (int) $admin[0] ) );
		$crees['roles'][] = $slug;
	}
	$agent = static function ( $login, $role, $etab = 'FS' ) use ( &$crees ) {
		$r = ueb_creer_compte_agent( array( 'login' => $login, 'nom' => $login, 'email' => '', 'etablissement' => $etab, 'mot_de_passe' => 'Test-Cms-2026x', 'role' => $role ) );
		verifier( ! is_wp_error( $r ), "compte $login créé " . ( is_wp_error( $r ) ? $r->get_error_message() : '' ) );
		$crees['agents'][] = $r[0];
		return $r[0];
	};
	$sco_fs    = $agent( 'test-cms.sco.fs', 'test-cms-scolarite' );
	$regisseur = $agent( 'test-cms.regisseur', 'test-cms-regisseur' );
	$lecteur   = $agent( 'test-cms.lecteur.falsh', 'test-cms-lecteur', 'FALSH' );

	/* ---------- Deux étudiants : FS (droits + frais médicaux), FALSH (droits + frais médicaux) ---------- */
	$quitus = array();
	foreach ( array( 'FS', 'FALSH' ) as $n => $etab ) {
		$wpdb->insert( 'ueb_insc_comptes', array( 'matricule' => "TEST-CMS-$n", 'telephone' => '69900000' . $n, 'mot_de_passe' => 'x', 'statut' => 'actif' ) );
		$cid  = (int) $wpdb->insert_id;
		$base = array( 'compte_id' => $cid, 'annee_academique' => $annee['code'], 'etablissement' => $etab, 'nom' => 'CMS', 'prenom' => "Essai $etab", 'date_naissance' => '2003-01-01', 'lieu_naissance' => 'Ebolowa', 'sexe' => 'F', 'nationalite' => 'Camerounaise', 'departement' => 'Test', 'parcours' => 'L1', 'identifiant' => "TEST-CMS-$n", 'type_identifiant' => 'matricule', 'statut' => 'recu_envoye' );
		$wpdb->insert( 'ueb_insc_quitus', $base + array( 'type' => 'droits', 'tranche' => 1, 'montant' => 25000, 'numero' => "TCMS-$etab-D", 'code_verif' => bin2hex( random_bytes( 10 ) ) ) );
		$droits = (int) $wpdb->insert_id;
		$wpdb->insert( 'ueb_insc_quitus', $base + array( 'type' => 'medicaux', 'tranche' => 0, 'montant' => 3000, 'quitus_droits_id' => $droits, 'numero' => "TCMS-$etab-M", 'code_verif' => bin2hex( random_bytes( 10 ) ) ) );
		$medical = (int) $wpdb->insert_id;
		foreach ( array( $droits => 'tranche1', $medical => 'medicaux' ) as $qid => $objet ) {
			$wpdb->insert( 'ueb_insc_recus', array( 'quitus_id' => $qid, 'compte_id' => $cid, 'fichier' => '2026-2027/test-cms.jpg', 'nom_original' => 'test-cms.jpg', 'type_mime' => 'image/jpeg', 'taille' => 1000, 'objet' => $objet ) );
		}
		$quitus[ $etab ] = array( 'droits' => ueb_quitus_par_id( $droits ), 'medicaux' => ueb_quitus_par_id( $medical ) );
	}
	$recu   = static fn( $q ) => $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ueb_insc_recus WHERE quitus_id = %d', $q->id ) );
	$liste  = static fn() => types_registre( ueb_gestion_liste_dossiers( array( 'annee' => $annee['code'] ), 200 ) );
	$attente = static fn() => (int) ueb_gestion_stats( $annee['code'], ueb_etab_agent() )['statuts']['recu_envoye'];

	/* ---------- Scolarité de la FS : les droits universitaires de la FS ---------- */
	wp_set_current_user( $sco_fs );
	verifier( array( 'droits' ) === ueb_types_quitus_visibles(), 'scolarité : ne voit que les droits universitaires' );
	verifier( ueb_peut_voir_quitus( $quitus['FS']['droits'] ) && ueb_peut_decider_quitus( $quitus['FS']['droits'] ), 'scolarité : voit et valide le reçu des droits de la FS' );
	verifier( ! ueb_peut_voir_quitus( $quitus['FS']['medicaux'] ) && ! ueb_peut_decider_quitus( $quitus['FS']['medicaux'] ), 'scolarité : ni ne voit ni ne valide le reçu médical, même de son établissement' );
	verifier( ! ueb_peut_voir_quitus( $quitus['FALSH']['droits'] ), 'scolarité : rien hors de sa portée' );
	verifier( array( 'FS:droits' ) === $liste(), 'scolarité : le registre ne liste que les droits de la FS' );
	verifier( ueb_peut_voir_recu( $recu( $quitus['FS']['droits'] ), null ) && ! ueb_peut_voir_recu( $recu( $quitus['FS']['medicaux'] ), null ), 'scolarité : ouvre la photo du reçu des droits, pas celle du reçu médical' );
	verifier( ueb_est_scolarite(), 'scolarité : accès à l’espace' );

	/* ---------- Régisseur CMS, portée « tous » : les frais médicaux partout ---------- */
	wp_set_current_user( $regisseur );
	verifier( array( 'medicaux' ) === ueb_types_quitus_visibles(), 'CMS : ne voit que les frais médicaux' );
	verifier( ueb_peut_decider_quitus( $quitus['FS']['medicaux'] ) && ueb_peut_decider_quitus( $quitus['FALSH']['medicaux'] ), 'CMS : valide les reçus médicaux de tous les établissements' );
	verifier( ! ueb_peut_voir_quitus( $quitus['FS']['droits'] ) && ! ueb_peut_decider_quitus( $quitus['FALSH']['droits'] ), 'CMS : ni ne voit ni ne valide les droits universitaires' );
	verifier( array( 'FALSH:medicaux', 'FS:medicaux' ) === $liste(), 'CMS : le registre ne liste que les frais médicaux' );
	verifier( ueb_peut_voir_recu( $recu( $quitus['FALSH']['medicaux'] ), null ) && ! ueb_peut_voir_recu( $recu( $quitus['FALSH']['droits'] ), null ), 'CMS : ouvre le reçu médical, pas celui des droits' );
	verifier( ueb_est_scolarite() && ! ueb_peut( UEB_CAP_GESTION ), 'CMS : entre dans l’espace sans le tableau de bord des droits' );
	$suivant = ueb_gestion_quitus_suivant( $quitus['FS']['droits'] );
	verifier( null === $suivant || 'medicaux' === ueb_quitus_par_id( $suivant['id'] )->type, 'CMS : le dossier suivant proposé est un reçu médical' );

	/* ---------- Lecteur CMS de la FALSH : consulte sans valider ---------- */
	wp_set_current_user( $lecteur );
	verifier( ueb_peut_voir_quitus( $quitus['FALSH']['medicaux'] ) && ! ueb_peut_decider_quitus( $quitus['FALSH']['medicaux'] ), 'lecteur CMS : consulte sans valider' );
	verifier( ! ueb_peut_voir_quitus( $quitus['FS']['medicaux'] ), 'lecteur CMS : portée limitée à son établissement' );
	verifier( array( 'FALSH:medicaux' ) === $liste(), 'lecteur CMS : registre limité aux frais médicaux de la FALSH' );

	/* ---------- Administrateur : tout ---------- */
	wp_set_current_user( (int) $admin[0] );
	verifier( array( 'droits', 'medicaux' ) === ueb_types_quitus_visibles(), 'administrateur : les deux types' );
	verifier( array( 'FALSH:droits', 'FALSH:medicaux', 'FS:droits', 'FS:medicaux' ) === $liste(), 'administrateur : le registre liste tout' );
	verifier( ueb_peut_decider_quitus( $quitus['FS']['droits'] ) && ueb_peut_decider_quitus( $quitus['FALSH']['medicaux'] ), 'administrateur : valide tout' );

	/* ---------- Visiteur ---------- */
	wp_set_current_user( 0 );
	verifier( array() === ueb_types_quitus_visibles() && ! ueb_peut_voir_quitus( $quitus['FS']['droits'] ), 'visiteur : rien' );

	echo $GLOBALS['assertions'] . " vérifications réussies.\n";
} finally {
	wp_set_current_user( (int) ( $admin[0] ?? 0 ) );
	nettoyer( $crees['agents'], $crees['roles'] );
}
