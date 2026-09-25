<?php
/**
 * Carte « Où en est ton inscription », en tête du formulaire du quitus :
 * une frise sobre des quatre étapes (titres seuls), puis l'état du dossier
 * suivi en une phrase. Pas d'action ici : le reçu s'envoie depuis Mes quitus.
 * Attend $parcours = ueb_parcours_inscription().
 */
defined( 'ABSPATH' ) || exit;

$focus    = $parcours['focus'];
$en_cours = $parcours['en_cours'];
$bloque   = $focus && 'rejete' === $focus->statut;
/* Remplissage du rail : 0 à l'étape 1, 1 quand les quatre sont faites. */
$rempli   = min( 1, max( 0, ( $en_cours - 1 ) / 3 ) );
?>
<section class="parcours" aria-labelledby="parcours-titre" style="--rempli: <?php echo esc_attr( $rempli ); ?>">
	<header class="parcours__entete">
		<h2 id="parcours-titre">Où en est ton inscription</h2>
		<p class="parcours__compteur<?php echo $bloque ? ' est-bloquee' : ''; ?>"><?php echo $en_cours > 4 ? 'Parcours terminé' : sprintf( 'Étape %d sur 4', (int) $en_cours ); ?></p>
	</header>

	<ol class="parcours__frise" aria-label="Les quatre étapes de ton inscription">
		<?php foreach ( $parcours['etapes'] as $n => $e ) :
			$numero = $n + 1;
			$etat   = $numero < $en_cours ? 'est-faite' : ( $numero === $en_cours ? 'est-en-cours' . ( $bloque ? ' est-bloquee' : '' ) : '' );
			?>
			<li class="<?php echo esc_attr( $etat ); ?>" style="--i: <?php echo (int) $n; ?>"<?php echo $numero === $en_cours ? ' aria-current="step"' : ''; ?>>
				<span class="parcours__puce" aria-hidden="true"><?php echo $numero < $en_cours ? ueb_icone( 'check', 14 ) : ( $numero === $en_cours && $bloque ? '!' : (int) $numero ); // phpcs:ignore -- SVG interne ?></span>
				<span class="parcours__titre"><?php echo esc_html( $e['titre'] ); ?><span class="sr"><?php echo $numero < $en_cours ? ' : fait' : ( $numero === $en_cours ? ' : en cours' : '' ); ?></span></span>
			</li>
		<?php endforeach; ?>
	</ol>

	<div class="parcours__suite parcours__suite--<?php echo esc_attr( $focus->statut ?? 'aucun' ); ?>">
		<span class="parcours__etat" aria-hidden="true"><?php echo ueb_icone( array( 'genere' => 'tampon', 'rejete' => 'alerte', 'recu_envoye' => 'horloge', 'verifie' => 'check' )[ $focus->statut ?? '' ] ?? 'fichier', 20 ); ?></span>
		<div class="parcours__texte">
			<p class="parcours__prochaine"><?php echo esc_html( $parcours['prochaine']['titre'] ); ?></p>
			<p class="parcours__consigne"><?php echo esc_html( $parcours['prochaine']['texte'] ); ?></p>
			<?php if ( $bloque && $focus->motif_rejet ) : ?>
				<p class="parcours__motif"><?php echo ueb_icone( 'alerte', 16 ); ?><span><b>Motif :</b> <?php echo esc_html( $focus->motif_rejet ); ?></span></p>
			<?php endif; ?>
		</div>
		<?php if ( $focus ) : ?>
			<p class="parcours__dossier">
				<img src="<?php echo esc_url( ueb_logo_url( $focus->etablissement ) ); ?>" alt="" width="20" height="20">
				<span>Quitus <b><?php echo esc_html( $focus->numero ); ?></b><br><?php echo esc_html( ueb_formater_montant( $focus->montant ) ); ?> FCFA</span>
			</p>
		<?php endif; ?>
	</div>
</section>
