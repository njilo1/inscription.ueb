<?php
/**
 * Fiches CMS adaptées des pages 2 et 3 du thème preinscriptions-ueb.
 * Copie autonome des modèles : aucune dépendance au thème de préinscription.
 */
defined( 'ABSPATH' ) || exit;

function ueb_cms_date_fr( $iso ) {
    $iso = trim( (string) $iso );

    if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m ) ) {
        return $iso;
    }

    if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
        return $iso;
    }

    return $m[3] . '/' . $m[2] . '/' . $m[1];
}

function ueb_cms_couleurs() {
    return array(
        'vert'       => array( 22, 82, 49 ),   // barres de section, bandeaux
        'vert_titre' => array( 22, 106, 58 ),  // grands titres
        'orange'     => array( 232, 126, 24 ), // numéro de dossier
        'or'         => array( 240, 190, 60 ), // code sur bandeau vert (page 2)
        'noir'       => array( 33, 37, 41 ),
        'gris'       => array( 107, 114, 128 ),
        'ligne'      => array( 225, 229, 226 ),
        'fond'       => array( 243, 247, 244 ), // lignes alternées
        'vert_pale'  => array( 238, 245, 239 ), // fond bloc notes page 2
    );
}

function ueb_cms_txt( $pdf, $x, $y, $txt, $size, $style = '', $color = null, $align = 'L', $w = 0 ) {
    $c = $color ?: ueb_cms_couleurs()['noir'];
    $police = array( '' => 'uebsans', 'B' => 'uebsansb', 'I' => 'uebserifi', 'BI' => 'uebserifbi' )[ $style ] ?? 'uebsans';
    $pdf->SetFont( $police, '', $size );
    $pdf->SetTextColor( $c[0], $c[1], $c[2] );
    $pdf->SetXY( $x, $y );
    if ( $w > 0 ) {
        $pdf->Cell( $w, 5, $txt, 0, 0, $align );
    } else {
        $pdf->Cell( $pdf->GetStringWidth( $txt ) + 1, 5, $txt, 0, 0, 'L' );
    }
}

function ueb_cms_ligne( $pdf, $x1, $y1, $x2, $y2, $color, $width = 0.2, $dash = 0 ) {
    $pdf->SetLineStyle( array(
        'width' => $width,
        'dash'  => $dash,
        'color' => $color,
    ) );
    $pdf->Line( $x1, $y1, $x2, $y2 );
}

function ueb_cms_entete_bilingue( $pdf, $y ) {
    $c    = ueb_cms_couleurs();
    $logo = ueb_logo_chemin( 'UEB' );

    // Colonne française (gauche)
    ueb_cms_txt( $pdf, 8,  $y,        'RÉPUBLIQUE DU CAMEROUN', 9, 'B', $c['noir'], 'C', 66 );
    ueb_cms_txt( $pdf, 8,  $y + 5,    'Paix – Travail – Patrie', 8, 'I', $c['noir'], 'C', 66 );
    ueb_cms_txt( $pdf, 8,  $y + 10,   'MINISTÈRE DE L\'ENSEIGNEMENT SUPÉRIEUR', 7, '', $c['noir'], 'C', 66 );
    ueb_cms_txt( $pdf, 8,  $y + 15,   'UNIVERSITÉ D\'ÉBOLOWA', 8.5, 'B', $c['vert_titre'], 'C', 66 );

    // Logo central
    if ( file_exists( $logo ) ) {
        $pdf->Image( $logo, 94, $y - 1, 22, 0, 'PNG' );
    }

    // Colonne anglaise (droite)
    ueb_cms_txt( $pdf, 136, $y,      'REPUBLIC OF CAMEROON', 9, 'B', $c['noir'], 'C', 66 );
    ueb_cms_txt( $pdf, 136, $y + 5,  'Peace – Work – Fatherland', 8, 'I', $c['noir'], 'C', 66 );
    ueb_cms_txt( $pdf, 136, $y + 10, 'MINISTRY OF HIGHER EDUCATION', 7, '', $c['noir'], 'C', 66 );
    ueb_cms_txt( $pdf, 136, $y + 15, 'THE UNIVERSITY OF EBOLOWA', 8.5, 'B', $c['vert_titre'], 'C', 66 );

    return $y + 22;
}

