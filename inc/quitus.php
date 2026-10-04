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
	'genere'      => array( 'libelle' => 'À payer', 'aide' => 'Paie-le, puis envoie la photo de ton reçu.' ),
	'recu_envoye' => array( 'libelle' => 'Reçu envoyé', 'aide' => 'Présente l’original de ton reçu à la scolarité pour le faire tamponner.' ),
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

/**
 * Vrai si l'étudiant peut encore corriger la filière et le niveau de ce
 * dossier après l'envoi : année en cours et aucun paiement du dossier vérifié
 * (version 10). Le reste du quitus est figé dès l'envoi d'un reçu.
 */
function ueb_quitus_parcours_modifiable( $quitus ) {
	if ( $quitus->annee_academique !== ueb_annee_academique()['code'] ) {
		return false;
	}
	$dossier = ueb_dossier_du_quitus( $quitus );
	foreach ( $dossier ? $dossier['paiements'] : array( $quitus ) as $paiement ) {
		if ( 'verifie' === $paiement->statut ) {
			return false;
		}
	}
	return true;
}

/**
 * Filières vers lesquelles ce quitus peut être corrigé : ouvertes, du même
 * établissement et du même type (classique ou pro) que sa filière actuelle,
 * pour que le montant reste juste. Par identifiant.
 */
function ueb_filieres_correction( $quitus ) {
	$toutes  = ueb_formations_inscription( true );
	$actuelle = $toutes[ (int) $quitus->filiere_id ] ?? null;
	if ( ! $actuelle ) {
		global $wpdb;
		/* Filière fermée depuis : son type se lit dans le catalogue. */
		$actuelle = $wpdb->get_row( $wpdb->prepare( 'SELECT type_formation FROM ueb_filieres WHERE id = %d', (int) $quitus->filiere_id ) );
	}
	$type = $actuelle->type_formation ?? 'classique';
	return array_filter( $toutes, static fn( $f ) => $f->etablissement === $quitus->etablissement && $f->type_formation === $type );
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
 * Valeurs de départ du formulaire : le dernier quitus de l'étudiant, sinon rien.
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
	return array();
}

/* ---------- Validation ---------- */

/**
 * @return array{0: array, 1: array} valeurs nettoyées, erreurs par champ
 */
