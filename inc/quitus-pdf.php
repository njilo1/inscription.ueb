<?php
/**
 * PDF du quitus — modèle A « Bilingue officiel ».
 *
 * Une page A4 porte quatre coupons identiques (étudiant, DAF, scolarité,
 * banque) séparés par des pointillés de découpe. Chaque coupon reprend
 * l'en-tête des actes officiels camerounais (français à gauche, anglais à
 * droite, emblèmes au centre) dans la couleur d'identité de l'établissement.
 *
 * Unités : millimètres. Polices : Source Serif 4 et Source Sans 3,
 * converties pour TCPDF dans lib/tcpdf/fonts (familles « uebserif » et
 * « uebsans »).
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

const UEB_PDF_COUPONS = array( 'Coupon étudiant', 'Coupon DAF', 'Coupon scolarité', 'Coupon banque' );

/* Géométrie de la page */
const UEB_PDF_MARGE_X   = 7.0;
const UEB_PDF_MARGE_Y   = 6.0;
const UEB_PDF_LARGEUR   = 196.0;
const UEB_PDF_HAUTEUR   = 67.5;
const UEB_PDF_INTERVALLE = 5.0;

/** Envoie le PDF d'un quitus appartenant à l'étudiant connecté. */
function ueb_telecharger_quitus( $compte, $numero ) {
	$quitus = $compte ? ueb_quitus_du_compte_par_numero( $compte->id, $numero ) : null;
	if ( ! $quitus ) {
		status_header( 404 );
		wp_die( 'Quitus introuvable.', 'Quitus introuvable', array( 'response' => 404 ) );
	}
	ueb_envoyer_pdf_quitus( $quitus );
}

function ueb_envoyer_pdf_quitus( $quitus ) {
	$pdf = ueb_generer_pdf_quitus( $quitus );
	nocache_headers();
	$pdf->Output( 'quitus-' . $quitus->numero . '.pdf', 'D' );
	exit;
}

/** @return TCPDF */
function ueb_generer_pdf_quitus( $quitus ) {
	require_once UEB_INSC_DIR . '/lib/tcpdf/tcpdf.php';

	$pdf  = new TCPDF( 'P', 'mm', 'A4', true, 'UTF-8', false );
	$pdf->SetCreator( 'Plateforme d’inscription — ' . UEB_UNIVERSITE['fr'] );
	$pdf->SetAuthor( UEB_UNIVERSITE['fr'] );
	$pdf->SetTitle( 'Quitus ' . $quitus->numero );
	$pdf->SetSubject( ueb_libelle_type_quitus( $quitus->type ?? 'droits' ) . ' — ' . str_replace( '-', ' – ', $quitus->annee_academique ) );
	$pdf->setFontSubsetting( false );
	$pdf->setPrintHeader( false );
	$pdf->setPrintFooter( false );
	$pdf->SetMargins( 0, 0, 0 );
	$pdf->SetAutoPageBreak( false );
	$pdf->setCellPaddings( 0, 0, 0, 0 );
	$pdf->setCellHeightRatio( 1.12 );
	ueb_pdf_page_quitus( $pdf, $quitus );
	$medical = 'medicaux' === ( $quitus->type ?? 'droits' ) ? $quitus : ueb_medical_du_dossier( $quitus );
	if ( $medical ) {
		if ( 'droits' === ( $quitus->type ?? 'droits' ) ) {
			ueb_pdf_page_quitus( $pdf, $medical );
		}
		require_once UEB_INSC_DIR . '/inc/cms-pdf.php';
		$compte = ueb_compte_par_id( $medical->compte_id );
		$d = array(
			'libelle_identifiant' => 'matricule' === $medical->type_identifiant ? 'Matricule' : 'N° Dossier',
			'numero_dossier' => $medical->identifiant,
			'nom' => $medical->nom,
			'prenom' => $medical->prenom,
			'date_naissance' => $medical->date_naissance,
			'sexe' => $medical->sexe,
			'telephone' => $compte->telephone ?? '',
			'email' => $medical->email ?? '', 'adresse' => $medical->adresse ?? '', 'nom_urgence' => $medical->nom_urgence ?? '', 'numero_urgence' => $medical->numero_urgence ?? '', 'adresse_urgence' => $medical->adresse_urgence ?? '',
		);
		ueb_cms_page_medicale( $pdf, $d );
		ueb_cms_page_examen( $pdf );
	}
	return $pdf;
}

