<?php
/**
 * Étudiants UEB : la liste des étudiants inscrits, pour l'administration,
 * l'espace de gestion (Direction) et l'espace scolarité.
 *
 * Un étudiant est inscrit pour une année dès qu'il a un quitus de droits
 * universitaires cette année-là. Ses informations sont celles de son dernier
 * quitus de droits de l'année (figées à la génération) ; son paiement
 * additionne les droits préparés et ceux vérifiés par la scolarité.
 *
 * La portée est toujours appliquée ici, côté serveur : la liste ne contient
 * que les établissements passés en paramètre ($etabs, issus de
 * ueb_etabs_autorises() ou de tous les établissements pour l'administrateur).
 * Lecture seule : aucune action n'est proposée sur un étudiant.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

const UEB_ETUDIANTS_PAR_PAGE = 30;

/* État des droits de l'année : soldés (50 000 vérifiés), partiel, aucun paiement
   vérifié ; « à vérifier » croise les trois (un reçu attend la scolarité). */
const UEB_ETUDIANTS_STATUTS = array(
	'solde'      => 'Droits soldés',
	'partiel'    => 'Paiement partiel',
	'aucun'      => 'Aucun paiement vérifié',
	'a_verifier' => 'Reçu à vérifier',
);

const UEB_ETUDIANTS_SITUATIONS = array(
	'nouveau' => 'Nouveau',
	'ancien'  => 'Réinscription',
	'reprise' => 'Reprise après interruption',
);

/** Années académiques qui ont des inscrits, la plus récente d'abord ; l'année en cours toujours présente. */
function ueb_etudiants_annees() {
	global $wpdb;
	$annees = $wpdb->get_col( "SELECT DISTINCT annee_academique FROM ueb_insc_quitus WHERE type = 'droits' ORDER BY annee_academique DESC" );
	$courante = ueb_exercice_consulte()['code'];
	if ( ! in_array( $courante, $annees, true ) ) {
		array_unshift( $annees, $courante );
	}
	rsort( $annees );
	return $annees;
}

/**
 * Filtres lus dans l'adresse, chacun revalidé : un établissement hors portée,
 * une valeur inconnue ou une page impossible sont ignorés.
 */
function ueb_etudiants_filtres( array $etabs ) {
	$get   = static fn( $cle ) => sanitize_text_field( wp_unslash( (string) ( $_GET[ $cle ] ?? '' ) ) ); // phpcs:ignore -- lecture seule
	$annee = $get( 'annee' );
	$etab  = strtoupper( $get( 'etab' ) );
	return array(
		'annee'     => in_array( $annee, ueb_etudiants_annees(), true ) ? $annee : ueb_exercice_consulte()['code'],
		'etab'      => in_array( $etab, $etabs, true ) ? $etab : '',
		'filiere'   => max( 0, (int) $get( 'filiere' ) ),
		'niveau'    => isset( UEB_NIVEAUX_INSCRIPTION[ $get( 'niveau' ) ] ) ? $get( 'niveau' ) : '',
		'sexe'      => in_array( $get( 'sexe' ), array( 'M', 'F' ), true ) ? $get( 'sexe' ) : '',
		'situation' => isset( UEB_ETUDIANTS_SITUATIONS[ $get( 'situation' ) ] ) ? $get( 'situation' ) : '',
		'statut'    => isset( UEB_ETUDIANTS_STATUTS[ $get( 'statut' ) ] ) ? $get( 'statut' ) : '',
		'q'         => mb_substr( trim( $get( 'q' ) ), 0, 80 ),
		'p'         => max( 1, (int) $get( 'p' ) ),
	);
}

/**
 * Étudiants inscrits d'une année dans la portée donnée, filtrés et paginés.
 *
 * @param array $f       filtres (ueb_etudiants_filtres())
 * @param array $etabs   sigles autorisés ; vide : aucun résultat
 * @param bool  $toutes  toutes les lignes, sans pagination (exports)
 * @return array lignes, total (avec tous les filtres), compteurs (par état,
 *               sans le filtre d'état), filieres (options par établissement),
 *               page, pages
 */
