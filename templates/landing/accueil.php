<?php
/**
 * Page d'accueil : page claire au ton institutionnel. L'animation Remotion
 * du parcours est présentée dans un écran ; les établissements forment un
 * annuaire où le RIB se copie directement.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

$d = $args;
?>
<main id="contenu" class="accueil">
	<section class="acc-hero">
		<div class="conteneur acc-hero__grille">
			<div class="acc-hero__texte">
				<div class="acc-sceau" data-intro>
					<img src="<?php echo esc_url( ueb_logo_url( 'UEB' ) ); ?>" alt="" width="64" height="64">
					<span><b>Université d’Ebolowa</b>The University of Ebolowa</span>
				</div>
				<h1 class="acc-titre">
					<span class="ligne-masque"><span data-intro-ligne>Plateforme officielle</span></span>
					<span class="ligne-masque"><span data-intro-ligne>d’inscription <?php echo esc_html( $d['annee']['libelle'] ); ?></span></span>
				</h1>
				<p class="acc-intro" data-intro>Les étudiants des neuf établissements génèrent ici leur quitus de paiement, puis envoient la photo de leur reçu bancaire avant la vérification à la scolarité.</p>
				<div class="acc-actions" data-intro><?php ueb_landing_actions( $d['compte'], 'btn--fantome' ); ?></div>
			</div>
			<figure class="acc-ecran" data-intro-visuel>
				<div class="acc-ecran__barre" aria-hidden="true"><i></i><i></i><i></i><span>Le parcours en 13 secondes</span></div>
				<?php ueb_animation( 'parcours', ueb_props_parcours(), 'acc-ecran__animation', 'Animation : le formulaire se remplit, le quitus sort avec ses quatre coupons, il est tamponné et payé à la banque, puis le reçu est envoyé et vérifié.' ); ?>
			</figure>
		</div>
	</section>

	<section class="acc-acces" aria-label="Accès rapides">
		<div class="conteneur acc-acces__grille" data-apparition-groupe>
			<a class="acc-acces__carte" href="<?php echo esc_url( ueb_url( $d['compte'] ? 'mon-espace' : 'creer-mon-compte' ) ); ?>">
				<span class="acc-acces__icone"><?php echo ueb_icone( 'utilisateur', 24 ); ?></span>
				<b><?php echo $d['compte'] ? 'Mon espace' : 'Créer mon compte'; ?></b>
				<span>Matricule ou numéro de dossier de préinscription.</span>
			</a>
			<a class="acc-acces__carte" href="<?php echo esc_url( ueb_url( $d['compte'] ? 'mon-espace/quitus' : 'connexion' ) ); ?>">
				<span class="acc-acces__icone"><?php echo ueb_icone( 'fichier', 24 ); ?></span>
				<b>Générer mon quitus</b>
				<span>Quatre coupons en PDF, prêts à imprimer.</span>
			</a>
			<a class="acc-acces__carte" href="<?php echo esc_url( ueb_url( $d['compte'] ? 'mon-espace' : 'connexion' ) ); ?>">
				<span class="acc-acces__icone"><?php echo ueb_icone( 'recu', 24 ); ?></span>
				<b>Envoyer mon reçu</b>
				<span>La photo du reçu de la CCA Bank.</span>
			</a>
			<a class="acc-acces__carte" href="#etablissements">
				<span class="acc-acces__icone"><?php echo ueb_icone( 'banque', 24 ); ?></span>
				<b>Comptes bancaires</b>
				<span>Le RIB de chaque établissement.</span>
			</a>
		</div>
	</section>

	<section class="acc-etapes" id="parcours">
		<div class="conteneur">
			<header class="acc-entete" data-apparition>
				<h2>La procédure d’inscription</h2>
				<p>Quatre étapes, dans cet ordre. Garde les originaux de tes reçus jusqu’à la vérification.</p>
			</header>
			<ol class="acc-frise" data-frise>
				<?php foreach ( $d['etapes'] as $i => $e ) : ?>
					<li class="acc-frise__etape">
						<span class="acc-frise__num" aria-hidden="true"><?php echo (int) $i + 1; ?></span>
						<h3><?php echo esc_html( $e['titre'] ); ?></h3>
						<p><?php echo esc_html( $e['texte'] ); ?></p>
					</li>
				<?php endforeach; ?>
			</ol>
		</div>
	</section>

	<section class="acc-annuaire" id="etablissements">
		<div class="conteneur">
			<header class="acc-entete" data-apparition>
				<h2>Annuaire des établissements</h2>
				<p>Paie toujours sur le compte de ton établissement : il est aussi imprimé en bas de chaque coupon de ton quitus.</p>
			</header>
			<ul class="acc-annuaire__liste" data-apparition-groupe>
				<?php foreach ( $d['fiches'] as $sigle => $f ) : ?>
					<li class="acc-ligne" style="--etab: <?php echo esc_attr( $f['couleur'] ); ?>">
						<img src="<?php echo esc_url( $f['logo'] ); ?>" alt="" width="48" height="48" loading="lazy">
						<div class="acc-ligne__nom"><b><?php echo esc_html( $sigle ); ?></b><span><?php echo esc_html( $f['fr'] ); ?></span></div>
						<span class="acc-ligne__ville"><?php echo ueb_icone( 'lieu', 15 ); ?><?php echo esc_html( $f['ville'] ); ?></span>
						<span class="acc-ligne__rib"><?php echo esc_html( $f['rib'] ); ?></span>
						<button type="button" class="btn btn--fantome btn--petit" data-copier-rib="<?php echo esc_attr( $f['rib'] ); ?>" aria-label="Copier le RIB de <?php echo esc_attr( $sigle ); ?>">Copier</button>
						<button type="button" class="btn btn--lien btn--petit" data-fiche="<?php echo esc_attr( $sigle ); ?>" aria-haspopup="dialog">Contacts</button>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	</section>

	<section class="acc-campus" id="campus">
		<div class="conteneur">
			<div class="acc-triptyque" data-apparition-groupe>
				<figure><img src="<?php echo esc_url( ueb_photo_url( 'campus-ebolowa' ) ); ?>" alt="Bâtiments du campus d’Ebolowa" loading="lazy"><figcaption>Campus d’Ebolowa</figcaption></figure>
				<figure><img src="<?php echo esc_url( ueb_photo_url( 'amphi-cours' ) ); ?>" alt="Cours dans un amphithéâtre de l’université" loading="lazy"><figcaption>En amphithéâtre</figcaption></figure>
				<figure><img src="<?php echo esc_url( ueb_photo_url( 'visite-port' ) ); ?>" alt="Étudiants en sortie pédagogique" loading="lazy"><figcaption>Sortie pédagogique</figcaption></figure>
			</div>
			<p class="acc-campus__texte" data-apparition>Les établissements sont répartis à <?php echo esc_html( $d['villes'] ); ?>. Où que tu étudies, ton inscription passe par cette plateforme.</p>
		</div>
	</section>

	<section class="acc-questions" id="questions">
		<div class="conteneur conteneur--etroit">
			<header class="acc-entete acc-entete--centre" data-apparition>
				<h2>Questions fréquentes</h2>
			</header>
			<?php get_template_part( 'templates/landing/faq', null, $d ); ?>
		</div>
	</section>

	<section class="acc-appel">
		<div class="conteneur acc-appel__bande" data-apparition>
			<div>
				<h2>Inscriptions <?php echo esc_html( $d['annee']['libelle'] ); ?></h2>
				<p>Crée ton compte avec ton matricule ou ton numéro de dossier.</p>
			</div>
			<div class="acc-actions"><?php ueb_landing_actions( $d['compte'] ); ?></div>
		</div>
	</section>
</main>
