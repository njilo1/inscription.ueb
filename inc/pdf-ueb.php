<?php
/**
 * TCPDF pour les documents en français de l'université : les polices sont
 * incorporées entières (lisibles dans tous les lecteurs), la langue du
 * document est déclarée (lecteurs PDF, synthèse vocale, indexation), la
 * mention anglaise que la bibliothèque ajoute en bas de la dernière page est
 * retirée, et tout le texte écrit prend l'apostrophe typographique du
 * français (« Université d’Ebolowa »).
 *
 * Usage : require_once ce fichier, puis new UEB_TCPDF( … ) comme TCPDF.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

require_once UEB_INSC_DIR . '/lib/tcpdf/tcpdf.php';

class UEB_TCPDF extends TCPDF {

	public function __construct( ...$arguments ) {
		parent::__construct( ...$arguments );
		/* Les polices Source converties (uebsans, uebserif…) deviennent illisibles
		   quand TCPDF n'en garde qu'un sous-ensemble (FreeType : « unknown file
		   format ») : les lecteurs PDF affichent alors une police de remplacement,
		   des carrés ou rien. Elles sont donc incorporées entières, comme sur le quitus. */
		$this->setFontSubsetting( false );
		$this->tcpdflink = false;
		$this->setLanguageArray( array(
			'a_meta_charset'  => 'UTF-8',
			'a_meta_dir'      => 'ltr',
			'a_meta_language' => 'fr',
			'w_page'          => 'page',
		) );
	}

	/** Tout texte écrit passe par Cell (Write, MultiCell, writeHTML compris). */
	public function Cell( $w, $h = 0, $txt = '', $border = 0, $ln = 0, $align = '', $fill = false, $link = '', $stretch = 0, $ignore_min_height = false, $calign = 'T', $valign = 'M' ) {
		return parent::Cell( $w, $h, str_replace( "'", '’', (string) $txt ), $border, $ln, $align, $fill, $link, $stretch, $ignore_min_height, $calign, $valign );
	}
}
