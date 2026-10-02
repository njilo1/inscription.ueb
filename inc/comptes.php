<?php
/**
 * Comptes étudiants : création, connexion, déconnexion, changement de mot
 * de passe et d'identifiant.
 *
 * Identifiant de connexion : le matricule seul (UEB_REGEX_MATRICULE, inc/config.php).
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
		'SELECT * FROM ueb_insc_comptes WHERE matricule = %s LIMIT 1',
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

/** Message d'erreur si l'identifiant saisi (normalisé) n'est pas un matricule valable, sinon ''. */
function ueb_erreur_matricule( $identifiant ) {
	if ( '' === $identifiant ) {
		return 'Saisis ton matricule.';
	}
	return 'matricule' === ueb_type_identifiant( $identifiant ) ? '' : UEB_MESSAGE_MATRICULE;
}

/** Identifiant affiché du compte : son matricule. */
function ueb_identifiant_compte( $compte ) {
	return (string) $compte->matricule;
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

	$erreur = ueb_erreur_matricule( $identifiant );
	if ( $erreur ) {
		ueb_memoriser_saisie( array( 'identifiant' => $identifiant ), array( 'general' => $erreur ) );
		ueb_rediriger( $retour );
	}

	$compte = ueb_compte_par_identifiant( $identifiant );
	if ( ! $compte || ! password_verify( $mdp, $compte->mot_de_passe ) ) {
		ueb_noter_echec();
		ueb_memoriser_saisie( array( 'identifiant' => $identifiant ), array( 'general' => 'Identifiant ou mot de passe incorrect.' ) );
		ueb_rediriger( $retour );
	}
	if ( 'actif' !== $compte->statut ) {
		ueb_memoriser_saisie( array( 'identifiant' => $identifiant ), array( 'general' => 'Ce compte est suspendu. Présente-toi à la scolarité de ton établissement.' ) );
		ueb_rediriger( $retour );
	}

	global $wpdb;
	if ( password_needs_rehash( $compte->mot_de_passe, PASSWORD_DEFAULT ) ) {
		$wpdb->update( 'ueb_insc_comptes', array( 'mot_de_passe' => password_hash( $mdp, PASSWORD_DEFAULT ) ), array( 'id' => $compte->id ) );
	}
	delete_transient( ueb_cle_essais() );
	/* Il retrouve son ancien mot de passe : la réinitialisation en attente est annulée. */
	if ( ! empty( $compte->reinit_le ) ) {
		$wpdb->update( 'ueb_insc_comptes', array( 'doit_changer_mdp' => 0, 'reinit_le' => null ), array( 'id' => $compte->id ) );
		$compte->doit_changer_mdp = 0;
	}
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

	$erreur_id = ueb_erreur_matricule( $identifiant );
	if ( $erreur_id ) {
		$erreurs['identifiant'] = $erreur_id;
	} elseif ( ueb_compte_par_identifiant( $identifiant ) ) {
		$erreurs['identifiant'] = 'Un compte existe déjà avec ce matricule. Connecte-toi.';
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
		'matricule'    => $identifiant,
		'telephone'    => $telephone,
		'mot_de_passe' => password_hash( $mdp, PASSWORD_DEFAULT ),
	) );
	if ( ! $ok ) {
		error_log( '[inscriptions-ueb] Création de compte impossible : ' . $wpdb->last_error );
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
		ueb_rediriger( ueb_url( 'mon-espace/compte' ) . '#mot-de-passe' );
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
	ueb_rediriger( ueb_url( 'mon-espace/compte' ) . '#securite' );
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

	$erreur_matricule = ueb_erreur_matricule( $matricule );
	if ( $erreur_matricule ) {
		$erreurs['matricule'] = $erreur_matricule;
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
		ueb_rediriger( ueb_url( 'mon-espace/compte' ) . '#identifiant' );
	}

	$wpdb->update( 'ueb_insc_comptes', array( 'matricule' => $matricule ), array( 'id' => $compte->id ) );
	ueb_flash( 'succes', "Matricule enregistré. Tu peux désormais te connecter avec $matricule." );
	ueb_rediriger( ueb_url( 'mon-espace/compte' ) . '#securite' );
}

