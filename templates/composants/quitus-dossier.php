<?php
/**
 * Une ligne de « Mes quitus » : établissement et numéro, formation, total et
 * avancement, puis les trois actions (télécharger, modifier, reçu). Le détail
 * des paiements se déplie en dessous. Attend $dossier (et $rang, facultatif).
 */
defined( 'ABSPATH' ) || exit;
$q = $dossier['principal'];
$etab = ueb_etablissement( $q->etablissement );
/* Étapes : généré, payé et reçu envoyé, reçu tamponné à la scolarité, vérifié. */
$faites = array( 'genere' => 1, 'rejete' => 2, 'recu_envoye' => 2, 'verifie' => 4 )[ $dossier['statut'] ] ?? 1;
$modifiable = ueb_quitus_modifiable( $q );
/* Après l'envoi : seules la filière et le niveau se corrigent, tant que rien n'est vérifié. */
$corrigeable = ! $modifiable && ueb_quitus_parcours_modifiable( $q );
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
			<span class="ligne-quitus__verrou" title="<?php echo esc_attr( $corrigeable ? 'Après l’envoi d’un reçu, seules la filière et le niveau se corrigent (détails du dossier).' : 'Les informations sont verrouillées.' ); ?>"><?php echo ueb_icone( 'cadenas', 16 ); ?>Verrouillé</span>
		<?php endif; ?>
		<a class="btn btn--fantome btn--petit" data-dossier-recus href="<?php echo esc_url( ueb_url( 'mon-espace/recus/' . $q->numero ) ); ?>"><?php echo ueb_icone( $dossier['nb_recus'] ? 'recu' : 'envoyer', 17 ); ?><?php echo esc_html( $recus_libelle ); ?></a>
	</div>

	<details class="ligne-quitus__details"<?php echo 'rejete' === $dossier['statut'] ? ' open' : ''; ?>>
		<summary>Détails du dossier<?php echo ueb_icone( 'chevron', 16 ); ?></summary>
		<div class="ligne-quitus__deplie">
			<p class="ligne-quitus__aide"><?php echo esc_html( ueb_aide_statut( $dossier['statut'], array_map( static fn( $p ) => $p->type, array_filter( $dossier['paiements'], static fn( $p ) => $p->statut === $dossier['statut'] ) ) ) ); ?></p>
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
			<?php if ( $q->corrige_le ) : ?><p class="ligne-quitus__meta"><?php echo esc_html( 'Filière ou niveau corrigé le ' . mysql2date( 'j F Y', $q->corrige_le ) . ' (' . lcfirst( (string) $q->correction ) . ').' ); ?></p><?php endif; ?>
			<?php if ( $corrigeable ) : ?>
				<form class="ligne-quitus__correction" method="post" action="<?php echo esc_url( ueb_url( 'mon-espace' ) ); ?>" data-confirmer="Corriger la filière et le niveau de ce dossier ? La scolarité verra la correction.">
					<?php ueb_champ_csrf(); ?>
					<input type="hidden" name="ueb_action" value="corriger_parcours">
					<input type="hidden" name="numero" value="<?php echo esc_attr( $q->numero ); ?>">
					<p class="ligne-quitus__correction-titre"><?php echo ueb_icone( 'crayon', 16 ); ?>Corriger ma filière ou mon niveau</p>
					<?php
					ueb_champ( array( 'nom' => 'parcours', 'id' => 'correction-niveau-' . $q->id, 'libelle' => 'Niveau', 'type' => 'select', 'options' => UEB_NIVEAUX_INSCRIPTION, 'valeur' => $q->parcours ) );
					ueb_champ( array( 'nom' => 'filiere_id', 'id' => 'correction-filiere-' . $q->id, 'libelle' => 'Filière', 'type' => 'select', 'options' => array_map( static fn( $f ) => $f->libelle, ueb_filieres_correction( $q ) ), 'valeur' => (string) $q->filiere_id, 'aide' => 'Filières de ' . $q->etablissement . ' du même type, ouvertes à ton niveau : le montant ne change pas.' ) );
					?>
					<button class="btn btn--fantome btn--petit" type="submit"><?php echo ueb_icone( 'check', 16 ); ?>Enregistrer la correction</button>
				</form>
			<?php elseif ( ! $modifiable ) : ?><p class="ligne-quitus__meta"><?php echo $q->annee_academique !== ueb_annee_academique()['code'] ? 'Année archivée : les informations de ce dossier sont conservées.' : 'Dossier vérifié par la scolarité : ses informations sont définitives.'; ?></p><?php endif; ?>
		</div>
	</details>
</li>
