<?php
/**
 * Rôles du back-office, dynamiques.
 *
 * Aucun nom de rôle n'est écrit dans le code : la Direction crée, nomme et
 * modifie les rôles depuis son interface (page-direction.php). Chaque rôle
 * du registre (option « ueb_roles_registre ») est un vrai rôle WordPress
 * dont les capacités sont prises dans une liste blanche (ueb_permissions()),
 * avec une portée : un établissement (fixé sur chaque compte), plusieurs
 * établissements (fixés dans le rôle) ou tous.
 *
 * Toute décision d'accès vérifie une CAPACITÉ et une PORTÉE, jamais un nom
 * de rôle. Les fonctions historiques (ueb_est_scolarite(), ueb_est_cellule(),
 * ueb_etab_agent()…) restent disponibles comme alias.
 *
 * Migration (version 3, additive) : les rôles déjà présents en base qui
 * portent une capacité du back-office sont détectés PAR LEUR CAPACITÉ,
 * repris dans le registre sous le même identifiant et le même nom — aucun
 * compte n'est modifié ni supprimé, aucune reconnexion n'est nécessaire.
 *
 * Un agent suspendu (méta « ueb_agent_suspendu ») garde son compte — ses
 * décisions restent tracées — mais perd tout accès.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

/* Capacités historiques : conservées telles quelles (comptes et code existants). */
const UEB_CAP_GESTION   = 'ueb_gerer_quitus';
const UEB_CAP_COMPTES   = 'ueb_gerer_comptes';
const UEB_CAP_DIRECTION = 'ueb_diriger';
const UEB_ROLES_VERSION = '3';
/* Identifiants des deux rôles historiques. Ils ne servent plus qu'à la
   compatibilité : le code ne teste plus jamais un rôle par son nom. */
const UEB_ROLE_SCOLARITE = 'ueb_scolarite';
const UEB_ROLE_CELLULE   = 'ueb_cellule';
/* Portée vide : ne correspond à aucun établissement (jamais « tous »). */
const UEB_AUCUN_ETAB = '-';

/**
 * Liste blanche des permissions attribuables, groupées par thème. Une entrée
 * par section ou action réelle du back-office. « requiert » : permission sans
 * laquelle celle-ci n'a pas de sens (cochée automatiquement dans l'assistant).
 */
function ueb_permissions() {
	return array(
		'ueb_gerer_quitus'    => array( 'groupe' => 'Quitus et paiements', 'libelle' => 'Consulter les quitus', 'phrase' => 'consulter les quitus, leurs reçus et leurs PDF', 'aide' => 'Tableau de bord, liste des quitus, fiche d’un dossier, reçus envoyés.', 'icone' => 'recu' ),
		'ueb_decider_quitus'  => array( 'groupe' => 'Quitus et paiements', 'libelle' => 'Rendre les décisions', 'phrase' => 'valider un paiement ou renvoyer un reçu à l’étudiant', 'aide' => 'Boutons « Paiement vérifié », « Renvoyer à l’étudiant », « Annuler la décision ».', 'icone' => 'tampon', 'requiert' => 'ueb_gerer_quitus' ),
		'ueb_voir_paiements'  => array( 'groupe' => 'Quitus et paiements', 'libelle' => 'Suivre les paiements', 'phrase' => 'suivre le recouvrement des droits par établissement et filière', 'aide' => 'Vue « Paiements » : montants attendus, encaissés, taux.', 'icone' => 'banque' ),
		'ueb_gerer_comptes'   => array( 'groupe' => 'Comptes étudiants', 'libelle' => 'Gérer les comptes étudiants', 'phrase' => 'créer, réinitialiser ou suspendre les comptes étudiants', 'aide' => 'Espace « Comptes étudiants » : recherche, mot de passe provisoire, suspension.', 'icone' => 'utilisateur' ),
		'ueb_creer_agents'    => array( 'groupe' => 'Personnel', 'libelle' => 'Créer des comptes pour son établissement', 'phrase' => 'créer des comptes pour son établissement, avec un rôle aux droits inférieurs', 'aide' => 'Comme la scolarité qui crée sa cellule informatique.', 'icone' => 'plus' ),
		UEB_CAP_DIRECTION     => array( 'groupe' => 'Direction', 'libelle' => 'Gérer les rôles et le personnel', 'phrase' => 'créer des rôles et des comptes du personnel, sans dépasser ses propres droits', 'aide' => 'Accès à l’interface Direction.', 'icone' => 'bouclier' ),
	);
}

