<?php
/**
 * Centre médico-social (CMS) : l'espace qui vérifie les frais médicaux.
 *
 * Les reçus des frais médicaux ne partent plus à la scolarité : le CMS les
 * examine pour toute l'université, les valide ou les refuse avec un motif.
 * La scolarité ne reçoit plus que les droits universitaires. Le registre, la
 * fiche d'un dossier et les décisions sont ceux de la scolarité, réglés par
 * ueb_espace_verification( 'cms' ) (inc/gestion.php).
 *
 * L'espace est une Page WordPress (gabarit page-cms.php), créée une fois si
 * elle manque, comme les espaces Direction et IPES. Accès : capacité
 * UEB_CAP_MEDICAUX (rôle « Centre médico-social » du registre, portée
 * « tous » par défaut), compte non suspendu.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

/* ---------- Page de l'espace, créée une fois si elle manque ---------- */

add_action( 'init', function () {
	if ( get_option( 'ueb_page_cms_creee' ) || ueb_page_par_gabarit( 'page-cms.php' ) || ! ueb_insc_verrouiller( 'page_cms' ) ) {
		return;
	}
	/* Une autre requête a pu créer la Page pendant qu'on attendait. */
	if ( ueb_insc_option_en_base( 'ueb_page_cms_creee' ) ) {
		ueb_insc_deverrouiller( 'page_cms' );
		return;
	}
	$id = wp_insert_post( array(
		'post_title'  => 'Centre médico-social',
		'post_name'   => 'cms',
		'post_status' => 'publish',
		'post_type'   => 'page',
		'meta_input'  => array( '_wp_page_template' => 'page-cms.php' ),
	) );
	if ( $id && ! is_wp_error( $id ) ) {
		update_option( 'ueb_page_cms_creee', (int) $id );
		delete_option( 'ueb_page_page-cms' ); // recalcul de ueb_page_par_gabarit()
	}
	ueb_insc_deverrouiller( 'page_cms' );
}, 30 );

/** Adresse de l'espace du Centre médico-social. */
function ueb_url_cms() {
	$id = ueb_page_par_gabarit( 'page-cms.php' );
	return $id ? get_permalink( $id ) : home_url( '/cms/' );
}

/** Vrai si ce compte du personnel (hors administrateur) a accès à l'espace du CMS. */
function ueb_est_cms( $user_id = 0 ) {
	$user = get_userdata( $user_id ?: get_current_user_id() );
	return $user && ! user_can( $user, 'manage_options' ) && user_can( $user, UEB_CAP_MEDICAUX ) && ! ueb_agent_suspendu( $user->ID ) && ueb_etabs_autorises( $user->ID );
}

/* La barre d'outils de WordPress ne mène qu'à wp-admin, fermé à ces comptes. */
add_filter( 'show_admin_bar', function ( $afficher ) {
	return is_user_logged_in() && ueb_est_cms() ? false : $afficher;
} );

/* ---------- Actions : les décisions de la scolarité, réglées pour le CMS ---------- */

function ueb_action_cms_statut() {
	ueb_decider_statut_quitus( ueb_espace_verification( 'cms' ) );
}

function ueb_action_cms_valider() {
	ueb_valider_dossier( ueb_espace_verification( 'cms' ) );
}

/* PDF d'un quitus médical depuis l'espace du CMS : ?quitus={id}&pdf=1 */
add_action( 'template_redirect', function () {
	if ( ! isset( $_GET['pdf'], $_GET['quitus'] ) || ! is_page_template( 'page-cms.php' ) || ! ueb_peut( UEB_CAP_MEDICAUX ) ) {
		return;
	}
	$quitus = ueb_quitus_par_id( (int) $_GET['quitus'] );
	if ( $quitus && 'medicaux' === $quitus->type && ueb_peut( UEB_CAP_MEDICAUX, $quitus->etablissement ) ) {
		ueb_envoyer_pdf_quitus( $quitus );
	}
}, 20 );

/**
 * Le quitus que l'espace peut ouvrir à partir de l'identifiant demandé :
 * celui du type de l'espace, ou celui de son type dans le même dossier
 * (un lien vers les droits ouvre, au CMS, les frais médicaux rattachés).
 * Null s'il n'y en a pas ou s'il sort de la portée du compte.
 */
function ueb_quitus_de_l_espace( array $espace, $id ) {
	$quitus = $id ? ueb_quitus_par_id( (int) $id ) : null;
	if ( ! $quitus ) {
		return null;
	}
	foreach ( ueb_gestion_dossier( $quitus ) as $q ) {
		if ( $espace['type'] === ( $q->type ?? 'droits' ) && ueb_peut( $espace['voir'], $q->etablissement ) ) {
			return $q;
		}
	}
	return null;
}

/* ---------- Données du tableau de bord ---------- */

