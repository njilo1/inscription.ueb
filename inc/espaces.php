<?php
/**
 * Back-office unique : une seule interface, l'Administration (/administration/),
 * dont les onglets dépendent de ce que le compte connecté a le droit de faire.
 *
 * Les écrans de travail sont rangés par « espace » (paramètre ?espace=) :
 *   - admin     : pilotage de l'université (super-administrateur) ;
 *   - scolarite : quitus, reçus, paiements, étudiants, IPES sous tutelle
 *                 (scolarité, Régisseur CMS, suivi financier…) ;
 *   - direction : rôles et comptes du personnel ;
 *   - cellule   : comptes étudiants ;
 *   - ipes      : l'espace d'un IPES (son administrateur).
 * Tous s'ouvrent depuis /administration/ : page-administration.php vérifie la
 * connexion, puis affiche l'espace demandé s'il est permis, sinon le premier
 * espace permis. La barre latérale (ueb_navigation_administration) réunit
 * tous les onglets permis, quel que soit l'espace affiché.
 *
 * Comme partout : une capacité et une portée, jamais un nom de rôle. Le
 * super-administrateur (capacité « manage_options ») voit tout.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

/** Espaces ouverts à un compte, dans l'ordre où ils sont proposés. */
function ueb_espaces_du_compte( $user_id = 0 ) {
	$user_id = $user_id ?: get_current_user_id();
	if ( ! $user_id || ueb_agent_suspendu( $user_id ) ) {
		return array();
	}
	if ( user_can( $user_id, 'manage_options' ) ) {
		return array( 'admin', 'scolarite', 'direction', 'cellule' );
	}
	$espaces = array();
	if ( ueb_est_scolarite( $user_id ) && ueb_etabs_autorises( $user_id ) ) {
		$espaces[] = 'scolarite';
	}
	if ( user_can( $user_id, UEB_CAP_DIRECTION ) ) {
		$espaces[] = 'direction';
	}
	/* La scolarité ne gère pas les comptes étudiants : elle garde la permission
	   pour la déléguer à sa cellule informatique (ueb_creer_agents), mais pas
	   l'espace. Une cellule qui consulte les droits universitaires le garde. */
	if ( ueb_est_cellule( $user_id ) && ! user_can( $user_id, 'ueb_creer_agents' ) && ueb_etabs_autorises( $user_id ) ) {
		$espaces[] = 'cellule';
	}
	if ( ueb_ipes_du_compte( $user_id ) ) {
		$espaces[] = 'ipes';
	}
	return $espaces;
}

/**
 * Espace affiché sur /administration/ : celui demandé (?espace=) s'il est
 * permis, sinon le premier permis. Vide hors de l'Administration ou sans accès.
 */
function ueb_espace_courant() {
	static $espace = null;
	/* Avant la requête principale, la page n’'est pas encore connue : on ne fige rien. */
	if ( ! did_action( 'wp' ) ) {
		return '';
	}
	if ( null === $espace ) {
		$espace = '';
		if ( is_page_template( 'page-administration.php' ) ) {
			$permis = ueb_espaces_du_compte();
			$demande = sanitize_key( $_GET['espace'] ?? '' ); // phpcs:ignore -- lecture seule
			$espace  = in_array( $demande, $permis, true ) ? $demande : ( $permis[0] ?? '' );
		}
	}
	return $espace;
}

/**
 * Champ caché « espace » d'un formulaire GET : un navigateur ignore les
 * paramètres écrits dans l'adresse (action) d'un formulaire GET, il faut
 * donc renvoyer l'espace affiché avec les champs du formulaire.
 */
function ueb_champ_espace() {
	$espace = ueb_espace_courant();
	if ( $espace && 'admin' !== $espace ) {
		echo '<input type="hidden" name="espace" value="' . esc_attr( $espace ) . '">';
	}
}

/**
 * Vue affichée dans l'espace courant : celle de l'adresse (?vue=), sinon la
 * vue par défaut de l'espace. Sert à la barre latérale et au chargement des
 * feuilles de style et des scripts (inc/assets.php).
 */
function ueb_vue_courante() {
	$espace = ueb_espace_courant();
	$vue    = sanitize_key( $_GET['vue'] ?? '' ); // phpcs:ignore -- lecture seule
	if ( 'scolarite' === $espace && isset( $_GET['quitus'] ) ) { // phpcs:ignore
		return 'quitus';
	}
	if ( 'direction' === $espace && 'role' === $vue ) {
		return 'roles';
	}
	if ( '' !== $vue ) {
		return $vue;
	}
	switch ( $espace ) {
		case 'scolarite':
			/* Tableau de bord des droits (scolarité) ou des frais médicaux (CMS). */
			return ueb_types_stats_visibles() ? 'bord' : ( ueb_peut( 'ueb_voir_paiements' ) ? 'paiements' : ( ueb_peut( 'ueb_voir_ipes' ) ? 'ipes' : 'etudiants' ) );
		case 'direction':
			return 'roles';
		case 'cellule':
			return 'comptes';
		default:
			return 'bord';
	}
}