/* ---------- Registre des rôles ---------- */

/**
 * Rôles du registre : slug => nom, portee (un | plusieurs | tous),
 * etablissements (pour « plusieurs »), permissions, historique, dates.
 */
function ueb_roles() {
	$registre = get_option( 'ueb_roles_registre', array() );
	return is_array( $registre ) ? $registre : array();
}

function ueb_role( $slug ) {
	return ueb_roles()[ $slug ] ?? null;
}

/** Enregistre (ou remplace) un rôle du registre et son rôle WordPress. */
function ueb_enregistrer_role( $slug, array $def ) {
	$registre          = ueb_roles();
	$registre[ $slug ] = $def;
	update_option( 'ueb_roles_registre', $registre, true );
	ueb_synchroniser_role( $slug, $def );
}

/**
 * Aligne le rôle WordPress sur sa définition : nom affiché, « read » et les
 * seules capacités de la liste blanche cochées. Les autres capacités ueb_*
 * éventuellement présentes sont retirées ; rien d'autre n'est touché.
 */
function ueb_synchroniser_role( $slug, array $def ) {
	$wp_roles = wp_roles();
	$caps     = array( 'read' => true );
	foreach ( $def['permissions'] as $cap ) {
		if ( isset( ueb_permissions()[ $cap ] ) ) {
			$caps[ $cap ] = true;
		}
	}
	$role = get_role( $slug );
	if ( ! $role ) {
		add_role( $slug, $def['nom'], $caps );
		return;
	}
	foreach ( array_keys( ueb_permissions() ) as $cap ) {
		if ( isset( $caps[ $cap ] ) ) {
			$role->add_cap( $cap );
		} elseif ( $role->has_cap( $cap ) ) {
			$role->remove_cap( $cap );
		}
	}
	$role->add_cap( 'read' );
	/* Nom affiché : wp_roles() n'a pas d'API de renommage, on met à jour sa copie persistée. */
	if ( ( $wp_roles->roles[ $slug ]['name'] ?? '' ) !== $def['nom'] ) {
		$wp_roles->roles[ $slug ]['name'] = $def['nom'];
		$wp_roles->role_names[ $slug ]    = $def['nom'];
		update_option( $wp_roles->role_key, $wp_roles->roles );
	}
}

/** Supprime un rôle du registre ; ses comptes doivent avoir été réaffectés avant. */
function ueb_retirer_role( $slug ) {
	$registre = ueb_roles();
	unset( $registre[ $slug ] );
	update_option( 'ueb_roles_registre', $registre, true );
	remove_role( $slug );
}

/** Nouvel identifiant de rôle, jamais dérivé du nom saisi. */
function ueb_nouveau_slug_role() {
	do {
		$slug = 'ueb_r_' . strtolower( wp_generate_password( 8, false, false ) );
	} while ( ueb_role( $slug ) || get_role( $slug ) );
	return $slug;
}

/* ---------- Migration, capacités de l'administrateur ---------- */

add_action( 'init', function () {
	$admin = get_role( 'administrator' );
	if ( $admin ) {
		foreach ( array_keys( ueb_permissions() ) as $cap ) {
			if ( ! $admin->has_cap( $cap ) ) {
				$admin->add_cap( $cap );
			}
		}
	}
	if ( get_option( 'ueb_insc_roles_version' ) === UEB_ROLES_VERSION ) {
		return;
	}
	ueb_migrer_roles_historiques();
	update_option( 'ueb_insc_roles_version', UEB_ROLES_VERSION );
}, 4 );

