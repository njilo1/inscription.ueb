<?php
/**
 * Exports du suivi des paiements (administration, vue Paiements).
 *
 *   - PDF et Word : rapport au format institutionnel, en A4 paysage.
 *     Il porte l'en-tête bilingue des actes de l'université (français à
 *     gauche, anglais à droite, sceau au centre), puis le titre et le bilan,
 *     les tableaux, les notes de lecture et un pied de page paginé.
 *   - Excel : les mêmes tableaux en données brutes, sans en-tête, avec une
 *     feuille par tableau. Montants et taux y sont de vraies valeurs
 *     numériques, filtrables, et la ligne de titres des colonnes reste figée.
 *
 * Les trois formats lisent un seul jeu de données (ueb_adm_rapport_donnees) :
 * ce sont les chiffres de la page, pour le périmètre affiché.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

const UEB_EXPORT_FORMATS = array( 'pdf', 'docx', 'xlsx' );

/** Lien de téléchargement d'un format, pour le périmètre affiché (jeton anti-rejeu). */
function ueb_adm_export_url( $format, $focus ) {
	$args = array( 'vue' => 'paiements', 'export' => $format );
	if ( $focus ) {
		$args['etab'] = $focus;
	}
	return wp_nonce_url( add_query_arg( $args, ueb_url_administration() ), 'ueb_export_paiements', 'jeton' );
}

/** Menu « Exporter » de l'en-tête de la vue Paiements (HTML échappé). */
function ueb_adm_exports_menu( $focus ) {
	$choix = array(
		'pdf'  => array( 'PDF', 'Rapport PDF', 'Document officiel, prêt à imprimer' ),
		'docx' => array( 'DOCX', 'Rapport Word', 'Document officiel modifiable' ),
		'xlsx' => array( 'XLSX', 'Tableur Excel', 'Données brutes, sans en-tête' ),
	);
	ob_start();
	?>
	<div class="adm-export" data-export>
		<button type="button" class="adm-bouton adm-export__bouton" aria-expanded="false" aria-controls="adm-export-menu"><?php echo ueb_icone( 'telecharger', 17 ); ?>Exporter<?php echo ueb_icone( 'chevron', 15 ); ?></button>
		<div class="adm-export__menu" id="adm-export-menu" hidden>
			<p class="adm-export__titre">Suivi des paiements<?php echo $focus ? ' de ' . esc_html( $focus ) : ''; ?></p>
			<?php foreach ( $choix as $format => $c ) : ?>
				<a class="adm-export__choix" href="<?php echo esc_url( ueb_adm_export_url( $format, $focus ) ); ?>" data-format="<?php echo esc_attr( $format ); ?>">
					<span class="adm-export__format adm-export__format--<?php echo esc_attr( $format ); ?>" aria-hidden="true"><?php echo esc_html( $c[0] ); ?></span>
					<span class="adm-export__texte"><b><?php echo esc_html( $c[1] ); ?></b><small><?php echo esc_html( $c[2] ); ?></small></span>
				</a>
			<?php endforeach; ?>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

/* Téléchargement : ?vue=paiements&export=pdf|docx|xlsx[&etab=FS]&jeton=… */
add_action( 'template_redirect', function () {
	if ( ! isset( $_GET['export'] ) || ! is_page_template( 'page-administration.php' ) ) { // phpcs:ignore
		return;
	}
	if ( ! ueb_est_admin_ueb() ) {
		wp_die( 'Cet export est réservé à l’administration.', 'Accès refusé', array( 'response' => 403 ) );
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['jeton'] ?? '' ) ), 'ueb_export_paiements' ) ) {
		wp_die( 'Ce lien d’export a expiré. Recharge la page Paiements puis relance l’export.', 'Lien expiré', array( 'response' => 403 ) );
	}
	$format = sanitize_key( $_GET['export'] );
	if ( ! in_array( $format, UEB_EXPORT_FORMATS, true ) ) {
		wp_die( 'Format d’export inconnu.', 'Export', array( 'response' => 400 ) );
	}
	$focus = strtoupper( sanitize_text_field( wp_unslash( $_GET['etab'] ?? '' ) ) );
	$focus = ueb_etablissement( $focus ) ? $focus : '';
	$annee = ueb_annee_academique();
	$d     = ueb_adm_rapport_donnees( ueb_suivi_paiements( $annee['code'], $focus, 366 ), $focus, $annee );

	nocache_headers();
	if ( 'pdf' === $format ) {
		ueb_adm_export_pdf( $d );
	} elseif ( 'docx' === $format ) {
		ueb_adm_export_docx( $d );
	} else {
		ueb_adm_export_xlsx( $d );
	}
	exit;
}, 20 );

/* ==========================================================================
   Données communes
   ========================================================================== */

