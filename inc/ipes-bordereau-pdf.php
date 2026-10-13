<?php
/**
 * PDF du bordereau de reversement d'un IPES à sa tutelle.
 *
 * A4 portrait : en-tête officiel bilingue dans la couleur de la tutelle (le
 * même que les quitus, inc/quitus-pdf.php), bandeau du bordereau, IPES et
 * tutelle, tableau des étudiants reversés, au montant par étudiant figé à
 * l'envoi (en-tête répété à chaque page), total en chiffres et en lettres,
 * signatures, pied de page numéroté.
 *
 * Disponible dès l'envoi : un brouillon n'a pas encore de numéro officiel.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

const UEB_BRD_MARGE   = 14.0;
const UEB_BRD_LARGEUR = 182.0; /* 210 − 2 × 14 */
const UEB_BRD_BAS     = 272.0; /* au-delà, page suivante (pied de page à 284) */

/** Vrai si ce bordereau a un PDF : il a été envoyé au moins une fois (numéro officiel). */
function ueb_ipes_bordereau_a_pdf( $bordereau ) {
	return $bordereau && ! str_starts_with( (string) $bordereau->numero, 'BROUILLON-' );
}

/** Envoie le PDF au navigateur et s'arrête. Les droits sont vérifiés par l'appelant. */
function ueb_ipes_envoyer_pdf_bordereau( $ipes, $bordereau ) {
	$pdf = ueb_ipes_generer_pdf_bordereau( $ipes, $bordereau );
	nocache_headers();
	$pdf->Output( 'bordereau-' . $bordereau->numero . '.pdf', 'D' );
	exit;
}