function ueb_cms_boite_medicale( $pdf, $titre, $icone_titre, $largeur_pastille, $lignes, $y ) {
    $c     = ueb_cms_couleurs();
    $h_row = 13.4;
    $h_box = count( $lignes ) * $h_row + 7.5;

    // Cadre
    $pdf->RoundedRect( 10, $y + 4.5, 190, $h_box, 2, '1111', 'D',
        array( 'width' => 0.3, 'dash' => 0, 'color' => $c['gris'] ) );

    // Pastille de titre
    $pdf->RoundedRect( 10, $y, $largeur_pastille, 9, 2, '1111', 'F', array(), $c['vert'] );
    ueb_cms_icone( $pdf, $icone_titre, 14.5, $y + 2, 5, array( 255, 255, 255 ) );
    ueb_cms_txt( $pdf, 23, $y + 2, $titre, 9.5, 'B', array( 255, 255, 255 ) );

    // Lignes
    $ry = $y + 9 + 1.5;
    foreach ( $lignes as $idx => $ligne ) {
        list( $icone, $label, $valeur ) = $ligne;
        $ty = $ry + ( $h_row - 5 ) / 2;

        ueb_cms_icone( $pdf, $icone, 18, $ty - 0.2, 5 );
        ueb_cms_txt( $pdf, 28, $ty, $label, 10, 'B', $c['noir'] );
        ueb_cms_txt( $pdf, 79, $ty, ':', 10, 'B', $c['noir'] );
        $valeur = $valeur !== '' ? $valeur : 'À compléter';
        ueb_pdf_ajuster( $pdf, $valeur, 'uebserifbi', '', 10.5, 108, 7.0 );
        $pdf->SetTextColorArray( $c['noir'] );
        $pdf->MultiCell( 108, 5, $valeur, 0, 'L', false, 1, 86, $ry + 0.5, true, 0, false, true, 12, 'M', true );

        if ( $idx < count( $lignes ) - 1 ) {
            ueb_cms_ligne( $pdf, 14, $ry + $h_row, 196, $ry + $h_row, $c['ligne'], 0.2, '0.6,1.2' );
        }
        $ry += $h_row;
    }

    return $y + 4.5 + $h_box;
}

