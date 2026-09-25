<?php
/**
 * Sécurité du compte : bandeau vert avec la carte du compte, puis deux
 * colonnes — mot de passe (jauge de solidité) et matricule à gauche, bons
 * réflexes à droite. Actions POST inchangées : changer_mdp, changer_identifiant.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

$compte = ueb_compte_courant();
list( $saisie, $erreurs ) = ueb_reprendre_saisie();
$formulaire = $saisie['formulaire'] ?? '';
/* Initiales pour l'avatar : celles du dernier quitus, sinon l'identifiant. */
$dernier  = ueb_quitus_du_compte( $compte->id )[0] ?? null;
$initiales = $dernier ? mb_strtoupper( mb_substr( trim( $dernier->prenom ), 0, 1 ) . mb_substr( trim( $dernier->nom ), 0, 1 ) ) : mb_strtoupper( mb_substr( ueb_identifiant_compte( $compte ), 0, 2 ) );
$nom_complet = $dernier ? trim( mb_convert_case( $dernier->prenom, MB_CASE_TITLE ) . ' ' . $dernier->nom ) : '';
/* Le matricule se corrige à la demande ; on l'ouvre d'office s'il manque ou si sa saisie a échoué. */
$matricule_ouvert = ! $compte->matricule || 'identifiant' === $formulaire;

