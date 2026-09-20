<?php
/**
 * Comptes étudiants : création, connexion, déconnexion, changement de mot
 * de passe et d'identifiant.
 *
 * Identifiant de connexion : le matricule (anciens) ou le n° de dossier de
 * préinscription (nouveaux). Une fois le matricule attribué, l'étudiant
 * l'enregistre dans son espace ; les deux identifiants restent valides.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

/* ---------- Lecture ---------- */

function ueb_compte_par_id( $id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ueb_insc_comptes WHERE id = %d', $id ) );
}

function ueb_compte_par_identifiant( $identifiant ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare(
		'SELECT * FROM ueb_insc_comptes WHERE matricule = %s OR numero_dossier = %s LIMIT 1',
		$identifiant,
		$identifiant
	) );
}

/**
 * Compte de l'étudiant connecté, ou null. La session est invalidée si le
 * mot de passe a changé ailleurs (version_session) ou si le compte est bloqué.
 */
function ueb_compte_courant() {
	static $compte = false;
	if ( false !== $compte ) {
		return $compte;
	}
	$compte = null;
	if ( empty( $_SESSION['ueb_compte_id'] ) ) {
		return null;
	}
	$trouve = ueb_compte_par_id( (int) $_SESSION['ueb_compte_id'] );
	if ( ! $trouve || 'actif' !== $trouve->statut || (int) $trouve->version_session !== (int) ( $_SESSION['ueb_version_session'] ?? 0 ) ) {
		unset( $_SESSION['ueb_compte_id'], $_SESSION['ueb_version_session'] );
		return null;
	}
	$compte = $trouve;
	return $compte;
}

/** Identifiant affiché : le matricule s'il existe, sinon le n° de dossier. */
function ueb_identifiant_compte( $compte ) {
	return $compte->matricule ?: $compte->numero_dossier;
}

/**
 * Données de préinscription d'un n° de dossier soumis (lecture seule),
 * pour vérifier le dossier et pré-remplir le quitus.
 */
function ueb_preinscription_par_dossier( $dossier ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare(
		"SELECT p.numero_dossier, p.nom, p.prenom, p.date_naissance, p.lieu_naissance, p.sexe,
		        p.email, p.adresse, p.nom_urgence, p.numero_urgence, p.adresse_urgence,
		        p.filiere_1_id, p.filiere_2_id, p.filiere_3_id,
		        n.nom AS nationalite, f.code AS etablissement, fi.libelle AS filiere, nv.code AS niveau
		   FROM ueb_preinscriptions p
		   LEFT JOIN ueb_nationalites n ON n.id = p.nationalite_id
		   LEFT JOIN ueb_facultes f ON f.id = p.faculte_id
		   LEFT JOIN ueb_filieres fi ON fi.id = p.filiere_1_id
		   LEFT JOIN ueb_niveaux_lmd nv ON nv.id = p.niveau_lmd_id
		  WHERE p.numero_dossier = %s AND p.statut = 'soumis'",
		$dossier
	) );
}

/**
 * Vrai si l'étudiant s'est préinscrit pendant l'année académique en cours :
 * sa visite médicale est alors déjà payée avec les frais de préinscription,
 * et il n'a pas de quitus médical à générer.
 */
function ueb_preinscrit_cette_annee( $compte ) {
	global $wpdb;
	if ( empty( $compte->numero_dossier ) ) {
		return false;
	}
	/* Le numéro de dossier porte l'année de la campagne : UEB-2026-000150 vaut
	   pour l'année 2026-2027. On ne peut pas se fier à la date de saisie : les
	   préinscriptions se font avant la rentrée, donc avant le 1er septembre. */
	if ( ! preg_match( '/(\d{4})-\d{6}$/', $compte->numero_dossier, $trouve ) ) {
		return false;
	}
	if ( (int) $trouve[1] !== ueb_annee_academique()['debut'] ) {
		return false;
	}
	/* Le dossier doit exister et avoir été soumis. */
	return (bool) $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM ueb_preinscriptions WHERE numero_dossier = %s AND statut = 'soumis'",
		$compte->numero_dossier
	) );
}

/* ---------- Session ---------- */

