<?php
/**
 * Espace d'administration (/gestion) : suivi des quitus, vérification des
 * paiements, comptes étudiants (réinitialisation du mot de passe, suspension).
 *
 * Accès : comptes WordPress administrateurs (partagés avec la préinscription).
 * Chaque action revérifie la capacité : les formulaires sont traités avant
 * le contrôle d'accès des pages.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

function ueb_est_gestionnaire() {
	return is_user_logged_in()
		&& ( current_user_can( 'manage_options' ) || current_user_can( UEB_CAP_GESTION ) )
		&& ! ueb_agent_suspendu();
}

function ueb_exiger_gestionnaire() {
	if ( ! ueb_est_gestionnaire() ) {
		wp_die( 'Action réservée à l’administration et aux scolarités.', 'Accès refusé', array( 'response' => 403 ) );
	}
}

function ueb_exiger_admin() {
	if ( ! ueb_est_admin_ueb() ) {
		wp_die( 'Action réservée aux administrateurs.', 'Accès refusé', array( 'response' => 403 ) );
	}
}

/** Un agent de scolarité n'agit que sur les quitus de son établissement. */
function ueb_exiger_etab( $sigle ) {
	if ( ! ueb_peut_gerer_etab( $sigle ) ) {
		wp_die( 'Ce quitus relève d’un autre établissement.', 'Accès refusé', array( 'response' => 403 ) );
	}
}

/* ---------- Lecture ---------- */

/**
 * Nombre de quitus de l'année par statut et par établissement.
 * $etab limite le calcul à un établissement (agent de scolarité).
 */
function ueb_gestion_stats( $annee_code, $etab = '' ) {
	global $wpdb;
	$sql    = 'SELECT etablissement, statut, COUNT(*) AS n, SUM(montant) AS total
		   FROM ueb_insc_quitus WHERE annee_academique = %s';
	$params = array( $annee_code );
	if ( $etab ) {
		$sql     .= ' AND etablissement = %s';
		$params[] = $etab;
	}
	$lignes = $wpdb->get_results( $wpdb->prepare( $sql . ' GROUP BY etablissement, statut', $params ) );
	$stats = array( 'statuts' => array_fill_keys( array_keys( UEB_STATUTS_QUITUS ), 0 ), 'etabs' => array(), 'total' => 0, 'montant_verifie' => 0 );
	foreach ( $lignes as $l ) {
		$stats['statuts'][ $l->statut ]            += (int) $l->n;
		$stats['etabs'][ $l->etablissement ][ $l->statut ] = (int) $l->n;
		$stats['total']                            += (int) $l->n;
		if ( 'verifie' === $l->statut ) {
			$stats['montant_verifie'] += (int) $l->total;
		}
	}
	$stats['comptes'] = $etab
		? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT compte_id) FROM ueb_insc_quitus WHERE etablissement = %s', $etab ) )
		: (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ueb_insc_comptes' );
	return $stats;
}

/**
 * Chiffres d'un tableau de bord, calculés sur les quitus de l'année.
 * $etab limite à un établissement ; vide = toute l'université.
 *
 * « Payé » signifie ici : quitus vérifié par la scolarité. Un étudiant est
 * compté une seule fois par tranche, et « la totalité » couvre aussi bien un
 * quitus « les deux tranches » que deux quitus vérifiés séparément.
 *
 * @return array<string, mixed>
 */
