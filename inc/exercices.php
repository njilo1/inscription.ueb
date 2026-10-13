<?php
/**
 * Exercices : les années académiques de la plateforme, depuis la création de
 * l'université (2022-2023).
 *
 *   - L'exercice en cours est celui des inscriptions : les étudiants y
 *     préparent leurs quitus, la scolarité et le CMS y travaillent. Le
 *     super-administrateur le choisit (« Activer ») ; ueb_annee_academique()
 *     le renvoie partout. Il ne change plus seul le 1er septembre.
 *   - Un exercice clôturé reste consultable, mais plus aucune décision n'est
 *     rendue sur ses reçus (ueb_peut_decider_quitus). L'exercice en cours ne
 *     se clôture pas : on active d'abord le suivant.
 *   - Le super-administrateur consulte l'exercice de son choix (onglet
 *     Exercice du Pilotage) : tableaux de bord, paiements, reçus et étudiants
 *     suivent (ueb_exercice_consulte()). Les autres comptes, et lui-même en
 *     profil simulé, restent sur l'exercice en cours. Chaque connexion repart
 *     de l'exercice en cours.
 *
 * Réglages : option ueb_exercices = array( en_cours, clotures (code =>
 * array( date, par )), journal (les dernières décisions, la plus récente
 * d'abord) ).
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

const UEB_EXERCICE_PREMIER  = 2022; // 2022-2023 : création de l'Université d'Ebolowa
const UEB_EXERCICES_JOURNAL = 30;   // décisions gardées dans le journal

const UEB_EXERCICE_STATUTS = array(
	'en_cours' => array( 'libelle' => 'En cours', 'icone' => 'lecture' ),
	'ouvert'   => array( 'libelle' => 'Ouvert', 'icone' => 'calendrier' ),
	'cloture'  => array( 'libelle' => 'Clôturé', 'icone' => 'cadenas' ),
	'a_venir'  => array( 'libelle' => 'À venir', 'icone' => 'horloge' ),
);

/**
 * Exercice d'après son code (« 2025-2026 »), ou null si le code est invalide.
 *
 * @return array{debut:int, fin:int, libelle:string, code:string}|null
 */
function ueb_exercice( $code ) {
	if ( ! preg_match( '/^(\d{4})-(\d{4})$/', (string) $code, $m ) || (int) $m[2] !== (int) $m[1] + 1 ) {
		return null;
	}
	$debut = (int) $m[1];
	return array(
		'debut'   => $debut,
		'fin'     => $debut + 1,
		'libelle' => $debut . ' – ' . ( $debut + 1 ),
		'code'    => $debut . '-' . ( $debut + 1 ),
	);
}

/**
 * Exercice du calendrier : il bascule le 1er septembre (le 31 août 2027 on
 * est en 2026-2027, le 1er septembre 2027 en 2027-2028). Sert de point de
 * départ et de repère ; l'exercice en cours, lui, se choisit.
 */
function ueb_exercice_calendaire( $timestamp = null ) {
	$date  = ( new DateTimeImmutable( '@' . ( $timestamp ?? time() ) ) )->setTimezone( wp_timezone() );
	$annee = (int) $date->format( 'Y' );
	$debut = (int) $date->format( 'n' ) >= 9 ? $annee : $annee - 1;
	return ueb_exercice( $debut . '-' . ( $debut + 1 ) );
}

/**
 * Réglages des exercices. Au premier appel, l'exercice du calendrier devient
 * l'exercice en cours et n'en bouge plus sans décision du super-administrateur.
 */
function ueb_exercices_reglages() {
	$r = get_option( 'ueb_exercices' );
	$r = is_array( $r ) ? $r : array();
	if ( ! ueb_exercice( $r['en_cours'] ?? '' ) ) {
		$r['en_cours'] = ueb_exercice_calendaire()['code'];
		update_option( 'ueb_exercices', $r + array( 'clotures' => array(), 'journal' => array() ), true );
	}
	return $r + array( 'clotures' => array(), 'journal' => array() );
}

