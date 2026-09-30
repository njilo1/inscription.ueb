<?php
/**
 * Espace de l'administrateur d'un IPES : accès et page.
 *
 * L'espace est une Page WordPress (gabarit page-ipes.php), créée une fois
 * si elle manque, comme l'espace Direction. Le compte y voit son IPES et
 * rien d'autre : l'IPES vient toujours de la méta « ueb_ipes_id » du compte
 * connecté, jamais d'un paramètre envoyé par le navigateur.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

/* ---------- Page de l'espace, créée une fois si elle manque ---------- */

add_action( 'init', function () {
	if ( get_option( 'ueb_page_ipes_creee' ) || ueb_page_par_gabarit( 'page-ipes.php' ) || ! ueb_insc_verrouiller( 'page_ipes' ) ) {
		return;
	}
	/* Une autre requête a pu créer la Page pendant qu'on attendait. */
	if ( ueb_insc_option_en_base( 'ueb_page_ipes_creee' ) ) {
		ueb_insc_deverrouiller( 'page_ipes' );
		return;
	}
	$id = wp_insert_post( array(
		'post_title'  => 'Espace IPES',
		'post_name'   => 'espace-ipes',
		'post_status' => 'publish',
		'post_type'   => 'page',
		'meta_input'  => array( '_wp_page_template' => 'page-ipes.php' ),
	) );
	if ( $id && ! is_wp_error( $id ) ) {
		update_option( 'ueb_page_ipes_creee', (int) $id );
		delete_option( 'ueb_page_page-ipes' ); // recalcul de ueb_page_par_gabarit()
	}
	ueb_insc_deverrouiller( 'page_ipes' );
}, 30 );

/** Adresse de l'espace IPES. */
function ueb_url_espace_ipes() {
	$id = ueb_page_par_gabarit( 'page-ipes.php' );
	return $id ? get_permalink( $id ) : home_url( '/espace-ipes/' );
}

/* La barre d'outils de WordPress ne mène qu'à wp-admin, fermé à ces comptes. */
add_filter( 'show_admin_bar', function ( $afficher ) {
	return is_user_logged_in() && ueb_est_admin_ipes( get_current_user_id() ) ? false : $afficher;
} );

/* ---------- Accès ---------- */

/**
 * IPES administré par ce compte, s'il peut y accéder : compte d'administrateur
 * d'IPES non suspendu, IPES existant et actif. Null sinon.
 */
function ueb_ipes_du_compte( $user_id = 0 ) {
	$user_id = $user_id ?: get_current_user_id();
	if ( ! $user_id || ! user_can( $user_id, UEB_CAP_IPES ) || ! ueb_est_admin_ipes( $user_id ) || ueb_agent_suspendu( $user_id ) ) {
		return null;
	}
	$ipes = ueb_ipes( (int) get_user_meta( $user_id, 'ueb_ipes_id', true ) );
	return ( $ipes && (int) $ipes->actif ) ? $ipes : null;
}

/** Pour les actions de l'espace : l'IPES du compte connecté, ou refus (403). */
function ueb_exiger_admin_ipes() {
	$ipes = is_user_logged_in() ? ueb_ipes_du_compte() : null;
	if ( ! $ipes ) {
		wp_die( 'Action réservée à l’administrateur d’un IPES actif.', 'Accès refusé', array( 'response' => 403 ) );
	}
	return $ipes;
}

/* ---------- Actions de l'espace ----------
   Chaque action commence par ueb_exiger_admin_ipes() : l'IPES vient du compte
   connecté. Les identifiants postés (étudiant, bordereau) sont
   ensuite cherchés DANS cet IPES par les fonctions de données. */

/** Adresse d'une vue de l'espace IPES : ueb_url_espace_ipes_vue( 'etudiants', array( 'etudiant' => 12 ) ). */
function ueb_url_espace_ipes_vue( $vue, array $args = array() ) {
	return add_query_arg( array( 'vue' => $vue ) + $args, ueb_url_espace_ipes() );
}

/** Erreurs d'un WP_Error de validation (champ => message), ou son message en « general ». */
function ueb_ipes_erreurs_de( WP_Error $erreur ) {
	$donnees = $erreur->get_error_data();
	return is_array( $donnees ) && $donnees ? $donnees : array( 'general' => $erreur->get_error_message() );
}