/** @return TCPDF */
function ueb_ipes_generer_pdf_bordereau( $ipes, $bordereau ) {
	require_once UEB_INSC_DIR . '/lib/tcpdf/tcpdf.php';
	require_once UEB_INSC_DIR . '/inc/quitus-pdf.php';

	$tutelle    = ueb_etablissement( $bordereau->etablissement );
	$c          = ueb_pdf_couleurs( $tutelle['couleur'] );
	$etudiants  = ueb_ipes_bordereau_etudiants( $ipes->id, $bordereau->id );
	$unitaire   = (int) $bordereau->montant_unitaire;
	$annee      = str_replace( '-', ' – ', $bordereau->annee_academique );

	$pdf = new TCPDF( 'P', 'mm', 'A4', true, 'UTF-8', false );
	$pdf->SetCreator( 'Plateforme d’inscription — ' . UEB_UNIVERSITE['fr'] );
	$pdf->SetAuthor( $ipes->nom_fr );
	$pdf->SetTitle( 'Bordereau ' . $bordereau->numero );
	$pdf->SetSubject( 'Reversement par étudiant ' . $annee . ' — ' . $ipes->sigle . ' → ' . $bordereau->etablissement );
	$pdf->setFontSubsetting( false );
	$pdf->setPrintHeader( false );
	$pdf->setPrintFooter( false );
	$pdf->SetMargins( 0, 0, 0 );
	$pdf->SetAutoPageBreak( false );
	$pdf->setCellPaddings( 0, 0, 0, 0 );
	$pdf->setCellHeightRatio( 1.12 );
	$pdf->AddPage();

	$x = UEB_BRD_MARGE;
	$W = UEB_BRD_LARGEUR;
	$y = ueb_brd_entete( $pdf, $tutelle, $c, 10.0 );

	/* ---- Bandeau ---- */
	$pdf->SetFillColorArray( $c['etab'] );
	$pdf->Rect( $x, $y, $W, 11, 'F' );
	$pdf->SetTextColor( 255, 255, 255 );
	$pdf->SetFont( 'uebsansb', '', 11 );
	$pdf->setFontSpacing( 0.2 );
	$pdf->SetXY( $x + 4, $y );
	$pdf->Cell( $W - 8, 11, 'BORDEREAU DE REVERSEMENT', 0, 0, 'L', false, '', 0, false, 'T', 'M' );
	$pdf->setFontSpacing( 0 );
	$pdf->SetFont( 'uebsansb', '', 10 );
	$pdf->SetXY( $x + 4, $y );
	$pdf->Cell( $W - 8, 11, $bordereau->numero, 0, 0, 'R', false, '', 0, false, 'T', 'M' );
	$y += 11 + 1.5;
	$pdf->SetFont( 'uebsans', '', 8 );
	ueb_pdf_texte( $pdf, $x, $y, 4, 'Année académique ' . $annee . ( $bordereau->date_envoi ? ' · envoyé le ' . mysql2date( 'd/m/Y à H:i', $bordereau->date_envoi ) : '' ) . ' · ' . ( UEB_IPES_STATUTS_BORDEREAU[ $bordereau->statut ] ?? '' ), $c['gris'] );
	$y += 7;

	/* ---- IPES et tutelle ---- */
	$demi = ( $W - 6 ) / 2;
	$ipes_lignes = array_filter( array(
		$ipes->ville,
		trim( ( $ipes->telephone ? 'Tél. ' . ueb_formater_telephone( $ipes->telephone ) : '' ) . ( $ipes->telephone && $ipes->email ? '  ·  ' : '' ) . ( $ipes->email ?: '' ) ),
		$ipes->convention_ref ? 'Convention ' . $ipes->convention_ref . ( $ipes->convention_signee_le ? ' du ' . mysql2date( 'd/m/Y', $ipes->convention_signee_le ) : '' ) : '',
	) );
	$tutelle_lignes = array_filter( array(
		UEB_UNIVERSITE['fr'],
		$tutelle['bp'] ?? '',
		trim( ( $tutelle['tel'] ? 'Tél. ' . $tutelle['tel'] : '' ) . ( $tutelle['tel'] && $tutelle['email'] ? '  ·  ' : '' ) . ( $tutelle['email'] ?: '' ) ),
	) );
	$h1 = ueb_brd_encadre( $pdf, $x, $y, $demi, 'ÉTABLISSEMENT PRIVÉ (IPES)', $ipes->nom_fr . ' (' . $ipes->sigle . ')', $ipes_lignes, $c, false );
	$h2 = ueb_brd_encadre( $pdf, $x + $demi + 6, $y, $demi, 'ÉTABLISSEMENT DE TUTELLE', $tutelle['fr'] . ' (' . $tutelle['sigle'] . ')', $tutelle_lignes, $c, false );
	$hEnc = max( $h1, $h2 );
	ueb_brd_encadre( $pdf, $x, $y, $demi, 'ÉTABLISSEMENT PRIVÉ (IPES)', $ipes->nom_fr . ' (' . $ipes->sigle . ')', $ipes_lignes, $c, true, $hEnc );
	ueb_brd_encadre( $pdf, $x + $demi + 6, $y, $demi, 'ÉTABLISSEMENT DE TUTELLE', $tutelle['fr'] . ' (' . $tutelle['sigle'] . ')', $tutelle_lignes, $c, true, $hEnc );
	$y += $hEnc + 7;

	/* ---- Tableau des étudiants ---- */
	$colonnes = array(
		array( 'N°', 10, 'C' ),
		array( 'Matricule', 28, 'L' ),
		array( 'Nom et prénom', 64, 'L' ),
		array( 'Filière · niveau', 54, 'L' ),
		array( 'Montant (FCFA)', 26, 'R' ),
	);
	$y = ueb_brd_ligne_entete( $pdf, $colonnes, $x, $y, $c );
	foreach ( array_values( $etudiants ) as $i => $v ) {
		/* Un nom trop long passe sur plusieurs lignes : la ligne du tableau grandit (jamais de nom tronqué). */
		$pdf->SetFont( 'uebsans', '', 8.2 );
		$lignes_nom = max( 1, $pdf->getNumLines( $v->nom . ' ' . $v->prenom, $colonnes[2][1] - 3 ) );
		$hLigne     = max( 6.2, $lignes_nom * 3.6 + 2.4 );
		if ( $y + $hLigne > UEB_BRD_BAS ) {
			$pdf->AddPage();
			$y = ueb_brd_ligne_entete( $pdf, $colonnes, $x, 12.0, $c );
		}
		if ( $i % 2 ) {
			$pdf->SetFillColorArray( $c['fond'] );
			$pdf->Rect( $x, $y, $W, $hLigne, 'F' );
		}
		$valeurs = array(
			(string) ( $i + 1 ),
			$v->matricule,
			$v->nom . ' ' . $v->prenom,
			trim( ( $v->filiere ?? '' ) . ' · ' . $v->niveau, ' ·' ),
			ueb_formater_montant( $unitaire ),
		);
		$xc = $x;
		foreach ( $colonnes as $k => $col ) {
			$pdf->SetTextColorArray( $c['encre'] );
			if ( 2 === $k ) {
				$pdf->SetFont( 'uebsans', '', 8.2 );
				$pdf->MultiCell( $col[1] - 3, $hLigne, $valeurs[ $k ], 0, 'L', false, 0, $xc + 1.5, $y, true, 0, false, true, $hLigne, 'M' );
			} else {
				ueb_pdf_ajuster( $pdf, $valeurs[ $k ], 4 === $k ? 'uebsemi' : 'uebsans', '', 8.2, $col[1] - 3, 6.2 );
				$pdf->SetXY( $xc + 1.5, $y );
				$pdf->Cell( $col[1] - 3, $hLigne, $valeurs[ $k ], 0, 0, $col[2], false, '', 0, false, 'T', 'M' );
			}
			$xc += $col[1];
		}
		$pdf->SetLineStyle( array( 'width' => 0.15, 'color' => $c['filet'], 'dash' => 0 ) );
		$pdf->Line( $x, $y + $hLigne, $x + $W, $y + $hLigne );
		$y += $hLigne;
	}

	/* ---- Total ---- */
	$total = (int) $bordereau->total;
	$lettres = 'Arrêté le présent bordereau à la somme de ' . ueb_nombre_en_lettres( $total ) . ' (' . ueb_formater_montant( $total ) . ') francs CFA, pour ' . count( $etudiants ) . ' étudiant' . ( count( $etudiants ) > 1 ? 's' : '' ) . ' à ' . ueb_formater_montant( $unitaire ) . ' francs CFA chacun.';
	$pdf->SetFont( 'uebserifi', '', 9 );
	$hLettres = $pdf->getNumLines( $lettres, $W ) * 9 * 0.3528 * 1.25;
	if ( $y + 10 + 4 + $hLettres + 42 > UEB_BRD_BAS ) {
		$pdf->AddPage();
		$y = 12.0;
	}
	$pdf->SetFillColorArray( ueb_pdf_teinte( $c['etab'], 0.12 ) );
	$pdf->Rect( $x, $y, $W, 10, 'F' );
	$pdf->SetFont( 'uebsansb', '', 10 );
	$pdf->SetTextColorArray( $c['encre'] );
	$pdf->SetXY( $x + 4, $y );
	$pdf->Cell( $W - 8, 10, 'TOTAL REVERSÉ', 0, 0, 'L', false, '', 0, false, 'T', 'M' );
	$pdf->SetFont( 'uebsansb', '', 12 );
	$pdf->SetXY( $x + 4, $y );
	$pdf->Cell( $W - 8, 10, ueb_fcfa( $total ), 0, 0, 'R', false, '', 0, false, 'T', 'M' );
	$y += 10 + 4;
	$pdf->SetFont( 'uebserifi', '', 9 );
	$pdf->SetTextColorArray( $c['encre'] );
	$pdf->MultiCell( $W, 4, $lettres, 0, 'L', false, 1, $x, $y, true, 0, false, true, 0, 'T' );
	$y += $hLettres + 2;
	/* Pièces jointes : les reçus bancaires du virement, consultables sur la plateforme. */
	$nb_recus = ueb_ipes_nb_recus( $ipes->id, $bordereau->id );
	$pdf->SetFont( 'uebsans', '', 8 );
	ueb_pdf_texte( $pdf, $x, $y, 4, 'Pièces jointes : ' . ( $nb_recus ? $nb_recus . ' reçu' . ( $nb_recus > 1 ? 's' : '' ) . ' bancaire' . ( $nb_recus > 1 ? 's' : '' ) . ' du virement, consultable' . ( $nb_recus > 1 ? 's' : '' ) . ' sur la plateforme d’inscription.' : 'aucun reçu bancaire.' ), $c['gris'] );
	$y += 4 + 6;

	/* ---- Signatures ---- */
	$verification = 'verifie' === $bordereau->statut && $bordereau->date_verification
		? 'Vérifié le ' . mysql2date( 'd/m/Y', $bordereau->date_verification )
		: ( 'rejete' === $bordereau->statut ? 'Rejeté : ' . $bordereau->motif_rejet : 'En attente de vérification' );
	ueb_brd_signature( $pdf, $x, $y, $demi, 'Le responsable de l’IPES', 'Nom, signature et cachet', $c );
	ueb_brd_signature( $pdf, $x + $demi + 6, $y, $demi, 'Visa de l’UEb', $verification, $c );

	/* ---- Pied de page, sur chaque page ---- */
	$pages = $pdf->getNumPages();
	$genere = 'Généré le ' . wp_date( 'd/m/Y à H:i' );
	for ( $p = 1; $p <= $pages; $p++ ) {
		$pdf->setPage( $p );
		$pdf->SetLineStyle( array( 'width' => 0.2, 'color' => $c['filet'], 'dash' => 0 ) );
		$pdf->Line( $x, 283, $x + $W, 283 );
		$pdf->SetFont( 'uebsans', '', 7 );
		$pdf->SetTextColorArray( $c['gris'] );
		$pdf->SetXY( $x, 284 );
		$pdf->Cell( $W / 3, 4, $bordereau->numero, 0, 0, 'L', false, '', 0, false, 'T', 'M' );
		$pdf->Cell( $W / 3, 4, 'Page ' . $p . ' / ' . $pages, 0, 0, 'C', false, '', 0, false, 'T', 'M' );
		$pdf->Cell( $W / 3, 4, $genere, 0, 0, 'R', false, '', 0, false, 'T', 'M' );
	}
	$pdf->setPage( $pages );
	return $pdf;
}

