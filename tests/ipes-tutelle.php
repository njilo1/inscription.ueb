<?php
/**
 * Régressions de la portée des tutelles sur les IPES (inc/ipes-tutelle.php) :
 * qui voit quels IPES, quels bordereaux, et qui peut en décider.
 *
 * Charge WordPress COMPLET (ces fonctions dépendent des comptes, des rôles et
 * des capacités) et travaille sur la base locale : rôles, comptes et IPES
 * temporaires, tous préfixés « test-portee », supprimés à la fin même en cas
 * d'échec, et au début s'il en reste d'une exécution interrompue. Les listes
 * d'IPES ne sont comparées que sur ces IPES de test : les vrais IPES de la base
 * locale n'influencent pas le résultat. Données exclusivement fictives.
 *
 * Usage : php tests/ipes-tutelle.php (PHP de XAMPP ; sous Linux /opt/lampp/bin/php)
 */
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
require dirname( __DIR__, 4 ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

function verifier( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( 'ÉCHEC : ' . $message );
	}
	$GLOBALS['assertions'] = ( $GLOBALS['assertions'] ?? 0 ) + 1;
}
/** Sigles des IPES de test d'une liste, triés (les autres IPES de la base sont ignorés). */
function sigles( array $liste ) {
	$s = array_values( array_filter( array_map( static fn( $i ) => $i->sigle, $liste ), static fn( $sigle ) => str_starts_with( $sigle, 'TEST-PORTEE-' ) ) );
	sort( $s );
	return $s;
}

/** Supprime les comptes, rôles et IPES de test (et leurs données). */
function nettoyer( array $comptes, array $roles, array $ipes ) {
	global $wpdb;
	foreach ( $comptes as $id ) {
		wp_delete_user( $id );
	}
	foreach ( $roles as $slug ) {
		ueb_retirer_role( $slug );
	}
	foreach ( $ipes as $id ) {
		foreach ( array( 'ueb_insc_ipes_recus', 'ueb_insc_ipes_etudiants', 'ueb_insc_ipes_bordereaux', 'ueb_insc_ipes_sequence', 'ueb_insc_ipes_filieres', 'ueb_insc_ipes_tutelles' ) as $table ) {
			$wpdb->delete( $table, array( 'ipes_id' => $id ) );
		}
		$wpdb->delete( 'ueb_insc_ipes', array( 'id' => $id ) );
	}
}
/** Identifiants d'une liste de bordereaux, triés. */
function ids( array $liste ) {
	$s = array_map( static fn( $b ) => (int) $b->id, $liste );
	sort( $s );
	return $s;
}

global $wpdb;
$crees = array( 'comptes' => array(), 'roles' => array(), 'ipes' => array() );
$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );

