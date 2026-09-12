<?php
/**
 * Connexion étudiant : matricule ou n° de dossier + mot de passe.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

list( $saisie, $erreurs ) = ueb_reprendre_saisie();
ueb_page_debut( array( 'titre' => 'Connexion', 'variante' => 'auth' ) );
get_template_part( 'templates/partie', 'auth-debut', array( 'page' => 'connexion' ) );
?>
				<h1 class="acces__titre" id="acces-titre">Connexion</h1>
				<p class="acces__intro">Connecte-toi avec ton matricule ou ton numéro de dossier de préinscription.</p>

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
						'libelle' => 'Matricule ou numéro de dossier',
						'icone'   => 'utilisateur',
						'valeur'  => $saisie['identifiant'] ?? '',
						'attrs'   => array( 'autocomplete' => 'username', 'autocapitalize' => 'characters', 'spellcheck' => 'false', 'placeholder' => '24I0017FS ou UEB-2026-000123', 'data-identifiant' => true, 'autofocus' => true ),
					) );
					ueb_champ( array(
						'nom'     => 'mot_de_passe',
						'libelle' => 'Mot de passe',
						'type'    => 'password',
						'icone'   => 'cadenas',
						'attrs'   => array( 'autocomplete' => 'current-password' ),
					) );
					?>
					<details class="acces__oubli">
						<summary>Mot de passe oublié ?</summary>
						<p>Présente-toi à la scolarité de ton établissement avec ta carte d’identité et le téléphone enregistré sur ton compte. Un agent réinitialisera ton mot de passe ; tu en choisiras un nouveau à ta prochaine connexion.</p>
					</details>
					<button class="btn btn--sombre btn--large" type="submit">Se connecter</button>
				</form>
<?php
get_template_part( 'templates/partie', 'auth-fin', array( 'page' => 'connexion' ) );
ueb_page_fin( 'auth' );