function ueb_connecter( $compte ) {
	global $wpdb;
	session_regenerate_id( true );
	$_SESSION['ueb_compte_id']       = (int) $compte->id;
	$_SESSION['ueb_version_session'] = (int) $compte->version_session;
	$wpdb->update( 'ueb_insc_comptes', array( 'derniere_connexion' => current_time( 'mysql' ) ), array( 'id' => $compte->id ) );
}

function ueb_deconnecter() {
	unset( $_SESSION['ueb_compte_id'], $_SESSION['ueb_version_session'], $_SESSION['ueb_bienvenue'], $_SESSION['ueb_telechargement'] );
	session_regenerate_id( true );
}

/** Conseil de confidentialité, uniquement lors de l'arrivée après création du compte. */
function ueb_bienvenue_a_afficher( $compte ) {
	$id = (int) ( $_SESSION['ueb_bienvenue'] ?? 0 );
	unset( $_SESSION['ueb_bienvenue'] );
	return $id === (int) $compte->id;
}

/* ---------- Règles ---------- */

/** Message d'erreur si le mot de passe est trop faible, sinon null. */
function ueb_erreur_mot_de_passe( $mdp, $identifiant = '' ) {
	if ( mb_strlen( $mdp ) < 8 ) {
		return 'Le mot de passe doit contenir au moins 8 caractères.';
	}
	if ( ! preg_match( '/\pL/u', $mdp ) || ! preg_match( '/\d/', $mdp ) ) {
		return 'Le mot de passe doit contenir au moins une lettre et un chiffre.';
	}
	if ( $identifiant && false !== stripos( $mdp, $identifiant ) ) {
		return 'Le mot de passe ne doit pas contenir ton identifiant.';
	}
	return null;
}

/* Limite les essais de connexion : 8 échecs par adresse IP en 15 minutes. */
function ueb_cle_essais() {
	return 'ueb_insc_essais_' . md5( $_SERVER['REMOTE_ADDR'] ?? '' );
}
function ueb_trop_d_essais() {
	return (int) get_transient( ueb_cle_essais() ) >= 8;
}
function ueb_noter_echec() {
	set_transient( ueb_cle_essais(), (int) get_transient( ueb_cle_essais() ) + 1, 15 * MINUTE_IN_SECONDS );
}

/* Limite dédiée aux connexions des espaces réservés aux personnels. */
function ueb_cle_essais_gestion( $identifiant ) {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
	return 'ueb_gestion_essais_' . hash( 'sha256', strtolower( trim( $ip . "\0" . (string) $identifiant ) ) );
}
function ueb_connexion_gestion_bloquee( $identifiant ) {
	return (int) get_transient( ueb_cle_essais_gestion( $identifiant ) ) >= 8;
}
function ueb_noter_echec_gestion( $identifiant ) {
	$cle = ueb_cle_essais_gestion( $identifiant );
	set_transient( $cle, (int) get_transient( $cle ) + 1, 15 * MINUTE_IN_SECONDS );
}
function ueb_reinitialiser_echecs_gestion( $identifiant ) {
	delete_transient( ueb_cle_essais_gestion( $identifiant ) );
}

/* ---------- Actions ---------- */

function ueb_action_connexion() {
	$identifiant = ueb_normaliser_identifiant( wp_unslash( $_POST['identifiant'] ?? '' ) );
	$mdp         = (string) wp_unslash( $_POST['mot_de_passe'] ?? '' );
	$retour      = ueb_url( 'connexion' );

	if ( ueb_trop_d_essais() ) {
		ueb_memoriser_saisie( array( 'identifiant' => $identifiant ), array( 'general' => 'Trop de tentatives. Patiente 15 minutes avant de réessayer.' ) );
		ueb_rediriger( $retour );
	}

	$compte = '' !== $identifiant ? ueb_compte_par_identifiant( $identifiant ) : null;
	if ( ! $compte || ! password_verify( $mdp, $compte->mot_de_passe ) ) {
		ueb_noter_echec();
		ueb_memoriser_saisie( array( 'identifiant' => $identifiant ), array( 'general' => 'Identifiant ou mot de passe incorrect.' ) );
		ueb_rediriger( $retour );
	}
	if ( 'actif' !== $compte->statut ) {
		ueb_memoriser_saisie( array( 'identifiant' => $identifiant ), array( 'general' => 'Ce compte est suspendu. Présente-toi à la scolarité de ton établissement.' ) );
		ueb_rediriger( $retour );
	}

	if ( password_needs_rehash( $compte->mot_de_passe, PASSWORD_DEFAULT ) ) {
		global $wpdb;
		$wpdb->update( 'ueb_insc_comptes', array( 'mot_de_passe' => password_hash( $mdp, PASSWORD_DEFAULT ) ), array( 'id' => $compte->id ) );
	}
	delete_transient( ueb_cle_essais() );
	ueb_connecter( $compte );
	ueb_rediriger( ueb_url( 'mon-espace' ) );
}

