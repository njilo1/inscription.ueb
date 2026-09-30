<?php
/**
 * Bordereaux de reversement d'un IPES à l'une de ses tutelles.
 *
 * Un bordereau reverse des ÉTUDIANTS, pas des montants : chacun vaut la somme
 * fixe UEB_IPES_REVERSEMENT_PAR_ETUDIANT, et seuls les étudiants dont la
 * filière dépend de la tutelle du bordereau peuvent y figurer. Un étudiant
 * est dans un bordereau au plus.
 *
 * Cycle : brouillon (l'IPES coche des étudiants) → envoyé (montant unitaire et
 * total figés, plus rien ne bouge) → vérifié ou rejeté par l'administration
 * de l'UEb ou par la tutelle. Un bordereau rejeté garde son motif et
 * redevient modifiable ; renvoyé, il garde son numéro.
 *
 * Numérotation : le numéro officiel (BRD-SIANTOU-2627-0001) n'est attribué
 * qu'au premier envoi. Un brouillon abandonné ne laisse pas de trou dans la
 * série ; d'ici là, il porte un numéro provisoire (BROUILLON-{id}).
 *
 * Isolation : comme pour les étudiants, les fonctions de l'espace IPES
 * reçoivent l'identifiant de l'IPES et filtrent dessus.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

const UEB_IPES_STATUTS_BORDEREAU = array(
	'brouillon' => 'Brouillon',
	'envoye'    => 'Envoyé',
	'verifie'   => 'Vérifié',
	'rejete'    => 'Rejeté',
);
/* Statuts dans lesquels l'IPES peut encore modifier son bordereau. */
const UEB_IPES_BORDEREAU_MODIFIABLE = array( 'brouillon', 'rejete' );

/** Badge d'un statut, aux couleurs des statuts de quitus (à faire, en cours, vérifié, rejeté). */
function ueb_ipes_badge_bordereau( $statut ) {
	$classe = array( 'brouillon' => 'genere', 'envoye' => 'recu_envoye', 'verifie' => 'verifie', 'rejete' => 'rejete' )[ $statut ] ?? 'genere';
	return sprintf( '<span class="badge badge--%s"><i aria-hidden="true"></i>%s</span>', esc_attr( $classe ), esc_html( UEB_IPES_STATUTS_BORDEREAU[ $statut ] ?? $statut ) );
}

/**
 * Montant d'un bordereau : figé à l'envoi ; tant qu'il est modifiable,
 * nombre d'étudiants cochés × montant par étudiant en vigueur.
 *
 * @param object $b Bordereau avec « nb_etudiants » (ueb_ipes_bordereaux*).
 */
function ueb_ipes_montant_bordereau( $b ) {
	return in_array( $b->statut, UEB_IPES_BORDEREAU_MODIFIABLE, true )
		? (int) ( $b->nb_etudiants ?? 0 ) * UEB_IPES_REVERSEMENT_PAR_ETUDIANT
		: (int) $b->total;
}

/* ---------- Lecture ---------- */

/** Un bordereau de CET IPES, ou null. */
function ueb_ipes_bordereau( $ipes_id, $id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare(
		'SELECT * FROM ueb_insc_ipes_bordereaux WHERE id = %d AND ipes_id = %d',
		$id, $ipes_id
	) );
}

/**
 * Bordereaux d'un IPES, les plus récents d'abord, avec leur nombre d'étudiants.
 *
 * @param array $filtres annee (défaut : l'année en cours), statut.
 */
