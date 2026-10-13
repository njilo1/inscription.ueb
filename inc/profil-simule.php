<?php
/**
 * Changer de profil : le super-administrateur se met à la place d'un profil.
 *
 * Un profil est un rôle du registre (sur un établissement quand sa portée est
 * « un »), ou l'administrateur d'un IPES. Tant qu'un profil est choisi (méta
 * « ueb_profil_simule » du compte), les capacités, le rôle, l'établissement
 * et l'IPES du compte sont ceux du profil : barre latérale, écrans, listes et
 * décisions se filtrent exactement comme pour un compte de ce profil. Les
 * décisions prises restent enregistrées au nom du super-administrateur.
 *
 * Garde-fous : seul un VRAI administrateur WordPress (rôles du compte, avant
 * tout filtre) peut choisir ou quitter un profil ; la simulation ne touche
 * jamais wp-admin, qui reste entièrement accessible ; un profil devenu
 * invalide (rôle supprimé, IPES retiré) est ignoré.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

/** Vrai si le compte est administrateur WordPress, profil choisi ou non. */
function ueb_super_admin_reel( $user_id = 0 ) {
	$user = get_userdata( $user_id ?: get_current_user_id() );
	/* allcaps : capacités tirées des rôles du compte, avant le filtre « user_has_cap ». */
	return $user && ! empty( $user->allcaps['manage_options'] );
}

/**
 * Profil choisi par le super-administrateur connecté, ou null.
 *
 * @return array{type: string, role?: string, etab?: string, ipes?: int}|null
 */
function ueb_profil_simule() {
	static $lecture = false; // les filtres ci-dessous relisent les métas : pas de boucle
	$user_id = get_current_user_id();
	if ( $lecture || ! $user_id || ( is_admin() && ! wp_doing_ajax() ) || ! ueb_super_admin_reel( $user_id ) ) {
		return null;
	}
	$lecture = true;
	$p       = get_user_meta( $user_id, 'ueb_profil_simule', true );
	$valide  = null;
	if ( is_array( $p ) && 'role' === ( $p['type'] ?? '' ) && ueb_role( $p['role'] ?? '' ) ) {
		$un     = 'un' === ueb_role( $p['role'] )['portee'];
		$valide = ! $un || ueb_etablissement( $p['etab'] ?? '' ) ? $p : null;
	} elseif ( is_array( $p ) && 'ipes' === ( $p['type'] ?? '' ) && function_exists( 'ueb_ipes' ) && ueb_ipes( (int) ( $p['ipes'] ?? 0 ) ) ) {
		$valide = $p;
	}
	$lecture = false;
	return $valide;
}

/** Libellé d'un profil : « Scolarité UEb (FS) », « IPES UCAC ». */
function ueb_libelle_profil( array $p ) {
	if ( 'ipes' === $p['type'] ) {
		return 'IPES ' . ( ueb_ipes( (int) $p['ipes'] )->sigle ?? '?' );
	}
	$def = ueb_role( $p['role'] );
	return 'un' === $def['portee'] ? ueb_intitule_role( $def, $p['etab'] ) . ' — ' . $p['etab'] : $def['nom'];
}

/* Capacités du profil à la place de celles de l'administrateur. */
add_filter( 'user_has_cap', function ( $allcaps, $caps, $args, $user ) {
	if ( ! $user instanceof WP_User || (int) $user->ID !== get_current_user_id() ) {
		return $allcaps;
	}
	$p = ueb_profil_simule();
	if ( ! $p ) {
		return $allcaps;
	}
	$simule = array( 'read' => true );
	if ( 'ipes' === $p['type'] ) {
		$simule[ UEB_CAP_IPES ] = true;
	} else {
		foreach ( (array) ueb_role( $p['role'] )['permissions'] as $cap ) {
			$simule[ $cap ] = true;
		}
	}
	return $simule;
}, 99, 4 );

