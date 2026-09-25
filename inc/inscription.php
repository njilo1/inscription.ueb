<?php
/** Catalogue partagé et règles annuelles du paiement de l'inscription. */
defined( 'ABSPATH' ) || exit;

/** Les trois vœux du préinscrit ; toutes les filières actives pour les anciens. */
function ueb_formations_inscription( $compte, $preinscrit ) {
	global $wpdb;
	$sql = 'SELECT fi.id, fi.libelle, fi.type_formation, f.code AS etablissement
		FROM ueb_filieres fi JOIN ueb_facultes f ON f.id = fi.faculte_id';
	if ( $preinscrit ) {
		$pre = ueb_preinscription_par_dossier( $compte->numero_dossier );
		if ( ! $pre ) {
			return array();
		}
		$ids = array_values( array_unique( array_filter( array_map( 'intval', array( $pre->filiere_1_id, $pre->filiere_2_id, $pre->filiere_3_id ) ) ) ) );
		if ( ! $ids ) {
			return array();
		}
		$marques = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// Conserver les vœux soumis, même si une filière n'est plus ouverte aux nouvelles candidatures.
		$sql = $wpdb->prepare( $sql . " WHERE fi.id IN ($marques) ORDER BY FIELD(fi.id, $marques)", array_merge( $ids, $ids ) );
	} else {
		$sql .= ' WHERE fi.actif = 1 ORDER BY f.code, fi.libelle';
	}
	$formations = array();
	foreach ( $wpdb->get_results( $sql ) as $i => $f ) {
		$f->id = (int) $f->id;
		$f->choix = $preinscrit ? $i + 1 : null;
		$formations[ $f->id ] = $f;
	}
	return $formations;
}

/** Un rejet de reçu n'annule pas les frais : le même quitus doit être corrigé. */
function ueb_quitus_medical_annuel( $compte_id, $annee ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM ueb_insc_quitus WHERE compte_id = %d AND annee_academique = %s AND type = 'medicaux'
		 ORDER BY FIELD(statut, 'verifie', 'recu_envoye', 'genere', 'rejete'), id LIMIT 1",
		$compte_id, $annee
	) );
}

/** Données de confiance, communes au formulaire et à sa validation serveur. */
function ueb_contexte_inscription( $compte, $edite = null ) {
	global $wpdb;
	$annee = ueb_annee_academique()['code'];
	$nouveau = ueb_preinscrit_cette_annee( $compte );
	$medical = ueb_quitus_medical_annuel( $compte->id, $annee );
	$droits = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, numero, tranche, montant, situation, statut FROM ueb_insc_quitus
		 WHERE compte_id = %d AND annee_academique = %s AND type = 'droits' ORDER BY id",
		$compte->id, $annee
	) );
	$situation = $nouveau ? 'nouveau' : ( $medical->situation ?? ( $droits[0]->situation ?? '' ) );
	if ( ! $nouveau && ! isset( UEB_FRAIS_MEDICAUX[ $situation ] ) ) {
		$situation = $medical && 5000 === (int) $medical->montant ? 'reprise' : 'ancien';
	}
	$tranches = array( 1 => 'Première tranche', 2 => 'Deuxième tranche', 3 => 'Les deux tranches' );
	$premiere_preparee = false;
	$deja_prepare = 0;
	foreach ( $droits as $q ) {
		if ( $edite && (int) $q->id === (int) $edite->id ) {
			continue;
		}
		$deja_prepare += (int) $q->montant;
		$premiere_preparee = $premiere_preparee || in_array( (int) $q->tranche, array( 1, 3 ), true );
		foreach ( array_keys( $tranches ) as $t ) {
			if ( 3 === $t || 3 === (int) $q->tranche || $t === (int) $q->tranche ) {
				unset( $tranches[ $t ] );
			}
		}
	}
	if ( ! $premiere_preparee && ( ! $edite || 2 !== (int) $edite->tranche ) ) {
		unset( $tranches[2] );
	}
	$medical_dans_dossier = $medical && $edite && ( (int) ( $medical->quitus_droits_id ?? 0 ) === (int) $edite->id || (int) $medical->id === (int) $edite->id );
	return array(
		'nouveau' => $nouveau,
		'formations' => ueb_formations_inscription( $compte, $nouveau ),
		'medical' => $medical,
		'medical_inclus' => ! $nouveau && ( ! $medical || $medical_dans_dossier ),
		'situation' => $situation,
		'situation_verrouillee' => (bool) ( $medical && ( ! $medical_dans_dossier || 'genere' !== $medical->statut ) ),
		'tranches' => $tranches,
		'reste_droits' => max( 0, UEB_DROITS_CLASSIQUES - $deja_prepare ),
	);
}