/**
 * Exercice en cours : celui des inscriptions.
 *
 * @return array{debut:int, fin:int, libelle:string, code:string}
 */
function ueb_annee_academique() {
	return ueb_exercice( ueb_exercices_reglages()['en_cours'] );
}

/** Codes des exercices, du plus ancien (2022-2023) à celui qui suit l'exercice en cours ou le calendrier. */
function ueb_exercices_codes() {
	$dernier = max( ueb_annee_academique()['debut'], ueb_exercice_calendaire()['debut'] ) + 1;
	$codes   = array();
	for ( $debut = UEB_EXERCICE_PREMIER; $debut <= $dernier; $debut++ ) {
		$codes[] = $debut . '-' . ( $debut + 1 );
	}
	return $codes;
}

/** Statut d'un exercice : en_cours, cloture, a_venir (après l'exercice en cours) ou ouvert. */
function ueb_exercice_statut( $code ) {
	$r = ueb_exercices_reglages();
	if ( $code === $r['en_cours'] ) {
		return 'en_cours';
	}
	if ( isset( $r['clotures'][ $code ] ) ) {
		return 'cloture';
	}
	return ( ueb_exercice( $code )['debut'] ?? 0 ) > ueb_exercice( $r['en_cours'] )['debut'] ? 'a_venir' : 'ouvert';
}

/** Vrai si l'exercice est clôturé : consultable, mais plus aucune décision. */
function ueb_exercice_cloture( $code ) {
	return isset( ueb_exercices_reglages()['clotures'][ (string) $code ] );
}

/**
 * Exercice consulté : celui que le super-administrateur a choisi dans
 * l'onglet Exercice, l'exercice en cours pour tous les autres comptes (et
 * pour lui en profil simulé, où il voit ce que voit le rôle).
 *
 * @return array{debut:int, fin:int, libelle:string, code:string}
 */
function ueb_exercice_consulte() {
	$en_cours = ueb_annee_academique();
	if ( ! function_exists( 'ueb_est_admin_ueb' ) || ! ueb_est_admin_ueb() ) { // sans les rôles (tests en SHORTINIT) : l'exercice en cours
		return $en_cours;
	}
	$code = (string) get_user_meta( get_current_user_id(), 'ueb_exercice_consulte', true );
	return '' !== $code && in_array( $code, ueb_exercices_codes(), true ) ? ueb_exercice( $code ) : $en_cours;
}

/** Vrai si le super-administrateur consulte un autre exercice que celui en cours. */
function ueb_exercice_hors_cours() {
	return ueb_exercice_consulte()['code'] !== ueb_annee_academique()['code'];
}

/* Chaque connexion, et chaque déconnexion, repart de l'exercice en cours. */
add_action( 'wp_login', static function ( $login, $utilisateur ) {
	delete_user_meta( $utilisateur->ID, 'ueb_exercice_consulte' );
}, 10, 2 );
add_action( 'wp_logout', static function ( $user_id = 0 ) {
	delete_user_meta( $user_id ?: get_current_user_id(), 'ueb_exercice_consulte' );
} );

/** Adresse de l'onglet Exercice de l'administration. */
function ueb_url_exercices( array $args = array() ) {
	return ueb_url_espace_admin( 'admin', array( 'vue' => 'exercices' ) + $args );
}

/** Adresse de la page affichée : la bascule d'exercice y ramène (ueb_exercice_retour la revérifie). */
function ueb_url_courante() {
	return set_url_scheme( 'http://' . wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) . wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );
}

