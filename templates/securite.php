<?php
/**
 * Sécurité du compte : identifiants (enregistrement du matricule),
 * changement du mot de passe, et rappels de prudence.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

$compte = ueb_compte_courant();
list( $saisie, $erreurs ) = ueb_reprendre_saisie();
$formulaire = $saisie['formulaire'] ?? '';

ueb_page_debut( array( 'titre' => 'Sécurité du compte', 'variante' => 'espace' ) );
?>
<main id="contenu" class="page-app">
	<div class="conteneur conteneur--moyen">
		<a class="fil" href="<?php echo esc_url( ueb_url( 'mon-espace' ) ); ?>"><?php echo ueb_icone( 'fleche-g', 18 ); ?>Mon espace</a>
		<header class="page-app__entete">
			<div>
				<h1>Sécurité du compte</h1>
				<p class="page-app__sous-titre">Tes identifiants de connexion et ton mot de passe.</p>
			</div>
		</header>

		<ul class="quitus-contexte">
			<li><?php echo ueb_icone( 'utilisateur', 16 ); ?><?php echo $compte->matricule ? 'Matricule' : 'N° de dossier'; ?> <b><?php echo esc_html( ueb_identifiant_compte( $compte ) ); ?></b></li>
			<li><?php echo ueb_icone( 'telephone', 16 ); ?>Téléphone <b><?php echo esc_html( ueb_formater_telephone( $compte->telephone ) ); ?></b></li>
			<?php if ( $compte->derniere_connexion ) : ?>
				<li><?php echo ueb_icone( 'horloge', 16 ); ?>Dernière connexion <b><?php echo esc_html( mysql2date( 'j F Y à H:i', $compte->derniere_connexion ) ); ?></b></li>
			<?php endif; ?>
		</ul>

		<?php ueb_afficher_flash(); ?>
		<?php if ( $compte->doit_changer_mdp ) : ?>
			<div class="alerte alerte--alerte" role="alert"><?php echo ueb_icone( 'alerte', 20 ); ?><p>Tu utilises un mot de passe provisoire donné par la scolarité. Choisis ton propre mot de passe pour continuer.</p></div>
		<?php endif; ?>

		<div class="securite-pile">
			<!-- Identifiants -->
			<section class="carte section-form" id="identifiant" aria-labelledby="titre-identifiants">
				<header class="section-form__entete">
					<span class="section-form__num"><?php echo ueb_icone( 'utilisateur', 18 ); ?></span>
					<div>
						<h2 id="titre-identifiants">Mes identifiants</h2>
						<p>Tu peux te connecter avec ton matricule ou ton numéro de dossier.</p>
					</div>
				</header>
				<div class="section-form__corps">
					<dl class="fiche">
						<div><dt>Matricule</dt><dd><?php echo $compte->matricule ? esc_html( $compte->matricule ) : '<span class="texte-discret">Pas encore enregistré</span>'; ?></dd></div>
						<?php if ( $compte->numero_dossier ) : ?>
							<div><dt>N° de dossier</dt><dd><?php echo esc_html( $compte->numero_dossier ); ?></dd></div>
						<?php endif; ?>
						<div><dt>Téléphone</dt><dd><?php echo esc_html( ueb_formater_telephone( $compte->telephone ) ); ?></dd></div>
					</dl>
					<p class="champ__aide">Pour changer de numéro de téléphone, adresse-toi à la scolarité de ton établissement.</p>

					<form class="formulaire securite-form" method="post" action="<?php echo esc_url( ueb_url( 'mon-espace/securite' ) ); ?>" data-formulaire novalidate>
						<h3 class="formulaire__titre"><?php echo $compte->matricule ? 'Corriger mon matricule' : 'J’ai reçu mon matricule'; ?></h3>
						<p class="champ__aide">Une fois enregistré, tu te connectes avec ton matricule<?php echo $compte->numero_dossier ? ' (ton numéro de dossier reste valable)' : ''; ?>.</p>
						<?php ueb_champ_csrf(); ?>
						<input type="hidden" name="ueb_action" value="changer_identifiant">
						<?php
						ueb_champ( array(
							'nom'     => 'matricule',
							'libelle' => 'Matricule',
							'icone'   => 'utilisateur',
							'valeur'  => 'identifiant' === $formulaire ? ( $saisie['matricule'] ?? '' ) : '',
							'erreur'  => $erreurs['matricule'] ?? '',
							'attrs'   => array( 'autocapitalize' => 'characters', 'spellcheck' => 'false', 'placeholder' => '24I0017FS', 'autocomplete' => 'off' ),
						) );
						ueb_champ( array(
							'nom'     => 'mdp_confirmation_id',
							'libelle' => 'Ton mot de passe actuel',
							'type'    => 'password',
							'icone'   => 'cadenas',
							'erreur'  => $erreurs['mdp_confirmation_id'] ?? '',
							'attrs'   => array( 'autocomplete' => 'current-password' ),
						) );
						?>
						<div class="securite-form__actions">
							<button class="btn btn--fantome" type="submit"><?php echo ueb_icone( 'check', 18 ); ?>Enregistrer le matricule</button>
						</div>
					</form>
				</div>
			</section>

			<!-- Mot de passe -->
			<section class="carte section-form" id="mot-de-passe" aria-labelledby="titre-mdp">
				<header class="section-form__entete">
					<span class="section-form__num"><?php echo ueb_icone( 'cadenas', 18 ); ?></span>
					<div>
						<h2 id="titre-mdp">Mon mot de passe</h2>
						<p>Change-le dès que tu penses qu’il a été vu : toutes tes autres sessions seront fermées.</p>
					</div>
				</header>
				<div class="section-form__corps">
					<form class="formulaire securite-form" method="post" action="<?php echo esc_url( ueb_url( 'mon-espace/securite' ) ); ?>" data-formulaire novalidate>
						<?php ueb_champ_csrf(); ?>
						<input type="hidden" name="ueb_action" value="changer_mdp">
						<?php
						ueb_champ( array(
							'nom'     => 'mdp_actuel',
							'libelle' => $compte->doit_changer_mdp ? 'Mot de passe provisoire' : 'Mot de passe actuel',
							'type'    => 'password',
							'icone'   => 'cadenas',
							'erreur'  => $erreurs['mdp_actuel'] ?? '',
							'attrs'   => array( 'autocomplete' => 'current-password' ),
						) );
						?>
						<div class="formulaire__rangee">
							<?php
							ueb_champ( array(
								'nom'     => 'mdp_nouveau',
								'libelle' => 'Nouveau mot de passe',
								'type'    => 'password',
								'icone'   => 'cle',
								'erreur'  => $erreurs['mdp_nouveau'] ?? '',
								'attrs'   => array( 'autocomplete' => 'new-password', 'minlength' => 8, 'data-regles-mdp' => 'regles-nouveau' ),
							) );
							ueb_champ( array(
								'nom'     => 'mdp_confirmation',
								'libelle' => 'Confirmation',
								'type'    => 'password',
								'icone'   => 'cle',
								'erreur'  => $erreurs['mdp_confirmation'] ?? '',
								'attrs'   => array( 'autocomplete' => 'new-password', 'data-confirme' => 'champ-mdp_nouveau' ),
							) );
							?>
						</div>
						<ul class="regles-mdp" id="regles-nouveau" aria-live="polite">
							<li data-regle="longueur">8 caractères</li>
							<li data-regle="lettre">Une lettre</li>
							<li data-regle="chiffre">Un chiffre</li>
						</ul>
						<div class="securite-form__actions">
							<button class="btn btn--primaire" type="submit"><?php echo ueb_icone( 'bouclier', 18 ); ?>Changer le mot de passe</button>
						</div>
					</form>
				</div>
			</section>

			<!-- Bons réflexes -->
			<section class="carte section-form" aria-labelledby="titre-conseils">
				<header class="section-form__entete">
					<span class="section-form__num"><?php echo ueb_icone( 'bouclier', 18 ); ?></span>
					<div>
						<h2 id="titre-conseils">Les bons réflexes</h2>
						<p>Ton compte donne accès à tes quitus et à tes reçus de paiement.</p>
					</div>
				</header>
				<div class="section-form__corps">
					<ul class="securite-conseils">
						<li><?php echo ueb_icone( 'cadenas', 18 ); ?><span><b>Garde ton mot de passe pour toi.</b> Ni la scolarité ni un camarade n’ont besoin de le connaître.</span></li>
						<li><?php echo ueb_icone( 'appareil', 18 ); ?><span><b>Sur un ordinateur partagé</b>, déconnecte-toi en fin de session et change ton mot de passe ensuite.</span></li>
						<li><?php echo ueb_icone( 'telephone', 18 ); ?><span><b>Mot de passe oublié ?</b> Présente-toi à la scolarité avec ta carte d’identité et le téléphone enregistré ici.</span></li>
					</ul>
				</div>
			</section>
		</div>
	</div>
</main>
<?php
ueb_page_fin( 'espace' );