/**
 * Tout ce que montre le tableau de bord du CMS, en une lecture des quitus de
 * frais médicaux de l'année dans la portée du compte ($etab : '' = tous).
 *
 * Encaissé : quitus vérifiés ; en vérification : reçu envoyé ; déclaré, sans
 * reçu : quitus généré ou reçu refusé. Les trois parts font l'attendu.
 *
 * @return array{chiffres: array, finances: array, attente: array, etabs: array, situations: array, niveaux: array, historique: array}
 */
function ueb_cms_donnees( $annee_code, $etab, $periode ) {
	global $wpdb;
	list( $portee, $params ) = ueb_gestion_portee_sql( array( 'annee' => $annee_code, 'etab' => $etab, 'type' => 'medicaux' ) );
	$lignes = (array) $wpdb->get_results( $wpdb->prepare(
		"SELECT q.id, q.compte_id, q.etablissement, q.statut, q.montant, q.situation, q.date_modification,
		        UPPER(TRIM(q.parcours)) AS niveau,
		        DATE(q.date_creation) AS genere,
		        IF(q.statut = 'verifie', DATE(COALESCE(q.date_verification, q.date_modification)), NULL) AS verifie
		   FROM ueb_insc_quitus q
		  WHERE $portee", // phpcs:ignore -- portée préparée
		$params
	) );
	$depots = (array) $wpdb->get_results( $wpdb->prepare(
		"SELECT DATE(r.date_envoi) AS jour, COUNT(DISTINCT r.quitus_id) AS n
		   FROM ueb_insc_recus r JOIN ueb_insc_quitus q ON q.id = r.quitus_id
		  WHERE $portee
		  GROUP BY jour", // phpcs:ignore -- portée préparée
		$params
	) );

	$finances   = array( 'attendu' => 0, 'encaisse' => 0, 'verification' => 0, 'declare' => 0 );
	$etabs      = array();
	$situations = array_fill_keys( array_keys( UEB_FRAIS_MEDICAUX ), 0 );
	$niveaux    = array_fill_keys( array_keys( UEB_NIVEAUX_INSCRIPTION ), 0 );
	$attente    = array( 'nombre' => 0, 'plus_ancien' => null );
	foreach ( $lignes as $l ) {
		$montant                = (int) $l->montant;
		$finances['attendu']   += $montant;
		$part                   = array( 'verifie' => 'encaisse', 'recu_envoye' => 'verification' )[ $l->statut ] ?? 'declare';
		$finances[ $part ]     += $montant;
		$e                      = $etabs[ $l->etablissement ] ?? array( 'quitus' => 0, 'verifies' => 0, 'attente' => 0, 'encaisse' => 0 );
		$e['quitus']++;
		$e['verifies']         += 'verifie' === $l->statut ? 1 : 0;
		$e['attente']          += 'recu_envoye' === $l->statut ? 1 : 0;
		$e['encaisse']         += 'verifie' === $l->statut ? $montant : 0;
		$etabs[ $l->etablissement ] = $e;
		/* La situation n'est pas toujours stockée : elle se retrouve au montant. */
		$situation = isset( $situations[ $l->situation ] ) ? $l->situation : '';
		foreach ( UEB_FRAIS_MEDICAUX as $cle => $frais ) {
			if ( '' === $situation && $frais['montant'] === $montant ) {
				$situation = $cle;
			}
		}
		if ( '' !== $situation ) {
			$situations[ $situation ]++;
		}
		foreach ( $niveaux as $code => $n ) {
			if ( 0 === strcasecmp( $code, (string) $l->niveau ) ) {
				$niveaux[ $code ]++;
			}
		}
		if ( 'recu_envoye' === $l->statut ) {
			$attente['nombre']++;
			if ( ! $attente['plus_ancien'] || strcmp( $l->date_modification, $attente['plus_ancien'] ) < 0 ) {
				$attente['plus_ancien'] = $l->date_modification;
			}
		}
	}
	uasort( $etabs, static fn( $a, $b ) => $b['quitus'] <=> $a['quitus'] );

	return array(
		'chiffres'   => ueb_gestion_chiffres( $annee_code, $etab, 'medicaux' ),
		'finances'   => $finances,
		'attente'    => $attente,
		'etabs'      => $etabs,
		'situations' => $situations,
		'niveaux'    => $niveaux,
		'historique' => ueb_cms_historique( $lignes, $depots, $periode ),
	);
}

/**
 * Séries quotidiennes des mini-courbes, sur la fenêtre affichée : cumuls des
 * étudiants, des frais encaissés et des quitus, taux d'encaissement (encaissé
 * sur attendu à la même date) et reçus déposés par jour. Ce qui précède la
 * fenêtre est reporté dans la valeur de départ.
 */