/**
 * Bornes du montant des droits d'une formation classique. Premier versement :
 * de 25 000 à 50 000 ; second versement (la première tranche existe déjà) :
 * de 5 000 au reste de l'année. Toujours par multiples de 5 000.
 */
function ueb_regle_droits_classiques( array $contexte ) {
	$second = isset( $contexte['tranches'][2] ) && ! isset( $contexte['tranches'][1] );
	return array(
		'second' => $second,
		'min'    => $second ? UEB_DROITS_PAS : UEB_DROITS_MINIMUM,
		'max'    => $second ? (int) $contexte['reste_droits'] : UEB_DROITS_CLASSIQUES,
		'pas'    => UEB_DROITS_PAS,
	);
}

/** Tranche couverte par un montant classique : 2 au second versement, sinon 1, ou 3 à 50 000. */
function ueb_tranche_du_montant( $montant, array $regle ) {
	if ( $regle['second'] ) {
		return 2;
	}
	return (int) $montant >= UEB_DROITS_CLASSIQUES ? 3 : 1;
}

/** Message d'erreur du montant classique, ou chaîne vide s'il est valable. */
function ueb_erreur_montant_classique( $montant, array $regle ) {
	$montant = (int) $montant;
	if ( ! $montant ) {
		return $regle['second'] ? sprintf( 'Saisis le montant de ton second versement (%s FCFA au plus).', ueb_formater_montant( $regle['max'] ) ) : 'Saisis le montant que tu verses : 25 000 FCFA au moins.';
	}
	if ( $montant % $regle['pas'] ) {
		return 'Saisis un multiple de 5 000 FCFA : 25 000, 30 000, 35 000…';
	}
	if ( $montant < $regle['min'] ) {
		return sprintf( 'Le premier versement est de %s FCFA au moins.', ueb_formater_montant( $regle['min'] ) );
	}
	if ( $montant > $regle['max'] ) {
		return $regle['second']
			? sprintf( 'Il te reste %s FCFA à payer cette année : ne dépasse pas ce montant.', ueb_formater_montant( $regle['max'] ) )
			: 'Les droits de l’année sont de 50 000 FCFA au plus (les deux tranches).';
	}
	return '';
}

/** Les coordonnées CMS ne sont obligatoires que si les fiches sont générées. */
function ueb_fiches_cms_requises( array $contexte, $situation, $type = 'droits' ) {
	return 'medicaux' === $type || ( $contexte['medical_inclus'] && 'nouveau' !== $situation );
}

/** Montants calculés sans faire confiance au tarif ou au type de formation postés. */
function ueb_calculer_paiement( $formation, $tranche, $montant, $situation, array $contexte, $type = 'droits' ) {
	/* Classique comme professionnelle, le montant est celui saisi ; les
	   bornes et la tranche des formations classiques sont contrôlées par
	   ueb_valider_quitus(). */
	$droits = (int) $montant;
	$medical = 'nouveau' === $situation ? 0 : ( $contexte['medical_inclus'] ? ( UEB_FRAIS_MEDICAUX[ $situation ]['montant'] ?? 0 ) : 0 );
	if ( 'medicaux' === $type ) {
		$droits = 0;
	}
	return array( 'droits' => $droits, 'medicaux' => $medical, 'total' => $droits + $medical );
}

