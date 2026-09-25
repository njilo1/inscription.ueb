<?php
/**
 * Configuration : les neuf établissements, la banque, l'année académique
 * et les formats d'identifiants.
 *
 * Les coordonnées « null » n'ont pas encore été communiquées : elles
 * s'impriment comme un champ à compléter sur le quitus.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

const UEB_UNIVERSITE = array(
	'fr'    => "Université d'Ebolowa",
	'en'    => 'The University of Ebolowa',
	'bp'    => 'BP 118 Ebolowa',
	'tel'   => '+237 6 76 29 54 88',
	'email' => 'info@unv-ebolowa.cm',
	'couleur' => '#1E3A8A', /* identité de l'université, sur le quitus */
	'logo'  => 'ueb.png',
);

/* Tous les comptes sont ouverts à la CCA Bank, banque 10039, guichet 10012. */
const UEB_BANQUE = array(
	'nom'     => 'CCA Bank',
	'pays'    => 'CM21',
	'banque'  => '10039',
	'guichet' => '10012',
);

/* Compte des services centraux : il reçoit les frais de visite médicale,
   pour toute l'université (relevé des comptes bancaires de l'UEb). */
const UEB_COMPTE_MEDICAL = array( 'compte' => '00272772201', 'cle' => '07' );

/* Les deux quitus que la plateforme génère. */
const UEB_TYPES_QUITUS = array(
	'droits'   => array(
		'libelle' => 'Droits universitaires',
		'titre'   => 'Quitus de paiement des droits universitaires',
		'aide'    => 'Les droits de ton inscription, payés en une ou deux tranches.',
	),
	'medicaux' => array(
		'libelle' => 'Frais médicaux',
		'titre'   => 'Quitus de paiement des frais de visite médicale',
		'aide'    => 'La visite médicale, payée en une seule fois sur le compte des services centraux.',
	),
);

/* Frais de visite médicale : montant fixe, selon la situation de l'étudiant.
   Les nouveaux étudiants ne paient pas : la visite est comprise dans les
   frais de préinscription. */
const UEB_FRAIS_MEDICAUX = array(
	'ancien'  => array( 'montant' => 3000, 'libelle' => 'Réinscription sans interruption' ),
	'reprise' => array( 'montant' => 5000, 'libelle' => 'Réinscription avec interruption' ),
);

/* Droits des formations classiques : 50 000 FCFA par an, saisis par
   l'étudiant par multiples de 5 000. Premier versement de 25 000 au moins :
   en dessous de 50 000 c'est la première tranche, à 50 000 les deux. Le
   second versement va jusqu'au reste de l'année. */
const UEB_DROITS_CLASSIQUES = 50000;
const UEB_DROITS_MINIMUM    = 25000;
const UEB_DROITS_PAS        = 5000;
const UEB_NIVEAUX_INSCRIPTION = array( 'L1' => 'L1 — Licence 1', 'L2' => 'L2 — Licence 2', 'L3' => 'L3 — Licence 3', 'M1' => 'M1 — Master 1', 'M2' => 'M2 — Master 2' );

/**
 * Les neuf établissements. « couleur » est la couleur d'identité utilisée
 * sur le quitus et dans l'interface.
 */
function ueb_etablissements() {
	return array(
		'FS'     => array(
			'fr' => 'Faculté des Sciences', 'en' => 'Faculty of Science',
			'tel' => '699 73 07 81', 'email' => 'fsunivebolowa@gmail.com', 'bp' => 'BP 118 Ebolowa',
			'compte' => '00272769101', 'cle' => '92', 'couleur' => '#1f5aa6', 'ville' => 'Ebolowa',
		),
		'FSJP'   => array(
			'fr' => 'Faculté des Sciences Juridiques et Politiques', 'en' => 'Faculty of Law and Political Science',
			'tel' => null, 'email' => null, 'bp' => 'BP 118 Ebolowa',
			'compte' => '00272771301', 'cle' => '88', 'couleur' => '#8B1E1E', 'ville' => 'Ebolowa',
		),
		'FSEG'   => array(
			'fr' => 'Faculté des Sciences Économiques et de Gestion', 'en' => 'Faculty of Economics and Management',
			'tel' => null, 'email' => null, 'bp' => 'BP 118 Ebolowa',
			'compte' => '00272770501', 'cle' => '63', 'couleur' => '#16803C', 'ville' => 'Ebolowa',
		),
		'FALSH'  => array(
			'fr' => 'Faculté des Arts, Lettres et Sciences Humaines', 'en' => 'Faculty of Arts, Letters and Human Sciences',
			'tel' => null, 'email' => null, 'bp' => 'BP 118 Ebolowa',
			'compte' => '00272772501', 'cle' => '77', 'couleur' => '#6A1B6D', 'ville' => 'Ebolowa',
		),
		'FMSP'   => array(
			'fr' => 'Faculté de Médecine et des Sciences Pharmaceutiques', 'en' => 'Faculty of Medicine and Pharmaceutical Sciences',
			'tel' => null, 'email' => null, 'bp' => 'Sangmélima',
			'compte' => '00272768902', 'cle' => '84', 'couleur' => '#0077B6', 'ville' => 'Sangmélima',
		),
		'ENSET'  => array(
			'fr' => "École Normale Supérieure d'Enseignement Technique", 'en' => 'Higher Technical Teacher Training College',
			'tel' => null, 'email' => null, 'bp' => 'BP 118 Ebolowa',
			'compte' => '00272770701', 'cle' => '45', 'couleur' => '#006B3C', 'ville' => 'Ebolowa',
		),
		'ISABEE' => array(
			'fr' => "Institut Supérieur d'Agriculture, du Bois, de l'Eau et de l'Environnement",
			'en' => 'Higher Institute of Agriculture, Forestry, Water and Environment',
			'tel' => '694 19 36 07 / 677 07 97 47', 'email' => 'contact@isabee.cm', 'bp' => 'BP 118 Ebolowa',
			'compte' => '00272771401', 'cle' => '79', 'couleur' => '#3A7D44', 'ville' => 'Ebolowa',
		),
		'ESTLC'  => array(
			'fr' => 'École Supérieure de Transport, de Logistique et de Commerce',
			'en' => 'Higher School of Transport, Logistics and Commerce',
			'tel' => null, 'email' => 'estlc@estlc.unv-ebolowa.cm', 'bp' => 'BP 22 Ambam',
			'compte' => '00272768601', 'cle' => '40', 'couleur' => '#4E7F1D', 'ville' => 'Ambam',
		),
		'ENSTMO' => array(
			'fr' => 'École Nationale Supérieure des Sciences et Techniques Maritimes et Océaniques',
			'en' => 'National Advanced School of Maritime and Ocean Science and Technology',
			'tel' => '695 73 41 50 / 677 17 54 40', 'email' => null, 'bp' => 'Kribi',
			'compte' => '00272769801', 'cle' => '29', 'couleur' => '#1b2c5a', 'ville' => 'Kribi',
		),
	);
}