function ueb_cms_page_medicale( $pdf, $d ) {
    $c = ueb_cms_couleurs();
    $pdf->AddPage();
    /* ── En-tête bilingue identique page 1 ── */
    ueb_cms_entete_bilingue( $pdf, 6 );
    /* ── Titre "FICHE CMS" — même style que page 1 ── */
    ueb_cms_txt( $pdf, 37, 30, 'FICHE CMS', 15.5, 'B', $c['vert_titre'], 'C', 130 );
    /* ── N° Dossier — même style que page 1 (noir + orange) */
    $pdf->SetFont( 'uebsansb', '', 10.5 );
    $w1 = $pdf->GetStringWidth( $d['libelle_identifiant'] . ' : ' );
    $pdf->SetFont( 'uebsansb', '', 11 );
    $w2 = $pdf->GetStringWidth( $d['numero_dossier'] );
    $x0 = 37 + ( 130 - $w1 - $w2 ) / 2;
    ueb_cms_txt( $pdf, $x0, 41, $d['libelle_identifiant'] . ' : ', 10.5, 'B', $c['noir'] );
    ueb_cms_txt( $pdf, $x0 + $w1, 40.9, $d['numero_dossier'], 11, 'B', $c['orange'] );
    ueb_cms_txt( $pdf, 0, 55,
        '(Imprimez ces deux fiches et apportez-les au Centre médico-social lors de la visite médicale)',
        9, 'I', $c['gris'], 'C', 210 );
    /* --- Informations personnelles --- */
    $date_fr = ueb_cms_date_fr( $d['date_naissance'] );
    $sexe_label = $d['sexe'] === 'M' ? 'MASCULIN' : ( $d['sexe'] === 'F' ? 'FÉMININ' : '' );
    $y = 62;
    $y = ueb_cms_boite_medicale( $pdf, 'INFORMATIONS PERSONNELLES', 'personne', 76, array(
        array( 'personne', 'Nom(s)', mb_strtoupper( $d['nom'] ) ),
        array( 'personne', 'Prénom(s)', mb_strtoupper( $d['prenom'] ) ),
        array( 'calendrier', 'Date de Naissance', $date_fr ),
        array( 'mail', 'Email', $d['email'] ),
        array( 'telephone', 'Téléphone', $d['telephone'] ),
        array( 'genre', 'Sexe', $sexe_label ),
        array( 'lieu', 'Adresse', mb_strtoupper( $d['adresse'] ) ),
    ), $y );
    /* --- Personne à contacter --- */
    $y += 7.5;
    $y = ueb_cms_boite_medicale( $pdf, "PERSONNE À CONTACTER EN CAS D'URGENCE", 'tel_urgence', 104, array(
        array( 'personne', 'Nom et Prénom', $d['nom_urgence'] ),
        array( 'telephone', 'Téléphone (urgence)', $d['numero_urgence'] ),
        array( 'lieu',       'Adresse (urgence)',    $d['adresse_urgence'] ),
    ), $y );
    /* --- Notes importantes --- */
    $y += 7.5;
    $pdf->RoundedRect( 10, $y, 190, 27, 2, '1111', 'DF',
        array( 'width' => 0.3, 'dash' => 0, 'color' => array( 200, 215, 202 ) ), $c['vert_pale'] );
    ueb_cms_icone( $pdf, 'info', 16, $y + 4, 5.5 );
    ueb_cms_txt( $pdf, 25, $y + 4, 'NOTES IMPORTANTES', 10.5, 'B', $c['noir'] );
    ueb_cms_icone( $pdf, 'coche', 17, $y + 12.5, 4.5 );
    ueb_cms_txt( $pdf, 25, $y + 12.2, 'Imprimez ces deux fiches.', 9.5, '', $c['noir'] );
    ueb_cms_icone( $pdf, 'coche', 17, $y + 19.5, 4.5 );
    ueb_cms_txt( $pdf, 25, $y + 19.2, 'Apportez-les au Centre médico-social lors de votre visite médicale.', 9.5, '', $c['noir'] );
    /* --- Pied de page --- */
    $yf = 272;
    ueb_cms_ligne( $pdf, 30, $yf, 99, $yf, $c['gris'], 0.25 );
    ueb_cms_ligne( $pdf, 111, $yf, 180, $yf, $c['gris'], 0.25 );
    ueb_cms_icone( $pdf, 'coeur', 101.5, $yf - 3.5, 7 );
    ueb_cms_txt( $pdf, 0, $yf + 6, 'Merci de votre collaboration.', 10.5, 'B', $c['noir'], 'C', 210 );
    ueb_cms_txt( $pdf, 0, $yf + 12, 'Thank you for your cooperation.', 9.5, 'I', $c['gris'], 'C', 210 );

    /* --- Crédit groupe en bas de page --- */
    ueb_cms_txt( $pdf, 0, 292, '@NexusCore: Nous developpons vos solutions informatiques (676295488/659490221/693899150/673414381).', 6.5, 'I', $c['gris'], 'C', 210 );
}

