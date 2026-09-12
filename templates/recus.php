<?php
/**
 * Reçus bancaires d'un quitus : envoi (photo ou scan) et liste.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

$compte = ueb_compte_courant();
$q      = ueb_quitus_du_compte_par_numero( $compte->id, get_query_var( 'ueb_arg' ) );
if ( ! $q ) {
	ueb_rediriger( ueb_url( 'mon-espace' ) );
}
$etab     = ueb_etablissement( $q->etablissement );
$recus    = ueb_recus_du_quitus( $q->id );
$restants = max( 0, UEB_RECUS_MAX_FICHIERS - count( $recus ) );
$ouvert   = ueb_quitus_accepte_recus( $q );

ueb_page_debut( array( 'titre' => 'Reçus du quitus ' . $q->numero, 'variante' => 'espace' ) );
?>
<main id="contenu" class="page-app">
	<div class="conteneur conteneur--moyen">
		<a class="fil" href="<?php echo esc_url( ueb_url( 'mon-espace' ) ); ?>"><?php echo ueb_icone( 'fleche-g', 18 ); ?>Mes quitus</a>
		<header class="page-app__entete">
			<div>
				<h1>Mes reçus de paiement</h1>
				<p class="page-app__sous-titre">Quitus <b><?php echo esc_html( $q->numero ); ?></b> · <?php echo esc_html( $etab['fr'] ); ?> · <?php echo esc_html( ueb_detail_quitus( $q ) ); ?> ·<?php echo esc_html( ueb_formater_montant( $q->montant ) ); ?> FCFA</p>
			</div>
			<?php echo ueb_badge_statut( $q->statut ); // phpcs:ignore ?>
		</header>

		<?php ueb_afficher_flash(); ?>
		<?php if ( 'rejete' === $q->statut && $q->motif_rejet ) : ?>
			<div class="alerte alerte--erreur"><?php echo ueb_icone( 'alerte', 20 ); ?><p><b>Motif de la scolarité :</b> <?php echo esc_html( $q->motif_rejet ); ?> Envoie un nouveau reçu lisible.</p></div>
		<?php elseif ( 'verifie' === $q->statut ) : ?>
			<div class="alerte alerte--succes"><?php echo ueb_icone( 'check', 20 ); ?><p>Paiement vérifié par la scolarité. Ton inscription pour cette tranche est en règle.</p></div>
		<?php endif; ?>

		<div class="recus-grille">
			<?php if ( $ouvert ) : ?>
				<section class="carte carte__corps">
					<h2 class="section-form__titre">Envoyer un reçu</h2>
					<?php if ( $restants > 0 ) : ?>
						<form method="post" action="<?php echo esc_url( ueb_url( 'mon-espace/recus/' . $q->numero ) ); ?>" enctype="multipart/form-data" data-formulaire data-envoi-recus>
							<?php ueb_champ_csrf(); ?>
							<input type="hidden" name="ueb_action" value="envoyer_recus">
							<input type="hidden" name="numero" value="<?php echo esc_attr( $q->numero ); ?>">
							<label class="depot" data-depot>
								<input type="file" name="recus[]" accept="image/jpeg,image/png,application/pdf" multiple data-max="<?php echo (int) $restants; ?>" data-max-octets="<?php echo (int) UEB_RECUS_MAX_OCTETS; ?>">
								<span class="depot__icone"><?php echo ueb_icone( 'appareil', 30 ); ?></span>
								<span class="depot__titre">Prends en photo ou choisis ton reçu</span>
								<span class="depot__aide">JPG, PNG ou PDF · 5 Mo au plus · <?php echo (int) $restants; ?> fichier(s) encore possible(s)</span>
							</label>
							<ul class="depot__apercus" data-apercus aria-live="polite"></ul>
							<p class="champ__erreur" data-depot-erreur hidden></p>
							<button class="btn btn--primaire btn--large" type="submit" disabled data-depot-envoyer><?php echo ueb_icone( 'envoyer', 18 ); ?>Envoyer</button>
						</form>
					<?php else : ?>
						<p>Tu as atteint la limite de <?php echo (int) UEB_RECUS_MAX_FICHIERS; ?> fichiers. Supprime un reçu pour en envoyer un autre.</p>
					<?php endif; ?>
					<ul class="conseils">
						<li><?php echo ueb_icone( 'check', 16 ); ?>Le reçu entier, à plat, bien éclairé.</li>
						<li><?php echo ueb_icone( 'check', 16 ); ?>Le cachet de la banque et le montant lisibles.</li>
						<li><?php echo ueb_icone( 'check', 16 ); ?>Garde les originaux : la scolarité les vérifiera.</li>
					</ul>
				</section>
			<?php endif; ?>

			<section class="carte carte__corps">
				<h2 class="section-form__titre">Reçus envoyés</h2>
				<?php if ( ! $recus ) : ?>
					<p class="texte-discret">Aucun reçu pour ce quitus.</p>
				<?php else : ?>
					<ul class="recus-liste">
						<?php foreach ( $recus as $r ) :
							$url = ueb_url( 'recu/' . $r->id );
							?>
							<li class="recus-liste__item">
								<a class="recus-liste__vignette" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener">
									<?php if ( 'application/pdf' === $r->type_mime ) : ?>
										<span class="recus-liste__pdf"><?php echo ueb_icone( 'fichier', 28 ); ?>PDF</span>
									<?php else : ?>
										<img src="<?php echo esc_url( $url ); ?>" alt="Reçu envoyé le <?php echo esc_attr( mysql2date( 'j F Y', $r->date_envoi ) ); ?>" loading="lazy">
									<?php endif; ?>
								</a>
								<div class="recus-liste__infos">
									<b><?php echo esc_html( $r->nom_original ); ?></b>
									<span>Envoyé le <?php echo esc_html( mysql2date( 'j F Y à H:i', $r->date_envoi ) ); ?> · <?php echo esc_html( size_format( $r->taille ) ); ?></span>
								</div>
								<?php if ( 'verifie' !== $q->statut ) : ?>
									<form method="post" action="<?php echo esc_url( ueb_url( 'mon-espace/recus/' . $q->numero ) ); ?>" data-confirmer="Supprimer ce reçu ? Tu pourras en envoyer un autre.">
										<?php ueb_champ_csrf(); ?>
										<input type="hidden" name="ueb_action" value="supprimer_recu">
										<input type="hidden" name="recu_id" value="<?php echo (int) $r->id; ?>">
										<button class="btn btn--lien btn--petit" type="submit" aria-label="Supprimer le reçu <?php echo esc_attr( $r->nom_original ); ?>"><?php echo ueb_icone( 'corbeille', 18 ); ?></button>
									</form>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</section>
		</div>
	</div>
</main>
<?php
ueb_page_fin( 'espace' );