/**
 * Reprend dans le registre les rôles déjà en base qui portent une capacité du
 * back-office. Détection par capacité (jamais par nom), même identifiant, même
 * nom affiché, portée « un établissement » (la méta de chaque compte). Les
 * droits effectifs sont conservés à l'identique : un rôle qui examinait les
 * quitus garde l'examen, la décision, le suivi et la création de comptes pour
 * son établissement ; la capacité « comptes étudiants » est conservée là où
 * elle était.
 */
function ueb_migrer_roles_historiques() {
	$registre = ueb_roles();
	foreach ( wp_roles()->roles as $slug => $role ) {
		if ( 'administrator' === $slug || isset( $registre[ $slug ] ) ) {
			continue;
		}
		$caps = array_filter( (array) ( $role['capabilities'] ?? array() ) );
		if ( empty( $caps[ UEB_CAP_GESTION ] ) && empty( $caps[ UEB_CAP_COMPTES ] ) ) {
			continue;
		}
		/* Même jeu de capacités qu'avant (ueb_gerer_comptes compris : il est
		   nécessaire pour déléguer la gestion des comptes étudiants sans jamais
		   donner plus que ses propres droits), plus le détail des nouvelles
		   permissions qui couvrent ce que le rôle faisait déjà. */
		$permissions = ! empty( $caps[ UEB_CAP_GESTION ] )
			? array( UEB_CAP_GESTION, 'ueb_decider_quitus', 'ueb_voir_paiements', 'ueb_creer_agents' )
			: array();
		if ( ! empty( $caps[ UEB_CAP_COMPTES ] ) ) {
			$permissions[] = UEB_CAP_COMPTES;
		}
		$registre[ $slug ] = array(
			'nom'            => $role['name'],
			'portee'         => 'un',
			'etablissements' => array(),
			'permissions'    => $permissions,
			'historique'     => true,
			'cree_le'        => current_time( 'mysql' ),
			'modifie_le'     => current_time( 'mysql' ),
			'modifie_par'    => 0,
		);
	}
	update_option( 'ueb_roles_registre', $registre, true );
	foreach ( $registre as $slug => $def ) {
		ueb_synchroniser_role( $slug, $def );
	}
}

/* ---------- Qui est qui : capacités et portée ---------- */

function ueb_est_admin_ueb() {
	return is_user_logged_in() && current_user_can( 'manage_options' );
}

/** Vrai si le compte a été suspendu. */
function ueb_agent_suspendu( $user_id = 0 ) {
	return (bool) get_user_meta( $user_id ?: get_current_user_id(), 'ueb_agent_suspendu', true );
}

/** Rôle du registre porté par ce compte (slug), ou chaîne vide. */
function ueb_role_du_compte( $user_id = 0 ) {
	$user = get_userdata( $user_id ?: get_current_user_id() );
	if ( ! $user ) {
		return '';
	}
	foreach ( (array) $user->roles as $slug ) {
		if ( ueb_role( $slug ) ) {
			return $slug;
		}
	}
	return '';
}

/** Nom affiché du rôle d'un compte. */
function ueb_nom_role_du_compte( $user_id = 0 ) {
	$user = get_userdata( $user_id ?: get_current_user_id() );
	if ( $user && user_can( $user, 'manage_options' ) ) {
		return 'Administrateur';
	}
	$role = ueb_role( ueb_role_du_compte( $user_id ) );
	return $role ? $role['nom'] : 'Compte du personnel';
}

/**
 * Établissements que ce compte peut voir. Administrateur et portée « tous » :
 * tous les établissements, y compris ceux ajoutés plus tard à la configuration.
 */
