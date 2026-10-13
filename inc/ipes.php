<?php
/**
 * IPES : établissements privés placés sous la tutelle d'un ou plusieurs
 * établissements de l'UEb, par convention.
 *
 * Les IPES sont en base (tables ueb_insc_ipes*), créés par l'administration,
 * contrairement aux neuf établissements de ueb_etablissements() qui restent
 * écrits dans inc/config.php. Leurs étudiants n'utilisent pas ce site.
 *
 * Ce fichier ne contient que l'accès aux données et leur validation ; les
 * actions des formulaires s'appuient dessus.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

/* Longueurs maximales, alignées sur les colonnes de ueb_insc_ipes. */
const UEB_IPES_LONGUEURS = array(
	'nom_fr'         => 150,
	'nom_en'         => 150,
	'ville'          => 100,
	'email'          => 150,
	'convention_ref' => 100,
);

/* ---------- Lecture ---------- */

/** Un IPES avec ses tutelles (->tutelles : tableau de sigles), ou null. */
function ueb_ipes( $id ) {
	global $wpdb;
	$ipes = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ueb_insc_ipes WHERE id = %d', $id ) );
	if ( $ipes ) {
		$ipes->tutelles = ueb_ipes_tutelles( $ipes->id );
	}
	return $ipes;
}

/** Sigles des établissements de tutelle d'un IPES, par ordre alphabétique. */
function ueb_ipes_tutelles( $ipes_id ) {
	global $wpdb;
	return $wpdb->get_col( $wpdb->prepare(
		'SELECT etablissement FROM ueb_insc_ipes_tutelles WHERE ipes_id = %d ORDER BY etablissement',
		$ipes_id
	) );
}

/**
 * Liste des IPES, chacun avec ses tutelles, triée par sigle.
 *
 * @param array $filtres etablissement (sigle de tutelle), actif (0 ou 1),
 *                       recherche (dans le sigle et les noms).
 */
function ueb_ipes_liste( array $filtres = array() ) {
	global $wpdb;
	$where  = array( '1 = 1' );
	$params = array();

	$etab = strtoupper( (string) ( $filtres['etablissement'] ?? '' ) );
	if ( ueb_etablissement( $etab ) ) {
		$where[]  = 'EXISTS ( SELECT 1 FROM ueb_insc_ipes_tutelles t WHERE t.ipes_id = i.id AND t.etablissement = %s )';
		$params[] = $etab;
	}
	if ( isset( $filtres['actif'] ) && '' !== $filtres['actif'] ) {
		$where[]  = 'i.actif = %d';
		$params[] = $filtres['actif'] ? 1 : 0;
	}
	$recherche = trim( (string) ( $filtres['recherche'] ?? '' ) );
	if ( '' !== $recherche ) {
		$motif    = '%' . $wpdb->esc_like( $recherche ) . '%';
		$where[]  = '( i.sigle LIKE %s OR i.nom_fr LIKE %s OR i.nom_en LIKE %s )';
		$params[] = $motif;
		$params[] = $motif;
		$params[] = $motif;
	}

	$sql = 'SELECT i.* FROM ueb_insc_ipes i WHERE ' . implode( ' AND ', $where ) . ' ORDER BY i.sigle';
	$liste = $wpdb->get_results( $params ? $wpdb->prepare( $sql, $params ) : $sql );
	if ( ! $liste ) {
		return array();
	}

	/* Toutes les tutelles en une requête, plutôt qu'une par IPES. */
	$ids     = array_map( static fn( $i ) => (int) $i->id, $liste );
	$marques = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$lignes  = $wpdb->get_results( $wpdb->prepare(
		"SELECT ipes_id, etablissement FROM ueb_insc_ipes_tutelles WHERE ipes_id IN ($marques) ORDER BY etablissement",
		$ids
	) );
	$tutelles = array();
	foreach ( $lignes as $l ) {
		$tutelles[ (int) $l->ipes_id ][] = $l->etablissement;
	}
	foreach ( $liste as $ipes ) {
		$ipes->tutelles = $tutelles[ (int) $ipes->id ] ?? array();
	}
	return $liste;
}