function ueb_gestion_chiffres( $annee_code, $etab = '' ) {
	global $wpdb;
	$ou     = 'annee_academique = %s';
	$params = array( $annee_code );
	if ( $etab ) {
		$ou      .= ' AND etablissement = %s';
		$params[] = $etab;
	}

	$chiffres = array(
		'etudiants'       => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT compte_id) FROM ueb_insc_quitus WHERE $ou", $params ) ), // phpcs:ignore
		'quitus'          => 0,
		'recus_envoyes'   => 0,
		'recus_verifies'  => 0,
		'recus_rejetes'   => 0,
		'a_payer'         => 0,
		'montant_verifie' => 0,
		'sexe'            => array( 'M' => 0, 'F' => 0 ),
		'tranches'        => array( 'tranche1' => 0, 'tranche2' => 0, 'totalite' => 0 ),
	);

	foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT statut, COUNT(*) n, SUM(montant) total FROM ueb_insc_quitus WHERE $ou GROUP BY statut", $params ) ) as $l ) { // phpcs:ignore
		$chiffres['quitus'] += (int) $l->n;
		if ( 'recu_envoye' === $l->statut ) {
			$chiffres['recus_envoyes'] = (int) $l->n;
		} elseif ( 'verifie' === $l->statut ) {
			$chiffres['recus_verifies']  = (int) $l->n;
			$chiffres['montant_verifie'] = (int) $l->total;
		} elseif ( 'rejete' === $l->statut ) {
			$chiffres['recus_rejetes'] = (int) $l->n;
		} elseif ( 'genere' === $l->statut ) {
			$chiffres['a_payer'] = (int) $l->n;
		}
	}

	/* Répartition par sexe : un étudiant compte une fois, d'après son dernier quitus. */
	foreach ( (array) $wpdb->get_results( $wpdb->prepare(
		"SELECT sexe, COUNT(DISTINCT compte_id) n FROM ueb_insc_quitus WHERE $ou GROUP BY sexe", // phpcs:ignore
		$params
	) ) as $l ) {
		if ( isset( $chiffres['sexe'][ $l->sexe ] ) ) {
			$chiffres['sexe'][ $l->sexe ] = (int) $l->n;
		}
	}

	/* Tranches réglées, d'après les quitus vérifiés (3 = les deux tranches). */
	$payees = array();
	foreach ( (array) $wpdb->get_results( $wpdb->prepare(
		"SELECT compte_id, tranche FROM ueb_insc_quitus WHERE $ou AND statut = 'verifie' AND type = 'droits'", // phpcs:ignore
		$params
	) ) as $l ) {
		$payees[ (int) $l->compte_id ][ (int) $l->tranche ] = true;
	}
	foreach ( $payees as $tranches ) {
		$une   = ! empty( $tranches[1] ) || ! empty( $tranches[3] );
		$deux  = ! empty( $tranches[2] ) || ! empty( $tranches[3] );
		$chiffres['tranches']['tranche1'] += $une ? 1 : 0;
		$chiffres['tranches']['tranche2'] += $deux ? 1 : 0;
		$chiffres['tranches']['totalite'] += ( $une && $deux ) ? 1 : 0;
	}

	return $chiffres;
}

/**
 * Étudiants distincts par établissement pour l'année, en une seule requête
 * (le tableau de bord affiche les neuf établissements d'un coup).
 *
 * @return array<string, int> sigle => nombre d'étudiants
 */
function ueb_gestion_etudiants_par_etab( $annee_code ) {
	global $wpdb;
	$par_etab = array();
	foreach ( (array) $wpdb->get_results( $wpdb->prepare(
		'SELECT etablissement, COUNT(DISTINCT compte_id) AS n FROM ueb_insc_quitus WHERE annee_academique = %s GROUP BY etablissement',
		$annee_code
	) ) as $ligne ) {
		$par_etab[ $ligne->etablissement ] = (int) $ligne->n;
	}
	return $par_etab;
}

/**
 * Effectifs par filière d'un établissement, regroupés sur le département
 * saisi dans le quitus (texte libre : on normalise la casse et les espaces).
 *
 * @return array<int, object{filiere: string, n: int}>
 */
function ueb_gestion_par_filiere( $annee_code, $etab ) {
	global $wpdb;
	return (array) $wpdb->get_results( $wpdb->prepare(
		"SELECT UPPER(TRIM(departement)) AS filiere, COUNT(DISTINCT compte_id) AS n
		   FROM ueb_insc_quitus
		  WHERE annee_academique = %s AND etablissement = %s AND TRIM(departement) <> ''
		  GROUP BY filiere
		  ORDER BY n DESC, filiere ASC",
		$annee_code,
		$etab
	) );
}

/**
 * @return array{lignes: array, total: int, pages: int, page: int}
 */
