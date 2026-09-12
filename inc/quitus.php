<?php
/**
 * Quitus : lecture, validation du formulaire, numérotation, enregistrement.
 *
 * Cycle de vie : genere → recu_envoye (reçus bancaires envoyés) → verifie
 * (contrôle physique à la scolarité) ou rejete (motif communiqué, l'étudiant
 * renvoie ses reçus). Un quitus n'est modifiable que tant qu'aucun reçu n'a
 * été envoyé.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

const UEB_STATUTS_QUITUS = array(
	'genere'      => array( 'libelle' => 'À payer', 'aide' => 'Fais tamponner le quitus à ton établissement, puis paie à la banque.' ),
	'recu_envoye' => array( 'libelle' => 'Reçu envoyé', 'aide' => 'Présente-toi à la scolarité avec les originaux pour la vérification physique.' ),
	'verifie'     => array( 'libelle' => 'Vérifié', 'aide' => 'Paiement vérifié par la scolarité.' ),
	'rejete'      => array( 'libelle' => 'À corriger', 'aide' => 'La scolarité a signalé un problème : lis le motif et renvoie tes reçus.' ),
);

/**
 * Libellé d'une tranche payée : la valeur 3 couvre les deux tranches, la
 * valeur 0 signifie « sans tranche » (frais médicaux, paiement unique).
 */
function ueb_libelle_tranche( $tranche ) {
	$tranche = (int) $tranche;
	if ( ! $tranche ) {
		return '';
	}
	return 3 === $tranche ? 'Tranches 1 et 2' : 'Tranche ' . $tranche;
}

/** Libellé court d'un type de quitus. */
function ueb_libelle_type_quitus( $type ) {
	return UEB_TYPES_QUITUS[ $type ]['libelle'] ?? UEB_TYPES_QUITUS['droits']['libelle'];
}

/** Type et modalité : « Droits universitaires · Tranche 1 », « Frais médicaux · Paiement unique ». */
function ueb_detail_quitus( $quitus ) {
	$type  = $quitus->type ?? 'droits';
	$suite = 'medicaux' === $type ? 'Paiement unique' : ueb_libelle_tranche( $quitus->tranche );
	return ueb_libelle_type_quitus( $type ) . ( $suite ? ' · ' . $suite : '' );
}

const UEB_MONTANT_MIN = 1000;
const UEB_MONTANT_MAX = 10000000;

/* ---------- Lecture ---------- */

function ueb_quitus_du_compte( $compte_id ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare(
		'SELECT q.*, (SELECT COUNT(*) FROM ueb_insc_recus r WHERE r.quitus_id = q.id) AS nb_recus
		   FROM ueb_insc_quitus q WHERE q.compte_id = %d ORDER BY q.date_creation DESC',
		$compte_id
	) );
}

function ueb_quitus_par_id( $id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ueb_insc_quitus WHERE id = %d', $id ) );
}

/** Quitus d'un numéro donné, seulement s'il appartient au compte. */
function ueb_quitus_du_compte_par_numero( $compte_id, $numero ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare(
		'SELECT * FROM ueb_insc_quitus WHERE numero = %s AND compte_id = %d',
		strtoupper( (string) $numero ),
		$compte_id
	) );
}

function ueb_quitus_par_code( $code ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ueb_insc_quitus WHERE code_verif = %s', (string) $code ) );
}

function ueb_quitus_modifiable( $quitus ) {
	return 'genere' === $quitus->statut;
}

function ueb_nationalites() {
	global $wpdb;
	static $liste = null;
	if ( null === $liste ) {
		$liste = $wpdb->get_col( "SELECT nom FROM ueb_nationalites ORDER BY nom = 'Camerounaise' DESC, nom = 'Autre' ASC, nom" );
	}
	return $liste;
}

/**
 * Valeurs de départ du formulaire : le dernier quitus de l'étudiant, sinon
 * sa préinscription (nouveaux étudiants), sinon rien.
 */
function ueb_valeurs_initiales_quitus( $compte ) {
	global $wpdb;
	$dernier = $wpdb->get_row( $wpdb->prepare(
		'SELECT * FROM ueb_insc_quitus WHERE compte_id = %d ORDER BY date_creation DESC LIMIT 1',
		$compte->id
	), ARRAY_A );
	if ( $dernier ) {
		/* Le type, le montant et la tranche se choisissent à chaque quitus. */
		unset( $dernier['montant'], $dernier['tranche'], $dernier['type'] );
		return $dernier;
	}
	if ( $compte->numero_dossier ) {
		$pre = ueb_preinscription_par_dossier( $compte->numero_dossier );
		if ( $pre ) {
			return array(
				'etablissement'  => $pre->etablissement,
				'nom'            => mb_strtoupper( $pre->nom ),
				'prenom'         => $pre->prenom,
				'date_naissance' => $pre->date_naissance,
				'lieu_naissance' => $pre->lieu_naissance,
				'sexe'           => $pre->sexe,
				'nationalite'    => $pre->nationalite,
				'departement'    => $pre->filiere,
				'parcours'       => $pre->niveau,
			);
		}
	}
	return array();
}

