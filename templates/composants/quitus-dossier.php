<?php
/**
 * Une ligne de « Mes quitus » : établissement et numéro, formation, total et
 * avancement, puis les trois actions (télécharger, modifier, reçu). Le détail
 * des paiements se déplie en dessous. Attend $dossier (et $rang, facultatif).
 */
defined( 'ABSPATH' ) || exit;
$q = $dossier['principal'];
$etab = ueb_etablissement( $q->etablissement );
$faites = array( 'genere' => 1, 'rejete' => 2, 'recu_envoye' => 3, 'verifie' => 4 )[ $dossier['statut'] ] ?? 1;
$modifiable = ueb_quitus_modifiable( $q );
$recus_libelle = $dossier['nb_recus'] || 'verifie' === $dossier['statut'] ? 'Mes reçus (' . (int) $dossier['nb_recus'] . ')' : ( 'rejete' === $dossier['statut'] ? 'Renvoyer mon reçu' : 'Envoyer mon reçu' );
$formation = array_filter( array(
	$q->departement,
	UEB_NIVEAUX_INSCRIPTION[ $q->parcours ] ?? $q->parcours,
	'medicaux' === $q->type ? 'Frais médicaux' : ueb_libelle_tranche( $q->tranche ),
) );
?>
<li class="ligne-quitus ligne-quitus--<?php echo esc_attr( $dossier['statut'] ); ?>" id="quitus-<?php echo esc_attr( $q->numero ); ?>" style="--etab: <?php echo esc_attr( $etab['couleur'] ?? '#13351a' ); ?>; --i: <?php echo (int) ( $rang ?? 0 ); ?>" data-dossier>
	<div class="ligne-quitus__ident">
		<span class="ligne-quitus__logo"><img src="<?php echo esc_url( ueb_logo_url( $q->etablissement ) ); ?>" alt="" width="34" height="34" loading="lazy"></span>
		<div>
			<h3><span class="ligne-quitus__sigle"><?php echo esc_html( $q->etablissement ); ?></span> <?php echo esc_html( $etab['fr'] ?? '' ); ?></h3>
			<p class="ligne-quitus__numero">N° <b><?php echo esc_html( $q->numero ); ?></b></p>
		</div>
	</div>

	<p class="ligne-quitus__formation"><?php echo esc_html( implode( ' · ', $formation ) ); ?></p>

	<div class="ligne-quitus__bilan">
		<p class="ligne-quitus__montant"><?php echo esc_html( ueb_formater_montant( $dossier['total'] ) ); ?> <small>FCFA</small></p>
		<?php echo ueb_badge_statut( $dossier['statut'] ); ?>
		<div class="ligne-quitus__jauge" role="img" aria-label="<?php echo esc_attr( sprintf( 'Étape %d sur 4', $faites ) ); ?>">
			<?php for ( $n = 1; $n <= 4; $n++ ) : ?><i class="<?php echo $n <= $faites ? ( 'rejete' === $dossier['statut'] && $n === $faites ? 'est-bloquee' : 'est-faite' ) : ''; ?>" style="--n: <?php echo (int) $n; ?>"></i><?php endfor; ?>
		</div>
	</div>

	<div class="ligne-quitus__actions">
		<a class="btn btn--primaire btn--petit" data-dossier-pdf download href="<?php echo esc_url( ueb_url( 'mon-espace/quitus/' . $q->numero . '/pdf' ) ); ?>"><?php echo ueb_icone( 'telecharger', 17 ); ?>Télécharger<span class="ligne-quitus__pages"><?php echo (int) $dossier['pages']; ?> p.</span></a>
		<?php if ( $modifiable ) : ?>
			<a class="btn btn--fantome btn--petit" data-dossier-modifier href="<?php echo esc_url( add_query_arg( 'id', $q->id, ueb_url( 'mon-espace/quitus' ) ) ); ?>"><?php echo ueb_icone( 'crayon', 17 ); ?>Modifier</a>
		<?php else : ?>
			<span class="ligne-quitus__verrou" title="Les informations sont verrouillées après l’envoi d’un reçu."><?php echo ueb_icone( 'cadenas', 16 ); ?>Verrouillé</span>
		<?php endif; ?>
		<a class="btn btn--fantome btn--petit" data-dossier-recus href="<?php echo esc_url( ueb_url( 'mon-espace/recus/' . $q->numero ) ); ?>"><?php echo ueb_icone( $dossier['nb_recus'] ? 'recu' : 'envoyer', 17 ); ?><?php echo esc_html( $recus_libelle ); ?></a>
	</div>

	<details class="ligne-quitus__details"<?php echo 'rejete' === $dossier['statut'] ? ' open' : ''; ?>>
		<summary>Détails du dossier<?php echo ueb_icone( 'chevron', 16 ); ?></summary>
		<div class="ligne-quitus__deplie">
			<p class="ligne-quitus__aide"><?php echo esc_html( UEB_STATUTS_QUITUS[ $dossier['statut'] ]['aide'] ); ?></p>
			<ul class="ligne-quitus__paiements" aria-label="Détail des paiements">
				<?php foreach ( $dossier['paiements'] as $paiement ) : ?>
					<li>
						<div><strong><?php echo esc_html( ueb_libelle_type_quitus( $paiement->type ) ); ?></strong><span>N° <?php echo esc_html( $paiement->numero ); ?></span></div>
						<b><?php echo esc_html( ueb_formater_montant( $paiement->montant ) ); ?> FCFA</b>
						<?php echo ueb_badge_statut( $paiement->statut ); ?>
						<?php if ( 'rejete' === $paiement->statut && $paiement->motif_rejet ) : ?><p class="ligne-quitus__motif">Motif : <?php echo esc_html( $paiement->motif_rejet ); ?></p><?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="ligne-quitus__meta">Créé le <?php echo esc_html( mysql2date( 'j F Y', $q->date_creation ) ); ?> · un seul PDF<?php echo $dossier['pages'] > 1 ? ', fiches CMS incluses' : ''; ?></p>
			<?php if ( ! $modifiable ) : ?><p class="ligne-quitus__meta"><?php echo $q->annee_academique !== ueb_annee_academique()['code'] ? 'Année archivée : les informations de ce dossier sont conservées.' : 'Les informations sont verrouillées après l’envoi d’un reçu. Pour une correction, contacte la scolarité.'; ?></p><?php endif; ?>
		</div>
	</details>
</li>
