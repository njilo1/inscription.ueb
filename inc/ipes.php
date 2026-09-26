<?php
/**
 * IPES : établissements privés placés sous la tutelle d'un ou plusieurs
 * établissements de l'UEb, par convention.
 *
 * Les IPES sont en base (tables ueb_insc_ipes*), créés par l'administration,
 * contrairement aux neuf établissements de ueb_etablissements() qui restent
 * écrits dans inc/config.php. Leurs étudiants n'utilisent pas ce site.
 *
 * Ce fichier ne contient que l'accès aux données et leur validation ; les
 * actions des formulaires s'appuient dessus.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

/* Longueurs maximales, alignées sur les colonnes de ueb_insc_ipes. */
const UEB_IPES_LONGUEURS = array(
	'nom_fr'         => 150,
	'nom_en'         => 150,
	'ville'          => 100,
	'email'          => 150,
	'convention_ref' => 100,
);

/* ---------- Lecture ---------- */

/** Un IPES avec ses tutelles (->tutelles : tableau de sigles), ou null. */
function ueb_ipes( $id ) {
	global $wpdb;
	$ipes = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ueb_insc_ipes WHERE id = %d', $id ) );
	if ( $ipes ) {
		$ipes->tutelles = ueb_ipes_tutelles( $ipes->id );
	}
	return $ipes;
}

/** Sigles des établissements de tutelle d'un IPES, par ordre alphabétique. */
function ueb_ipes_tutelles( $ipes_id ) {
	global $wpdb;
	return $wpdb->get_col( $wpdb->prepare(
		'SELECT etablissement FROM ueb_insc_ipes_tutelles WHERE ipes_id = %d ORDER BY etablissement',
		$ipes_id
	) );
}

/**
 * Liste des IPES, chacun avec ses tutelles, triée par sigle.
 *
 * @param array $filtres etablissement (sigle de tutelle), actif (0 ou 1),
 *                       recherche (dans le sigle et les noms).
 */
function ueb_ipes_liste( array $filtres = array() ) {
	global $wpdb;
	$where  = array( '1 = 1' );
	$params = array();

	$etab = strtoupper( (string) ( $filtres['etablissement'] ?? '' ) );
	if ( ueb_etablissement( $etab ) ) {
		$where[]  = 'EXISTS ( SELECT 1 FROM ueb_insc_ipes_tutelles t WHERE t.ipes_id = i.id AND t.etablissement = %s )';
		$params[] = $etab;
	}
	if ( isset( $filtres['actif'] ) && '' !== $filtres['actif'] ) {
		$where[]  = 'i.actif = %d';
		$params[] = $filtres['actif'] ? 1 : 0;
	}
	$recherche = trim( (string) ( $filtres['recherche'] ?? '' ) );
	if ( '' !== $recherche ) {
		$motif    = '%' . $wpdb->esc_like( $recherche ) . '%';
		$where[]  = '( i.sigle LIKE %s OR i.nom_fr LIKE %s OR i.nom_en LIKE %s )';
		$params[] = $motif;
		$params[] = $motif;
		$params[] = $motif;
	}

	$sql = 'SELECT i.* FROM ueb_insc_ipes i WHERE ' . implode( ' AND ', $where ) . ' ORDER BY i.sigle';
	$liste = $wpdb->get_results( $params ? $wpdb->prepare( $sql, $params ) : $sql );
	if ( ! $liste ) {
		return array();
	}

	/* Toutes les tutelles en une requête, plutôt qu'une par IPES. */
	$ids     = array_map( static fn( $i ) => (int) $i->id, $liste );
	$marques = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$lignes  = $wpdb->get_results( $wpdb->prepare(
		"SELECT ipes_id, etablissement FROM ueb_insc_ipes_tutelles WHERE ipes_id IN ($marques) ORDER BY etablissement",
		$ids
	) );
	$tutelles = array();
	foreach ( $lignes as $l ) {
		$tutelles[ (int) $l->ipes_id ][] = $l->etablissement;
	}
	foreach ( $liste as $ipes ) {
		$ipes->tutelles = $tutelles[ (int) $ipes->id ] ?? array();
	}
	return $liste;
}

/* ---------- Validation ---------- */

