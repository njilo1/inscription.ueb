<?php
/**
 * Étudiants d'un IPES, saisis par l'administrateur de l'IPES dans son espace.
 *
 * Chaque étudiant enregistré vaut un reversement fixe à la tutelle de sa
 * filière (UEB_IPES_REVERSEMENT_PAR_ETUDIANT) : l'IPES ne déclare plus de
 * versements de pension. L'étudiant est reversé quand il figure dans un
 * bordereau (colonne « bordereau_id ») ; un étudiant d'un bordereau envoyé ou
 * vérifié n'est plus modifiable.
 *
 * Isolation : chaque fonction reçoit l'identifiant de l'IPES et filtre
 * dessus. Un étudiant d'un autre IPES se comporte comme s'il n'existait pas :
 * l'espace d'un IPES ne peut ni le lire ni le modifier.
 *
 * Un étudiant est saisi une fois par année académique (sa filière et son
 * niveau changent), toujours avec le même matricule.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

const UEB_IPES_MATRICULE_REGEX = '/^[A-Z0-9][A-Z0-9\/.-]{2,29}$/';

/* ---------- Lecture ---------- */

/**
 * Un étudiant de CET IPES, ou null (inexistant ou d'un autre IPES), avec la
 * tutelle de sa filière et le statut de son bordereau.
 */
function ueb_ipes_etudiant( $ipes_id, $id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare(
		'SELECT e.*, f.etablissement AS tutelle, b.statut AS bordereau_statut
		FROM ueb_insc_ipes_etudiants e
		LEFT JOIN ueb_insc_ipes_filieres f ON f.id = e.filiere_id
		LEFT JOIN ueb_insc_ipes_bordereaux b ON b.id = e.bordereau_id AND b.ipes_id = e.ipes_id
		WHERE e.id = %d AND e.ipes_id = %d',
		$id, $ipes_id
	) );
}

/**
 * Étudiants d'un IPES pour une année, avec leur filière, sa tutelle et leur
 * bordereau (numéro et statut), triés par nom.
 *
 * @param array $filtres annee (défaut : l'année en cours), recherche (matricule,
 *                       nom ou prénom), filiere_id, tutelle (un sigle, ou une
 *                       liste de sigles : ce que voit une scolarité de tutelle).
 */
function ueb_ipes_etudiants( $ipes_id, array $filtres = array() ) {
	global $wpdb;
	$annee  = (string) ( $filtres['annee'] ?? ueb_exercice_consulte()['code'] );
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
	if ( isset( $filtres['tutelle'] ) && is_array( $filtres['tutelle'] ) ) {
		$sigles = array_values( array_map( 'strtoupper', $filtres['tutelle'] ) );
		if ( ! $sigles ) {
			return array();
		}
		$where[] = 'f.etablissement IN (' . implode( ',', array_fill( 0, count( $sigles ), '%s' ) ) . ')';
		$params  = array_merge( $params, $sigles );
	} elseif ( ! empty( $filtres['tutelle'] ) ) {
		$where[]  = 'f.etablissement = %s';
		$params[] = strtoupper( (string) $filtres['tutelle'] );
	}

	return $wpdb->get_results( $wpdb->prepare(
		'SELECT e.*, f.libelle AS filiere, f.etablissement AS tutelle,
			b.numero AS bordereau_numero, b.statut AS bordereau_statut
		FROM ueb_insc_ipes_etudiants e
		LEFT JOIN ueb_insc_ipes_filieres f ON f.id = e.filiere_id
		LEFT JOIN ueb_insc_ipes_bordereaux b ON b.id = e.bordereau_id AND b.ipes_id = e.ipes_id
		WHERE ' . implode( ' AND ', $where ) . '
		ORDER BY e.nom, e.prenom',
		$params
	) );
}

/** Vrai si l'étudiant figure dans un bordereau envoyé ou vérifié : il n'est plus modifiable. */
function ueb_ipes_etudiant_fige( $etudiant ) {
	return $etudiant && $etudiant->bordereau_id && ! in_array( $etudiant->bordereau_statut, UEB_IPES_BORDEREAU_MODIFIABLE, true );
}

