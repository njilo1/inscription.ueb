<?php
/**
 * Comptes de la plateforme : les rôles et les comptes de la liste des
 * utilisateurs (outils/comptes-personnel.csv), installés en une fois.
 *
 * Deux portes, le même code : le bouton « Installer les comptes de la
 * plateforme » de l'onglet Personnel (super-administrateur) et le script
 * outils/comptes-personnel.php (ligne de commande).
 *
 * Rien n'est jamais supprimé : un rôle de la liste est créé, ou remis à sa
 * définition s'il existe déjà (même nom) ; un compte est créé s'il n'existe
 * pas, sinon laissé tel quel. Les mots de passe d'une liste déjà distribuée
 * (identifiant;mot_de_passe;…) sont repris ; un compte absent de cette liste
 * reçoit un mot de passe aléatoire.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rôles de la liste des utilisateurs : nom => array( portée, permissions,
 * intitulés ). Les modules (droits universitaires, visite médicale) sont
 * ensuite filtrés compte par compte (inc/acces-modules.php), statistiques
 * comprises. Les statistiques sont en lecture seule, sans les reçus.
 */
function ueb_plateforme_roles() {
	$stats = array( 'ueb_voir_stats', 'ueb_voir_stats_cms', 'ueb_voir_paiements' );
	return array(
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
}

/**
 * Comptes de la liste (identifiant;role;etablissement;modules).
 *
 * @return array|WP_Error
 */
function ueb_plateforme_comptes( $liste = '' ) {
	$liste = $liste ?: UEB_INSC_DIR . '/outils/comptes-personnel.csv';
	if ( ! is_readable( $liste ) ) {
		return new WP_Error( 'ueb_liste', "Liste des comptes introuvable ($liste)." );
	}
	$roles   = ueb_plateforme_roles();
	$comptes = array();
	foreach ( array_slice( file( $liste, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ), 1 ) as $n => $ligne ) {
		list( $login, $role, $etab, $modules ) = array_map( 'trim', array_pad( explode( ';', $ligne ), 4, '' ) );
		$etab   = strtoupper( $etab );
		$numero = 'Ligne ' . ( $n + 2 ) . ' : ';
		if ( ! isset( $roles[ $role ] ) ) {
			return new WP_Error( 'ueb_liste', $numero . "rôle inconnu « $role »." );
		}
		if ( 'un' === $roles[ $role ][0] && ! ueb_etablissement( $etab ) ) {
			return new WP_Error( 'ueb_liste', $numero . "établissement inconnu « $etab »." );
		}
		if ( ! isset( UEB_ACCES_MODULES[ $modules ] ) ) {
			return new WP_Error( 'ueb_liste', $numero . "accès aux modules « $modules » (du, vm, tous ou aucun)." );
		}
		if ( sanitize_user( $login, true ) !== $login ) {
			return new WP_Error( 'ueb_liste', $numero . "identifiant invalide « $login »." );
		}
		$comptes[] = compact( 'login', 'role', 'etab', 'modules' );
	}
	return $comptes;
}

/**
 * Mots de passe d'une liste déjà distribuée : identifiant => mot de passe.
 * Le fichier doit commencer par « identifiant;mot_de_passe » (credentials_temp.csv
 * tel qu'il a été produit) ; un autre fichier (la liste des comptes, un CSV
 * réenregistré par un tableur…) est refusé, avec la raison.
 *
 * @return array|WP_Error
 */
function ueb_plateforme_mots_de_passe( $fichier ) {
	$lignes = (array) file( $fichier, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	$entete = array_map( static fn( $c ) => strtolower( trim( $c, " 	\"﻿" ) ), explode( ';', (string) ( $lignes[0] ?? '' ) ) );
	if ( 'identifiant' !== ( $entete[0] ?? '' ) || 'mot_de_passe' !== ( $entete[1] ?? '' ) ) {
		return new WP_Error( 'ueb_mdp', 'Ce fichier n’est pas la liste des mots de passe : sa première ligne doit être « identifiant;mot_de_passe;… ». Choisis le fichier credentials_temp.csv d’origine, sans l’ouvrir ni le réenregistrer dans Excel.' );
	}
	$connus = array();
	foreach ( array_slice( $lignes, 1 ) as $n => $ligne ) {
		list( $login, $mdp ) = array_map( static fn( $c ) => trim( $c, " 	\"" ), array_pad( explode( ';', (string) $ligne ), 2, '' ) );
		if ( '' === $login ) {
			continue;
		}
		if ( strlen( $mdp ) < 8 || ! preg_match( '/[A-Za-z]/', $mdp ) || ! preg_match( '/[0-9]/', $mdp ) ) {
			return new WP_Error( 'ueb_mdp', sprintf( 'Ligne %d du fichier (%s) : le mot de passe ne compte pas 8 caractères avec une lettre et un chiffre. Rien n’a été fait.', $n + 2, $login ) );
		}
		$connus[ $login ] = $mdp;
	}
	return $connus;
}

/** Mot de passe aléatoire lisible : 10 signes, au moins une lettre et un chiffre, sans 0, O, 1, l, I. */
function ueb_plateforme_mot_de_passe_aleatoire( $longueur = 10 ) {
	$signes = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
	do {
		$mdp = '';
		for ( $i = 0; $i < $longueur; $i++ ) {
			$mdp .= $signes[ random_int( 0, strlen( $signes ) - 1 ) ];
		}
	} while ( ! preg_match( '/[A-Za-z]/', $mdp ) || ! preg_match( '/[0-9]/', $mdp ) );
	return $mdp;
}

/**
 * Installe les rôles et les comptes de la liste, au nom du compte courant
 * (un administrateur). Les mots de passe n'apparaissent jamais dans le journal.
 *
 * @param array $connus     identifiant => mot de passe déjà distribué.
 * @param bool  $simulation vrai : rien n'est écrit, le journal dit ce qui serait fait.
 * @return array|WP_Error array( 'journal' => lignes, 'crees' => array( identifiant, mot de passe, repris ), 'total' => n )
 */
function ueb_plateforme_installer( array $connus = array(), $simulation = false ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	$comptes = ueb_plateforme_comptes();
	if ( is_wp_error( $comptes ) ) {
		return $comptes;
	}
	$roles   = ueb_plateforme_roles();
	$journal = array();
	$slugs   = array();

	/* Rôles : créés, ou remis à leur définition s'ils existent déjà (même nom). */
	foreach ( array_unique( array_column( $comptes, 'role' ) ) as $nom ) {
		list( $portee, $permissions ) = $roles[ $nom ];
		$slug = '';
		foreach ( ueb_roles() as $s => $def ) {
			if ( $def['nom'] === $nom ) {
				$slug = $s;
			}
		}
		$existant      = '' !== $slug;
		$slug          = $existant ? $slug : ueb_nouveau_slug_role();
		$slugs[ $nom ] = $slug;
		$journal[]     = sprintf( 'Rôle %s : %s', $existant ? 'mis à jour' : 'créé', $nom );
		if ( ! $simulation ) {
			ueb_enregistrer_role( $slug, array(
				'nom'            => $nom,
				'portee'         => $portee,
				'etablissements' => array(),
				'permissions'    => $permissions,
				'intitules'      => $roles[ $nom ][2] ?? array(), // Doyen ou Directeur selon l'établissement
				'historique'     => false,
				'cree_le'        => $existant ? ueb_role( $slug )['cree_le'] : current_time( 'mysql' ),
				'modifie_le'     => current_time( 'mysql' ),
				'modifie_par'    => get_current_user_id(),
			) );
		}
	}

	/* Comptes : créés s'ils n'existent pas. Nom affiché : la fonction et l'établissement. */
	$fonctions = array( 'regie' => 'Régie CMS', 'chef' => 'Chef CMS' );
	$crees     = array();
	foreach ( $comptes as $c ) {
		if ( username_exists( $c['login'] ) ) {
			$journal[] = 'Compte déjà présent, laissé tel quel : ' . $c['login'];
			continue;
		}
		$un  = 'un' === $roles[ $c['role'] ][0];
		$nom = ( isset( $roles[ $c['role'] ][2] ) ? ueb_intitule_role( array( 'nom' => $c['role'], 'intitules' => $roles[ $c['role'] ][2] ), $c['etab'] ) : ( $fonctions[ strtok( $c['login'], '.@' ) ] ?? $c['role'] ) ) . ( $un ? ' ' . $c['etab'] : '' );
		if ( $simulation ) {
			$journal[] = 'Compte à créer : ' . $c['login'] . ' — ' . $nom . ( isset( $connus[ $c['login'] ] ) ? '' : ' (nouveau mot de passe)' );
			continue;
		}
		$mdp      = $connus[ $c['login'] ] ?? ueb_plateforme_mot_de_passe_aleatoire();
		$resultat = ueb_creer_compte_agent( array(
			'login'         => $c['login'],
			'nom'           => $nom,
			'email'         => '',
			'role'          => $slugs[ $c['role'] ],
			'etablissement' => $un ? $c['etab'] : '',
			'mot_de_passe'  => $mdp,
		) );
		if ( is_wp_error( $resultat ) ) {
			$journal[] = 'ÉCHEC ' . $c['login'] . ' : ' . $resultat->get_error_message();
			continue;
		}
		ueb_definir_acces_modules( $resultat[0], $c['modules'] );
		$crees[]   = array( $c['login'], $mdp, isset( $connus[ $c['login'] ] ) );
		$journal[] = 'Compte créé : ' . $c['login'] . ' — ' . $nom;
	}
	return array( 'journal' => $journal, 'crees' => $crees, 'total' => count( $comptes ) );
}

/**
 * Action « comptes_plateforme_installer » (onglet Personnel) : liste des mots
 * de passe distribués facultative (fichier CSV), simulation par défaut.
 */
function ueb_action_comptes_plateforme_installer() {
	ueb_exiger_admin();
	$retour     = add_query_arg( 'vue', 'scolarites', ueb_url_administration() );
	$simulation = ! empty( $_POST['simulation'] );
	$connus     = array();
	$fichier    = $_FILES['mots_de_passe'] ?? null; // phpcs:ignore -- lu ligne à ligne, jamais enregistré
	if ( $fichier && UPLOAD_ERR_OK === (int) $fichier['error'] && is_uploaded_file( $fichier['tmp_name'] ) ) {
		$connus = ueb_plateforme_mots_de_passe( $fichier['tmp_name'] );
		if ( is_wp_error( $connus ) ) {
			ueb_flash( 'erreur', $connus->get_error_message() );
			ueb_rediriger( $retour );
		}
	}
	$resultat = ueb_plateforme_installer( $connus, $simulation );
	if ( is_wp_error( $resultat ) ) {
		ueb_flash( 'erreur', $resultat->get_error_message() );
		ueb_rediriger( $retour );
	}
	/* Journal sans mot de passe ; seuls les mots de passe NOUVEAUX sont montrés, une fois. */
	$_SESSION['ueb_installation'] = array(
		'simulation' => $simulation,
		'repris'     => count( $connus ),
		'journal'    => $resultat['journal'],
		'nouveaux'   => array_values( array_map( static fn( $c ) => array( $c[0], $c[1] ), array_filter( $resultat['crees'], static fn( $c ) => ! $c[2] ) ) ),
	);
	ueb_flash( 'succes', $simulation
		? 'Simulation terminée : rien n’a été écrit. Lis le journal, puis lance l’installation.'
		: sprintf( '%d compte(s) créé(s) sur %d.', count( $resultat['crees'] ), $resultat['total'] ) );
	ueb_rediriger( $retour );
}
