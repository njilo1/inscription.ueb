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
		"SELECT id, numero, tranche, situation, statut FROM ueb_insc_quitus
		 WHERE compte_id = %d AND annee_academique = %s AND type = 'droits' ORDER BY id",
		$compte->id, $annee
	) );
	$situation = $nouveau ? 'nouveau' : ( $medical->situation ?? ( $droits[0]->situation ?? '' ) );
	if ( ! $nouveau && ! isset( UEB_FRAIS_MEDICAUX[ $situation ] ) ) {
		$situation = $medical && 5000 === (int) $medical->montant ? 'reprise' : 'ancien';
	}
	$tranches = array( 1 => 'Première tranche', 2 => 'Deuxième tranche', 3 => 'Les deux tranches' );
	$premiere_preparee = false;
	foreach ( $droits as $q ) {
		if ( $edite && (int) $q->id === (int) $edite->id ) {
			continue;
		}
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
	);
}

/** Les coordonnées CMS ne sont obligatoires que si les fiches sont générées. */
function ueb_fiches_cms_requises( array $contexte, $situation, $type = 'droits' ) {
	return 'medicaux' === $type || ( $contexte['medical_inclus'] && 'nouveau' !== $situation );
}

/** Montants calculés sans faire confiance au tarif ou au type de formation postés. */
function ueb_calculer_paiement( $formation, $tranche, $montant, $situation, array $contexte, $type = 'droits' ) {
	$droits = $formation && 'classique' === $formation->type_formation
		? ( in_array( (int) $tranche, array( 1, 2, 3 ), true ) ? ( 3 === (int) $tranche ? UEB_DROITS_CLASSIQUES : (int) ( UEB_DROITS_CLASSIQUES / 2 ) ) : 0 )
		: (int) $montant;
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