function ueb_gestion_liste_quitus( array $filtres, $par_page = 30 ) {
	global $wpdb;
	/* Un agent de scolarité ne voit que son établissement, quel que soit le filtre demandé. */
	$limite = ueb_etab_agent();
	if ( $limite ) {
		$filtres['etab'] = $limite;
	}
	$where  = array( 'q.annee_academique = %s' );
	$params = array( $filtres['annee'] );
	if ( ! empty( $filtres['etab'] ) && ueb_etablissement( $filtres['etab'] ) ) {
		$where[]  = 'q.etablissement = %s';
		$params[] = $filtres['etab'];
	}
	if ( ! empty( $filtres['statut'] ) && isset( UEB_STATUTS_QUITUS[ $filtres['statut'] ] ) ) {
		$where[]  = 'q.statut = %s';
		$params[] = $filtres['statut'];
	}
	if ( ! empty( $filtres['q'] ) ) {
		$like     = '%' . $wpdb->esc_like( $filtres['q'] ) . '%';
		$where[]  = '(q.numero LIKE %s OR q.identifiant LIKE %s OR q.nom LIKE %s OR q.prenom LIKE %s)';
		array_push( $params, $like, $like, $like, $like );
	}
	$sql_where = implode( ' AND ', $where );
	$total     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM ueb_insc_quitus q WHERE $sql_where", $params ) );
	$pages     = max( 1, (int) ceil( $total / $par_page ) );
	$page      = min( max( 1, (int) ( $filtres['page'] ?? 1 ) ), $pages );
	$lignes    = $wpdb->get_results( $wpdb->prepare(
		"SELECT q.*, c.telephone, (SELECT COUNT(*) FROM ueb_insc_recus r WHERE r.quitus_id = q.id) AS nb_recus
		   FROM ueb_insc_quitus q JOIN ueb_insc_comptes c ON c.id = q.compte_id
		  WHERE $sql_where
		  ORDER BY FIELD(q.statut, 'recu_envoye', 'rejete', 'genere', 'verifie'), q.date_modification DESC
		  LIMIT %d OFFSET %d",
		array_merge( $params, array( $par_page, ( $page - 1 ) * $par_page ) )
	) );
	return compact( 'lignes', 'total', 'pages', 'page' );
}

/**
 * Recherche d'étudiants : un seul champ qui cherche dans le matricule, le
 * numéro de dossier, le téléphone, le nom et le prénom, plus un filtre sur
 * le paiement. $etab limite à un établissement.
 *
 * @param array $filtres q (texte), paiement ('paye' | 'non_paye' | '')
 * @return array<int, object>
 */
function ueb_gestion_chercher_etudiants( array $filtres, $etab = '' ) {
	global $wpdb;
	$params   = array();
	$jointure = 'LEFT JOIN ueb_insc_quitus q ON q.compte_id = c.id';
	if ( $etab ) {
		$jointure .= ' AND q.etablissement = %s';
		$params[]  = $etab;
	}

	$where = array( '1 = 1' );
	$texte = trim( (string) ( $filtres['q'] ?? '' ) );
	if ( '' !== $texte ) {
		$like = '%' . $wpdb->esc_like( $texte ) . '%';
		$maj  = '%' . $wpdb->esc_like( ueb_normaliser_identifiant( $texte ) ) . '%';
		$tel  = ueb_normaliser_telephone( $texte );
		$where[] = '( c.matricule LIKE %s OR c.numero_dossier LIKE %s OR c.telephone = %s
			OR EXISTS ( SELECT 1 FROM ueb_insc_quitus r WHERE r.compte_id = c.id AND ( r.nom LIKE %s OR r.prenom LIKE %s ) ) )';
		array_push( $params, $maj, $maj, $tel ?: '-', $like, $like );
	}

	$ayant = '';
	if ( 'paye' === ( $filtres['paiement'] ?? '' ) ) {
		$ayant = 'HAVING verifies > 0';
	} elseif ( 'non_paye' === ( $filtres['paiement'] ?? '' ) ) {
		$ayant = 'HAVING verifies = 0';
	}
	if ( $etab ) {
		$ayant = $ayant ? $ayant . ' AND quitus > 0' : 'HAVING quitus > 0';
	}

	$sql = "SELECT c.*, COUNT(q.id) AS quitus,
			SUM( q.statut = 'verifie' ) AS verifies,
			SUM( q.statut = 'recu_envoye' ) AS en_attente,
			MAX( q.nom ) AS nom, MAX( q.prenom ) AS prenom
		   FROM ueb_insc_comptes c $jointure
		  WHERE " . implode( ' AND ', $where ) . "
		  GROUP BY c.id $ayant
		  ORDER BY c.date_creation DESC
		  LIMIT 100";

	return (array) ( $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql ) ); // phpcs:ignore
}

/**
 * Comptes étudiants. $etab limite aux étudiants ayant au moins un quitus
 * dans cet établissement (agent de scolarité).
 */