function ueb_valider_quitus( array $post, array $contexte ) {
	$texte = static fn( $cle ) => ueb_texte_poste( $post, $cle );
	$v = array(
		'type'           => $texte( 'type' ) ?: 'droits',
		'situation'      => $contexte['situation_verrouillee'] ? $contexte['situation'] : $texte( 'situation' ),
		'montant'        => (int) preg_replace( '/\D+/', '', $texte( 'montant' ) ),
		'tranche'        => (int) $texte( 'tranche' ),
		'moyen_paiement' => $texte( 'moyen_paiement' ),
	);
	$cms_requis = ueb_fiches_cms_requises( $contexte, $v['situation'], $v['type'] );
	list( $profil, $e ) = ueb_valider_profil( $post, $contexte['formations'], $cms_requis );
	$v = $profil + $v;
	$formation = $contexte['formations'][ $v['filiere_id'] ] ?? null;

	if ( 'nouveau' !== $v['situation'] && ! isset( UEB_FRAIS_MEDICAUX[ $v['situation'] ] ) ) {
		$e['situation'] = 'Indique ta situation : nouveau, réinscription sans interruption ou réinscription avec interruption.';
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
		$classique = $formation && 'classique' === $formation->type_formation;
		if ( ! isset( $contexte['tranches'][ $v['tranche'] ] ) ) {
			$e['tranche'] = 'Choisis une tranche disponible. Pour une tranche déjà préparée, utilise le quitus existant dans ton espace.';
		} elseif ( $classique ) {
			/* Formation classique : la deuxième tranche vaut le reste, les deux tranches
			   50 000 ; seule la première se saisit. Le montant posté est ignoré sinon. */
			$regle = ueb_regle_droits_classiques( $contexte );
			$fixe  = ueb_montant_tranche_classique( $v['tranche'], $regle );
			if ( null !== $fixe ) {
				$v['montant'] = $fixe;
			} else {
				$erreur_montant = ueb_erreur_montant_classique( $v['montant'], $regle );
				if ( $erreur_montant ) {
					$e['montant'] = $erreur_montant;
				}
			}
		}
		if ( ! $classique && ( $v['montant'] < UEB_MONTANT_MIN || $v['montant'] > UEB_MONTANT_MAX || ( $formation && 'pro' === $formation->type_formation && ! preg_match( '/^\d[\d\s]*$/u', $texte( 'montant' ) ) ) ) ) {
			$e['montant'] = sprintf( 'Montant entre %s et %s FCFA.', ueb_formater_montant( UEB_MONTANT_MIN ), ueb_formater_montant( UEB_MONTANT_MAX ) );
		}
	}
	if ( ! in_array( $v['moyen_paiement'], UEB_MOYENS_PAIEMENT, true ) ) {
		$e['moyen_paiement'] = 'Choisis où tu vas payer : CCA Bank, Express Union, MTN Mobile Money ou Campost Money.';
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

/**
 * Jeton à usage unique du formulaire de création : un double clic ou un
 * renvoi du même formulaire ne crée pas un second quitus (le même montant
 * pourrait sinon passer pour le second versement). Dix jetons au plus.
 */
function ueb_jeton_formulaire_quitus() {
	$jeton   = bin2hex( random_bytes( 12 ) );
	$jetons  = array_slice( (array) ( $_SESSION['ueb_jetons_quitus'] ?? array() ), -9, null, true );
	$jetons[ $jeton ] = time();
	$_SESSION['ueb_jetons_quitus'] = $jetons;
	return $jeton;
}

/** Consomme le jeton posté ; faux s'il est absent ou déjà utilisé. */
function ueb_consommer_jeton_quitus( $jeton ) {
	$jeton = (string) $jeton;
	if ( '' === $jeton || empty( $_SESSION['ueb_jetons_quitus'][ $jeton ] ) ) {
		return false;
	}
	unset( $_SESSION['ueb_jetons_quitus'][ $jeton ] );
	return true;
}

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
	if ( ! $id && ! $actualiser && ! ueb_consommer_jeton_quitus( $_POST['jeton_quitus'] ?? '' ) ) {
		ueb_flash( 'info', 'Ce formulaire a déjà été envoyé : ton quitus est dans Mes quitus.' );
		ueb_rediriger( add_query_arg( 'vue', 'quitus', ueb_url( 'mon-espace' ) ) );
	}
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
			/* Nouveau quitus : les informations déjà sur la fiche ne changent que dans
			   Mon compte, elles remplacent celles postées (champs verrouillés du
			   formulaire). Quitus pas encore envoyé : tout se modifie, la fiche suit. */
			$profil = $existant ? array() : ueb_profil_fige( $compte->id, $contexte['formations'] );
			$post = array_replace( $_POST, wp_slash( array_map( 'strval', $profil ) ) );
			// Le type est choisi par le parcours, jamais par une valeur modifiée dans le navigateur.
			$post['type'] = $existant->type ?? 'droits';
			list( $v, $erreurs ) = ueb_valider_quitus( $post, $contexte );
			foreach ( array_intersect_key( $erreurs, $profil ) as $cle => $message ) {
				/* L'établissement et la filière ne se changent pas dans Mon compte. */
				$erreurs[ $cle ] = $message . ( in_array( $cle, array( 'etablissement', 'filiere_id' ), true ) ? ' Adresse-toi à la scolarité de ton établissement.' : ' Modifie cette information dans Mon compte.' );
			}
			if ( 'medicaux' === $v['type'] && 'nouveau' === $v['situation'] ) {
				$erreurs['general'] = 'Un nouvel étudiant a déjà payé la visite médicale avec sa préinscription : pas de quitus médical.';
			}
		}
		if ( $erreurs || $actualiser ) {
			$wpdb->query( 'ROLLBACK' );
		} else {
			$donnees = $v + array(
				'identifiant' => ueb_identifiant_compte( $compte ),
				'type_identifiant' => 'matricule',
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
			if ( ! ueb_profil_enregistrer( $compte->id, $v ) ) {
				throw new RuntimeException( $wpdb->last_error );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new RuntimeException( $wpdb->last_error );
			}
		}
	} catch ( Throwable $exception ) {
		$wpdb->query( 'ROLLBACK' );
		error_log( '[inscriptions-ueb] Enregistrement du dossier impossible : ' . $exception->getMessage() );
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
	ueb_flash( 'succes', 'Tes documents sont enregistrés dans Mes quitus. Imprime-les, paie, puis envoie la photo de ton reçu.' );
	ueb_rediriger( add_query_arg( 'vue', 'quitus', ueb_url( 'mon-espace' ) ) . '#quitus-' . $numero );
}

/** URL publique de vérification, conservée pour les liens et les anciens QR codes. */
function ueb_url_verification( $quitus ) {
	return ueb_url( 'verifier/' . $quitus->code_verif );
}

/**
 * Correction de la filière ou du niveau d'un dossier déjà envoyé, tant
 * qu'aucun de ses paiements n'est vérifié. Le quitus des droits et le quitus
 * médical du dossier sont corrigés ensemble, ainsi que la fiche de
 * l'étudiant ; la correction est tracée (corrige_le, correction) pour la
 * scolarité qui vérifie.
 */
function ueb_action_corriger_parcours() {
	global $wpdb;
	$compte = ueb_compte_courant();
	if ( ! $compte ) {
		ueb_rediriger( ueb_url( 'connexion' ) );
	}
	$quitus = ueb_quitus_du_compte_par_numero( $compte->id, sanitize_text_field( wp_unslash( $_POST['numero'] ?? '' ) ) );
	if ( ! $quitus ) {
		ueb_rediriger( ueb_url( 'mon-espace' ) );
	}
	$retour = add_query_arg( 'vue', 'quitus', ueb_url( 'mon-espace' ) ) . '#quitus-' . $quitus->numero;
	if ( ! ueb_quitus_parcours_modifiable( $quitus ) ) {
		ueb_flash( 'erreur', 'Ce dossier a déjà été vérifié par la scolarité : la filière et le niveau ne peuvent plus changer.' );
		ueb_rediriger( $retour );
	}
	$filieres = ueb_filieres_correction( $quitus );
	$filiere  = $filieres[ (int) ( $_POST['filiere_id'] ?? 0 ) ] ?? null;
	$niveau   = sanitize_text_field( wp_unslash( $_POST['parcours'] ?? '' ) );
	if ( ! $filiere ) {
		ueb_flash( 'erreur', 'Choisis une filière de ' . $quitus->etablissement . ' du même type que la tienne : le montant du quitus en dépend.' );
		ueb_rediriger( $retour );
	}
	if ( ! isset( UEB_NIVEAUX_INSCRIPTION[ $niveau ] ) ) {
		ueb_flash( 'erreur', 'Choisis ton niveau dans la liste.' );
		ueb_rediriger( $retour );
	}
	if ( ! ueb_filiere_ouverte_au_niveau( $filiere, $niveau ) ) {
		ueb_flash( 'erreur', 'Cette filière n’est pas ouverte en ' . $niveau . ' : choisis une filière de ton niveau.' );
		ueb_rediriger( $retour );
	}
	if ( (int) $filiere->id === (int) $quitus->filiere_id && $niveau === $quitus->parcours ) {
		ueb_flash( 'info', 'Aucun changement : ta filière et ton niveau sont déjà ceux-là.' );
		ueb_rediriger( $retour );
	}

	$avant      = 'Avant : ' . $quitus->departement . ' · ' . ( UEB_NIVEAUX_INSCRIPTION[ $quitus->parcours ] ?? $quitus->parcours );
	$dossier    = ueb_dossier_du_quitus( $quitus );
	$paiements  = $dossier ? $dossier['paiements'] : array( $quitus );
	$ids        = array_map( static fn( $p ) => (int) $p->id, $paiements );
	$marques    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	/* Condition sur le statut dans la même requête : une vérification arrivée entre-temps l'emporte. */
	$modifies = $wpdb->query( $wpdb->prepare(
		"UPDATE ueb_insc_quitus SET filiere_id = %d, departement = %s, parcours = %s, corrige_le = %s, correction = %s
		 WHERE compte_id = %d AND statut <> 'verifie' AND id IN ($marques)",
		array_merge( array( $filiere->id, $filiere->libelle, $niveau, current_time( 'mysql' ), mb_substr( $avant, 0, 255 ), $compte->id ), $ids )
	) );
	if ( ! $modifies ) {
		ueb_flash( 'erreur', 'La correction n’a pas pu être enregistrée. Réessaie dans un instant.' );
		ueb_rediriger( $retour );
	}
	/* La fiche de l'étudiant suit : ses prochains quitus partent de la bonne filière. */
	$wpdb->update( 'ueb_insc_profils', array( 'filiere_id' => $filiere->id, 'parcours' => $niveau ), array( 'compte_id' => $compte->id ) );

	ueb_flash( 'succes', 'Filière et niveau corrigés : ' . $filiere->libelle . ' · ' . UEB_NIVEAUX_INSCRIPTION[ $niveau ] . '. Télécharge de nouveau ton quitus : la scolarité verra la correction.' );
	ueb_rediriger( $retour );
}