/* ---------- Validation ---------- */

/**
 * Données d'un formulaire IPES mises en forme : textes rognés, sigle en
 * majuscules, téléphone sans espaces, tutelles uniques, dates vides à null.
 * Un téléphone mal formé est conservé tel quel pour que la validation le signale.
 */
function ueb_ipes_normaliser( array $d ) {
	$propre = array();
	foreach ( array( 'nom_fr', 'nom_en', 'ville', 'email', 'convention_ref' ) as $champ ) {
		$propre[ $champ ] = trim( (string) ( $d[ $champ ] ?? '' ) );
	}
	$propre['sigle']     = strtoupper( preg_replace( '/\s+/', '', (string) ( $d['sigle'] ?? '' ) ) );
	$telephone           = trim( (string) ( $d['telephone'] ?? '' ) );
	$propre['telephone'] = '' === $telephone ? '' : ( ueb_normaliser_telephone( $telephone ) ?? $telephone );
	foreach ( array( 'convention_signee_le', 'convention_fin_le' ) as $champ ) {
		$date             = trim( (string) ( $d[ $champ ] ?? '' ) );
		$propre[ $champ ] = '' === $date ? null : $date;
	}
	$propre['tutelles'] = array_values( array_unique( array_map(
		static fn( $s ) => strtoupper( trim( (string) $s ) ),
		(array) ( $d['tutelles'] ?? array() )
	) ) );
	return $propre;
}

/** Vrai pour une date AAAA-MM-JJ qui existe au calendrier. */
function ueb_ipes_date_valide( $date ) {
	$objet = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $date );
	return $objet && $objet->format( 'Y-m-d' ) === $date;
}

/**
 * Erreurs d'un IPES normalisé, sous la forme champ => message (vide si tout va).
 *
 * @param array $d  Données passées par ueb_ipes_normaliser().
 * @param int   $id IPES modifié (0 à la création), exclu du contrôle d'unicité.
 */
function ueb_ipes_valider( array $d, $id = 0 ) {
	global $wpdb;
	$erreurs = array();

	if ( ! preg_match( '/^[A-Z0-9-]{2,20}$/', $d['sigle'] ) ) {
		$erreurs['sigle'] = 'Le sigle compte 2 à 20 caractères : lettres, chiffres ou tiret.';
	} elseif ( ueb_etablissement( $d['sigle'] ) || 'UEB' === $d['sigle'] ) {
		$erreurs['sigle'] = 'Ce sigle est celui d’un établissement de l’UEb.';
	} elseif ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ueb_insc_ipes WHERE sigle = %s AND id <> %d', $d['sigle'], $id ) ) ) {
		$erreurs['sigle'] = 'Un autre IPES porte déjà ce sigle.';
	}

	if ( '' === $d['nom_fr'] ) {
		$erreurs['nom_fr'] = 'Saisis le nom de l’IPES.';
	}
	foreach ( UEB_IPES_LONGUEURS as $champ => $max ) {
		if ( ! isset( $erreurs[ $champ ] ) && mb_strlen( $d[ $champ ] ) > $max ) {
			$erreurs[ $champ ] = "$max caractères au maximum.";
		}
	}

	if ( '' !== $d['email'] && ! isset( $erreurs['email'] ) && ! is_email( $d['email'] ) ) {
		$erreurs['email'] = 'Adresse e-mail invalide.';
	}
	if ( '' !== $d['telephone'] && ! preg_match( UEB_REGEX_TELEPHONE, $d['telephone'] ) ) {
		$erreurs['telephone'] = 'Numéro mobile à 9 chiffres commençant par 6.';
	}

	if ( ! $d['tutelles'] ) {
		$erreurs['tutelles'] = 'Choisis au moins un établissement de tutelle.';
	} elseif ( array_filter( $d['tutelles'], static fn( $s ) => ! ueb_etablissement( $s ) ) ) {
		$erreurs['tutelles'] = 'Établissement de tutelle inconnu.';
	} elseif ( $id ) {
		/* Une tutelle qui a encore des filières actives ne peut pas être retirée :
		   ses filières et leurs étudiants resteraient sans faculté destinataire. */
		$retirees = array_diff( ueb_ipes_tutelles( $id ), $d['tutelles'] );
		foreach ( $retirees as $sigle ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ueb_insc_ipes_filieres WHERE ipes_id = %d AND etablissement = %s AND actif = 1', $id, $sigle ) ) ) {
				$erreurs['tutelles'] = sprintf( '%s a encore des filières actives : retire-les d’abord, puis retire la tutelle.', $sigle );
				break;
			}
		}
	}

	foreach ( array( 'convention_signee_le', 'convention_fin_le' ) as $champ ) {
		if ( null !== $d[ $champ ] && ! ueb_ipes_date_valide( $d[ $champ ] ) ) {
			$erreurs[ $champ ] = 'Date invalide.';
		}
	}
	if ( ! isset( $erreurs['convention_signee_le'] ) && ! isset( $erreurs['convention_fin_le'] )
		&& null !== $d['convention_signee_le'] && null !== $d['convention_fin_le']
		&& $d['convention_fin_le'] <= $d['convention_signee_le'] ) {
		$erreurs['convention_fin_le'] = 'La fin de la convention doit suivre sa signature.';
	}

	return $erreurs;
}

