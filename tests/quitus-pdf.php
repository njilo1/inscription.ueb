<?php
/**
 * PDF de contrôle sans accès à WordPress ni à la base : données fictives uniquement.
 * Usage : /opt/lampp/bin/php tests/quitus-pdf.php [répertoire de sortie]
 * Puis : python3 tests/quitus-pdf.py [même répertoire]
 */
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'UEB_INSC_DIR', dirname( __DIR__ ) );
define( 'K_PATH_CACHE', sys_get_temp_dir() . '/' );
foreach ( array( 'config', 'nombres', 'quitus', 'quitus-pdf' ) as $module ) {
	require UEB_INSC_DIR . '/inc/' . $module . '.php';
}
require UEB_INSC_DIR . '/lib/tcpdf/tcpdf.php';

function pdf_de_controle() {
	$pdf = new TCPDF( 'P', 'mm', 'A4', true, 'UTF-8', false );
	$pdf->setFontSubsetting( false );
	$pdf->setPrintHeader( false );
	$pdf->setPrintFooter( false );
	$pdf->SetMargins( 0, 0, 0 );
	$pdf->SetAutoPageBreak( false );
	$pdf->setCellPaddings( 0, 0, 0, 0 );
	$pdf->setCellHeightRatio( 1.12 );
	return $pdf;
}

$sortie = $argv[1] ?? sys_get_temp_dir() . '/ueb-quitus-qr-review';
if ( ! is_dir( $sortie ) ) {
	mkdir( $sortie, 0700, true );
}
$recueil = pdf_de_controle();
$manifest = array();
foreach ( ueb_etablissements() as $sigle => $etab ) {
	foreach ( array( 'droits', 'medicaux' ) as $type ) {
		$medical = 'medicaux' === $type;
		$q = (object) array(
			'numero' => 'TEST-' . $sigle . ( $medical ? '-M' : '' ) . '-2627-0001',
			'etablissement' => $sigle, 'annee_academique' => '2026-2027', 'type' => $type,
			'type_identifiant' => $medical ? 'matricule' : 'dossier',
			'identifiant' => $medical ? '24TEST01' . $sigle : 'UEB-2026-999999',
			'nom' => 'ÉTUDIANT TEST', 'prenom' => 'Marie-Anne Élodie',
			'date_naissance' => '2002-04-12', 'lieu_naissance' => 'Ebolowa',
			'sexe' => 'F', 'nationalite' => 'Camerounaise',
			'departement' => 'Biotechnologie et pharmacognosie', 'parcours' => 'M1',
			'montant' => $medical ? 5000 : 50000, 'tranche' => $medical ? 0 : 3,
			'email' => 'non-imprime@example.com', 'nom_urgence' => 'Contact privé',
		);
		$pdf = pdf_de_controle();
		ueb_pdf_page_quitus( $pdf, $q );
		ueb_pdf_page_quitus( $recueil, $q );
		$fichier = $sigle . '-' . $type . '.pdf';
		$pdf->Output( $sortie . '/' . $fichier, 'F' );
		$manifest[] = array( 'fichier' => $fichier, 'couleur' => $etab['couleur'], 'quitus' => $q, 'qr' => ueb_pdf_contenu_qr( $q ) );
	}
}
$recueil->Output( $sortie . '/quitus-couleurs-qr-18-pages.pdf', 'F' );

// Vérifier aussi une longue identité et une deuxième tranche avec accents/apostrophes.
$q = clone $q;
$q->type = 'droits';
$q->numero = 'TEST-ENSTMO-2627-0002';
$q->nom = 'ÉTUDIANT TEST NOM COMPOSÉ NGONO ESSOMBA';
$q->prenom = 'Marie-Anne Élodie Grâce Charlotte';
$q->lieu_naissance = 'Nko’ovos — Ebolowa';
$q->departement = 'Sciences et techniques maritimes, océanographie et gestion des ressources halieutiques';
$q->tranche = 2;
$q->montant = 25000;
$pdf = pdf_de_controle();
ueb_pdf_page_quitus( $pdf, $q );
$pdf->Output( $sortie . '/identite-longue.pdf', 'F' );
$manifest[] = array( 'fichier' => 'identite-longue.pdf', 'couleur' => $etab['couleur'], 'quitus' => $q, 'qr' => ueb_pdf_contenu_qr( $q ) );
file_put_contents( $sortie . '/manifest.json', json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
echo "PDF de contrôle générés : $sortie\n";
