<?php
/**
 * Espace Direction : gestion des rôles dynamiques et des comptes du personnel.
 *
 * Tout se fait par formulaires POST (« ueb_action », jeton de session vérifié
 * par ueb_traiter_action()) doublés d'un nonce WordPress propre à la
 * Direction. Chaque action revérifie côté serveur :
 *   - la capacité « ueb_diriger » et le compte non suspendu (ueb_peut) ;
 *   - qu'aucun rôle créé, modifié ou attribué ne dépasse les permissions ni
 *     la portée du compte qui agit (ueb_role_dans_mes_droits) ;
 *   - qu'on ne touche ni à son propre rôle, ni à son propre compte, ni à un
 *     administrateur, ni aux rôles hors du registre ;
 *   - que les établissements viennent de ueb_etablissements() (lecture seule :
 *     aucun établissement ni compte bancaire n'est modifiable ici).
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

/* ---------- Page de l'espace, créée une fois si elle manque ---------- */

add_action( 'init', function () {
	if ( get_option( 'ueb_page_direction_creee' ) || ueb_page_par_gabarit( 'page-direction.php' ) || ! ueb_insc_verrouiller( 'page_direction' ) ) {
		return;
	}
	/* Une autre requête a pu créer la Page pendant qu'on attendait. */
	if ( ueb_insc_option_en_base( 'ueb_page_direction_creee' ) ) {
		ueb_insc_deverrouiller( 'page_direction' );
		return;
	}
	$id = wp_insert_post( array(
		'post_title'  => 'Direction',
		'post_name'   => 'direction',
		'post_status' => 'publish',
		'post_type'   => 'page',
		'meta_input'  => array( '_wp_page_template' => 'page-direction.php' ),
	) );
	if ( $id && ! is_wp_error( $id ) ) {
		update_option( 'ueb_page_direction_creee', (int) $id );
		delete_option( 'ueb_page_page-direction' ); // recalcul de ueb_page_par_gabarit()
	}
	ueb_insc_deverrouiller( 'page_direction' );
}, 30 );

/* ---------- Modèles de l'assistant ----------
   Des suggestions pour aller vite : le nom proposé est modifiable et
   n'est jamais utilisé par le code pour décider d'un accès. */

function ueb_modeles_roles() {
	return array(
		'verification' => array( 'titre' => 'Vérification des paiements', 'texte' => 'Examine les quitus, rend les décisions, suit les paiements et délègue les comptes étudiants.', 'nom' => 'Scolarité', 'portee' => 'un', 'permissions' => array( 'ueb_gerer_quitus', 'ueb_decider_quitus', 'ueb_voir_paiements', 'ueb_gerer_comptes', 'ueb_creer_agents' ), 'icone' => 'tampon' ),
		'comptes'      => array( 'titre' => 'Comptes étudiants', 'texte' => 'Crée, réinitialise et suspend les comptes étudiants de son établissement.', 'nom' => 'Cellule informatique', 'portee' => 'un', 'permissions' => array( 'ueb_gerer_comptes' ), 'icone' => 'utilisateur' ),
		'finances'     => array( 'titre' => 'Suivi financier', 'texte' => 'Consulte le recouvrement des droits, sans rien modifier.', 'nom' => 'Suivi financier', 'portee' => 'tous', 'permissions' => array( 'ueb_voir_paiements' ), 'icone' => 'banque' ),
		'direction'    => array( 'titre' => 'Direction', 'texte' => 'Gère les rôles et le personnel, avec une vue sur tout.', 'nom' => 'Direction', 'portee' => 'tous', 'permissions' => array_keys( ueb_permissions() ), 'icone' => 'bouclier' ),
	);
}

/* ---------- Contrôles communs ---------- */

/** Nonce propre à la Direction, en plus du jeton de session de tous les formulaires. */
function ueb_champs_direction( $action ) {
	ueb_champ_csrf();
	echo '<input type="hidden" name="ueb_action" value="' . esc_attr( $action ) . '">';
	wp_nonce_field( 'ueb_' . $action, 'ueb_nonce_direction' );
}

/** Capacité Direction, compte actif, nonce valide pour cette action. */
function ueb_exiger_direction( $action ) {
	if ( ! ueb_peut( UEB_CAP_DIRECTION ) ) {
		wp_die( 'Action réservée à la Direction.', 'Accès refusé', array( 'response' => 403 ) );
	}
	if ( ! isset( $_POST['ueb_nonce_direction'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['ueb_nonce_direction'] ) ), 'ueb_' . $action ) ) {
		ueb_flash( 'erreur', 'Ta session a expiré. Recommence, s’il te plaît.' );
		ueb_rediriger( ueb_url_direction() );
	}
}