/* ---------- Écriture ---------- */

/**
 * Crée ($id = 0) ou modifie un IPES, tutelles comprises, en une transaction.
 * Les données sont normalisées puis validées ici : en cas d'erreur, le
 * WP_Error porte le tableau champ => message dans ses données.
 *
 * @return int|WP_Error Identifiant de l'IPES.
 */
function ueb_ipes_enregistrer( array $d, $id = 0 ) {
	global $wpdb;
	$id = (int) $id;
	if ( $id && ! ueb_ipes( $id ) ) {
		return new WP_Error( 'ueb_ipes_introuvable', 'Cet IPES n’existe pas.' );
	}
	$d       = ueb_ipes_normaliser( $d );
	$erreurs = ueb_ipes_valider( $d, $id );
	if ( $erreurs ) {
		return new WP_Error( 'ueb_ipes_invalide', 'Corrige les champs signalés.', $erreurs );
	}

	$ligne = array_intersect_key( $d, array_flip( array( 'sigle', 'nom_fr', 'nom_en', 'ville', 'telephone', 'email', 'convention_ref', 'convention_signee_le', 'convention_fin_le' ) ) );
	$ligne['modifie_par'] = get_current_user_id() ?: null;

	$wpdb->query( 'START TRANSACTION' );
	$ok = $id ? $wpdb->update( 'ueb_insc_ipes', $ligne, array( 'id' => $id ) ) : $wpdb->insert( 'ueb_insc_ipes', $ligne );
	if ( false === $ok ) {
		$wpdb->query( 'ROLLBACK' );
		error_log( '[inscriptions-ueb] Enregistrement de l’IPES impossible : ' . $wpdb->last_error );
		/* Deux créations simultanées du même sigle : la clé unique tranche. */
		return new WP_Error( 'ueb_ipes_invalide', 'Corrige les champs signalés.', array( 'sigle' => 'Un autre IPES porte déjà ce sigle.' ) );
	}
	$id = $id ?: (int) $wpdb->insert_id;
	if ( ! ueb_ipes_definir_tutelles( $id, $d['tutelles'] ) ) {
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error( 'ueb_ipes_tutelles', 'Les tutelles n’ont pas pu être enregistrées. Réessaie dans un instant.' );
	}
	$wpdb->query( 'COMMIT' );
	return $id;
}

/**
 * Remplace les tutelles d'un IPES. Une tutelle déjà présente garde sa date
 * « depuis_le » ; seules les nouvelles sont ajoutées, les retirées supprimées.
 * Les sigles doivent avoir été validés (ueb_ipes_valider).
 */
