<?php
/**
 * Fin commune des pages de connexion et de création de compte : lien vers
 * l'autre page, puis panneau vert (couleur du pied de page) au bord en nuage.
 * $args['page'] vaut « connexion » ou « creer-compte ».
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

$courante = $args['page'] ?? 'connexion';
$panneau  = 'creer-compte' === $courante
	? array( 'titre' => 'Bienvenue !', 'texte' => 'Un seul compte suffit pour faire toute ton inscription en ligne, sans file d’attente.' )
	: array( 'titre' => 'Bon retour !', 'texte' => 'Retrouve ton espace étudiant et reprends ton inscription là où tu l’as laissée.' );
?>
			<p class="acces__bascule">
				<?php if ( 'creer-compte' === $courante ) : ?>
					Déjà un compte ? <a href="<?php echo esc_url( ueb_url( 'connexion' ) ); ?>">Se connecter</a>
				<?php else : ?>
					Pas encore de compte ? <a href="<?php echo esc_url( ueb_url( 'creer-mon-compte' ) ); ?>">Créer mon compte</a>
				<?php endif; ?>
			</p>
		</section>

		<aside class="acces__panneau">
			<svg class="acces__nuage" viewBox="0 0 150 600" preserveAspectRatio="none" aria-hidden="true" focusable="false">
				<path class="acces__nuage-halo" transform="translate(18 0)" d="M0 0H48A30 30 0 0 1 62 52A38 38 0 0 1 40 118A34 34 0 0 1 74 176A42 42 0 0 1 44 252A38 38 0 0 1 16 318A40 40 0 0 1 58 378A40 40 0 0 1 36 448A38 38 0 0 1 70 512A48 48 0 0 1 54 600H0Z"/>
				<path d="M0 0H48A30 30 0 0 1 62 52A38 38 0 0 1 40 118A34 34 0 0 1 74 176A42 42 0 0 1 44 252A38 38 0 0 1 16 318A40 40 0 0 1 58 378A40 40 0 0 1 36 448A38 38 0 0 1 70 512A48 48 0 0 1 54 600H0Z"/>
			</svg>
			<div class="acces__panneau-texte">
				<p class="acces__annee">Inscriptions <?php echo esc_html( ueb_annee_academique()['libelle'] ); ?></p>
				<h2><?php echo esc_html( $panneau['titre'] ); ?></h2>
				<p><?php echo esc_html( $panneau['texte'] ); ?></p>
				<ul>
					<li><?php echo ueb_icone( 'fichier', 16 ); ?>Génère ton quitus de paiement</li>
					<li><?php echo ueb_icone( 'envoyer', 16 ); ?>Envoie tes reçus bancaires</li>
					<li><?php echo ueb_icone( 'bouclier', 16 ); ?>Suis la vérification de ton dossier</li>
				</ul>
			</div>
		</aside>
	</div>
</main>
