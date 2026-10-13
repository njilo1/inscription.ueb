<?php
/**
 * Recherche intelligente du back-office : dès la première lettre, une liste
 * de suggestions sous le champ de recherche, les noms, matricules et numéros
 * présents en base qui commencent par la saisie (au début d'un mot : « ab »
 * propose ABOMO Jean comme Marie ABENA).
 *
 * Trois façons d'alimenter un champ (assets/js/suggestions.js) :
 *   - la base (ueb_attr_suggestions()) : admin-ajax.php, action
 *     « ueb_suggestions », avec les droits et la portée de la page qui porte le
 *     champ (comptes étudiants, registre des reçus, Étudiants UEB) ;
 *   - une liste fournie par la page (ueb_attr_suggestions_liste()), déjà
 *     limitée à ce que le compte voit (personnel, IPES, filières, étudiants
 *     d'un IPES) ;
 *   - les lignes déjà affichées (attribut data-suggestions-lignes : paiements).
 * Lecture seule ; huit suggestions au plus. Choisir une suggestion met sa
 * valeur dans le champ (un matricule plutôt qu'un nom quand il existe : la
 * recherche de la page le retrouve sans ambiguïté) et lance la recherche.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

const UEB_SUGGESTIONS_MAX = 8;

/**
 * Attributs d'un champ alimenté par la base.
 *
 * @param string $source comptes, quitus ou etudiants.
 * @param array  $args   Contexte de la page (type de reçus, année), revérifié par le serveur.
 */
function ueb_attr_suggestions( $source, array $args = array() ) {
	$url = add_query_arg( array( 'action' => 'ueb_suggestions', 'source' => $source, '_ajax_nonce' => wp_create_nonce( 'ueb_suggestions' ) ) + $args, admin_url( 'admin-ajax.php' ) );
	return sprintf( ' data-suggestions-url="%s"', esc_url( $url ) );
}

/**
 * Attributs d'un champ alimenté par une liste de la page.
 *
 * @param array $liste array( libellé, détail, valeur ) ; valeur = libellé par défaut.
 */
function ueb_attr_suggestions_liste( array $liste ) {
	$propres = array();
	foreach ( $liste as $s ) {
		$l = trim( (string) $s[0] );
		if ( '' !== $l ) {
			$propres[ $l . '|' . ( $s[1] ?? '' ) ] = array( 'l' => $l, 'd' => (string) ( $s[1] ?? '' ), 'v' => (string) ( $s[2] ?? $l ) );
		}
	}
	return sprintf( ' data-suggestions-liste="%s"', esc_attr( wp_json_encode( array_values( $propres ) ) ) );
}

/**
 * Condition SQL « une de ces colonnes commence par la saisie, ou l'un de
 * leurs mots » (la collation de la base ignore casse et accents).
 *
 * @return array{0: string, 1: array} Condition et paramètres.
 */
function ueb_suggestions_condition( array $colonnes, $q ) {
	global $wpdb;
	$debut  = $wpdb->esc_like( $q ) . '%';
	$mot    = '% ' . $wpdb->esc_like( $q ) . '%';
	$parts  = array();
	$params = array();
	foreach ( $colonnes as $colonne ) {
		$parts[] = "$colonne LIKE %s OR $colonne LIKE %s";
		array_push( $params, $debut, $mot );
	}
	return array( '( ' . implode( ' OR ', $parts ) . ' )', $params );
}

/** Une suggestion : libellé (le nom), détail (matricule…), valeur mise dans le champ. */
function ueb_suggestion( $nom, $prenom, $matricule, $detail = '' ) {
	$libelle = trim( $nom . ' ' . $prenom );
	return array(
		'l' => $libelle ?: (string) $matricule,
		'd' => implode( ', ', array_filter( array( $libelle ? (string) $matricule : '', (string) $detail ) ) ),
		'v' => (string) ( $matricule ?: $libelle ),
	);
}

/** Comptes étudiants (espace de la cellule informatique) : nom, prénom, matricule, téléphone. */
function ueb_suggestions_comptes( $q ) {
	global $wpdb;
	if ( ! ( ( ueb_est_cellule() || ueb_est_admin_ueb() ) && ( ueb_peut( UEB_CAP_COMPTES ) || ueb_peut( 'ueb_reinit_mdp' ) ) ) ) {
		return null;
	}
	$etab     = ueb_etab_agent();
	$jointure = 'LEFT JOIN ueb_insc_quitus q ON q.compte_id = c.id' . ( $etab ? $wpdb->prepare( ' AND q.etablissement = %s', $etab ) : '' );
	list( $noms, $params ) = ueb_suggestions_condition( array( 'r.nom', 'r.prenom' ), $q );
	list( $ident, $p2 )    = ueb_suggestions_condition( array( 'c.matricule' ), ueb_normaliser_identifiant( $q ) );
	$tel    = preg_replace( '/\D/', '', $q );
	$where  = "( $ident OR EXISTS ( SELECT 1 FROM ueb_insc_quitus r WHERE r.compte_id = c.id AND $noms )" . ( strlen( $tel ) >= 2 ? ' OR c.telephone LIKE %s' : '' ) . ' )';
	$params = array_merge( $p2, $params, strlen( $tel ) >= 2 ? array( $wpdb->esc_like( $tel ) . '%' ) : array() );
	$lignes = $wpdb->get_results( $wpdb->prepare(
		"SELECT c.matricule, c.telephone, MAX(q.nom) AS nom, MAX(q.prenom) AS prenom
		   FROM ueb_insc_comptes c $jointure
		  WHERE $where
		  GROUP BY c.id" . ( $etab ? ' HAVING COUNT(q.id) > 0' : '' ) . '
		  ORDER BY nom IS NULL, nom, prenom
		  LIMIT ' . UEB_SUGGESTIONS_MAX,
		$params
	) ); // phpcs:ignore -- conditions préparées
	return array_map( static function ( $l ) {
		$s = ueb_suggestion( $l->nom, $l->prenom, $l->matricule, $l->telephone ? ueb_formater_telephone( $l->telephone ) : '' );
		$s['v'] = $l->matricule ?: ( $l->telephone ?: $s['l'] );
		return $s;
	}, (array) $lignes );
}