function ueb_ipes_bordereaux( $ipes_id, array $filtres = array() ) {
	global $wpdb;
	$where  = array( 'b.ipes_id = %d', 'b.annee_academique = %s' );
	$params = array( $ipes_id, (string) ( $filtres['annee'] ?? ueb_annee_academique()['code'] ) );
	if ( isset( UEB_IPES_STATUTS_BORDEREAU[ $filtres['statut'] ?? '' ] ) ) {
		$where[]  = 'b.statut = %s';
		$params[] = $filtres['statut'];
	}
	return $wpdb->get_results( $wpdb->prepare(
		'SELECT b.*, COUNT( e.id ) AS nb_etudiants
		FROM ueb_insc_ipes_bordereaux b
		LEFT JOIN ueb_insc_ipes_etudiants e ON e.bordereau_id = b.id AND e.ipes_id = b.ipes_id
		WHERE ' . implode( ' AND ', $where ) . '
		GROUP BY b.id
		ORDER BY b.date_creation DESC, b.id DESC',
		$params
	) );
}

/** Étudiants d'un bordereau, avec leur filière, triés par nom. */
function ueb_ipes_bordereau_etudiants( $ipes_id, $bordereau_id ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare(
		'SELECT e.*, f.libelle AS filiere
		FROM ueb_insc_ipes_etudiants e
		LEFT JOIN ueb_insc_ipes_filieres f ON f.id = e.filiere_id
		WHERE e.ipes_id = %d AND e.bordereau_id = %d
		ORDER BY e.nom, e.prenom',
		$ipes_id, $bordereau_id
	) );
}

/**
 * Étudiants encore à reverser (dans aucun bordereau) d'une année, dont la
 * filière dépend de cette tutelle, triés par nom.
 */
function ueb_ipes_etudiants_libres( $ipes_id, $etablissement, $annee = null ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare(
		'SELECT e.*, f.libelle AS filiere
		FROM ueb_insc_ipes_etudiants e
		JOIN ueb_insc_ipes_filieres f ON f.id = e.filiere_id AND f.ipes_id = e.ipes_id
		WHERE e.ipes_id = %d AND e.bordereau_id IS NULL AND e.annee_academique = %s AND f.etablissement = %s
		ORDER BY e.nom, e.prenom',
		$ipes_id, $annee ?? ueb_annee_academique()['code'], strtoupper( (string) $etablissement )
	) );
}

/* ---------- Écriture (espace de l'IPES) ---------- */

/**
 * Nouveau brouillon pour l'une des tutelles de l'IPES, sur l'année en cours.
 * Sans tutelle précisée, celle de l'IPES est prise s'il n'en a qu'une.
 *
 * @return int|WP_Error
 */
function ueb_ipes_bordereau_creer( $ipes_id, $etablissement = '' ) {
	global $wpdb;
	$ipes = ueb_ipes( $ipes_id );
	if ( ! $ipes || ! (int) $ipes->actif ) {
		return new WP_Error( 'ueb_ipes_bordereau', 'Cet IPES est désactivé.' );
	}
	$etablissement = strtoupper( (string) $etablissement );
	if ( '' === $etablissement && 1 === count( $ipes->tutelles ) ) {
		$etablissement = $ipes->tutelles[0];
	}
	if ( ! in_array( $etablissement, $ipes->tutelles, true ) ) {
		return new WP_Error( 'ueb_ipes_bordereau', 'Choisis l’établissement de tutelle destinataire.' );
	}
	$ok = $wpdb->insert( 'ueb_insc_ipes_bordereaux', array(
		'numero'           => 'BROUILLON-' . bin2hex( random_bytes( 6 ) ), // remplacé juste après par BROUILLON-{id}
		'ipes_id'          => (int) $ipes_id,
		'etablissement'    => $etablissement,
		'annee_academique' => ueb_annee_academique()['code'],
		'cree_par'         => get_current_user_id() ?: null,
	) );
	if ( ! $ok ) {
		return new WP_Error( 'ueb_ipes_bordereau', 'Le bordereau n’a pas pu être créé. Réessaie dans un instant.' );
	}
	$id = (int) $wpdb->insert_id;
	$wpdb->update( 'ueb_insc_ipes_bordereaux', array( 'numero' => 'BROUILLON-' . $id ), array( 'id' => $id ) );
	return $id;
}