/** Ajoute une page contenant les quatre coupons d'un seul paiement. */
function ueb_pdf_page_quitus( TCPDF $pdf, $quitus ) {
	$etab = ueb_etablissement( $quitus->etablissement );
	$pdf->AddPage();
	$c = ueb_pdf_couleurs( $etab['couleur'] );
	for ( $i = 0; $i < 4; $i++ ) {
		$y = UEB_PDF_MARGE_Y + $i * ( UEB_PDF_HAUTEUR + UEB_PDF_INTERVALLE );
		ueb_pdf_coupon( $pdf, $quitus, $etab, $c, UEB_PDF_COUPONS[ $i ], UEB_PDF_MARGE_X, $y );
		if ( $i < 3 ) {
			ueb_pdf_ligne_coupe( $pdf, $y + UEB_PDF_HAUTEUR + UEB_PDF_INTERVALLE / 2 );
		}
	}
}

/* ---------- Couleurs ---------- */

function ueb_pdf_rvb( $hex ) {
	$hex = ltrim( $hex, '#' );
	return array( hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) );
}

/** Mélange une couleur avec du blanc : $part de couleur (0-1). */
function ueb_pdf_teinte( array $rvb, $part ) {
	return array_map( static fn( $v ) => (int) round( $v * $part + 255 * ( 1 - $part ) ), $rvb );
}

function ueb_pdf_couleurs( $hex ) {
	$etab = ueb_pdf_rvb( $hex );
	return array(
		'etab'   => $etab,
		'ueb'    => ueb_pdf_rvb( UEB_UNIVERSITE['couleur'] ),
		'fond'   => ueb_pdf_teinte( $etab, 0.06 ),
		'encre'  => array( 27, 36, 51 ),
		'gris'   => array( 91, 100, 114 ),
		'filet'  => array( 185, 192, 200 ),
		'pale'   => array( 154, 161, 170 ),
	);
}

/* ---------- Outils de texte ---------- */

/** Taille de police réduite (par pas de 0,2 pt) jusqu'à tenir dans $largeur. */
function ueb_pdf_ajuster( TCPDF $pdf, $texte, $famille, $style, $taille, $largeur, $min = 6.0 ) {
	while ( $taille > $min ) {
		$pdf->SetFont( $famille, $style, $taille );
		if ( $pdf->GetStringWidth( $texte ) <= $largeur ) {
			break;
		}
		$taille -= 0.2;
	}
	$pdf->SetFont( $famille, $style, $taille );
	return $taille;
}

/** Écrit un texte à gauche d'une cellule, centré verticalement. Renvoie la largeur écrite. */
function ueb_pdf_texte( TCPDF $pdf, $x, $y, $h, $texte, array $couleur ) {
	$pdf->SetTextColorArray( $couleur );
	$pdf->SetXY( $x, $y );
	$pdf->Cell( 0, $h, $texte, 0, 0, 'L', false, '', 0, false, 'T', 'M' );
	return $pdf->GetStringWidth( $texte );
}

function ueb_pdf_case( TCPDF $pdf, $x, $y, $cochee, array $c ) {
	$pdf->SetLineStyle( array( 'width' => 0.3, 'color' => $c['encre'], 'dash' => 0 ) );
	$pdf->Rect( $x, $y, 3, 3 );
	if ( $cochee ) {
		$pdf->Line( $x + 0.6, $y + 0.6, $x + 2.4, $y + 2.4 );
		$pdf->Line( $x + 2.4, $y + 0.6, $x + 0.6, $y + 2.4 );
	}
}

/* ---------- Éléments ---------- */

function ueb_pdf_ligne_coupe( TCPDF $pdf, $y ) {
	$pdf->SetLineStyle( array( 'width' => 0.3, 'color' => array( 138, 146, 156 ), 'dash' => '1.2,1' ) );
	$pdf->Line( 0, $y, 210, $y );
	$pdf->SetFillColor( 255, 255, 255 );
	$pdf->Rect( 5.2, $y - 2, 4.6, 4, 'F' );
	$pdf->SetFont( 'zapfdingbats', '', 10 );
	$pdf->SetTextColor( 138, 146, 156 );
	$pdf->SetXY( 5.2, $y - 2 );
	$pdf->Cell( 4.6, 4, chr( 0x22 ), 0, 0, 'C', false, '', 0, false, 'T', 'M' ); // ciseaux
	$pdf->SetLineStyle( array( 'dash' => 0 ) );
}

