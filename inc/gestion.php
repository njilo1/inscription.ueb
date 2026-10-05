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

/* Toutes les vérifications passent par ueb_peut() (inc/roles.php) : une
   capacité, la portée par établissement, et le compte non suspendu. Jamais
   un nom de rôle. */

function ueb_est_gestionnaire() {
	return ueb_peut( UEB_CAP_GESTION );
}

/* ---------- Qui voit et qui valide quel reçu ----------
   Chaque étudiant envoie deux reçus : celui des droits universitaires va à la
   scolarité (ueb_gerer_quitus, ueb_decider_quitus), celui des frais médicaux
   au CMS (ueb_voir_cms, ueb_decider_cms). Chacun ne voit et ne valide que les
   siens, dans sa portée ; l'administrateur voit tout. */

/** Permissions de consultation et de décision de chaque type de quitus. */
const UEB_PERMISSIONS_TYPE_QUITUS = array(
	'droits'   => array( 'ueb_gerer_quitus', 'ueb_decider_quitus' ), // UEB_CAP_GESTION (inc/roles.php)
	'medicaux' => array( 'ueb_voir_cms', 'ueb_decider_cms' ),
);

/** Types de quitus que le compte consulte (« droits », « medicaux »). */
function ueb_types_quitus_visibles( $etab = null ) {
	return array_keys( array_filter( UEB_PERMISSIONS_TYPE_QUITUS, static fn( $p ) => ueb_peut( $p[0], $etab ) ) );
}

function ueb_type_du_quitus( $quitus ) {
	return 'medicaux' === ( $quitus->type ?? 'droits' ) ? 'medicaux' : 'droits';
}

/** Le compte peut-il consulter ce quitus (son type et son établissement) ? */
function ueb_peut_voir_quitus( $quitus ) {
	return $quitus && ueb_peut( UEB_PERMISSIONS_TYPE_QUITUS[ ueb_type_du_quitus( $quitus ) ][0], $quitus->etablissement );
}

/** Le compte peut-il valider ou renvoyer le reçu de ce quitus ? */
function ueb_peut_decider_quitus( $quitus ) {
	$p = UEB_PERMISSIONS_TYPE_QUITUS[ ueb_type_du_quitus( $quitus ) ];
	return $quitus && ueb_peut( $p[0], $quitus->etablissement ) && ueb_peut( $p[1], $quitus->etablissement );
}

/** Décision sur un quitus : consulter ET décider au moins un type ; le quitus est contrôlé ensuite. */
function ueb_exiger_gestionnaire() {
	foreach ( UEB_PERMISSIONS_TYPE_QUITUS as $p ) {
		if ( ueb_peut( $p[0] ) && ueb_peut( $p[1] ) ) {
			return;
		}
	}
	wp_die( 'Action réservée aux comptes autorisés à rendre les décisions.', 'Accès refusé', array( 'response' => 403 ) );
}

function ueb_exiger_admin() {
	if ( ! ueb_est_admin_ueb() ) {
		wp_die( 'Action réservée aux administrateurs.', 'Accès refusé', array( 'response' => 403 ) );
	}
}

/** Accès aux opérations réservées à la cellule informatique. */
function ueb_exiger_comptes() {
	if ( ! ueb_peut( UEB_CAP_COMPTES ) ) {
		wp_die( 'Action réservée aux comptes autorisés à gérer les comptes étudiants.', 'Accès refusé', array( 'response' => 403 ) );
	}
}

/** Création de comptes pour l'établissement consulté (ex. la cellule d'une scolarité). */
function ueb_exiger_scolarite() {
	$etab = ueb_etab_agent();
	if ( ! ueb_peut( 'ueb_creer_agents' ) || ! ueb_etablissement( $etab ) || ! ueb_peut_gerer_etab( $etab ) ) {
		wp_die( 'Action réservée aux comptes autorisés à créer des comptes pour leur établissement.', 'Accès refusé', array( 'response' => 403 ) );
	}
}

function ueb_action_gestion_creer_cellule() {
	ueb_exiger_scolarite();
	$retour = add_query_arg( 'vue', 'cellule', ueb_url_scolarite() );
	/* Rôle choisi parmi ceux que ce compte peut attribuer pour son établissement. */
	$role = sanitize_key( wp_unslash( $_POST['role'] ?? '' ) );
	if ( $role && ! isset( ueb_roles_attribuables( true )[ $role ] ) ) {
		ueb_flash( 'erreur', 'Ce rôle ne peut pas être attribué depuis ton espace.' );
		ueb_rediriger( $retour );
	}
	$cellule = ueb_creer_cellule(
		sanitize_text_field( wp_unslash( $_POST['login'] ?? '' ) ),
		sanitize_text_field( wp_unslash( $_POST['nom'] ?? '' ) ),
		sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
		ueb_etab_agent(),
		$role
	);
	if ( is_wp_error( $cellule ) ) {
		ueb_flash( 'erreur', $cellule->get_error_message() );
		ueb_rediriger( $retour );
	}
	list( $id, $provisoire ) = $cellule;
	$_SESSION['ueb_mdp_cellule'] = array( 'compte' => get_userdata( $id )->user_login, 'mdp' => $provisoire );
	ueb_flash( 'succes', 'Compte créé.' );
	ueb_rediriger( $retour );
}

