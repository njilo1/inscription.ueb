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

/** IPES demandé par son identifiant, seulement s'il est visible par le compte. */
function ueb_ipes_sous_tutelle_par_id( $id ) {
	$ipes = ueb_ipes( (int) $id );
	return ueb_peut_voir_ipes( $ipes ) ? $ipes : null;
}