/* ---------- Éléments ---------- */

/** En-tête bilingue des actes officiels, logos au centre. Renvoie le y sous le filet. */
function ueb_brd_entete( TCPDF $pdf, array $etab, array $c, $y ) {
	$x     = UEB_BRD_MARGE;
	$W     = UEB_BRD_LARGEUR;
	$logo  = 18.0;
	$emblW = $logo * 2 + 6.0;
	$colW  = ( $W - $emblW - 8 ) / 2;
	$xEmb  = $x + $colW + 4;
	$xEn   = $xEmb + $emblW + 4;
	$fr    = ueb_pdf_lignes_entete( 'fr', $etab, $c );
	$en    = ueb_pdf_lignes_entete( 'en', $etab, $c );
	$hFr   = ueb_pdf_colonne_entete( $pdf, $fr, $x, 0, $colW, $c, false );
	$hEn   = ueb_pdf_colonne_entete( $pdf, $en, $xEn, 0, $colW, $c, false );
	$h     = max( $hFr, $hEn, $logo );
	ueb_pdf_colonne_entete( $pdf, $fr, $x, $y + ( $h - $hFr ) / 2, $colW, $c, true );
	ueb_pdf_colonne_entete( $pdf, $en, $xEn, $y + ( $h - $hEn ) / 2, $colW, $c, true );
	$yLogo = $y + ( $h - $logo ) / 2;
	$pdf->Image( ueb_logo_chemin( 'UEB' ), $xEmb, $yLogo, $logo, $logo, 'PNG', '', '', true, 300, '', false, false, 0, 'CM' );
	$pdf->Image( ueb_logo_chemin( $etab['sigle'] ), $xEmb + $logo + 6, $yLogo, $logo, $logo, 'PNG', '', '', true, 300, '', false, false, 0, 'CM' );
	$pdf->SetLineStyle( array( 'width' => 0.25, 'color' => $c['filet'], 'dash' => 0 ) );
	$pdf->Line( $xEmb + $logo + 3, $yLogo + 2, $xEmb + $logo + 3, $yLogo + $logo - 2 );
	$y += $h + 3;
	$pdf->SetLineStyle( array( 'width' => 0.5, 'color' => $c['etab'], 'dash' => 0 ) );
	$pdf->Line( $x, $y, $x + $W, $y );
	return $y + 4;
}