/* Rôle du profil : un rôle du registre, ou celui d'administrateur d'IPES. */
add_filter( 'ueb_role_du_compte', function ( $slug, $user_id ) {
	$p = (int) $user_id === get_current_user_id() ? ueb_profil_simule() : null;
	return $p && 'role' === $p['type'] ? $p['role'] : ( $p ? '' : $slug );
}, 10, 2 );
add_filter( 'ueb_est_admin_ipes', function ( $oui, $user_id ) {
	$p = (int) $user_id === get_current_user_id() ? ueb_profil_simule() : null;
	return $p ? 'ipes' === $p['type'] : $oui;
}, 10, 2 );

/* Établissement (portée « un ») et IPES du profil. Le sélecteur d'établissement
   (portée « plusieurs » ou « tous ») fonctionne comme pour tout compte. */
add_filter( 'get_user_metadata', function ( $valeur, $user_id, $cle ) {
	if ( ! in_array( $cle, array( 'ueb_etablissement', 'ueb_ipes_id' ), true ) || (int) $user_id !== get_current_user_id() ) {
		return $valeur;
	}
	$p = ueb_profil_simule();
	if ( ! $p ) {
		return $valeur;
	}
	$par_cle = array( 'ueb_etablissement' => $p['etab'] ?? '', 'ueb_ipes_id' => (int) ( $p['ipes'] ?? 0 ) );
	return array( $par_cle[ $cle ] );
}, 10, 3 );

/** Profils proposés : chaque rôle (par établissement si sa portée est « un »), puis chaque IPES actif. */
function ueb_profils_proposes() {
	$groupes = array();
	foreach ( ueb_roles() as $slug => $def ) {
		if ( 'un' === $def['portee'] ) {
			foreach ( array_keys( ueb_etablissements() ) as $sigle ) {
				$groupes['Rôles'][ "role:$slug:$sigle" ] = ueb_intitule_role( $def, $sigle ) . ' — ' . $sigle;
			}
		} else {
			$groupes['Rôles'][ "role:$slug" ] = $def['nom'] . ( 'tous' === $def['portee'] ? '' : ' — ' . implode( ', ', (array) $def['etablissements'] ) );
		}
	}
	foreach ( ueb_ipes_liste( array( 'actif' => 1 ) ) as $ipes ) {
		$groupes['IPES'][ 'ipes:' . (int) $ipes->id ] = $ipes->sigle;
	}
	return $groupes;
}

/** Action « profil_simuler » : choisit un profil, ou revient à la vue complète. */
function ueb_action_profil_simuler() {
	if ( ! ueb_super_admin_reel() ) {
		wp_die( 'Réservé aux administrateurs de la plateforme.', 'Accès refusé', array( 'response' => 403 ) );
	}
	$morceaux = explode( ':', sanitize_text_field( wp_unslash( $_POST['profil'] ?? '' ) ) );
	$profil   = null;
	if ( 'role' === $morceaux[0] && ueb_role( $morceaux[1] ?? '' ) ) {
		$etab   = strtoupper( $morceaux[2] ?? '' );
		$un     = 'un' === ueb_role( $morceaux[1] )['portee'];
		$profil = ! $un ? array( 'type' => 'role', 'role' => $morceaux[1] ) : ( ueb_etablissement( $etab ) ? array( 'type' => 'role', 'role' => $morceaux[1], 'etab' => $etab ) : null );
	} elseif ( 'ipes' === $morceaux[0] && ueb_ipes( (int) ( $morceaux[1] ?? 0 ) ) ) {
		$profil = array( 'type' => 'ipes', 'ipes' => (int) $morceaux[1] );
	}
	if ( $profil ) {
		update_user_meta( get_current_user_id(), 'ueb_profil_simule', $profil );
		ueb_flash( 'info', 'Tu vois maintenant l’Administration comme « ' . ueb_libelle_profil( $profil ) . ' ».' );
	} else {
		delete_user_meta( get_current_user_id(), 'ueb_profil_simule' );
		ueb_flash( 'succes', 'Retour à ta vue complète d’administrateur.' );
	}
	ueb_rediriger( ueb_url_administration() );
}

