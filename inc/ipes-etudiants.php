<?php
/**
 * Étudiants d'un IPES et leurs versements de pension, saisis par
 * l'administrateur de l'IPES dans son espace.
 *
 * Isolation : chaque fonction reçoit l'identifiant de l'IPES et filtre
 * dessus. Un étudiant ou un versement d'un autre IPES se comporte comme s'il
 * n'existait pas : l'espace d'un IPES ne peut ni le lire ni le modifier.
 *
 * Un étudiant est saisi une fois par année académique (sa filière et son
 * niveau changent), toujours avec le même matricule. Un versement placé dans
 * un bordereau n'est plus modifiable : il a été reversé, ou est en passe de l'être.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

const UEB_IPES_MATRICULE_REGEX = '/^[A-Z0-9][A-Z0-9\/.-]{2,29}$/';
const UEB_IPES_MONTANT_MAX     = 10000000; /* garde-fou contre une faute de frappe (un zéro de trop) */

/* ---------- Étudiants : lecture ---------- */

/** Un étudiant de CET IPES, ou null (inexistant ou d'un autre IPES). */
function ueb_ipes_etudiant( $ipes_id, $id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare(
		'SELECT * FROM ueb_insc_ipes_etudiants WHERE id = %d AND ipes_id = %d',
		$id, $ipes_id
	) );
}

/**
 * Étudiants d'un IPES pour une année, avec le libellé de leur filière et le
 * total de leurs versements, triés par nom.
 *
 * @param array $filtres annee (défaut : l'année en cours), recherche (matricule,
 *                       nom ou prénom), filiere_id.
 */
function ueb_ipes_etudiants( $ipes_id, array $filtres = array() ) {
	global $wpdb;
	$annee  = (string) ( $filtres['annee'] ?? ueb_annee_academique()['code'] );
	$where  = array( 'e.ipes_id = %d', 'e.annee_academique = %s' );
	$params = array( $ipes_id, $annee );

	$recherche = trim( (string) ( $filtres['recherche'] ?? '' ) );
	if ( '' !== $recherche ) {
		$motif    = '%' . $wpdb->esc_like( $recherche ) . '%';
		$where[]  = '( e.matricule LIKE %s OR e.nom LIKE %s OR e.prenom LIKE %s )';
		$params[] = $motif;
		$params[] = $motif;
		$params[] = $motif;
	}
	if ( ! empty( $filtres['filiere_id'] ) ) {
		$where[]  = 'e.filiere_id = %d';
		$params[] = (int) $filtres['filiere_id'];
	}

	return $wpdb->get_results( $wpdb->prepare(
		'SELECT e.*, f.libelle AS filiere,
			COALESCE( SUM( p.montant ), 0 ) AS total_paye,
			COUNT( p.id ) AS nb_versements
		FROM ueb_insc_ipes_etudiants e
		LEFT JOIN ueb_insc_ipes_filieres f ON f.id = e.filiere_id
		LEFT JOIN ueb_insc_ipes_paiements p ON p.etudiant_id = e.id AND p.ipes_id = e.ipes_id
		WHERE ' . implode( ' AND ', $where ) . '
		GROUP BY e.id
		ORDER BY e.nom, e.prenom',
		$params
	) );
}

/* ---------- Étudiants : validation ---------- */

/**
 * Saisie mise en forme : matricule en majuscules sans espaces, noms rognés,
 * niveau en majuscules, téléphone sans indicatif. Un téléphone mal formé est
 * conservé tel quel pour que la validation le signale.
 */
function ueb_ipes_etudiant_normaliser( array $d ) {
	$texte     = static fn( $v ) => trim( preg_replace( '/\s+/u', ' ', (string) $v ) );
	$telephone = trim( (string) ( $d['telephone'] ?? '' ) );
	return array(
		'matricule'  => strtoupper( preg_replace( '/\s+/', '', (string) ( $d['matricule'] ?? '' ) ) ),
		'nom'        => $texte( $d['nom'] ?? '' ),
		'prenom'     => $texte( $d['prenom'] ?? '' ),
		'filiere_id' => (int) ( $d['filiere_id'] ?? 0 ),
		'niveau'     => strtoupper( trim( (string) ( $d['niveau'] ?? '' ) ) ),
		'telephone'  => '' === $telephone ? '' : ( ueb_normaliser_telephone( $telephone ) ?? $telephone ),
	);
}