/** Registre des reçus (espace scolarité) : un dossier par étudiant, du type de reçus de l'onglet. */
function ueb_suggestions_quitus( $q, array $args ) {
	global $wpdb;
	if ( ! ueb_types_quitus_visibles() ) {
		return null;
	}
	/* Même portée que le registre : année, établissement de l'agent, type visible. */
	list( $portee, $params ) = ueb_gestion_portee_sql( array( 'annee' => ueb_annee_academique()['code'], 'type' => $args['type'] ) );
	list( $cond, $p2 )       = ueb_suggestions_condition( array( 'q.nom', 'q.prenom', 'q.identifiant', 'q.numero' ), $q );
	$lignes = $wpdb->get_results( $wpdb->prepare(
		"SELECT q.identifiant, MAX(q.nom) AS nom, MAX(q.prenom) AS prenom, q.etablissement
		   FROM ueb_insc_quitus q
		  WHERE $portee AND $cond
		  GROUP BY q.identifiant, q.etablissement
		  ORDER BY nom, prenom
		  LIMIT " . UEB_SUGGESTIONS_MAX,
		array_merge( $params, $p2 )
	) ); // phpcs:ignore -- conditions préparées
	return array_map( static fn( $l ) => ueb_suggestion( $l->nom, $l->prenom, $l->identifiant, ueb_etab_agent() ? '' : $l->etablissement ), (array) $lignes );
}

/** Étudiants UEB : les inscrits de l'année (quitus de droits) dans la portée du compte. */
function ueb_suggestions_etudiants( $q, array $args ) {
	global $wpdb;
	$etabs = ueb_etudiants_portee();
	if ( ! $etabs ) {
		return null;
	}
	$annee = in_array( $args['annee'], ueb_etudiants_annees(), true ) ? $args['annee'] : ueb_annee_academique()['code'];
	list( $cond, $params ) = ueb_suggestions_condition( array( 'q.nom', 'q.prenom', 'q.identifiant' ), $q );
	$lignes = $wpdb->get_results( $wpdb->prepare(
		"SELECT q.identifiant, MAX(q.nom) AS nom, MAX(q.prenom) AS prenom, MAX(q.etablissement) AS etablissement
		   FROM ueb_insc_quitus q
		  WHERE q.type = 'droits' AND q.annee_academique = %s
		    AND q.etablissement IN (" . implode( ',', array_fill( 0, count( $etabs ), '%s' ) ) . ")
		    AND $cond
		  GROUP BY q.compte_id, q.identifiant
		  ORDER BY nom, prenom
		  LIMIT " . UEB_SUGGESTIONS_MAX,
		array_merge( array( $annee ), $etabs, $params )
	) ); // phpcs:ignore -- conditions préparées
	return array_map( static fn( $l ) => ueb_suggestion( $l->nom, $l->prenom, $l->identifiant, 1 < count( $etabs ) ? $l->etablissement : '' ), (array) $lignes );
}

/* Point d'accès : comptes connectés seulement, jeton WordPress, droits de la source. */
add_action( 'wp_ajax_ueb_suggestions', function () {
	check_ajax_referer( 'ueb_suggestions' );
	$sources = array(
		'comptes'   => 'ueb_suggestions_comptes',
		'quitus'    => 'ueb_suggestions_quitus',
		'etudiants' => 'ueb_suggestions_etudiants',
	);
	$source = sanitize_key( wp_unslash( $_GET['source'] ?? '' ) );
	$q      = trim( mb_substr( sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) ), 0, 60 ) );
	if ( ! isset( $sources[ $source ] ) ) {
		wp_send_json_error( null, 400 );
	}
	if ( '' === $q ) {
		wp_send_json_success( array() );
	}
	$liste = call_user_func( $sources[ $source ], $q, array(
		'type'  => sanitize_key( wp_unslash( $_GET['type'] ?? '' ) ),
		'annee' => sanitize_text_field( wp_unslash( $_GET['annee'] ?? '' ) ),
	) );
	if ( null === $liste ) {
		wp_send_json_error( null, 403 );
	}
	wp_send_json_success( $liste );
} );