/* ---------- Validation ---------- */

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
 *                              contrôle du matricule. S'il est dans un brouillon,
 *                              sa filière ne peut pas changer de tutelle.
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
	} elseif ( $existant && $existant->bordereau_id && $filiere->etablissement !== $existant->tutelle ) {
		/* Le bordereau est adressé à une seule tutelle : l'étudiant ne peut pas en sortir par sa filière. */
		$erreurs['filiere_id'] = sprintf( 'L’étudiant figure dans un bordereau pour la %s : choisis une filière de la %s, ou retire-le d’abord du bordereau.', $existant->tutelle, $existant->tutelle );
	}

	if ( ! isset( UEB_NIVEAUX_INSCRIPTION[ $d['niveau'] ] ) ) {
		$erreurs['niveau'] = 'Choisis le niveau.';
	}
	if ( '' !== $d['telephone'] && ! preg_match( UEB_REGEX_TELEPHONE, $d['telephone'] ) ) {
		$erreurs['telephone'] = 'Numéro mobile à 9 chiffres commençant par 6.';
	}
	return $erreurs;
}

/* ---------- Écriture ---------- */

/**
 * Ajoute ($id = 0, pour l'année en cours) ou modifie un étudiant de l'IPES.
 * En cas d'erreur, le WP_Error porte le tableau champ => message.
 *
 * Si la saisie porte « tutelle » (formulaire de l'espace : faculté choisie
 * avant la filière), la filière doit dépendre de cette faculté.
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
		if ( ueb_ipes_etudiant_fige( $existant ) ) {
			return new WP_Error( 'ueb_ipes_etudiant', 'Cet étudiant figure dans un bordereau envoyé : il n’est plus modifiable.' );
		}
	}
	$tutelle = array_key_exists( 'tutelle', $d ) ? strtoupper( trim( (string) $d['tutelle'] ) ) : null;
	$d       = ueb_ipes_etudiant_normaliser( $d );
	$erreurs = ueb_ipes_etudiant_valider( $ipes_id, $d, $existant );
	if ( null !== $tutelle ) {
		$ipes = ueb_ipes( $ipes_id );
		if ( ! $ipes || ! in_array( $tutelle, $ipes->tutelles, true ) ) {
			$erreurs['tutelle'] = 'Choisis la faculté de tutelle de l’étudiant.';
		} elseif ( ! isset( $erreurs['filiere_id'] ) && ueb_ipes_filiere( $d['filiere_id'] )->etablissement !== $tutelle ) {
			$erreurs['filiere_id'] = sprintf( 'Cette filière ne dépend pas de la %s : choisis une filière de cette faculté.', $tutelle );
		}
	}
	if ( $erreurs ) {
		return new WP_Error( 'ueb_ipes_etudiant', 'Corrige les champs signalés.', $erreurs );
	}
	if ( $existant ) {
		/* Condition sur le bordereau : un envoi arrivé entre-temps l'emporte. */
		$ok = $wpdb->query( $wpdb->prepare(
			"UPDATE ueb_insc_ipes_etudiants e
			LEFT JOIN ueb_insc_ipes_bordereaux b ON b.id = e.bordereau_id
			SET e.matricule = %s, e.nom = %s, e.prenom = %s, e.filiere_id = %d, e.niveau = %s, e.telephone = %s
			WHERE e.id = %d AND e.ipes_id = %d AND ( e.bordereau_id IS NULL OR b.statut IN ('brouillon','rejete') )",
			$d['matricule'], $d['nom'], $d['prenom'], $d['filiere_id'], $d['niveau'], $d['telephone'], $id, $ipes_id
		) );
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

/** Supprime un étudiant saisi par erreur, seulement s'il n'est dans aucun bordereau. */
function ueb_ipes_etudiant_supprimer( $ipes_id, $id ) {
	global $wpdb;
	$etudiant = ueb_ipes_etudiant( $ipes_id, $id );
	if ( ! $etudiant ) {
		return new WP_Error( 'ueb_ipes_etudiant', 'Cet étudiant n’existe pas.' );
	}
	if ( $etudiant->bordereau_id ) {
		return new WP_Error( 'ueb_ipes_etudiant', 'Cet étudiant figure dans un bordereau : il ne peut pas être supprimé.' );
	}
	$wpdb->query( $wpdb->prepare(
		'DELETE FROM ueb_insc_ipes_etudiants WHERE id = %d AND ipes_id = %d AND bordereau_id IS NULL',
		$id, $ipes_id
	) );
	return true;
}