/** Vérifie qu'un compte étudiant possède un quitus dans l'établissement courant. */
function ueb_compte_dans_etab( $compte_id, $etab ) {
	global $wpdb;
	return ! $etab || (bool) $wpdb->get_var( $wpdb->prepare(
		' SELECT id FROM ueb_insc_quitus WHERE compte_id = %d AND etablissement = %s LIMIT 1',
		(int) $compte_id,
		$etab
	) );
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
	$types  = ueb_types_quitus_visibles();
	$sql    = 'SELECT etablissement, statut, COUNT(*) AS n, SUM(montant) AS total
		   FROM ueb_insc_quitus WHERE annee_academique = %s AND type IN (' . ( $types ? implode( ',', array_fill( 0, count( $types ), '%s' ) ) : "''" ) . ')';
	$params = array_merge( array( $annee_code ), $types );
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

/** Effectifs étudiants par établissement et par niveau d'inscription. */
function ueb_gestion_niveaux_par_etab( $annee_code ) {
	global $wpdb;
	$par_etab = array();
	$lignes   = $wpdb->get_results( $wpdb->prepare(
		"SELECT etablissement, UPPER(TRIM(parcours)) AS niveau, COUNT(DISTINCT compte_id) AS n
		   FROM ueb_insc_quitus
		  WHERE annee_academique = %s AND TRIM(parcours) <> ''
		  GROUP BY etablissement, niveau
		  ORDER BY etablissement ASC, niveau ASC",
		$annee_code
	) );
	foreach ( (array) $lignes as $ligne ) {
		$par_etab[ $ligne->etablissement ][ $ligne->niveau ] = (int) $ligne->n;
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
 * Progression de l'année, jour par jour et en cumul : quitus générés, quitus
 * dont un premier reçu a été envoyé, quitus vérifiés. Chaque quitus compte une
 * fois par courbe (un renvoi après correction ne le recompte pas).
 *
 * La fenêtre couvre au plus $jours_max jours jusqu'à aujourd'hui ; ce qui la
 * précède est reporté dans la valeur de départ, pour que les cumuls restent justes.
 *
 * Un quitus passé par la vérification sans reçu en base (ancien dossier)
 * compte comme envoyé le jour de sa décision : la courbe des envois ne passe
 * jamais sous celle des vérifiés.
 *
 * @return array{jours: string[], generes: int[], envoyes: int[], verifies: int[]}
 */
function ueb_gestion_activite( $annee_code, $etab = '', $jours_max = 45 ) {
	global $wpdb;
	$ou     = 'q.annee_academique = %s';
	$params = array( $annee_code );
	if ( $etab ) {
		$ou      .= ' AND q.etablissement = %s';
		$params[] = $etab;
	}
	$lignes = (array) $wpdb->get_results( $wpdb->prepare(
		"SELECT DATE(q.date_creation) AS genere,
		        COALESCE(
		            (SELECT DATE(MIN(r.date_envoi)) FROM ueb_insc_recus r WHERE r.quitus_id = q.id),
		            IF(q.statut IN ('recu_envoye', 'verifie', 'rejete'), DATE(COALESCE(q.date_verification, q.date_modification)), NULL)
		        ) AS envoye,
		        IF(q.statut = 'verifie', DATE(COALESCE(q.date_verification, q.date_modification)), NULL) AS verifie
		   FROM ueb_insc_quitus q
		  WHERE $ou", // phpcs:ignore
		$params
	) );

	$aujourdhui = current_time( 'Y-m-d' );
	$premier    = $aujourdhui;
	foreach ( $lignes as $l ) {
		$premier = min( $premier, $l->genere );
		/* Dates incohérentes en base (décision datée avant la création) : un
		   envoi ne précède jamais le quitus, une vérification jamais l'envoi. */
		$l->envoye  = $l->envoye ? max( $l->envoye, $l->genere ) : null;
		$l->verifie = $l->verifie ? max( $l->verifie, (string) $l->envoye, $l->genere ) : null;
	}
	$debut = max( $premier, gmdate( 'Y-m-d', strtotime( $aujourdhui . ' -' . ( max( 2, (int) $jours_max ) - 1 ) . ' days' ) ) );

	$jours = array();
	for ( $t = strtotime( $debut ); $t <= strtotime( $aujourdhui ); $t += DAY_IN_SECONDS ) {
		$jours[] = gmdate( 'Y-m-d', $t );
	}
	$index  = array_flip( $jours );
	$series = array();
	foreach ( array( 'generes' => 'genere', 'envoyes' => 'envoye', 'verifies' => 'verifie' ) as $serie => $colonne ) {
		$par_jour = array_fill( 0, count( $jours ), 0 );
		foreach ( $lignes as $l ) {
			if ( ! $l->$colonne ) {
				continue;
			}
			/* Avant la fenêtre : reporté au premier jour ; jamais après aujourd'hui. */
			$par_jour[ $index[ max( $debut, min( $aujourdhui, $l->$colonne ) ) ] ]++;
		}
		$cumul = 0;
		foreach ( $par_jour as $i => $n ) {
			$cumul          += $n;
			$par_jour[ $i ] = $cumul;
		}
		$series[ $serie ] = $par_jour;
	}
	return array( 'jours' => $jours ) + $series;
}

/* ---------- Suivi des paiements ----------
 *
 * Règles de calcul (affichées aussi sur la page, pour que les chiffres
 * soient vérifiables) :
 *   - un étudiant compte dès qu'il a au moins un quitus de droits
 *     universitaires dans l'année, rattaché à l'établissement et à la
 *     filière de son quitus le plus récent ;
 *   - montant attendu : 50 000 FCFA pour une formation classique ; pour une
 *     formation professionnelle, le total des quitus qu'il a préparés (le
 *     tarif est communiqué par l'établissement) ;
 *   - encaissé : les quitus « vérifiés » par la scolarité, plafonnés au
 *     montant attendu (un trop-perçu n'augmente pas le taux) ;
 *   - le reste de l'attendu se répartit en « en vérification » (reçu envoyé),
 *     « déclaré » (quitus généré ou reçu à corriger) et « pas encore déclaré » ;
 *     les quatre parts font toujours 100 % ;
 *   - taux de recouvrement = encaissé / attendu ;
 *   - soldé : encaissé = attendu ; partiel : encaissé > 0 ; aucun : 0.
 *   - frais médicaux : suivis à part (montant fixe, compte des services centraux).
 */

/** Agrégat vide du suivi des paiements. */
function ueb_suivi_vide() {
	return array( 'etudiants' => 0, 'attendu' => 0, 'encaisse' => 0, 'verification' => 0, 'declare' => 0, 'non_declare' => 0, 'soldes' => 0, 'partiels' => 0, 'aucun' => 0, 'trop_percu' => 0 );
}

/** Ajoute un étudiant (déjà ventilé) à un agrégat. */
function ueb_suivi_ajouter( array &$agregat, array $e ) {
	$agregat['etudiants']++;
	foreach ( array( 'attendu', 'encaisse', 'verification', 'declare', 'non_declare', 'trop_percu' ) as $cle ) {
		$agregat[ $cle ] += $e[ $cle ];
	}
	$agregat[ $e['attendu'] > 0 && $e['encaisse'] >= $e['attendu'] ? 'soldes' : ( $e['encaisse'] > 0 ? 'partiels' : 'aucun' ) ]++;
}

/** Taux d'un agrégat, en pourcentage (0 à 100). */
function ueb_suivi_taux( array $a, $cle = 'encaisse' ) {
	return $a['attendu'] > 0 ? 100 * $a[ $cle ] / $a['attendu'] : 0;
}

/**
 * Suivi des paiements de l'année : global, par établissement, par filière,
 * par niveau, et frais médicaux. $etab limite à un établissement (scolarité).
 */
function ueb_suivi_paiements( $annee_code, $etab = '', $jours_historique = 0 ) {
	global $wpdb;
	$etab   = ueb_etab_agent() ?: $etab;
	$filtre = $etab ? $wpdb->prepare( ' AND q.etablissement = %s', $etab ) : '';
	$lignes = $wpdb->get_results( $wpdb->prepare(
		"SELECT q.compte_id, q.etablissement, q.type, q.filiere_id, q.departement, q.parcours, q.montant, q.statut, q.date_creation, q.date_verification, q.date_modification, q.id,
		        fi.libelle AS filiere_libelle, fi.type_formation
		   FROM ueb_insc_quitus q
		   LEFT JOIN ueb_filieres fi ON fi.id = q.filiere_id
		  WHERE q.annee_academique = %s $filtre
		  ORDER BY q.date_creation ASC, q.id ASC",
		$annee_code
	) );

	/* Anciens quitus sans identifiant de filière : on rapproche le texte saisi
	   (« tic », « TIC — … ») d'une filière de l'établissement, par code ou libellé. */
	$catalogue = array();
	foreach ( $wpdb->get_results( 'SELECT fi.id, fi.code, fi.libelle, fi.type_formation, f.code AS etab FROM ueb_filieres fi JOIN ueb_facultes f ON f.id = fi.faculte_id' ) as $fi ) {
		$catalogue[ $fi->etab ][] = $fi;
	}
	$rapprocher = static function ( $etab, $texte ) use ( $catalogue ) {
		$t = mb_strtoupper( trim( (string) $texte ) );
		if ( '' === $t ) {
			return null;
		}
		foreach ( $catalogue[ $etab ] ?? array() as $fi ) {
			$code = mb_strtoupper( $fi->code );
			if ( $t === $code || $t === mb_strtoupper( $fi->libelle ) || str_starts_with( $t, $code . ' ' ) || str_starts_with( $t, $code . ' —' ) ) {
				return $fi;
			}
		}
		return null;
	};

	$medicaux  = array( 'etudiants' => 0, 'attendu' => 0, 'encaisse' => 0, 'verification' => 0 );
	$medicaux_etabs = array();
	$quitus_statuts = array_fill_keys( array( 'droits', 'medicaux' ), array_fill_keys( array( 'genere', 'recu_envoye', 'verifie', 'rejete' ), 0 ) );
	$etudiants = array();
	foreach ( $lignes as $l ) {
		$quitus_statuts[ $l->type ][ $l->statut ]++;
		if ( 'medicaux' === $l->type ) {
			$medicaux['etudiants']++;
			$medicaux['attendu'] += (int) $l->montant;
			$medicaux['encaisse'] += 'verifie' === $l->statut ? (int) $l->montant : 0;
			$medicaux['verification'] += 'recu_envoye' === $l->statut ? (int) $l->montant : 0;
			$medicaux_etabs[ $l->etablissement ] = $medicaux_etabs[ $l->etablissement ] ?? array( 'etudiants' => 0, 'attendu' => 0, 'encaisse' => 0, 'verification' => 0 );
			$medicaux_etabs[ $l->etablissement ]['etudiants']++;
			$medicaux_etabs[ $l->etablissement ]['attendu'] += (int) $l->montant;
			$medicaux_etabs[ $l->etablissement ]['encaisse'] += 'verifie' === $l->statut ? (int) $l->montant : 0;
			$medicaux_etabs[ $l->etablissement ]['verification'] += 'recu_envoye' === $l->statut ? (int) $l->montant : 0;
			continue;
		}
		$cle = $l->compte_id . '|' . $l->etablissement;
		if ( ! isset( $etudiants[ $cle ] ) ) {
			$etudiants[ $cle ] = array( 'etab' => $l->etablissement, 'declare_total' => 0, 'verifie' => 0, 'recu' => 0, 'a_payer' => 0 );
		}
		$e = &$etudiants[ $cle ];
		/* Le quitus le plus récent fixe la filière, le niveau et le type de formation. */
		$trouvee = $l->filiere_id ? null : $rapprocher( $l->etablissement, $l->departement );
		$fil_id  = $l->filiere_id ?: ( $trouvee->id ?? 0 );
		$libelle = $l->filiere_libelle ?: ( $trouvee->libelle ?? trim( (string) $l->departement ) );
		$e['filiere']   = $fil_id ? 'id:' . $fil_id : 'txt:' . mb_strtoupper( $libelle );
		$e['libelle']   = $libelle ?: 'Filière non précisée';
		$e['pro']       = 'pro' === ( $l->type_formation ?: ( $trouvee->type_formation ?? 'classique' ) );
		$niveau_saisi   = strtoupper( trim( (string) $l->parcours ) );
		$e['niveau']    = isset( UEB_NIVEAUX_INSCRIPTION[ $niveau_saisi ] ) ? $niveau_saisi : 'Non précisé';
		$e['declare_total'] += (int) $l->montant;
		$e[ 'verifie' === $l->statut ? 'verifie' : ( 'recu_envoye' === $l->statut ? 'recu' : 'a_payer' ) ] += (int) $l->montant;
		unset( $e );
	}

	$global = ueb_suivi_vide();
	$etabs = $filieres = $niveaux = array();
	foreach ( $etudiants as $e ) {
		$attendu      = $e['pro'] ? $e['declare_total'] : UEB_DROITS_CLASSIQUES;
		$encaisse     = min( $e['verifie'], $attendu );
		$verification = min( $e['recu'], $attendu - $encaisse );
		$declare      = min( $e['a_payer'], $attendu - $encaisse - $verification );
		$ventile      = array(
			'attendu'      => $attendu,
			'encaisse'     => $encaisse,
			'verification' => $verification,
			'declare'      => $declare,
			'non_declare'  => $attendu - $encaisse - $verification - $declare,
			'trop_percu'   => max( 0, $e['verifie'] - $attendu ),
		);
		ueb_suivi_ajouter( $global, $ventile );
		$etabs[ $e['etab'] ] = $etabs[ $e['etab'] ] ?? ueb_suivi_vide();
		ueb_suivi_ajouter( $etabs[ $e['etab'] ], $ventile );
		$cle_f = $e['etab'] . '|' . $e['filiere'];
		$filieres[ $cle_f ] = $filieres[ $cle_f ] ?? ( ueb_suivi_vide() + array( 'libelle' => $e['libelle'], 'etab' => $e['etab'], 'pro' => $e['pro'] ) );
		ueb_suivi_ajouter( $filieres[ $cle_f ], $ventile );
		$niveaux[ $e['niveau'] ] = $niveaux[ $e['niveau'] ] ?? ueb_suivi_vide();
		ueb_suivi_ajouter( $niveaux[ $e['niveau'] ], $ventile );
	}
	/* Établissements et filières : les plus gros montants attendus d'abord. */
	uasort( $etabs, static fn( $a, $b ) => $b['attendu'] <=> $a['attendu'] );
	uasort( $filieres, static fn( $a, $b ) => $b['attendu'] <=> $a['attendu'] ?: strcmp( $a['libelle'], $b['libelle'] ) );
	$ordre = array_flip( array_merge( array_keys( UEB_NIVEAUX_INSCRIPTION ), array( 'Non précisé' ) ) );
	uksort( $niveaux, static fn( $a, $b ) => ( $ordre[ $a ] ?? 99 ) <=> ( $ordre[ $b ] ?? 99 ) );

	$resultat = compact( 'global', 'etabs', 'filieres', 'niveaux', 'medicaux', 'medicaux_etabs', 'quitus_statuts', 'etab' );
	if ( $jours_historique > 0 ) {
		$aujourdhui = current_time( 'Y-m-d' );
		$debut = gmdate( 'Y-m-d', strtotime( $aujourdhui . ' -' . ( min( 366, max( 2, (int) $jours_historique ) ) - 1 ) . ' days' ) );
		$depots = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(r.date_envoi) AS jour, COUNT(DISTINCT r.quitus_id) AS nombre
			 FROM ueb_insc_recus r JOIN ueb_insc_quitus q ON q.id = r.quitus_id
			 WHERE q.annee_academique = %s $filtre AND r.date_envoi >= %s AND r.date_envoi < %s
			 GROUP BY DATE(r.date_envoi)",
			$annee_code, $debut, gmdate( 'Y-m-d', strtotime( $aujourdhui . ' +1 day' ) )
		) );
		$resultat['historique'] = ueb_suivi_series_indicateurs( (array) $lignes, $etudiants, $depots, $debut, $aujourdhui );
	}
	return $resultat;
}

/**
 * Historique reconstitué depuis les dossiers conservés, sans requête par jour.
 * Le type de formation actuel est le même que celui du bilan. Les validations
 * annulées et pièces supprimées ne peuvent pas être reconstituées sans journal.
 * « depots » est un flux quotidien de quitus distincts, pas la file d'attente.
 * Les cumuls antérieurs à la fenêtre sont conservés ; zéro est une vraie valeur.
 */
function ueb_suivi_series_indicateurs( array $lignes, array $etudiants, array $depots, $debut, $fin ) {
	$jours = array();
	for ( $t = strtotime( $debut ); $t <= strtotime( $fin ); $t += DAY_IN_SECONDS ) {
		$jours[] = gmdate( 'Y-m-d', $t );
	}
	$series = array_fill_keys( array( 'etudiants', 'encaisse', 'medicaux', 'quitus', 'taux', 'depots' ), array() );
	$evenements = $vus = $groupes = array();
	foreach ( $lignes as $l ) {
		$creation = substr( $l->date_creation, 0, 10 );
		if ( $creation > $fin ) {
			continue;
		}
		$jour = max( $debut, $creation );
		$evenements[ $jour ][] = array( 'quitus', 1 );
		$vus[ $l->compte_id ] = isset( $vus[ $l->compte_id ] ) ? min( $vus[ $l->compte_id ], $jour ) : $jour;
		if ( 'droits' !== $l->type ) {
			if ( 'verifie' === $l->statut ) {
				$date = max( $creation, substr( $l->date_verification ?: $l->date_modification, 0, 10 ) );
				if ( $date <= $fin ) {
					$evenements[ max( $debut, $date ) ][] = array( 'medical', (int) $l->montant );
				}
			}
			continue;
		}
		$cle = $l->compte_id . '|' . $l->etablissement;
		$pro = ! empty( $etudiants[ $cle ]['pro'] );
		$evenements[ $jour ][] = array( 'droits', (int) $l->montant, $cle, $pro );
		if ( 'verifie' === $l->statut ) {
			$date = max( $creation, substr( $l->date_verification ?: $l->date_modification, 0, 10 ) );
			if ( $date <= $fin ) {
				$evenements[ max( $debut, $date ) ][] = array( 'validation', (int) $l->montant, $cle, $pro );
			}
		}
	}
	foreach ( $vus as $jour ) {
		$evenements[ $jour ][] = array( 'etudiants', 1 );
	}
	$receptions = array();
	foreach ( $depots as $depot ) {
		$receptions[ $depot->jour ] = (int) $depot->nombre;
	}
	$effectif = $quitus = $attendu = $encaisse = $medical = 0;
	foreach ( $jours as $jour ) {
		foreach ( $evenements[ $jour ] ?? array() as $evt ) {
			if ( 'quitus' === $evt[0] ) {
				$quitus++;
			} elseif ( 'etudiants' === $evt[0] ) {
				$effectif++;
			} elseif ( 'medical' === $evt[0] ) {
				$medical += $evt[1];
			} else {
				$cle = $evt[2];
				$avant = $groupes[ $cle ] ?? array( 'attendu' => 0, 'verifie' => 0 );
				$apres = $avant;
				if ( 'droits' === $evt[0] ) {
					$apres['attendu'] = $evt[3] ? $avant['attendu'] + $evt[1] : UEB_DROITS_CLASSIQUES;
				} else {
					$apres['verifie'] += $evt[1];
				}
				$attendu += $apres['attendu'] - $avant['attendu'];
				$encaisse += min( $apres['attendu'], $apres['verifie'] ) - min( $avant['attendu'], $avant['verifie'] );
				$groupes[ $cle ] = $apres;
			}
		}
		$series['etudiants'][] = $effectif;
		$series['quitus'][] = $quitus;
		$series['encaisse'][] = $encaisse;
		$series['medicaux'][] = $medical;
		$series['taux'][] = $attendu ? round( 100 * $encaisse / $attendu, 2 ) : null;
		$series['depots'][] = $receptions[ $jour ] ?? 0;
	}
	return array( 'jours' => $jours ) + $series;
}

/**
 * Portée d'une lecture des quitus : l'année, et l'établissement de l'agent
 * de scolarité quel que soit le filtre demandé.
 *
 * @return array{0: string, 1: array} Conditions SQL sur l'alias q, et leurs paramètres.
 */
function ueb_gestion_portee_sql( array $filtres ) {
	$limite = ueb_etab_agent();
	if ( $limite ) {
		$filtres['etab'] = $limite;
	}
	$where  = array( 'q.annee_academique = %s' );
	$params = array( $filtres['annee'] );
	$types  = ueb_types_quitus_visibles();
	/* Filtre « type » (onglet Reçus CMS) : un seul type, parmi ceux que le compte voit. */
	if ( ! empty( $filtres['type'] ) && in_array( $filtres['type'], $types, true ) ) {
		$types = array( $filtres['type'] );
	}
	if ( ! $types ) {
		$where[] = '1 = 0';
	} elseif ( count( $types ) < count( UEB_PERMISSIONS_TYPE_QUITUS ) ) {
		$where[]  = 'q.type = %s';
		$params[] = $types[0];
	}
	if ( UEB_AUCUN_ETAB === $limite ) {
		$where[] = '1 = 0'; // aucune portée : aucune ligne, jamais « tous »
	}
	if ( ! empty( $filtres['etab'] ) && ueb_etablissement( $filtres['etab'] ) ) {
		$where[]  = 'q.etablissement = %s';
		$params[] = $filtres['etab'];
	}
	return array( implode( ' AND ', $where ), $params );
}

/**
 * @return array{lignes: array, total: int, pages: int, page: int}
 */
function ueb_gestion_liste_quitus( array $filtres, $par_page = 30 ) {
	global $wpdb;
	list( $portee, $params ) = ueb_gestion_portee_sql( $filtres );
	$where = array( $portee );
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

/* ---------- Dossiers : droits universitaires et frais médicaux ensemble ----------
   Le quitus médical d'un premier paiement est rattaché au quitus des droits
   (quitus_droits_id) : la scolarité les traite comme un seul dossier, sur une
   ligne du registre et une seule fiche. Chaque paiement garde son statut. */

/** Clé du dossier d'un quitus (alias q) : l'id des droits pour un quitus médical rattaché. */
const UEB_SQL_CLE_DOSSIER = "(CASE WHEN q.type = 'medicaux' AND q.quitus_droits_id IS NOT NULL THEN q.quitus_droits_id ELSE q.id END)";

/** Statut d'un dossier vu de la scolarité : ce qui attend une action passe d'abord. */
const UEB_SQL_STATUT_DOSSIER = "(CASE WHEN SUM(q.statut = 'recu_envoye') > 0 THEN 'recu_envoye' WHEN SUM(q.statut = 'rejete') > 0 THEN 'rejete' WHEN SUM(q.statut = 'genere') > 0 THEN 'genere' ELSE 'verifie' END)";

/**
 * Paiements d'un dossier, droits d'abord : le quitus lui-même et le quitus qui
 * lui est rattaché (frais médicaux d'un premier paiement, ou droits d'un
 * quitus médical).
 *
 * @return array<int, object>
 */
function ueb_gestion_dossier( $quitus ) {
	$principal = $quitus;
	if ( 'medicaux' === ( $quitus->type ?? 'droits' ) && ! empty( $quitus->quitus_droits_id ) ) {
		$parent = ueb_quitus_par_id( (int) $quitus->quitus_droits_id );
		if ( $parent && 'droits' === $parent->type && (int) $parent->compte_id === (int) $quitus->compte_id && $parent->annee_academique === $quitus->annee_academique ) {
			$principal = $parent;
		}
	}
	$medical = 'droits' === ( $principal->type ?? 'droits' ) ? ueb_medical_du_dossier( $principal ) : null;
	return array_values( array_filter( array( $principal, $medical ) ) );
}

/** Filtre « Paiements » du registre : conditions sur le dossier entier. */
const UEB_FILTRES_PAIEMENTS = array(
	'du_fm' => array( 'DU et FM', "SUM(q.type = 'droits') > 0 AND SUM(q.type = 'medicaux') > 0" ),
	'du'    => array( 'DU seuls', "SUM(q.type = 'medicaux') = 0" ),
	'fm'    => array( 'FM seuls', "SUM(q.type = 'droits') = 0" ),
);

/** Filtres du registre portant sur un quitus : clé du filtre => colonne. */
const UEB_FILTRES_QUITUS = array(
	'filiere' => 'q.departement',
	'niveau'  => 'q.parcours',
	'moyen'   => 'q.moyen_paiement',
);

/**
 * Sélection des dossiers du registre : la portée, puis les filtres. Ceux qui
 * portent sur un quitus (recherche, filière, niveau, moyen de paiement)
 * retiennent tout son dossier ; le type de paiements et le statut portent sur
 * le dossier entier (HAVING).
 *
 * @return array{0: string, 1: array, 2: string, 3: array} WHERE et ses valeurs, HAVING et ses valeurs.
 */
function ueb_gestion_selection_dossiers( array $filtres, $avec_statut = true ) {
	global $wpdb;
	list( $portee, $params ) = ueb_gestion_portee_sql( $filtres );
	$cle        = UEB_SQL_CLE_DOSSIER;
	$conditions = array();
	$valeurs    = array();
	if ( ! empty( $filtres['q'] ) ) {
		$like         = '%' . $wpdb->esc_like( $filtres['q'] ) . '%';
		$conditions[] = '( q.numero LIKE %s OR q.identifiant LIKE %s OR q.nom LIKE %s OR q.prenom LIKE %s )';
		array_push( $valeurs, $like, $like, $like, $like );
	}
	foreach ( UEB_FILTRES_QUITUS as $filtre => $colonne ) {
		if ( isset( $filtres[ $filtre ] ) && '' !== $filtres[ $filtre ] ) {
			$conditions[] = "$colonne = %s";
			$valeurs[]    = $filtres[ $filtre ];
		}
	}
	$where = $portee;
	if ( $conditions ) {
		$where .= " AND $cle IN ( SELECT $cle FROM ueb_insc_quitus q WHERE $portee AND " . implode( ' AND ', $conditions ) . ' )';
		$params = array_merge( $params, $params, $valeurs );
	}
	$ayant         = array();
	$valeurs_ayant = array();
	if ( isset( UEB_FILTRES_PAIEMENTS[ $filtres['paiements'] ?? '' ] ) ) {
		$ayant[] = UEB_FILTRES_PAIEMENTS[ $filtres['paiements'] ][1];
	}
	if ( $avec_statut && ! empty( $filtres['statut'] ) && isset( UEB_STATUTS_QUITUS[ $filtres['statut'] ] ) ) {
		$ayant[]         = UEB_SQL_STATUT_DOSSIER . ' = %s';
		$valeurs_ayant[] = $filtres['statut'];
	}
	return array( $where, $params, $ayant ? 'HAVING ' . implode( ' AND ', $ayant ) : '', $valeurs_ayant );
}

/**
 * Valeurs proposées par les filtres du registre : celles présentes dans les
 * quitus de la portée (les anciennes saisies libres comprises).
 *
 * @return array{filiere: string[], niveau: string[], moyen: string[]}
 */
function ueb_gestion_options_dossiers( array $filtres ) {
	global $wpdb;
	list( $portee, $params ) = ueb_gestion_portee_sql( $filtres );
	$options = array();
	foreach ( UEB_FILTRES_QUITUS as $filtre => $colonne ) {
		$options[ $filtre ] = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT $colonne FROM ueb_insc_quitus q WHERE $portee AND $colonne <> '' ORDER BY $colonne", $params ) ); // phpcs:ignore -- colonne constante
	}
	return $options;
}

/**
 * Registre de la scolarité : une ligne par dossier, selon les filtres de
 * ueb_gestion_selection_dossiers().
 *
 * @return array{lignes: array<int, object>, total: int, pages: int, page: int}
 *         Chaque ligne : cle, statut, paiements (quitus avec telephone et nb_recus), principal.
 */
function ueb_gestion_liste_dossiers( array $filtres, $par_page = 30 ) {
	global $wpdb;
	list( $where, $params, $ayant, $valeurs_ayant ) = ueb_gestion_selection_dossiers( $filtres );
	$params  = array_merge( $params, $valeurs_ayant );
	$cle     = UEB_SQL_CLE_DOSSIER;
	$statut  = UEB_SQL_STATUT_DOSSIER;
	$groupes = "SELECT $cle AS cle, $statut AS statut_dossier, MAX(q.date_modification) AS maj
		FROM ueb_insc_quitus q WHERE $where GROUP BY cle $ayant";
	$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM ( $groupes ) d", $params ) ); // phpcs:ignore -- assemblé ci-dessus, valeurs préparées
	$pages = max( 1, (int) ceil( $total / $par_page ) );
	$page  = min( max( 1, (int) ( $filtres['page'] ?? 1 ) ), $pages );
	$cles  = $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM ( $groupes ) d ORDER BY FIELD(d.statut_dossier, 'recu_envoye', 'rejete', 'genere', 'verifie'), d.maj DESC, d.cle DESC LIMIT %d OFFSET %d", // phpcs:ignore
		array_merge( $params, array( $par_page, ( $page - 1 ) * $par_page ) )
	) );
	$lignes = array();
	if ( $cles ) {
		list( $portee_bis, $params_bis ) = ueb_gestion_portee_sql( $filtres );
		$ids      = implode( ',', array_map( static fn( $c ) => (int) $c->cle, $cles ) );
		$quitus   = $wpdb->get_results( $wpdb->prepare(
			"SELECT q.*, c.telephone, $cle AS cle, (SELECT COUNT(*) FROM ueb_insc_recus r WHERE r.quitus_id = q.id) AS nb_recus
			   FROM ueb_insc_quitus q LEFT JOIN ueb_insc_comptes c ON c.id = q.compte_id
			  WHERE $portee_bis AND $cle IN ( $ids )", // phpcs:ignore -- ids entiers
			$params_bis
		) );
		foreach ( $cles as $c ) {
			$lignes[ (int) $c->cle ] = (object) array( 'cle' => (int) $c->cle, 'statut' => $c->statut_dossier, 'paiements' => array() );
		}
		foreach ( $quitus as $q ) {
			$lignes[ (int) $q->cle ]->paiements[] = $q;
		}
		foreach ( $lignes as $ligne ) {
			usort( $ligne->paiements, static fn( $a, $b ) => array( 'medicaux' === $a->type, (int) $a->id ) <=> array( 'medicaux' === $b->type, (int) $b->id ) );
			$ligne->principal = $ligne->paiements[0] ?? null;
		}
		$lignes = array_values( array_filter( $lignes, static fn( $l ) => $l->principal ) );
	}
	return compact( 'lignes', 'total', 'pages', 'page' );
}