function ueb_etabs_autorises( $user_id = 0 ) {
	$user_id = $user_id ?: get_current_user_id();
	$tous    = array_keys( ueb_etablissements() );
	if ( user_can( $user_id, 'manage_options' ) ) {
		return $tous;
	}
	$role = ueb_role( ueb_role_du_compte( $user_id ) );
	if ( ! $role ) {
		/* Compte hors registre (capacité posée à la main) : repli sur la méta historique. */
		$sigle = strtoupper( (string) get_user_meta( $user_id, 'ueb_etablissement', true ) );
		return ueb_etablissement( $sigle ) ? array( $sigle ) : array();
	}
	if ( 'tous' === $role['portee'] ) {
		return $tous;
	}
	if ( 'plusieurs' === $role['portee'] ) {
		return array_values( array_intersect( $tous, (array) $role['etablissements'] ) );
	}
	$sigle = strtoupper( (string) get_user_meta( $user_id, 'ueb_etablissement', true ) );
	return ueb_etablissement( $sigle ) ? array( $sigle ) : array();
}

/** Vrai si la portée de ce compte couvre tous les établissements. */
function ueb_portee_totale( $user_id = 0 ) {
	$user_id = $user_id ?: get_current_user_id();
	if ( user_can( $user_id, 'manage_options' ) ) {
		return true;
	}
	$role = ueb_role( ueb_role_du_compte( $user_id ) );
	return $role && 'tous' === $role['portee'];
}

/**
 * Établissement consulté par l'agent. Historique : chaîne vide = tous (un
 * administrateur ou une portée « tous » qui n'a rien choisi). Portée
 * « plusieurs » : l'établissement choisi dans le sélecteur (méta
 * « ueb_etab_courant »), toujours revalidé contre la portée. Aucun
 * établissement valable : UEB_AUCUN_ETAB, qui ne correspond à rien —
 * jamais « tous » par défaut.
 */
function ueb_etab_agent( $user_id = 0 ) {
	if ( ! $user_id && ueb_est_admin_ueb() ) {
		return '';
	}
	$user_id   = $user_id ?: get_current_user_id();
	$autorises = ueb_etabs_autorises( $user_id );
	if ( ! $autorises ) {
		return UEB_AUCUN_ETAB;
	}
	$courant = strtoupper( (string) get_user_meta( $user_id, 'ueb_etab_courant', true ) );
	if ( ueb_portee_totale( $user_id ) ) {
		return in_array( $courant, $autorises, true ) ? $courant : '';
	}
	if ( 1 === count( $autorises ) ) {
		return $autorises[0];
	}
	return in_array( $courant, $autorises, true ) ? $courant : $autorises[0];
}

/** Vrai si le compte courant peut agir sur cet établissement (portée, jamais le seul sélecteur). */
function ueb_peut_gerer_etab( $sigle ) {
	return in_array( strtoupper( (string) $sigle ), ueb_etabs_autorises(), true );
}

/**
 * LA vérification d'accès : compte connecté, non suspendu, qui a la capacité
 * et dont la portée couvre l'établissement demandé (s'il y en a un).
 */
function ueb_peut( $cap, $sigle = null ) {
	if ( ! is_user_logged_in() || ! current_user_can( $cap ) || ueb_agent_suspendu() ) {
		return false;
	}
	if ( ! current_user_can( 'manage_options' ) && ! ueb_etabs_autorises() ) {
		return false; // aucune portée : aucun accès
	}
	return null === $sigle || ueb_peut_gerer_etab( $sigle );
}

/* Sélecteur d'établissement (portée « plusieurs » ou « tous ») : ?ueb_etab=SIGLE
   mémorisé sur le compte après validation contre sa portée. */
add_action( 'init', function () {
	if ( ! isset( $_GET['ueb_etab'] ) || ! is_user_logged_in() || ueb_est_admin_ueb() ) {
		return;
	}
	$sigle = strtoupper( sanitize_key( wp_unslash( $_GET['ueb_etab'] ) ) );
	if ( '' === $sigle && ueb_portee_totale() ) {
		delete_user_meta( get_current_user_id(), 'ueb_etab_courant' );
	} elseif ( in_array( $sigle, ueb_etabs_autorises(), true ) ) {
		update_user_meta( get_current_user_id(), 'ueb_etab_courant', $sigle );
	}
	wp_safe_redirect( remove_query_arg( 'ueb_etab' ) );
	exit;
}, 6 );