/**
 * Remplace les étudiants d'un bordereau modifiable par ceux cochés. Seuls des
 * étudiants libres de l'IPES, de l'année du bordereau et d'une filière de sa
 * tutelle sont acceptés : si l'un d'eux ne l'est plus (pris entre-temps),
 * rien n'est changé.
 *
 * @return true|WP_Error
 */
function ueb_ipes_bordereau_definir_etudiants( $ipes_id, $bordereau_id, array $etudiant_ids ) {
	global $wpdb;
	$ids = array_values( array_unique( array_filter( array_map( 'intval', $etudiant_ids ) ) ) );

	$wpdb->query( 'START TRANSACTION' );
	/* Verrou sur le bordereau : un envoi simultané attend la fin de ce changement. */
	$bordereau = $wpdb->get_row( $wpdb->prepare(
		'SELECT * FROM ueb_insc_ipes_bordereaux WHERE id = %d AND ipes_id = %d FOR UPDATE',
		$bordereau_id, $ipes_id
	) );
	if ( ! $bordereau ) {
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error( 'ueb_ipes_bordereau', 'Ce bordereau n’existe pas.' );
	}
	if ( ! in_array( $bordereau->statut, UEB_IPES_BORDEREAU_MODIFIABLE, true ) ) {
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error( 'ueb_ipes_bordereau', 'Ce bordereau a été envoyé : il n’est plus modifiable.' );
	}
	$wpdb->query( $wpdb->prepare(
		'UPDATE ueb_insc_ipes_etudiants SET bordereau_id = NULL WHERE ipes_id = %d AND bordereau_id = %d',
		$ipes_id, $bordereau_id
	) );
	if ( $ids ) {
		$marques = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$pris    = $wpdb->query( $wpdb->prepare(
			"UPDATE ueb_insc_ipes_etudiants e
			JOIN ueb_insc_ipes_filieres f ON f.id = e.filiere_id AND f.ipes_id = e.ipes_id
			SET e.bordereau_id = %d
			WHERE e.ipes_id = %d AND e.bordereau_id IS NULL AND e.annee_academique = %s AND f.etablissement = %s AND e.id IN ($marques)",
			array_merge( array( $bordereau_id, $ipes_id, $bordereau->annee_academique, $bordereau->etablissement ), $ids )
		) );
		if ( count( $ids ) !== (int) $pris ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'ueb_ipes_bordereau', 'Certains étudiants ne peuvent plus être reversés dans ce bordereau : actualise la page et recommence.' );
		}
	}
	$wpdb->query( 'COMMIT' );
	return true;
}