function ueb_etudiants( array $f, array $etabs, $toutes = false ) {
	global $wpdb;
	$vide = array( 'lignes' => array(), 'total' => 0, 'compteurs' => array_fill_keys( array_merge( array( 'tous' ), array_keys( UEB_ETUDIANTS_STATUTS ) ), 0 ), 'filieres' => array(), 'page' => 1, 'pages' => 1 );
	if ( ! $etabs ) {
		return $vide;
	}

	/* Dernier quitus de droits de l'année par compte, avec ses totaux. */
	$from = $wpdb->prepare(
		"FROM ueb_insc_quitus q
		 JOIN ( SELECT compte_id, MAX(id) AS dernier, SUM(montant) AS prepare,
		               SUM(CASE WHEN statut = 'verifie' THEN montant ELSE 0 END) AS verifie,
		               SUM(CASE WHEN statut = 'recu_envoye' THEN montant ELSE 0 END) AS en_verification,
		               SUM(statut = 'recu_envoye') AS a_verifier, SUM(statut = 'rejete') AS a_corriger
		          FROM ueb_insc_quitus WHERE type = 'droits' AND annee_academique = %s GROUP BY compte_id ) a ON a.dernier = q.id",
		$f['annee']
	);
	$portee = $wpdb->prepare( 'q.etablissement IN (' . implode( ',', array_fill( 0, count( $etabs ), '%s' ) ) . ')', $etabs );
	$etat   = $wpdb->prepare( "CASE WHEN a.verifie >= %d THEN 'solde' WHEN a.verifie > 0 THEN 'partiel' ELSE 'aucun' END", UEB_DROITS_CLASSIQUES );

	/* Filtres hors état : ils servent aussi aux compteurs des onglets. */
	$where = array( $portee );
	if ( $f['etab'] ) {
		$where[] = $wpdb->prepare( 'q.etablissement = %s', $f['etab'] );
	}
	if ( $f['filiere'] ) {
		$where[] = $wpdb->prepare( 'q.filiere_id = %d', $f['filiere'] );
	}
	if ( $f['niveau'] ) {
		$where[] = $wpdb->prepare( 'q.parcours = %s', $f['niveau'] );
	}
	if ( $f['sexe'] ) {
		$where[] = $wpdb->prepare( 'q.sexe = %s', $f['sexe'] );
	}
	if ( $f['situation'] ) {
		$where[] = $wpdb->prepare( 'q.situation = %s', $f['situation'] );
	}
	if ( '' !== $f['q'] ) {
		$motif   = '%' . $wpdb->esc_like( $f['q'] ) . '%';
		$where[] = $wpdb->prepare( "(q.nom LIKE %s OR q.prenom LIKE %s OR q.identifiant LIKE %s OR CONCAT(q.nom, ' ', q.prenom) LIKE %s OR CONCAT(q.prenom, ' ', q.nom) LIKE %s)", $motif, $motif, $motif, $motif, $motif );
	}
	$sans_etat = implode( ' AND ', $where );

	/* Compteurs des onglets : tous les filtres sauf l'état. */
	$c = $wpdb->get_row( "SELECT COUNT(*) AS tous,
		SUM(($etat) = 'solde') AS solde, SUM(($etat) = 'partiel') AS partiel, SUM(($etat) = 'aucun') AS aucun,
		SUM(a.a_verifier > 0) AS a_verifier $from WHERE $sans_etat", ARRAY_A ); // phpcs:ignore -- fragments préparés ci-dessus
	$compteurs = array_map( 'intval', (array) $c ) + $vide['compteurs'];

	$where_etat = $sans_etat;
	if ( 'a_verifier' === $f['statut'] ) {
		$where_etat .= ' AND a.a_verifier > 0';
	} elseif ( $f['statut'] ) {
		$where_etat .= $wpdb->prepare( " AND ($etat) = %s", $f['statut'] ); // phpcs:ignore
	}
	$total = 'a_verifier' === $f['statut'] ? $compteurs['a_verifier'] : ( $f['statut'] ? $compteurs[ $f['statut'] ] : $compteurs['tous'] );
	$pages = max( 1, (int) ceil( $total / UEB_ETUDIANTS_PAR_PAGE ) );
	$page  = min( $f['p'], $pages );

	$limite = $toutes ? '' : $wpdb->prepare( ' LIMIT %d OFFSET %d', UEB_ETUDIANTS_PAR_PAGE, ( $page - 1 ) * UEB_ETUDIANTS_PAR_PAGE );
	$lignes = $wpdb->get_results(
		"SELECT q.id, q.compte_id, q.identifiant, q.type_identifiant, q.nom, q.prenom, q.sexe, q.etablissement,
		        q.filiere_id, q.departement, q.parcours, q.situation,
		        a.prepare, a.verifie, a.en_verification, a.a_verifier, a.a_corriger, ($etat) AS etat
		 $from WHERE $where_etat ORDER BY q.nom, q.prenom, q.id$limite" // phpcs:ignore -- fragments préparés ci-dessus
	);

	/* Filières proposées : celles des inscrits de l'année dans la portée, par établissement. */
	$filieres = array();
	foreach ( $wpdb->get_results( "SELECT q.etablissement, q.filiere_id, q.departement, COUNT(*) AS n $from WHERE $portee AND q.filiere_id IS NOT NULL GROUP BY q.etablissement, q.filiere_id, q.departement ORDER BY q.etablissement, q.departement" ) as $l ) { // phpcs:ignore
		$filieres[ $l->etablissement ][ (int) $l->filiere_id ] = array( $l->departement, (int) $l->n );
	}

	return array( 'lignes' => $lignes, 'total' => (int) $total, 'compteurs' => $compteurs, 'filieres' => $filieres, 'page' => $page, 'pages' => $pages );
}

/** Adresse de la liste avec ces filtres (les valeurs vides et la page 1 sont omises). */
function ueb_etudiants_url( $base, array $args ) {
	$args = array_filter( $args, static fn( $v ) => '' !== (string) $v && 0 !== $v );
	if ( isset( $args['p'] ) && 1 === (int) $args['p'] ) {
		unset( $args['p'] );
	}
	return add_query_arg( $args, $base );
}

/**
 * Affiche le registre filtrable (templates/composants/etudiants.php).
 *
 * @param array $o url : adresse de la page ; params : paramètres fixes de la
 *                 vue (ex. array( 'vue' => 'etudiants' )) ; etabs : sigles de
 *                 la portée (jamais plus que ceux autorisés au compte).
 */
function ueb_vue_etudiants( array $o ) {
	require UEB_INSC_DIR . '/templates/composants/etudiants.php';
}

/* ==========================================================================
   Exports : PDF et Word au format institutionnel, Excel en données brutes.
   Rendu commun avec le suivi des paiements (inc/administration-exports.php).
   ========================================================================== */

/**
 * Établissements dont le compte courant peut voir les étudiants : tous pour
 * l'administrateur, sa portée pour un rôle qui a la permission, null sinon.
 */
function ueb_etudiants_portee() {
	return ueb_peut( 'ueb_voir_etudiants' ) ? ueb_etabs_autorises() : null;
}

/** Lien d'export de la liste affichée (filtres compris), avec jeton anti-rejeu. */
function ueb_etudiants_export_url( $url, array $args, $format ) {
	return wp_nonce_url( ueb_etudiants_url( $url, array_merge( $args, array( 'export' => $format ) ) ), 'ueb_export_etudiants', 'jeton' );
}

/** Jeu de données du rapport : la liste filtrée entière, son bilan et ses notes. */
function ueb_etudiants_rapport( array $f, array $etabs ) {
	$r     = ueb_etudiants( $f, $etabs, true );
	$date  = new DateTimeImmutable( 'now', wp_timezone() );
	$annee = array( 'code' => $f['annee'], 'libelle' => str_replace( '-', ' – ', $f['annee'] ) );
	$sigle = $f['etab'] ?: ( 1 === count( $etabs ) ? $etabs[0] : '' );
	$e     = $sigle ? ueb_etablissement( $sigle ) : null;

	/* Filtres appliqués, avec les mots de l'écran. */
	$filiere = '';
	foreach ( $r['filieres'] as $liste ) {
		$filiere = $filiere ?: ( $liste[ $f['filiere'] ][0] ?? '' );
	}
	$filtres = array_filter( array(
		$f['filiere'] ? 'filière ' . ( $filiere ?: 'choisie' ) : '',
		$f['niveau'] ? 'niveau ' . $f['niveau'] : '',
		array( 'F' => 'femmes', 'M' => 'hommes' )[ $f['sexe'] ] ?? '',
		$f['situation'] ? mb_strtolower( UEB_ETUDIANTS_SITUATIONS[ $f['situation'] ] ) : '',
		$f['statut'] ? mb_strtolower( UEB_ETUDIANTS_STATUTS[ $f['statut'] ] ) : '',
		'' !== $f['q'] ? 'recherche « ' . $f['q'] . ' »' : '',
	) );
	$portee = $e ? $e['fr'] . ' (' . $sigle . ')' : ( count( $etabs ) === count( ueb_etablissements() ) ? 'toute l’université' : implode( ', ', $etabs ) );

	$etats    = array( 'solde' => 'Droits soldés', 'partiel' => 'Paiement partiel', 'aucun' => 'Aucun paiement vérifié' );
	$nombre   = count( $r['lignes'] );
	$par_etat = array_fill_keys( array_keys( $etats ), 0 );
	$a_verif  = 0;
	$sexes    = array( 'F' => 0, 'M' => 0 );
	$verifie  = 0;
	$attente  = 0;
	$lignes   = array();
	foreach ( $r['lignes'] as $i => $l ) {
		$par_etat[ $l->etat ]++;
		$a_verif += (int) $l->a_verifier > 0 ? 1 : 0;
		if ( isset( $sexes[ $l->sexe ] ) ) {
			$sexes[ $l->sexe ]++;
		}
		$verifie += (int) $l->verifie;
		$attente += (int) $l->en_verification;
		$lignes[] = array(
			(string) ( $i + 1 ),
			$l->identifiant,
			trim( $l->nom . ' ' . $l->prenom ),
			$l->sexe,
			$l->etablissement,
			$l->departement,
			$l->parcours,
			UEB_ETUDIANTS_SITUATIONS[ $l->situation ] ?? '',
			(int) $l->verifie,
			(int) $l->en_verification,
			$etats[ $l->etat ] . ( (int) $l->a_verifier ? ', reçu à vérifier' : '' ),
		);
	}
	$part = static fn( $n ) => $nombre ? ueb_pourcent( 100 * $n / $nombre ) . ' de la liste' : '';

	return array(
		'titre'      => 'Liste des étudiants inscrits',
		'sous_titre' => 'Étudiants qui ont préparé un quitus de droits universitaires, année académique ' . $annee['libelle'],
		'perimetre'  => $portee . ( $filtres ? ' ; ' . implode( ', ', $filtres ) : '' ),
		'etab'       => $e,
		'couleur'    => $e ? $e['couleur'] : UEB_UNIVERSITE['couleur'],
		'edite'      => ueb_adm_date_longue( $date ) . ' à ' . $date->format( 'H' ) . ' h ' . $date->format( 'i' ),
		'fait_le'    => ueb_adm_date_longue( $date ),
		'annee'      => $annee,
		'fichier'    => 'etudiants-inscrits-' . $annee['code'] . '-' . ( $sigle ? strtolower( $sigle ) : 'universite' ),
		'bilan'      => array(
			array( 'Étudiants de la liste', $nombre, 'nombre', $sexes['F'] . ' femmes et ' . $sexes['M'] . ' hommes', 'étudiants' ),
			array( 'Droits soldés', $par_etat['solde'], 'nombre', $part( $par_etat['solde'] ), 'étudiants' ),
			array( 'Paiement partiel', $par_etat['partiel'], 'nombre', $part( $par_etat['partiel'] ), 'étudiants' ),
			array( 'Aucun paiement vérifié', $par_etat['aucun'], 'nombre', $part( $par_etat['aucun'] ), 'étudiants' ),
			array( 'Reçu à vérifier', $a_verif, 'nombre', 'Au moins un reçu déposé, pas encore contrôlé par la scolarité', 'étudiants' ),
			array( 'Droits vérifiés', $verifie, 'fcfa', 'Paiements contrôlés par la scolarité', 'FCFA' ),
			array( 'Droits en vérification', $attente, 'fcfa', 'Reçus déposés, pas encore contrôlés', 'FCFA' ),
		),
		'tableaux'   => array(
			array(
				'titre'    => 'Registre des inscrits',
				'feuille'  => 'Étudiants',
				'colonnes' => array(
					array( 'N°', 'valeur', 4 ),
					array( 'Matricule', 'texte', 11 ),
					array( 'Nom et prénoms', 'texte', 21 ),
					array( 'Sexe', 'texte', 4 ),
					array( 'Établ.', 'texte', 6 ),
					array( 'Filière', 'texte', 19 ),
					array( 'Niveau', 'texte', 6 ),
					array( 'Situation', 'texte', 10 ),
					array( 'Vérifié (FCFA)', 'fcfa', 9 ),
					array( 'En vérification (FCFA)', 'fcfa', 10 ),
					array( 'État', 'texte', 14 ),
				),
				'lignes'   => $lignes,
				'pieds'    => $nombre ? array( array( '', '', 'Total : ' . ueb_adm_accord( $nombre, 'étudiant', 'étudiants' ), '', '', '', '', '', $verifie, $attente, '' ) ) : array(),
				'note'     => 'Triés par nom. Informations du dernier quitus de droits de l’année de chaque étudiant.',
			),
		),
		'notes'      => array(
			array( 'Inscrits', 'Un étudiant figure dans la liste dès qu’il a préparé un quitus de droits universitaires pour l’année. Son établissement, sa filière et son niveau sont ceux de son dernier quitus.' ),
			array( 'Droits', 'Seuls les paiements contrôlés par la scolarité sont vérifiés. Les droits sont soldés à ' . ueb_fcfa( UEB_DROITS_CLASSIQUES ) . ' vérifiés ; un reçu déposé ne vaut pas validation.' ),
			array( 'Filtres', $filtres ? 'Ce document reprend la liste affichée au moment de l’export : ' . implode( ', ', $filtres ) . '.' : 'Ce document reprend la liste entière du périmètre, sans filtre.' ),
			array( 'Usage', 'Document à usage interne : il contient des données personnelles d’étudiants.' ),
		),
	);
}

/* Téléchargement : ?vue=etudiants&export=pdf|docx|xlsx&<filtres>&jeton=… dans l'Administration
   (pilotage, scolarité ou Direction). La portée est celle
   du compte connecté, jamais celle de l'adresse. */
add_action( 'template_redirect', function () {
	if ( ! isset( $_GET['export'] ) || 'etudiants' !== sanitize_key( $_GET['vue'] ?? '' ) ) { // phpcs:ignore
		return;
	}
	if ( ! is_page_template( 'page-administration.php' ) ) { // tous les espaces : /administration/
		return;
	}
	$etabs = ueb_etudiants_portee();
	if ( null === $etabs ) {
		wp_die( 'Cet export est réservé aux comptes qui peuvent voir la liste des étudiants.', 'Accès refusé', array( 'response' => 403 ) );
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['jeton'] ?? '' ) ), 'ueb_export_etudiants' ) ) {
		wp_die( 'Ce lien d’export a expiré. Recharge la liste des étudiants puis relance l’export.', 'Lien expiré', array( 'response' => 403 ) );
	}
	$format = sanitize_key( $_GET['export'] );
	if ( ! in_array( $format, UEB_EXPORT_FORMATS, true ) ) {
		wp_die( 'Format d’export inconnu.', 'Export', array( 'response' => 400 ) );
	}
	if ( function_exists( 'set_time_limit' ) ) {
		set_time_limit( 300 ); // une liste de plusieurs milliers d'étudiants
	}
	$d = ueb_etudiants_rapport( ueb_etudiants_filtres( $etabs ), $etabs );
	nocache_headers();
	if ( 'pdf' === $format ) {
		ueb_adm_export_pdf( $d );
	} elseif ( 'docx' === $format ) {
		ueb_adm_export_docx( $d );
	} else {
		ueb_adm_export_xlsx( $d );
	}
	exit;
}, 19 );