/* ---------- Alias historiques, désormais fondés sur les capacités ---------- */

/** Accès à l'espace scolarité : examiner les quitus ou suivre les paiements (hors administrateur). */
function ueb_est_scolarite( $user_id = 0 ) {
	$user = get_userdata( $user_id ?: get_current_user_id() );
	return $user && ! user_can( $user, 'manage_options' ) && ( user_can( $user, UEB_CAP_GESTION ) || user_can( $user, 'ueb_voir_paiements' ) );
}

/** Accès à l'espace « comptes étudiants » (hors administrateur). */
function ueb_est_cellule( $user_id = 0 ) {
	$user = get_userdata( $user_id ?: get_current_user_id() );
	return $user && ! user_can( $user, 'manage_options' ) && user_can( $user, UEB_CAP_COMPTES );
}

/** Vrai si ce compte WordPress est un agent (porte un rôle du registre), hors administrateur. */
function ueb_est_agent( $user_id ) {
	$user = get_userdata( $user_id );
	return $user && ! user_can( $user, 'manage_options' ) && '' !== ueb_role_du_compte( $user->ID );
}

/** Vrai si le compte peut gérer les comptes étudiants. */
function ueb_est_gestionnaire_comptes() {
	return ueb_peut( UEB_CAP_COMPTES );
}

/* ---------- Comptes du personnel ---------- */

/** Tous les agents (comptes portant un rôle du registre), éventuellement d'un seul rôle. */
function ueb_agents( $slug = '' ) {
	$roles = $slug ? array( $slug ) : array_keys( ueb_roles() );
	return $roles ? get_users( array( 'role__in' => $roles, 'orderby' => 'display_name' ) ) : array();
}

/** Historique : agents qui examinent les quitus (détectés par capacité). */
function ueb_agents_scolarite() {
	return array_values( array_filter( ueb_agents(), static fn( $u ) => user_can( $u, UEB_CAP_GESTION ) ) );
}

/** Historique : agents qui gèrent les comptes étudiants sans examiner les quitus. */
function ueb_agents_cellule() {
	return array_values( array_filter( ueb_agents(), static fn( $u ) => user_can( $u, UEB_CAP_COMPTES ) && ! user_can( $u, UEB_CAP_GESTION ) ) );
}

/**
 * Rôles que le compte courant peut attribuer ou créer : jamais plus de
 * permissions que les siennes, jamais une portée plus large. $etab_seul :
 * pour la création « pour son établissement », seulement des rôles à portée
 * « un établissement » et sans la Direction.
 */
function ueb_roles_attribuables( $etab_seul = false ) {
	$roles = array();
	foreach ( ueb_roles() as $slug => $def ) {
		if ( ! ueb_role_dans_mes_droits( $def ) ) {
			continue;
		}
		if ( $etab_seul && ( 'un' !== $def['portee'] || in_array( UEB_CAP_DIRECTION, $def['permissions'], true ) || in_array( 'ueb_creer_agents', $def['permissions'], true ) ) ) {
			continue;
		}
		$roles[ $slug ] = $def;
	}
	return $roles;
}

/** Vrai si une définition de rôle reste dans les droits et la portée du compte courant. */
function ueb_role_dans_mes_droits( array $def ) {
	foreach ( $def['permissions'] as $cap ) {
		if ( ! current_user_can( $cap ) ) {
			return false;
		}
	}
	if ( ueb_portee_totale() ) {
		return true;
	}
	if ( 'tous' === $def['portee'] ) {
		return false;
	}
	if ( 'plusieurs' === $def['portee'] ) {
		return ! array_diff( (array) $def['etablissements'], ueb_etabs_autorises() );
	}
	return true; // « un établissement » : l'établissement du compte sera contrôlé à la création
}