/**
 * Formulaire d'une décision sur un exercice : un seul bouton, confirmé par la
 * fenêtre de confirmation (data-confirmer, assets/js/app.js).
 *
 * @param string $action     consulter, activer, cloturer ou rouvrir.
 * @param array  $bouton     libelle, icone, classe (classes adm-bouton en plus) ;
 *                           interrupteur : un interrupteur éteint à la place
 *                           du bouton (libelle = son nom accessible).
 * @param array  $confirmer  titre, texte, bouton, ton (« enregistrer » = vert) ;
 *                           vide : sans confirmation.
 * @param string $retour     Page où revenir (consulter) ; l'onglet par défaut.
 * @param array  $data       Attributs data-* du formulaire (repris par assets/js/exercices.js).
 */
function ueb_exercice_formulaire( $action, $code, array $bouton, array $confirmer = array(), $retour = '', array $data = array() ) {
	$attrs = '';
	foreach ( $data as $nom => $valeur ) {
		$attrs .= sprintf( ' data-%s="%s"', sanitize_key( $nom ), esc_attr( $valeur ) );
	}
	if ( $confirmer ) {
		$attrs .= sprintf(
			' data-confirmer="%s" data-confirmer-titre="%s" data-confirmer-bouton="%s"%s',
			esc_attr( $confirmer['texte'] ),
			esc_attr( $confirmer['titre'] ),
			esc_attr( $confirmer['bouton'] ),
			empty( $confirmer['ton'] ) ? '' : ' data-confirmer-ton="' . esc_attr( $confirmer['ton'] ) . '"'
		);
	}
	?>
	<form method="post" action="<?php echo esc_url( ueb_url_exercices() ); ?>"<?php echo $attrs; // phpcs:ignore -- échappé ci-dessus ?>>
		<?php ueb_champ_csrf(); ?>
		<input type="hidden" name="ueb_action" value="<?php echo esc_attr( 'exercice_' . $action ); ?>">
		<input type="hidden" name="exercice" value="<?php echo esc_attr( $code ); ?>">
		<?php if ( $retour ) : ?><input type="hidden" name="retour" value="<?php echo esc_url( $retour ); ?>"><?php endif; ?>
		<?php if ( ! empty( $bouton['interrupteur'] ) ) : /* l'interrupteur « Exercice actif », éteint : l'allumer active l'exercice */ ?>
			<button class="exo-interrupteur" type="submit" role="switch" aria-checked="false" aria-label="<?php echo esc_attr( $bouton['libelle'] ); ?>"><span class="exo-interrupteur__curseur"></span></button>
		<?php else : ?>
			<button class="adm-bouton <?php echo esc_attr( $bouton['classe'] ?? '' ); ?>" type="submit"><?php echo ueb_icone( $bouton['icone'], 17 ); ?><?php echo esc_html( $bouton['libelle'] ); ?></button>
		<?php endif; ?>
	</form>
	<?php
}

/**
 * Dernier jour de l'historique d'un exercice : aujourd'hui pour l'exercice en
 * cours, son dernier jour (31 août) pour un exercice passé, sans jamais
 * dépasser aujourd'hui. Les courbes des tableaux de bord vont du premier
 * quitus de l'exercice à ce jour : la situation de tout l'exercice.
 */
function ueb_exercice_fin_historique( $code ) {
	$aujourdhui = current_time( 'Y-m-d' );
	$exercice   = ueb_exercice( $code );
	if ( ! $exercice || $code === ueb_annee_academique()['code'] ) {
		return $aujourdhui;
	}
	return min( $aujourdhui, $exercice['fin'] . '-08-31' );
}

/**
 * Premier et dernier jour de l'historique d'un exercice : du premier dossier
 * ($premier, Y-m-d) au dernier jour de l'historique, repoussé jusqu'au
 * dernier mouvement ($dernier) s'il est plus tardif (dossier repris après la
 * fin de l'exercice), jamais au-delà d'aujourd'hui. Sans dossier : null.
 *
 * @return array{0: string, 1: string}|null
 */