/* ---------- Mot de passe oublié ----------
   La scolarité (ou la cellule informatique) réinitialise le compte après
   avoir vu la carte d'identité de l'étudiant : « doit_changer_mdp » (la
   « première connexion ») passe à vrai, avec l'heure dans « reinit_le ». Elle
   ne voit ni ne choisit aucun mot de passe. Pendant UEB_REINIT_DUREE,
   l'étudiant saisit son matricule sur /mot-de-passe-oublie/ et choisit
   directement le nouveau. Passé ce délai, la réinitialisation expire : il
   repasse à la scolarité. L'ancien mot de passe reste valable jusque-là ; se
   connecter avec lui annule la réinitialisation. Chaque réinitialisation est
   tracée dans ueb_insc_reinitialisations (qui, quand, mot de passe choisi quand). */

const UEB_REINIT_DUREE = HOUR_IN_SECONDS;

/** Fin de validité de la réinitialisation du compte (horodatage local), ou null s'il n'y en a pas. */
function ueb_reinit_fin( $compte ) {
	if ( empty( $compte->doit_changer_mdp ) || empty( $compte->reinit_le ) ) {
		return null;
	}
	return strtotime( $compte->reinit_le ) + UEB_REINIT_DUREE;
}

/** « active » (l'étudiant peut choisir son mot de passe), « expiree », ou '' (aucune réinitialisation). */
function ueb_reinit_etat( $compte ) {
	$fin = $compte ? ueb_reinit_fin( $compte ) : null;
	if ( null === $fin ) {
		return '';
	}
	return current_time( 'timestamp' ) < $fin ? 'active' : 'expiree';
}

/** Heure lisible de fin d'une réinitialisation : « 11 h 05 », ou « 3 octobre à 11 h 05 » un autre jour. */
function ueb_reinit_heure( $horodatage ) {
	$jour = wp_date( 'Y-m-d', $horodatage, new DateTimeZone( 'UTC' ) ) === current_time( 'Y-m-d' ) ? '' : wp_date( 'j F', $horodatage, new DateTimeZone( 'UTC' ) ) . ' à ';
	return $jour . str_replace( ':', ' h ', wp_date( 'G:i', $horodatage, new DateTimeZone( 'UTC' ) ) );
}

/** Badge de l'état du mot de passe d'un compte, pour les listes de la scolarité et de la cellule. */
function ueb_badge_mdp( $compte ) {
	switch ( ueb_reinit_etat( $compte ) ) {
		case 'active':
			return ' <span class="badge badge--recu_envoye"><i></i>' . esc_html( 'Réinitialisé, jusqu’à ' . ueb_reinit_heure( ueb_reinit_fin( $compte ) ) ) . '</span>';
		case 'expiree':
			return ' <span class="badge badge--rejete"><i></i>Réinitialisation expirée</span>';
	}
	return ! empty( $compte->doit_changer_mdp ) ? ' <span class="badge badge--genere"><i></i>Mdp provisoire</span>' : '';
}

/** Compte dont l'étudiant choisit son mot de passe (étape 2 de /mot-de-passe-oublie/), s'il est toujours valable. */
function ueb_reinit_en_cours() {
	$s = $_SESSION['ueb_reinit'] ?? null;
	if ( ! $s ) {
		return null;
	}
	$compte = ueb_compte_par_id( (int) $s['compte'] );
	/* La même réinitialisation (une nouvelle par la scolarité invalide l'étape), toujours active, compte actif. */
	if ( ! $compte || 'actif' !== $compte->statut || 'active' !== ueb_reinit_etat( $compte ) || (string) $compte->reinit_le !== (string) $s['le'] ) {
		unset( $_SESSION['ueb_reinit'] );
		return null;
	}
	return $compte;
}