/**
 * Erreurs champ => message d'un étudiant normalisé (tableau vide s'il convient).
 *
 * @param object|null $existant Étudiant modifié : sa filière reste acceptée même
 *                              si elle a été retirée depuis ; son année sert au
 *                              contrôle du matricule.
 */
function ueb_ipes_etudiant_valider( $ipes_id, array $d, $existant = null ) {
	global $wpdb;
	$erreurs = array();
	$annee   = $existant ? $existant->annee_academique : ueb_annee_academique()['code'];

	if ( ! preg_match( UEB_IPES_MATRICULE_REGEX, $d['matricule'] ) ) {
		$erreurs['matricule'] = 'Le matricule compte 3 à 30 caractères : lettres, chiffres, « / », « . » ou « - ».';
	} elseif ( $wpdb->get_var( $wpdb->prepare(
		'SELECT id FROM ueb_insc_ipes_etudiants WHERE ipes_id = %d AND annee_academique = %s AND matricule = %s AND id <> %d',
		$ipes_id, $annee, $d['matricule'], $existant ? $existant->id : 0
	) ) ) {
		$erreurs['matricule'] = 'Un étudiant porte déjà ce matricule cette année.';
	}

	foreach ( array( 'nom' => 100, 'prenom' => 150 ) as $champ => $max ) {
		if ( '' === $d[ $champ ] ) {
			$erreurs[ $champ ] = 'nom' === $champ ? 'Saisis le nom.' : 'Saisis le prénom.';
		} elseif ( mb_strlen( $d[ $champ ] ) > $max ) {
			$erreurs[ $champ ] = "$max caractères au maximum.";
		}
	}

	$filiere = $d['filiere_id'] ? ueb_ipes_filiere( $d['filiere_id'] ) : null;
	$garde   = $existant && (int) $existant->filiere_id === $d['filiere_id'];
	if ( ! $filiere || (int) $filiere->ipes_id !== (int) $ipes_id || ( ! (int) $filiere->actif && ! $garde ) ) {
		$erreurs['filiere_id'] = 'Choisis une filière de l’IPES.';
	}

	if ( ! isset( UEB_NIVEAUX_INSCRIPTION[ $d['niveau'] ] ) ) {
		$erreurs['niveau'] = 'Choisis le niveau.';
	}
	if ( '' !== $d['telephone'] && ! preg_match( UEB_REGEX_TELEPHONE, $d['telephone'] ) ) {
		$erreurs['telephone'] = 'Numéro mobile à 9 chiffres commençant par 6.';
	}
	return $erreurs;
}

/* ---------- Étudiants : écriture ---------- */

/**
 * Ajoute ($id = 0, pour l'année en cours) ou modifie un étudiant de l'IPES.
 * En cas d'erreur, le WP_Error porte le tableau champ => message.
 *
 * @return int|WP_Error
 */
function ueb_ipes_etudiant_enregistrer( $ipes_id, array $d, $id = 0 ) {
	global $wpdb;
	$existant = null;
	if ( $id ) {
		$existant = ueb_ipes_etudiant( $ipes_id, $id );
		if ( ! $existant ) {
			return new WP_Error( 'ueb_ipes_etudiant', 'Cet étudiant n’existe pas.' );
		}
	}
	$d       = ueb_ipes_etudiant_normaliser( $d );
	$erreurs = ueb_ipes_etudiant_valider( $ipes_id, $d, $existant );
	if ( $erreurs ) {
		return new WP_Error( 'ueb_ipes_etudiant', 'Corrige les champs signalés.', $erreurs );
	}
	if ( $existant ) {
		$ok = $wpdb->update( 'ueb_insc_ipes_etudiants', $d, array( 'id' => (int) $id, 'ipes_id' => (int) $ipes_id ) );
	} else {
		$ok = $wpdb->insert( 'ueb_insc_ipes_etudiants', $d + array(
			'ipes_id'          => (int) $ipes_id,
			'annee_academique' => ueb_annee_academique()['code'],
			'saisi_par'        => get_current_user_id() ?: null,
		) );
	}
	if ( false === $ok ) {
		/* Deux saisies simultanées du même matricule : la clé unique tranche. */
		return new WP_Error( 'ueb_ipes_etudiant', 'Corrige les champs signalés.', array( 'matricule' => 'Un étudiant porte déjà ce matricule cette année.' ) );
	}
	return $existant ? (int) $id : (int) $wpdb->insert_id;
}