/**
 * Colonne d'en-tête : République / devise / université / établissement,
 * centrée dans $largeur. Renvoie la hauteur occupée (sans rien dessiner si
 * $dessiner est faux, pour mesurer avant de centrer verticalement).
 */
function ueb_pdf_colonne_entete( TCPDF $pdf, array $lignes, $x, $y, $largeur, array $c, $dessiner ) {
	$pt   = 0.3528;
	$yCur = $y;
	foreach ( $lignes as $ligne ) {
		if ( 'sep' === $ligne['type'] ) {
			if ( $dessiner ) {
				$pdf->SetLineStyle( array( 'width' => 0.3, 'color' => $c['etab'], 'dash' => 0 ) );
				$pdf->Line( $x + $largeur / 2 - 3, $yCur + 1.1, $x + $largeur / 2 + 3, $yCur + 1.1 );
			}
			$yCur += 2.2;
			continue;
		}
		$pdf->SetFont( $ligne['famille'], $ligne['style'], $ligne['taille'] );
		$pdf->setFontSpacing( $ligne['espacement'] ?? 0 );
		$h        = $ligne['taille'] * $pt * 1.12;
		$nbLignes = $pdf->getNumLines( $ligne['texte'], $largeur );
		if ( $dessiner ) {
			$pdf->SetTextColorArray( $ligne['couleur'] );
			$pdf->SetXY( $x, $yCur );
			$pdf->MultiCell( $largeur, $h, $ligne['texte'], 0, 'C', false, 1, $x, $yCur, true, 0, false, true, 0, 'T' );
		}
		$yCur += $h * $nbLignes;
	}
	$pdf->setFontSpacing( 0 );
	return $yCur - $y;
}

function ueb_pdf_lignes_entete( $langue, $etab, array $c ) {
	$fr   = 'fr' === $langue;
	$long = mb_strlen( $etab[ $langue ] ) > 44;
	return array(
		array( 'type' => 'txt', 'texte' => $fr ? 'RÉPUBLIQUE DU CAMEROUN' : 'REPUBLIC OF CAMEROON', 'famille' => 'uebserifb', 'style' => '', 'taille' => 7, 'espacement' => 0.15, 'couleur' => $c['etab'] ),
		array( 'type' => 'txt', 'texte' => $fr ? 'Paix – Travail – Patrie' : 'Peace – Work – Fatherland', 'famille' => 'uebserifi', 'style' => '', 'taille' => 6.4, 'couleur' => $c['etab'] ),
		array( 'type' => 'sep' ),
		array( 'type' => 'txt', 'texte' => mb_strtoupper( $fr ? UEB_UNIVERSITE['fr'] : UEB_UNIVERSITE['en'] ), 'famille' => 'uebserifb', 'style' => '', 'taille' => 8.2, 'espacement' => 0.14, 'couleur' => $c['etab'] ),
		array( 'type' => 'sep' ),
		array( 'type' => 'txt', 'texte' => mb_strtoupper( $etab[ $langue ] ), 'famille' => $fr ? 'uebserifb' : 'uebserifbi', 'style' => '', 'taille' => $long ? 6.9 : 8.4, 'espacement' => 0.05, 'couleur' => $c['etab'] ),
	);
}

/** Contenu court aligné sur le QR du site de préinscription. */
function ueb_pdf_contenu_qr( $q ) {
	$etab = ueb_etablissement( $q->etablissement );
	$nom  = trim( $q->nom . ' ' . $q->prenom );
	$nom_ascii = iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $nom );
	$nom  = false === $nom_ascii ? $nom : $nom_ascii;
	return 'Quitus inscription ' . $q->annee_academique . "\n"
		. 'Dossier : ' . $q->identifiant . "\n"
		. 'Nom : ' . mb_strtoupper( $nom ) . "\n"
		. ( $etab && ! empty( $etab['sigle'] ) ? 'Etab : ' . $etab['sigle'] . "\n" : '' )
		. 'Montant : ' . (int) $q->montant . ' FCFA';
}

