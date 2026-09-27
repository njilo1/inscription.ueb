<?php
/**
 * Régressions de la portée des tutelles sur les IPES (inc/ipes-tutelle.php) :
 * qui voit quels IPES, quels bordereaux, et qui peut en décider.
 *
 * Charge WordPress COMPLET (ces fonctions dépendent des comptes, des rôles et
 * des capacités) et travaille sur la base locale : rôles, comptes et IPES
 * temporaires, tous préfixés « test-portee », supprimés à la fin même en cas
 * d'échec. Données exclusivement fictives.
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
/** Sigles d'une liste d'IPES, triés. */
function sigles( array $liste ) {
	$s = array_map( static fn( $i ) => $i->sigle, $liste );
	sort( $s );
	return $s;
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
		$f = ueb_ipes_filiere_ajouter( $id, 'Informatique' );
		$e = ueb_ipes_etudiant_enregistrer( $id, array( 'matricule' => $sigle . '-1', 'nom' => 'Test', 'prenom' => 'Portee', 'filiere_id' => $f, 'niveau' => 'L1' ) );
		for ( $i = 0; $i < 3; $i++ ) {
			ueb_ipes_paiement_enregistrer( $id, $e, array( 'montant' => '10000', 'date_paiement' => current_time( 'Y-m-d' ) ) );
		}
	}
	$a = $ipes['TEST-PORTEE-A'];
	$b = $ipes['TEST-PORTEE-B'];
	$c = $ipes['TEST-PORTEE-C'];
	$bordereau = static function ( $ipes_id, $tutelle, $envoyer ) {
		$libres = ueb_ipes_paiements_libres( $ipes_id );
		$id     = ueb_ipes_bordereau_creer( $ipes_id, $tutelle );
		ueb_ipes_bordereau_definir_paiements( $ipes_id, $id, array( (int) $libres[0]->id ) );
		if ( $envoyer ) {
			ueb_ipes_bordereau_envoyer( $ipes_id, $id );
		}
		return $id;
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
	verifier( ueb_est_scolarite() && array() === ueb_ipes_sous_tutelle() && ! ueb_peut_voir_ipes( ueb_ipes( $a ) ), 'quitus : accès à la scolarité, mais aucun IPES' );
	wp_set_current_user( $suspendu );
	verifier( array() === ueb_ipes_sous_tutelle() && ! ueb_peut_verifier_bordereau( ueb_ipes_bordereau( $b, $b_fseg ) ), 'suspendu : plus rien, même avec « Vérifier »' );
	wp_set_current_user( $admin_ipes[0] );
	verifier( ! ueb_est_scolarite() && array() === ueb_ipes_sous_tutelle() && null === ueb_ipes_sous_tutelle_par_id( $a ), 'admin d’IPES : aucune vue de tutelle, même sur son propre IPES' );
	wp_set_current_user( 0 );
	verifier( array() === ueb_ipes_sous_tutelle() && null === ueb_ipes_sous_tutelle_par_id( $a ), 'visiteur : rien' );

	echo $GLOBALS['assertions'] . " vérifications réussies.\n";
} finally {
	/* Nettoyage, même après un échec : comptes, rôles, puis IPES et leurs données. */
	wp_set_current_user( (int) ( $admin[0] ?? 0 ) );
	foreach ( $crees['comptes'] as $id ) {
		wp_delete_user( $id );
	}
	foreach ( $crees['roles'] as $slug ) {
		ueb_retirer_role( $slug );
	}
	foreach ( $crees['ipes'] as $id ) {
		foreach ( array( 'ueb_insc_ipes_paiements', 'ueb_insc_ipes_etudiants', 'ueb_insc_ipes_bordereaux', 'ueb_insc_ipes_sequence', 'ueb_insc_ipes_filieres', 'ueb_insc_ipes_tutelles' ) as $table ) {
			$wpdb->delete( $table, array( 'ipes_id' => $id ) );
		}
		$wpdb->delete( 'ueb_insc_ipes', array( 'id' => $id ) );
	}
}