/** Vrai si ce rôle du registre peut être modifié par le compte courant (ni le sien, ni au-dessus de ses droits). */
function ueb_role_modifiable( $slug ) {
	$def = ueb_role( $slug );
	if ( ! $def ) {
		return false;
	}
	return ueb_role_du_compte() !== $slug && ueb_role_dans_mes_droits( $def );
}

/** Vrai si ce compte agent est géré par le compte courant : pas lui-même, pas un administrateur, rôle dans ses droits, portée commune. */
function ueb_agent_gerable( $user_id ) {
	$user_id = (int) $user_id;
	if ( ! $user_id || get_current_user_id() === $user_id || user_can( $user_id, 'manage_options' ) || ! ueb_est_agent( $user_id ) ) {
		return false;
	}
	$def = ueb_role( ueb_role_du_compte( $user_id ) );
	if ( ! $def || ! ueb_role_dans_mes_droits( $def ) ) {
		return false;
	}
	return ueb_portee_totale() || (bool) array_intersect( ueb_etabs_autorises( $user_id ), ueb_etabs_autorises() );
}

/** Nombre de comptes par rôle du registre. */
function ueb_comptes_par_role() {
	$nombres = array();
	foreach ( ueb_agents() as $u ) {
		$slug             = ueb_role_du_compte( $u->ID );
		$nombres[ $slug ] = ( $nombres[ $slug ] ?? 0 ) + 1;
	}
	return $nombres;
}

/** Phrase de portée d'un rôle. */
function ueb_phrase_portee( array $def ) {
	if ( 'tous' === $def['portee'] ) {
		return 'Tous les établissements';
	}
	if ( 'plusieurs' === $def['portee'] ) {
		return implode( ', ', (array) $def['etablissements'] );
	}
	return 'Un établissement, fixé sur chaque compte';
}

/* ---------- Rôles ---------- */

/**
 * Lit et valide une définition postée. Retourne [ $def, $erreur ].
 * Les permissions « requises » sont ajoutées d'office.
 */
function ueb_lire_role_poste( $slug_existant = '' ) {
	$nom         = trim( sanitize_text_field( wp_unslash( $_POST['nom'] ?? '' ) ) );
	$portee      = sanitize_key( wp_unslash( $_POST['portee'] ?? '' ) );
	$etabs       = array_map( static fn( $s ) => strtoupper( sanitize_key( $s ) ), (array) wp_unslash( $_POST['etablissements'] ?? array() ) );
	$permissions = array_map( 'sanitize_key', (array) wp_unslash( $_POST['permissions'] ?? array() ) );
	$catalogue   = ueb_permissions();

	$permissions = array_values( array_intersect( array_keys( $catalogue ), $permissions ) ); // liste blanche, ordre du catalogue
	foreach ( $permissions as $cap ) {
		if ( ! empty( $catalogue[ $cap ]['requiert'] ) && ! in_array( $catalogue[ $cap ]['requiert'], $permissions, true ) ) {
			$permissions[] = $catalogue[ $cap ]['requiert'];
		}
	}
	$etabs = array_values( array_intersect( array_keys( ueb_etablissements() ), $etabs ) );
	$def   = array( 'nom' => $nom, 'portee' => $portee, 'etablissements' => 'plusieurs' === $portee ? $etabs : array(), 'permissions' => $permissions );

	if ( mb_strlen( $nom ) < 2 || mb_strlen( $nom ) > 60 ) {
		return array( $def, 'Donne au rôle un nom de 2 à 60 caractères.' );
	}
	foreach ( ueb_roles() as $slug => $autre ) {
		if ( $slug !== $slug_existant && 0 === strcasecmp( remove_accents( $autre['nom'] ), remove_accents( $nom ) ) ) {
			return array( $def, 'Un rôle porte déjà ce nom.' );
		}
	}
	if ( in_array( strtolower( remove_accents( $nom ) ), array( 'administrator', 'administrateur' ), true ) ) {
		return array( $def, 'Ce nom est réservé.' );
	}
	if ( ! in_array( $portee, array( 'un', 'plusieurs', 'tous' ), true ) ) {
		return array( $def, 'Choisis la portée du rôle.' );
	}
	if ( 'plusieurs' === $portee && count( $etabs ) < 2 ) {
		return array( $def, 'Pour « plusieurs établissements », coche-en au moins deux (ou choisis « un établissement »).' );
	}
	if ( ! $permissions ) {
		return array( $def, 'Coche au moins une permission.' );
	}
	if ( ! ueb_role_dans_mes_droits( $def ) ) {
		return array( $def, 'Ce rôle dépasserait tes propres droits (permissions ou portée) : retire ce que tu ne peux pas accorder.' );
	}
	return array( $def, '' );
}