function ueb_gestion_chercher_comptes( $recherche, $etab = '' ) {
	global $wpdb;
	$recherche = trim( (string) $recherche );
	$jointure  = $etab ? 'JOIN ueb_insc_quitus q ON q.compte_id = c.id AND q.etablissement = %s' : '';
	$params    = $etab ? array( $etab ) : array();
	if ( '' === $recherche ) {
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT DISTINCT c.* FROM ueb_insc_comptes c $jointure ORDER BY c.date_creation DESC LIMIT 30", // phpcs:ignore -- jointure sans saisie
			$params ?: array( 1 )
		) );
	}
	$tel  = ueb_normaliser_telephone( $recherche );
	$like = '%' . $wpdb->esc_like( ueb_normaliser_identifiant( $recherche ) ) . '%';
	return $wpdb->get_results( $wpdb->prepare(
		"SELECT DISTINCT c.* FROM ueb_insc_comptes c $jointure
		  WHERE c.matricule LIKE %s OR c.numero_dossier LIKE %s OR c.telephone = %s
		  ORDER BY c.date_creation DESC LIMIT 50", // phpcs:ignore -- jointure sans saisie
		array_merge( $params, array( $like, $like, $tel ?: '-' ) )
	) );
}

/* ---------- Actions ---------- */

function ueb_action_gestion_statut() {
	global $wpdb;
	ueb_exiger_gestionnaire();
	$quitus = ueb_quitus_par_id( (int) ( $_POST['quitus_id'] ?? 0 ) );
	$statut = sanitize_key( $_POST['statut'] ?? '' );
	$motif  = sanitize_text_field( wp_unslash( $_POST['motif'] ?? '' ) );
	if ( ! $quitus || ! in_array( $statut, array( 'verifie', 'rejete', 'recu_envoye' ), true ) ) {
		ueb_rediriger( ueb_url_scolarite() );
	}
	ueb_exiger_etab( $quitus->etablissement );
	$retour = add_query_arg( 'quitus', $quitus->id, ueb_url_scolarite() );
	if ( 'rejete' === $statut && mb_strlen( $motif ) < 5 ) {
		ueb_flash( 'erreur', "Indique le motif du rejet : l'étudiant le verra dans son espace." );
		ueb_rediriger( $retour );
	}
	$wpdb->update( 'ueb_insc_quitus', array(
		'statut'            => $statut,
		'motif_rejet'       => 'rejete' === $statut ? $motif : null,
		'verifie_par'       => 'recu_envoye' === $statut ? null : get_current_user_id(),
		'date_verification' => 'recu_envoye' === $statut ? null : current_time( 'mysql' ),
	), array( 'id' => $quitus->id ) );
	$messages = array(
		'verifie'     => "Paiement du quitus {$quitus->numero} vérifié.",
		'rejete'      => "Quitus {$quitus->numero} renvoyé à l'étudiant pour correction.",
		'recu_envoye' => "Quitus {$quitus->numero} remis en attente de vérification.",
	);
	ueb_flash( 'succes', $messages[ $statut ] );
	ueb_rediriger( $retour );
}

/** Mot de passe provisoire lisible (sans 0/O ni 1/l/I). */
function ueb_mot_de_passe_provisoire() {
	$lettres  = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ';
	$chiffres = '23456789';
	$mdp      = '';
	for ( $i = 0; $i < 7; $i++ ) {
		$mdp .= $lettres[ random_int( 0, strlen( $lettres ) - 1 ) ];
	}
	for ( $i = 0; $i < 3; $i++ ) {
		$mdp .= $chiffres[ random_int( 0, strlen( $chiffres ) - 1 ) ];
	}
	return str_shuffle( $mdp );
}

function ueb_action_gestion_reinit_mdp() {
	global $wpdb;
	ueb_exiger_gestionnaire();
	$compte = ueb_compte_par_id( (int) ( $_POST['compte_id'] ?? 0 ) );
	$retour = add_query_arg( array( 'vue' => 'comptes', 'qc' => sanitize_text_field( wp_unslash( $_POST['q'] ?? '' ) ) ), ueb_url_scolarite() );
	if ( ! $compte ) {
		ueb_rediriger( $retour );
	}
	$provisoire = ueb_mot_de_passe_provisoire();
	$wpdb->update( 'ueb_insc_comptes', array(
		'mot_de_passe'     => password_hash( $provisoire, PASSWORD_DEFAULT ),
		'doit_changer_mdp' => 1,
		'version_session'  => (int) $compte->version_session + 1,
	), array( 'id' => $compte->id ) );
	$_SESSION['ueb_mdp_provisoire'] = array( 'compte' => ueb_identifiant_compte( $compte ), 'mdp' => $provisoire );
	ueb_rediriger( $retour );
}

