<?php
/**
 * Montant en lettres, en français : 25000 → « Vingt-cinq mille francs CFA ».
 *
 * Règles de l'orthographe traditionnelle : traits d'union sous cent,
 * « et un », « quatre-vingts » et « deux cents » prennent un s en fin de
 * nombre, « mille » est invariable, « million » et « milliard » s'accordent.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

function ueb_lettres_moins_de_cent( $n ) {
	static $unites = array( 'zéro', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf', 'dix',
		'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize', 'dix-sept', 'dix-huit', 'dix-neuf' );
	static $dizaines = array( 2 => 'vingt', 3 => 'trente', 4 => 'quarante', 5 => 'cinquante', 6 => 'soixante' );

	if ( $n < 20 ) {
		return $unites[ $n ];
	}
	$d = intdiv( $n, 10 );
	$u = $n % 10;
	if ( $d <= 6 ) {
		if ( 0 === $u ) {
			return $dizaines[ $d ];
		}
		return $dizaines[ $d ] . ( 1 === $u ? ' et un' : '-' . $unites[ $u ] );
	}
	if ( 7 === $d ) { // 70-79 : soixante-dix, soixante et onze…
		return 'soixante' . ( 1 === $u ? ' et onze' : '-' . $unites[ 10 + $u ] );
	}
	if ( 8 === $d ) { // 80-89 : quatre-vingts, quatre-vingt-un…
		return 0 === $u ? 'quatre-vingts' : 'quatre-vingt-' . $unites[ $u ];
	}
	return 'quatre-vingt-' . $unites[ 10 + $u ]; // 90-99
}

/** $final : le groupe termine le nombre (accord de « cents » et « vingts »). */
function ueb_lettres_moins_de_mille( $n, $final ) {
	$c    = intdiv( $n, 100 );
	$r    = $n % 100;
	$mots = '';
	if ( $c > 0 ) {
		$mots = ( $c > 1 ? ueb_lettres_moins_de_cent( $c ) . ' ' : '' ) . 'cent';
		if ( $c > 1 && 0 === $r && $final ) {
			$mots .= 's';
		}
	}
	if ( $r > 0 ) {
		$dizaines = ueb_lettres_moins_de_cent( $r );
		if ( ! $final ) {
			$dizaines = preg_replace( '/quatre-vingts$/', 'quatre-vingt', $dizaines );
		}
		$mots .= ( $mots ? ' ' : '' ) . $dizaines;
	}
	return $mots;
}

function ueb_nombre_en_lettres( $n ) {
	$n = (int) $n;
	if ( 0 === $n ) {
		return 'zéro';
	}
	$echelles = array(
		1000000000 => array( 'milliard', 'milliards' ),
		1000000    => array( 'million', 'millions' ),
	);
	$mots = array();
	foreach ( $echelles as $valeur => $noms ) {
		if ( $n >= $valeur ) {
			$q      = intdiv( $n, $valeur );
			$mots[] = ueb_lettres_moins_de_mille( $q, true ) . ' ' . ( $q > 1 ? $noms[1] : $noms[0] );
			$n     %= $valeur;
		}
	}
	if ( $n >= 1000 ) {
		$q      = intdiv( $n, 1000 );
		$mots[] = ( 1 === $q ? '' : ueb_lettres_moins_de_mille( $q, false ) . ' ' ) . 'mille';
		$n     %= 1000;
	}
	if ( $n > 0 ) {
		$mots[] = ueb_lettres_moins_de_mille( $n, true );
	}
	return trim( implode( ' ', $mots ) );
}

/** 25000 → « Vingt-cinq mille francs CFA ». */
function ueb_montant_en_lettres( $montant ) {
	$lettres = ueb_nombre_en_lettres( $montant );
	/* « deux millions de francs », mais « deux millions cent francs ». */
	$liaison = preg_match( '/(million|milliard)s?$/', $lettres ) ? ' de francs CFA' : ' francs CFA';
	$texte   = $lettres . $liaison;
	return mb_strtoupper( mb_substr( $texte, 0, 1 ) ) . mb_substr( $texte, 1 );
}

/** 25000 → « 25 000 ». */
function ueb_formater_montant( $montant ) {
	return number_format( (int) $montant, 0, ',', ' ' );
}