/**
 * Données d'un formulaire IPES mises en forme : textes rognés, sigle en
 * majuscules, téléphone sans espaces, tutelles uniques, dates vides à null.
 * Un téléphone mal formé est conservé tel quel pour que la validation le signale.
 */
function ueb_ipes_normaliser( array $d ) {
	$propre = array();
	foreach ( array( 'nom_fr', 'nom_en', 'ville', 'email', 'convention_ref' ) as $champ ) {
		$propre[ $champ ] = trim( (string) ( $d[ $champ ] ?? '' ) );
	}
	$propre['sigle']     = strtoupper( preg_replace( '/\s+/', '', (string) ( $d['sigle'] ?? '' ) ) );
	$telephone           = trim( (string) ( $d['telephone'] ?? '' ) );
	$propre['telephone'] = '' === $telephone ? '' : ( ueb_normaliser_telephone( $telephone ) ?? $telephone );
	foreach ( array( 'convention_signee_le', 'convention_fin_le' ) as $champ ) {
		$date             = trim( (string) ( $d[ $champ ] ?? '' ) );
		$propre[ $champ ] = '' === $date ? null : $date;
	}
	$propre['tutelles'] = array_values( array_unique( array_map(
		static fn( $s ) => strtoupper( trim( (string) $s ) ),
		(array) ( $d['tutelles'] ?? array() )
	) ) );
	return $propre;
}

/** Vrai pour une date AAAA-MM-JJ qui existe au calendrier. */
function ueb_ipes_date_valide( $date ) {
	$objet = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $date );
	return $objet && $objet->format( 'Y-m-d' ) === $date;
}

/**
 * Erreurs d'un IPES normalisé, sous la forme champ => message (vide si tout va).
 *
 * @param array $d  Données passées par ueb_ipes_normaliser().
 * @param int   $id IPES modifié (0 à la création), exclu du contrôle d'unicité.
 */
function ueb_ipes_valider( array $d, $id = 0 ) {
	global $wpdb;
	$erreurs = array();

	if ( ! preg_match( '/^[A-Z0-9-]{2,20}$/', $d['sigle'] ) ) {
		$erreurs['sigle'] = 'Le sigle compte 2 à 20 caractères : lettres, chiffres ou tiret.';
	} elseif ( ueb_etablissement( $d['sigle'] ) || 'UEB' === $d['sigle'] ) {
		$erreurs['sigle'] = 'Ce sigle est celui d’un établissement de l’UEb.';
	} elseif ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ueb_insc_ipes WHERE sigle = %s AND id <> %d', $d['sigle'], $id ) ) ) {
		$erreurs['sigle'] = 'Un autre IPES porte déjà ce sigle.';
	}

	if ( '' === $d['nom_fr'] ) {
		$erreurs['nom_fr'] = 'Saisis le nom de l’IPES.';
	}
	foreach ( UEB_IPES_LONGUEURS as $champ => $max ) {
		if ( ! isset( $erreurs[ $champ ] ) && mb_strlen( $d[ $champ ] ) > $max ) {
			$erreurs[ $champ ] = "$max caractères au maximum.";
		}
	}

	if ( '' !== $d['email'] && ! isset( $erreurs['email'] ) && ! is_email( $d['email'] ) ) {
		$erreurs['email'] = 'Adresse e-mail invalide.';
	}
	if ( '' !== $d['telephone'] && ! preg_match( UEB_REGEX_TELEPHONE, $d['telephone'] ) ) {
		$erreurs['telephone'] = 'Numéro mobile à 9 chiffres commençant par 6.';
	}

	if ( ! $d['tutelles'] ) {
		$erreurs['tutelles'] = 'Choisis au moins un établissement de tutelle.';
	} elseif ( array_filter( $d['tutelles'], static fn( $s ) => ! ueb_etablissement( $s ) ) ) {
		$erreurs['tutelles'] = 'Établissement de tutelle inconnu.';
	}

	foreach ( array( 'convention_signee_le', 'convention_fin_le' ) as $champ ) {
		if ( null !== $d[ $champ ] && ! ueb_ipes_date_valide( $d[ $champ ] ) ) {
			$erreurs[ $champ ] = 'Date invalide.';
		}
	}
	if ( ! isset( $erreurs['convention_signee_le'] ) && ! isset( $erreurs['convention_fin_le'] )
		&& null !== $d['convention_signee_le'] && null !== $d['convention_fin_le']
		&& $d['convention_fin_le'] <= $d['convention_signee_le'] ) {
		$erreurs['convention_fin_le'] = 'La fin de la convention doit suivre sa signature.';
	}

	return $erreurs;
}