ueb_page_debut( array( 'titre' => 'Sécurité du compte', 'variante' => 'espace' ) );
?>
<main id="contenu" class="securite-page">
	<section class="espace__bandeau securite-bandeau">
		<div class="conteneur conteneur--moyen securite-bandeau__rangee">
			<div>
				<a class="fil fil--clair" href="<?php echo esc_url( ueb_url( 'mon-espace' ) ); ?>"><?php echo ueb_icone( 'fleche-g', 16 ); ?>Mon espace</a>
				<h1>Sécurité du compte</h1>
				<p class="espace__etab espace__etab--texte">Ton mot de passe et tes identifiants de connexion. Ce compte donne accès à tes quitus et à tes reçus.</p>
			</div>
			<div class="carte-compte">
				<span class="carte-compte__avatar" aria-hidden="true"><?php echo esc_html( $initiales ); ?></span>
				<div class="carte-compte__ident">
					<?php if ( $nom_complet ) : ?><p class="carte-compte__nom"><?php echo esc_html( $nom_complet ); ?></p><?php endif; ?>
					<p><?php echo $compte->matricule ? 'Matricule' : 'N° de dossier'; ?> <b><?php echo esc_html( ueb_identifiant_compte( $compte ) ); ?></b></p>
				</div>
				<dl class="carte-compte__infos">
					<div><dt><?php echo ueb_icone( 'telephone', 15 ); ?>Téléphone</dt><dd><?php echo esc_html( ueb_formater_telephone( $compte->telephone ) ); ?></dd></div>
					<?php if ( $compte->derniere_connexion ) : ?>
						<div><dt><?php echo ueb_icone( 'horloge', 15 ); ?>Dernière connexion</dt><dd><?php echo esc_html( mysql2date( 'j F Y à H:i', $compte->derniere_connexion ) ); ?></dd></div>
					<?php endif; ?>
				</dl>
			</div>
		</div>
		<?php ueb_nuages(); ?>
	</section>

	<div class="conteneur conteneur--moyen securite-corps">
		<?php ueb_afficher_flash(); ?>
		<?php if ( $compte->doit_changer_mdp ) : ?>
			<div class="alerte alerte--alerte" role="alert"><?php echo ueb_icone( 'alerte', 20 ); ?><p>Tu utilises un mot de passe provisoire donné par la scolarité. Choisis ton propre mot de passe pour continuer.</p></div>
		<?php endif; ?>

		<div class="securite-grille">
			<div class="securite-pile">
				<!-- Mot de passe -->
				<section class="carte securite-carte" id="mot-de-passe" aria-labelledby="titre-mdp">
					<header class="securite-carte__entete">
						<span class="securite-carte__icone"><?php echo ueb_icone( 'cadenas', 20 ); ?></span>
						<div>
							<h2 id="titre-mdp">Mon mot de passe</h2>
							<p>Change-le dès que tu penses qu’il a été vu : tes autres sessions seront fermées.</p>
						</div>
					</header>
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
						<div class="force-mdp" data-force-mdp="champ-mdp_nouveau" data-niveau="0">
							<div class="force-mdp__jauge" aria-hidden="true"><i></i><i></i><i></i><i></i></div>
							<p class="force-mdp__libelle" aria-live="polite">Solidité : <b data-force-libelle>à saisir</b></p>
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
				</section>

				<!-- Matricule -->
				<section class="carte securite-carte" id="identifiant" aria-labelledby="titre-identifiants">
					<header class="securite-carte__entete">
						<span class="securite-carte__icone"><?php echo ueb_icone( 'utilisateur', 20 ); ?></span>
						<div>
							<h2 id="titre-identifiants">Mes identifiants</h2>
							<p>Tu te connectes avec ton matricule<?php echo $compte->numero_dossier ? ' ou ton numéro de dossier' : ''; ?>.</p>
						</div>
					</header>
					<ul class="identifiants">
						<li class="<?php echo $compte->matricule ? '' : 'est-vide'; ?>">
							<span>Matricule</span>
							<b><?php echo $compte->matricule ? esc_html( $compte->matricule ) : 'Pas encore enregistré'; ?></b>
						</li>
						<?php if ( $compte->numero_dossier ) : ?>
							<li><span>N° de dossier</span><b><?php echo esc_html( $compte->numero_dossier ); ?></b></li>
						<?php endif; ?>
					</ul>
					<details class="securite-depli"<?php echo $matricule_ouvert ? ' open' : ''; ?>>
						<summary><?php echo $compte->matricule ? 'Corriger mon matricule' : 'J’ai reçu mon matricule : l’enregistrer'; ?><?php echo ueb_icone( 'chevron', 16 ); ?></summary>
						<form class="formulaire securite-form" method="post" action="<?php echo esc_url( ueb_url( 'mon-espace/securite' ) ); ?>" data-formulaire novalidate>
							<p class="champ__aide">Une fois enregistré, tu te connectes avec ton matricule<?php echo $compte->numero_dossier ? ' (ton numéro de dossier reste valable)' : ''; ?>.</p>
							<?php ueb_champ_csrf(); ?>
							<input type="hidden" name="ueb_action" value="changer_identifiant">
							<div class="formulaire__rangee">
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
							</div>
							<div class="securite-form__actions">
								<button class="btn btn--fantome" type="submit"><?php echo ueb_icone( 'check', 18 ); ?>Enregistrer le matricule</button>
							</div>
						</form>
					</details>
					<p class="securite-carte__note"><?php echo ueb_icone( 'telephone', 15 ); ?>Pour changer de numéro de téléphone, adresse-toi à la scolarité de ton établissement.</p>
				</section>
			</div>

			<!-- Bons réflexes -->
			<aside class="carte reflexes" aria-labelledby="titre-conseils">
				<span class="reflexes__icone" aria-hidden="true"><?php echo ueb_icone( 'bouclier', 22 ); ?></span>
				<h2 id="titre-conseils">Les bons réflexes</h2>
				<ul class="reflexes__liste">
					<li><?php echo ueb_icone( 'cadenas', 17 ); ?><span><b>Garde ton mot de passe pour toi.</b> Ni la scolarité ni un camarade n’ont besoin de le connaître.</span></li>
					<li><?php echo ueb_icone( 'sortie', 17 ); ?><span><b>Sur un ordinateur partagé</b>, déconnecte-toi en fin de session, puis change ton mot de passe.</span></li>
					<li><?php echo ueb_icone( 'cle', 17 ); ?><span><b>Un mot de passe par site.</b> Ne réutilise pas celui de ton email ou de tes réseaux sociaux.</span></li>
				</ul>
				<div class="reflexes__oubli">
					<p><b>Mot de passe oublié ?</b> Présente-toi à la cellule informatique de ton établissement avec ta carte d’identité et le téléphone enregistré ici.</p>
				</div>
			</aside>
		</div>
	</div>
</main>
<?php
ueb_page_fin( 'espace' );