/** Quitus médical associé à ce premier paiement, jamais à la deuxième tranche suivante. */
function ueb_medical_du_dossier( $quitus ) {
	global $wpdb;
	if ( 'droits' !== ( $quitus->type ?? 'droits' ) ) {
		return null;
	}
	return $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM ueb_insc_quitus WHERE quitus_droits_id = %d AND compte_id = %d AND annee_academique = %s AND type = 'medicaux' LIMIT 1",
		$quitus->id, $quitus->compte_id, $quitus->annee_academique
	) );
}

/** Une carte par PDF, en conservant les paiements et leurs statuts distincts. */
function ueb_dossiers_quitus( array $quitus ) {
	$par_id = array();
	foreach ( $quitus as $q ) {
		$par_id[ (int) $q->id ] = $q;
	}
	$dossiers = array();
	foreach ( $quitus as $q ) {
		$principal = $q;
		$parent = $par_id[ (int) ( $q->quitus_droits_id ?? 0 ) ] ?? null;
		if ( 'medicaux' === $q->type && $parent && 'droits' === $parent->type
			&& (int) $parent->compte_id === (int) $q->compte_id && $parent->annee_academique === $q->annee_academique ) {
			$principal = $parent;
		}
		$id = (int) $principal->id;
		if ( ! isset( $dossiers[ $id ] ) ) {
			$dossiers[ $id ] = array( 'principal' => $principal, 'paiements' => array(), 'total' => 0, 'pages' => 0, 'nb_recus' => 0 );
		}
		$dossiers[ $id ]['paiements'][] = $q;
		$dossiers[ $id ]['total'] += (int) $q->montant;
		$dossiers[ $id ]['pages'] += 'medicaux' === $q->type ? 3 : 1;
		$dossiers[ $id ]['nb_recus'] += (int) ( $q->nb_recus ?? 0 );
	}
	foreach ( $dossiers as &$dossier ) {
		$statuts = array_column( $dossier['paiements'], 'statut' );
		$dossier['statut'] = 'verifie';
		foreach ( array( 'rejete', 'genere', 'recu_envoye' ) as $statut ) {
			if ( in_array( $statut, $statuts, true ) ) {
				$dossier['statut'] = $statut;
				break;
			}
		}
		usort( $dossier['paiements'], static fn( $a, $b ) => ( 'medicaux' === $a->type ) <=> ( 'medicaux' === $b->type ) );
	}
	unset( $dossier );
	return array_values( $dossiers );
}

/**
 * Où en est l'inscription de l'année en cours : le dossier suivi (le plus
 * urgent : à corriger, puis à payer, puis en vérification, puis vérifié),
 * l'étape atteinte sur quatre et l'action utile pour avancer.
 *
 * @return array focus (quitus cloné, statut et montant du dossier, ou null),
 *               en_cours (1 à 4, 5 quand tout est fait), etapes, prochaine
 *               (titre, texte), action (libelle, url, icone, principal) ou null.
 */