function ueb_pdf_coupon( TCPDF $pdf, $q, array $etab, array $c, $libelle_coupon, $x0, $y0 ) {
	$W  = UEB_PDF_LARGEUR;
	$px = 3.0;              // marge intérieure horizontale
	$xi = $x0 + $px;        // bord intérieur gauche
	$Wi = $W - 2 * $px;     // largeur intérieure
	$y  = $y0 + 1.8;

	/* Cadre */
	$pdf->SetLineStyle( array( 'width' => 0.35, 'color' => $c['encre'], 'dash' => 0 ) );
	$pdf->Rect( $x0, $y0, $W, UEB_PDF_HAUTEUR );

	/* ---- En-tête bilingue ---- */
	$logo    = 15.0;
	$emblW   = $logo * 2 + 5.0;
	$colW    = ( $Wi - $emblW - 8 ) / 2;
	$xFr     = $xi;
	$xEmb    = $xi + $colW + 4;
	$xEn     = $xEmb + $emblW + 4;
	$lignesFr = ueb_pdf_lignes_entete( 'fr', $etab, $c );
	$lignesEn = ueb_pdf_lignes_entete( 'en', $etab, $c );
	$hFr     = ueb_pdf_colonne_entete( $pdf, $lignesFr, $xFr, 0, $colW, $c, false );
	$hEn     = ueb_pdf_colonne_entete( $pdf, $lignesEn, $xEn, 0, $colW, $c, false );
	$hEntete = max( $hFr, $hEn, $logo );
	ueb_pdf_colonne_entete( $pdf, $lignesFr, $xFr, $y + ( $hEntete - $hFr ) / 2, $colW, $c, true );
	ueb_pdf_colonne_entete( $pdf, $lignesEn, $xEn, $y + ( $hEntete - $hEn ) / 2, $colW, $c, true );

	$yLogo = $y + ( $hEntete - $logo ) / 2;
	$pdf->Image( ueb_logo_chemin( 'UEB' ), $xEmb, $yLogo, $logo, $logo, 'PNG', '', '', true, 300, '', false, false, 0, 'CM' );
	$pdf->Image( ueb_logo_chemin( $etab['sigle'] ), $xEmb + $logo + 5, $yLogo, $logo, $logo, 'PNG', '', '', true, 300, '', false, false, 0, 'CM' );
	$pdf->SetLineStyle( array( 'width' => 0.25, 'color' => $c['filet'], 'dash' => 0 ) );
	$pdf->Line( $xEmb + $logo + 2.5, $yLogo + 2, $xEmb + $logo + 2.5, $yLogo + $logo - 2 );
	$y += $hEntete + 0.6;

	/* ---- Coordonnées ---- */
	$pdf->SetLineStyle( array( 'width' => 0.25, 'color' => $c['filet'], 'dash' => 0 ) );
	$pdf->Line( $xi, $y, $xi + $Wi, $y );
	$morceaux = array(
		array( $etab['bp'], 'uebsans', $c['gris'] ),
		array( '   |   ', 'uebsans', $c['filet'] ),
		array( 'Tél. ', 'uebsans', $c['gris'] ),
		array( $etab['tel'] ?: '....................', 'uebsemi', $etab['tel'] ? $c['encre'] : $c['pale'] ),
		array( '   |   ', 'uebsans', $c['filet'] ),
		array( $etab['email'] ?: 'Email : ....................', 'uebsans', $etab['email'] ? $c['gris'] : $c['pale'] ),
	);
	$total = 0;
	foreach ( $morceaux as $m ) {
		$pdf->SetFont( $m[1], '', 7 );
		$total += $pdf->GetStringWidth( $m[0] );
	}
	$xc = $xi + ( $Wi - $total ) / 2;
	foreach ( $morceaux as $m ) {
		$pdf->SetFont( $m[1], '', 7 );
		$xc += ueb_pdf_texte( $pdf, $xc, $y + 0.3, 3.4, $m[0], $m[2] );
	}
	$y += 3.9 + 1.1;

	/* ---- Bandeau du coupon (sans filet double : seul le trait sous le bandeau sépare l'en-tête du corps) ---- */
	$hTitre = 5.4;
	$yT     = $y + 0.9;
	$pdf->SetFont( 'uebsansb', '', 8.2 );
	$wTag = $pdf->GetStringWidth( $libelle_coupon ) + 4.8;
	$pdf->SetFillColorArray( $c['encre'] );
	$pdf->Rect( $xi, $yT + 0.3, $wTag, $hTitre - 1.2, 'F' );
	$pdf->setFontSpacing( 0.05 );
	$pdf->SetTextColor( 255, 255, 255 );
	$pdf->SetXY( $xi, $yT + 0.3 );
	$pdf->Cell( $wTag, $hTitre - 1.2, $libelle_coupon, 0, 0, 'C', false, '', 0, false, 'T', 'M' );
	$pdf->setFontSpacing( 0 );

	$pdf->SetFont( 'uebserif', '', 9 );
	$type_quitus = UEB_TYPES_QUITUS[ $q->type ?? 'droits' ] ?? UEB_TYPES_QUITUS['droits'];
	ueb_pdf_texte( $pdf, $xi + $wTag + 3, $yT, $hTitre - 0.6, $type_quitus['titre'], $c['encre'] );

	$annee = str_replace( '-', ' – ', $q->annee_academique );
	$pdf->SetFont( 'uebsansb', '', 9 );
	$wAnnee = $pdf->GetStringWidth( $annee );
	$pdf->SetFont( 'uebsans', '', 8 );
	$wLib = $pdf->GetStringWidth( 'Année académique ' );
	$xA   = $xi + $Wi - $wAnnee - $wLib;
	ueb_pdf_texte( $pdf, $xA, $yT, $hTitre - 0.6, 'Année académique ', $c['gris'] );
	$pdf->SetFont( 'uebsansb', '', 9 );
	ueb_pdf_texte( $pdf, $xA + $wLib, $yT, $hTitre - 0.6, $annee, $c['encre'] );

	$y = $yT + $hTitre - 0.4;
	$pdf->SetLineStyle( array( 'width' => 0.25, 'color' => $c['encre'], 'dash' => 0 ) );
	$pdf->Line( $xi, $y, $xi + $Wi, $y );
	$y += 1.1;

	/* ---- Registre + QR ---- */
	$hBanque = 5.8;
	$yBanque = $y0 + UEB_PDF_HAUTEUR - 1.8 - $hBanque;
	$hCorps  = $yBanque - 1.1 - $y;
	$wQr     = 24.0;
	$wReg    = $Wi - $wQr - 3.5;
	ueb_pdf_registre( $pdf, $q, $c, $xi, $y, $wReg, $hCorps );

	/* Utiliser l'espace disponible et garder une marge blanche de quatre modules. */
	$tQr  = min( $wQr, $hCorps - 5.2 );
	$xQr  = $xi + $wReg + 3.5 + ( $wQr - $tQr ) / 2;
	$yQr  = $y + ( $hCorps - $tQr - 5.2 ) / 2;
	$pdf->write2DBarcode( ueb_pdf_contenu_qr( $q ), 'QRCODE,L', $xQr, $yQr, $tQr, $tQr, array(
		'border' => false, 'padding' => 4, 'fgcolor' => array( 0, 0, 0 ), 'bgcolor' => array( 255, 255, 255 ),
	), 'N' );
	$pdf->SetFont( 'uebsans', '', 6.2 );
	$pdf->SetTextColorArray( $c['gris'] );
	$pdf->SetXY( $xi + $wReg + 3.5, $yQr + $tQr + 0.6 );
	$pdf->Cell( $wQr, 2.4, 'Quitus n°', 0, 2, 'C' );
	ueb_pdf_ajuster( $pdf, $q->numero, 'uebsansb', '', 6.6, $wQr );
	$pdf->SetTextColorArray( $c['encre'] );
	$pdf->Cell( $wQr, 2.6, $q->numero, 0, 0, 'C' );

	/* ---- Ligne bancaire ---- */
	$pdf->SetFillColorArray( $c['fond'] );
	$pdf->Rect( $xi, $yBanque, $Wi, $hBanque, 'F' );
	$pdf->SetFillColorArray( $c['etab'] );
	$pdf->Rect( $xi, $yBanque, 0.9, $hBanque, 'F' );
	$xb = $xi + 3.3;
	$pdf->SetFont( 'uebsans', '', 8 );
	$xb += ueb_pdf_texte( $pdf, $xb, $yBanque, $hBanque, UEB_BANQUE['nom'], $c['encre'] ) + 2.5;
	$xb += ueb_pdf_texte( $pdf, $xb, $yBanque, $hBanque, 'N° de compte', $c['encre'] ) + 2.5;
	$pdf->SetFont( 'uebsansb', '', 9.8 );
	$pdf->setFontSpacing( 0.25 );
	/* Les frais de visite médicale sont versés au compte des services centraux. */
	$compte_banque = 'medicaux' === ( $q->type ?? 'droits' ) ? UEB_COMPTE_MEDICAL : $etab;
	ueb_pdf_texte( $pdf, $xb, $yBanque, $hBanque, ueb_rib( $compte_banque ), $c['encre'] );
	$pdf->setFontSpacing( 0 );

	$pdf->SetFont( 'uebsans', '', 8 );
	$wDate = $pdf->GetStringWidth( 'Date de paiement' );
	$xDate = $xi + $Wi - 2.4 - 28 - 1 - $wDate;
	ueb_pdf_texte( $pdf, $xDate, $yBanque, $hBanque, 'Date de paiement', $c['gris'] );
	$pdf->SetLineStyle( array( 'width' => 0.3, 'color' => $c['encre'], 'dash' => '0.3,0.7' ) );
	$pdf->Line( $xi + $Wi - 2.4 - 28, $yBanque + $hBanque - 1.7, $xi + $Wi - 2.4, $yBanque + $hBanque - 1.7 );
	$pdf->SetLineStyle( array( 'dash' => 0 ) );
}