/** Supprime un étudiant saisi par erreur, seulement s'il n'a aucun versement. */
function ueb_ipes_etudiant_supprimer( $ipes_id, $id ) {
	global $wpdb;
	if ( ! ueb_ipes_etudiant( $ipes_id, $id ) ) {
		return new WP_Error( 'ueb_ipes_etudiant', 'Cet étudiant n’existe pas.' );
	}
	if ( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ueb_insc_ipes_paiements WHERE etudiant_id = %d', $id ) ) ) {
		return new WP_Error( 'ueb_ipes_etudiant', 'Cet étudiant a des versements : supprime-les d’abord.' );
	}
	$wpdb->delete( 'ueb_insc_ipes_etudiants', array( 'id' => (int) $id, 'ipes_id' => (int) $ipes_id ) );
	return true;
}

/* ---------- Versements ---------- */

/** Un versement de CET IPES, ou null. */
function ueb_ipes_paiement( $ipes_id, $id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare(
		'SELECT * FROM ueb_insc_ipes_paiements WHERE id = %d AND ipes_id = %d',
		$id, $ipes_id
	) );
}

/** Versements d'un étudiant de l'IPES, du plus récent au plus ancien. */
function ueb_ipes_paiements_etudiant( $ipes_id, $etudiant_id ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare(
		'SELECT * FROM ueb_insc_ipes_paiements WHERE ipes_id = %d AND etudiant_id = %d ORDER BY date_paiement DESC, id DESC',
		$ipes_id, $etudiant_id
	) );
}

/** « 25 000 », « 25000 FCFA » → 25000 ; 0 si la saisie ne contient aucun chiffre. */
function ueb_ipes_montant( $saisie ) {
	return (int) preg_replace( '/\D+/', '', (string) $saisie );
}

/** Erreurs champ => message d'un versement (montant entier, date passée ou du jour). */
function ueb_ipes_paiement_valider( $montant, $date ) {
	$erreurs = array();
	if ( $montant <= 0 ) {
		$erreurs['montant'] = 'Saisis le montant versé, en FCFA.';
	} elseif ( $montant > UEB_IPES_MONTANT_MAX ) {
		$erreurs['montant'] = 'Montant trop élevé : vérifie le nombre de zéros.';
	}
	if ( ! ueb_ipes_date_valide( (string) $date ) ) {
		$erreurs['date_paiement'] = 'Date invalide.';
	} elseif ( $date > current_time( 'Y-m-d' ) ) {
		$erreurs['date_paiement'] = 'La date du versement ne peut pas être dans le futur.';
	}
	return $erreurs;
}

/**
 * Ajoute ($id = 0) ou modifie un versement d'un étudiant de l'IPES. Un
 * versement déjà placé dans un bordereau n'est plus modifiable.
 *
 * @return int|WP_Error
 */