function ueb_ipes_definir_tutelles( $ipes_id, array $sigles ) {
	global $wpdb;
	$sigles = array_values( array_unique( array_map( 'strtoupper', $sigles ) ) );
	if ( $sigles ) {
		$marques   = implode( ',', array_fill( 0, count( $sigles ), '%s' ) );
		$supprimer = $wpdb->prepare( "DELETE FROM ueb_insc_ipes_tutelles WHERE ipes_id = %d AND etablissement NOT IN ($marques)", array_merge( array( $ipes_id ), $sigles ) );
	} else {
		$supprimer = $wpdb->prepare( 'DELETE FROM ueb_insc_ipes_tutelles WHERE ipes_id = %d', $ipes_id );
	}
	if ( false === $wpdb->query( $supprimer ) ) {
		return false;
	}
	foreach ( $sigles as $sigle ) {
		if ( false === $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ueb_insc_ipes_tutelles ( ipes_id, etablissement ) VALUES ( %d, %s )', $ipes_id, $sigle ) ) ) {
			return false;
		}
	}
	return true;
}

/** Active ou désactive un IPES. Vrai si l'IPES existe. */
function ueb_ipes_changer_etat( $id, $actif ) {
	global $wpdb;
	if ( ! ueb_ipes( $id ) ) {
		return false;
	}
	return false !== $wpdb->update(
		'ueb_insc_ipes',
		array( 'actif' => $actif ? 1 : 0, 'modifie_par' => get_current_user_id() ?: null ),
		array( 'id' => (int) $id )
	);
}

/* ---------- Actions de l'administration ----------
   Formulaires de l'onglet IPES (page-administration.php, ?vue=ipes). Le jeton
   de session est vérifié par ueb_traiter_action() ; chaque action revérifie
   que le compte est administrateur. */

/** Adresse de l'onglet IPES : la liste, la fiche d'un IPES ou « nouveau ». */
function ueb_url_ipes( $ipes = null ) {
	$args = array( 'vue' => 'ipes' );
	if ( null !== $ipes ) {
		$args['ipes'] = $ipes;
	}
	return add_query_arg( $args, ueb_url_administration() );
}

/** IPES désigné par le champ « ipes_id » du formulaire, ou retour à la liste. */
function ueb_ipes_du_formulaire() {
	$ipes = ueb_ipes( (int) ( $_POST['ipes_id'] ?? 0 ) );
	if ( ! $ipes ) {
		ueb_flash( 'erreur', 'Cet IPES n’existe pas.' );
		ueb_rediriger( ueb_url_ipes() );
	}
	return $ipes;
}

/**
 * Bloc de la fiche (« filieres », « comptes ») où l'action renvoie : ses
 * messages s'y affichent, là où l'administrateur regarde, plutôt qu'en haut.
 */
function ueb_ipes_retour_bloc( $bloc ) {
	$_SESSION['ueb_ipes_bloc'] = $bloc;
}

/** Créer (ipes_id vide) ou modifier un IPES et ses tutelles. */
function ueb_action_ipes_enregistrer() {
	ueb_exiger_admin();
	$id = (int) ( $_POST['ipes_id'] ?? 0 );
	if ( $id && ! ueb_ipes( $id ) ) {
		ueb_flash( 'erreur', 'Cet IPES n’existe pas.' );
		ueb_rediriger( ueb_url_ipes() );
	}
	$saisie = array();
	foreach ( array( 'sigle', 'nom_fr', 'nom_en', 'ville', 'telephone', 'email', 'convention_ref', 'convention_signee_le', 'convention_fin_le' ) as $champ ) {
		$saisie[ $champ ] = sanitize_text_field( wp_unslash( $_POST[ $champ ] ?? '' ) );
	}
	$saisie['tutelles'] = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['tutelles'] ?? array() ) );

	/* Logo refusé : rien n'est enregistré, et les autres erreurs sont signalées en même temps. */
	$logo = ueb_ipes_logo_envoye();
	if ( isset( $logo['erreur'] ) ) {
		ueb_memoriser_saisie( $saisie, ueb_ipes_valider( ueb_ipes_normaliser( $saisie ), $id ) + array( 'logo' => $logo['erreur'] ) );
		ueb_flash( 'erreur', 'Corrige les champs signalés.' );
		ueb_rediriger( ueb_url_ipes( $id ?: 'nouveau' ) );
	}

	$resultat = ueb_ipes_enregistrer( $saisie, $id );
	if ( is_wp_error( $resultat ) ) {
		ueb_memoriser_saisie( $saisie, (array) $resultat->get_error_data() ?: array( 'general' => $resultat->get_error_message() ) );
		ueb_flash( 'erreur', $resultat->get_error_message() );
		ueb_rediriger( ueb_url_ipes( $id ?: 'nouveau' ) );
	}
	ueb_flash( 'succes', $id ? 'IPES mis à jour.' : 'IPES créé.' );
	if ( $logo && ! ueb_ipes_installer_logo( $resultat, $logo ) ) {
		ueb_flash( 'alerte', 'Le logo n’a pas pu être enregistré : envoie-le à nouveau.' );
	}
	ueb_rediriger( ueb_url_ipes( $resultat ) );
}