/** Un établissement par son sigle, ou null. */
function ueb_etablissement( $sigle ) {
	$liste = ueb_etablissements();
	$sigle = strtoupper( (string) $sigle );
	return isset( $liste[ $sigle ] ) ? $liste[ $sigle ] + array( 'sigle' => $sigle ) : null;
}

/** Chemin et URL d'un logo (université ou établissement). */
function ueb_logo_chemin( $sigle ) {
	$fichier = 'UEB' === $sigle ? UEB_UNIVERSITE['logo'] : strtolower( $sigle ) . '.png';
	return UEB_INSC_DIR . '/assets/images/logos/' . $fichier;
}
function ueb_logo_url( $sigle ) {
	$fichier = 'UEB' === $sigle ? UEB_UNIVERSITE['logo'] : strtolower( $sigle ) . '.png';
	return UEB_INSC_URI . '/assets/images/logos/' . $fichier;
}

/** RIB complet d'un établissement, groupé comme sur le relevé de la banque. */
function ueb_rib( array $etab ) {
	return sprintf( '%s %s %s %s %s', UEB_BANQUE['pays'], UEB_BANQUE['banque'], UEB_BANQUE['guichet'], $etab['compte'], $etab['cle'] );
}

/**
 * Année académique en cours. Elle bascule le 1er septembre :
 * le 31 août 2027 on est en 2026-2027, le 1er septembre 2027 en 2027-2028.
 *
 * @return array{debut:int, fin:int, libelle:string, code:string}
 */
function ueb_annee_academique( $timestamp = null ) {
	$date  = ( new DateTimeImmutable( '@' . ( $timestamp ?? time() ) ) )->setTimezone( wp_timezone() );
	$annee = (int) $date->format( 'Y' );
	$debut = (int) $date->format( 'n' ) >= 9 ? $annee : $annee - 1;
	return array(
		'debut'   => $debut,
		'fin'     => $debut + 1,
		'libelle' => $debut . ' – ' . ( $debut + 1 ),
		'code'    => $debut . '-' . ( $debut + 1 ),
	);
}

/* Formats d'identifiants (comparés en majuscules, sans espaces). */
const UEB_REGEX_DOSSIER   = '/^(DEMO-)?UEB-\d{4}-\d{6}$/';
const UEB_REGEX_MATRICULE = '/^\d{2}[A-Z0-9]{4,13}$/';
const UEB_REGEX_TELEPHONE = '/^6\d{8}$/'; /* mobile camerounais : 9 chiffres commençant par 6 */

/** Normalise un identifiant saisi : majuscules, sans espaces. */
function ueb_normaliser_identifiant( $valeur ) {
	return strtoupper( preg_replace( '/\s+/', '', (string) $valeur ) );
}

/** « dossier », « matricule » ou null selon le format de l'identifiant. */
function ueb_type_identifiant( $identifiant ) {
	if ( preg_match( UEB_REGEX_DOSSIER, $identifiant ) ) {
		return 'dossier';
	}
	if ( preg_match( UEB_REGEX_MATRICULE, $identifiant ) ) {
		return 'matricule';
	}
	return null;
}

/** Téléphone mobile camerounais (9 chiffres commençant par 6), sans indicatif ni espaces, ou null. */
function ueb_normaliser_telephone( $valeur ) {
	$chiffres = preg_replace( '/\D+/', '', (string) $valeur );
	if ( 12 === strlen( $chiffres ) && str_starts_with( $chiffres, '237' ) ) {
		$chiffres = substr( $chiffres, 3 );
	}
	return preg_match( UEB_REGEX_TELEPHONE, $chiffres ) ? $chiffres : null;
}

/** 699730781 → « 699 73 07 81 ». */
function ueb_formater_telephone( $tel ) {
	return preg_replace( '/^(\d{3})(\d{2})(\d{2})(\d{2})$/', '$1 $2 $3 $4', (string) $tel );
}