/**
 * Compteurs du registre : dossiers par statut selon les filtres en cours
 * (hors statut), et, pour l'alerte, les validations en attente dans toute
 * la portée avec le reçu qui attend depuis le plus longtemps.
 *
 * @return array{total: int, statuts: array<string, int>, attente: object, plus_ancien: object|null}
 */
function ueb_gestion_stats_dossiers( array $filtres ) {
	global $wpdb;
	list( $portee, $params ) = ueb_gestion_portee_sql( $filtres );
	list( $where, $params_selection, $ayant, $valeurs_ayant ) = ueb_gestion_selection_dossiers( $filtres, false );
	$cle    = UEB_SQL_CLE_DOSSIER;
	$statut = UEB_SQL_STATUT_DOSSIER;
	$stats  = array( 'total' => 0, 'statuts' => array_fill_keys( array_keys( UEB_STATUTS_QUITUS ), 0 ) );
	$lignes = $wpdb->get_results( $wpdb->prepare(
		"SELECT statut_dossier, COUNT(*) AS n FROM ( SELECT $statut AS statut_dossier FROM ueb_insc_quitus q WHERE $where GROUP BY $cle $ayant ) d GROUP BY statut_dossier", // phpcs:ignore -- assemblé ci-dessus
		array_merge( $params_selection, $valeurs_ayant )
	) );
	foreach ( $lignes as $l ) {
		$stats['statuts'][ $l->statut_dossier ] = (int) $l->n;
		$stats['total']                        += (int) $l->n;
	}
	$stats['attente'] = $wpdb->get_row( $wpdb->prepare(
		"SELECT COUNT(*) AS paiements, COUNT(DISTINCT $cle) AS dossiers FROM ueb_insc_quitus q WHERE $portee AND q.statut = 'recu_envoye'", // phpcs:ignore
		$params
	) );
	$stats['plus_ancien'] = $wpdb->get_row( $wpdb->prepare(
		"SELECT q.id, q.date_modification FROM ueb_insc_quitus q WHERE $portee AND q.statut = 'recu_envoye' ORDER BY q.date_modification ASC, q.id ASC LIMIT 1", // phpcs:ignore
		$params
	) );
	return $stats;
}

