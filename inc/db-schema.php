<?php
/**
 * Tables de l'inscription, dans la base partagée avec la préinscription.
 *
 * Préfixe fixe « ueb_insc_ » (comme les tables ueb_* de la préinscription,
 * indépendant de $wpdb->prefix). Création idempotente : CREATE TABLE
 * IF NOT EXISTS, déclenchée quand UEB_INSC_DB_VERSION change.
 *
 * Les tables ueb_* de la préinscription ne sont que lues, jamais modifiées.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

const UEB_INSC_DB_VERSION = '7';

function ueb_insc_schema() {
	return array(
		/* Comptes étudiants. Identifiant de connexion : matricule ou n° de dossier.
		   version_session : incrémentée à chaque changement de mot de passe pour
		   déconnecter toutes les autres sessions ouvertes. */
		'ueb_insc_comptes' => "CREATE TABLE IF NOT EXISTS ueb_insc_comptes (
			id INT UNSIGNED NOT NULL AUTO_INCREMENT,
			matricule VARCHAR(20) NULL,
			numero_dossier VARCHAR(30) NULL,
			telephone VARCHAR(12) NOT NULL,
			mot_de_passe VARCHAR(255) NOT NULL,
			doit_changer_mdp TINYINT(1) NOT NULL DEFAULT 0,
			version_session INT UNSIGNED NOT NULL DEFAULT 1,
			statut ENUM('actif','bloque') NOT NULL DEFAULT 'actif',
			date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			date_modification DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			derniere_connexion DATETIME NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_matricule (matricule),
			UNIQUE KEY uniq_dossier (numero_dossier),
			KEY idx_telephone (telephone)
		) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

		/* Quitus. Les champs imprimés sont figés au moment de la génération :
		   modifier le compte ensuite ne réécrit pas un quitus déjà remis. */
		'ueb_insc_quitus' => "CREATE TABLE IF NOT EXISTS ueb_insc_quitus (
			id INT UNSIGNED NOT NULL AUTO_INCREMENT,
			numero VARCHAR(30) NOT NULL,
			code_verif CHAR(20) NOT NULL,
			compte_id INT UNSIGNED NOT NULL,
			etablissement VARCHAR(10) NOT NULL,
			annee_academique CHAR(9) NOT NULL,
			type ENUM('droits','medicaux') NOT NULL DEFAULT 'droits',
			situation VARCHAR(12) NOT NULL DEFAULT '',
			filiere_id INT UNSIGNED NULL,
			quitus_droits_id INT UNSIGNED NULL,
			email VARCHAR(150) NOT NULL DEFAULT '',
			adresse VARCHAR(255) NOT NULL DEFAULT '',
			nom_urgence VARCHAR(150) NOT NULL DEFAULT '',
			numero_urgence VARCHAR(20) NOT NULL DEFAULT '',
			adresse_urgence VARCHAR(255) NOT NULL DEFAULT '',
			identifiant VARCHAR(30) NOT NULL,
			type_identifiant ENUM('matricule','dossier') NOT NULL,
			nom VARCHAR(100) NOT NULL,
			prenom VARCHAR(150) NOT NULL,
			date_naissance DATE NOT NULL,
			lieu_naissance VARCHAR(150) NOT NULL,
			sexe ENUM('M','F') NOT NULL,
			nationalite VARCHAR(100) NOT NULL,
			departement VARCHAR(150) NOT NULL,
			parcours VARCHAR(150) NOT NULL,
			montant INT UNSIGNED NOT NULL,
			tranche TINYINT UNSIGNED NOT NULL,
			statut ENUM('genere','recu_envoye','verifie','rejete') NOT NULL DEFAULT 'genere',
			motif_rejet VARCHAR(255) NULL,
			verifie_par BIGINT UNSIGNED NULL,
			date_verification DATETIME NULL,
			date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			date_modification DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_numero (numero),
			UNIQUE KEY uniq_code (code_verif),
			UNIQUE KEY uniq_medical_droits (quitus_droits_id),
			KEY idx_compte (compte_id),
			KEY idx_etab_annee (etablissement, annee_academique),
			KEY idx_statut (statut)
		) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

		/* Photos ou scans des reçus de paiement bancaire, rattachés à un quitus. */
		'ueb_insc_recus' => "CREATE TABLE IF NOT EXISTS ueb_insc_recus (
			id INT UNSIGNED NOT NULL AUTO_INCREMENT,
			quitus_id INT UNSIGNED NOT NULL,
			compte_id INT UNSIGNED NOT NULL,
			fichier VARCHAR(255) NOT NULL,
			nom_original VARCHAR(255) NOT NULL,
			type_mime VARCHAR(50) NOT NULL,
			taille INT UNSIGNED NOT NULL,
			objet VARCHAR(12) NOT NULL DEFAULT '',
			date_envoi DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_quitus (quitus_id),
			KEY idx_compte (compte_id)
		) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

		/* Compteur de numéros de quitus par établissement, année et type :
		   les droits universitaires et les frais médicaux ont chacun leur série. */
		'ueb_insc_sequence' => "CREATE TABLE IF NOT EXISTS ueb_insc_sequence (
			etablissement VARCHAR(10) NOT NULL,
			annee_academique CHAR(9) NOT NULL,
			type ENUM('droits','medicaux') NOT NULL DEFAULT 'droits',
			dernier INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY (etablissement, annee_academique, type)
		) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

		/* Établissements privés sous tutelle (IPES), enregistrés par
		   l'administration. convention_fin_le reste facultative. */
		'ueb_insc_ipes' => "CREATE TABLE IF NOT EXISTS ueb_insc_ipes (
			id INT UNSIGNED NOT NULL AUTO_INCREMENT,
			sigle VARCHAR(20) NOT NULL,
			nom_fr VARCHAR(150) NOT NULL,
			nom_en VARCHAR(150) NOT NULL DEFAULT '',
			ville VARCHAR(100) NOT NULL DEFAULT '',
			telephone VARCHAR(12) NOT NULL DEFAULT '',
			email VARCHAR(150) NOT NULL DEFAULT '',
			logo VARCHAR(100) NOT NULL DEFAULT '',
			convention_ref VARCHAR(100) NOT NULL DEFAULT '',
			convention_signee_le DATE NULL,
			convention_fin_le DATE NULL,
			montant_annuel_du INT UNSIGNED NULL,
			actif TINYINT(1) NOT NULL DEFAULT 1,
			modifie_par BIGINT UNSIGNED NULL,
			date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			date_modification DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_sigle (sigle)
		) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

		/* Tutelles d'un IPES : un ou plusieurs établissements de l'UEb
		   (sigles de ueb_etablissements()). */
		'ueb_insc_ipes_tutelles' => "CREATE TABLE IF NOT EXISTS ueb_insc_ipes_tutelles (
			ipes_id INT UNSIGNED NOT NULL,
			etablissement VARCHAR(10) NOT NULL,
			depuis_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (ipes_id, etablissement),
			KEY idx_etablissement (etablissement)
		) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

		/* Filières d'un IPES. Une filière retirée est désactivée, jamais
		   supprimée. La collation rend l'unicité insensible à la casse. */
		'ueb_insc_ipes_filieres' => "CREATE TABLE IF NOT EXISTS ueb_insc_ipes_filieres (
			id INT UNSIGNED NOT NULL AUTO_INCREMENT,
			ipes_id INT UNSIGNED NOT NULL,
			libelle VARCHAR(150) NOT NULL,
			actif TINYINT(1) NOT NULL DEFAULT 1,
			date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_ipes_libelle (ipes_id, libelle)
		) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

		/* Étudiants d'un IPES, saisis par son administrateur : une ligne par
		   année académique (filière et niveau changent d'une année à l'autre),
		   le matricule restant le même. */
		'ueb_insc_ipes_etudiants' => "CREATE TABLE IF NOT EXISTS ueb_insc_ipes_etudiants (
			id INT UNSIGNED NOT NULL AUTO_INCREMENT,
			ipes_id INT UNSIGNED NOT NULL,
			annee_academique CHAR(9) NOT NULL,
			matricule VARCHAR(30) NOT NULL,
			nom VARCHAR(100) NOT NULL,
			prenom VARCHAR(150) NOT NULL,
			filiere_id INT UNSIGNED NOT NULL,
			niveau VARCHAR(2) NOT NULL,
			telephone VARCHAR(12) NOT NULL DEFAULT '',
			saisi_par BIGINT UNSIGNED NULL,
			date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			date_modification DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_ipes_annee_matricule (ipes_id, annee_academique, matricule),
			KEY idx_filiere (filiere_id)
		) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

		/* Versements de pension d'un étudiant d'IPES. bordereau_id : le seul
		   bordereau qui le reverse (NULL tant qu'il n'est dans aucun). */
		'ueb_insc_ipes_paiements' => "CREATE TABLE IF NOT EXISTS ueb_insc_ipes_paiements (
			id INT UNSIGNED NOT NULL AUTO_INCREMENT,
			ipes_id INT UNSIGNED NOT NULL,
			etudiant_id INT UNSIGNED NOT NULL,
			montant INT UNSIGNED NOT NULL,
			date_paiement DATE NOT NULL,
			bordereau_id INT UNSIGNED NULL,
			saisi_par BIGINT UNSIGNED NULL,
			date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			date_modification DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_etudiant (etudiant_id),
			KEY idx_ipes_bordereau (ipes_id, bordereau_id)
		) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

		/* Bordereaux de reversement d'un IPES à UNE de ses tutelles. Le total
		   est figé à l'envoi ; un bordereau rejeté redevient modifiable. */
		'ueb_insc_ipes_bordereaux' => "CREATE TABLE IF NOT EXISTS ueb_insc_ipes_bordereaux (
			id INT UNSIGNED NOT NULL AUTO_INCREMENT,
			numero VARCHAR(40) NOT NULL,
			ipes_id INT UNSIGNED NOT NULL,
			etablissement VARCHAR(10) NOT NULL,
			annee_academique CHAR(9) NOT NULL,
			total INT UNSIGNED NOT NULL DEFAULT 0,
			statut ENUM('brouillon','envoye','verifie','rejete') NOT NULL DEFAULT 'brouillon',
			motif_rejet VARCHAR(255) NULL,
			cree_par BIGINT UNSIGNED NULL,
			date_envoi DATETIME NULL,
			verifie_par BIGINT UNSIGNED NULL,
			date_verification DATETIME NULL,
			date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			date_modification DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_numero (numero),
			KEY idx_ipes_annee (ipes_id, annee_academique),
			KEY idx_etab_statut (etablissement, statut)
		) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

		/* Compteur des numéros de bordereau, par IPES et par année académique. */
		'ueb_insc_ipes_sequence' => "CREATE TABLE IF NOT EXISTS ueb_insc_ipes_sequence (
			ipes_id INT UNSIGNED NOT NULL,
			annee_academique CHAR(9) NOT NULL,
			dernier INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY (ipes_id, annee_academique)
		) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
	);
}