function ueb_action_creer_compte() {
	global $wpdb;
	$identifiant  = ueb_normaliser_identifiant( wp_unslash( $_POST['identifiant'] ?? '' ) );
	$tel_saisi    = sanitize_text_field( wp_unslash( $_POST['telephone'] ?? '' ) );
	$telephone    = ueb_normaliser_telephone( $tel_saisi );
	$mdp          = (string) wp_unslash( $_POST['mot_de_passe'] ?? '' );
	$confirmation = (string) wp_unslash( $_POST['confirmation'] ?? '' );
	$erreurs      = array();

	$type = ueb_type_identifiant( $identifiant );
	if ( '' === $identifiant ) {
		$erreurs['identifiant'] = 'Saisis ton matricule ou ton numéro de dossier.';
	} elseif ( ! $type ) {
		$erreurs['identifiant'] = 'Format non reconnu. Exemples : 24I0017FS (matricule) ou UEB-2026-000123 (dossier).';
	} elseif ( ueb_compte_par_identifiant( $identifiant ) ) {
		$erreurs['identifiant'] = 'Un compte existe déjà avec cet identifiant. Connecte-toi.';
	} elseif ( 'dossier' === $type && ! ueb_preinscription_par_dossier( $identifiant ) ) {
		$erreurs['identifiant'] = "Ce numéro de dossier n'existe pas ou la préinscription n'a pas été soumise.";
	}

	if ( ! $telephone ) {
		$erreurs['telephone'] = 'Numéro mobile camerounais attendu : 9 chiffres commençant par 6, par exemple 699 73 07 81.';
	}

	$erreur_mdp = ueb_erreur_mot_de_passe( $mdp, $identifiant );
	if ( $erreur_mdp ) {
		$erreurs['mot_de_passe'] = $erreur_mdp;
	} elseif ( $mdp !== $confirmation ) {
		$erreurs['confirmation'] = 'Les deux mots de passe ne sont pas identiques.';
	}

	if ( $erreurs ) {
		ueb_memoriser_saisie( array( 'identifiant' => $identifiant, 'telephone' => $tel_saisi ), $erreurs );
		ueb_rediriger( ueb_url( 'creer-mon-compte' ) );
	}

	$ok = $wpdb->insert( 'ueb_insc_comptes', array(
		'matricule'      => 'matricule' === $type ? $identifiant : null,
		'numero_dossier' => 'dossier' === $type ? $identifiant : null,
		'telephone'      => $telephone,
		'mot_de_passe'   => password_hash( $mdp, PASSWORD_DEFAULT ),
	) );
	if ( ! $ok ) {
		error_log( '[inscription-ueb] Création de compte impossible : ' . $wpdb->last_error );
		ueb_memoriser_saisie( array( 'identifiant' => $identifiant, 'telephone' => $tel_saisi ), array( 'general' => "Le compte n'a pas pu être créé. Réessaie dans un instant." ) );
		ueb_rediriger( ueb_url( 'creer-mon-compte' ) );
	}

	ueb_connecter( ueb_compte_par_id( $wpdb->insert_id ) );
	$_SESSION['ueb_bienvenue'] = (int) $_SESSION['ueb_compte_id'];
	ueb_flash( 'succes', 'Ton compte est créé. Tu peux maintenant préparer ton quitus.' );
	ueb_rediriger( ueb_url( 'mon-espace' ) );
}