/**
 * Dossier suivant à vérifier depuis la fiche d'un dossier : même
 * établissement, même année, le reçu le plus ancien en attente d'abord.
 *
 * @param object $quitus Quitus principal du dossier affiché.
 * @return array{id: int, reste: int}|null reste = dossiers en attente hors celui-ci.
 */
function ueb_gestion_quitus_suivant( $quitus ) {
	global $wpdb;
	$cle   = UEB_SQL_CLE_DOSSIER;
	$where = $wpdb->prepare(
		"q.statut = 'recu_envoye' AND q.annee_academique = %s AND q.etablissement = %s AND $cle <> %d", // phpcs:ignore -- expression constante
		$quitus->annee_academique,
		$quitus->etablissement,
		$quitus->id
	);
	$types = ueb_types_quitus_visibles( $quitus->etablissement );
	if ( ! $types ) {
		return null;
	}
	$where .= $wpdb->prepare( ' AND q.type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')', $types ); // phpcs:ignore -- marqueurs générés
	$reste = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT $cle) FROM ueb_insc_quitus q WHERE $where" ); // phpcs:ignore -- préparé ci-dessus
	if ( ! $reste ) {
		return null;
	}
	$id = (int) $wpdb->get_var( "SELECT q.id FROM ueb_insc_quitus q WHERE $where ORDER BY q.date_modification ASC, q.id ASC LIMIT 1" ); // phpcs:ignore
	return array( 'id' => $id, 'reste' => $reste );
}