function ueb_exercice_bornes( $code, $premier, $dernier = '' ) {
	if ( ! $premier ) {
		return null;
	}
	$fin = min( current_time( 'Y-m-d' ), max( ueb_exercice_fin_historique( $code ), substr( (string) $dernier, 0, 10 ) ) );
	return array( min( substr( (string) $premier, 0, 10 ), $fin ), $fin );
}

/* Géométrie de la frise, reprise de _source/remotion/src/FriseExercice.tsx. */
const UEB_FRISE = array( 'largeur' => 720, 'hauteur' => 48, 'marge' => 16, 'epaisseur' => 16, 'ecart' => 5 );

/**
 * Avancement d'un exercice, du 1er septembre au 31 août : jours écoulés et
 * restants, part écoulée, et les douze mois (pour la frise Remotion « frise »
 * et ses noms de mois en HTML). Un exercice à venir n'avance pas tant qu'il
 * n'est pas activé.
 *
 * @return array{debut:int, statut:string, total:int, ecoules:int, restants:int, avant:int, progression:float, mois:array}
 */
function ueb_exercice_avancement( $code ) {
	static $noms = array( 'septembre', 'octobre', 'novembre', 'décembre', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août' );
	static $courts = array( 'sept.', 'oct.', 'nov.', 'déc.', 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août' );
	$e        = ueb_exercice( $code );
	$statut   = ueb_exercice_statut( $code );
	$jour     = static fn( $a, $m ) => intdiv( gmmktime( 0, 0, 0, $m, 1, $a ), DAY_IN_SECONDS );
	$origine  = $jour( $e['debut'], 9 );
	$total    = $jour( $e['fin'], 9 ) - $origine;
	$present  = intdiv( strtotime( current_time( 'Y-m-d' ) . ' UTC' ), DAY_IN_SECONDS ) - $origine + 1; // le 1er septembre est le jour 1
	$ecoules  = 'a_venir' === $statut ? 0 : max( 0, min( $total, $present ) );
	$t        = round( $ecoules / $total, 4 );
	$mois     = array();
	foreach ( $noms as $k => $nom ) {
		$annee  = $k < 4 ? $e['debut'] : $e['fin'];
		$m      = ( 8 + $k ) % 12 + 1;
		$ouv    = ( $jour( $annee, $m ) - $origine ) / $total;
		$fin    = ( $jour( 12 === $m ? $annee + 1 : $annee, 12 === $m ? 1 : $m + 1 ) - $origine ) / $total;
		$mois[] = array(
			'nom'       => $nom,
			'court'     => $courts[ $k ],
			'ouverture' => $ouv,
			'fin'       => $fin,
			'centre'    => ueb_frise_position( ( $ouv + $fin ) / 2 ),
			'etat'      => $t >= $fin ? 'passe' : ( $t > $ouv ? 'courant' : 'futur' ),
		);
	}
	return array(
		'debut'       => $e['debut'],
		'statut'      => $statut,
		'total'       => $total,
		'ecoules'     => $ecoules,
		'restants'    => $total - $ecoules,
		'avant'       => max( 0, 1 - $present ), // jours avant le 1er septembre
		'progression' => $t,
		'mois'        => $mois,
	);
}

/** Position (en % de la largeur de la frise) d'une fraction de l'exercice : marges du point d'aujourd'hui comprises. */
function ueb_frise_position( $t ) {
	return round( ( UEB_FRISE['marge'] + $t * ( UEB_FRISE['largeur'] - 2 * UEB_FRISE['marge'] ) ) / UEB_FRISE['largeur'] * 100, 3 );
}

/**
 * Image fixe de la frise, à géométrie identique à la dernière image de la
 * composition : affichée avant le montage du lecteur, et seule sans JavaScript.
 */
function ueb_exercice_frise_repli( array $a ) {
	$f      = UEB_FRISE;
	$utile  = $f['largeur'] - 2 * $f['marge'];
	$y      = $f['hauteur'] / 2 - $f['epaisseur'] / 2;
	$bout   = $f['marge'] + $a['progression'] * $utile;
	$plein  = 'cloture' === $a['statut'] ? '--frise-clos' : '--frise-plein';
	$svg    = '';
	foreach ( $a['mois'] as $m ) {
		$x0 = $f['marge'] + $m['ouverture'] * $utile + $f['ecart'] / 2;
		$x1 = $f['marge'] + $m['fin'] * $utile - $f['ecart'] / 2;
		if ( 'a_venir' === $a['statut'] ) {
			$svg .= sprintf( '<rect x="%.2f" y="%.2f" width="%.2f" height="%.2f" rx="%.2f" fill="none" style="stroke:var(--frise-contour)" stroke-width="1.5" stroke-dasharray="3 4"/>', $x0 + .75, $y + .75, $x1 - $x0 - 1.5, $f['epaisseur'] - 1.5, ( $f['epaisseur'] - 1.5 ) / 2 );
		} else {
			$svg .= sprintf( '<rect x="%.2f" y="%.2f" width="%.2f" height="%d" rx="%d" style="fill:var(--frise-piste)"/>', $x0, $y, $x1 - $x0, $f['epaisseur'], $f['epaisseur'] / 2 );
		}
		$rempli = max( 0, min( $x1 - $x0, $bout - $x0 ) );
		if ( $rempli > .5 ) {
			$svg .= sprintf( '<rect x="%.2f" y="%.2f" width="%.2f" height="%d" rx="%.2f" style="fill:var(%s)"/>', $x0, $y, $rempli, $f['epaisseur'], min( $f['epaisseur'] / 2, $rempli / 2 ), $plein );
		}
	}
	$cy = $f['hauteur'] / 2;
	if ( 'en_cours' === $a['statut'] && $a['progression'] > 0 && $a['progression'] < 1 ) {
		$svg .= sprintf( '<circle cx="%1$.2f" cy="%2$d" r="16" fill="rgba(227,168,34,.24)"/><circle cx="%1$.2f" cy="%2$d" r="10" fill="#e3a822" style="stroke:var(--frise-anneau)" stroke-width="3"/>', $bout, $cy );
	} elseif ( 'a_venir' === $a['statut'] ) {
		$svg .= sprintf( '<circle cx="%.2f" cy="%d" r="9" style="fill:var(--frise-anneau)" stroke="#e3a822" stroke-width="3"/>', $f['marge'] + $f['ecart'] / 2, $cy );
	}
	return sprintf( '<svg class="exo-frise-repli" viewBox="0 0 %d %d" aria-hidden="true">%s</svg>', $f['largeur'], $f['hauteur'], $svg );
}

/** Chiffres de chaque exercice pour la liste : quitus et étudiants. */
function ueb_exercices_effectifs() {
	global $wpdb;
	$effectifs = array();
	foreach ( (array) $wpdb->get_results( 'SELECT annee_academique AS code, COUNT(*) AS quitus, COUNT(DISTINCT compte_id) AS etudiants FROM ueb_insc_quitus GROUP BY annee_academique' ) as $l ) {
		$effectifs[ $l->code ] = array( 'quitus' => (int) $l->quitus, 'etudiants' => (int) $l->etudiants );
	}
	return $effectifs;
}

/** Ajoute une décision au journal des exercices (le plus récent d'abord). */
function ueb_exercices_journaliser( array &$reglages, $action, $code ) {
	array_unshift( $reglages['journal'], array(
		'date'   => current_time( 'mysql' ),
		'par'    => get_current_user_id(),
		'action' => $action,
		'code'   => $code,
	) );
	$reglages['journal'] = array_slice( $reglages['journal'], 0, UEB_EXERCICES_JOURNAL );
}

/* ---------- Actions (super-administrateur seul) ---------- */

/** Exercice posté, contrôlé : un exercice de la liste, sinon retour à l'onglet. */
function ueb_exercice_poste() {
	ueb_exiger_admin();
	$code = sanitize_text_field( wp_unslash( $_POST['exercice'] ?? '' ) );
	if ( ! in_array( $code, ueb_exercices_codes(), true ) ) {
		ueb_flash( 'erreur', 'Cet exercice n’existe pas.' );
		ueb_rediriger( ueb_url_exercices() );
	}
	return ueb_exercice( $code );
}

/** Retour après une action : la page d'où elle vient si elle est de l'administration, sinon l'onglet. */
function ueb_exercice_retour() {
	$retour = wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['retour'] ?? '' ) ), '' );
	return $retour && str_starts_with( $retour, ueb_url_administration() ) ? $retour : ueb_url_exercices();
}