/* ---------- Logo ----------
   Rangé dans uploads/ueb-ipes/, public (il s'affiche dans l'administration).
   L'image envoyée est réencodée en PNG : un fichier piégé déguisé en image
   ne survit pas, et la transparence d'un logo est conservée. */

const UEB_IPES_LOGO_MAX_OCTETS = MB_IN_BYTES;
const UEB_IPES_LOGO_MAX_PIXELS = 512;

function ueb_ipes_dossier_logos() {
	$base  = wp_upload_dir( null, false )['basedir'] . '/ueb-ipes';
	$garde = $base . '/.htaccess';
	if ( ! is_dir( $base ) ) {
		wp_mkdir_p( $base );
	}
	if ( ! file_exists( $garde ) ) {
		/* Lecture des images permise, liste du dossier et scripts interdits. */
		file_put_contents( $garde, "Options -Indexes\n<FilesMatch \"\.(php|phtml|phar)\$\">\nRequire all denied\n</FilesMatch>\n" );
		file_put_contents( $base . '/index.php', "<?php // Silence.\n" );
	}
	return $base;
}

/** Adresse du logo d'un IPES, ou null s'il n'en a pas. */
function ueb_ipes_logo_url( $ipes ) {
	if ( ! $ipes || '' === (string) $ipes->logo || ! file_exists( ueb_ipes_dossier_logos() . '/' . $ipes->logo ) ) {
		return null;
	}
	return wp_upload_dir( null, false )['baseurl'] . '/ueb-ipes/' . rawurlencode( $ipes->logo );
}

/**
 * Logo envoyé avec le formulaire : null si aucun fichier, sinon
 * array( 'tmp' => chemin ) ou array( 'erreur' => message ).
 */
function ueb_ipes_logo_envoye() {
	$f = $_FILES['logo'] ?? null;
	if ( ! $f || is_array( $f['name'] ) || UPLOAD_ERR_NO_FILE === $f['error'] ) {
		return null;
	}
	if ( UPLOAD_ERR_OK !== $f['error'] || ! is_uploaded_file( $f['tmp_name'] ) ) {
		return array( 'erreur' => 'Le logo n’a pas pu être envoyé. Réessaie.' );
	}
	if ( $f['size'] > UEB_IPES_LOGO_MAX_OCTETS ) {
		return array( 'erreur' => 'Le logo dépasse 1 Mo.' );
	}
	$type = ( new finfo( FILEINFO_MIME_TYPE ) )->file( $f['tmp_name'] );
	if ( ! in_array( $type, array( 'image/png', 'image/jpeg' ), true ) || ! @getimagesize( $f['tmp_name'] ) ) {
		return array( 'erreur' => 'Le logo doit être une image PNG ou JPEG.' );
	}
	return array( 'tmp' => $f['tmp_name'], 'type' => $type );
}

/**
 * Réencode le logo en PNG (512 px au plus), l'enregistre sous un nom neuf
 * (le navigateur ne garde pas l'ancien en cache) et supprime le précédent.
 */