/**
 * Recherche d'étudiants : un seul champ qui cherche dans le matricule, le
 * téléphone, le nom et le prénom, plus un filtre sur
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
		$where[] = '( c.matricule LIKE %s OR c.telephone = %s
			OR EXISTS ( SELECT 1 FROM ueb_insc_quitus r WHERE r.compte_id = c.id AND ( r.nom LIKE %s OR r.prenom LIKE %s ) ) )';
		array_push( $params, $maj, $tel ?: '-', $like, $like );
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
		  WHERE c.matricule LIKE %s OR c.telephone = %s
		  ORDER BY c.date_creation DESC LIMIT 50", // phpcs:ignore -- jointure sans saisie
		array_merge( $params, array( $like, $tel ?: '-' ) )
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
	if ( ! ueb_peut_decider_quitus( $quitus ) ) {
		ueb_flash( 'erreur', 'medicaux' === ueb_type_du_quitus( $quitus ) ? 'Le reçu des frais médicaux est validé par le CMS.' : 'Le reçu des droits universitaires est validé par la scolarité.' );
		ueb_rediriger( $retour );
	}
	/* Rien à vérifier tant que l'étudiant n'a envoyé aucun reçu. */
	if ( 'verifie' === $statut && ! ueb_recus_du_quitus( $quitus->id ) ) {
		ueb_flash( 'erreur', "Aucun reçu envoyé pour ce quitus : le paiement ne peut pas encore être vérifié." );
		ueb_rediriger( $retour );
	}
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

