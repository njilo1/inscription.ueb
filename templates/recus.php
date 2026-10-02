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
$ouvert   = ueb_quitus_accepte_recus( $q );
$dossier  = ueb_dossier_du_quitus( $q );
/* Un seul reçu par paiement : dès qu'il est envoyé, le dépôt reste fermé. */
$libre    = (bool) ueb_objets_recu_libres( $q, $recus );
/* Sur cette page, le rappel « À payer » suit l'ordre réel : payer, envoyer le reçu, puis la scolarité. */
$note     = 'genere' === $q->statut ? 'Une fois le paiement effectué, envoie le reçu ici, puis dirige-toi vers la scolarité de ton établissement pour finaliser ton inscription.' : UEB_STATUTS_QUITUS[ $q->statut ]['aide'];

$titre = $ouvert ? ( 'rejete' === $q->statut ? 'Renvoyer mon reçu' : 'Envoyer mon reçu' ) : 'Reçus du quitus';

ueb_page_debut( array( 'titre' => $titre . ' ' . $q->numero, 'variante' => 'espace' ) );
?>
<main id="contenu" class="envoi-recus">
	<section class="espace__bandeau envoi-recus__bandeau">
		<div class="conteneur conteneur--moyen envoi-recus__entete">
			<div>
				<a class="fil fil--clair" href="<?php echo esc_url( add_query_arg( 'vue', 'quitus', ueb_url( 'mon-espace' ) ) ); ?>"><?php echo ueb_icone( 'fleche-g', 16 ); ?>Mes quitus</a>
				<h1><?php echo esc_html( $titre ); ?></h1>
				<p class="espace__etab espace__etab--texte"><?php echo $ouvert ? 'Après ton paiement ' . esc_html( ueb_moyens_paiement() ) . ', envoie une photo ou un scan de ton reçu. La scolarité le compare ensuite à l’original.' : 'Ce paiement est validé : tes reçus restent consultables ici.'; ?></p>
			</div>
			<div class="ticket-quitus" style="--etab: <?php echo esc_attr( $etab['couleur'] ?? '#13351a' ); ?>">
				<span class="ticket-quitus__logo"><img src="<?php echo esc_url( ueb_logo_url( $q->etablissement ) ); ?>" alt="" width="30" height="30"></span>
				<div class="ticket-quitus__ident">
					<p><b class="ticket-quitus__sigle"><?php echo esc_html( $q->etablissement ); ?></b> <span class="ticket-quitus__numero">N° <?php echo esc_html( $q->numero ); ?></span></p>
					<p><?php echo esc_html( ueb_detail_quitus( $q ) ); ?></p>
				</div>
				<p class="ticket-quitus__montant"><?php echo esc_html( ueb_formater_montant( $q->montant ) ); ?> <small>FCFA</small></p>
				<?php echo ueb_badge_statut( $q->statut ); // phpcs:ignore ?>
			</div>
		</div>
		<?php ueb_nuages(); ?>
	</section>

	<div class="conteneur conteneur--moyen envoi-recus__corps">
		<?php ueb_afficher_flash(); ?>
		<?php if ( $dossier && count( $dossier['paiements'] ) > 1 ) : ?>
			<nav class="choix-paiement" aria-label="Choisir le paiement du dossier">
				<p>Ce dossier compte deux paiements : envoie le reçu de chacun séparément.</p>
				<div class="choix-paiement__options">
					<?php foreach ( $dossier['paiements'] as $paiement ) : ?>
						<a class="choix-paiement__bouton" href="<?php echo esc_url( ueb_url( 'mon-espace/recus/' . $paiement->numero ) ); ?>" <?php echo (int) $paiement->id === (int) $q->id ? 'aria-current="page"' : ''; ?>>
							<span class="choix-paiement__rond" aria-hidden="true"></span><?php echo esc_html( ueb_libelle_type_quitus( $paiement->type ) ); ?>
						</a>
					<?php endforeach; ?>
				</div>
			</nav>
		<?php endif; ?>
		<?php if ( 'rejete' === $q->statut && $q->motif_rejet ) : ?>
			<div class="alerte alerte--erreur"><?php echo ueb_icone( 'alerte', 20 ); ?><p><b>Motif de la scolarité :</b> <?php echo esc_html( $q->motif_rejet ); ?> Envoie un nouveau reçu lisible.</p></div>
		<?php elseif ( 'verifie' === $q->statut ) : ?>
			<div class="alerte alerte--succes"><?php echo ueb_icone( 'check', 20 ); ?><p>Paiement vérifié par la scolarité. Ton inscription pour ce paiement est en règle.</p></div>
		<?php endif; ?>

		<div class="recus-grille<?php echo $ouvert ? '' : ' recus-grille--seule'; ?>">
			<?php if ( $ouvert ) : ?>
				<section class="carte envoi-carte" aria-labelledby="envoi-titre">
					<h2 id="envoi-titre" class="envoi-carte__titre">Ton reçu de paiement</h2>
					<?php if ( $libre ) : ?>
						<form method="post" action="<?php echo esc_url( ueb_url( 'mon-espace/recus/' . $q->numero ) ); ?>" enctype="multipart/form-data" data-formulaire data-envoi-recus data-un-seul>
							<?php ueb_champ_csrf(); ?>
							<input type="hidden" name="ueb_action" value="envoyer_recus">
							<input type="hidden" name="numero" value="<?php echo esc_attr( $q->numero ); ?>">
							<label class="depot" data-depot>
								<input type="file" name="recus[]" accept="image/jpeg,image/png,application/pdf" data-max="1" data-max-octets="<?php echo (int) UEB_RECUS_MAX_OCTETS; ?>" aria-describedby="depot-aide">
								<span class="depot__icone"><?php echo ueb_icone( 'fichier', 22, 'depot__icone-libre' ) . ueb_icone( 'cadenas', 20, 'depot__icone-verrou' ); // phpcs:ignore ?></span>
								<span class="depot__texte">
									<span class="depot__titre" data-depot-titre>Glisse-le ici ou clique pour le choisir</span>
									<span class="depot__aide" id="depot-aide">JPG, PNG ou PDF.</span>
								</span>
							</label>
							<div class="depot-camera">
								<span>ou</span>
								<button class="btn btn--fantome btn--petit" type="button" data-camera-ouvrir hidden><?php echo ueb_icone( 'appareil', 18 ); ?>Prendre une photo</button>
								<label class="btn btn--fantome btn--petit depot-camera__natif" data-camera-natif>
									<input type="file" name="recus[]" accept="image/*" capture="environment" data-capture>
									<?php echo ueb_icone( 'appareil', 18 ); ?>Prendre une photo
								</label>
							</div>
							<ul class="depot__apercus" data-apercus aria-live="polite"></ul>
							<p class="champ__erreur" data-depot-erreur hidden></p>
							<button class="btn btn--primaire btn--large" type="submit" disabled data-depot-envoyer><?php echo ueb_icone( 'envoyer', 18 ); ?><span data-depot-libelle>Envoyer mon reçu</span></button>
						</form>
					<?php else : ?>
						<div class="depot est-verrouille" aria-disabled="true">
							<span class="depot__icone"><?php echo ueb_icone( 'cadenas', 20 ); ?></span>
							<span class="depot__texte">
								<span class="depot__titre">Le reçu de ce paiement est envoyé</span>
								<span class="depot__aide">Pour le remplacer, supprime-le dans « Reçus envoyés ».</span>
							</span>
						</div>
					<?php endif; ?>
					<ul class="conseils-photo">
						<li><span><?php echo ueb_icone( 'appareil', 18 ); ?></span>Le reçu entier, à plat et bien éclairé</li>
						<li><span><?php echo ueb_icone( 'tampon', 18 ); ?></span>Le montant et le cachet ou la référence lisibles</li>
						<li><span><?php echo ueb_icone( 'recu', 18 ); ?></span>L’original gardé pour la scolarité</li>
					</ul>
				</section>
			<?php endif; ?>

			<section class="carte envoi-carte" aria-labelledby="envoyes-titre">
				<header class="envoi-carte__entete">
					<h2 id="envoyes-titre" class="envoi-carte__titre">Reçus envoyés</h2>
					<span class="envoi-carte__compteur"><?php echo count( $recus ); ?> sur <?php echo (int) UEB_RECUS_MAX_FICHIERS; ?></span>
				</header>
				<?php if ( ! $recus ) : ?>
					<div class="recus-attente">
						<span class="recus-attente__feuille" aria-hidden="true"><i></i><i></i><i></i></span>
						<p>Rien pour l’instant. Ton reçu apparaîtra ici dès l’envoi, avec son statut.</p>
					</div>
				<?php else : ?>
					<ul class="recus-liste">
						<?php foreach ( $recus as $rang => $r ) :
							$url = ueb_url_recu( $r->id );
							?>
							<li class="recus-liste__item" style="--i: <?php echo (int) $rang; ?>">
								<a class="recus-liste__vignette" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener" aria-label="<?php echo esc_attr( 'Ouvrir ' . $r->nom_original ); ?>">
									<?php if ( 'application/pdf' === $r->type_mime ) : ?>
										<span class="recus-liste__pdf"><?php echo ueb_icone( 'fichier', 26 ); ?>PDF</span>
									<?php else : ?>
										<img src="<?php echo esc_url( $url ); ?>" alt="" loading="lazy">
									<?php endif; ?>
								</a>
								<div class="recus-liste__infos">
									<b title="<?php echo esc_attr( $r->nom_original ); ?>"><?php echo esc_html( $r->nom_original ); ?></b>
									<span class="recus-liste__objet"><?php echo esc_html( ueb_libelle_objet_recu( $r, $q->type ) ); ?></span>
									<span>Envoyé le <?php echo esc_html( mysql2date( 'j F Y à H:i', $r->date_envoi ) ); ?> · <?php echo esc_html( size_format( $r->taille, 1 ) ); ?></span>
								</div>
								<a class="recus-liste__telecharger" href="<?php echo esc_url( ueb_url_recu( $r->id, true ) ); ?>" aria-label="Télécharger le reçu <?php echo esc_attr( $r->nom_original ); ?>" title="Télécharger"><?php echo ueb_icone( 'telecharger', 18 ); ?></a>
								<?php if ( 'verifie' !== $q->statut ) : ?>
									<form method="post" action="<?php echo esc_url( ueb_url( 'mon-espace/recus/' . $q->numero ) ); ?>" data-confirmer="Supprimer ce reçu ? Tu pourras en envoyer un autre.">
										<?php ueb_champ_csrf(); ?>
										<input type="hidden" name="ueb_action" value="supprimer_recu">
										<input type="hidden" name="recu_id" value="<?php echo (int) $r->id; ?>">
										<button class="recus-liste__supprimer" type="submit" aria-label="Supprimer le reçu <?php echo esc_attr( $r->nom_original ); ?>" title="Supprimer"><?php echo ueb_icone( 'corbeille', 18 ); ?></button>
									</form>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<p class="envoi-carte__note"><?php echo ueb_icone( 'horloge', 16 ); ?><?php echo esc_html( $note ); ?></p>
			</section>
		</div>
	</div>

	<?php if ( $ouvert && $libre ) : ?>
		<dialog class="camera" data-camera aria-labelledby="camera-titre">
			<div class="camera__contenu">
				<header class="camera__entete">
					<h2 id="camera-titre">Photographie ton reçu</h2>
					<button class="camera__fermer" type="button" data-camera-fermer aria-label="Fermer la caméra"><?php echo ueb_icone( 'croix', 20 ); ?></button>
				</header>
				<div class="camera__vue">
					<video data-camera-video playsinline muted></video>
					<canvas data-camera-cliche hidden></canvas>
					<span class="camera__cadre" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
					<p class="camera__message" data-camera-message role="status"></p>
				</div>
				<p class="camera__conseil">Place le reçu entier dans le cadre, à plat et bien éclairé : le cachet et le montant doivent se lire.</p>
				<footer class="camera__actions">
					<button class="btn btn--fantome btn--petit" type="button" data-camera-reprendre hidden><?php echo ueb_icone( 'fleche-g', 18 ); ?>Reprendre</button>
					<button class="camera__declencheur" type="button" data-camera-declencher aria-label="Prendre la photo" disabled><span></span></button>
					<button class="btn btn--primaire btn--petit" type="button" data-camera-utiliser hidden><?php echo ueb_icone( 'check', 18 ); ?>Utiliser cette photo</button>
				</footer>
			</div>
		</dialog>
	<?php endif; ?>
</main>
<?php
ueb_page_fin( 'espace' );
