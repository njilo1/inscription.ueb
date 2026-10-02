<?php
/**
 * Fiche de l'étudiant : établissement, formation, identité et contacts.
 *
 * Saisie au premier quitus, elle est gardée sur le compte. Les quitus
 * suivants reprennent chaque information déjà renseignée sans permettre de la
 * changer : l'étudiant la corrige dans Mon compte (sauf l'établissement et la
 * filière, fixés au premier quitus). Un champ encore vide (les
 * coordonnées CMS, facultatives au premier quitus) se remplit dans le
 * formulaire du quitus. Un quitus déjà généré garde ses valeurs : il ne
 * reprend la fiche que si l'étudiant l'enregistre de nouveau (« Modifier »).
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

const UEB_CHAMPS_PROFIL = array( 'etablissement', 'filiere_id', 'parcours', 'nom', 'prenom', 'date_naissance', 'lieu_naissance', 'sexe', 'nationalite', 'email', 'adresse', 'nom_urgence', 'numero_urgence', 'adresse_urgence' );

/** Informations renseignées de l'étudiant (champs vides omis) ; tableau vide sans fiche. */
function ueb_profil( $compte_id ) {
	global $wpdb;
	$ligne = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ueb_insc_profils WHERE compte_id = %d', $compte_id ), ARRAY_A );
	if ( ! $ligne ) {
		return array();
	}
	return array_filter(
		array_intersect_key( $ligne, array_flip( UEB_CHAMPS_PROFIL ) ),
		static fn( $valeur ) => null !== $valeur && '' !== $valeur
	);
}

/** Enregistre la fiche à partir de valeurs validées. */
function ueb_profil_enregistrer( $compte_id, array $v ) {
	global $wpdb;
	$donnees = array_intersect_key( $v, array_flip( UEB_CHAMPS_PROFIL ) );
	$donnees['filiere_id']     = (int) ( $donnees['filiere_id'] ?? 0 ) ?: null;
	$donnees['date_naissance'] = ( $donnees['date_naissance'] ?? '' ) ?: null;
	return false !== $wpdb->replace( 'ueb_insc_profils', array( 'compte_id' => (int) $compte_id ) + $donnees );
}

/** Affiche une partie des champs de la fiche (templates/composants/profil-champs.php). */
function ueb_champs_profil( array $args ) {
	require UEB_INSC_DIR . '/templates/composants/profil-champs.php';
}

/** Texte posté, nettoyé et ramené à une ligne. */
function ueb_texte_poste( array $post, $cle ) {
	$brut = $post[ $cle ] ?? '';
	return trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( wp_unslash( is_scalar( $brut ) ? (string) $brut : '' ) ) ) );
}

/**
 * Contrôle de la fiche, commun au quitus et à Mon compte.
 *
 * @param array $formations filières proposées, par identifiant (ueb_formations_inscription())
 * @param bool  $cms_requis email, adresse et contact d'urgence obligatoires (fiches CMS)
 * @return array{0: array, 1: array} valeurs nettoyées (avec le libellé « departement »), erreurs par champ
 */