/* ---------- Validation ---------- */

/**
 * @return array{0: array, 1: array} valeurs nettoyées, erreurs par champ
 */
function ueb_valider_quitus( array $post ) {
	$texte = static function ( $cle ) use ( $post ) {
		return trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( wp_unslash( $post[ $cle ] ?? '' ) ) ) );
	};
	$v = array(
		'etablissement'  => strtoupper( $texte( 'etablissement' ) ),
		'type'           => $texte( 'type' ) ?: 'droits',
		'nom'            => mb_strtoupper( $texte( 'nom' ) ),
		'prenom'         => $texte( 'prenom' ),
		'date_naissance' => $texte( 'date_naissance' ),
		'lieu_naissance' => $texte( 'lieu_naissance' ),
		'sexe'           => strtoupper( $texte( 'sexe' ) ),
		'nationalite'    => $texte( 'nationalite' ),
		'departement'    => $texte( 'departement' ),
		'parcours'       => $texte( 'parcours' ),
		'montant'        => (int) preg_replace( '/\D+/', '', $texte( 'montant' ) ),
		'tranche'        => (int) $texte( 'tranche' ),
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
	if ( mb_strlen( $v['departement'] ) < 2 ) {
		$e['departement'] = 'Saisis ton département ou ta filière.';
	}
	if ( mb_strlen( $v['parcours'] ) < 2 ) {
		$e['parcours'] = 'Saisis ton cycle, niveau et parcours (exemple : L2 TIC).';
	}
	if ( ! isset( UEB_TYPES_QUITUS[ $v['type'] ] ) ) {
		$v['type'] = 'droits';
		$e['type'] = 'Choisis le type de quitus.';
	}
	if ( 'medicaux' === $v['type'] ) {
		/* Frais de visite médicale : paiement unique, montant fixé par la situation déclarée. */
		$situation    = $texte( 'situation' );
		$v['tranche'] = 0;
		if ( ! isset( UEB_FRAIS_MEDICAUX[ $situation ] ) ) {
			$e['situation'] = 'Indique ta situation : elle détermine le montant.';
		} else {
			$v['montant'] = UEB_FRAIS_MEDICAUX[ $situation ]['montant'];
		}
	} else {
		if ( $v['montant'] < UEB_MONTANT_MIN || $v['montant'] > UEB_MONTANT_MAX ) {
			$e['montant'] = sprintf( 'Montant entre %s et %s FCFA.', ueb_formater_montant( UEB_MONTANT_MIN ), ueb_formater_montant( UEB_MONTANT_MAX ) );
		}
		if ( ! in_array( $v['tranche'], array( 1, 2, 3 ), true ) ) {
			$e['tranche'] = 'Indique la ou les tranches que tu paies.';
		}
	}
	foreach ( array( 'nom' => 100, 'prenom' => 150, 'lieu_naissance' => 150, 'departement' => 150, 'parcours' => 150 ) as $cle => $max ) {
		if ( mb_strlen( $v[ $cle ] ) > $max && empty( $e[ $cle ] ) ) {
			$e[ $cle ] = "$max caractères au maximum.";
		}
	}
	return array( $v, $e );
}

/* ---------- Numérotation ---------- */

/**
 * Numéro suivant pour un établissement, une année et un type :
 * FS-2627-000142 pour les droits, FS-M-2627-000007 pour les frais médicaux.
 * Chaque type a sa propre série.
 */
function ueb_prochain_numero_quitus( $sigle, array $annee, $type = 'droits' ) {
	global $wpdb;
	$wpdb->query( $wpdb->prepare(
		'INSERT INTO ueb_insc_sequence (etablissement, annee_academique, type, dernier) VALUES (%s, %s, %s, LAST_INSERT_ID(1))
		 ON DUPLICATE KEY UPDATE dernier = LAST_INSERT_ID(dernier + 1)',
		$sigle,
		$annee['code'],
		$type
	) );
	$rang   = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
	$marque = 'medicaux' === $type ? '-M' : '';
	return sprintf( '%s%s-%02d%02d-%06d', $sigle, $marque, $annee['debut'] % 100, $annee['fin'] % 100, $rang );
}

/* ---------- Enregistrement ---------- */