/**
 * Crée un compte agent (fonction unifiée). $a : login, nom, email, role (slug
 * du registre), etablissement (portée « un »), mot_de_passe (vide = provisoire
 * généré). Vérifie que le rôle et l'établissement restent dans les droits et
 * la portée du compte qui crée.
 *
 * @return array{0:int,1:string}|WP_Error identifiant du compte et mot de passe
 */
function ueb_creer_compte_agent( array $a ) {
	$login = sanitize_user( (string) ( $a['login'] ?? '' ), true );
	$email = (string) ( $a['email'] ?? '' );
	$slug  = (string) ( $a['role'] ?? '' );
	$def   = ueb_role( $slug );
	$etab  = strtoupper( (string) ( $a['etablissement'] ?? '' ) );
	if ( '' === $login ) {
		return new WP_Error( 'ueb_agent', 'Saisis un identifiant de connexion.' );
	}
	if ( ! $def ) {
		return new WP_Error( 'ueb_agent', 'Choisis le rôle de ce compte.' );
	}
	if ( ! ueb_role_dans_mes_droits( $def ) ) {
		return new WP_Error( 'ueb_agent', 'Ce rôle donne plus de droits que les tiens : tu ne peux pas l’attribuer.' );
	}
	if ( 'un' === $def['portee'] && ( ! ueb_etablissement( $etab ) || ! ueb_peut_gerer_etab( $etab ) ) ) {
		return new WP_Error( 'ueb_agent', 'Choisis l’établissement de ce compte, dans ta propre portée.' );
	}
	if ( username_exists( $login ) ) {
		return new WP_Error( 'ueb_agent', 'Cet identifiant est déjà pris.' );
	}
	if ( $email && ! is_email( $email ) ) {
		return new WP_Error( 'ueb_agent', 'Adresse e-mail invalide.' );
	}
	if ( $email && email_exists( $email ) ) {
		return new WP_Error( 'ueb_agent', 'Cette adresse e-mail est déjà utilisée par un autre compte.' );
	}
	$mot_de_passe = (string) ( $a['mot_de_passe'] ?? '' );
	if ( '' === $mot_de_passe ) {
		$mot_de_passe = ueb_mot_de_passe_provisoire();
	} elseif ( strlen( $mot_de_passe ) < 8 || ! preg_match( '/[A-Za-z]/', $mot_de_passe ) || ! preg_match( '/[0-9]/', $mot_de_passe ) ) {
		return new WP_Error( 'ueb_agent', 'Le mot de passe doit contenir au moins 8 caractères, une lettre et un chiffre.' );
	}
	$nom = (string) ( $a['nom'] ?? '' );
	$id  = wp_insert_user( array(
		'user_login'   => $login,
		'user_pass'    => $mot_de_passe,
		'user_email'   => $email ?: '',
		'display_name' => $nom ?: $login,
		'first_name'   => $nom ?: '',
		'role'         => $slug,
	) );
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	if ( 'un' === $def['portee'] ) {
		update_user_meta( $id, 'ueb_etablissement', $etab );
	}
	return array( (int) $id, $mot_de_passe );
}

/** Rôle historique « qui examine les quitus », détecté par capacité (pour les anciens formulaires). */
function ueb_role_par_defaut( $cap ) {
	foreach ( ueb_roles() as $slug => $def ) {
		if ( 'un' === $def['portee'] && in_array( $cap, $def['permissions'], true ) && ( UEB_CAP_GESTION === $cap || ! in_array( UEB_CAP_GESTION, $def['permissions'], true ) ) ) {
			return $slug;
		}
	}
	return '';
}

/**
 * Historique : crée un compte « scolarité » (rôle qui examine les quitus).
 * Conservé pour l'administration ; passe par ueb_creer_compte_agent().
 */