function ueb_action_ipes_etudiant_enregistrer() {
	$ipes   = ueb_exiger_admin_ipes();
	$id     = (int) ( $_POST['etudiant_id'] ?? 0 );
	$saisie = array();
	foreach ( array( 'matricule', 'nom', 'prenom', 'filiere_id', 'niveau', 'telephone' ) as $champ ) {
		$saisie[ $champ ] = sanitize_text_field( wp_unslash( $_POST[ $champ ] ?? '' ) );
	}
	/* Faculté choisie avant la filière (IPES à plusieurs tutelles) : contrôlée avec elle. */
	if ( isset( $_POST['tutelle'] ) ) {
		$saisie['tutelle'] = sanitize_text_field( wp_unslash( $_POST['tutelle'] ) );
	}
	$resultat = ueb_ipes_etudiant_enregistrer( $ipes->id, $saisie, $id );
	if ( is_wp_error( $resultat ) ) {
		ueb_memoriser_saisie( $saisie, ueb_ipes_erreurs_de( $resultat ) );
		ueb_rediriger( $id ? ueb_url_espace_ipes_vue( 'etudiants', array( 'etudiant' => $id ) ) : ueb_url_espace_ipes_vue( 'etudiants', array( 'ajout' => 1 ) ) . '#ajout' );
	}
	if ( $id ) {
		ueb_flash( 'succes', 'Étudiant mis à jour.' );
		ueb_rediriger( ueb_url_espace_ipes_vue( 'etudiants', array( 'etudiant' => $resultat ) ) );
	}
	/* Saisie en série : on revient sur le formulaire, faculté, filière et niveau déjà choisis. */
	$ajoute = ueb_ipes_etudiant( $ipes->id, $resultat );
	$_SESSION['ueb_ipes_dernier_ajout'] = array( 'tutelle' => $ajoute->tutelle, 'filiere_id' => (string) $ajoute->filiere_id, 'niveau' => $ajoute->niveau );
	ueb_flash( 'succes', trim( $ajoute->nom . ' ' . $ajoute->prenom ) . ' ajouté. Saisis l’étudiant suivant.' );
	ueb_rediriger( ueb_url_espace_ipes_vue( 'etudiants', array( 'ajout' => 1 ) ) . '#ajout' );
}

function ueb_action_ipes_etudiant_supprimer() {
	$ipes     = ueb_exiger_admin_ipes();
	$id       = (int) ( $_POST['etudiant_id'] ?? 0 );
	$resultat = ueb_ipes_etudiant_supprimer( $ipes->id, $id );
	if ( is_wp_error( $resultat ) ) {
		ueb_flash( 'erreur', $resultat->get_error_message() );
		ueb_rediriger( ueb_url_espace_ipes_vue( 'etudiants', array( 'etudiant' => $id ) ) );
	}
	ueb_flash( 'succes', 'Étudiant supprimé.' );
	ueb_rediriger( ueb_url_espace_ipes_vue( 'etudiants' ) );
}

function ueb_action_ipes_bordereau_creer() {
	$ipes     = ueb_exiger_admin_ipes();
	$resultat = ueb_ipes_bordereau_creer( $ipes->id, sanitize_text_field( wp_unslash( $_POST['etablissement'] ?? '' ) ) );
	if ( is_wp_error( $resultat ) ) {
		ueb_flash( 'erreur', $resultat->get_error_message() );
		ueb_rediriger( ueb_url_espace_ipes_vue( 'bordereaux' ) );
	}
	ueb_flash( 'succes', 'Brouillon créé : coche les étudiants à reverser.' );
	ueb_rediriger( ueb_url_espace_ipes_vue( 'bordereaux', array( 'bordereau' => $resultat ) ) );
}

/** Enregistre les étudiants cochés ; avec le bouton « envoyer », envoie aussi le bordereau. */
function ueb_action_ipes_bordereau_enregistrer() {
	$ipes     = ueb_exiger_admin_ipes();
	$id       = (int) ( $_POST['bordereau_id'] ?? 0 );
	$retour   = ueb_url_espace_ipes_vue( 'bordereaux', array( 'bordereau' => $id ) );
	$resultat = ueb_ipes_bordereau_definir_etudiants( $ipes->id, $id, array_map( 'intval', (array) wp_unslash( $_POST['etudiants'] ?? array() ) ) );
	if ( ! is_wp_error( $resultat ) && isset( $_POST['envoyer'] ) ) {
		$resultat = ueb_ipes_bordereau_envoyer( $ipes->id, $id );
		if ( ! is_wp_error( $resultat ) ) {
			ueb_flash( 'succes', 'Bordereau envoyé. Il n’est plus modifiable ; sa vérification s’affichera ici.' );
			ueb_rediriger( $retour );
		}
	}
	if ( is_wp_error( $resultat ) ) {
		ueb_flash( 'erreur', $resultat->get_error_message() );
	} else {
		ueb_flash( 'succes', 'Sélection enregistrée.' );
	}
	ueb_rediriger( $retour );
}

function ueb_action_ipes_bordereau_supprimer() {
	$ipes     = ueb_exiger_admin_ipes();
	$resultat = ueb_ipes_bordereau_supprimer( $ipes->id, (int) ( $_POST['bordereau_id'] ?? 0 ) );
	ueb_flash( is_wp_error( $resultat ) ? 'erreur' : 'succes', is_wp_error( $resultat ) ? $resultat->get_error_message() : 'Brouillon supprimé : ses étudiants sont de nouveau à reverser.' );
	ueb_rediriger( ueb_url_espace_ipes_vue( 'bordereaux' ) );
}
/* PDF d'un bordereau depuis l'espace : ?vue=bordereaux&bordereau={id}&pdf=1.
   Seulement un bordereau de l'IPES du compte, et déjà envoyé (numéro officiel). */
add_action( 'template_redirect', function () {
	if ( ! isset( $_GET['pdf'], $_GET['bordereau'] ) || ! is_page_template( 'page-ipes.php' ) ) {
		return;
	}
	$ipes      = ueb_ipes_du_compte();
	$bordereau = $ipes ? ueb_ipes_bordereau( $ipes->id, (int) $_GET['bordereau'] ) : null;
	if ( ueb_ipes_bordereau_a_pdf( $bordereau ) ) {
		ueb_ipes_envoyer_pdf_bordereau( $ipes, $bordereau );
	}
	/* Sinon la page s'affiche normalement : connexion, ou « Bordereau introuvable ». */
}, 20 );