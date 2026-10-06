<?php
/**
 * Accès aux deux modules sensibles, compte par compte : les droits
 * universitaires (du) et la visite médicale (vm).
 *
 * Le rôle dit ce qu'un compte peut faire ; la méta « ueb_acces_modules » du
 * compte dit quels modules il voit : du, vm, tous ou aucun. Les permissions
 * d'un module fermé sont retirées du compte (filtre user_has_cap) : tous les
 * contrôles du serveur (ueb_peut, current_user_can, user_can) les refusent,
 * pas seulement le menu. Le reste (étudiants, paiements, IPES…) suit le rôle.
 *
 * Sans méta, le rôle seul décide (comptes créés avant ce module ou depuis
 * l'interface). L'administrateur n'est jamais restreint.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

/* Modules ouverts par chaque valeur. Un module est un type de quitus, dont les
   permissions sont dans UEB_PERMISSIONS_TYPE_QUITUS (inc/gestion.php). */
const UEB_ACCES_MODULES = array(
	'du'    => array( 'droits' ),
	'vm'    => array( 'medicaux' ),
	'tous'  => array( 'droits', 'medicaux' ),
	'aucun' => array(),
);

/** Valeur du compte (du, vm, tous, aucun), ou chaîne vide si le rôle seul décide. */
function ueb_acces_modules( $user_id = 0 ) {
	$valeur = (string) get_user_meta( $user_id ?: get_current_user_id(), 'ueb_acces_modules', true );
	return isset( UEB_ACCES_MODULES[ $valeur ] ) ? $valeur : '';
}

/** Enregistre la valeur d'un compte. Faux si la valeur est inconnue. */
function ueb_definir_acces_modules( $user_id, $valeur ) {
	if ( ! isset( UEB_ACCES_MODULES[ $valeur ] ) ) {
		return false;
	}
	update_user_meta( $user_id, 'ueb_acces_modules', $valeur );
	return true;
}

/* Permissions des modules fermés retirées du compte. Priorité 100 : après le
   profil simulé (99), qui ne concerne que l'administrateur. */
add_filter( 'user_has_cap', function ( $allcaps, $caps, $args, $user ) {
	/* $user->allcaps : capacités tirées des rôles, avant tout filtre. */
	if ( ! $user instanceof WP_User || ! empty( $user->allcaps['manage_options'] ) ) {
		return $allcaps;
	}
	$acces = ueb_acces_modules( $user->ID );
	if ( '' === $acces ) {
		return $allcaps;
	}
	foreach ( UEB_PERMISSIONS_TYPE_QUITUS as $module => $permissions ) {
		if ( ! in_array( $module, UEB_ACCES_MODULES[ $acces ], true ) ) {
			foreach ( $permissions as $cap ) {
				unset( $allcaps[ $cap ] );
			}
		}
	}
	return $allcaps;
}, 100, 4 );
