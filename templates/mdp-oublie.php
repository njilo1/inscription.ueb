<?php
/**
 * Mot de passe oublié (/mot-de-passe-oublie/), en deux étapes :
 *   1. le matricule seul, avec la démarche : passer à la scolarité avec sa
 *      carte d'identité, qui réinitialise le compte (valable 1 h) ;
 *   2. si la réinitialisation est active : nouveau mot de passe et
 *      confirmation, puis connexion.
 * Logique : inc/comptes.php (ueb_action_mdp_oublie_verifier / _choisir).
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

/* « Ce n'est pas mon matricule » : on repart de l'étape 1. */
if ( isset( $_GET['recommencer'] ) ) {
	unset( $_SESSION['ueb_reinit'] );
	ueb_rediriger( ueb_url( 'mot-de-passe-oublie' ) );
}

list( $saisie, $erreurs ) = ueb_reprendre_saisie();
$compte = ueb_reinit_en_cours();
ueb_page_debut( array( 'titre' => 'Mot de passe oublié', 'variante' => 'auth' ) );
get_template_part( 'templates/partie', 'auth-debut', array( 'page' => 'mdp-oublie' ) );
?>
			<h1 class="acces__titre" id="acces-titre"><?php echo $compte ? 'Choisis ton nouveau mot de passe' : 'Mot de passe oublié'; ?></h1>

			<?php ueb_afficher_flash(); ?>
			<?php if ( ! empty( $erreurs['general'] ) ) : ?>
				<?php ueb_alerte( 'erreur', $erreurs['general'] ); ?>
			<?php endif; ?>

			<?php if ( $compte ) : ?>

				<p class="acces__intro">Pour le matricule <b><?php echo esc_html( $compte->matricule ); ?></b>. Ce mot de passe remplace l’ancien ; personne d’autre ne le connaîtra, pas même la scolarité.</p>
				<p class="acces__delai"><?php echo ueb_icone( 'horloge', 16 ); ?>Réinitialisation valable jusqu’à <?php echo esc_html( ueb_reinit_heure( ueb_reinit_fin( $compte ) ) ); ?>.</p>

				<form class="formulaire" method="post" action="<?php echo esc_url( ueb_url( 'mot-de-passe-oublie' ) ); ?>" data-formulaire novalidate>
					<?php ueb_champ_csrf(); ?>
					<input type="hidden" name="ueb_action" value="mdp_oublie_choisir">
					<input type="hidden" name="identifiant" value="<?php echo esc_attr( $compte->matricule ); ?>" autocomplete="username">
					<div class="formulaire__rangee">
						<?php
						ueb_champ( array(
							'nom'     => 'mot_de_passe',
							'libelle' => 'Nouveau mot de passe',
							'type'    => 'password',
							'icone'   => 'cadenas',
							'erreur'  => $erreurs['mot_de_passe'] ?? '',
							'attrs'   => array( 'autocomplete' => 'new-password', 'minlength' => 8, 'data-regles-mdp' => 'regles-mdp', 'autofocus' => true ),
						) );
						ueb_champ( array(
							'nom'     => 'confirmation',
							'libelle' => 'Confirme ton mot de passe',
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
					<button class="btn btn--sombre btn--large" type="submit">Enregistrer et me connecter</button>
				</form>
				<p class="acces__bascule"><a href="<?php echo esc_url( add_query_arg( 'recommencer', '1', ueb_url( 'mot-de-passe-oublie' ) ) ); ?>">Ce n’est pas mon matricule</a></p>

			<?php else : ?>

				<p class="acces__intro">Pour des raisons de sécurité, ton mot de passe se réinitialise en personne.</p>
				<ol class="acces__etapes">
					<li><b>Présente-toi à la scolarité de ton établissement</b> avec ta carte d’identité.</li>
					<li><b>Un agent réinitialise ton compte.</b> Il ne voit ni ne choisit aucun mot de passe. La réinitialisation est valable <b>1 heure</b>.</li>
					<li><b>Reviens ici</b>, saisis ton matricule, puis choisis ton nouveau mot de passe. Tu peux le faire tout de suite sur ton téléphone.</li>
				</ol>

				<form class="formulaire" method="post" action="<?php echo esc_url( ueb_url( 'mot-de-passe-oublie' ) ); ?>" data-formulaire novalidate>
					<?php ueb_champ_csrf(); ?>
					<input type="hidden" name="ueb_action" value="mdp_oublie_verifier">
					<?php
					ueb_champ( array(
						'nom'     => 'identifiant',
						'libelle' => 'Matricule',
						'icone'   => 'utilisateur',
						'valeur'  => $saisie['identifiant'] ?? '',
						'erreur'  => $erreurs['identifiant'] ?? '',
						'attrs'   => array( 'autocomplete' => 'username', 'autocapitalize' => 'characters', 'spellcheck' => 'false', 'placeholder' => 'Exemple : 24I0017FS', 'data-identifiant' => true, 'autofocus' => true ),
					) );
					?>
					<button class="btn btn--sombre btn--large" type="submit">Continuer</button>
				</form>

			<?php endif; ?>
<?php
get_template_part( 'templates/partie', 'auth-fin', array( 'page' => 'mdp-oublie' ) );
ueb_page_fin( 'auth' );