try {
	verifier( ! empty( $admin ), 'un administrateur existe pour préparer les données' );
	wp_set_current_user( (int) $admin[0] );

	/* Restes d'une exécution interrompue (MySQL arrêté, Ctrl+C…) : on repart propre. */
	nettoyer(
		get_users( array( 'search' => 'test-portee.*', 'search_columns' => array( 'user_login' ), 'fields' => 'ID' ) ),
		array_filter( array_keys( ueb_roles() ), static fn( $slug ) => str_starts_with( $slug, 'test-portee-' ) ),
		array_map( 'intval', $wpdb->get_col( "SELECT id FROM ueb_insc_ipes WHERE sigle LIKE 'TEST-PORTEE-%'" ) )
	);
	$m = current_time( 'mysql' );

	/* ---------- Rôles, comme la Direction les créerait ---------- */
	$roles = array(
		'test-portee-voir'     => array( 'un', array(), array( 'ueb_voir_ipes' ) ),
		'test-portee-verif'    => array( 'un', array(), array( 'ueb_voir_ipes', 'ueb_verifier_ipes' ) ),
		'test-portee-multi'    => array( 'plusieurs', array( 'FS', 'FSEG' ), array( 'ueb_voir_ipes' ) ),
		'test-portee-tous'     => array( 'tous', array(), array( 'ueb_voir_ipes' ) ),
		'test-portee-quitus'   => array( 'un', array(), array( 'ueb_gerer_quitus' ) ),
	);
	foreach ( $roles as $slug => $r ) {
		ueb_enregistrer_role( $slug, array( 'nom' => $slug, 'portee' => $r[0], 'etablissements' => $r[1], 'permissions' => $r[2], 'historique' => false, 'cree_le' => $m, 'modifie_le' => $m, 'modifie_par' => (int) $admin[0] ) );
		$crees['roles'][] = $slug;
	}
	$compte = static function ( $login, $role, $etab = 'FS' ) use ( &$crees ) {
		$r = ueb_creer_compte_agent( array( 'login' => $login, 'nom' => $login, 'email' => '', 'etablissement' => $etab, 'mot_de_passe' => 'Test-Portee-2026', 'role' => $role ) );
		verifier( ! is_wp_error( $r ), "compte $login créé " . ( is_wp_error( $r ) ? $r->get_error_message() : '' ) );
		$crees['comptes'][] = $r[0];
		return $r[0];
	};
	$fs_voir    = $compte( 'test-portee.fs.voir', 'test-portee-voir' );
	$fs_verif   = $compte( 'test-portee.fs.verif', 'test-portee-verif' );
	$fseg_verif = $compte( 'test-portee.fseg.verif', 'test-portee-verif', 'FSEG' );
	$multi      = $compte( 'test-portee.multi', 'test-portee-multi' );
	$tous       = $compte( 'test-portee.tous', 'test-portee-tous' );
	$quitus     = $compte( 'test-portee.quitus', 'test-portee-quitus' );
	$suspendu   = $compte( 'test-portee.suspendu', 'test-portee-verif' );
	update_user_meta( $suspendu, 'ueb_agent_suspendu', 1 );

	/* ---------- IPES : A (FS), B (FS + FSEG), C (FALSH) ---------- */
	$ipes = array();
	foreach ( array( 'TEST-PORTEE-A' => array( 'FS' ), 'TEST-PORTEE-B' => array( 'FS', 'FSEG' ), 'TEST-PORTEE-C' => array( 'FALSH' ) ) as $sigle => $tutelles ) {
		$id = ueb_ipes_enregistrer( array( 'sigle' => $sigle, 'nom_fr' => "Institut $sigle", 'tutelles' => $tutelles ) );
		verifier( is_int( $id ), "IPES $sigle créé" );
		$crees['ipes'][] = $id;
		$ipes[ $sigle ]  = $id;
		/* Une filière et trois étudiants par tutelle. */
		foreach ( $tutelles as $t ) {
			$f = ueb_ipes_filiere_ajouter( $id, 'Informatique ' . $t, $t );
			for ( $i = 1; $i <= 3; $i++ ) {
				ueb_ipes_etudiant_enregistrer( $id, array( 'matricule' => $sigle . '-' . $t . '-' . $i, 'nom' => 'Test', 'prenom' => 'Portee', 'filiere_id' => $f, 'niveau' => 'L1' ) );
			}
		}
	}
	$a = $ipes['TEST-PORTEE-A'];
	$b = $ipes['TEST-PORTEE-B'];
	$c = $ipes['TEST-PORTEE-C'];
	/* Bordereau d'un étudiant libre de la tutelle, avec un reçu (ligne seule : les fichiers ont leurs propres essais). */
	$recus     = array();
	$bordereau = static function ( $ipes_id, $tutelle, $envoyer ) use ( $wpdb, &$recus ) {
		$libres = ueb_ipes_etudiants_libres( $ipes_id, $tutelle );
		$id     = ueb_ipes_bordereau_creer( $ipes_id, $tutelle );
		verifier( true === ueb_ipes_bordereau_definir_etudiants( $ipes_id, $id, array( (int) $libres[0]->id ) ), "bordereau $tutelle préparé" );
		$wpdb->insert( 'ueb_insc_ipes_recus', array( 'bordereau_id' => $id, 'ipes_id' => $ipes_id, 'fichier' => '2026-2027/REC-' . $id . '-01-01-2026-00-00-00.jpg', 'nom_original' => 'recu.jpg', 'type_mime' => 'image/jpeg', 'taille' => 1000 ) );
		$recus[ $id ] = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ueb_insc_ipes_recus WHERE id = %d', $wpdb->insert_id ) );
		if ( $envoyer ) {
			verifier( true === ueb_ipes_bordereau_envoyer( $ipes_id, $id ), "bordereau $tutelle envoyé" );
		}
		return $id;
	};
	/* Le compte connecté peut-il lire le reçu de ce bordereau ? */
	$voit_recu = static function ( $ipes_id, $b_id ) use ( &$recus ) {
		return ueb_ipes_peut_voir_recu( $recus[ $b_id ], ueb_ipes_bordereau( $ipes_id, $b_id ) );
	};
	$b_fs      = $bordereau( $b, 'FS', true );
	$b_fseg    = $bordereau( $b, 'FSEG', true );
	$brouillon = $bordereau( $b, 'FS', false );
	$a_fs      = $bordereau( $a, 'FS', true );
	$admin_ipes = ueb_ipes_creer_compte( ueb_ipes( $a ), 'test-portee.admin-ipes', 'Admin IPES', '' );
	$crees['comptes'][] = $admin_ipes[0];

	/* ---------- Agent FS, « Voir » seul ---------- */
	wp_set_current_user( $fs_voir );
	verifier( ueb_est_scolarite(), 'fs.voir : accès à l’espace scolarité' );
	verifier( ueb_url_scolarite() === ueb_url_espace_du_compte( $fs_voir ), 'fs.voir : redirigé vers la scolarité après connexion' );
	verifier( array( 'TEST-PORTEE-A', 'TEST-PORTEE-B' ) === sigles( ueb_ipes_sous_tutelle() ), 'fs.voir : voit les IPES de la FS, pas celui de la FALSH' );
	verifier( null === ueb_ipes_sous_tutelle_par_id( $c ), 'fs.voir : IPES de la FALSH demandé par son identifiant, refusé' );
	verifier( array( $b_fs ) === ids( ueb_ipes_bordereaux_pour_tutelle( $b ) ), 'fs.voir : seulement le bordereau adressé à la FS, jamais le brouillon' );
	verifier( null === ueb_ipes_bordereau_pour_tutelle( ueb_ipes( $b ), $b_fseg ), 'fs.voir : bordereau adressé à la FSEG introuvable' );
	verifier( ! ueb_peut_verifier_bordereau( ueb_ipes_bordereau( $b, $b_fs ) ), 'fs.voir : ne peut pas décider sans la permission' );
	verifier( $voit_recu( $b, $b_fs ) && ! $voit_recu( $b, $b_fseg ) && ! $voit_recu( $b, $brouillon ), 'fs.voir : lit le reçu du bordereau adressé à la FS, ni celui de la FSEG ni celui d’un brouillon' );

	/* ---------- Agent FS, « Voir + Vérifier » ---------- */
	wp_set_current_user( $fs_verif );
	verifier( ueb_peut_verifier_bordereau( ueb_ipes_bordereau( $b, $b_fs ) ), 'fs.verif : peut décider du bordereau adressé à la FS' );
	verifier( ! ueb_peut_verifier_bordereau( ueb_ipes_bordereau( $b, $b_fseg ) ), 'fs.verif : pas du bordereau adressé à la FSEG' );
	verifier( ! ueb_peut_verifier_bordereau( ueb_ipes_bordereau( $b, $brouillon ) ), 'fs.verif : pas d’un brouillon' );
	verifier( true === ueb_ipes_bordereau_decider( $b_fs, true ) && ! ueb_peut_verifier_bordereau( ueb_ipes_bordereau( $b, $b_fs ) ), 'fs.verif : une fois vérifié, plus de décision possible' );

	/* ---------- Agent FSEG, « Voir + Vérifier » ---------- */
	wp_set_current_user( $fseg_verif );
	verifier( array( 'TEST-PORTEE-B' ) === sigles( ueb_ipes_sous_tutelle() ), 'fseg.verif : voit seulement l’IPES à double tutelle' );
	verifier( null === ueb_ipes_sous_tutelle_par_id( $a ), 'fseg.verif : IPES de la FS seule refusé' );
	verifier( array( $b_fseg ) === ids( ueb_ipes_bordereaux_pour_tutelle( $b ) ), 'fseg.verif : seulement le bordereau adressé à la FSEG' );
	verifier( ueb_peut_verifier_bordereau( ueb_ipes_bordereau( $b, $b_fseg ) ) && ! ueb_peut_verifier_bordereau( ueb_ipes_bordereau( $a, $a_fs ) ), 'fseg.verif : décide pour la FSEG, jamais pour la FS' );
	verifier( $voit_recu( $b, $b_fseg ) && ! $voit_recu( $b, $b_fs ) && ! $voit_recu( $a, $a_fs ), 'fseg.verif : lit seulement les reçus adressés à la FSEG' );

	/* ---------- Portée « plusieurs » (FS, FSEG) et son sélecteur ---------- */
	wp_set_current_user( $multi );
	delete_user_meta( $multi, 'ueb_etab_courant' );
	verifier( array( 'TEST-PORTEE-A', 'TEST-PORTEE-B' ) === sigles( ueb_ipes_sous_tutelle() ), 'multi : voit les IPES de ses deux établissements' );
	update_user_meta( $multi, 'ueb_etab_courant', 'FSEG' );
	verifier( array( 'TEST-PORTEE-B' ) === sigles( ueb_ipes_sous_tutelle() ), 'multi : le sélecteur sur FSEG restreint la liste' );
	verifier( null !== ueb_ipes_sous_tutelle_par_id( $a ), 'multi : l’IPES de la FS reste accessible (portée), même hors sélection' );
	update_user_meta( $multi, 'ueb_etab_courant', 'FALSH' ); // hors portée : ignoré
	/* Sélection hors portée ignorée : retour au premier établissement de la portée (FS). */
	verifier( array( 'TEST-PORTEE-A', 'TEST-PORTEE-B' ) === sigles( ueb_ipes_sous_tutelle() ), 'multi : un établissement hors portée dans le sélecteur n’ouvre rien de plus' );
	verifier( null === ueb_ipes_sous_tutelle_par_id( $c ), 'multi : l’IPES de la FALSH reste refusé' );

	/* ---------- Portée « tous » ---------- */
	wp_set_current_user( $tous );
	delete_user_meta( $tous, 'ueb_etab_courant' );
	verifier( array( 'TEST-PORTEE-A', 'TEST-PORTEE-B', 'TEST-PORTEE-C' ) === array_values( array_intersect( array( 'TEST-PORTEE-A', 'TEST-PORTEE-B', 'TEST-PORTEE-C' ), sigles( ueb_ipes_sous_tutelle() ) ) ), 'tous : voit les trois IPES de test' );
	verifier( ! ueb_peut_verifier_bordereau( ueb_ipes_bordereau( $b, $b_fseg ) ), 'tous : voir n’est pas vérifier' );

	/* ---------- Comptes sans droit sur les IPES ---------- */
	wp_set_current_user( $quitus );
	verifier( ueb_est_scolarite() && array() === ueb_ipes_sous_tutelle() && ! ueb_peut_voir_ipes( ueb_ipes( $a ) ) && ! $voit_recu( $a, $a_fs ), 'quitus : accès à la scolarité, mais aucun IPES ni reçu' );
	wp_set_current_user( $suspendu );
	verifier( array() === ueb_ipes_sous_tutelle() && ! ueb_peut_verifier_bordereau( ueb_ipes_bordereau( $b, $b_fseg ) ) && ! $voit_recu( $a, $a_fs ), 'suspendu : plus rien, même avec « Vérifier »' );
	wp_set_current_user( $admin_ipes[0] );
	verifier( ! ueb_est_scolarite() && array() === ueb_ipes_sous_tutelle() && null === ueb_ipes_sous_tutelle_par_id( $a ), 'admin d’IPES : aucune vue de tutelle, même sur son propre IPES' );
	verifier( $voit_recu( $a, $a_fs ) && ! $voit_recu( $b, $b_fs ), 'admin d’IPES : lit les reçus de son IPES, pas ceux d’un autre' );
	wp_set_current_user( 0 );
	verifier( array() === ueb_ipes_sous_tutelle() && null === ueb_ipes_sous_tutelle_par_id( $a ) && ! $voit_recu( $a, $a_fs ), 'visiteur : rien' );

	echo $GLOBALS['assertions'] . " vérifications réussies.\n";
} finally {
	/* Nettoyage, même après un échec : comptes, rôles, puis IPES et leurs données. */
	wp_set_current_user( (int) ( $admin[0] ?? 0 ) );
	nettoyer( $crees['comptes'], $crees['roles'], $crees['ipes'] );
}