/** Numéro officiel suivant d'un IPES : BRD-SIANTOU-2627-0001. */
function ueb_ipes_prochain_numero_bordereau( $ipes, $annee_code ) {
	global $wpdb;
	$ok = $wpdb->query( $wpdb->prepare(
		'INSERT INTO ueb_insc_ipes_sequence (ipes_id, annee_academique, dernier) VALUES (%d, %s, LAST_INSERT_ID(1))
		 ON DUPLICATE KEY UPDATE dernier = LAST_INSERT_ID(dernier + 1)',
		$ipes->id, $annee_code
	) );
	if ( false === $ok ) {
		return null;
	}
	$annee_courte = substr( $annee_code, 2, 2 ) . substr( $annee_code, 7, 2 );
	return sprintf( 'BRD-%s-%s-%04d', $ipes->sigle, $annee_courte, (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' ) );
}

/**
 * Envoie un bordereau modifiable : montant par étudiant et total figés,
 * numéro officiel au premier envoi. Refusé sans étudiant ou sans reçu bancaire.
 *
 * @return true|WP_Error
 */
function ueb_ipes_bordereau_envoyer( $ipes_id, $id ) {
	global $wpdb;
	$wpdb->query( 'START TRANSACTION' );
	/* Verrou : les étudiants ne bougent plus pendant qu'on les compte. */
	$bordereau = $wpdb->get_row( $wpdb->prepare(
		'SELECT * FROM ueb_insc_ipes_bordereaux WHERE id = %d AND ipes_id = %d FOR UPDATE',
		$id, $ipes_id
	) );
	if ( ! $bordereau ) {
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error( 'ueb_ipes_bordereau', 'Ce bordereau n’existe pas.' );
	}
	if ( ! in_array( $bordereau->statut, UEB_IPES_BORDEREAU_MODIFIABLE, true ) ) {
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error( 'ueb_ipes_bordereau', 'Ce bordereau a déjà été envoyé.' );
	}
	$nombre = (int) $wpdb->get_var( $wpdb->prepare(
		'SELECT COUNT(*) FROM ueb_insc_ipes_etudiants WHERE ipes_id = %d AND bordereau_id = %d',
		$ipes_id, $id
	) );
	if ( ! $nombre ) {
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error( 'ueb_ipes_bordereau', 'Coche au moins un étudiant avant d’envoyer le bordereau.' );
	}
	if ( ! ueb_ipes_nb_recus( $ipes_id, $id ) ) {
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error( 'ueb_ipes_bordereau', 'Joins au moins un reçu bancaire du virement avant d’envoyer le bordereau.' );
	}
	$numero = $bordereau->numero;
	if ( str_starts_with( $numero, 'BROUILLON-' ) ) {
		$numero = ueb_ipes_prochain_numero_bordereau( ueb_ipes( $ipes_id ), $bordereau->annee_academique );
		if ( ! $numero ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'ueb_ipes_bordereau', 'Le bordereau n’a pas pu être numéroté. Réessaie dans un instant.' );
		}
	}
	$unitaire = UEB_IPES_REVERSEMENT_PAR_ETUDIANT;
	$ok       = $wpdb->query( $wpdb->prepare(
		"UPDATE ueb_insc_ipes_bordereaux
		SET statut = 'envoye', montant_unitaire = %d, total = %d, numero = %s, motif_rejet = NULL, date_envoi = %s, verifie_par = NULL, date_verification = NULL
		WHERE id = %d AND ipes_id = %d AND statut IN ('brouillon','rejete')",
		$unitaire, $nombre * $unitaire, $numero, current_time( 'mysql' ), $id, $ipes_id
	) );
	if ( 1 !== (int) $ok ) {
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error( 'ueb_ipes_bordereau', 'Ce bordereau a déjà été envoyé.' );
	}
	$wpdb->query( 'COMMIT' );
	return true;
}

/** Supprime un brouillon jamais envoyé ; ses étudiants redeviennent à reverser, ses reçus sont effacés. */
function ueb_ipes_bordereau_supprimer( $ipes_id, $id ) {
	global $wpdb;
	$bordereau = ueb_ipes_bordereau( $ipes_id, $id );
	if ( ! $bordereau ) {
		return new WP_Error( 'ueb_ipes_bordereau', 'Ce bordereau n’existe pas.' );
	}
	if ( 'brouillon' !== $bordereau->statut ) {
		return new WP_Error( 'ueb_ipes_bordereau', 'Seul un brouillon jamais envoyé peut être supprimé.' );
	}
	$wpdb->query( 'START TRANSACTION' );
	$supprime = $wpdb->query( $wpdb->prepare( "DELETE FROM ueb_insc_ipes_bordereaux WHERE id = %d AND ipes_id = %d AND statut = 'brouillon'", $id, $ipes_id ) );
	if ( 1 !== (int) $supprime ) {
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error( 'ueb_ipes_bordereau', 'Seul un brouillon jamais envoyé peut être supprimé.' );
	}
	$wpdb->query( $wpdb->prepare( 'UPDATE ueb_insc_ipes_etudiants SET bordereau_id = NULL WHERE ipes_id = %d AND bordereau_id = %d', $ipes_id, $id ) );
	$recus = ueb_ipes_recus( $ipes_id, $id );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ueb_insc_ipes_recus WHERE ipes_id = %d AND bordereau_id = %d', $ipes_id, $id ) );
	$wpdb->query( 'COMMIT' );
	array_map( 'ueb_ipes_recu_effacer_fichier', $recus );
	return true;
}

/* ---------- Décision (administration de l'UEb ou tutelle) ---------- */

/**
 * Vérifie ($verifie = true) ou rejette, avec un motif, un bordereau envoyé.
 * Les droits du compte sont contrôlés par l'action qui appelle.
 *
 * @return true|WP_Error
 */
function ueb_ipes_bordereau_decider( $id, $verifie, $motif = '' ) {
	global $wpdb;
	$motif = trim( preg_replace( '/\s+/u', ' ', (string) $motif ) );
	if ( ! $verifie && ( mb_strlen( $motif ) < 5 || mb_strlen( $motif ) > 255 ) ) {
		return new WP_Error( 'ueb_ipes_bordereau', 'Explique en quelques mots pourquoi le bordereau est rejeté (5 à 255 caractères).' );
	}
	/* Condition sur le statut : une seule décision, même si deux arrivent ensemble. */
	$ok = $wpdb->update(
		'ueb_insc_ipes_bordereaux',
		array(
			'statut'            => $verifie ? 'verifie' : 'rejete',
			'motif_rejet'       => $verifie ? null : $motif,
			'verifie_par'       => get_current_user_id() ?: null,
			'date_verification' => current_time( 'mysql' ),
		),
		array( 'id' => (int) $id, 'statut' => 'envoye' )
	);
	return 1 === (int) $ok ? true : new WP_Error( 'ueb_ipes_bordereau', 'Seul un bordereau envoyé, en attente de vérification, peut recevoir une décision.' );
}

/* ---------- Jauge ---------- */

/**
 * Reversements de l'année, pour l'IPES entier et tutelle par tutelle.
 *
 * @return array{etudiants:int, libres:int, du:int, envoye:int, verifie:int, reste:int, par_tutelle:array}
 *         etudiants : étudiants de l'année ; libres : ceux qui ne sont dans
 *         aucun bordereau ; du : étudiants × montant par étudiant ; envoye :
 *         total des bordereaux envoyés ou vérifiés ; verifie : total vérifié ;
 *         reste : du − vérifié, jamais négatif. par_tutelle : sigle => les
 *         mêmes clés, pour chaque tutelle de l'IPES.
 */
function ueb_ipes_jauge( $ipes_id, $annee = null ) {
	global $wpdb;
	$annee = $annee ?? ueb_annee_academique()['code'];
	$ipes  = ueb_ipes( $ipes_id );
	$vide  = array( 'etudiants' => 0, 'libres' => 0, 'du' => 0, 'envoye' => 0, 'verifie' => 0, 'reste' => 0 );
	$par   = array_fill_keys( $ipes ? $ipes->tutelles : array(), $vide );

	$etudiants = $wpdb->get_results( $wpdb->prepare(
		'SELECT f.etablissement AS tutelle, COUNT(*) AS n, SUM( e.bordereau_id IS NULL ) AS libres
		FROM ueb_insc_ipes_etudiants e
		JOIN ueb_insc_ipes_filieres f ON f.id = e.filiere_id AND f.ipes_id = e.ipes_id
		WHERE e.ipes_id = %d AND e.annee_academique = %s
		GROUP BY f.etablissement',
		$ipes_id, $annee
	) );
	foreach ( $etudiants as $l ) {
		$par[ $l->tutelle ]              = $par[ $l->tutelle ] ?? $vide;
		$par[ $l->tutelle ]['etudiants'] = (int) $l->n;
		$par[ $l->tutelle ]['libres']    = (int) $l->libres;
		$par[ $l->tutelle ]['du']        = (int) $l->n * UEB_IPES_REVERSEMENT_PAR_ETUDIANT;
	}
	$reverse = $wpdb->get_results( $wpdb->prepare(
		"SELECT etablissement AS tutelle,
			COALESCE( SUM( CASE WHEN statut IN ('envoye','verifie') THEN total ELSE 0 END ), 0 ) AS envoye,
			COALESCE( SUM( CASE WHEN statut = 'verifie' THEN total ELSE 0 END ), 0 ) AS verifie
		FROM ueb_insc_ipes_bordereaux WHERE ipes_id = %d AND annee_academique = %s
		GROUP BY etablissement",
		$ipes_id, $annee
	) );
	foreach ( $reverse as $l ) {
		$par[ $l->tutelle ]            = $par[ $l->tutelle ] ?? $vide;
		$par[ $l->tutelle ]['envoye']  = (int) $l->envoye;
		$par[ $l->tutelle ]['verifie'] = (int) $l->verifie;
	}

	$total = $vide;
	foreach ( $par as $sigle => $t ) {
		$par[ $sigle ]['reste'] = max( 0, $t['du'] - $t['verifie'] );
		foreach ( array( 'etudiants', 'libres', 'du', 'envoye', 'verifie' ) as $cle ) {
			$total[ $cle ] += $t[ $cle ];
		}
	}
	$total['reste']       = max( 0, $total['du'] - $total['verifie'] );
	$total['par_tutelle'] = $par;
	return $total;
}

/* ---------- Côté administration de l'UEb ---------- */

/**
 * Bordereaux d'un IPES tels que l'UEb les voit : jamais les brouillons (le
 * travail en cours de l'IPES), toutes années, ceux à vérifier d'abord.
 */
function ueb_ipes_bordereaux_pour_ueb( $ipes_id ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare(
		"SELECT b.*, COUNT( e.id ) AS nb_etudiants
		FROM ueb_insc_ipes_bordereaux b
		LEFT JOIN ueb_insc_ipes_etudiants e ON e.bordereau_id = b.id AND e.ipes_id = b.ipes_id
		WHERE b.ipes_id = %d AND b.statut <> 'brouillon'
		GROUP BY b.id
		ORDER BY FIELD( b.statut, 'envoye', 'rejete', 'verifie' ), b.date_envoi DESC, b.id DESC",
		$ipes_id
	) );
}