/** Adresse d'un espace de l'Administration (l'espace « admin » n'a pas de paramètre). */
function ueb_url_espace_admin( $espace, array $args = array() ) {
	$base = ueb_url_administration();
	return add_query_arg( 'admin' === $espace ? $args : array( 'espace' => $espace ) + $args, $base );
}

/**
 * Onglets de la barre latérale : tous ceux que le compte peut ouvrir, rangés
 * par profil (Pilotage, Scolarité, CMS, Direction…). Chaque entrée : url,
 * libelle, icone, actif ; une entrée array( 'groupe' => …, 'icone' => … )
 * ouvre un groupe, que la barre affiche en menu déroulant (ueb_bo_barre).
 */
function ueb_navigation_administration() {
	$espace = ueb_espace_courant();
	$vue    = ueb_vue_courante();
	/* Tableau de bord et reçus : l'onglet du type affiché (droits ou frais médicaux) reste surligné, fiche comprise. */
	$type   = 'scolarite' === $espace && in_array( $vue, array( 'bord', 'quitus' ), true ) ? ueb_type_recus_courant() : '';
	$lien = static fn( $e, $v, $libelle, $icone, array $args = array() ) => array(
		'url'     => ueb_url_espace_admin( $e, ( in_array( $v, array( 'bord', 'roles', 'comptes' ), true ) ? array() : array( 'vue' => $v ) ) + $args ),
		'libelle' => $libelle,
		'icone'   => $icone,
		/* Actif : même espace, même vue et mêmes paramètres (« type » sépare les deux onglets des quitus). */
		'actif'   => $e === $espace && $v === $vue && $type === (string) ( $args['type'] ?? '' ),
	);
	$permis  = ueb_espaces_du_compte();
	$admin   = in_array( 'admin', $permis, true );
	$groupes = array(); // titre => array( icône du groupe, ses onglets )
	/* Centre médico-social : son tableau de bord et ses reçus (frais médicaux). */
	$cms     = array(
		$lien( 'scolarite', 'bord', 'Tableau de bord', 'tableau', array( 'type' => 'medicaux' ) ),
		$lien( 'scolarite', 'quitus', 'Reçus', 'recu', array( 'type' => 'medicaux' ) ),
	);

	if ( $admin ) {
		/* Super-administrateur : tous les onglets de tous les espaces (« Changer de
		   profil », en bas de la barre, filtre la barre comme pour un rôle). */
		$groupes['Pilotage'] = array( 'tableau', array(
			$lien( 'admin', 'bord', 'Tableau de bord', 'tableau' ),
			$lien( 'admin', 'paiements', 'Paiements', 'banque' ),
			$lien( 'admin', 'etudiants', 'Étudiants UEB', 'diplome' ),
			$lien( 'admin', 'scolarites', 'Personnel', 'groupe' ),
			$lien( 'admin', 'filieres', 'Filières', 'fichier' ),
			$lien( 'admin', 'ipes', 'IPES', 'ecole' ),
			$lien( 'admin', 'exercices', 'Exercice', 'calendrier' ),
		) );
		$groupes['Scolarité'] = array( 'tampon', array(
			$lien( 'scolarite', 'bord', 'Tableau de bord', 'tampon', array( 'type' => 'droits' ) ),
			$lien( 'scolarite', 'quitus', 'Reçus', 'recu', array( 'type' => 'droits' ) ),
			$lien( 'scolarite', 'paiements', 'Paiements', 'banque' ),
			$lien( 'scolarite', 'etudiants', 'Étudiants UEB', 'diplome' ),
			$lien( 'scolarite', 'ipes', 'IPES sous tutelle', 'ecole' ),
			$lien( 'scolarite', 'cellule', 'Comptes du personnel', 'cle' ),
			$lien( 'scolarite', 'securite', 'Sécurité', 'cadenas' ),
		) );
		$groupes['CMS'] = array( 'sante', $cms );
	} elseif ( in_array( 'scolarite', $permis, true ) ) {
		/* Tableau de bord : reçus consultés ou statistiques seules ; onglet Reçus : la seule consultation des reçus. */
		$stats  = ueb_types_stats_visibles();
		$recus  = ueb_types_quitus_visibles();
		$droits = in_array( 'droits', $stats, true );
		/* Sans les droits universitaires, l'espace ne montre que le CMS : il en prend le nom. */
		$seul_cms = ! $droits && in_array( 'medicaux', $stats, true );
		$groupes[ $seul_cms ? 'CMS' : 'Scolarité' ] = array( $seul_cms ? 'sante' : 'tampon', array(
			$droits ? $lien( 'scolarite', 'bord', 'Tableau de bord', 'tampon', array( 'type' => 'droits' ) ) : ( $seul_cms ? $cms[0] : null ),
			in_array( 'droits', $recus, true ) ? $lien( 'scolarite', 'quitus', 'Reçus', 'recu', array( 'type' => 'droits' ) ) : ( $seul_cms && in_array( 'medicaux', $recus, true ) ? $cms[1] : null ),
			ueb_peut( 'ueb_voir_paiements' ) ? $lien( 'scolarite', 'paiements', 'Paiements', 'banque' ) : null,
			ueb_peut( 'ueb_voir_etudiants' ) ? $lien( 'scolarite', 'etudiants', 'Étudiants UEB', 'diplome' ) : null,
			ueb_peut( 'ueb_voir_ipes' ) ? $lien( 'scolarite', 'ipes', 'IPES', 'ecole' ) : null,
			ueb_peut( 'ueb_creer_agents' ) ? $lien( 'scolarite', 'cellule', 'Comptes du personnel', 'cle' ) : null,
		) );
	}
	/* Rôle qui voit aussi les reçus du CMS : son tableau de bord et ses reçus dans un groupe CMS. */
	if ( ! $admin && in_array( 'scolarite', $permis, true ) && count( ueb_types_stats_visibles() ) > 1 ) {
		$groupes['CMS'] = array( 'sante', array( $cms[0], in_array( 'medicaux', ueb_types_quitus_visibles(), true ) ? $cms[1] : null ) );
	}
	if ( in_array( 'direction', $permis, true ) ) {
		$groupes['Direction'] = array( 'bouclier', array(
			$lien( 'direction', 'roles', 'Rôles et accès', 'bouclier' ),
			$lien( 'direction', 'personnel', 'Comptes', 'groupe' ),
			$admin || ( ! in_array( 'scolarite', $permis, true ) && ueb_peut( 'ueb_voir_etudiants' ) ) ? $lien( 'direction', 'etudiants', 'Étudiants UEB', 'diplome' ) : null,
			$admin ? $lien( 'direction', 'securite', 'Sécurité', 'cadenas' ) : null,
		) );
	}
	if ( in_array( 'cellule', $permis, true ) ) {
		$groupes['Comptes étudiants'] = array( 'utilisateur', array(
			$lien( 'cellule', 'comptes', 'Comptes étudiants', 'utilisateur' ),
			$admin ? $lien( 'cellule', 'securite', 'Sécurité', 'cadenas' ) : null,
		) );
	}
	if ( in_array( 'ipes', $permis, true ) ) {
		$groupes['IPES'] = array( 'ecole', array(
			$lien( 'ipes', 'bord', 'Tableau de bord', 'tableau' ),
			$lien( 'ipes', 'etudiants', 'Étudiants', 'groupe' ),
			$lien( 'ipes', 'bordereaux', 'Bordereaux', 'recu' ),
		) );
	}

	$groupes = array_filter(
		array_map( static fn( $g ) => array( $g[0], array_values( array_filter( $g[1] ) ) ), $groupes ),
		static fn( $g ) => $g[1]
	);
	/* Mot de passe : à la fin du groupe s'il n'y en a qu'un, sinon en dernier, à
	   part (le super-administrateur l'a déjà dans chaque espace). */
	$e = current( array_intersect( array( 'scolarite', 'direction', 'cellule', 'ipes' ), $permis ) );
	if ( ! $admin && $groupes && $e ) {
		$securite = $lien( $e, 'securite', 'Sécurité', 'cadenas' );
		if ( 1 === count( $groupes ) ) {
			$groupes[ array_key_first( $groupes ) ][1][] = $securite;
		} else {
			$groupes['Mon compte'] = array( 'cadenas', array( $securite ) );
		}
	}
	$liens = array();
	foreach ( $groupes as $titre => $groupe ) {
		$liens[] = array( 'groupe' => $titre, 'icone' => $groupe[0] );
		$liens   = array_merge( $liens, $groupe[1] );
	}
	return $liens;
}