/**
 * Création d'un compte étudiant par la scolarité, quand l'étudiant ne peut
 * pas le faire lui-même (pas de téléphone, par exemple). Le téléphone est
 * donc facultatif ici, et le mot de passe provisoire s'affiche une fois.
 */
function ueb_action_gestion_creer_etudiant() {
	global $wpdb;
	ueb_exiger_gestionnaire();
	$retour      = add_query_arg( 'vue', 'comptes', ueb_url_scolarite() );
	$identifiant = ueb_normaliser_identifiant( wp_unslash( $_POST['identifiant'] ?? '' ) );
	$type        = ueb_type_identifiant( $identifiant );
	$tel_saisi   = sanitize_text_field( wp_unslash( $_POST['telephone'] ?? '' ) );
	$telephone   = $tel_saisi ? ueb_normaliser_telephone( $tel_saisi ) : '';

	if ( ! $type ) {
		ueb_flash( 'erreur', 'Matricule ou numéro de dossier non reconnu. Exemples : 24I0017FS, UEB-2026-000123.' );
		ueb_rediriger( $retour );
	}
	if ( $tel_saisi && ! $telephone ) {
		ueb_flash( 'erreur', 'Numéro mobile camerounais attendu : 9 chiffres commençant par 6.' );
		ueb_rediriger( $retour );
	}
	if ( ueb_compte_par_identifiant( $identifiant ) ) {
		ueb_flash( 'erreur', "Un compte existe déjà avec cet identifiant." );
		ueb_rediriger( $retour );
	}

	$provisoire = ueb_mot_de_passe_provisoire();
	$ok         = $wpdb->insert( 'ueb_insc_comptes', array(
		'matricule'        => 'matricule' === $type ? $identifiant : null,
		'numero_dossier'   => 'dossier' === $type ? $identifiant : null,
		'telephone'        => $telephone ?: '',
		'mot_de_passe'     => password_hash( $provisoire, PASSWORD_DEFAULT ),
		'doit_changer_mdp' => 1,
	) );
	if ( ! $ok ) {
		error_log( '[inscription-ueb] Création du compte étudiant impossible : ' . $wpdb->last_error );
		ueb_flash( 'erreur', "Le compte n'a pas pu être créé. Réessaie dans un instant." );
		ueb_rediriger( $retour );
	}
	$_SESSION['ueb_mdp_provisoire'] = array( 'compte' => $identifiant, 'mdp' => $provisoire );
	ueb_flash( 'succes', "Compte $identifiant créé." );
	ueb_rediriger( $retour );
}

function ueb_action_gestion_bloquer() {
	global $wpdb;
	ueb_exiger_gestionnaire();
	$compte = ueb_compte_par_id( (int) ( $_POST['compte_id'] ?? 0 ) );
	$retour = add_query_arg( array( 'vue' => 'comptes', 'qc' => sanitize_text_field( wp_unslash( $_POST['q'] ?? '' ) ) ), ueb_url_scolarite() );
	if ( $compte ) {
		$nouveau = 'actif' === $compte->statut ? 'bloque' : 'actif';
		$wpdb->update( 'ueb_insc_comptes', array( 'statut' => $nouveau, 'version_session' => (int) $compte->version_session + 1 ), array( 'id' => $compte->id ) );
		ueb_flash( 'succes', 'bloque' === $nouveau ? 'Compte suspendu : l’étudiant est déconnecté.' : 'Compte réactivé.' );
	}
	ueb_rediriger( $retour );
}

/* ---------- Comptes de scolarité (administrateur seulement) ---------- */

function ueb_action_gestion_creer_agent() {
	ueb_exiger_admin();
	$retour = ueb_url_administration();
	$agent  = ueb_creer_agent(
		sanitize_text_field( wp_unslash( $_POST['login'] ?? '' ) ),
		sanitize_text_field( wp_unslash( $_POST['nom'] ?? '' ) ),
		sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
		sanitize_text_field( wp_unslash( $_POST['etablissement'] ?? '' ) )
	);
	if ( is_wp_error( $agent ) ) {
		ueb_flash( 'erreur', $agent->get_error_message() );
		ueb_rediriger( $retour );
	}
	list( $id, $provisoire ) = $agent;
	$_SESSION['ueb_mdp_agent'] = array( 'compte' => get_userdata( $id )->user_login, 'mdp' => $provisoire );
	ueb_flash( 'succes', 'Compte de scolarité créé.' );
	ueb_rediriger( $retour );
}