function ueb_action_changer_mdp() {
	global $wpdb;
	$compte = ueb_compte_courant();
	if ( ! $compte ) {
		ueb_rediriger( ueb_url( 'connexion' ) );
	}
	$actuel       = (string) wp_unslash( $_POST['mdp_actuel'] ?? '' );
	$nouveau      = (string) wp_unslash( $_POST['mdp_nouveau'] ?? '' );
	$confirmation = (string) wp_unslash( $_POST['mdp_confirmation'] ?? '' );
	$erreurs      = array();

	if ( ! password_verify( $actuel, $compte->mot_de_passe ) ) {
		$erreurs['mdp_actuel'] = 'Mot de passe actuel incorrect.';
	}
	$erreur_mdp = ueb_erreur_mot_de_passe( $nouveau, ueb_identifiant_compte( $compte ) );
	if ( $erreur_mdp ) {
		$erreurs['mdp_nouveau'] = $erreur_mdp;
	} elseif ( password_verify( $nouveau, $compte->mot_de_passe ) ) {
		$erreurs['mdp_nouveau'] = "Choisis un mot de passe différent de l'actuel.";
	} elseif ( $nouveau !== $confirmation ) {
		$erreurs['mdp_confirmation'] = 'Les deux mots de passe ne sont pas identiques.';
	}
	if ( $erreurs ) {
		ueb_memoriser_saisie( array( 'formulaire' => 'mdp' ), $erreurs );
		ueb_rediriger( ueb_url( 'mon-espace/securite' ) . '#mot-de-passe' );
	}

	/* Nouvelle version de session : toutes les autres sessions sont déconnectées. */
	$version = (int) $compte->version_session + 1;
	$wpdb->update( 'ueb_insc_comptes', array(
		'mot_de_passe'     => password_hash( $nouveau, PASSWORD_DEFAULT ),
		'doit_changer_mdp' => 0,
		'version_session'  => $version,
	), array( 'id' => $compte->id ) );
	$compte->version_session = $version;
	ueb_connecter( $compte );

	ueb_flash( 'succes', 'Mot de passe modifié. Toutes tes autres sessions ouvertes ont été déconnectées.' );
	ueb_rediriger( ueb_url( 'mon-espace/securite' ) );
}

function ueb_action_changer_identifiant() {
	global $wpdb;
	$compte = ueb_compte_courant();
	if ( ! $compte ) {
		ueb_rediriger( ueb_url( 'connexion' ) );
	}
	$matricule = ueb_normaliser_identifiant( wp_unslash( $_POST['matricule'] ?? '' ) );
	$mdp       = (string) wp_unslash( $_POST['mdp_confirmation_id'] ?? '' );
	$erreurs   = array();

	if ( 'matricule' !== ueb_type_identifiant( $matricule ) ) {
		$erreurs['matricule'] = 'Format de matricule non reconnu (exemple : 24I0017FS).';
	} elseif ( $matricule === $compte->matricule ) {
		$erreurs['matricule'] = "C'est déjà ton matricule enregistré.";
	} else {
		$existant = ueb_compte_par_identifiant( $matricule );
		if ( $existant && (int) $existant->id !== (int) $compte->id ) {
			$erreurs['matricule'] = 'Ce matricule est déjà utilisé par un autre compte. Signale-le à la scolarité.';
		}
	}
	if ( ! password_verify( $mdp, $compte->mot_de_passe ) ) {
		$erreurs['mdp_confirmation_id'] = 'Mot de passe incorrect.';
	}
	if ( $erreurs ) {
		ueb_memoriser_saisie( array( 'formulaire' => 'identifiant', 'matricule' => $matricule ), $erreurs );
		ueb_rediriger( ueb_url( 'mon-espace/securite' ) . '#identifiant' );
	}

	$wpdb->update( 'ueb_insc_comptes', array( 'matricule' => $matricule ), array( 'id' => $compte->id ) );
	ueb_flash( 'succes', "Matricule enregistré. Tu peux désormais te connecter avec $matricule." );
	ueb_rediriger( ueb_url( 'mon-espace/securite' ) );
}