/** Registre : 5 lignes, deux paires libellé / valeur par ligne. */
function ueb_pdf_registre( TCPDF $pdf, $q, array $c, $x, $y, $w, $h ) {
	$wLib1 = 29.0;
	$wLib2 = 35.0;
	$reste = $w - $wLib1 - $wLib2;
	$wVal1 = $reste * 1.3 / 2.3;
	$wVal2 = $reste - $wVal1;
	$xLib2 = $x + $wLib1 + $wVal1;
	$xVal2 = $xLib2 + $wLib2;
	$hL    = $h / 5;
	$pad   = 1.8;

	$identifiant = 'matricule' === $q->type_identifiant ? 'Matricule' : 'N° de dossier';
	$naissance   = ( new DateTimeImmutable( $q->date_naissance ) )->format( 'd/m/Y' );
	$lignes = array(
		array( $identifiant, $q->identifiant, 'Sexe', $q->sexe ),
		array( 'Nom(s) et prénom(s)', $q->nom . ' ' . $q->prenom, null, null ),
		array( 'Né(e) le', array( $naissance, $q->lieu_naissance ), 'Nationalité', $q->nationalite ),
		array( 'Département', $q->departement, 'Cycle / niveau / parcours', $q->parcours ),
		array( 'Montant', 'montant', null, 'tranches' ),
	);

	foreach ( $lignes as $i => $l ) {
		$yl = $y + $i * $hL;

		/* fonds des libellés */
		$pdf->SetFillColorArray( $c['fond'] );
		$pdf->Rect( $x, $yl, $wLib1, $hL, 'F' );
		if ( null !== $l[2] ) {
			$pdf->Rect( $xLib2, $yl, $wLib2, $hL, 'F' );
		}
		$pdf->SetFont( 'uebsans', '', 7 );
		ueb_pdf_texte( $pdf, $x + $pad, $yl, $hL, $l[0], $c['gris'] );
		if ( null !== $l[2] ) {
			ueb_pdf_texte( $pdf, $xLib2 + $pad, $yl, $hL, $l[2], $c['gris'] );
		}

		/* valeurs */
		$xv = $x + $wLib1 + $pad;
		if ( 'montant' === $l[1] ) {
			$chiffres = ueb_formater_montant( $q->montant ) . ' FCFA';
			$lettres  = ueb_montant_en_lettres( $q->montant );
			$place    = $wVal1 + $wLib2 - 2 * $pad;
			$taille   = 8.8;
			do {
				$pdf->SetFont( 'uebsansb', '', $taille );
				$w1 = $pdf->GetStringWidth( $chiffres );
				$pdf->SetFont( 'uebsans', '', $taille * 0.84 );
				$w2 = $pdf->GetStringWidth( $lettres );
				$taille -= 0.2;
			} while ( $w1 + 1.8 + $w2 > $place && $taille > 6 );
			$taille += 0.2;
			$pdf->SetFont( 'uebsansb', '', $taille );
			$xv += ueb_pdf_texte( $pdf, $xv, $yl, $hL, $chiffres, $c['encre'] ) + 1.8;
			$pdf->SetFont( 'uebsans', '', $taille * 0.84 );
			ueb_pdf_texte( $pdf, $xv, $yl, $hL, $lettres, $c['gris'] );
		} elseif ( is_array( $l[1] ) ) {
			list( $date, $lieu ) = $l[1];
			$place = $wVal1 - 2 * $pad;
			$pdf->SetFont( 'uebsansb', '', 8.8 );
			$wd = $pdf->GetStringWidth( $date );
			$pdf->SetFont( 'uebsans', '', 7.4 );
			$wa = $pdf->GetStringWidth( ' à ' ) + 1;
			ueb_pdf_ajuster( $pdf, $lieu, 'uebsansb', '', 8.8, $place - $wd - $wa );
			$tLieu = $pdf->getFontSizePt();
			$pdf->SetFont( 'uebsansb', '', 8.8 );
			$xv += ueb_pdf_texte( $pdf, $xv, $yl, $hL, $date, $c['encre'] );
			$pdf->SetFont( 'uebsans', '', 7.4 );
			$xv += ueb_pdf_texte( $pdf, $xv + 0.5, $yl, $hL, ' à ', $c['gris'] ) + 1;
			$pdf->SetFont( 'uebsansb', '', $tLieu );
			ueb_pdf_texte( $pdf, $xv, $yl, $hL, $lieu, $c['encre'] );
		} else {
			$place = ( null === $l[2] ? $w - $wLib1 : $wVal1 ) - 2 * $pad;
			ueb_pdf_ajuster( $pdf, $l[1], 'uebsansb', '', 8.8, $place );
			ueb_pdf_texte( $pdf, $xv, $yl, $hL, $l[1], $c['encre'] );
		}

		if ( 'tranches' === $l[3] ) {
			if ( 'medicaux' === ( $q->type ?? 'droits' ) ) {
				/* Visite médicale : paiement unique, donc pas de cases de tranche. */
				$pdf->SetFont( 'uebsemi', '', 8 );
				ueb_pdf_texte( $pdf, $xVal2 + $pad, $yl, $hL, 'Paiement unique', $c['encre'] );
			} else {
				$xt = $xVal2 + $pad;
				$yc = $yl + ( $hL - 3 ) / 2;
				foreach ( array( 1, 2 ) as $t ) {
					/* La tranche 3 vaut « les deux tranches » : les deux cases sont cochées. */
					ueb_pdf_case( $pdf, $xt, $yc, (int) $q->tranche === $t || 3 === (int) $q->tranche, $c );
					$pdf->SetFont( 'uebsemi', '', 8 );
					$xt += 4.2 + ueb_pdf_texte( $pdf, $xt + 4.2, $yl, $hL, "Tranche $t", $c['encre'] ) + 3.5;
				}
			}
		} elseif ( null !== $l[3] ) {
			ueb_pdf_ajuster( $pdf, $l[3], 'uebsansb', '', 8.8, $wVal2 - 2 * $pad );
			ueb_pdf_texte( $pdf, $xVal2 + $pad, $yl, $hL, $l[3], $c['encre'] );
		}
	}

	/* filets */
	$pdf->SetLineStyle( array( 'width' => 0.25, 'color' => $c['filet'], 'dash' => 0 ) );
	$pdf->Rect( $x, $y, $w, $h );
	for ( $i = 1; $i < 5; $i++ ) {
		$pdf->Line( $x, $y + $i * $hL, $x + $w, $y + $i * $hL );
	}
	foreach ( array( 0, 2, 3 ) as $i ) { // séparateur avant la 2e paire
		$pdf->Line( $xLib2, $y + $i * $hL, $xLib2, $y + ( $i + 1 ) * $hL );
	}
	$pdf->Line( $xVal2, $y + 4 * $hL, $xVal2, $y + 5 * $hL ); // avant les tranches
}
