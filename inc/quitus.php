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
		   FROM ueb_insc_quitus q WHERE q.compte_id = %d ORDER BY q.date_creation DESC, q.id DESC',
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
	if ( 'genere' !== $quitus->statut || $quitus->annee_academique !== ueb_annee_academique()['code'] ) {
		return false;
	}
	$medical = ueb_medical_du_dossier( $quitus );
	return ! $medical || 'genere' === $medical->statut;
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
		unset( $dernier['montant'], $dernier['tranche'], $dernier['type'], $dernier['quitus_droits_id'] );
		if ( $dernier['annee_academique'] !== ueb_annee_academique()['code'] ) {
			unset( $dernier['situation'] );
		}
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
				'email'          => $pre->email,
				'adresse'        => $pre->adresse,
				'nom_urgence'    => $pre->nom_urgence,
				'numero_urgence' => $pre->numero_urgence,
				'adresse_urgence'=> $pre->adresse_urgence,
				'sexe'           => $pre->sexe,
				'nationalite'    => $pre->nationalite,
				'departement'    => $pre->filiere,
				'filiere_id'     => $pre->filiere_1_id,
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
function ueb_valider_quitus( array $post, array $contexte ) {
	$texte = static function ( $cle ) use ( $post ) {
		return trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( wp_unslash( is_scalar( $post[ $cle ] ?? '' ) ? (string) ( $post[ $cle ] ?? '' ) : '' ) ) ) );
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
		'email'          => $texte( 'email' ),
		'adresse'        => $texte( 'adresse' ),
		'nom_urgence'    => $texte( 'nom_urgence' ),
		'numero_urgence' => ueb_normaliser_telephone( $texte( 'numero_urgence' ) ) ?? $texte( 'numero_urgence' ),
		'adresse_urgence'=> $texte( 'adresse_urgence' ),
		'filiere_id'     => (int) $texte( 'filiere_id' ),
		'situation'      => $contexte['situation_verrouillee'] ? $contexte['situation'] : $texte( 'situation' ),
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
	$cms_requis = ueb_fiches_cms_requises( $contexte, $v['situation'], $v['type'] );
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
	$formation = $contexte['formations'][ $v['filiere_id'] ] ?? null;
	if ( ! $formation || $formation->etablissement !== $v['etablissement'] ) {
		$e['filiere_id'] = $contexte['nouveau'] ? 'Choisis une des filières de ta préinscription dans cet établissement.' : 'Choisis une filière rattachée à cet établissement.';
	} else {
		$v['departement'] = $formation->libelle;
	}
	if ( ! isset( UEB_NIVEAUX_INSCRIPTION[ $v['parcours'] ] ) ) {
		$e['parcours'] = 'Choisis ton niveau dans la liste.';
	}
	if ( 'nouveau' !== $v['situation'] && ! isset( UEB_FRAIS_MEDICAUX[ $v['situation'] ] ) ) {
		$e['situation'] = 'Choisis Nouveau, une réinscription sans interruption ou une reprise après réactivation.';
	}
	if ( ! isset( UEB_TYPES_QUITUS[ $v['type'] ] ) ) {
		$v['type'] = 'droits';
		$e['type'] = 'Choisis le type de quitus.';
	}
	if ( 'medicaux' === $v['type'] ) {
		/* Frais de visite médicale : paiement unique, montant fixé par la situation déclarée. */
		$v['tranche'] = 0;
		$v['montant'] = UEB_FRAIS_MEDICAUX[ $v['situation'] ]['montant'] ?? 0;
	} else {
		$calcul = ueb_calculer_paiement( $formation, $v['tranche'], $v['montant'], $v['situation'], $contexte );
		$v['montant'] = $calcul['droits'];
		if ( $v['montant'] < UEB_MONTANT_MIN || $v['montant'] > UEB_MONTANT_MAX || ( $formation && 'pro' === $formation->type_formation && ! preg_match( '/^\d[\d\s]*$/u', $texte( 'montant' ) ) ) ) {
			$e['montant'] = sprintf( 'Montant entre %s et %s FCFA.', ueb_formater_montant( UEB_MONTANT_MIN ), ueb_formater_montant( UEB_MONTANT_MAX ) );
		}
		if ( ! isset( $contexte['tranches'][ $v['tranche'] ] ) ) {
			$e['tranche'] = 'Choisis une tranche disponible. Pour une tranche déjà préparée, utilise le quitus existant dans ton espace.';
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
	$ok = $wpdb->query( $wpdb->prepare(
		'INSERT INTO ueb_insc_sequence (etablissement, annee_academique, type, dernier) VALUES (%s, %s, %s, LAST_INSERT_ID(1))
		 ON DUPLICATE KEY UPDATE dernier = LAST_INSERT_ID(dernier + 1)',
		$sigle,
		$annee['code'],
		$type
	) );
	if ( false === $ok ) {
		throw new RuntimeException( 'Numérotation du quitus impossible : ' . $wpdb->last_error );
	}
	$rang   = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
	$marque = 'medicaux' === $type ? '-M' : '';
	return sprintf( '%s%s-%02d%02d-%06d', $sigle, $marque, $annee['debut'] % 100, $annee['fin'] % 100, $rang );
}

/* ---------- Enregistrement ---------- */

/** Enregistre les deux quitus ensemble, sous verrou du compte, ou aucun. */
function ueb_action_enregistrer_quitus() {
	global $wpdb;
	$compte = ueb_compte_courant();
	if ( ! $compte ) {
		ueb_rediriger( ueb_url( 'connexion' ) );
	}
	$annee = ueb_annee_academique();
	$id = (int) ( $_POST['quitus_id'] ?? 0 );
	$actualiser = '1' === ( $_POST['actualiser_paiement'] ?? '' );
	$retour = ueb_url( 'mon-espace/quitus' ) . ( $id ? '?id=' . $id : '' );
	$erreurs = array();
	$v = array();
	$numero = '';
	try {
		if ( false === $wpdb->query( 'START TRANSACTION' ) || ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ueb_insc_comptes WHERE id = %d FOR UPDATE', $compte->id ) ) ) {
			throw new RuntimeException( 'Verrouillage du compte impossible.' );
		}
		$existant = $id ? ueb_quitus_par_id( $id ) : null;
		if ( $id && ( ! $existant || (int) $existant->compte_id !== (int) $compte->id || ! ueb_quitus_modifiable( $existant ) ) ) {
			$erreurs['general'] = 'Ce quitus ne peut plus être modifié. Consulte les documents et les reçus dans ton espace.';
		} elseif ( $existant && ! empty( $existant->quitus_droits_id ) ) {
			$erreurs['general'] = 'Modifie le quitus des droits universitaires associé pour mettre à jour ce dossier médical.';
		} else {
			$contexte = ueb_contexte_inscription( $compte, $existant );
			$post = $_POST;
			// Le type est choisi par le parcours, jamais par une valeur modifiée dans le navigateur.
			$post['type'] = $existant->type ?? 'droits';
			list( $v, $erreurs ) = ueb_valider_quitus( $post, $contexte );
			if ( 'medicaux' === $v['type'] && $contexte['nouveau'] ) {
				$erreurs['general'] = 'La visite médicale est déjà comprise dans ta préinscription de cette année.';
			}
		}
		if ( $erreurs || $actualiser ) {
			$wpdb->query( 'ROLLBACK' );
		} else {
			$donnees = $v + array(
				'identifiant' => ueb_identifiant_compte( $compte ),
				'type_identifiant' => $compte->matricule ? 'matricule' : 'dossier',
			);
			if ( $existant ) {
				$numero = $existant->etablissement === $v['etablissement'] ? $existant->numero : ueb_prochain_numero_quitus( $v['etablissement'], $annee, $v['type'] );
				$ok = $wpdb->update( 'ueb_insc_quitus', $donnees + array( 'numero' => $numero ), array( 'id' => $id ) );
			} else {
				$numero = ueb_prochain_numero_quitus( $v['etablissement'], $annee, 'droits' );
				$ok = $wpdb->insert( 'ueb_insc_quitus', $donnees + array(
					'numero' => $numero,
					'code_verif' => bin2hex( random_bytes( 10 ) ),
					'compte_id' => $compte->id,
					'annee_academique' => $annee['code'],
				) );
				$id = (int) $wpdb->insert_id;
			}
			if ( false === $ok ) {
				throw new RuntimeException( $wpdb->last_error );
			}
		if ( 'droits' === $v['type'] && $contexte['medical_inclus'] && 'nouveau' !== $v['situation'] ) {
				$medical = $contexte['medical'];
				$donnees['type'] = 'medicaux';
				$donnees['tranche'] = 0;
				$donnees['montant'] = UEB_FRAIS_MEDICAUX[ $v['situation'] ]['montant'];
				$donnees['quitus_droits_id'] = $id;
				if ( $medical ) {
					if ( $medical->etablissement !== $v['etablissement'] ) {
						$donnees['numero'] = ueb_prochain_numero_quitus( $v['etablissement'], $annee, 'medicaux' );
					}
					$ok = $wpdb->update( 'ueb_insc_quitus', $donnees, array( 'id' => $medical->id ) );
				} else {
					$ok = $wpdb->insert( 'ueb_insc_quitus', $donnees + array(
						'numero' => ueb_prochain_numero_quitus( $v['etablissement'], $annee, 'medicaux' ),
						'code_verif' => bin2hex( random_bytes( 10 ) ),
						'compte_id' => $compte->id,
						'annee_academique' => $annee['code'],
					) );
				}
				if ( false === $ok ) {
					throw new RuntimeException( $wpdb->last_error );
				}
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new RuntimeException( $wpdb->last_error );
			}
		}
	} catch ( Throwable $exception ) {
		$wpdb->query( 'ROLLBACK' );
		error_log( '[inscription-ueb] Enregistrement du dossier impossible : ' . $exception->getMessage() );
		$erreurs['general'] = 'Les documents n’ont pas pu être enregistrés. Réessaie dans un instant.';
	}
	if ( $actualiser && empty( $erreurs['general'] ) ) {
		ueb_memoriser_saisie( $v, array() );
		ueb_rediriger( $retour . '#section-paiement' );
	}
	if ( $erreurs ) {
		ueb_memoriser_saisie( $v, $erreurs );
		ueb_rediriger( $retour );
	}
	$_SESSION['ueb_telechargement'] = array( 'compte_id' => (int) $compte->id, 'numero' => $numero );
	ueb_flash( 'succes', 'Tes documents sont enregistrés dans Mes quitus. Fais tamponner chaque quitus avant le paiement à la banque.' );
	ueb_rediriger( ueb_url( 'mon-espace' ) . '#quitus-' . $numero );
}

/** URL publique de vérification, conservée pour les liens et les anciens QR codes. */
function ueb_url_verification( $quitus ) {
	return ueb_url( 'verifier/' . $quitus->code_verif );
}