/**
 * Ajouts sur une base déjà installée : CREATE TABLE IF NOT EXISTS ne touche
 * pas une table existante. Version 2 : le type de quitus (droits ou frais
 * médicaux), et sa série de numéros.
 */
function ueb_insc_migrer() {
	global $wpdb;
	$colonnes = $wpdb->get_col( 'SHOW COLUMNS FROM ueb_insc_quitus' );
	if ( $colonnes && ! in_array( 'type', $colonnes, true ) ) {
		if ( false === $wpdb->query( "ALTER TABLE ueb_insc_quitus ADD COLUMN type ENUM('droits','medicaux') NOT NULL DEFAULT 'droits' AFTER annee_academique" ) ) {
			return false;
		}
	}
	foreach ( array( 'situation' => "VARCHAR(12) NOT NULL DEFAULT ''", 'filiere_id' => 'INT UNSIGNED NULL', 'quitus_droits_id' => 'INT UNSIGNED NULL, ADD UNIQUE KEY uniq_medical_droits (quitus_droits_id)', 'email' => "VARCHAR(150) NOT NULL DEFAULT ''", 'adresse' => "VARCHAR(255) NOT NULL DEFAULT ''", 'nom_urgence' => "VARCHAR(150) NOT NULL DEFAULT ''", 'numero_urgence' => "VARCHAR(20) NOT NULL DEFAULT ''", 'adresse_urgence' => "VARCHAR(255) NOT NULL DEFAULT ''" ) as $colonne => $definition ) {
		if ( ! in_array( $colonne, $colonnes, true ) && false === $wpdb->query( "ALTER TABLE ueb_insc_quitus ADD COLUMN $colonne $definition" ) ) {
			return false;
		}
	}
	/* Version 5 : ce que paie chaque reçu (tranche 1, tranche 2, totalité, frais médicaux). */
	$colonnes_recus = $wpdb->get_col( 'SHOW COLUMNS FROM ueb_insc_recus' );
	if ( $colonnes_recus && ! in_array( 'objet', $colonnes_recus, true ) ) {
		if ( false === $wpdb->query( "ALTER TABLE ueb_insc_recus ADD COLUMN objet VARCHAR(12) NOT NULL DEFAULT '' AFTER taille" ) ) {
			return false;
		}
	}
	$colonnes_sequence = $wpdb->get_col( 'SHOW COLUMNS FROM ueb_insc_sequence' );
	if ( $colonnes_sequence && ! in_array( 'type', $colonnes_sequence, true ) ) {
		if ( false === $wpdb->query( "ALTER TABLE ueb_insc_sequence ADD COLUMN type ENUM('droits','medicaux') NOT NULL DEFAULT 'droits',
			DROP PRIMARY KEY, ADD PRIMARY KEY (etablissement, annee_academique, type)" ) ) {
			return false;
		}
	}
	/* Version 7 : montant annuel dû par un IPES, prévu par sa convention (indicatif). */
	$colonnes_ipes = $wpdb->get_col( 'SHOW COLUMNS FROM ueb_insc_ipes' );
	if ( $colonnes_ipes && ! in_array( 'montant_annuel_du', $colonnes_ipes, true ) ) {
		if ( false === $wpdb->query( 'ALTER TABLE ueb_insc_ipes ADD COLUMN montant_annuel_du INT UNSIGNED NULL AFTER convention_fin_le' ) ) {
			return false;
		}
	}
	return true;
}

function ueb_insc_installer_schema() {
	global $wpdb;
	foreach ( ueb_insc_schema() as $table => $sql ) {
		if ( false === $wpdb->query( $sql ) ) {
			error_log( "[inscriptions-ueb] Création de $table impossible : " . $wpdb->last_error );
			return;
		}
	}
	if ( ! ueb_insc_migrer() ) {
		error_log( '[inscriptions-ueb] Migration impossible : ' . $wpdb->last_error );
		return;
	}
	update_option( 'ueb_insc_db_version', UEB_INSC_DB_VERSION );
}

/**
 * Verrou MySQL pour les tâches d'installation lancées depuis « init ».
 * Au premier affichage, le navigateur envoie plusieurs requêtes à la fois :
 * sans verrou, chacune croit l'installation à faire et la lance (colonnes
 * ajoutées deux fois, Page créée en double). Non bloquant : la requête qui
 * n'obtient pas le verrou passe son tour.
 */
function ueb_insc_verrouiller( $nom ) {
	global $wpdb;
	return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', DB_NAME . '.' . $nom ) );
}
function ueb_insc_deverrouiller( $nom ) {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', DB_NAME . '.' . $nom ) );
}

/** Valeur d'une option lue en base, sans le cache de la requête en cours. */
function ueb_insc_option_en_base( $nom ) {
	global $wpdb;
	return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $nom ) );
}

add_action( 'init', function () {
	if ( get_option( 'ueb_insc_db_version' ) === UEB_INSC_DB_VERSION || ! ueb_insc_verrouiller( 'schema' ) ) {
		return;
	}
	/* Une autre requête a pu finir le travail pendant qu'on attendait. */
	if ( ueb_insc_option_en_base( 'ueb_insc_db_version' ) !== UEB_INSC_DB_VERSION ) {
		ueb_insc_installer_schema();
	}
	ueb_insc_deverrouiller( 'schema' );
}, 5 );