/**
 * Validation depuis le registre : chaque paiement du dossier dont le reçu
 * attend la vérification (droits universitaires et frais médicaux d'un même
 * étudiant en un geste). Les paiements sans reçu, déjà vérifiés ou renvoyés
 * à l'étudiant ne bougent pas. Retour au registre tel qu'il était affiché.
 */
function ueb_action_gestion_valider() {
	global $wpdb;
	ueb_exiger_gestionnaire();
	$quitus = ueb_quitus_par_id( (int) ( $_POST['quitus_id'] ?? 0 ) );
	if ( ! $quitus ) {
		ueb_rediriger( ueb_url_scolarite() );
	}
	ueb_exiger_etab( $quitus->etablissement );
	$retour_post = (array) wp_unslash( $_POST['retour'] ?? array() );
	$texte       = static fn( $cle ) => sanitize_text_field( (string) ( $retour_post[ $cle ] ?? '' ) ) ?: null;
	$filtre      = sanitize_key( $retour_post['statut'] ?? '' );
	$paiements   = sanitize_key( $retour_post['paiements'] ?? '' );
	$retour      = add_query_arg( array_filter( array(
		'vue'       => 'quitus',
		'statut'    => isset( UEB_STATUTS_QUITUS[ $filtre ] ) ? $filtre : null,
		'paiements' => isset( UEB_FILTRES_PAIEMENTS[ $paiements ] ) ? $paiements : null,
		'q'         => $texte( 'q' ),
		'filiere'   => $texte( 'filiere' ),
		'niveau'    => $texte( 'niveau' ),
		'moyen'     => $texte( 'moyen' ),
		'p'         => max( 0, (int) ( $retour_post['p'] ?? 0 ) ) ?: null,
	) ), ueb_url_scolarite() );

	/* Le registre met en évidence la ligne du dossier validé. */
	$paiements = ueb_gestion_dossier( $quitus );
	$retour   .= '#dossier-' . (int) $paiements[0]->id;
	$valides   = array();
	foreach ( $paiements as $q ) {
		if ( 'recu_envoye' !== $q->statut || ! ueb_peut_gerer_etab( $q->etablissement ) || ! ueb_peut_decider_quitus( $q ) || ! ueb_recus_du_quitus( $q->id ) ) {
			continue;
		}
		/* La condition sur le statut écarte une décision prise entre-temps sur la fiche. */
		$fait = $wpdb->update( 'ueb_insc_quitus', array(
			'statut'            => 'verifie',
			'motif_rejet'       => null,
			'verifie_par'       => get_current_user_id(),
			'date_verification' => current_time( 'mysql' ),
		), array( 'id' => $q->id, 'statut' => 'recu_envoye' ) );
		if ( $fait ) {
			$valides[] = $q;
		}
	}
	if ( ! $valides ) {
		ueb_flash( 'info', 'Rien à valider dans ce dossier : aucun reçu n’attend la vérification.' );
		ueb_rediriger( $retour );
	}
	$quoi = implode( ' et ', array_map( static fn( $q ) => mb_strtolower( ueb_libelle_type_quitus( $q->type ) ), $valides ) );
	ueb_flash( 'succes', sprintf( '%s pour %s : %s.', count( $valides ) > 1 ? 'Paiements vérifiés' : 'Paiement vérifié', trim( $quitus->nom . ' ' . $quitus->prenom ), $quoi ) );
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
	ueb_exiger_comptes();
	$compte = ueb_compte_par_id( (int) ( $_POST['compte_id'] ?? 0 ) );
	$retour = add_query_arg( array( 'vue' => 'comptes', 'qc' => sanitize_text_field( wp_unslash( $_POST['q'] ?? '' ) ) ), ueb_url_comptes() );
	if ( ! $compte || ! ueb_compte_dans_etab( $compte->id, ueb_etab_agent() ) ) {
		ueb_rediriger( $retour );
	}
	/* « Première connexion » à vrai pour UEB_REINIT_DUREE : l'étudiant choisit lui-même
	   son nouveau mot de passe sur /mot-de-passe-oublie/. L'agent n'en voit aucun. */
	$maintenant = current_time( 'mysql' );
	$wpdb->update( 'ueb_insc_comptes', array(
		'doit_changer_mdp' => 1,
		'reinit_le'        => $maintenant,
		'reinit_par'       => get_current_user_id() ?: null,
		'version_session'  => (int) $compte->version_session + 1,
	), array( 'id' => $compte->id ) );
	$wpdb->insert( 'ueb_insc_reinitialisations', array( 'compte_id' => (int) $compte->id, 'agent_id' => get_current_user_id() ?: null, 'date_reinit' => $maintenant ) );
	$_SESSION['ueb_reinit_effectuee'] = array( 'compte' => ueb_identifiant_compte( $compte ), 'jusqua' => ueb_reinit_heure( strtotime( $maintenant ) + UEB_REINIT_DUREE ) );
	ueb_rediriger( $retour );
}

