<?php
/**
 * Catalogue des filières de l'université, géré dans l'onglet « Filières » de
 * l'administration (templates/composants/filieres-admin.php).
 *
 * Les tables sont celles du catalogue de la préinscription, importées telles
 * quelles dans la base de l'inscription (ueb_filieres, ueb_facultes) : le
 * quitus et le suivi des paiements les lisent sous ces noms. Une fois
 * importées, elles appartiennent à l'inscription et se gèrent ici.
 *
 * Règles :
 *   - une filière n'est jamais supprimée : fermée, elle disparaît du quitus
 *     mais les quitus déjà établis la gardent ;
 *   - son établissement ne change plus après sa création (les quitus portent
 *     l'établissement de leur filière) ;
 *   - le couple code + établissement + type est unique (clé de la table) ;
 *   - un établissement absent de ueb_facultes y est ajouté à la première
 *     filière, d'après la configuration (inc/config.php).
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

const UEB_TYPES_FORMATION = array(
	'classique' => 'Classique',
	'pro'       => 'Professionnelle',
);
/* Cycle hérité du catalogue de la préinscription : il dit à quel moment du
   cursus la filière s'ouvre. Il n'a pas d'effet sur le quitus. */
const UEB_CYCLES_FILIERE = array(
	'tous'         => 'Tous niveaux',
	'tronc_commun' => 'Tronc commun',
	'licence_3'    => 'Licence 3',
	'master'       => 'Master',
	'doctorat'     => 'Doctorat',
);
const UEB_CODE_FILIERE_REGEX = '/^[A-Z0-9][A-Z0-9_-]{1,29}$/';

/* ---------- Disponibilité ---------- */

/** Vrai si les tables du catalogue existent dans la base (importées depuis la préinscription). */
function ueb_catalogue_disponible() {
	static $ok = null;
	if ( null === $ok ) {
		global $wpdb;
		$ok = 'ueb_filieres' === $wpdb->get_var( "SHOW TABLES LIKE 'ueb\\_filieres'" )
			&& 'ueb_facultes' === $wpdb->get_var( "SHOW TABLES LIKE 'ueb\\_facultes'" );
	}
	return $ok;
}

/* ---------- Lecture ---------- */

/**
 * Filières du catalogue avec le sigle de leur établissement et le nombre de
 * quitus qui les citent, triées par établissement puis par libellé.
 *
 * @param array $filtres recherche (libellé ou code), etablissement (sigle),
 *                       etat ('ouverte' | 'fermee').
 */