/**
 * Encadré à titre (IPES ou tutelle). Mesure sa hauteur sans rien dessiner si
 * $dessiner est faux ; sinon le dessine sur $hauteur (pour aligner les deux).
 */
function ueb_brd_encadre( TCPDF $pdf, $x, $y, $w, $etiquette, $nom, array $lignes, array $c, $dessiner, $hauteur = 0 ) {
	$pad = 3.5;
	$pdf->SetFont( 'uebserifb', '', 9.5 );
	$hNom = $pdf->getNumLines( $nom, $w - 2 * $pad ) * 9.5 * 0.3528 * 1.2;
	$h    = $pad + 4 + $hNom + 1 + count( $lignes ) * 3.9 + $pad;
	if ( ! $dessiner ) {
		return $h;
	}
	$pdf->SetLineStyle( array( 'width' => 0.3, 'color' => $c['filet'], 'dash' => 0 ) );
	$pdf->SetFillColorArray( $c['fond'] );
	$pdf->RoundedRect( $x, $y, $w, max( $h, $hauteur ), 2, '1111', 'DF' );
	$yc = $y + $pad;
	$pdf->SetFont( 'uebsansb', '', 7 );
	$pdf->setFontSpacing( 0.25 );
	ueb_pdf_texte( $pdf, $x + $pad, $yc, 3.5, $etiquette, $c['etab'] );
	$pdf->setFontSpacing( 0 );
	$yc += 4;
	$pdf->SetFont( 'uebserifb', '', 9.5 );
	$pdf->SetTextColorArray( $c['encre'] );
	$pdf->MultiCell( $w - 2 * $pad, 4, $nom, 0, 'L', false, 1, $x + $pad, $yc, true, 0, false, true, 0, 'T' );
	$yc += $hNom + 1;
	$pdf->SetFont( 'uebsans', '', 7.8 );
	foreach ( $lignes as $ligne ) {
		ueb_pdf_ajuster( $pdf, $ligne, 'uebsans', '', 7.8, $w - 2 * $pad, 6 );
		ueb_pdf_texte( $pdf, $x + $pad, $yc, 3.9, $ligne, $c['gris'] );
		$yc += 3.9;
	}
	return $h;
}