function ueb_ipes_installer_logo( $ipes_id, array $logo ) {
	global $wpdb;
	$image = 'image/png' === $logo['type'] ? @imagecreatefrompng( $logo['tmp'] ) : @imagecreatefromjpeg( $logo['tmp'] );
	if ( ! $image ) {
		return false;
	}
	$l     = imagesx( $image );
	$h     = imagesy( $image );
	$ratio = min( 1, UEB_IPES_LOGO_MAX_PIXELS / max( $l, $h ) );
	/* Toile transparente : la transparence du logo est conservée telle quelle. */
	$final = imagecreatetruecolor( max( 1, (int) round( $l * $ratio ) ), max( 1, (int) round( $h * $ratio ) ) );
	imagealphablending( $final, false );
	imagesavealpha( $final, true );
	imagefill( $final, 0, 0, imagecolorallocatealpha( $final, 0, 0, 0, 127 ) );
	imagecopyresampled( $final, $image, 0, 0, 0, 0, imagesx( $final ), imagesy( $final ), $l, $h );
	imagedestroy( $image );

	$dossier = ueb_ipes_dossier_logos();
	$nom     = 'ipes-' . (int) $ipes_id . '-' . time() . '.png';
	$ok      = imagepng( $final, $dossier . '/' . $nom, 9 );
	imagedestroy( $final );
	if ( ! $ok ) {
		return false;
	}
	$ancien = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT logo FROM ueb_insc_ipes WHERE id = %d', $ipes_id ) );
	if ( false === $wpdb->update( 'ueb_insc_ipes', array( 'logo' => $nom ), array( 'id' => (int) $ipes_id ) ) ) {
		wp_delete_file( $dossier . '/' . $nom );
		return false;
	}
	if ( '' !== $ancien && $ancien !== $nom ) {
		wp_delete_file( $dossier . '/' . basename( $ancien ) );
	}
	return true;
}

/* ---------- Désactivation ---------- */

/* Rôle WordPress des administrateurs d'IPES (créé à part, hors du registre de la Direction). */
const UEB_ROLE_ADMIN_IPES = 'ueb_admin_ipes';

/** Comptes administrateurs d'un IPES (méta « ueb_ipes_id »), par nom. */
function ueb_ipes_comptes( $ipes_id ) {
	return get_users( array(
		'role'       => UEB_ROLE_ADMIN_IPES,
		'meta_key'   => 'ueb_ipes_id', // phpcs:ignore WordPress.DB.SlowDBQuery
		'meta_value' => (int) $ipes_id, // phpcs:ignore WordPress.DB.SlowDBQuery
		'orderby'    => 'display_name',
	) );
}

/**
 * Désactive un IPES actif, réactive un IPES désactivé. Ses administrateurs
 * suivent : suspendus avec lui (méta « ueb_suspendu_avec_ipes » pour s'en
 * souvenir), rétablis avec lui. Un compte déjà suspendu à part le reste.
 */
function ueb_action_ipes_etat() {
	ueb_exiger_admin();
	$ipes   = ueb_ipes_du_formulaire();
	$actif  = ! (int) $ipes->actif;
	if ( ! ueb_ipes_changer_etat( $ipes->id, $actif ) ) {
		ueb_flash( 'erreur', 'L’état de l’IPES n’a pas pu être changé. Réessaie dans un instant.' );
		ueb_rediriger( ueb_url_ipes( $ipes->id ) );
	}
	foreach ( ueb_ipes_comptes( $ipes->id ) as $compte ) {
		if ( ! $actif && ! ueb_agent_suspendu( $compte->ID ) ) {
			update_user_meta( $compte->ID, 'ueb_agent_suspendu', 1 );
			update_user_meta( $compte->ID, 'ueb_suspendu_avec_ipes', 1 );
		} elseif ( $actif && get_user_meta( $compte->ID, 'ueb_suspendu_avec_ipes', true ) ) {
			delete_user_meta( $compte->ID, 'ueb_agent_suspendu' );
			delete_user_meta( $compte->ID, 'ueb_suspendu_avec_ipes' );
		}
	}
	ueb_flash( 'succes', $actif
		? 'IPES réactivé : ses administrateurs retrouvent leur accès.'
		: 'IPES désactivé : ses administrateurs n’ont plus accès. Rien n’est supprimé.' );
	ueb_rediriger( ueb_url_ipes( $ipes->id ) );
}