function ueb_action_direction_role_enregistrer() {
	ueb_exiger_direction( 'direction_role_enregistrer' );
	$slug = sanitize_key( wp_unslash( $_POST['role'] ?? '' ) );
	if ( $slug && ! ueb_role_modifiable( $slug ) ) {
		ueb_flash( 'erreur', 'Tu ne peux pas modifier ce rôle (le tien, ou un rôle au-delà de tes droits).' );
		ueb_rediriger( ueb_url_direction() );
	}
	list( $def, $erreur ) = ueb_lire_role_poste( $slug );
	if ( $erreur ) {
		$_SESSION['ueb_role_saisi'] = $def;
		ueb_flash( 'erreur', $erreur );
		ueb_rediriger( add_query_arg( array_filter( array( 'vue' => 'role', 'role' => $slug ) ), ueb_url_direction() ) );
	}
	$ancien = $slug ? ueb_role( $slug ) : array();
	$def   += array(
		'historique'  => ! empty( $ancien['historique'] ),
		'cree_le'     => $ancien['cree_le'] ?? current_time( 'mysql' ),
		'modifie_le'  => current_time( 'mysql' ),
		'modifie_par' => get_current_user_id(),
	);
	ueb_enregistrer_role( $slug ?: ueb_nouveau_slug_role(), $def );
	ueb_flash( 'succes', $slug ? 'Rôle « ' . $def['nom'] . ' » mis à jour : ses comptes ont leurs nouveaux droits dès leur prochaine page.' : 'Rôle « ' . $def['nom'] . ' » créé.' );
	ueb_rediriger( ueb_url_direction() );
}

function ueb_action_direction_role_dupliquer() {
	ueb_exiger_direction( 'direction_role_dupliquer' );
	$slug = sanitize_key( wp_unslash( $_POST['role'] ?? '' ) );
	$def  = ueb_role( $slug );
	if ( ! $def || ! ueb_role_dans_mes_droits( $def ) ) {
		ueb_rediriger( ueb_url_direction() );
	}
	$noms = array_map( static fn( $r ) => mb_strtolower( $r['nom'] ), ueb_roles() );
	$nom  = $def['nom'] . ' (copie)';
	for ( $n = 2; in_array( mb_strtolower( $nom ), $noms, true ); $n++ ) {
		$nom = $def['nom'] . ' (copie ' . $n . ')';
	}
	$nouveau = ueb_nouveau_slug_role();
	ueb_enregistrer_role( $nouveau, array_merge( $def, array( 'nom' => $nom, 'historique' => false, 'cree_le' => current_time( 'mysql' ), 'modifie_le' => current_time( 'mysql' ), 'modifie_par' => get_current_user_id() ) ) );
	ueb_flash( 'succes', 'Rôle dupliqué : renomme-le et ajuste ses accès.' );
	ueb_rediriger( add_query_arg( array( 'vue' => 'role', 'role' => $nouveau ), ueb_url_direction() ) );
}

/**
 * Supprime un rôle. S'il a des comptes, ils sont d'abord réaffectés au rôle
 * choisi (dans les droits du compte courant) ; aucun compte n'est supprimé.
 */
function ueb_action_direction_role_supprimer() {
	ueb_exiger_direction( 'direction_role_supprimer' );
	$slug       = sanitize_key( wp_unslash( $_POST['role'] ?? '' ) );
	$remplacant = sanitize_key( wp_unslash( $_POST['remplacant'] ?? '' ) );
	$retour     = ueb_url_direction();
	if ( ! ueb_role_modifiable( $slug ) ) {
		ueb_flash( 'erreur', 'Tu ne peux pas supprimer ce rôle.' );
		ueb_rediriger( $retour );
	}
	if ( 'SUPPRIMER' !== strtoupper( trim( sanitize_text_field( wp_unslash( $_POST['confirmation'] ?? '' ) ) ) ) ) {
		ueb_flash( 'erreur', 'Pour confirmer, saisis SUPPRIMER.' );
		ueb_rediriger( $retour );
	}
	$comptes = ueb_agents( $slug );
	if ( $comptes ) {
		$cible = ueb_role( $remplacant );
		if ( ! $cible || $remplacant === $slug || ! ueb_role_dans_mes_droits( $cible ) ) {
			ueb_flash( 'erreur', 'Choisis le rôle qui reprendra les ' . count( $comptes ) . ' compte(s) de ce rôle.' );
			ueb_rediriger( $retour );
		}
		foreach ( $comptes as $u ) {
			$u->remove_role( $slug );
			$u->add_role( $remplacant );
		}
	}
	$nom = ueb_role( $slug )['nom'];
	ueb_retirer_role( $slug );
	ueb_flash( 'succes', 'Rôle « ' . $nom . ' » supprimé' . ( $comptes ? ' ; ' . count( $comptes ) . ' compte(s) réaffecté(s) à « ' . ueb_role( $remplacant )['nom'] . ' ».' : '.' ) );
	ueb_rediriger( $retour );
}

