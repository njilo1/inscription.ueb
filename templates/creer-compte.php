<?php
/**
 * Création de compte : identifiant (matricule ou n° de dossier), téléphone,
 * mot de passe et confirmation.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

list( $saisie, $erreurs ) = ueb_reprendre_saisie();
ueb_page_debut( array( 'titre' => 'Créer mon compte', 'variante' => 'auth' ) );
get_template_part( 'templates/partie', 'auth-debut', array( 'page' => 'creer-compte' ) );
?>
			<h1 class="acces__titre" id="acces-titre">Créer ton compte</h1>
			<p class="acces__intro">Ancien étudiant : ton matricule. Nouvel étudiant : ton numéro de dossier.</p>

			<?php ueb_afficher_flash(); ?>
			<?php if ( ! empty( $erreurs['general'] ) ) : ?>
				<?php ueb_alerte( 'erreur', $erreurs['general'] ); ?>
			<?php endif; ?>

			<form class="formulaire" method="post" action="<?php echo esc_url( ueb_url( 'creer-mon-compte' ) ); ?>" data-formulaire novalidate>
				<?php ueb_champ_csrf(); ?>
				<input type="hidden" name="ueb_action" value="creer_compte">
				<?php
				ueb_champ( array(
					'nom'     => 'identifiant',
					'libelle' => 'Matricule ou numéro de dossier',
					'icone'   => 'utilisateur',
					'valeur'  => $saisie['identifiant'] ?? '',
					'erreur'  => $erreurs['identifiant'] ?? '',
					'attrs'   => array( 'autocomplete' => 'username', 'autocapitalize' => 'characters', 'spellcheck' => 'false', 'placeholder' => '24I0017FS ou UEB-2026-000123', 'data-identifiant' => true ),
				) );
				ueb_champ( array(
					'nom'     => 'telephone',
					'libelle' => 'Numéro de téléphone',
					'type'    => 'tel',
					'icone'   => 'telephone',
					'valeur'  => $saisie['telephone'] ?? '',
					'erreur'  => $erreurs['telephone'] ?? '',
					'aide'    => 'La scolarité s’en sert si tu oublies ton mot de passe.',
					'attrs'   => array( 'autocomplete' => 'tel-national', 'inputmode' => 'tel', 'maxlength' => 17, 'placeholder' => '6XX XX XX XX', 'data-telephone' => true ),
				) );
				?>
				<div class="formulaire__rangee">
					<?php
					ueb_champ( array(
						'nom'     => 'mot_de_passe',
						'libelle' => 'Mot de passe',
						'type'    => 'password',
						'icone'   => 'cadenas',
						'erreur'  => $erreurs['mot_de_passe'] ?? '',
						'attrs'   => array( 'autocomplete' => 'new-password', 'minlength' => 8, 'data-regles-mdp' => 'regles-mdp' ),
					) );
					ueb_champ( array(
						'nom'     => 'confirmation',
						'libelle' => 'Confirmation',
						'type'    => 'password',
						'icone'   => 'cadenas',
						'erreur'  => $erreurs['confirmation'] ?? '',
						'attrs'   => array( 'autocomplete' => 'new-password', 'data-confirme' => 'champ-mot_de_passe' ),
					) );
					?>
				</div>
				<ul class="regles-mdp" id="regles-mdp" aria-live="polite">
					<li data-regle="longueur">8 caractères</li>
					<li data-regle="lettre">Une lettre</li>
					<li data-regle="chiffre">Un chiffre</li>
				</ul>
				<button class="btn btn--sombre btn--large" type="submit">Créer mon compte</button>
			</form>
<?php
get_template_part( 'templates/partie', 'auth-fin', array( 'page' => 'creer-compte' ) );
ueb_page_fin( 'auth' );
