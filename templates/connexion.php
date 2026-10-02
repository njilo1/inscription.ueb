<?php
/**
 * Connexion étudiant : matricule + mot de passe.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

list( $saisie, $erreurs ) = ueb_reprendre_saisie();
ueb_page_debut( array( 'titre' => 'Connexion', 'variante' => 'auth' ) );
get_template_part( 'templates/partie', 'auth-debut', array( 'page' => 'connexion' ) );
?>
				<h1 class="acces__titre" id="acces-titre">Connexion</h1>
				<p class="acces__intro">Connecte-toi avec ton matricule et ton mot de passe.</p>

				<?php ueb_afficher_flash(); ?>
				<?php if ( ! empty( $erreurs['general'] ) ) : ?>
					<?php ueb_alerte( 'erreur', $erreurs['general'] ); ?>
				<?php endif; ?>

				<form class="formulaire" method="post" action="<?php echo esc_url( ueb_url( 'connexion' ) ); ?>" data-formulaire novalidate>
					<?php ueb_champ_csrf(); ?>
					<input type="hidden" name="ueb_action" value="connexion">
					<?php
					ueb_champ( array(
						'nom'     => 'identifiant',
						'libelle' => 'Matricule',
						'icone'   => 'utilisateur',
						'valeur'  => $saisie['identifiant'] ?? '',
						'attrs'   => array( 'autocomplete' => 'username', 'autocapitalize' => 'characters', 'spellcheck' => 'false', 'placeholder' => 'Exemple : 24I0017FS', 'data-identifiant' => true, 'autofocus' => true ),
					) );
					ueb_champ( array(
						'nom'     => 'mot_de_passe',
						'libelle' => 'Mot de passe',
						'type'    => 'password',
						'icone'   => 'cadenas',
						'attrs'   => array( 'autocomplete' => 'current-password' ),
					) );
					?>
					<p class="acces__oubli"><a href="<?php echo esc_url( ueb_url( 'mot-de-passe-oublie' ) ); ?>">Mot de passe oublié ?</a></p>
					<button class="btn btn--sombre btn--large" type="submit">Se connecter</button>
				</form>
<?php
get_template_part( 'templates/partie', 'auth-fin', array( 'page' => 'connexion' ) );
ueb_page_fin( 'auth' );