/**
 * « Changer de profil », en bas de la barre latérale (super-administrateur
 * seulement). La liste native, invisible, couvre un bouton dessiné : elle
 * reste utilisable au clavier et au lecteur d'écran. En profil simulé, une
 * carte dorée dit quel profil on voit et ramène à la vue complète.
 */
function ueb_selecteur_profil() {
	if ( ! ueb_super_admin_reel() ) {
		return;
	}
	$actuel = ueb_profil_simule();
	$valeur = '';
	$nom    = '';
	$portee = '';
	if ( $actuel && 'ipes' === $actuel['type'] ) {
		$valeur = 'ipes:' . $actuel['ipes'];
		$nom    = 'IPES';
		$portee = ueb_ipes( (int) $actuel['ipes'] )->sigle ?? '';
	} elseif ( $actuel ) {
		$def    = ueb_role( $actuel['role'] );
		$valeur = 'role:' . $actuel['role'] . ( 'un' === $def['portee'] ? ':' . $actuel['etab'] : '' );
		$nom    = $def['nom'];
		$portee = 'un' === $def['portee'] ? $actuel['etab'] : ( 'tous' === $def['portee'] ? 'Tous les établissements' : implode( ', ', (array) $def['etablissements'] ) );
	}
	?>
	<form class="bo-profil<?php echo $actuel ? ' est-simule' : ''; ?>" method="post" action="<?php echo esc_url( ueb_url_administration() ); ?>">
		<?php ueb_champ_csrf(); ?>
		<input type="hidden" name="ueb_action" value="profil_simuler">
		<?php if ( $actuel ) : ?>
			<div class="bo-profil__etat">
				<span class="bo-profil__icone"><?php echo ueb_icone( 'oeil', 16 ); ?></span>
				<p class="bo-profil__qui">
					<small>Profil simulé</small>
					<b><?php echo esc_html( $nom ); ?></b>
					<?php if ( $portee ) : ?><span class="bo-profil__portee"><?php echo esc_html( $portee ); ?></span><?php endif; ?>
				</p>
			</div>
		<?php endif; ?>
		<div class="bo-profil__actions">
			<label class="bo-profil__changer">
				<?php echo $actuel ? '' : ueb_icone( 'oeil', 16 ); ?>
				<span><?php echo $actuel ? 'Changer' : 'Changer de profil'; ?></span>
				<?php echo ueb_icone( 'chevron', 16, 'bo-profil__chevron' ); ?>
				<select name="profil" onchange="this.form.submit()" aria-label="Changer de profil">
					<option value="" <?php selected( $valeur, '' ); ?>>Ma vue complète (administrateur)</option>
					<?php foreach ( ueb_profils_proposes() as $groupe => $options ) : ?>
						<optgroup label="<?php echo esc_attr( $groupe ); ?>">
							<?php foreach ( $options as $cle => $libelle ) : ?>
								<option value="<?php echo esc_attr( $cle ); ?>" <?php selected( $valeur, $cle ); ?>><?php echo esc_html( $libelle ); ?></option>
							<?php endforeach; ?>
						</optgroup>
					<?php endforeach; ?>
				</select>
			</label>
			<?php if ( $actuel ) : /* après la liste : son « profil » vide l'emporte à l'envoi */ ?>
				<button class="bo-profil__retour" type="submit" name="profil" value="" title="Revenir à ma vue complète"><?php echo ueb_icone( 'fleche-g', 15 ); ?>Revenir</button>
			<?php endif; ?>
		</div>
		<noscript><button class="btn btn--petit btn--clair" type="submit">Afficher</button></noscript>
	</form>
	<?php
}