function ueb_catalogue_filieres( array $filtres = array() ) {
	global $wpdb;
	if ( ! ueb_catalogue_disponible() ) {
		return array();
	}
	$where  = array( '1 = 1' );
	$params = array();
	$recherche = trim( (string) ( $filtres['recherche'] ?? '' ) );
	if ( '' !== $recherche ) {
		$motif    = '%' . $wpdb->esc_like( $recherche ) . '%';
		$where[]  = '( fi.libelle LIKE %s OR fi.code LIKE %s )';
		$params[] = $motif;
		$params[] = $motif;
	}
	if ( ! empty( $filtres['etablissement'] ) ) {
		$where[]  = 'fa.code = %s';
		$params[] = strtoupper( (string) $filtres['etablissement'] );
	}
	$etat = $filtres['etat'] ?? '';
	if ( in_array( $etat, array( 'ouverte', 'fermee' ), true ) ) {
		$where[] = 'ouverte' === $etat ? 'fi.actif = 1' : 'fi.actif = 0';
	}
	$sql = 'SELECT fi.*, fa.code AS etablissement,
			( SELECT COUNT(*) FROM ueb_insc_quitus q WHERE q.filiere_id = fi.id ) AS nb_quitus,
			( SELECT GROUP_CONCAT( n.niveau ORDER BY n.niveau SEPARATOR \', \' ) FROM ueb_filieres_niveaux n WHERE n.filiere_id = fi.id ) AS niveaux
		FROM ueb_filieres fi
		JOIN ueb_facultes fa ON fa.id = fi.faculte_id
		WHERE ' . implode( ' AND ', $where ) . '
		ORDER BY fa.code, fi.libelle, fi.type_formation';
	return $wpdb->get_results( $params ? $wpdb->prepare( $sql, $params ) : $sql );
}

/** Une filière du catalogue (avec le sigle de son établissement et son nombre de quitus), ou null. */
function ueb_catalogue_filiere( $id ) {
	global $wpdb;
	if ( ! ueb_catalogue_disponible() ) {
		return null;
	}
	return $wpdb->get_row( $wpdb->prepare(
		'SELECT fi.*, fa.code AS etablissement, ( SELECT COUNT(*) FROM ueb_insc_quitus q WHERE q.filiere_id = fi.id ) AS nb_quitus
		FROM ueb_filieres fi JOIN ueb_facultes fa ON fa.id = fi.faculte_id WHERE fi.id = %d',
		$id
	) );
}

/** Nombre de filières ouvertes et fermées, par établissement : sigle => array( ouvertes, fermees ). */
function ueb_catalogue_compte_par_etablissement() {
	global $wpdb;
	$compte = array();
	if ( ! ueb_catalogue_disponible() ) {
		return $compte;
	}
	foreach ( $wpdb->get_results( 'SELECT fa.code, SUM( fi.actif = 1 ) AS ouvertes, SUM( fi.actif = 0 ) AS fermees FROM ueb_filieres fi JOIN ueb_facultes fa ON fa.id = fi.faculte_id GROUP BY fa.code' ) as $l ) {
		$compte[ $l->code ] = array( (int) $l->ouvertes, (int) $l->fermees );
	}
	return $compte;
}

/* ---------- Établissements ---------- */

/**
 * Identifiant de l'établissement dans ueb_facultes, créé d'après la
 * configuration s'il n'y figure pas encore. 0 si le sigle est inconnu.
 */
function ueb_catalogue_faculte_id( $sigle ) {
	global $wpdb;
	$etab = ueb_etablissement( $sigle );
	if ( ! $etab ) {
		return 0;
	}
	$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ueb_facultes WHERE code = %s', $etab['sigle'] ) );
	if ( $id ) {
		return $id;
	}
	$wpdb->insert( 'ueb_facultes', array(
		'code'   => $etab['sigle'],
		'nom_fr' => $etab['fr'],
		'nom_en' => $etab['en'],
		'slug'   => sanitize_title( $etab['fr'] ),
		'type'   => ueb_type_etablissement( $etab['sigle'] ),
		'actif'  => 1,
	) );
	/* Deux créations simultanées : la clé unique sur le code tranche, on relit. */
	return (int) ( $wpdb->insert_id ?: $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ueb_facultes WHERE code = %s', $etab['sigle'] ) ) );
}

/* ---------- Validation ---------- */

/** Saisie mise en forme : code en majuscules sans espaces, libellé rogné. */
function ueb_catalogue_normaliser( array $d ) {
	return array(
		'etablissement'  => strtoupper( trim( (string) ( $d['etablissement'] ?? '' ) ) ),
		'code'           => strtoupper( preg_replace( '/\s+/', '', (string) ( $d['code'] ?? '' ) ) ),
		'libelle'        => trim( preg_replace( '/\s+/u', ' ', (string) ( $d['libelle'] ?? '' ) ) ),
		'type_formation' => (string) ( $d['type_formation'] ?? 'classique' ),
		'cycle'          => (string) ( $d['cycle'] ?? 'tous' ),
	);
}

/**
 * Erreurs champ => message d'une filière normalisée (vide si elle convient).
 *
 * @param object|null $existante Filière modifiée : son établissement est conservé.
 */
function ueb_catalogue_valider( array $d, $existante = null ) {
	global $wpdb;
	$erreurs = array();
	$sigle   = $existante ? $existante->etablissement : $d['etablissement'];
	if ( ! $existante && ! ueb_etablissement( $sigle ) ) {
		$erreurs['etablissement'] = 'Choisis l’établissement de la filière.';
	}
	if ( ! preg_match( UEB_CODE_FILIERE_REGEX, $d['code'] ) ) {
		$erreurs['code'] = 'Code de 2 à 30 caractères : lettres, chiffres, « _ » ou « - ».';
	}
	$longueur = mb_strlen( $d['libelle'] );
	if ( $longueur < 3 || $longueur > 150 ) {
		$erreurs['libelle'] = 'Le nom de la filière compte 3 à 150 caractères.';
	}
	if ( ! isset( UEB_TYPES_FORMATION[ $d['type_formation'] ] ) ) {
		$erreurs['type_formation'] = 'Choisis le type de formation.';
	}
	if ( ! isset( UEB_CYCLES_FILIERE[ $d['cycle'] ] ) ) {
		$erreurs['cycle'] = 'Choisis le cycle.';
	}
	if ( ! isset( $erreurs['code'] ) && ! isset( $erreurs['etablissement'] ) && ! isset( $erreurs['type_formation'] ) && $wpdb->get_var( $wpdb->prepare(
		'SELECT fi.id FROM ueb_filieres fi JOIN ueb_facultes fa ON fa.id = fi.faculte_id
		WHERE fi.code = %s AND fa.code = %s AND fi.type_formation = %s AND fi.id <> %d',
		$d['code'], $sigle, $d['type_formation'], $existante ? $existante->id : 0
	) ) ) {
		$erreurs['code'] = 'Ce code existe déjà pour une filière de même type dans cet établissement.';
	}
	return $erreurs;
}

/* ---------- Écriture ---------- */

/**
 * Ajoute ($id = 0) ou modifie une filière du catalogue. En cas d'erreur, le
 * WP_Error porte le tableau champ => message.
 *
 * @return int|WP_Error Identifiant de la filière.
 */
function ueb_catalogue_filiere_enregistrer( array $d, $id = 0 ) {
	global $wpdb;
	if ( ! ueb_catalogue_disponible() ) {
		return new WP_Error( 'ueb_catalogue', 'Le catalogue des filières n’est pas encore importé dans cette base.' );
	}
	$existante = null;
	if ( $id ) {
		$existante = ueb_catalogue_filiere( $id );
		if ( ! $existante ) {
			return new WP_Error( 'ueb_catalogue', 'Cette filière n’existe pas.' );
		}
	}
	$d       = ueb_catalogue_normaliser( $d );
	$erreurs = ueb_catalogue_valider( $d, $existante );
	if ( $erreurs ) {
		return new WP_Error( 'ueb_catalogue', 'Corrige les champs signalés.', $erreurs );
	}
	$ligne = array(
		'code'           => $d['code'],
		'libelle'        => $d['libelle'],
		'type_formation' => $d['type_formation'],
		'cycle'          => $d['cycle'],
	);
	if ( $existante ) {
		$ok = $wpdb->update( 'ueb_filieres', $ligne, array( 'id' => (int) $id ) );
	} else {
		$faculte = ueb_catalogue_faculte_id( $d['etablissement'] );
		$ok      = $faculte ? $wpdb->insert( 'ueb_filieres', $ligne + array( 'faculte_id' => $faculte, 'actif' => 1 ) ) : false;
	}
	if ( false === $ok ) {
		/* Deux saisies simultanées du même code : la clé unique tranche. */
		return new WP_Error( 'ueb_catalogue', 'Corrige les champs signalés.', array( 'code' => 'Ce code existe déjà pour une filière de même type dans cet établissement.' ) );
	}
	return $existante ? (int) $id : (int) $wpdb->insert_id;
}

/** Ferme (retire du quitus) ou rouvre une filière. Vrai si elle existe. */
function ueb_catalogue_filiere_changer_etat( $id, $ouverte ) {
	global $wpdb;
	if ( ! ueb_catalogue_filiere( $id ) ) {
		return false;
	}
	return false !== $wpdb->update( 'ueb_filieres', array( 'actif' => $ouverte ? 1 : 0 ), array( 'id' => (int) $id ) );
}

/* ---------- Actions de l'administration ---------- */

/** Adresse de l'onglet Filières, avec ses filtres éventuels. */
function ueb_url_filieres( array $args = array() ) {
	return add_query_arg( array( 'vue' => 'filieres' ) + $args, ueb_url_administration() );
}

function ueb_action_catalogue_filiere_enregistrer() {
	ueb_exiger_admin();
	$id     = (int) ( $_POST['filiere_id'] ?? 0 );
	$saisie = array();
	foreach ( array( 'etablissement', 'code', 'libelle', 'type_formation', 'cycle' ) as $champ ) {
		$saisie[ $champ ] = sanitize_text_field( wp_unslash( $_POST[ $champ ] ?? '' ) );
	}
	$resultat = ueb_catalogue_filiere_enregistrer( $saisie, $id );
	if ( is_wp_error( $resultat ) ) {
		$erreurs = $resultat->get_error_data();
		ueb_memoriser_saisie( $saisie + array( 'filiere_id' => $id ), is_array( $erreurs ) && $erreurs ? $erreurs : array( 'general' => $resultat->get_error_message() ) );
		ueb_rediriger( $id ? ueb_url_filieres( array( 'modifier' => $id ) ) . '#modifier' : ueb_url_filieres( array( 'ajout' => 1 ) ) . '#ajout' );
	}
	$filiere = ueb_catalogue_filiere( $resultat );
	ueb_flash( 'succes', ( $id ? 'Filière modifiée : ' : 'Filière ajoutée : ' ) . $filiere->libelle . ' (' . $filiere->etablissement . ').' );
	/* Après un ajout, on reste sur le formulaire pour saisir la suivante dans le même établissement. */
	ueb_rediriger( $id ? ueb_url_filieres( array( 'etab' => $filiere->etablissement ) ) . '#filiere-' . (int) $resultat : ueb_url_filieres( array( 'ajout' => 1, 'etab_ajout' => $filiere->etablissement ) ) . '#ajout' );
}

function ueb_action_catalogue_filiere_etat() {
	ueb_exiger_admin();
	$filiere = ueb_catalogue_filiere( (int) ( $_POST['filiere_id'] ?? 0 ) );
	if ( ! $filiere ) {
		ueb_flash( 'erreur', 'Cette filière n’existe pas.' );
		ueb_rediriger( ueb_url_filieres() );
	}
	$ouverte = ! (int) $filiere->actif;
	if ( ueb_catalogue_filiere_changer_etat( $filiere->id, $ouverte ) ) {
		ueb_flash( 'succes', $ouverte ? 'Filière rouverte : elle est de nouveau proposée dans le quitus.' : 'Filière fermée : elle n’est plus proposée dans le quitus. Les quitus déjà établis la gardent.' );
	} else {
		ueb_flash( 'erreur', 'La filière n’a pas pu être modifiée. Réessaie dans un instant.' );
	}
	$retour = wp_validate_redirect( (string) wp_get_referer(), ueb_url_filieres() ) ?: ueb_url_filieres();
	ueb_rediriger( $retour . '#filiere-' . (int) $filiere->id );
}

/* ---------- Unités de formation doctorale ---------- */

/* Liste de l'École Doctorale : chaque unité (UFD) est une filière du cycle
   « doctorat », rattachée à l'établissement indiqué (sa faculté, ou l'école ou
   l'institut cité entre parenthèses sur la liste). */
const UEB_UFD_DOCTORALES = array(
	array( 'code' => 'UFD-2SAD', 'etablissement' => 'FALSH',  'libelle' => 'Unité de Formation Doctorale en Sciences Humaines, Société, Arts et Développement' ),
	array( 'code' => 'UFD-SS',   'etablissement' => 'FMSP',   'libelle' => 'Unité de Formation Doctorale en Sciences de la Santé' ),
	array( 'code' => 'UFD-SATI', 'etablissement' => 'FS',     'libelle' => 'Unité de Formation Doctorale en Sciences Appliquées et Technologies Industrielles' ),
	array( 'code' => 'UFD-SMAP', 'etablissement' => 'ISABEE', 'libelle' => 'Unité de Formation Doctorale des Sciences de la Matière, de l’Agroenvironnement et des Procédés' ),
	array( 'code' => 'UFD-SIGD', 'etablissement' => 'ENSET',  'libelle' => 'Unité de Formation Doctorale en Sciences d’Ingénieries Globales et Durables' ),
	array( 'code' => 'UFD-STML', 'etablissement' => 'ENSTMO', 'libelle' => 'Unité de Formation Doctorale des Sciences et Technologies de la Mer et du Littoral' ),
	array( 'code' => 'UFD-SEG',  'etablissement' => 'FSEG',   'libelle' => 'Unité de Formation Doctorale en Sciences Économiques et de Gestion' ),
	array( 'code' => 'UFD-TSI',  'etablissement' => 'ESTLC',  'libelle' => 'Unité de Formation Doctorale en Technologies et Sciences de l’Innovation' ),
	array( 'code' => 'UFD-DSP',  'etablissement' => 'FSJP',   'libelle' => 'Unité de Formation Doctorale en Droit et Science Politique' ),
);

/**
 * Fait accepter le cycle « doctorat » par la colonne « cycle » de ueb_filieres.
 * Si cette colonne est une liste fermée (ENUM) qui ne le contient pas, on ajoute
 * « doctorat » à la fin de la liste : les cycles existants ne bougent pas, et
 * rien n'est réécrit dans la table. Une colonne de texte n'a rien à changer.
 *
 * @return bool Vrai si la colonne accepte « doctorat ».
 */
function ueb_catalogue_accepter_doctorat() {
	global $wpdb;
	$colonne = $wpdb->get_row( "SHOW COLUMNS FROM ueb_filieres LIKE 'cycle'" );
	if ( ! $colonne ) {
		return false;
	}
	if ( 0 !== stripos( $colonne->Type, 'enum(' ) || false !== stripos( $colonne->Type, "'doctorat'" ) ) {
		return true;
	}
	$sql = 'ALTER TABLE ueb_filieres MODIFY cycle ' . substr( $colonne->Type, 0, -1 ) . ",'doctorat') "
		. ( 'NO' === $colonne->Null ? 'NOT NULL' : 'NULL' )
		. ( null !== $colonne->Default ? $wpdb->prepare( ' DEFAULT %s', $colonne->Default ) : '' );
	if ( false === $wpdb->query( $sql ) ) {
		error_log( '[inscriptions-ueb] Le cycle « doctorat » n’a pas pu être ajouté à ueb_filieres.cycle : ' . $wpdb->last_error );
		return false;
	}
	return true;
}

/**
 * Ajoute au catalogue les unités doctorales qui n'y figurent pas encore (filière
 * classique, cycle « doctorat »), par les mêmes contrôles que l'onglet Filières.
 * Une unité déjà présente (même code, même établissement) est laissée telle
 * quelle, ouverte ou fermée : on ne la recrée ni ne la rouvre.
 *
 * Sans effet si le catalogue n'est pas importé ou s'il n'a pas encore les
 * colonnes « cycle » et « actif ». Si la colonne « cycle » est une liste fermée,
 * « doctorat » y est d'abord ajouté. Un échec est consigné, jamais bloquant.
 *
 * @return int Nombre d'unités ajoutées.
 */
function ueb_catalogue_semer_ufd() {
	global $wpdb;
	if ( ! ueb_catalogue_disponible() ) {
		return 0;
	}
	if ( array_diff( array( 'cycle', 'actif' ), $wpdb->get_col( 'SHOW COLUMNS FROM ueb_filieres' ) ) ) {
		error_log( '[inscriptions-ueb] Unités doctorales non ajoutées : ueb_filieres n’a pas les colonnes « cycle » et « actif ».' );
		return 0;
	}
	if ( ! ueb_catalogue_accepter_doctorat() ) {
		return 0;
	}
	$ajoutees = 0;
	foreach ( UEB_UFD_DOCTORALES as $ufd ) {
		$existe = $wpdb->get_var( $wpdb->prepare(
			'SELECT fi.id FROM ueb_filieres fi JOIN ueb_facultes fa ON fa.id = fi.faculte_id WHERE fi.code = %s AND fa.code = %s',
			$ufd['code'], $ufd['etablissement']
		) );
		if ( $existe ) {
			continue;
		}
		$resultat = ueb_catalogue_filiere_enregistrer( $ufd + array( 'type_formation' => 'classique', 'cycle' => 'doctorat' ) );
		if ( is_wp_error( $resultat ) ) {
			error_log( '[inscriptions-ueb] Unité doctorale ' . $ufd['code'] . ' non ajoutée : ' . wp_json_encode( $resultat->get_error_data() ?: $resultat->get_error_message() ) );
			continue;
		}
		++$ajoutees;
	}
	return $ajoutees;
}