function ueb_action_enregistrer_quitus() {
	global $wpdb;
	$compte = ueb_compte_courant();
	if ( ! $compte ) {
		ueb_rediriger( ueb_url( 'connexion' ) );
	}
	$annee  = ueb_annee_academique();
	$id     = (int) ( $_POST['quitus_id'] ?? 0 );
	$existant = null;
	if ( $id ) {
		$existant = ueb_quitus_par_id( $id );
		if ( ! $existant || (int) $existant->compte_id !== (int) $compte->id ) {
			ueb_rediriger( ueb_url( 'mon-espace' ) );
		}
		if ( ! ueb_quitus_modifiable( $existant ) ) {
			ueb_flash( 'erreur', "Ce quitus n'est plus modifiable : des reçus ont déjà été envoyés." );
			ueb_rediriger( ueb_url( 'mon-espace' ) );
		}
	}

	list( $v, $erreurs ) = ueb_valider_quitus( $_POST );

	/* La visite médicale est déjà réglée avec les frais de préinscription de l'année. */
	if ( 'medicaux' === $v['type'] && ueb_preinscrit_cette_annee( $compte ) ) {
		$erreurs['type'] = 'Ta visite médicale est déjà comprise dans tes frais de préinscription de cette année : tu n’as pas de quitus médical à générer.';
	}

	/* Doublons : un seul quitus médical par an, et pour les droits un seul par
	   tranche (« les deux tranches » couvre la 1 et la 2). */
	if ( empty( $erreurs['type'] ) && 'medicaux' === $v['type'] ) {
		$doublon = $wpdb->get_var( $wpdb->prepare(
			"SELECT numero FROM ueb_insc_quitus
			  WHERE compte_id = %d AND annee_academique = %s AND type = 'medicaux' AND statut <> 'rejete' AND id <> %d",
			$compte->id, $annee['code'], $id
		) );
		if ( $doublon ) {
			$erreurs['type'] = "Tu as déjà un quitus de frais médicaux pour cette année ($doublon). Modifie-le plutôt que d’en créer un autre.";
		}
	} elseif ( empty( $erreurs['tranche'] ) && empty( $erreurs['type'] ) ) {
		$tranches = 3 === $v['tranche'] ? array( 1, 2, 3 ) : array( $v['tranche'], 3 );
		$marques  = implode( ', ', array_fill( 0, count( $tranches ), '%d' ) );
		$doublon  = $wpdb->get_row( $wpdb->prepare(
			"SELECT numero, tranche FROM ueb_insc_quitus
			  WHERE compte_id = %d AND annee_academique = %s AND type = 'droits' AND tranche IN ($marques) AND statut <> 'rejete' AND id <> %d", // phpcs:ignore -- marques = suite de %d
			array_merge( array( $compte->id, $annee['code'] ), $tranches, array( $id ) )
		) );
		if ( $doublon ) {
			$erreurs['tranche'] = sprintf(
				'Tu as déjà un quitus pour « %s » (%s). Modifie-le plutôt que d’en créer un autre.',
				ueb_libelle_tranche( $doublon->tranche ),
				$doublon->numero
			);
		}
	}

	$retour = ueb_url( 'mon-espace/quitus' ) . ( $id ? '?id=' . $id : '' );
	if ( $erreurs ) {
		ueb_memoriser_saisie( $v, $erreurs );
		ueb_rediriger( $retour );
	}

	$donnees = $v + array(
		'identifiant'      => ueb_identifiant_compte( $compte ),
		'type_identifiant' => $compte->matricule ? 'matricule' : 'dossier',
	);

	if ( $existant ) {
		/* Changer d'établissement ou de type change la série : nouveau numéro. */
		if ( $existant->etablissement !== $v['etablissement'] || ( $existant->type ?? 'droits' ) !== $v['type'] ) {
			$donnees['numero'] = ueb_prochain_numero_quitus( $v['etablissement'], $annee, $v['type'] );
		}
		$wpdb->update( 'ueb_insc_quitus', $donnees, array( 'id' => $existant->id ) );
		$numero = $donnees['numero'] ?? $existant->numero;
		ueb_flash( 'succes', "Quitus $numero mis à jour. Télécharge la nouvelle version." );
	} else {
		$numero = ueb_prochain_numero_quitus( $v['etablissement'], $annee, $v['type'] );
		$ok     = $wpdb->insert( 'ueb_insc_quitus', $donnees + array(
			'numero'           => $numero,
			'code_verif'       => bin2hex( random_bytes( 10 ) ),
			'compte_id'        => $compte->id,
			'annee_academique' => $annee['code'],
		) );
		if ( ! $ok ) {
			error_log( '[inscription-ueb] Enregistrement du quitus impossible : ' . $wpdb->last_error );
			ueb_memoriser_saisie( $v, array( 'general' => "Le quitus n'a pas pu être enregistré. Réessaie dans un instant." ) );
			ueb_rediriger( $retour );
		}
		ueb_flash( 'succes', "Quitus $numero prêt. Télécharge-le, fais-le tamponner à ton établissement puis paie à la banque." );
	}
	ueb_rediriger( ueb_url( 'mon-espace' ) . '#quitus-' . $numero );
}

/** URL publique de vérification, encodée dans le QR code. */
function ueb_url_verification( $quitus ) {
	return ueb_url( 'verifier/' . $quitus->code_verif );
}