/** Consulter un exercice : tout l'espace d'administration affiche ses données. */
function ueb_action_exercice_consulter() {
	$e = ueb_exercice_poste();
	if ( $e['code'] === ueb_annee_academique()['code'] ) {
		delete_user_meta( get_current_user_id(), 'ueb_exercice_consulte' );
	} else {
		update_user_meta( get_current_user_id(), 'ueb_exercice_consulte', $e['code'] );
	}
	ueb_rediriger( ueb_exercice_retour() );
}

/** Activer un exercice : il devient celui des inscriptions, pour tous les comptes. */
function ueb_action_exercice_activer() {
	$e = ueb_exercice_poste();
	$r = ueb_exercices_reglages();
	if ( isset( $r['clotures'][ $e['code'] ] ) ) {
		ueb_flash( 'erreur', 'L’exercice ' . $e['libelle'] . ' est clôturé : rouvre-le avant de l’activer.' );
	} elseif ( $e['code'] !== $r['en_cours'] ) {
		$r['en_cours'] = $e['code'];
		ueb_exercices_journaliser( $r, 'activer', $e['code'] );
		update_option( 'ueb_exercices', $r, true );
		delete_user_meta( get_current_user_id(), 'ueb_exercice_consulte' );
		ueb_flash( 'succes', 'L’exercice ' . $e['libelle'] . ' est maintenant en cours : les nouveaux quitus des étudiants y sont rattachés.' );
	}
	ueb_rediriger( ueb_url_exercices() );
}