/* ---------- Comptes des administrateurs d'IPES ----------
   Un rôle WordPress fixe, qui ne porte que la capacité « ueb_espace_ipes » :
   ni « read » (donc aucun accès à wp-admin), ni aucune capacité du
   back-office. Il reste hors du registre de la Direction, qui ne peut ni le
   voir ni l'attribuer. Le compte est rattaché à son IPES par la méta
   « ueb_ipes_id ». Son espace arrive à l'étape suivante. */

const UEB_CAP_IPES          = 'ueb_espace_ipes';
const UEB_IPES_ROLE_VERSION = '1';

add_action( 'init', function () {
	if ( get_option( 'ueb_insc_ipes_role_version' ) === UEB_IPES_ROLE_VERSION || ! ueb_insc_verrouiller( 'role_ipes' ) ) {
		return;
	}
	if ( ueb_insc_option_en_base( 'ueb_insc_ipes_role_version' ) !== UEB_IPES_ROLE_VERSION ) {
		remove_role( UEB_ROLE_ADMIN_IPES );
		add_role( UEB_ROLE_ADMIN_IPES, 'Administrateur d’IPES', array( UEB_CAP_IPES => true ) );
		update_option( 'ueb_insc_ipes_role_version', UEB_IPES_ROLE_VERSION );
	}
	ueb_insc_deverrouiller( 'role_ipes' );
}, 4 );

/** Vrai si ce compte administre un IPES. */
function ueb_est_admin_ipes( $user_id ) {
	$user = get_userdata( $user_id );
	/* Profil choisi par un super-administrateur : inc/profil-simule.php. */
	return (bool) apply_filters( 'ueb_est_admin_ipes', $user && in_array( UEB_ROLE_ADMIN_IPES, (array) $user->roles, true ), (int) $user_id );
}

/**
 * Crée le compte administrateur d'un IPES actif, avec un mot de passe provisoire.
 *
 * @return array|WP_Error array( id, mot de passe provisoire ).
 */
function ueb_ipes_creer_compte( $ipes, $login, $nom, $email ) {
	$login = sanitize_user( (string) $login, true );
	if ( ! (int) $ipes->actif ) {
		return new WP_Error( 'ueb_ipes_compte', 'Cet IPES est désactivé : réactive-le avant de lui créer un compte.' );
	}
	if ( '' === $login ) {
		return new WP_Error( 'ueb_ipes_compte', 'Saisis un identifiant de connexion.' );
	}
	if ( username_exists( $login ) ) {
		return new WP_Error( 'ueb_ipes_compte', 'Cet identifiant est déjà pris.' );
	}
	if ( '' !== $email && ! is_email( $email ) ) {
		return new WP_Error( 'ueb_ipes_compte', 'Adresse e-mail invalide.' );
	}
	if ( '' !== $email && email_exists( $email ) ) {
		return new WP_Error( 'ueb_ipes_compte', 'Cette adresse e-mail est déjà utilisée par un autre compte.' );
	}
	$mot_de_passe = ueb_mot_de_passe_provisoire();
	$id = wp_insert_user( array(
		'user_login'   => $login,
		'user_pass'    => $mot_de_passe,
		'user_email'   => $email,
		'display_name' => '' !== $nom ? $nom : $login,
		'first_name'   => $nom,
		'role'         => UEB_ROLE_ADMIN_IPES,
	) );
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	update_user_meta( $id, 'ueb_ipes_id', (int) $ipes->id );
	return array( (int) $id, $mot_de_passe );
}