/** Date française lisible : « 27 septembre 2026 ». */
function ueb_adm_date_longue( DateTimeInterface $date ) {
	$mois = array( 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre' );
	return (int) $date->format( 'j' ) . ( '1' === $date->format( 'j' ) ? 'er' : '' ) . ' ' . $mois[ (int) $date->format( 'n' ) - 1 ] . ' ' . $date->format( 'Y' );
}

/** « 1 soldé », « 2 soldés » : le nombre et son nom accordé. */
function ueb_adm_accord( $nombre, $singulier, $pluriel ) {
	return (int) $nombre . ' ' . ( (int) $nombre > 1 ? $pluriel : $singulier );
}

/** Notes de lecture des rapports PDF et Word. */
function ueb_adm_paiements_notes() {
	return array(
		array( 'Droits attendus', ueb_fcfa( UEB_DROITS_CLASSIQUES ) . ' par étudiant en formation classique ; somme des quitus préparés en formation professionnelle. La formation retenue est celle du dernier quitus.' ),
		array( 'Frais médicaux', 'Montants des quitus médicaux effectivement générés, versés au compte des services centraux. Ils ne représentent pas tous les frais potentiellement dus par les étudiants.' ),
		array( 'Encaissé et reste', 'Seuls les paiements vérifiés sont encaissés. Les droits sont plafonnés à l’attendu par étudiant et établissement. Le reste inclut les montants en vérification ; un reçu déposé ne vaut pas validation.' ),
		array( 'Historique mensuel', 'Reconstitué avec les dates de validation des dossiers conservés et les formations actuelles. Les anciennes décisions annulées et les pièces supprimées ne sont pas conservées. Le mois en cours est incomplet.' ),
		array( 'Effectifs et filtres', 'Les droits comptent les étudiants par établissement ; les frais médicaux comptent les quitus. La recherche et les filtres du registre ne modifient pas le bilan du périmètre affiché en haut.' ),
	);
}

/**
 * Jeu de données du rapport. Chaque tableau décrit ses colonnes
 * (libellé, genre : texte | nombre | fcfa | pourcent, poids de largeur) ;
 * les valeurs restent brutes, chaque format les écrit à sa façon.
 */
function ueb_adm_rapport_donnees( array $suivi, $focus, array $annee ) {
	$g        = $suivi['global'];
	$m        = $suivi['medicaux'];
	$st       = $suivi['quitus_statuts'];
	$e        = $focus ? ueb_etablissement( $focus ) : null;
	$date     = new DateTimeImmutable( 'now', wp_timezone() );
	$libelles = array( 'solde' => 'Soldé', 'partiel' => 'Partiel', 'attente' => 'À encaisser', 'vide' => 'Aucun montant' );
	$taux     = static fn( array $a ) => $a['attendu'] > 0 ? ueb_suivi_taux( $a ) : null;

	/* Suivi par établissement (ou par filière) : droits et frais médicaux séparés. */
	$lignes = array();
	$totaux = array();
	foreach ( ueb_adm_paiements_lignes( $suivi, $focus ) as $l ) {
		$a        = $l['a'];
		$reste    = max( 0, $a['attendu'] - $a['encaisse'] );
		$type     = 'droits' === $l['type'] ? 'Droits universitaires' : 'Frais médicaux';
		$lignes[] = array( $focus && 'droits' === $l['type'] ? $l['nom'] : $l['detail'] . ' (' . $l['sigle'] . ')', $type, (int) $a['etudiants'], (int) $a['attendu'], (int) $a['encaisse'], (int) $a['verification'], $reste, $taux( $a ), $libelles[ ueb_adm_paiement_situation( $a ) ] );
		$t        = &$totaux[ $l['type'] ];
		$t        = $t ?? array( 'etudiants' => 0, 'attendu' => 0, 'encaisse' => 0, 'verification' => 0 );
		foreach ( array_keys( $t ) as $cle ) {
			$t[ $cle ] += (int) $a[ $cle ];
		}
		unset( $t );
	}
	$pieds = array();
	foreach ( array( 'droits' => 'Total des droits universitaires', 'medicaux' => 'Total des frais médicaux' ) as $cle => $libelle ) {
		if ( isset( $totaux[ $cle ] ) ) {
			$t       = $totaux[ $cle ];
			$pieds[] = array( $libelle, '', $t['etudiants'], $t['attendu'], $t['encaisse'], $t['verification'], max( 0, $t['attendu'] - $t['encaisse'] ), $taux( $t ), '' );
		}
	}
	$colonnes_registre = array(
		array( $focus ? 'Filière ou établissement' : 'Établissement', 'texte', 30 ),
		array( 'Type', 'texte', 13 ),
		array( 'Effectif', 'nombre', 7 ),
		array( 'Attendu (FCFA)', 'fcfa', 10 ),
		array( 'Encaissé (FCFA)', 'fcfa', 10 ),
		array( 'À vérifier (FCFA)', 'fcfa', 10 ),
		array( 'Reste (FCFA)', 'fcfa', 10 ),
		array( 'Recouvrement', 'pourcent', 9 ),
		array( 'Situation', 'texte', 9 ),
	);

	/* Recouvrement des droits par niveau. */
	$niveaux = array();
	$ordre   = array_merge( array_keys( UEB_NIVEAUX_INSCRIPTION ), array( 'Non précisé' ) );
	foreach ( $ordre as $niv ) {
		if ( empty( $suivi['niveaux'][ $niv ] ) ) {
			continue;
		}
		$a         = $suivi['niveaux'][ $niv ];
		$niveaux[] = array( $niv, (int) $a['etudiants'], (int) $a['attendu'], (int) $a['encaisse'], (int) $a['verification'], max( 0, $a['attendu'] - $a['encaisse'] ), $taux( $a ) );
	}

	/* Écarts de rapprochement (trop-perçus). */
	$ecarts = array();
	foreach ( $suivi[ $focus ? 'filieres' : 'etabs' ] as $cle => $a ) {
		if ( $a['trop_percu'] > 0 ) {
			$ecarts[] = array( $focus ? $a['libelle'] : ueb_etablissement( $cle )['fr'] . ' (' . $cle . ')', (int) $a['trop_percu'] );
		}
	}
	usort( $ecarts, static fn( $x, $y ) => $y[1] <=> $x[1] );

	$a_verifier = $st['droits']['recu_envoye'] + $st['medicaux']['recu_envoye'];
	$suivis     = $g['soldes'] + $g['partiels'] + $g['aucun'];
	$bilan      = array(
		array( 'Droits universitaires attendus', (int) $g['attendu'], 'fcfa', sprintf( '%s suivis', ueb_suivi_etudiants( $g['etudiants'] ) ), 'FCFA' ),
		array( 'Droits universitaires encaissés', (int) $g['encaisse'], 'fcfa', $g['attendu'] ? ueb_pourcent( ueb_suivi_taux( $g ) ) . ' des droits attendus' : 'Aucun droit attendu', 'FCFA' ),
		array( 'Frais médicaux déclarés', (int) $m['attendu'], 'fcfa', ueb_adm_accord( $m['etudiants'], 'quitus médical', 'quitus médicaux' ), 'FCFA' ),
		array( 'Frais médicaux encaissés', (int) $m['encaisse'], 'fcfa', $m['attendu'] ? ueb_pourcent( ueb_suivi_taux( $m ) ) . ' des frais déclarés' : '', 'FCFA' ),
		array( 'Montants en vérification', (int) ( $g['verification'] + $m['verification'] ), 'fcfa', 'Reçus déposés, pas encore contrôlés', 'FCFA' ),
		array( 'Reste à encaisser', max( 0, $g['attendu'] + $m['attendu'] - $g['encaisse'] - $m['encaisse'] ), 'fcfa', 'Montants en vérification compris', 'FCFA' ),
		array( 'Trop-perçus à rapprocher', (int) $g['trop_percu'], 'fcfa', 'Exclus des montants encaissés et du taux', 'FCFA' ),
		array( 'Étudiants suivis', $suivis, 'nombre', ueb_adm_accord( $g['soldes'], 'soldé', 'soldés' ) . ', ' . ueb_adm_accord( $g['partiels'], 'paiement partiel', 'paiements partiels' ) . ', ' . $g['aucun'] . ' sans paiement vérifié', 'étudiants' ),
		array( 'Quitus à vérifier', $a_verifier, 'nombre', sprintf( '%d de droits universitaires, %d de frais médicaux', $st['droits']['recu_envoye'], $st['medicaux']['recu_envoye'] ), 'quitus' ),
		array( 'Quitus à corriger', $st['droits']['rejete'] + $st['medicaux']['rejete'], 'nombre', 'Reçus refusés, en attente d’un nouveau dépôt', 'quitus' ),
	);

	$tableaux = array(
		array(
			'titre'    => $focus ? 'Suivi détaillé de ' . $focus : 'Suivi par établissement',
			'feuille'  => $focus ? 'Filières' : 'Établissements',
			'colonnes' => $colonnes_registre,
			'lignes'   => $lignes,
			'pieds'    => $pieds,
			'note'     => 'Effectif : étudiants pour les droits universitaires, quitus pour les frais médicaux.',
		),
	);
	if ( $niveaux ) {
		$tableaux[] = array(
			'titre'    => 'Recouvrement des droits par niveau',
			'feuille'  => 'Niveaux',
			'colonnes' => array( array( 'Niveau', 'texte', 22 ), array( 'Étudiants', 'nombre', 11 ), array( 'Attendu (FCFA)', 'fcfa', 14 ), array( 'Encaissé (FCFA)', 'fcfa', 14 ), array( 'En vérification (FCFA)', 'fcfa', 14 ), array( 'Reste (FCFA)', 'fcfa', 14 ), array( 'Recouvrement', 'pourcent', 11 ) ),
			'lignes'   => $niveaux,
			'pieds'    => array( array( 'Ensemble', (int) $g['etudiants'], (int) $g['attendu'], (int) $g['encaisse'], (int) $g['verification'], max( 0, $g['attendu'] - $g['encaisse'] ), $taux( $g ) ) ),
			'note'     => 'Niveau saisi sur le dernier quitus de l’étudiant.',
		);
	}
	if ( $ecarts ) {
		$tableaux[] = array(
			'titre'    => 'Écarts de rapprochement',
			'feuille'  => 'Écarts',
			'colonnes' => array( array( $focus ? 'Filière' : 'Établissement', 'texte', 70 ), array( 'Trop-perçu (FCFA)', 'fcfa', 30 ) ),
			'lignes'   => $ecarts,
			'pieds'    => array( array( 'Total', (int) $g['trop_percu'] ) ),
			'note'     => 'Reçus vérifiés au-delà du montant attendu : chaque écart est à rapprocher du dossier de l’étudiant concerné.',
		);
	}

	return array(
		'titre'      => 'Rapport de suivi des paiements',
		'sous_titre' => 'Droits universitaires et frais médicaux, année académique ' . $annee['libelle'],
		'perimetre'  => $focus ? $e['fr'] . ' (' . $focus . ')' : 'toute l’université',
		'etab'       => $e,
		'couleur'    => $e ? $e['couleur'] : UEB_UNIVERSITE['couleur'],
		'edite'      => ueb_adm_date_longue( $date ) . ' à ' . $date->format( 'H' ) . ' h ' . $date->format( 'i' ),
		'fait_le'    => ueb_adm_date_longue( $date ),
		'annee'      => $annee,
		'fichier'    => 'suivi-paiements-' . $annee['code'] . '-' . ( $focus ? strtolower( $focus ) : 'universite' ),
		'bilan'      => $bilan,
		'tableaux'   => $tableaux,
		'notes'      => ueb_adm_paiements_notes(),
	);
}

/** Valeur d'une cellule telle qu'on la lit dans un document. */
function ueb_adm_rapport_cellule( $valeur, $genre ) {
	if ( null === $valeur || '' === $valeur ) {
		return 'texte' === $genre ? '' : '—';
	}
	if ( 'valeur' === $genre ) {
		return (string) $valeur;
	}
	if ( 'fcfa' === $genre || 'nombre' === $genre ) {
		return ueb_formater_montant( $valeur );
	}
	if ( 'pourcent' === $genre ) {
		return ueb_pourcent( $valeur );
	}
	return (string) $valeur;
}

/** En-tête institutionnel, lignes des colonnes française et anglaise. */
function ueb_adm_rapport_entete( array $d ) {
	$lignes = array(
		'fr' => array( array( 'RÉPUBLIQUE DU CAMEROUN', 'b' ), array( 'Paix – Travail – Patrie', 'i' ), array( 'MINISTÈRE DE L’ENSEIGNEMENT SUPÉRIEUR', '' ), array( mb_strtoupper( UEB_UNIVERSITE['fr'] ), 'u' ) ),
		'en' => array( array( 'REPUBLIC OF CAMEROON', 'b' ), array( 'Peace – Work – Fatherland', 'i' ), array( 'MINISTRY OF HIGHER EDUCATION', '' ), array( mb_strtoupper( UEB_UNIVERSITE['en'] ), 'u' ) ),
	);
	if ( $d['etab'] ) {
		$lignes['fr'][] = array( mb_strtoupper( $d['etab']['fr'] ), 'e' );
		$lignes['en'][] = array( mb_strtoupper( $d['etab']['en'] ), 'e' );
	}
	return $lignes;
}

/* ==========================================================================
   PDF (TCPDF)
   ========================================================================== */

function ueb_adm_export_pdf( array $d ) {
	require_once UEB_INSC_DIR . '/lib/tcpdf/tcpdf.php';
	$couleur = ueb_pdf_rvb( $d['couleur'] );
	$encre   = array( 33, 37, 41 );
	$gris    = array( 100, 108, 104 );
	$marge   = 14.0;

	$pdf = new TCPDF( 'L', 'mm', 'A4', true, 'UTF-8', false );
	$pdf->SetCreator( 'Plateforme d’inscription, ' . UEB_UNIVERSITE['fr'] );
	$pdf->SetAuthor( UEB_UNIVERSITE['fr'] );
	$pdf->SetTitle( $d['titre'] . ' ' . $d['annee']['libelle'] );
	$pdf->SetSubject( $d['sous_titre'] );
	$pdf->setPrintHeader( false );
	$pdf->setPrintFooter( false );
	$pdf->SetMargins( $marge, $marge, $marge );
	$pdf->SetAutoPageBreak( true, 18 );
	$pdf->setCellHeightRatio( 1.25 );
	$pdf->AddPage();
	$largeur = $pdf->getPageWidth() - 2 * $marge;

	/* En-tête bilingue : français à gauche, sceau au centre, anglais à droite. */
	$colonne = 92;
	$bas     = $marge + 26;
	foreach ( ueb_adm_rapport_entete( $d ) as $langue => $lignes ) {
		$x = 'fr' === $langue ? $marge : $marge + $largeur - $colonne;
		$y = $marge;
		foreach ( $lignes as $l ) {
			$police = array( 'b' => array( 'uebserifb', 9.2 ), 'i' => array( 'uebserifi', 8.2 ), '' => array( 'uebsans', 7.6 ), 'u' => array( 'uebserifb', 9.4 ), 'e' => array( 'uebserifb', 8.2 ) )[ $l[1] ];
			$pdf->SetFont( $police[0], '', $police[1] );
			$pdf->SetTextColorArray( in_array( $l[1], array( 'u', 'e' ), true ) ? $couleur : $encre );
			$pdf->MultiCell( $colonne, 4.4, $l[0], 0, 'C', false, 1, $x, $y, true );
			$y = $pdf->GetY() + ( 'i' === $l[1] ? 1.2 : 0.4 );
		}
		$bas = max( $bas, $y );
	}
	$logo = ueb_logo_chemin( 'UEB' );
	if ( file_exists( $logo ) ) {
		$pdf->Image( $logo, $marge + $largeur / 2 - 12, $marge - 1, 24, 0, 'PNG' );
	}
	$y = $bas + 2.5;
	$pdf->SetLineStyle( array( 'width' => 0.7, 'color' => $couleur, 'dash' => 0 ) );
	$pdf->Line( $marge, $y, $marge + $largeur, $y );
	$pdf->SetLineStyle( array( 'width' => 0.2, 'color' => $couleur, 'dash' => 0 ) );
	$pdf->Line( $marge, $y + 1.1, $marge + $largeur, $y + 1.1 );

	/* Titre, objet et mentions d'édition. */
	$pdf->SetY( $y + 6 );
	$pdf->SetFont( 'uebserifb', '', 16 );
	$pdf->SetTextColorArray( $couleur );
	$pdf->Cell( 0, 8, mb_strtoupper( $d['titre'] ), 0, 1, 'C' );
	$pdf->SetFont( 'uebserifi', '', 10.5 );
	$pdf->SetTextColorArray( $encre );
	$pdf->Cell( 0, 6, $d['sous_titre'], 0, 1, 'C' );
	$pdf->SetFont( 'uebsans', '', 8.6 );
	$pdf->SetTextColorArray( $gris );
	$pdf->Cell( 0, 6, 'Périmètre : ' . $d['perimetre'] . '      Édité le ' . $d['edite'], 0, 1, 'C' );
	$pdf->Ln( 3 );

	$hex    = sprintf( '#%02x%02x%02x', $couleur[0], $couleur[1], $couleur[2] );
	$teinte = sprintf( '#%02x%02x%02x', ...ueb_pdf_teinte( $couleur, 0.07 ) );
	$titre  = static function ( $numero, $texte ) use ( $pdf, $couleur ) {
		if ( $pdf->GetY() > $pdf->getPageHeight() - 45 ) {
			$pdf->AddPage();
		}
		$pdf->Ln( 2 );
		$pdf->SetFont( 'uebserifb', '', 11.5 );
		$pdf->SetTextColorArray( $couleur );
		$pdf->Cell( 0, 7, $numero . '. ' . $texte, 0, 1, 'L' );
		$pdf->SetLineStyle( array( 'width' => 0.25, 'color' => $couleur, 'dash' => 0 ) );
		$pdf->Line( $pdf->GetX(), $pdf->GetY(), $pdf->getPageWidth() - 14, $pdf->GetY() );
		$pdf->Ln( 2.5 );
	};
	$tableau = static function ( array $colonnes, array $lignes, array $pieds ) use ( $pdf, $hex, $teinte ) {
		$poids = array_sum( array_column( $colonnes, 2 ) );
		$html  = '<table cellpadding="3.2" cellspacing="0" border="0" style="font-family:uebsans;font-size:8.4pt;color:#212529;"><thead><tr style="background-color:' . $hex . ';color:#ffffff;">';
		foreach ( $colonnes as $c ) {
			$html .= '<th width="' . round( 100 * $c[2] / $poids, 3 ) . '%" align="' . ( in_array( $c[1], array( 'texte', 'long' ), true ) ? 'left' : 'right' ) . '" style="font-family:uebsansb;">' . esc_html( $c[0] ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';
		foreach ( $lignes as $i => $l ) {
			$html .= '<tr style="background-color:' . ( $i % 2 ? $teinte : '#ffffff' ) . ';" nobr="true">';
			foreach ( $colonnes as $k => $c ) {
				$html .= '<td width="' . round( 100 * $c[2] / $poids, 3 ) . '%" align="' . ( in_array( $c[1], array( 'texte', 'long' ), true ) ? 'left' : 'right' ) . '">' . esc_html( ueb_adm_rapport_cellule( $l[ $k ], $c[1] ) ) . '</td>';
			}
			$html .= '</tr>';
		}
		foreach ( $pieds as $l ) {
			$html .= '<tr style="background-color:#e9ecea;" nobr="true">';
			foreach ( $colonnes as $k => $c ) {
				$html .= '<td width="' . round( 100 * $c[2] / $poids, 3 ) . '%" align="' . ( in_array( $c[1], array( 'texte', 'long' ), true ) ? 'left' : 'right' ) . '" style="font-family:uebsansb;">' . esc_html( ueb_adm_rapport_cellule( $l[ $k ], $c[1] ) ) . '</td>';
			}
			$html .= '</tr>';
		}
		$pdf->writeHTML( $html . '</tbody></table>', true, false, false, false, '' );
	};
	$note = static function ( $texte ) use ( $pdf, $gris ) {
		$pdf->SetFont( 'uebserifi', '', 8 );
		$pdf->SetTextColorArray( $gris );
		$pdf->MultiCell( 0, 4.5, $texte, 0, 'L', false, 1 );
		$pdf->Ln( 1 );
	};

	$n = 1;
	$titre( $n++, 'Bilan du périmètre' );
	$lignes_bilan = array_map( static fn( $b ) => array( $b[0], ueb_adm_rapport_cellule( $b[1], $b[2] ) . ( 'fcfa' === $b[2] ? ' FCFA' : '' ), $b[3] ), $d['bilan'] );
	$tableau( array( array( 'Indicateur', 'texte', 30 ), array( 'Valeur', 'valeur', 18 ), array( 'Précision', 'texte', 52 ) ), $lignes_bilan, array() );
	foreach ( $d['tableaux'] as $t ) {
		$titre( $n++, $t['titre'] );
		$tableau( $t['colonnes'], $t['lignes'], $t['pieds'] );
		$note( $t['note'] );
	}

	$titre( $n, 'Notes de lecture' );
	foreach ( $d['notes'] as $nt ) {
		$pdf->SetTextColorArray( $encre );
		$pdf->writeHTML( '<p style="font-family:uebsans;font-size:8.4pt;color:#212529;"><span style="font-family:uebsansb;">' . esc_html( $nt[0] ) . ' : </span>' . esc_html( $nt[1] ) . '</p>', true, false, false, false, '' );
		$pdf->Ln( 0.8 );
	}
	$pdf->Ln( 4 );
	$pdf->SetFont( 'uebserifi', '', 9.5 );
	$pdf->SetTextColorArray( $encre );
	$pdf->Cell( 0, 6, 'Fait à Ebolowa, le ' . $d['fait_le'] . '.', 0, 1, 'R' );

	/* Pied de chaque page : origine du document et pagination. */
	$total = $pdf->getNumPages();
	for ( $p = 1; $p <= $total; $p++ ) {
		$pdf->setPage( $p );
		$pdf->SetAutoPageBreak( false ); // TCPDF rétablit le réglage propre à chaque page
		$yp = $pdf->getPageHeight() - 11;
		$pdf->SetLineStyle( array( 'width' => 0.2, 'color' => array( 200, 206, 202 ), 'dash' => 0 ) );
		$pdf->Line( $marge, $yp - 1.5, $marge + $largeur, $yp - 1.5 );
		$pdf->SetFont( 'uebsans', '', 7.4 );
		$pdf->SetTextColorArray( $gris );
		$pdf->SetXY( $marge, $yp );
		$pdf->Cell( $largeur - 30, 4, UEB_UNIVERSITE['fr'] . ', plateforme d’inscription. ' . $d['titre'] . ' ' . $d['annee']['libelle'] . ', édité le ' . $d['edite'] . '.', 0, 0, 'L' );
		$pdf->SetXY( $marge + $largeur - 30, $yp );
		$pdf->Cell( 30, 4, 'Page ' . $p . ' sur ' . $total, 0, 0, 'R' );
	}
	$pdf->lastPage();
	$pdf->Output( $d['fichier'] . '.pdf', 'D' );
}

/* ==========================================================================
   Office Open XML : outils communs (Word et Excel)
   ========================================================================== */

function ueb_ooxml_txt( $texte ) {
	return htmlspecialchars( (string) $texte, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
}

/** Assemble une archive OOXML et l'envoie. */
function ueb_ooxml_envoyer( array $fichiers, $nom, $type ) {
	$chemin = tempnam( get_temp_dir(), 'ueb-export' );
	$zip    = new ZipArchive();
	if ( true !== $zip->open( $chemin, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		wp_die( 'L’export n’a pas pu être préparé sur le serveur.', 'Export', array( 'response' => 500 ) );
	}
	foreach ( $fichiers as $interne => $contenu ) {
		$zip->addFromString( $interne, $contenu );
	}
	$zip->close();
	header( 'Content-Type: ' . $type );
	header( 'Content-Disposition: attachment; filename="' . $nom . '"' );
	header( 'Content-Length: ' . filesize( $chemin ) );
	readfile( $chemin ); // phpcs:ignore
	wp_delete_file( $chemin );
}

function ueb_ooxml_core( $titre ) {
	$maintenant = gmdate( 'Y-m-d\TH:i:s\Z' );
	return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
		. '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
		. '<dc:title>' . ueb_ooxml_txt( $titre ) . '</dc:title><dc:creator>' . ueb_ooxml_txt( UEB_UNIVERSITE['fr'] ) . '</dc:creator>'
		. '<dcterms:created xsi:type="dcterms:W3CDTF">' . $maintenant . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . $maintenant . '</dcterms:modified>'
		. '</cp:coreProperties>';
}

/* ==========================================================================
   Word (.docx)
   ========================================================================== */

function ueb_adm_export_docx( array $d ) {
	$couleur = strtoupper( ltrim( $d['couleur'], '#' ) );
	$largeur = 16838 - 2 * 850; // A4 paysage, marges de 1,5 cm, en vingtièmes de point

	$run = static function ( $texte, array $o = array() ) use ( $couleur ) {
		$pr  = '<w:rFonts w:ascii="' . ( $o['police'] ?? 'Calibri' ) . '" w:hAnsi="' . ( $o['police'] ?? 'Calibri' ) . '" w:cs="' . ( $o['police'] ?? 'Calibri' ) . '"/>';
		$pr .= ! empty( $o['b'] ) ? '<w:b/>' : '';
		$pr .= ! empty( $o['i'] ) ? '<w:i/>' : '';
		$pr .= ! empty( $o['majuscules'] ) ? '<w:caps/>' : '';
		$pr .= '<w:color w:val="' . ( ! empty( $o['identite'] ) ? $couleur : ( $o['couleur'] ?? '212529' ) ) . '"/>';
		$pr .= '<w:sz w:val="' . (int) ( 2 * ( $o['taille'] ?? 10 ) ) . '"/><w:szCs w:val="' . (int) ( 2 * ( $o['taille'] ?? 10 ) ) . '"/>';
		return '<w:r><w:rPr>' . $pr . '</w:rPr><w:t xml:space="preserve">' . ueb_ooxml_txt( $texte ) . '</w:t></w:r>';
	};
	$para = static function ( $runs, array $o = array() ) {
		$pr  = ! empty( $o['style'] ) ? '<w:pStyle w:val="' . $o['style'] . '"/>' : '';
		$pr .= ! empty( $o['suivant'] ) ? '<w:keepNext/>' : '';
		$pr .= $o['bordure'] ?? '';
		$pr .= '<w:spacing w:before="' . (int) ( $o['avant'] ?? 0 ) . '" w:after="' . (int) ( $o['apres'] ?? 80 ) . '"/>';
		$pr .= '<w:jc w:val="' . ( $o['align'] ?? 'left' ) . '"/>';
		return '<w:p><w:pPr>' . $pr . '</w:pPr>' . $runs . '</w:p>';
	};
	$cellule = static function ( $contenu, $largeur_cellule, array $o = array() ) {
		$pr  = '<w:tcW w:w="' . (int) $largeur_cellule . '" w:type="dxa"/>';
		$pr .= ! empty( $o['fond'] ) ? '<w:shd w:val="clear" w:color="auto" w:fill="' . $o['fond'] . '"/>' : '';
		$pr .= '<w:vAlign w:val="' . ( $o['valign'] ?? 'center' ) . '"/>';
		return '<w:tc><w:tcPr>' . $pr . '</w:tcPr>' . $contenu . '</w:tc>';
	};

	/* En-tête bilingue : tableau sans bordure, sceau au centre. */
	$colonnes_entete = array( (int) ( $largeur * 0.4 ), (int) ( $largeur * 0.2 ), (int) ( $largeur * 0.4 ) );
	$bloc            = static function ( array $lignes ) use ( $run, $para ) {
		$xml = '';
		foreach ( $lignes as $l ) {
			$o    = array( 'b' => 'b' === $l[1] || 'u' === $l[1] || 'e' === $l[1], 'i' => 'i' === $l[1], 'police' => '' === $l[1] ? 'Calibri' : 'Cambria', 'taille' => array( 'b' => 10, 'i' => 9, '' => 8.5, 'u' => 10.5, 'e' => 9 )[ $l[1] ], 'identite' => in_array( $l[1], array( 'u', 'e' ), true ) );
			$xml .= $para( $run( $l[0], $o ), array( 'align' => 'center', 'apres' => 'i' === $l[1] ? 60 : 10 ) );
		}
		return $xml;
	};
	$entete = ueb_adm_rapport_entete( $d );
	$logo   = ueb_logo_chemin( 'UEB' );
	$image  = '';
	if ( file_exists( $logo ) ) {
		$dim   = getimagesize( $logo );
		$cx    = 864000; // 2,4 cm
		$cy    = (int) round( $cx * $dim[1] / max( 1, $dim[0] ) );
		$image = '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="' . $cx . '" cy="' . $cy . '"/><wp:docPr id="1" name="Sceau"/>'
			. '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
			. '<pic:nvPicPr><pic:cNvPr id="1" name="sceau.png"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="rIdSceau"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
			. '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r>';
	}
	$sans_bordure = '<w:tblBorders><w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="nil"/><w:right w:val="nil"/><w:insideH w:val="nil"/><w:insideV w:val="nil"/></w:tblBorders>';
	$corps        = '<w:tbl><w:tblPr><w:tblW w:w="' . $largeur . '" w:type="dxa"/>' . $sans_bordure . '<w:tblLayout w:type="fixed"/></w:tblPr><w:tblGrid>';
	foreach ( $colonnes_entete as $l ) {
		$corps .= '<w:gridCol w:w="' . $l . '"/>';
	}
	$corps .= '</w:tblGrid><w:tr>' . $cellule( $bloc( $entete['fr'] ), $colonnes_entete[0], array( 'valign' => 'top' ) ) . $cellule( $para( $image, array( 'align' => 'center' ) ), $colonnes_entete[1] ) . $cellule( $bloc( $entete['en'] ), $colonnes_entete[2], array( 'valign' => 'top' ) ) . '</w:tr></w:tbl>';
	$corps .= $para( '', array( 'apres' => 200, 'bordure' => '<w:pBdr><w:bottom w:val="double" w:sz="6" w:space="1" w:color="' . $couleur . '"/></w:pBdr>' ) );

	/* Titre, objet, mentions d'édition. */
	$corps .= $para( $run( $d['titre'], array( 'b' => true, 'majuscules' => true, 'police' => 'Cambria', 'taille' => 16, 'identite' => true ) ), array( 'align' => 'center', 'avant' => 120, 'apres' => 60 ) );
	$corps .= $para( $run( $d['sous_titre'], array( 'i' => true, 'police' => 'Cambria', 'taille' => 11 ) ), array( 'align' => 'center', 'apres' => 60 ) );
	$corps .= $para( $run( 'Périmètre : ' . $d['perimetre'] . '      Édité le ' . $d['edite'], array( 'taille' => 9, 'couleur' => '646C68' ) ), array( 'align' => 'center', 'apres' => 240 ) );

	$titre = static function ( $numero, $texte ) use ( $run, $para, $couleur ) {
		return $para( $run( $numero . '. ' . $texte, array( 'b' => true, 'police' => 'Cambria', 'taille' => 12, 'identite' => true ) ), array( 'avant' => 240, 'apres' => 120, 'suivant' => true, 'bordure' => '<w:pBdr><w:bottom w:val="single" w:sz="4" w:space="2" w:color="' . $couleur . '"/></w:pBdr>' ) );
	};
	$teinte  = strtoupper( vsprintf( '%02x%02x%02x', ueb_pdf_teinte( ueb_pdf_rvb( $d['couleur'] ), 0.07 ) ) );
	$tableau = static function ( array $colonnes, array $lignes, array $pieds ) use ( $run, $para, $cellule, $largeur, $couleur, $teinte ) {
		$poids    = array_sum( array_column( $colonnes, 2 ) );
		$largeurs = array_map( static fn( $c ) => (int) floor( $largeur * $c[2] / $poids ), $colonnes );
		$bordures = '<w:tblBorders><w:top w:val="single" w:sz="4" w:color="' . $couleur . '"/><w:left w:val="nil"/><w:bottom w:val="single" w:sz="4" w:color="' . $couleur . '"/><w:right w:val="nil"/><w:insideH w:val="single" w:sz="2" w:color="DCE3DE"/><w:insideV w:val="nil"/></w:tblBorders>';
		$xml      = '<w:tbl><w:tblPr><w:tblW w:w="' . array_sum( $largeurs ) . '" w:type="dxa"/>' . $bordures . '<w:tblLayout w:type="fixed"/><w:tblCellMar><w:top w:w="50" w:type="dxa"/><w:left w:w="90" w:type="dxa"/><w:bottom w:w="50" w:type="dxa"/><w:right w:w="90" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>';
		foreach ( $largeurs as $l ) {
			$xml .= '<w:gridCol w:w="' . $l . '"/>';
		}
		$xml .= '</w:tblGrid><w:tr><w:trPr><w:tblHeader/><w:cantSplit/></w:trPr>';
		foreach ( $colonnes as $k => $c ) {
			$xml .= $cellule( $para( $run( $c[0], array( 'b' => true, 'taille' => 8.5, 'couleur' => 'FFFFFF' ) ), array( 'align' => in_array( $c[1], array( 'texte', 'long' ), true ) ? 'left' : 'right', 'apres' => 0 ) ), $largeurs[ $k ], array( 'fond' => $couleur ) );
		}
		$xml .= '</w:tr>';
		foreach ( array_merge( $lignes, array( null ), $pieds ) as $i => $l ) {
			if ( null === $l ) {
				continue;
			}
			$pied = $i > count( $lignes );
			$xml .= '<w:tr><w:trPr><w:cantSplit/></w:trPr>';
			foreach ( $colonnes as $k => $c ) {
				$xml .= $cellule( $para( $run( ueb_adm_rapport_cellule( $l[ $k ], $c[1] ), array( 'b' => $pied, 'taille' => 8.5 ) ), array( 'align' => in_array( $c[1], array( 'texte', 'long' ), true ) ? 'left' : 'right', 'apres' => 0 ) ), $largeurs[ $k ], array( 'fond' => $pied ? 'E9ECEA' : ( $i % 2 ? $teinte : '' ) ) );
			}
			$xml .= '</w:tr>';
		}
		return $xml . '</w:tbl>';
	};
	$note = static fn( $texte ) => $para( $run( $texte, array( 'i' => true, 'police' => 'Cambria', 'taille' => 8.5, 'couleur' => '646C68' ) ), array( 'avant' => 80, 'apres' => 80 ) );

	$n      = 1;
	$corps .= $titre( $n++, 'Bilan du périmètre' );
	$corps .= $tableau( array( array( 'Indicateur', 'texte', 30 ), array( 'Valeur', 'valeur', 18 ), array( 'Précision', 'texte', 52 ) ), array_map( static fn( $b ) => array( $b[0], ueb_adm_rapport_cellule( $b[1], $b[2] ) . ( 'fcfa' === $b[2] ? ' FCFA' : '' ), $b[3] ), $d['bilan'] ), array() );
	foreach ( $d['tableaux'] as $t ) {
		$corps .= $titre( $n++, $t['titre'] ) . $tableau( $t['colonnes'], $t['lignes'], $t['pieds'] ) . $note( $t['note'] );
	}
	$corps .= $titre( $n, 'Notes de lecture' );
	foreach ( $d['notes'] as $nt ) {
		$corps .= $para( $run( $nt[0] . ' : ', array( 'b' => true, 'taille' => 9 ) ) . $run( $nt[1], array( 'taille' => 9 ) ), array( 'apres' => 80 ) );
	}
	$corps .= $para( $run( 'Fait à Ebolowa, le ' . $d['fait_le'] . '.', array( 'i' => true, 'police' => 'Cambria', 'taille' => 10 ) ), array( 'align' => 'right', 'avant' => 360 ) );

	$section = '<w:sectPr><w:footerReference w:type="default" r:id="rIdPied"/><w:pgSz w:w="16838" w:h="11906" w:orient="landscape"/><w:pgMar w:top="850" w:right="850" w:bottom="850" w:left="850" w:header="450" w:footer="450" w:gutter="0"/></w:sectPr>';
	$ns      = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"';
	$document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document ' . $ns . '><w:body>' . $corps . $section . '</w:body></w:document>';

	$rpr   = '<w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/><w:color w:val="646C68"/><w:sz w:val="15"/><w:szCs w:val="15"/></w:rPr>';
	$champ = static fn( $code ) => '<w:r>' . $rpr . '<w:fldChar w:fldCharType="begin"/></w:r><w:r>' . $rpr . '<w:instrText xml:space="preserve"> ' . $code . ' </w:instrText></w:r><w:r>' . $rpr . '<w:fldChar w:fldCharType="separate"/></w:r><w:r>' . $rpr . '<w:t>1</w:t></w:r><w:r>' . $rpr . '<w:fldChar w:fldCharType="end"/></w:r>';
	$gris  = array( 'taille' => 7.5, 'couleur' => '646C68' );
	$pied  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:ftr ' . $ns . '><w:p><w:pPr><w:pBdr><w:top w:val="single" w:sz="2" w:space="4" w:color="C8CECA"/></w:pBdr><w:tabs><w:tab w:val="right" w:pos="' . $largeur . '"/></w:tabs><w:spacing w:after="0"/></w:pPr>'
		. $run( UEB_UNIVERSITE['fr'] . ', plateforme d’inscription. ' . $d['titre'] . ' ' . $d['annee']['libelle'] . ', édité le ' . $d['edite'] . '.', $gris )
		. '<w:r><w:tab/></w:r>' . $run( 'Page ', $gris ) . $champ( 'PAGE' ) . $run( ' sur ', $gris ) . $champ( 'NUMPAGES' ) . '</w:p></w:ftr>';

	$styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
		. '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri" w:eastAsia="Calibri"/><w:sz w:val="20"/><w:szCs w:val="20"/><w:lang w:val="fr-FR"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:after="80" w:line="259" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
		. '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>'
		. '<w:style w:type="table" w:default="1" w:styleId="TableNormal"><w:name w:val="Normal Table"/><w:tblPr><w:tblInd w:w="0" w:type="dxa"/><w:tblCellMar><w:top w:w="0" w:type="dxa"/><w:left w:w="108" w:type="dxa"/><w:bottom w:w="0" w:type="dxa"/><w:right w:w="108" w:type="dxa"/></w:tblCellMar></w:tblPr></w:style>'
		. '</w:styles>';

	$fichiers = array(
		'[Content_Types].xml'          => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="png" ContentType="image/png"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/><Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>',
		'_rels/.rels'                  => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>',
		'docProps/core.xml'            => ueb_ooxml_core( $d['titre'] . ' ' . $d['annee']['libelle'] ),
		'word/document.xml'            => $document,
		'word/styles.xml'              => $styles,
		'word/footer1.xml'             => $pied,
		'word/_rels/document.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/><Relationship Id="rIdPied" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/>' . ( $image ? '<Relationship Id="rIdSceau" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/sceau.png"/>' : '' ) . '</Relationships>',
	);
	if ( $image ) {
		$fichiers['word/media/sceau.png'] = file_get_contents( $logo ); // phpcs:ignore
	}
	ueb_ooxml_envoyer( $fichiers, $d['fichier'] . '.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' );
}

/* ==========================================================================
   Excel (.xlsx) : données brutes, sans en-tête institutionnel
   ========================================================================== */

/** Référence de colonne Excel : 0 → A, 26 → AA. */
function ueb_xlsx_colonne( $i ) {
	$nom = '';
	for ( $i++; $i > 0; $i = intdiv( $i - 1, 26 ) ) {
		$nom = chr( 65 + ( $i - 1 ) % 26 ) . $nom;
	}
	return $nom;
}

function ueb_adm_export_xlsx( array $d ) {
	/* Styles : 0 texte, 1 titre de colonne, 2 entier « # ##0 », 3 taux « 0,0 % », 4 texte à la ligne. */
	$styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
		. '<numFmts count="1"><numFmt numFmtId="164" formatCode="0.0%"/></numFmts>'
		. '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
		. '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE9EEEA"/><bgColor indexed="64"/></patternFill></fill></fills>'
		. '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left/><right/><top/><bottom style="thin"><color rgb="FF9AA69E"/></bottom><diagonal/></border></borders>'
		. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
		. '<cellXfs count="5"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/><xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf></cellXfs>'
		. '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';

	$feuilles = array();
	foreach ( $d['tableaux'] as $t ) {
		$feuilles[] = array( $t['feuille'], $t['colonnes'], $t['lignes'] );
	}
	$feuilles[] = array(
		'Bilan',
		array( array( 'Indicateur', 'texte', 34 ), array( 'Valeur', 'nombre', 16 ), array( 'Unité', 'texte', 10 ), array( 'Précision', 'long', 60 ) ),
		array_map( static fn( $b ) => array( $b[0], $b[1], $b[4], $b[3] ), $d['bilan'] ),
	);

	$fichiers = array();
	$classeur = '';
	$liens    = '';
	$noms     = '';
	$types    = '';
	foreach ( $feuilles as $i => $f ) {
		list( $nom, $colonnes, $lignes ) = $f;
		$fin  = ueb_xlsx_colonne( count( $colonnes ) - 1 ) . ( count( $lignes ) + 1 );
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
		$xml .= '<dimension ref="A1:' . $fin . '"/><sheetViews><sheetView workbookViewId="0"' . ( 0 === $i ? ' tabSelected="1"' : '' ) . '><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>';
		foreach ( $colonnes as $k => $c ) {
			$largeur = 'texte' === $c[1] || 'long' === $c[1] ? max( 12, $c[2] * ( 'long' === $c[1] ? 1 : 1.3 ) ) : max( 14, mb_strlen( $c[0] ) + 4 );
			$xml    .= '<col min="' . ( $k + 1 ) . '" max="' . ( $k + 1 ) . '" width="' . round( $largeur, 1 ) . '" customWidth="1"/>';
		}
		$xml .= '</cols><sheetData><row r="1">';
		foreach ( $colonnes as $k => $c ) {
			$xml .= '<c r="' . ueb_xlsx_colonne( $k ) . '1" t="inlineStr" s="1"><is><t>' . ueb_ooxml_txt( $c[0] ) . '</t></is></c>';
		}
		$xml .= '</row>';
		foreach ( $lignes as $r => $l ) {
			$ligne = $r + 2;
			$xml  .= '<row r="' . $ligne . '">';
			foreach ( $colonnes as $k => $c ) {
				$ref = ueb_xlsx_colonne( $k ) . $ligne;
				$v   = $l[ $k ];
				if ( null === $v || '' === $v ) {
					continue;
				}
				if ( 'texte' === $c[1] || 'long' === $c[1] || ! is_numeric( $v ) ) {
					$xml .= '<c r="' . $ref . '" t="inlineStr"' . ( 'long' === $c[1] ? ' s="4"' : '' ) . '><is><t xml:space="preserve">' . ueb_ooxml_txt( $v ) . '</t></is></c>';
				} elseif ( 'pourcent' === $c[1] ) {
					$xml .= '<c r="' . $ref . '" s="3"><v>' . round( $v / 100, 6 ) . '</v></c>';
				} else {
					$xml .= '<c r="' . $ref . '" s="2"><v>' . (int) $v . '</v></c>';
				}
			}
			$xml .= '</row>';
		}
		$xml .= '</sheetData><autoFilter ref="A1:' . $fin . '"/><pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/><pageSetup paperSize="9" orientation="landscape"/></worksheet>';

		$n                                          = $i + 1;
		$fichiers[ 'xl/worksheets/sheet' . $n . '.xml' ] = $xml;
		$classeur .= '<sheet name="' . ueb_ooxml_txt( $nom ) . '" sheetId="' . $n . '" r:id="rIdF' . $n . '"/>';
		$liens    .= '<Relationship Id="rIdF' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
		$noms     .= '<definedName name="_xlnm._FilterDatabase" localSheetId="' . $i . '" hidden="1">' . ueb_ooxml_txt( "'" . str_replace( "'", "''", $nom ) . "'" ) . '!$A$1:$' . preg_replace( '/\d+$/', '', $fin ) . '$' . preg_replace( '/^[A-Z]+/', '', $fin ) . '</definedName>';
		$types    .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
	}

	$fichiers = array( '[Content_Types].xml' => '' ) + $fichiers;
	$fichiers['[Content_Types].xml']        = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>' . $types . '</Types>';
	$fichiers['_rels/.rels']                = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>';
	$fichiers['docProps/core.xml']          = ueb_ooxml_core( $d['titre'] . ' ' . $d['annee']['libelle'] );
	$fichiers['xl/workbook.xml']            = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView activeTab="0"/></bookViews><sheets>' . $classeur . '</sheets><definedNames>' . $noms . '</definedNames></workbook>';
	$fichiers['xl/_rels/workbook.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $liens . '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
	$fichiers['xl/styles.xml']              = $styles;

	ueb_ooxml_envoyer( $fichiers, $d['fichier'] . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
}