/**
 * Création d'un compte étudiant par la scolarité, quand l'étudiant ne peut
 * pas le faire lui-même (pas de téléphone, par exemple). Le téléphone est
 * donc facultatif ici, et le mot de passe provisoire s'affiche une fois.
 */
function ueb_action_gestion_creer_etudiant() {
	global $wpdb;
	ueb_exiger_comptes();
	$retour      = add_query_arg( 'vue', 'comptes', ueb_url_comptes() );
	$identifiant = ueb_normaliser_identifiant( wp_unslash( $_POST['identifiant'] ?? '' ) );
	$tel_saisi   = sanitize_text_field( wp_unslash( $_POST['telephone'] ?? '' ) );
	$telephone   = $tel_saisi ? ueb_normaliser_telephone( $tel_saisi ) : '';

	$erreur_matricule = ueb_erreur_matricule( $identifiant );
	if ( $erreur_matricule ) {
		ueb_flash( 'erreur', $erreur_matricule );
		ueb_rediriger( $retour );
	}
	if ( $tel_saisi && ! $telephone ) {
		ueb_flash( 'erreur', 'Numéro mobile camerounais attendu : 9 chiffres commençant par 6.' );
		ueb_rediriger( $retour );
	}
	if ( ueb_compte_par_identifiant( $identifiant ) ) {
		ueb_flash( 'erreur', 'Un compte existe déjà avec ce matricule.' );
		ueb_rediriger( $retour );
	}

	$provisoire = ueb_mot_de_passe_provisoire();
	$ok         = $wpdb->insert( 'ueb_insc_comptes', array(
		'matricule'        => $identifiant,
		'telephone'        => $telephone ?: '',
		'mot_de_passe'     => password_hash( $provisoire, PASSWORD_DEFAULT ),
		'doit_changer_mdp' => 1,
	) );
	if ( ! $ok ) {
		error_log( '[inscriptions-ueb] Création du compte étudiant impossible : ' . $wpdb->last_error );
		ueb_flash( 'erreur', "Le compte n'a pas pu être créé. Réessaie dans un instant." );
		ueb_rediriger( $retour );
	}
	$_SESSION['ueb_mdp_provisoire'] = array( 'compte' => $identifiant, 'mdp' => $provisoire );
	ueb_flash( 'succes', "Compte $identifiant créé." );
	ueb_rediriger( $retour );
}