function ueb_ipes_paiement_enregistrer( $ipes_id, $etudiant_id, array $d, $id = 0 ) {
	global $wpdb;
	if ( ! ueb_ipes_etudiant( $ipes_id, $etudiant_id ) ) {
		return new WP_Error( 'ueb_ipes_paiement', 'Cet étudiant n’existe pas.' );
	}
	if ( $id ) {
		$existant = ueb_ipes_paiement( $ipes_id, $id );
		if ( ! $existant || (int) $existant->etudiant_id !== (int) $etudiant_id ) {
			return new WP_Error( 'ueb_ipes_paiement', 'Ce versement n’existe pas.' );
		}
		if ( $existant->bordereau_id ) {
			return new WP_Error( 'ueb_ipes_paiement', 'Ce versement figure dans un bordereau : il n’est plus modifiable.' );
		}
	}
	$montant = ueb_ipes_montant( $d['montant'] ?? '' );
	$date    = trim( (string) ( $d['date_paiement'] ?? '' ) );
	$erreurs = ueb_ipes_paiement_valider( $montant, $date );
	if ( $erreurs ) {
		return new WP_Error( 'ueb_ipes_paiement', 'Corrige les champs signalés.', $erreurs );
	}
	$ligne = array( 'montant' => $montant, 'date_paiement' => $date );
	if ( $id ) {
		/* bordereau_id IS NULL dans la condition : un bordereau formé entre-temps l'emporte. */
		$ok = $wpdb->query( $wpdb->prepare(
			'UPDATE ueb_insc_ipes_paiements SET montant = %d, date_paiement = %s WHERE id = %d AND ipes_id = %d AND bordereau_id IS NULL',
			$montant, $date, $id, $ipes_id
		) );
		return false === $ok ? new WP_Error( 'ueb_ipes_paiement', 'Le versement n’a pas pu être modifié. Réessaie dans un instant.' ) : (int) $id;
	}
	$ok = $wpdb->insert( 'ueb_insc_ipes_paiements', $ligne + array(
		'ipes_id'     => (int) $ipes_id,
		'etudiant_id' => (int) $etudiant_id,
		'saisi_par'   => get_current_user_id() ?: null,
	) );
	return $ok ? (int) $wpdb->insert_id : new WP_Error( 'ueb_ipes_paiement', 'Le versement n’a pas pu être enregistré. Réessaie dans un instant.' );
}

/** Supprime un versement saisi par erreur, s'il n'est dans aucun bordereau. */
function ueb_ipes_paiement_supprimer( $ipes_id, $id ) {
	global $wpdb;
	$paiement = ueb_ipes_paiement( $ipes_id, $id );
	if ( ! $paiement ) {
		return new WP_Error( 'ueb_ipes_paiement', 'Ce versement n’existe pas.' );
	}
	if ( $paiement->bordereau_id ) {
		return new WP_Error( 'ueb_ipes_paiement', 'Ce versement figure dans un bordereau : il ne peut pas être supprimé.' );
	}
	$wpdb->query( $wpdb->prepare(
		'DELETE FROM ueb_insc_ipes_paiements WHERE id = %d AND ipes_id = %d AND bordereau_id IS NULL',
		$id, $ipes_id
	) );
	return true;
}

/* ---------- Totaux ---------- */

/**
 * Chiffres d'un IPES pour une année : nombre d'étudiants, total encaissé,
 * et part encore libre (dans aucun bordereau).
 *
 * @return array{etudiants:int, encaisse:int, libre:int}
 */
function ueb_ipes_totaux( $ipes_id, $annee = null ) {
	global $wpdb;
	$annee = $annee ?? ueb_annee_academique()['code'];
	$ligne = $wpdb->get_row( $wpdb->prepare(
		'SELECT
			( SELECT COUNT(*) FROM ueb_insc_ipes_etudiants WHERE ipes_id = %d AND annee_academique = %s ) AS etudiants,
			COALESCE( SUM( p.montant ), 0 ) AS encaisse,
			COALESCE( SUM( CASE WHEN p.bordereau_id IS NULL THEN p.montant ELSE 0 END ), 0 ) AS libre
		FROM ueb_insc_ipes_paiements p
		JOIN ueb_insc_ipes_etudiants e ON e.id = p.etudiant_id AND e.ipes_id = p.ipes_id
		WHERE p.ipes_id = %d AND e.annee_academique = %s',
		$ipes_id, $annee, $ipes_id, $annee
	) );
	return array(
		'etudiants' => (int) ( $ligne->etudiants ?? 0 ),
		'encaisse'  => (int) ( $ligne->encaisse ?? 0 ),
		'libre'     => (int) ( $ligne->libre ?? 0 ),
	);
}