/** Clôturer un exercice passé ou à venir ; jamais celui en cours. */
function ueb_action_exercice_cloturer() {
	$e = ueb_exercice_poste();
	$r = ueb_exercices_reglages();
	if ( $e['code'] === $r['en_cours'] ) {
		ueb_flash( 'erreur', 'L’exercice en cours ne se clôture pas : active d’abord l’exercice suivant.' );
	} elseif ( ! isset( $r['clotures'][ $e['code'] ] ) ) {
		$r['clotures'][ $e['code'] ] = array( 'date' => current_time( 'mysql' ), 'par' => get_current_user_id() );
		ueb_exercices_journaliser( $r, 'cloturer', $e['code'] );
		update_option( 'ueb_exercices', $r, true );
		ueb_flash( 'succes', 'Exercice ' . $e['libelle'] . ' clôturé : ses chiffres restent consultables, plus aucun reçu n’y est validé ni renvoyé.' );
	}
	ueb_rediriger( ueb_url_exercices() );
}

/** Rouvrir un exercice clôturé. */
function ueb_action_exercice_rouvrir() {
	$e = ueb_exercice_poste();
	$r = ueb_exercices_reglages();
	if ( isset( $r['clotures'][ $e['code'] ] ) ) {
		unset( $r['clotures'][ $e['code'] ] );
		ueb_exercices_journaliser( $r, 'rouvrir', $e['code'] );
		update_option( 'ueb_exercices', $r, true );
		ueb_flash( 'succes', 'Exercice ' . $e['libelle'] . ' rouvert : ses reçus peuvent de nouveau recevoir une décision.' );
	}
	ueb_rediriger( ueb_url_exercices() );
}