function ueb_action_gestion_bloquer() {
	global $wpdb;
	ueb_exiger_comptes();
	$compte = ueb_compte_par_id( (int) ( $_POST['compte_id'] ?? 0 ) );
	$retour = add_query_arg( array( 'vue' => 'comptes', 'qc' => sanitize_text_field( wp_unslash( $_POST['q'] ?? '' ) ) ), ueb_url_comptes() );
	if ( $compte && ueb_compte_dans_etab( $compte->id, ueb_etab_agent() ) ) {
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
		sanitize_text_field( wp_unslash( $_POST['etablissement'] ?? '' ) ),
		trim( (string) wp_unslash( $_POST['mot_de_passe'] ?? '' ) ),
		sanitize_key( wp_unslash( $_POST['role'] ?? '' ) )
	);
	if ( is_wp_error( $agent ) ) {
		ueb_flash( 'erreur', $agent->get_error_message() );
		ueb_rediriger( $retour );
	}
	list( $id, $provisoire ) = $agent;
	$_SESSION['ueb_mdp_agent'] = array( 'compte' => get_userdata( $id )->user_login, 'mdp' => $provisoire );
	ueb_flash( 'succes', 'Compte du personnel créé.' );
	ueb_rediriger( $retour );
}

/** Permet à un agent connecté de remplacer son mot de passe WordPress. */
function ueb_action_gestion_changer_mdp_personnel() {
	/* Personnel et administrateurs d'IPES : même formulaire, même règle. */
	if ( ! is_user_logged_in() || ! ( ueb_est_agent( get_current_user_id() ) || ueb_est_admin_ipes( get_current_user_id() ) ) || ueb_agent_suspendu() ) {
		wp_die( 'Action réservée aux personnels autorisés.', 'Accès refusé', array( 'response' => 403 ) );
	}
	/* Retour vers l'espace d'où vient le formulaire (Direction, scolarité ou comptes étudiants). */
	/* Sans page d'origine, wp_validate_redirect( false, … ) renvoie '' : repli explicite. */
	$retour = wp_validate_redirect( (string) wp_get_referer(), ueb_url_espace() ) ?: ueb_url_espace();
	$user = wp_get_current_user();
	$actuel = (string) ( $_POST['mot_de_passe_actuel'] ?? '' );
	$nouveau = (string) ( $_POST['mot_de_passe_nouveau'] ?? '' );
	$confirmation = (string) ( $_POST['mot_de_passe_confirmation'] ?? '' );
	if ( ! wp_check_password( $actuel, $user->user_pass, $user->ID ) ) {
		ueb_flash( 'erreur', 'Le mot de passe actuel est incorrect.' );
	} elseif ( ( $erreur = ueb_erreur_mot_de_passe( $nouveau, $user->user_login ) ) ) {
		ueb_flash( 'erreur', $erreur );
	} elseif ( $nouveau !== $confirmation ) {
		ueb_flash( 'erreur', 'La confirmation ne correspond pas au nouveau mot de passe.' );
	} else {
		wp_set_password( $nouveau, $user->ID );
		ueb_flash( 'succes', 'Mot de passe modifié. Reconnecte-toi avec ton nouveau mot de passe.' );
		wp_logout();
	}
	ueb_rediriger( $retour );
}

function ueb_action_gestion_agent_mdp() {
	ueb_exiger_admin();
	$retour = ueb_url_administration();
	$id     = (int) ( $_POST['agent_id'] ?? 0 );
	$agent  = get_userdata( $id );
	$mot_de_passe = trim( (string) wp_unslash( $_POST['mot_de_passe'] ?? '' ) );
	$confirmation = trim( (string) wp_unslash( $_POST['mot_de_passe_confirmation'] ?? '' ) );
	if ( ! $agent || ! ueb_est_agent( $id ) ) {
		ueb_rediriger( $retour );
	}
	if ( ( $erreur = ueb_erreur_mot_de_passe( $mot_de_passe, $agent->user_login ) ) ) {
		ueb_flash( 'erreur', $erreur );
		ueb_rediriger( $retour );
	}
	if ( $mot_de_passe !== $confirmation ) {
		ueb_flash( 'erreur', 'La confirmation ne correspond pas au nouveau mot de passe.' );
		ueb_rediriger( $retour );
	}
	wp_set_password( $mot_de_passe, $id );
	$_SESSION['ueb_mdp_agent'] = array( 'compte' => $agent->user_login, 'mdp' => $mot_de_passe );
	ueb_flash( 'succes', 'Mot de passe mis à jour pour ' . $agent->user_login . '.' );
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
	/* L'administrateur d'un IPES n'a pas accès à wp-admin : il n'y est jamais envoyé. */
	if ( $utilisateur instanceof WP_User && ! user_can( $utilisateur, 'manage_options' ) && ( ueb_est_agent( $utilisateur->ID ) || ueb_est_admin_ipes( $utilisateur->ID ) ) ) {
		return ueb_url_espace_du_compte( $utilisateur->ID );
	}
	if ( $demande ) {
		return $url; /* les comptes étudiants et les administrateurs gardent la destination demandée */
	}
	return $url;
}, 10, 3 );

/* PDF d'un quitus depuis l'espace scolarité : ?quitus={id}&pdf=1 */
add_action( 'template_redirect', function () {
	if ( ! isset( $_GET['pdf'], $_GET['quitus'] ) || 'scolarite' !== ueb_espace_courant() || ! ueb_types_quitus_visibles() ) {
		return;
	}
	$quitus = ueb_quitus_par_id( (int) $_GET['quitus'] );
	if ( ueb_peut_voir_quitus( $quitus ) ) {
		ueb_envoyer_pdf_quitus( $quitus );
	}
}, 20 );