function ueb_parcours_inscription( $compte, ?array $quitus = null ) {
	$quitus = $quitus ?? ueb_quitus_du_compte( $compte->id );
	$annee  = ueb_annee_academique();
	$actuels = array_filter( ueb_dossiers_quitus( $quitus ), static fn( $d ) => $annee['code'] === $d['principal']->annee_academique );

	$focus = null;
	$paiement_focus = null;
	foreach ( array( 'rejete', 'genere', 'recu_envoye', 'verifie' ) as $statut ) {
		foreach ( $actuels as $dossier ) {
			if ( $statut !== $dossier['statut'] ) {
				continue;
			}
			$focus = clone $dossier['principal'];
			$focus->statut  = $statut;
			$focus->montant = $dossier['total'];
			foreach ( $dossier['paiements'] as $paiement ) {
				if ( $paiement->statut === $statut ) {
					$paiement_focus     = $paiement;
					$focus->motif_rejet = $paiement->motif_rejet;
					break;
				}
			}
			break 2;
		}
	}

	$etapes = array(
		array( 'titre' => 'Quitus généré', 'texte' => 'Remplis le formulaire : ton quitus et ses coupons sont réunis dans un PDF.' ),
		array( 'titre' => 'Tamponné et payé', 'texte' => 'Fais-le tamponner à la scolarité, puis paie à la ' . UEB_BANQUE['nom'] . '.' ),
		array( 'titre' => 'Reçu envoyé', 'texte' => 'Envoie ici la photo de ton reçu bancaire.' ),
		array( 'titre' => 'Vérifié', 'texte' => 'La scolarité contrôle les originaux et valide ton paiement.' ),
	);
	$en_cours = $focus ? array( 'genere' => 2, 'rejete' => 3, 'recu_envoye' => 4, 'verifie' => 5 )[ $focus->statut ] : 1;
	$url_recus = $paiement_focus ? ueb_url( 'mon-espace/recus/' . $paiement_focus->numero ) : '';
	$action = null;

	if ( ! $focus ) {
		$prochaine = array( 'titre' => 'Prépare ton quitus ' . $annee['libelle'], 'texte' => 'Remplis les quatre sections ci-dessous : ton PDF est généré à la fin.' );
	} elseif ( 'genere' === $focus->statut ) {
		$prochaine = array( 'titre' => 'Quitus à faire tamponner, puis à payer', 'texte' => 'Imprime-le, fais-le tamponner à la scolarité, puis paie à la ' . UEB_BANQUE['nom'] . '.' );
		$action = array( 'libelle' => 'Envoyer mon reçu', 'url' => $url_recus, 'icone' => 'envoyer', 'principal' => true );
	} elseif ( 'rejete' === $focus->statut ) {
		$prochaine = array( 'titre' => 'Reçu à corriger', 'texte' => 'La scolarité a signalé un problème : renvoie une photo lisible depuis Mes quitus.' );
		$action = array( 'libelle' => 'Renvoyer mon reçu', 'url' => $url_recus, 'icone' => 'envoyer', 'principal' => true );
	} elseif ( 'recu_envoye' === $focus->statut ) {
		$prochaine = array( 'titre' => 'Reçu en cours de vérification', 'texte' => 'Présente-toi à la scolarité avec les originaux.' );
		$action = array( 'libelle' => 'Voir mes reçus', 'url' => $url_recus, 'icone' => 'recu', 'principal' => false );
	} else {
		$prochaine = array( 'titre' => 'Paiement vérifié', 'texte' => sprintf( 'Validé par la scolarité (%s). Garde ton quitus tamponné et ton reçu.', mb_strtolower( ueb_detail_quitus( $focus ) ) ) );
	}

	return compact( 'focus', 'en_cours', 'etapes', 'prochaine', 'action' );
}

/** Retrouver les deux paiements depuis l'un ou l'autre quitus du compte. */
function ueb_dossier_du_quitus( $quitus ) {
	foreach ( ueb_dossiers_quitus( ueb_quitus_du_compte( $quitus->compte_id ) ) as $dossier ) {
		foreach ( $dossier['paiements'] as $paiement ) {
			if ( (int) $paiement->id === (int) $quitus->id ) {
				return $dossier;
			}
		}
	}
	return null;
}

/** Déclenchement unique, après un enregistrement réussi, pour ce compte seulement. */
function ueb_telechargement_a_demarrer( $compte ) {
	$demande = $_SESSION['ueb_telechargement'] ?? array();
	unset( $_SESSION['ueb_telechargement'] );
	if ( (int) ( $demande['compte_id'] ?? 0 ) !== (int) $compte->id ) {
		return null;
	}
	return ueb_quitus_du_compte_par_numero( $compte->id, $demande['numero'] ?? '' );
}