/** Compte administrateur désigné par « compte_id », s'il appartient bien à cet IPES. */
function ueb_ipes_compte_du_formulaire( $ipes ) {
	$id = (int) ( $_POST['compte_id'] ?? 0 );
	if ( ! ueb_est_admin_ipes( $id ) || (int) get_user_meta( $id, 'ueb_ipes_id', true ) !== (int) $ipes->id ) {
		ueb_flash( 'erreur', 'Ce compte n’appartient pas à cet IPES.' );
		ueb_rediriger( ueb_url_ipes( $ipes->id ) . '#comptes' );
	}
	return get_userdata( $id );
}

/** Mot de passe provisoire à afficher une seule fois sur la fiche de l'IPES. */
function ueb_ipes_montrer_mot_de_passe( $user, $mot_de_passe ) {
	$_SESSION['ueb_mdp_ipes'] = array( 'compte' => $user->user_login, 'mdp' => $mot_de_passe );
}

function ueb_action_ipes_compte_creer() {
	ueb_exiger_admin();
	ueb_ipes_retour_bloc( 'comptes' );
	$ipes     = ueb_ipes_du_formulaire();
	$resultat = ueb_ipes_creer_compte(
		$ipes,
		wp_unslash( $_POST['login'] ?? '' ),
		sanitize_text_field( wp_unslash( $_POST['nom'] ?? '' ) ),
		sanitize_email( wp_unslash( $_POST['email'] ?? '' ) )
	);
	if ( is_wp_error( $resultat ) ) {
		ueb_flash( 'erreur', $resultat->get_error_message() );
		ueb_rediriger( ueb_url_ipes( $ipes->id ) . '#comptes' );
	}
	ueb_ipes_montrer_mot_de_passe( get_userdata( $resultat[0] ), $resultat[1] );
	ueb_flash( 'succes', 'Compte administrateur de l’IPES créé.' );
	ueb_rediriger( ueb_url_ipes( $ipes->id ) . '#comptes' );
}

/** Nouveau mot de passe provisoire. Les sessions ouvertes du compte sont fermées. */
function ueb_action_ipes_compte_mdp() {
	ueb_exiger_admin();
	ueb_ipes_retour_bloc( 'comptes' );
	$ipes   = ueb_ipes_du_formulaire();
	$compte = ueb_ipes_compte_du_formulaire( $ipes );
	$mdp    = ueb_mot_de_passe_provisoire();
	wp_set_password( $mdp, $compte->ID );
	WP_Session_Tokens::get_instance( $compte->ID )->destroy_all();
	ueb_ipes_montrer_mot_de_passe( $compte, $mdp );
	ueb_flash( 'succes', 'Nouveau mot de passe provisoire créé.' );
	ueb_rediriger( ueb_url_ipes( $ipes->id ) . '#comptes' );
}

/** Suspendre ou rétablir un compte. Pas de rétablissement tant que l'IPES est désactivé. */
function ueb_action_ipes_compte_etat() {
	ueb_exiger_admin();
	ueb_ipes_retour_bloc( 'comptes' );
	$ipes   = ueb_ipes_du_formulaire();
	$compte = ueb_ipes_compte_du_formulaire( $ipes );
	if ( ueb_agent_suspendu( $compte->ID ) ) {
		if ( ! (int) $ipes->actif ) {
			ueb_flash( 'erreur', 'Cet IPES est désactivé : réactive-le pour rétablir ses comptes.' );
			ueb_rediriger( ueb_url_ipes( $ipes->id ) . '#comptes' );
		}
		delete_user_meta( $compte->ID, 'ueb_agent_suspendu' );
		ueb_flash( 'succes', 'Accès rétabli.' );
	} else {
		update_user_meta( $compte->ID, 'ueb_agent_suspendu', 1 );
		WP_Session_Tokens::get_instance( $compte->ID )->destroy_all();
		ueb_flash( 'succes', 'Accès suspendu. Le compte est conservé.' );
	}
	/* Décision prise à la main : la réactivation de l'IPES ne la défera pas. */
	delete_user_meta( $compte->ID, 'ueb_suspendu_avec_ipes' );
	ueb_rediriger( ueb_url_ipes( $ipes->id ) . '#comptes' );
}
