<?php
/**
 * Mon compte : bandeau vert avec la carte du compte, puis la fiche de
 * l'étudiant — niveau, identité, contact d'urgence ; l'établissement et la
 * filière, fixés au premier quitus, n'y figurent pas (action enregistrer_profil) — et, en
 * bas, la sécurité en deux colonnes — mot de passe (jauge de solidité) et
 * matricule (formulaire toujours visible) à gauche, bons réflexes à droite
 * (changer_mdp, changer_identifiant).
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

$compte = ueb_compte_courant();
list( $saisie, $erreurs ) = ueb_reprendre_saisie();
$formulaire = $saisie['formulaire'] ?? '';
/* Fiche : la saisie refusée, sinon la fiche enregistrée complétée par le dernier quitus. */
$profil = ueb_profil( $compte->id );
$infos  = 'profil' === $formulaire ? $saisie : array_replace( ueb_valeurs_initiales_quitus( $compte ), $profil );
$erreurs_infos = 'profil' === $formulaire ? $erreurs : array();
/* Initiales pour l'avatar : celles de la fiche, sinon l'identifiant. */
$initiales = ! empty( $infos['nom'] ) ? mb_strtoupper( mb_substr( trim( $infos['prenom'] ?? '' ), 0, 1 ) . mb_substr( trim( $infos['nom'] ), 0, 1 ) ) : mb_strtoupper( mb_substr( ueb_identifiant_compte( $compte ), 0, 2 ) );
$nom_complet = ! empty( $infos['nom'] ) ? trim( mb_convert_case( $infos['prenom'] ?? '', MB_CASE_TITLE ) . ' ' . $infos['nom'] ) : '';

ueb_page_debut( array( 'titre' => 'Mon compte', 'variante' => 'espace' ) );
?>
<main id="contenu" class="securite-page">
	<section class="espace__bandeau securite-bandeau">
		<div class="conteneur conteneur--moyen securite-bandeau__rangee">
			<div>
				<a class="fil fil--clair" href="<?php echo esc_url( ueb_url( 'mon-espace' ) ); ?>"><?php echo ueb_icone( 'fleche-g', 16 ); ?>Mon espace</a>
				<h1>Mon compte</h1>
				<p class="espace__etab espace__etab--texte">Tes informations, reprises sur chacun de tes quitus, puis ton mot de passe et tes identifiants de connexion.</p>
			</div>
			<div class="carte-compte">
				<span class="carte-compte__avatar" aria-hidden="true"><?php echo esc_html( $initiales ); ?></span>
				<div class="carte-compte__ident">
					<?php if ( $nom_complet ) : ?><p class="carte-compte__nom"><?php echo esc_html( $nom_complet ); ?></p><?php endif; ?>
					<p>Matricule <b><?php echo esc_html( ueb_identifiant_compte( $compte ) ); ?></b></p>
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
			<div class="alerte alerte--alerte" role="alert"><?php echo ueb_icone( 'alerte', 20 ); ?><p>Tu utilises un mot de passe provisoire donné par la scolarité. <a href="#mot-de-passe">Choisis ton propre mot de passe</a> pour continuer.</p></div>
		<?php endif; ?>

		<!-- Mes informations -->
		<section class="carte compte-infos" id="informations" aria-labelledby="titre-infos">
			<header class="securite-carte__entete">
				<span class="securite-carte__icone"><?php echo ueb_icone( 'utilisateur', 20 ); ?></span>
				<div>
					<h2 id="titre-infos">Mes informations</h2>
					<p>Elles sont reprises sur chacun de tes quitus. En cas d’erreur, corrige-les ici.</p>
				</div>
			</header>
			<?php if ( ! empty( $erreurs_infos['general'] ) ) : ?>
				<?php ueb_alerte( 'erreur', $erreurs_infos['general'] ); ?>
			<?php endif; ?>
			<form class="formulaire compte-infos__form" method="post" action="<?php echo esc_url( ueb_url( 'mon-espace/compte' ) ); ?>" data-formulaire novalidate
				data-confirmer-ton="enregistrer" data-confirmer-titre="Enregistrer tes informations ?" data-confirmer-bouton="Oui, enregistrer"
				data-confirmer="Elles seront reprises sur tes prochains quitus. Un quitus déjà généré garde les anciennes : pour le mettre à jour, ouvre-le avec « Modifier ».">
				<?php ueb_champ_csrf(); ?>
				<input type="hidden" name="ueb_action" value="enregistrer_profil">
				<div class="compte-infos__groupe">
					<h3>Niveau d’études</h3>
					<?php ueb_champs_profil( array( 'partie' => 'niveau', 'v' => $infos, 'erreurs' => $erreurs_infos ) ); ?>
				</div>
				<div class="compte-infos__groupe">
					<h3>Identité</h3>
					<?php ueb_champs_profil( array( 'partie' => 'identite', 'v' => $infos, 'erreurs' => $erreurs_infos, 'aide_cms' => 'Demandé pour les fiches CMS de la visite médicale.' ) ); ?>
				</div>
				<div class="securite-form__actions">
					<button class="btn btn--primaire" type="submit"><?php echo ueb_icone( 'check', 18 ); ?>Enregistrer mes informations</button>
				</div>
			</form>
		</section>

		<h2 class="compte-rubrique" id="securite">Sécurité du compte</h2>

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
					<form class="formulaire securite-form" method="post" action="<?php echo esc_url( ueb_url( 'mon-espace/compte' ) ); ?>" data-formulaire novalidate>
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
							<p>Tu te connectes avec ton matricule.</p>
						</div>
					</header>
					<ul class="identifiants">
						<li class="<?php echo $compte->matricule ? '' : 'est-vide'; ?>">
							<span>Matricule</span>
							<b><?php echo $compte->matricule ? esc_html( $compte->matricule ) : 'Pas encore enregistré'; ?></b>
						</li>
					</ul>
					<section class="matricule-correction" aria-labelledby="titre-matricule">
						<h3 id="titre-matricule"><?php echo $compte->matricule ? 'Corriger mon matricule' : 'Enregistrer mon matricule'; ?></h3>
						<form class="formulaire securite-form" method="post" action="<?php echo esc_url( ueb_url( 'mon-espace/compte' ) ); ?>" data-formulaire novalidate
							data-confirmer-ton="enregistrer" data-confirmer-titre="<?php echo $compte->matricule ? 'Changer ton matricule ?' : 'Enregistrer ton matricule ?'; ?>" data-confirmer-bouton="Oui, enregistrer"
							data-confirmer="Tu te connecteras ensuite avec ce matricule. Vérifie-le bien : il figure sur tes quitus.">
							<p class="champ__aide">Une fois enregistré, tu te connectes avec ton matricule.</p>
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
					</section>
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