/* ---------- Comptes du personnel ---------- */

function ueb_action_direction_compte_creer() {
	ueb_exiger_direction( 'direction_compte_creer' );
	$retour = add_query_arg( 'vue', 'personnel', ueb_url_direction() );
	$agent  = ueb_creer_compte_agent( array(
		'login'         => sanitize_text_field( wp_unslash( $_POST['login'] ?? '' ) ),
		'nom'           => sanitize_text_field( wp_unslash( $_POST['nom'] ?? '' ) ),
		'email'         => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
		'role'          => sanitize_key( wp_unslash( $_POST['role'] ?? '' ) ),
		'etablissement' => sanitize_key( wp_unslash( $_POST['etablissement'] ?? '' ) ),
	) );
	if ( is_wp_error( $agent ) ) {
		ueb_flash( 'erreur', $agent->get_error_message() );
		ueb_rediriger( $retour );
	}
	list( $id, $provisoire ) = $agent;
	$_SESSION['ueb_mdp_direction'] = array( 'compte' => get_userdata( $id )->user_login, 'mdp' => $provisoire );
	ueb_flash( 'succes', 'Compte créé avec le rôle « ' . ueb_nom_role_du_compte( $id ) . ' ».' );
	ueb_rediriger( $retour );
}

/** Changer le rôle (et l'établissement) d'un compte. */
function ueb_action_direction_compte_role() {
	ueb_exiger_direction( 'direction_compte_role' );
	$retour = add_query_arg( 'vue', 'personnel', ueb_url_direction() );
	$id     = (int) ( $_POST['agent_id'] ?? 0 );
	$slug   = sanitize_key( wp_unslash( $_POST['role'] ?? '' ) );
	$etab   = strtoupper( sanitize_key( wp_unslash( $_POST['etablissement'] ?? '' ) ) );
	$def    = ueb_role( $slug );
	if ( ! ueb_agent_gerable( $id ) || ! $def || ! ueb_role_dans_mes_droits( $def ) ) {
		ueb_flash( 'erreur', 'Ce compte ou ce rôle est hors de tes droits.' );
		ueb_rediriger( $retour );
	}
	if ( 'un' === $def['portee'] && ( ! ueb_etablissement( $etab ) || ! ueb_peut_gerer_etab( $etab ) ) ) {
		ueb_flash( 'erreur', 'Ce rôle agit sur un établissement : choisis-le, dans ta portée.' );
		ueb_rediriger( $retour );
	}
	$user = get_userdata( $id );
	$user->remove_role( ueb_role_du_compte( $id ) );
	$user->add_role( $slug );
	if ( 'un' === $def['portee'] ) {
		update_user_meta( $id, 'ueb_etablissement', $etab );
	}
	delete_user_meta( $id, 'ueb_etab_courant' );
	ueb_flash( 'succes', 'Accès de ' . $user->user_login . ' mis à jour.' );
	ueb_rediriger( $retour );
}

/** Suspendre ou rétablir un compte (il est conservé : ses décisions restent tracées). */
function ueb_action_direction_compte_etat() {
	ueb_exiger_direction( 'direction_compte_etat' );
	$retour = add_query_arg( 'vue', 'personnel', ueb_url_direction() );
	$id     = (int) ( $_POST['agent_id'] ?? 0 );
	if ( ! ueb_agent_gerable( $id ) ) {
		ueb_rediriger( $retour );
	}
	if ( ueb_agent_suspendu( $id ) ) {
		delete_user_meta( $id, 'ueb_agent_suspendu' );
		ueb_flash( 'succes', 'Accès rétabli.' );
	} else {
		update_user_meta( $id, 'ueb_agent_suspendu', 1 );
		ueb_flash( 'succes', 'Accès suspendu : le compte est conservé.' );
	}
	ueb_rediriger( $retour );
}

/** Nouveau mot de passe provisoire, affiché une seule fois. */
function ueb_action_direction_compte_mdp() {
	ueb_exiger_direction( 'direction_compte_mdp' );
	$retour = add_query_arg( 'vue', 'personnel', ueb_url_direction() );
	$id     = (int) ( $_POST['agent_id'] ?? 0 );
	if ( ! ueb_agent_gerable( $id ) ) {
		ueb_rediriger( $retour );
	}
	$provisoire = ueb_mot_de_passe_provisoire();
	wp_set_password( $provisoire, $id );
	$_SESSION['ueb_mdp_direction'] = array( 'compte' => get_userdata( $id )->user_login, 'mdp' => $provisoire );
	ueb_flash( 'succes', 'Nouveau mot de passe provisoire créé.' );
	ueb_rediriger( $retour );
}