/** Ligne d'en-tête du tableau. Renvoie le y sous la ligne. */
function ueb_brd_ligne_entete( TCPDF $pdf, array $colonnes, $x, $y, array $c ) {
	$h = 7.0;
	$pdf->SetFillColorArray( $c['etab'] );
	$pdf->Rect( $x, $y, UEB_BRD_LARGEUR, $h, 'F' );
	$pdf->SetFont( 'uebsansb', '', 7.8 );
	$pdf->SetTextColor( 255, 255, 255 );
	$xc = $x;
	foreach ( $colonnes as $col ) {
		$pdf->SetXY( $xc + 1.5, $y );
		$pdf->Cell( $col[1] - 3, $h, $col[0], 0, 0, $col[2], false, '', 0, false, 'T', 'M' );
		$xc += $col[1];
	}
	return $y + $h;
}

/** Cadre de signature : titre, mention, espace pour signer. */
function ueb_brd_signature( TCPDF $pdf, $x, $y, $w, $titre, $mention, array $c ) {
	$pdf->SetLineStyle( array( 'width' => 0.3, 'color' => $c['filet'], 'dash' => 0 ) );
	$pdf->RoundedRect( $x, $y, $w, 34, 2, '1111', 'D' );
	$pdf->SetFont( 'uebsansb', '', 8.5 );
	ueb_pdf_texte( $pdf, $x + 3.5, $y + 3, 4, $titre, $c['encre'] );
	ueb_pdf_ajuster( $pdf, $mention, 'uebsans', '', 7.5, $w - 7, 6 );
	ueb_pdf_texte( $pdf, $x + 3.5, $y + 7.5, 3.8, $mention, $c['gris'] );
}

/* PDF d'un bordereau depuis l'administration : fiche de l'IPES,
   ?vue=ipes&ipes={id}&bordereau={id}&pdf=1. Réservé à l'administrateur. */
add_action( 'template_redirect', function () {
	if ( ! isset( $_GET['pdf'], $_GET['bordereau'], $_GET['ipes'] ) || 'admin' !== ueb_espace_courant() || ! ueb_est_admin_ueb() ) {
		return;
	}
	$ipes      = ueb_ipes( (int) $_GET['ipes'] );
	$bordereau = $ipes ? ueb_ipes_bordereau( $ipes->id, (int) $_GET['bordereau'] ) : null;
	if ( ueb_ipes_bordereau_a_pdf( $bordereau ) ) {
		ueb_ipes_envoyer_pdf_bordereau( $ipes, $bordereau );
	}
}, 20 );