function ueb_cms_historique( array $lignes, array $depots, $periode ) {
	$aujourdhui = current_time( 'Y-m-d' );
	$debut      = gmdate( 'Y-m-d', strtotime( $aujourdhui . ' -' . ( max( 2, (int) $periode ) - 1 ) . ' days' ) );
	$jours      = array();
	for ( $t = strtotime( $debut ); $t <= strtotime( $aujourdhui ); $t += DAY_IN_SECONDS ) {
		$jours[] = gmdate( 'Y-m-d', $t );
	}
	$par_depot = array();
	foreach ( $depots as $d ) {
		$par_depot[ $d->jour ] = (int) $d->n;
	}
	$series = array_fill_keys( array( 'etudiants', 'encaisse', 'quitus', 'taux', 'depots' ), array() );
	foreach ( $jours as $jour ) {
		$comptes  = array();
		$encaisse = 0;
		$attendu  = 0;
		$quitus   = 0;
		foreach ( $lignes as $l ) {
			if ( $l->genere > $jour ) {
				continue;
			}
			$quitus++;
			$attendu                          += (int) $l->montant;
			$comptes[ (int) $l->compte_id ] = true;
			if ( $l->verifie && max( $l->verifie, $l->genere ) <= $jour ) {
				$encaisse += (int) $l->montant;
			}
		}
		$series['etudiants'][] = count( $comptes );
		$series['encaisse'][]  = $encaisse;
		$series['quitus'][]    = $quitus;
		$series['taux'][]      = $attendu ? round( 100 * $encaisse / $attendu, 2 ) : null;
		$series['depots'][]    = $par_depot[ $jour ] ?? 0;
	}
	return array( 'jours' => $jours ) + $series;
}

/* ---------- Journal des connexions du personnel ----------
   Les huit dernières connexions de chaque compte du personnel (date, adresse
   IP, navigateur), affichées sur sa page Sécurité. */

add_action( 'wp_login', function ( $login, $utilisateur ) {
	if ( ! $utilisateur instanceof WP_User || ! ( user_can( $utilisateur, 'manage_options' ) || ueb_est_agent( $utilisateur->ID ) ) ) {
		return;
	}
	$journal = (array) get_user_meta( $utilisateur->ID, 'ueb_journal_connexions', true );
	array_unshift( $journal, array(
		'date' => current_time( 'mysql' ),
		'ip'   => sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) ),
		'ua'   => mb_substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 300 ),
	) );
	update_user_meta( $utilisateur->ID, 'ueb_journal_connexions', array_slice( array_values( array_filter( $journal ) ), 0, 8 ) );
}, 10, 2 );

/**
 * Appareil lisible d'après l'en-tête User-Agent : « Chrome sur Windows ».
 *
 * @return array{0: string, 1: string} libellé, icône (ecran | mobile)
 */
function ueb_appareil_lisible( $ua ) {
	$ua         = (string) $ua;
	$navigateur = 'Navigateur';
	foreach ( array( 'Edg' => 'Edge', 'OPR' => 'Opera', 'SamsungBrowser' => 'Samsung Internet', 'Firefox' => 'Firefox', 'Chrome' => 'Chrome', 'Safari' => 'Safari' ) as $repere => $nom ) {
		if ( false !== strpos( $ua, $repere ) ) {
			$navigateur = $nom;
			break;
		}
	}
	$systeme = '';
	foreach ( array( 'Android' => 'Android', 'iPhone' => 'iPhone', 'iPad' => 'iPad', 'Windows' => 'Windows', 'Mac OS X' => 'macOS', 'CrOS' => 'ChromeOS', 'Linux' => 'Linux' ) as $repere => $nom ) {
		if ( false !== strpos( $ua, $repere ) ) {
			$systeme = $nom;
			break;
		}
	}
	$mobile = (bool) preg_match( '/Mobile|Android|iPhone|iPad/', $ua );
	return array( $systeme ? $navigateur . ' sur ' . $systeme : $navigateur, $mobile ? 'mobile' : 'ecran' );
}

/** Ferme les autres sessions ouvertes du compte connecté ; celle-ci reste ouverte. */
function ueb_action_personnel_fermer_sessions() {
	$id = get_current_user_id();
	if ( ! $id || ! ( ueb_est_agent( $id ) || ueb_est_admin_ipes( $id ) || user_can( $id, 'manage_options' ) ) || ueb_agent_suspendu( $id ) ) {
		wp_die( 'Action réservée aux personnels autorisés.', 'Accès refusé', array( 'response' => 403 ) );
	}
	$sessions = WP_Session_Tokens::get_instance( $id );
	$avant    = count( $sessions->get_all() );
	$sessions->destroy_others( wp_get_session_token() );
	$fermees = max( 0, $avant - 1 );
	ueb_flash( 'succes', $fermees ? sprintf( '%d %s. Seule celle-ci reste ouverte.', $fermees, $fermees > 1 ? 'autres sessions fermées' : 'autre session fermée' ) : 'Aucune autre session n’était ouverte.' );
	ueb_rediriger( wp_validate_redirect( (string) wp_get_referer(), ueb_url_espace() ) ?: ueb_url_espace() );
}