function ueb_cms_page_examen( $pdf ) {
    $pdf->AddPage();

    $img = UEB_INSC_DIR . '/assets/pdf/fiche-visite-medicale-p3.png';
    if ( file_exists( $img ) ) {
        // Image 300 dpi au ratio de la page source (205×297 mm), centrée.
        $pdf->Image( $img, 2.4, 0, 205.2, 297, 'PNG' );
        ueb_cms_txt( $pdf, 0, 292, '@NexusCore: Nous developpons vos solutions informatiques (676295488/659490221/693899150/673414381).', 6.5, 'I', ueb_cms_couleurs()['gris'], 'C', 210 );
    } else {
        $c = ueb_cms_couleurs();
        ueb_cms_txt( $pdf, 0, 140,
            "Fiche d'examen médical indisponible (assets/pdf/fiche-visite-medicale-p3.png manquant).",
            10, 'B', $c['gris'], 'C', 210 );
    }
}

function ueb_cms_icone( $pdf, $type, $x, $y, $s, $color = null ) {
    $c  = $color ?: ueb_cms_couleurs()['vert'];
    $lw = max( 0.35, $s * 0.09 );
    $pdf->SetLineStyle( array( 'width' => $lw, 'dash' => 0, 'color' => $c ) );
    $cx = $x + $s / 2;

    switch ( $type ) {
        case 'personne': // tête + épaules
            $pdf->Circle( $cx, $y + $s * 0.3, $s * 0.19 );
            $pdf->Ellipse( $cx, $y + $s * 1.02, $s * 0.32, $s * 0.42, 0, 55, 125 );
            break;

        case 'calendrier':
            $pdf->RoundedRect( $x + $s * 0.08, $y + $s * 0.16, $s * 0.84, $s * 0.74, $s * 0.08, '1111', 'D', array(), array() );
            $pdf->Line( $x + $s * 0.08, $y + $s * 0.4, $x + $s * 0.92, $y + $s * 0.4 );
            $pdf->Line( $x + $s * 0.3, $y + $s * 0.05, $x + $s * 0.3, $y + $s * 0.26 );
            $pdf->Line( $x + $s * 0.7, $y + $s * 0.05, $x + $s * 0.7, $y + $s * 0.26 );
            break;

        case 'mail':
            $pdf->RoundedRect( $x + $s * 0.05, $y + $s * 0.2, $s * 0.9, $s * 0.6, $s * 0.05, '1111', 'D', array(), array() );
            $pdf->Line( $x + $s * 0.09, $y + $s * 0.25, $cx, $y + $s * 0.55 );
            $pdf->Line( $cx, $y + $s * 0.55, $x + $s * 0.91, $y + $s * 0.25 );
            break;

        case 'telephone': // combiné (arc épais + écouteurs)
            $pdf->SetLineStyle( array( 'width' => $lw * 1.5, 'dash' => 0, 'color' => $c ) );
            $pdf->Ellipse( $cx, $y + $s * 0.62, $s * 0.34, $s * 0.34, 0, 30, 150 );
            $pdf->Circle( $cx - $s * 0.3, $y + $s * 0.48, $s * 0.09, 0, 360, 'F', array(), $c );
            $pdf->Circle( $cx + $s * 0.3, $y + $s * 0.48, $s * 0.09, 0, 360, 'F', array(), $c );
            break;

        case 'tel_urgence': // combiné + ondes
            ueb_cms_icone( $pdf, 'telephone', $x, $y + $s * 0.12, $s * 0.85, $c );
            $pdf->SetLineStyle( array( 'width' => $lw * 0.8, 'dash' => 0, 'color' => $c ) );
            $pdf->Ellipse( $x + $s * 0.78, $y + $s * 0.3, $s * 0.14, $s * 0.14, 0, 300, 60 );
            $pdf->Ellipse( $x + $s * 0.78, $y + $s * 0.3, $s * 0.26, $s * 0.26, 0, 300, 60 );
            break;

        case 'genre': // symbole masculin
            $pdf->Circle( $cx - $s * 0.1, $y + $s * 0.6, $s * 0.24 );
            $pdf->Line( $cx + $s * 0.07, $y + $s * 0.43, $x + $s * 0.88, $y + $s * 0.12 );
            $pdf->Line( $x + $s * 0.88, $y + $s * 0.12, $x + $s * 0.62, $y + $s * 0.1 );
            $pdf->Line( $x + $s * 0.88, $y + $s * 0.12, $x + $s * 0.9, $y + $s * 0.38 );
            break;

        case 'lieu': // épingle de localisation
            $pdf->Circle( $cx, $y + $s * 0.34, $s * 0.24 );
            $pdf->Circle( $cx, $y + $s * 0.34, $s * 0.07, 0, 360, 'F', array(), $c );
            $pdf->Line( $cx - $s * 0.17, $y + $s * 0.51, $cx, $y + $s * 0.92 );
            $pdf->Line( $cx, $y + $s * 0.92, $cx + $s * 0.17, $y + $s * 0.51 );
            break;

        case 'info': // cercle plein + i
            $pdf->Circle( $cx, $y + $s / 2, $s / 2, 0, 360, 'F', array(), $c );
            ueb_cms_txt( $pdf, $x, $y + $s * 0.14, 'i', $s * 2.2, 'BI', array( 255, 255, 255 ), 'C', $s );
            break;

        case 'coche': // cercle plein + check
            $pdf->Circle( $cx, $y + $s / 2, $s / 2, 0, 360, 'F', array(), $c );
            ueb_cms_txt( $pdf, $x, $y + $s * 0.08, '✓', $s * 1.9, 'B', array( 255, 255, 255 ), 'C', $s );
            break;

        case 'coeur': // cœur médical (contour + croix)
            $pdf->Circle( $cx - $s * 0.18, $y + $s * 0.3, $s * 0.22 );
            $pdf->Circle( $cx + $s * 0.18, $y + $s * 0.3, $s * 0.22 );
            $pdf->Line( $cx - $s * 0.38, $y + $s * 0.42, $cx, $y + $s * 0.92 );
            $pdf->Line( $cx, $y + $s * 0.92, $cx + $s * 0.38, $y + $s * 0.42 );
            $pdf->Line( $cx, $y + $s * 0.3, $cx, $y + $s * 0.62 );
            $pdf->Line( $cx - $s * 0.16, $y + $s * 0.46, $cx + $s * 0.16, $y + $s * 0.46 );
            break;

        case 'banque': // fronton + colonnes
            $pdf->Line( $x + $s * 0.05, $y + $s * 0.32, $cx, $y + $s * 0.02 );
            $pdf->Line( $cx, $y + $s * 0.02, $x + $s * 0.95, $y + $s * 0.32 );
            $pdf->Line( $x + $s * 0.05, $y + $s * 0.32, $x + $s * 0.95, $y + $s * 0.32 );
            foreach ( array( 0.2, 0.5, 0.8 ) as $fx ) {
                $pdf->Line( $x + $s * $fx, $y + $s * 0.4, $x + $s * $fx, $y + $s * 0.78 );
            }
            $pdf->Line( $x, $y + $s * 0.88, $x + $s, $y + $s * 0.88 );
            break;

        case 'recu': // reçu de transaction
            $pdf->RoundedRect( $x + $s * 0.14, $y + $s * 0.04, $s * 0.72, $s * 0.84, $s * 0.06, '1111', 'D', array(), array() );
            $pdf->Line( $x + $s * 0.28, $y + $s * 0.24, $x + $s * 0.72, $y + $s * 0.24 );
            $pdf->Line( $x + $s * 0.28, $y + $s * 0.4, $x + $s * 0.72, $y + $s * 0.4 );
            $pdf->Circle( $cx, $y + $s * 0.64, $s * 0.13 );
            break;
    }
}
