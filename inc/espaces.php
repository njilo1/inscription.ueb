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
	if ( ueb_est_cellule( $user_id ) && ueb_etabs_autorises( $user_id ) ) {
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

/** Adresse d'un espace de l'Administration (l'espace « admin » n'a pas de paramètre). */
function ueb_url_espace_admin( $espace, array $args = array() ) {
	$base = ueb_url_administration();
	return add_query_arg( 'admin' === $espace ? $args : array( 'espace' => $espace ) + $args, $base );
}

/**
 * Onglets de la barre latérale : tous ceux que le compte peut ouvrir,
 * groupés quand il en a plusieurs sortes. Chaque entrée : url, libelle,
 * icone, actif ; une entrée array( 'groupe' => … ) ouvre un groupe.
 */
function ueb_navigation_administration() {
	$espace = ueb_espace_courant();
	$vue    = sanitize_key( $_GET['vue'] ?? '' ); // phpcs:ignore -- lecture seule
	/* Vue affichée, avec la vue par défaut de chaque espace. */
	if ( 'scolarite' === $espace && isset( $_GET['quitus'] ) ) { // phpcs:ignore
		$vue = 'quitus';
	} elseif ( 'scolarite' === $espace && '' === $vue ) {
		$vue = ueb_peut( UEB_CAP_GESTION ) ? 'bord' : ( ueb_types_quitus_visibles() ? 'quitus' : ( ueb_peut( 'ueb_voir_paiements' ) ? 'paiements' : ( ueb_peut( 'ueb_voir_ipes' ) ? 'ipes' : 'etudiants' ) ) );
	} elseif ( 'direction' === $espace && in_array( $vue, array( '', 'role' ), true ) ) {
		$vue = 'roles';
	} elseif ( 'cellule' === $espace && '' === $vue ) {
		$vue = 'comptes';
	} elseif ( in_array( $espace, array( 'admin', 'ipes' ), true ) && '' === $vue ) {
		$vue = 'bord';
	}
	$lien = static fn( $e, $v, $libelle, $icone, array $args = array() ) => array(
		'url'     => ueb_url_espace_admin( $e, ( in_array( $v, array( 'bord', 'roles', 'comptes' ), true ) ? array() : array( 'vue' => $v ) ) + $args ),
		'libelle' => $libelle,
		'icone'   => $icone,
		'actif'   => $e === $espace && $v === $vue,
	);
	$permis = ueb_espaces_du_compte();
	$admin  = in_array( 'admin', $permis, true );
	$groupes = array();

	if ( $admin ) {
		$groupes['Pilotage'] = array(
			$lien( 'admin', 'bord', 'Tableau de bord', 'tableau' ),
			$lien( 'admin', 'paiements', 'Paiements', 'banque' ),
			$lien( 'admin', 'etudiants', 'Étudiants UEB', 'diplome' ),
			$lien( 'admin', 'scolarites', 'Personnel', 'groupe' ),
			$lien( 'admin', 'filieres', 'Filières', 'fichier' ),
			$lien( 'admin', 'ipes', 'IPES', 'ecole' ),
		);
		$groupes['Vérification'] = array( $lien( 'scolarite', 'quitus', 'Quitus et reçus', 'recu' ) );
	} elseif ( in_array( 'scolarite', $permis, true ) ) {
		$groupes['Scolarité'] = array(
			ueb_peut( UEB_CAP_GESTION ) ? $lien( 'scolarite', 'bord', 'Tableau de bord', 'tampon' ) : null,
			ueb_types_quitus_visibles() ? $lien( 'scolarite', 'quitus', ueb_peut( UEB_CAP_GESTION ) ? 'Quitus' : 'Reçus CMS', 'recu' ) : null,
			ueb_peut( 'ueb_voir_paiements' ) ? $lien( 'scolarite', 'paiements', 'Paiements', 'banque' ) : null,
			ueb_peut( 'ueb_voir_etudiants' ) ? $lien( 'scolarite', 'etudiants', 'Étudiants UEB', 'diplome' ) : null,
			ueb_peut( 'ueb_voir_ipes' ) ? $lien( 'scolarite', 'ipes', 'IPES', 'ecole' ) : null,
			ueb_peut( 'ueb_creer_agents' ) ? $lien( 'scolarite', 'cellule', 'Comptes du personnel', 'cle' ) : null,
		);
	}
	if ( in_array( 'direction', $permis, true ) ) {
		$groupes['Direction'] = array(
			$lien( 'direction', 'roles', 'Rôles et accès', 'bouclier' ),
			$admin ? null : $lien( 'direction', 'personnel', 'Comptes', 'groupe' ),
			! $admin && ! in_array( 'scolarite', $permis, true ) && ueb_peut( 'ueb_voir_etudiants' ) ? $lien( 'direction', 'etudiants', 'Étudiants UEB', 'diplome' ) : null,
		);
	}
	if ( in_array( 'cellule', $permis, true ) ) {
		$groupes['Comptes étudiants'] = array( $lien( 'cellule', 'comptes', 'Comptes étudiants', 'utilisateur' ) );
	}
	if ( in_array( 'ipes', $permis, true ) ) {
		$groupes['IPES'] = array(
			$lien( 'ipes', 'bord', 'Tableau de bord', 'tableau' ),
			$lien( 'ipes', 'etudiants', 'Étudiants', 'groupe' ),
			$lien( 'ipes', 'bordereaux', 'Bordereaux', 'recu' ),
		);
	}
	/* Mot de passe : une seule entrée, dans le premier espace qui la propose (pas pour le super-administrateur). */
	if ( ! $admin ) {
		foreach ( array( 'scolarite', 'direction', 'cellule', 'ipes' ) as $e ) {
			if ( in_array( $e, $permis, true ) ) {
				$groupes['Compte'] = array( $lien( $e, 'securite', 'Sécurité', 'cadenas' ) );
				break;
			}
		}
	}

	/* Un seul groupe : pas d'intertitre. */
	$groupes = array_filter( array_map( static fn( $g ) => array_values( array_filter( $g ) ), $groupes ) );
	$liens   = array();
	$multi   = count( array_diff( array_keys( $groupes ), array( 'Compte' ) ) ) > 1;
	foreach ( $groupes as $titre => $entrees ) {
		if ( $multi && $liens ) {
			$liens[] = array( 'groupe' => $titre );
		}
		$liens = array_merge( $liens, $entrees );
	}
	return $liens;
}