/* ---------- Écriture ---------- */

/**
 * Crée ($id = 0) ou modifie un IPES, tutelles comprises, en une transaction.
 * Les données sont normalisées puis validées ici : en cas d'erreur, le
 * WP_Error porte le tableau champ => message dans ses données.
 *
 * @return int|WP_Error Identifiant de l'IPES.
 */
function ueb_ipes_enregistrer( array $d, $id = 0 ) {
	global $wpdb;
	$id = (int) $id;
	if ( $id && ! ueb_ipes( $id ) ) {
		return new WP_Error( 'ueb_ipes_introuvable', 'Cet IPES n’existe pas.' );
	}
	$d       = ueb_ipes_normaliser( $d );
	$erreurs = ueb_ipes_valider( $d, $id );
	if ( $erreurs ) {
		return new WP_Error( 'ueb_ipes_invalide', 'Corrige les champs signalés.', $erreurs );
	}

	$ligne = array_intersect_key( $d, array_flip( array( 'sigle', 'nom_fr', 'nom_en', 'ville', 'telephone', 'email', 'convention_ref', 'convention_signee_le', 'convention_fin_le' ) ) );
	$ligne['modifie_par'] = get_current_user_id() ?: null;

	$wpdb->query( 'START TRANSACTION' );
	$ok = $id ? $wpdb->update( 'ueb_insc_ipes', $ligne, array( 'id' => $id ) ) : $wpdb->insert( 'ueb_insc_ipes', $ligne );
	if ( false === $ok ) {
		$wpdb->query( 'ROLLBACK' );
		error_log( '[inscriptions-ueb] Enregistrement de l’IPES impossible : ' . $wpdb->last_error );
		/* Deux créations simultanées du même sigle : la clé unique tranche. */
		return new WP_Error( 'ueb_ipes_invalide', 'Corrige les champs signalés.', array( 'sigle' => 'Un autre IPES porte déjà ce sigle.' ) );
	}
	$id = $id ?: (int) $wpdb->insert_id;
	if ( ! ueb_ipes_definir_tutelles( $id, $d['tutelles'] ) ) {
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error( 'ueb_ipes_tutelles', 'Les tutelles n’ont pas pu être enregistrées. Réessaie dans un instant.' );
	}
	$wpdb->query( 'COMMIT' );
	return $id;
}

/**
 * Remplace les tutelles d'un IPES. Une tutelle déjà présente garde sa date
 * « depuis_le » ; seules les nouvelles sont ajoutées, les retirées supprimées.
 * Les sigles doivent avoir été validés (ueb_ipes_valider).
 */
function ueb_ipes_definir_tutelles( $ipes_id, array $sigles ) {
	global $wpdb;
	$sigles = array_values( array_unique( array_map( 'strtoupper', $sigles ) ) );
	if ( $sigles ) {
		$marques   = implode( ',', array_fill( 0, count( $sigles ), '%s' ) );
		$supprimer = $wpdb->prepare( "DELETE FROM ueb_insc_ipes_tutelles WHERE ipes_id = %d AND etablissement NOT IN ($marques)", array_merge( array( $ipes_id ), $sigles ) );
	} else {
		$supprimer = $wpdb->prepare( 'DELETE FROM ueb_insc_ipes_tutelles WHERE ipes_id = %d', $ipes_id );
	}
	if ( false === $wpdb->query( $supprimer ) ) {
		return false;
	}
	foreach ( $sigles as $sigle ) {
		if ( false === $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ueb_insc_ipes_tutelles ( ipes_id, etablissement ) VALUES ( %d, %s )', $ipes_id, $sigle ) ) ) {
			return false;
		}
	}
	return true;
}

/** Active ou désactive un IPES. Vrai si l'IPES existe. */
function ueb_ipes_changer_etat( $id, $actif ) {
	global $wpdb;
	if ( ! ueb_ipes( $id ) ) {
		return false;
	}
	return false !== $wpdb->update(
		'ueb_insc_ipes',
		array( 'actif' => $actif ? 1 : 0, 'modifie_par' => get_current_user_id() ?: null ),
		array( 'id' => (int) $id )
	);
}