function ueb_creer_agent( $login, $nom, $email, $etab, $mot_de_passe = '', $role = '' ) {
	if ( '' === (string) $mot_de_passe ) {
		return new WP_Error( 'ueb_agent', 'Le mot de passe doit contenir au moins 8 caractères, une lettre et un chiffre.' );
	}
	return ueb_creer_compte_agent( array( 'login' => $login, 'nom' => $nom, 'email' => $email, 'etablissement' => $etab, 'mot_de_passe' => $mot_de_passe, 'role' => $role ?: ueb_role_par_defaut( UEB_CAP_GESTION ) ) );
}

/** Historique : crée un compte « cellule » (rôle qui gère les comptes étudiants). */
function ueb_creer_cellule( $login, $nom, $email, $etab, $role = '' ) {
	return ueb_creer_compte_agent( array( 'login' => $login, 'nom' => $nom, 'email' => $email, 'etablissement' => $etab, 'role' => $role ?: ueb_role_par_defaut( UEB_CAP_COMPTES ) ) );
}

/* ---------- Les deux espaces, qui sont des Pages WordPress ---------- */

/**
 * Identifiant de la Page portant ce gabarit (page-scolarite.php,
 * page-administration.php). Mémorisé en option pour éviter une requête
 * à chaque appel ; recalculé si la Page a changé.
 */
function ueb_page_par_gabarit( $gabarit ) {
	$cle = 'ueb_page_' . sanitize_key( str_replace( '.php', '', $gabarit ) );
	$id  = (int) get_option( $cle );
	if ( $id && 'publish' === get_post_status( $id ) && get_page_template_slug( $id ) === $gabarit ) {
		return $id;
	}
	$pages = get_posts( array(
		'post_type'   => 'page',
		'post_status' => 'publish',
		'numberposts' => 1,
		'fields'      => 'ids',
		'meta_key'    => '_wp_page_template', // phpcs:ignore WordPress.DB.SlowDBQuery
		'meta_value'  => $gabarit,            // phpcs:ignore WordPress.DB.SlowDBQuery
	) );
	$id = $pages ? (int) $pages[0] : 0;
	update_option( $cle, $id );
	return $id;
}

/** Adresse de l'espace scolarité. */
function ueb_url_scolarite() {
	$id = ueb_page_par_gabarit( 'page-scolarite.php' );
	return $id ? get_permalink( $id ) : home_url( '/scolarite/' );
}

/** Adresse de l'espace cellule informatique. */
function ueb_url_cellule() {
	return ueb_url( 'cellule-informatique' );
}

/** Adresse de l'espace administration. */
function ueb_url_administration() {
	$id = ueb_page_par_gabarit( 'page-administration.php' );
	return $id ? get_permalink( $id ) : home_url( '/administration/' );
}

/** Adresse de l'espace Direction (même mécanisme : une Page portant le gabarit). */
function ueb_url_direction() {
	$id = ueb_page_par_gabarit( 'page-direction.php' );
	return $id ? get_permalink( $id ) : home_url( '/direction/' );
}

/**
 * Premier espace ouvert à un compte, d'après ses capacités : examen des
 * quitus ou suivi des paiements → scolarité ; comptes étudiants → cellule ;
 * Direction seule → Direction.
 */
function ueb_url_espace_du_compte( $user_id ) {
	if ( user_can( $user_id, 'manage_options' ) ) {
		return ueb_url_administration();
	}
	if ( user_can( $user_id, UEB_CAP_GESTION ) || user_can( $user_id, 'ueb_voir_paiements' ) ) {
		return ueb_url_scolarite();
	}
	if ( user_can( $user_id, UEB_CAP_COMPTES ) ) {
		return ueb_url_cellule();
	}
	if ( user_can( $user_id, UEB_CAP_DIRECTION ) ) {
		return ueb_url_direction();
	}
	return home_url( '/' );
}

/** L'espace qui correspond au compte connecté. */
function ueb_url_espace() {
	return ueb_url_espace_du_compte( get_current_user_id() );
}

/** URL de retour de la gestion des comptes étudiants. */
function ueb_url_comptes() {
	return ueb_url_cellule();
}