/** Nombre de bordereaux en attente de vérification, par IPES : array( ipes_id => n ). */
function ueb_ipes_bordereaux_a_verifier() {
	global $wpdb;
	$compte = array();
	foreach ( $wpdb->get_results( "SELECT ipes_id, COUNT(*) AS n FROM ueb_insc_ipes_bordereaux WHERE statut = 'envoye' GROUP BY ipes_id" ) as $ligne ) {
		$compte[ (int) $ligne->ipes_id ] = (int) $ligne->n;
	}
	return $compte;
}

/**
 * Décision de l'administration sur un bordereau envoyé (fiche de l'IPES,
 * bloc Bordereaux) : « verifie », ou « rejete » avec un motif.
 */
function ueb_action_ipes_bordereau_decider() {
	ueb_exiger_admin();
	ueb_ipes_retour_bloc( 'bordereaux' );
	$ipes      = ueb_ipes_du_formulaire();
	$retour    = ueb_url_ipes( $ipes->id ) . '#bordereaux';
	$bordereau = ueb_ipes_bordereau( $ipes->id, (int) ( $_POST['bordereau_id'] ?? 0 ) );
	if ( ! $bordereau ) {
		ueb_flash( 'erreur', 'Ce bordereau n’appartient pas à cet IPES.' );
		ueb_rediriger( $retour );
	}
	$verifie  = 'verifie' === sanitize_key( wp_unslash( $_POST['decision'] ?? '' ) );
	$resultat = ueb_ipes_bordereau_decider( $bordereau->id, $verifie, sanitize_textarea_field( wp_unslash( $_POST['motif'] ?? '' ) ) );
	if ( is_wp_error( $resultat ) ) {
		ueb_flash( 'erreur', $resultat->get_error_message() );
	} else {
		ueb_flash( 'succes', $verifie
			? 'Bordereau ' . $bordereau->numero . ' vérifié : l’IPES le voit dans son espace.'
			: 'Bordereau ' . $bordereau->numero . ' rejeté : l’IPES voit le motif et peut le corriger.' );
	}
	ueb_rediriger( $retour );
}