/** Étape 1 : le matricule. Réinitialisation active → étape 2 ; sinon, message en rouge. */
function ueb_action_mdp_oublie_verifier() {
	$retour      = ueb_url( 'mot-de-passe-oublie' );
	$identifiant = ueb_normaliser_identifiant( wp_unslash( $_POST['identifiant'] ?? '' ) );
	unset( $_SESSION['ueb_reinit'] );
	if ( ueb_trop_d_essais() ) {
		ueb_memoriser_saisie( array( 'identifiant' => $identifiant ), array( 'general' => 'Trop de tentatives. Patiente 15 minutes avant de réessayer.' ) );
		ueb_rediriger( $retour );
	}
	$erreur = ueb_erreur_matricule( $identifiant );
	if ( $erreur ) {
		ueb_memoriser_saisie( array( 'identifiant' => $identifiant ), array( 'identifiant' => $erreur ) );
		ueb_rediriger( $retour );
	}
	$compte = ueb_compte_par_identifiant( $identifiant );
	$etat   = ueb_reinit_etat( $compte );
	if ( $compte && 'actif' === $compte->statut && 'active' === $etat ) {
		$_SESSION['ueb_reinit'] = array( 'compte' => (int) $compte->id, 'le' => (string) $compte->reinit_le );
		ueb_rediriger( $retour );
	}
	/* Chaque matricule sans réinitialisation active compte comme un essai : on ne devine pas les comptes. */
	ueb_noter_echec();
	if ( $compte && 'actif' !== $compte->statut ) {
		$message = 'Ce compte est suspendu. Présente-toi à la scolarité de ton établissement avec ta carte d’identité.';
	} elseif ( 'expiree' === $etat ) {
		$quand   = ueb_reinit_heure( strtotime( $compte->reinit_le ) );
		$message = 'Ta réinitialisation faite ' . ( false === strpos( $quand, ' à ' ) ? 'à ' : 'le ' ) . $quand . ' a expiré (valable 1 h). Repasse à la scolarité de ton établissement avec ta carte d’identité.';
	} else {
		$message = 'Ton mot de passe n’a pas été réinitialisé. Présente-toi d’abord à la scolarité de ton établissement avec ta carte d’identité, puis reviens ici.';
	}
	ueb_memoriser_saisie( array( 'identifiant' => $identifiant ), array( 'general' => $message ) );
	ueb_rediriger( $retour );
}

/** Étape 2 : le nouveau mot de passe. La réinitialisation est close et l'étudiant connecté. */
function ueb_action_mdp_oublie_choisir() {
	global $wpdb;
	$retour = ueb_url( 'mot-de-passe-oublie' );
	$compte = ueb_reinit_en_cours();
	if ( ! $compte ) {
		ueb_memoriser_saisie( array(), array( 'general' => 'Ta réinitialisation n’est plus valable. Saisis de nouveau ton matricule ; si elle a expiré, repasse à la scolarité.' ) );
		ueb_rediriger( $retour );
	}
	$nouveau      = (string) wp_unslash( $_POST['mot_de_passe'] ?? '' );
	$confirmation = (string) wp_unslash( $_POST['confirmation'] ?? '' );
	$erreurs      = array();
	$erreur_mdp   = ueb_erreur_mot_de_passe( $nouveau, ueb_identifiant_compte( $compte ) );
	if ( $erreur_mdp ) {
		$erreurs['mot_de_passe'] = $erreur_mdp;
	} elseif ( $nouveau !== $confirmation ) {
		$erreurs['confirmation'] = 'Les deux mots de passe ne sont pas identiques.';
	}
	if ( $erreurs ) {
		ueb_memoriser_saisie( array(), $erreurs );
		ueb_rediriger( $retour );
	}
	/* Condition sur la réinitialisation : une seule utilisation, même si deux envois arrivent ensemble. */
	$version = (int) $compte->version_session + 1;
	$ok      = $wpdb->query( $wpdb->prepare(
		"UPDATE ueb_insc_comptes SET mot_de_passe = %s, doit_changer_mdp = 0, reinit_le = NULL, version_session = %d
		 WHERE id = %d AND doit_changer_mdp = 1 AND reinit_le = %s AND statut = 'actif'",
		password_hash( $nouveau, PASSWORD_DEFAULT ), $version, $compte->id, $compte->reinit_le
	) );
	unset( $_SESSION['ueb_reinit'] );
	if ( 1 !== (int) $ok ) {
		ueb_memoriser_saisie( array(), array( 'general' => 'Ta réinitialisation n’est plus valable. Saisis de nouveau ton matricule.' ) );
		ueb_rediriger( $retour );
	}
	$wpdb->query( $wpdb->prepare(
		'UPDATE ueb_insc_reinitialisations SET date_choix = %s WHERE compte_id = %d AND date_choix IS NULL ORDER BY id DESC LIMIT 1',
		current_time( 'mysql' ), $compte->id
	) );
	delete_transient( ueb_cle_essais() );
	$compte->version_session = $version;
	ueb_connecter( $compte );
	ueb_flash( 'succes', 'Ton nouveau mot de passe est enregistré. Tu es connecté.' );
	ueb_rediriger( ueb_url( 'mon-espace' ) );
}
