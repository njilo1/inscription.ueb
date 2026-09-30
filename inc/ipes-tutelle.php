<?php
/**
 * IPES vus par leur établissement de tutelle, dans l'espace scolarité.
 *
 * Deux permissions attribuées par la Direction (inc/roles.php) :
 *   - « ueb_voir_ipes »     : suivre, en lecture seule, les IPES dont au moins
 *                             une tutelle est dans la portée du compte ;
 *   - « ueb_verifier_ipes » : vérifier ou rejeter les bordereaux adressés à
 *                             un établissement de sa portée.
 *
 * Un IPES sous plusieurs tutelles est visible en entier par chacune, mais
 * chacune ne décide que des bordereaux qui lui sont adressés.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

/**
 * Établissements dont le compte consulte les IPES : celui choisi dans le
 * sélecteur de l'espace (portée « plusieurs » ou « tous »), sinon toute sa portée.
 */
function ueb_ipes_tutelles_consultees() {
	$courant = ueb_etab_agent();
	if ( $courant && UEB_AUCUN_ETAB !== $courant ) {
		return array( $courant );
	}
	return UEB_AUCUN_ETAB === $courant ? array() : ueb_etabs_autorises();
}

/** Vrai si le compte peut voir cet IPES : permission et une tutelle dans sa portée. */
function ueb_peut_voir_ipes( $ipes ) {
	return $ipes && ueb_peut( 'ueb_voir_ipes' ) && (bool) array_intersect( (array) $ipes->tutelles, ueb_etabs_autorises() );
}

/** Vrai si le compte peut décider de ce bordereau : permission, envoyé, adressé à sa portée. */
function ueb_peut_verifier_bordereau( $bordereau ) {
	return $bordereau && 'envoye' === $bordereau->statut && ueb_peut( 'ueb_verifier_ipes', $bordereau->etablissement );
}

/**
 * IPES que le compte suit, pour les établissements consultés, avec leurs
 * tutelles ; filtres de ueb_ipes_liste() (recherche, actif) acceptés.
 */
function ueb_ipes_sous_tutelle( array $filtres = array() ) {
	if ( ! ueb_peut( 'ueb_voir_ipes' ) ) {
		return array();
	}
	$sigles = ueb_ipes_tutelles_consultees();
	return array_values( array_filter(
		ueb_ipes_liste( $filtres ),
		static fn( $ipes ) => (bool) array_intersect( $ipes->tutelles, $sigles )
	) );
}

/**
 * Tutelles d'un IPES que le compte regarde : celles des établissements
 * consultés (sélecteur de l'espace), sinon celles de toute sa portée. Une
 * scolarité ne voit que ce qui concerne ses établissements : étudiants,
 * montants et bordereaux des autres tutelles restent cachés.
 */
function ueb_ipes_tutelles_vues( $ipes ) {
	if ( ! $ipes ) {
		return array();
	}
	$vues = array_values( array_intersect( $ipes->tutelles, ueb_ipes_tutelles_consultees() ) );
	return $vues ?: array_values( array_intersect( $ipes->tutelles, ueb_etabs_autorises() ) );
}

/** IPES demandé par son identifiant, seulement s'il est visible par le compte. */
function ueb_ipes_sous_tutelle_par_id( $id ) {
	$ipes = ueb_ipes( (int) $id );
	return ueb_peut_voir_ipes( $ipes ) ? $ipes : null;
}

/**
 * Bordereaux d'un IPES que la tutelle voit : ceux adressés aux tutelles qu'elle
 * regarde (jamais les brouillons, ni ceux d'une autre tutelle).
 */
function ueb_ipes_bordereaux_pour_tutelle( $ipes_id ) {
	$sigles = ueb_ipes_tutelles_vues( ueb_ipes( $ipes_id ) );
	return array_values( array_filter(
		ueb_ipes_bordereaux_pour_ueb( $ipes_id ),
		static fn( $b ) => in_array( $b->etablissement, $sigles, true )
	) );
}
/* ---------- PDF et décision depuis l'espace scolarité ---------- */

/** Bordereau d'un IPES visible par le compte ET adressé à sa portée, ou null. */
function ueb_ipes_bordereau_pour_tutelle( $ipes, $bordereau_id ) {
	$bordereau = $ipes ? ueb_ipes_bordereau( $ipes->id, (int) $bordereau_id ) : null;
	return $bordereau && in_array( $bordereau->etablissement, ueb_etabs_autorises(), true ) ? $bordereau : null;
}

/* PDF : ?vue=ipes&ipes={id}&bordereau={id}&pdf=1 sur la Page de la scolarité. */
add_action( 'template_redirect', function () {
	if ( ! isset( $_GET['pdf'], $_GET['bordereau'], $_GET['ipes'] ) || ! is_page_template( 'page-scolarite.php' ) || ! ueb_est_scolarite() ) {
		return;
	}
	$ipes      = ueb_ipes_sous_tutelle_par_id( (int) $_GET['ipes'] );
	$bordereau = ueb_ipes_bordereau_pour_tutelle( $ipes, (int) $_GET['bordereau'] );
	if ( ueb_ipes_bordereau_a_pdf( $bordereau ) ) {
		ueb_ipes_envoyer_pdf_bordereau( $ipes, $bordereau );
	}
}, 20 );

/**
 * Décision de la tutelle sur un bordereau qui lui est adressé :
 * « verifie », ou « rejete » avec un motif. Permission « ueb_verifier_ipes ».
 */
function ueb_action_ipes_bordereau_decider_tutelle() {
	if ( ! ueb_peut( 'ueb_verifier_ipes' ) ) {
		wp_die( 'Action réservée aux comptes autorisés à vérifier les bordereaux des IPES.', 'Accès refusé', array( 'response' => 403 ) );
	}
	$ipes = ueb_ipes_sous_tutelle_par_id( (int) ( $_POST['ipes_id'] ?? 0 ) );
	if ( ! $ipes ) {
		ueb_flash( 'erreur', 'Cet IPES n’est pas sous la tutelle de ton établissement.' );
		ueb_rediriger( add_query_arg( 'vue', 'ipes', ueb_url_scolarite() ) );
	}
	$retour    = add_query_arg( array( 'vue' => 'ipes', 'ipes' => (int) $ipes->id ), ueb_url_scolarite() );
	$bordereau = ueb_ipes_bordereau_pour_tutelle( $ipes, (int) ( $_POST['bordereau_id'] ?? 0 ) );
	if ( ! ueb_peut_verifier_bordereau( $bordereau ) ) {
		ueb_flash( 'erreur', 'Ce bordereau ne t’est pas adressé, ou n’attend pas de vérification.' );
		ueb_rediriger( $retour );
	}
	$verifie  = 'verifie' === sanitize_key( wp_unslash( $_POST['decision'] ?? '' ) );
	$resultat = ueb_ipes_bordereau_decider( $bordereau->id, $verifie, sanitize_textarea_field( wp_unslash( $_POST['motif'] ?? '' ) ) );
	if ( is_wp_error( $resultat ) ) {
		ueb_flash( 'erreur', $resultat->get_error_message() );
	} else {
		ueb_flash( 'succes', 'Bordereau ' . $bordereau->numero . ( $verifie ? ' vérifié.' : ' rejeté : l’IPES voit le motif et peut le corriger.' ) );
	}
	ueb_rediriger( $retour );
}