function ueb_action_gestion_agent_mdp() {
	ueb_exiger_admin();
	$retour = ueb_url_administration();
	$id     = (int) ( $_POST['agent_id'] ?? 0 );
	if ( ! ueb_est_agent( $id ) ) {
		ueb_rediriger( $retour );
	}
	$provisoire = ueb_mot_de_passe_provisoire();
	wp_set_password( $provisoire, $id );
	$_SESSION['ueb_mdp_agent'] = array( 'compte' => get_userdata( $id )->user_login, 'mdp' => $provisoire );
	ueb_rediriger( $retour );
}

function ueb_action_gestion_agent_etat() {
	ueb_exiger_admin();
	$retour = ueb_url_administration();
	$id     = (int) ( $_POST['agent_id'] ?? 0 );
	if ( ! ueb_est_agent( $id ) ) {
		ueb_rediriger( $retour );
	}
	if ( ueb_agent_suspendu( $id ) ) {
		delete_user_meta( $id, 'ueb_agent_suspendu' );
		ueb_flash( 'succes', 'Accès rétabli.' );
	} else {
		update_user_meta( $id, 'ueb_agent_suspendu', 1 );
		ueb_flash( 'succes', 'Accès suspendu : le compte est conservé pour garder la trace de ses décisions.' );
	}
	ueb_rediriger( $retour );
}

/* ---------- Entrées dans WordPress ----------
   Les pages du back-office vivent sur le site public (adresses propres, avec
   des numéros variables) : on y renvoie par des liens plutôt que par des
   pages WordPress. */

/** Modifier l'établissement d'un agent. */
function ueb_action_gestion_agent_modifier() {
	ueb_exiger_admin();
	$retour = ueb_url_administration();
	$id     = (int) ( $_POST['agent_id'] ?? 0 );
	$etab   = strtoupper( sanitize_text_field( wp_unslash( $_POST['etablissement'] ?? '' ) ) );
	if ( ! ueb_est_agent( $id ) || ! ueb_etablissement( $etab ) ) {
		ueb_rediriger( $retour );
	}
	update_user_meta( $id, 'ueb_etablissement', $etab );
	ueb_flash( 'succes', sprintf( 'Compte rattaché à %s.', ueb_etablissement( $etab )['fr'] ) );
	ueb_rediriger( $retour );
}

/** Supprimer un compte de scolarité ; les décisions déjà prises restent en base. */
function ueb_action_gestion_agent_supprimer() {
	ueb_exiger_admin();
	$retour = ueb_url_administration();
	$id     = (int) ( $_POST['agent_id'] ?? 0 );
	if ( ! ueb_est_agent( $id ) || $id === get_current_user_id() ) {
		ueb_rediriger( $retour );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	$login = get_userdata( $id )->user_login;
	wp_delete_user( $id );
	ueb_flash( 'succes', "Compte $login supprimé." );
	ueb_rediriger( $retour );
}

/** Après connexion, un gestionnaire arrive directement sur son espace. */
add_filter( 'login_redirect', function ( $url, $demande, $utilisateur ) {
	if ( $demande ) {
		return $url; /* il venait d'une page précise : on l'y ramène */
	}
	if ( $utilisateur instanceof WP_User && user_can( $utilisateur, UEB_CAP_GESTION ) && ! user_can( $utilisateur, 'manage_options' ) ) {
		return ueb_url_scolarite();
	}
	return $url;
}, 10, 3 );

/* PDF d'un quitus depuis l'espace scolarité : ?quitus={id}&pdf=1 */
add_action( 'template_redirect', function () {
	if ( ! isset( $_GET['pdf'], $_GET['quitus'] ) || ! is_page_template( 'page-scolarite.php' ) || ! ueb_est_gestionnaire() ) {
		return;
	}
	$quitus = ueb_quitus_par_id( (int) $_GET['quitus'] );
	if ( $quitus && ueb_peut_gerer_etab( $quitus->etablissement ) ) {
		ueb_envoyer_pdf_quitus( $quitus );
	}
}, 20 );