function ueb_valider_profil( array $post, array $formations, $cms_requis ) {
	$texte = static fn( $cle ) => ueb_texte_poste( $post, $cle );
	$v = array(
		'etablissement'  => strtoupper( $texte( 'etablissement' ) ),
		'nom'            => mb_strtoupper( $texte( 'nom' ) ),
		'prenom'         => $texte( 'prenom' ),
		'date_naissance' => $texte( 'date_naissance' ),
		'lieu_naissance' => $texte( 'lieu_naissance' ),
		'sexe'           => strtoupper( $texte( 'sexe' ) ),
		'nationalite'    => $texte( 'nationalite' ),
		'departement'    => $texte( 'departement' ),
		'parcours'       => $texte( 'parcours' ),
		'email'          => $texte( 'email' ),
		'adresse'        => $texte( 'adresse' ),
		'nom_urgence'    => $texte( 'nom_urgence' ),
		'numero_urgence' => ueb_normaliser_telephone( $texte( 'numero_urgence' ) ) ?? $texte( 'numero_urgence' ),
		'adresse_urgence'=> $texte( 'adresse_urgence' ),
		'filiere_id'     => (int) $texte( 'filiere_id' ),
	);
	$e = array();

	if ( ! ueb_etablissement( $v['etablissement'] ) ) {
		$e['etablissement'] = 'Choisis ton établissement.';
	}
	$nom_valide = '/^[\p{L}][\p{L}\' .-]*$/u';
	if ( mb_strlen( $v['nom'] ) < 2 || ! preg_match( $nom_valide, $v['nom'] ) ) {
		$e['nom'] = 'Saisis ton nom tel qu’il figure sur ton acte de naissance.';
	}
	if ( mb_strlen( $v['prenom'] ) < 2 || ! preg_match( $nom_valide, $v['prenom'] ) ) {
		$e['prenom'] = 'Saisis ton ou tes prénoms.';
	}
	$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $v['date_naissance'] );
	if ( ! $date || $date->format( 'Y-m-d' ) !== $v['date_naissance'] ) {
		$e['date_naissance'] = 'Date de naissance invalide.';
	} else {
		$age = $date->diff( new DateTimeImmutable( 'today' ) )->y;
		if ( $age < 14 || $age > 80 ) {
			$e['date_naissance'] = 'Vérifie ta date de naissance.';
		}
	}
	if ( mb_strlen( $v['lieu_naissance'] ) < 2 ) {
		$e['lieu_naissance'] = 'Saisis ton lieu de naissance.';
	}
	if ( ! in_array( $v['sexe'], array( 'M', 'F' ), true ) ) {
		$e['sexe'] = 'Indique ton sexe.';
	}
	if ( ! in_array( $v['nationalite'], ueb_nationalites(), true ) ) {
		$e['nationalite'] = 'Choisis ta nationalité dans la liste.';
	}
	if ( ( $cms_requis || '' !== $v['email'] ) && ! is_email( $v['email'] ) ) {
		$e['email'] = 'Saisis une adresse email valide pour les documents CMS.';
	}
	if ( ( $cms_requis || '' !== $v['adresse'] ) && mb_strlen( $v['adresse'] ) < 3 ) {
		$e['adresse'] = 'Saisis ton adresse complète.';
	}
	if ( ( $cms_requis || '' !== $v['nom_urgence'] ) && mb_strlen( $v['nom_urgence'] ) < 2 ) {
		$e['nom_urgence'] = 'Saisis la personne à contacter en cas d’urgence.';
	}
	if ( ( $cms_requis || '' !== $v['numero_urgence'] ) && ! ueb_normaliser_telephone( $v['numero_urgence'] ) ) {
		$e['numero_urgence'] = 'Saisis un numéro camerounais à 9 chiffres, avec ou sans +237.';
	}
	if ( ( $cms_requis || '' !== $v['adresse_urgence'] ) && mb_strlen( $v['adresse_urgence'] ) < 3 ) {
		$e['adresse_urgence'] = 'Saisis l’adresse de la personne à contacter.';
	}
	$formation = $formations[ $v['filiere_id'] ] ?? null;
	if ( ! $formation || $formation->etablissement !== $v['etablissement'] ) {
		$e['filiere_id'] = 'Choisis une filière rattachée à cet établissement.';
	} else {
		$v['departement'] = $formation->libelle;
	}
	if ( ! isset( UEB_NIVEAUX_INSCRIPTION[ $v['parcours'] ] ) ) {
		$e['parcours'] = 'Choisis ton niveau dans la liste.';
	}
	foreach ( array( 'nom' => 100, 'prenom' => 150, 'lieu_naissance' => 150, 'departement' => 150, 'parcours' => 150 ) as $cle => $max ) {
		if ( mb_strlen( $v[ $cle ] ) > $max && empty( $e[ $cle ] ) ) {
			$e[ $cle ] = "$max caractères au maximum.";
		}
	}
	return array( $v, $e );
}

/** Mon compte : enregistre les informations de l'étudiant. */
function ueb_action_enregistrer_profil() {
	$compte = ueb_compte_courant();
	if ( ! $compte ) {
		ueb_rediriger( ueb_url( 'connexion' ) );
	}
	$retour     = ueb_url( 'mon-espace/compte' ) . '#informations';
	list( $v, $erreurs ) = ueb_valider_profil( $_POST, ueb_formations_inscription(), false );
	/* L'établissement et la filière ne se modifient pas dans Mon compte : ceux
	   fixés au premier quitus sont conservés tels quels. */
	$profil = ueb_profil( $compte->id );
	unset( $erreurs['etablissement'], $erreurs['filiere_id'] );
	$v['etablissement'] = $profil['etablissement'] ?? '';
	$v['filiere_id']    = (int) ( $profil['filiere_id'] ?? 0 );
	if ( $erreurs ) {
		ueb_memoriser_saisie( array( 'formulaire' => 'profil' ) + $v, $erreurs );
		ueb_rediriger( $retour );
	}
	if ( ! ueb_profil_enregistrer( $compte->id, $v ) ) {
		global $wpdb;
		error_log( '[inscriptions-ueb] Enregistrement de la fiche impossible : ' . $wpdb->last_error );
		ueb_memoriser_saisie( array( 'formulaire' => 'profil' ) + $v, array( 'general' => 'Tes informations n’ont pas pu être enregistrées. Réessaie dans un instant.' ) );
		ueb_rediriger( $retour );
	}
	/* Un quitus généré garde ses valeurs : on indique comment le mettre à jour. */
	$a_payer = array_filter( ueb_quitus_du_compte( $compte->id ), static fn( $q ) => empty( $q->quitus_droits_id ) && ueb_quitus_modifiable( $q ) );
	ueb_flash( 'succes', 'Tes informations sont enregistrées. Elles seront reprises sur tes prochains quitus.'
		. ( $a_payer ? ' Pour corriger aussi un quitus déjà généré et pas encore payé, ouvre-le dans Mes quitus avec « Modifier », puis enregistre-le.' : '' ) );
	ueb_rediriger( $retour );
}
