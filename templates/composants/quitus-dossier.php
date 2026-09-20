<?php
/** Une seule carte pour le PDF et les paiements d'un dossier. */
defined( 'ABSPATH' ) || exit;
$q = $dossier['principal'];
$etab = ueb_etablissement( $q->etablissement );
$faites = array( 'genere' => 1, 'rejete' => 2, 'recu_envoye' => 3, 'verifie' => 4 )[ $dossier['statut'] ] ?? 1;
$modifiable = ueb_quitus_modifiable( $q );
?>
<li class="dossier-quitus" id="quitus-<?php echo esc_attr( $q->numero ); ?>" data-dossier>
	<div class="dossier-quitus__entete">
		<img src="<?php echo esc_url( ueb_logo_url( $q->etablissement ) ); ?>" alt="" width="36" height="36">
		<div><h3><?php echo esc_html( $etab['fr'] ?? $q->etablissement ); ?></h3>
			<p>N° <b><?php echo esc_html( $q->numero ); ?></b></p></div>
	</div>
	<dl class="dossier-quitus__infos">
		<div><dt>Filière</dt><dd><?php echo esc_html( $q->departement ?: '—' ); ?></dd></div>
		<div><dt>Niveau</dt><dd><?php echo esc_html( UEB_NIVEAUX_INSCRIPTION[ $q->parcours ] ?? ( $q->parcours ?: '—' ) ); ?></dd></div>
		<div><dt><?php echo 'medicaux' === $q->type ? 'Paiement' : 'Tranche'; ?></dt><dd><?php echo esc_html( 'medicaux' === $q->type ? 'Frais médicaux · paiement unique' : ueb_libelle_tranche( $q->tranche ) ); ?></dd></div>
	</dl>
	<div class="dossier-quitus__bilan">
		<dl class="dossier-quitus__total"><dt>Total du dossier</dt><dd><?php echo esc_html( ueb_formater_montant( $dossier['total'] ) ); ?> <small>FCFA</small></dd></dl>
		<?php echo ueb_badge_statut( $dossier['statut'] ); ?>
	</div>
	<div class="dossier-quitus__actions">
		<a class="btn btn--primaire btn--petit" data-dossier-pdf download href="<?php echo esc_url( ueb_url( 'mon-espace/quitus/' . $q->numero . '/pdf' ) ); ?>"><?php echo ueb_icone( 'telecharger', 17 ); ?>Télécharger · <?php echo (int) $dossier['pages']; ?> page<?php echo $dossier['pages'] > 1 ? 's' : ''; ?></a>
		<?php if ( $modifiable ) : ?>
			<a class="btn btn--fantome btn--petit" data-dossier-modifier href="<?php echo esc_url( add_query_arg( 'id', $q->id, ueb_url( 'mon-espace/quitus' ) ) ); ?>"><?php echo ueb_icone( 'crayon', 17 ); ?>Modifier</a>
		<?php endif; ?>
		<a class="btn btn--fantome btn--petit" data-dossier-recus href="<?php echo esc_url( ueb_url( 'mon-espace/recus/' . $q->numero ) ); ?>"><?php echo ueb_icone( 'envoyer', 17 ); ?><?php echo $dossier['nb_recus'] || 'verifie' === $dossier['statut'] ? 'Mes reçus (' . (int) $dossier['nb_recus'] . ')' : 'Envoyer mon reçu'; ?></a>
	</div>
	<details class="dossier-quitus__details"<?php echo 'rejete' === $dossier['statut'] ? ' open' : ''; ?>>
		<summary>Détails du dossier<?php echo ueb_icone( 'chevron', 16 ); ?></summary>
		<p class="dossier-quitus__date">Créé le <?php echo esc_html( mysql2date( 'j F Y', $q->date_creation ) ); ?></p>
	<ul class="dossier-quitus__paiements" aria-label="Détail des paiements">
		<?php foreach ( $dossier['paiements'] as $paiement ) : ?>
			<li><div><strong><?php echo esc_html( ueb_libelle_type_quitus( $paiement->type ) ); ?></strong><span>N° <?php echo esc_html( $paiement->numero ); ?></span></div>
				<b><?php echo esc_html( ueb_formater_montant( $paiement->montant ) ); ?> FCFA</b>
				<?php echo ueb_badge_statut( $paiement->statut ); ?>
				<?php if ( 'rejete' === $paiement->statut && $paiement->motif_rejet ) : ?><p class="dossier-quitus__motif">Motif : <?php echo esc_html( $paiement->motif_rejet ); ?></p><?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
	<div class="dossier-quitus__suivi">
		<span><?php echo esc_html( UEB_STATUTS_QUITUS[ $dossier['statut'] ]['aide'] ); ?></span>
		<div class="dossier-quitus__jauge" aria-hidden="true">
			<?php for ( $n = 1; $n <= 4; $n++ ) : ?><i class="<?php echo $n <= $faites ? ( 'rejete' === $dossier['statut'] && $n === $faites ? 'est-bloquee' : 'est-faite' ) : ''; ?>"></i><?php endfor; ?>
		</div>
	</div>
	<p class="dossier-quitus__format">Un seul fichier PDF<?php echo $dossier['pages'] > 1 ? ' · fiches CMS incluses' : ''; ?></p>
	<?php if ( ! $modifiable ) : ?><p class="dossier-quitus__verrou"><?php echo $q->annee_academique !== ueb_annee_academique()['code'] ? 'Année archivée : les informations de ce dossier sont conservées.' : 'Les informations sont verrouillées après l’envoi d’un reçu. Pour une correction, contacte la scolarité.'; ?></p><?php endif; ?>
	</details>
</li>
