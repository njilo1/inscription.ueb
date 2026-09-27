<?php
/**
 * Tableau de bordereaux d'un IPES (tableau de bord et onglet Bordereaux).
 * Attend $recents (ueb_ipes_bordereaux) et $ici.
 */
defined( 'ABSPATH' ) || exit;
?>
<div class="tableau-conteneur">
	<table class="tableau">
		<thead><tr><th>Bordereau</th><th>Tutelle</th><th class="num">Montant</th><th>État</th><th><span class="sr">Actions</span></th></tr></thead>
		<tbody>
		<?php foreach ( $recents as $b ) :
			$e = ueb_etablissement( $b->etablissement );
			/* Envoyé : total figé ; brouillon ou rejeté : somme des versements cochés. */
			$montant = in_array( $b->statut, UEB_IPES_BORDEREAU_MODIFIABLE, true ) ? (int) $b->montant_coche : (int) $b->total;
			?>
			<tr>
				<td><b><?php echo esc_html( str_starts_with( $b->numero, 'BROUILLON-' ) ? 'Brouillon n° ' . $b->id : $b->numero ); ?></b><br><small class="texte-discret"><?php echo (int) $b->nb_versements; ?> versement<?php echo (int) $b->nb_versements > 1 ? 's' : ''; ?><?php echo $b->date_envoi ? ' · envoyé le ' . esc_html( mysql2date( 'd/m/Y', $b->date_envoi ) ) : ''; ?></small></td>
				<td><span class="pastille-etab" style="--etab: <?php echo esc_attr( $e['couleur'] ?? 'var(--vert)' ); ?>" title="<?php echo esc_attr( $e['fr'] ?? '' ); ?>"><?php echo esc_html( $b->etablissement ); ?></span></td>
				<td class="num"><?php echo esc_html( ueb_fcfa( $montant ) ); ?></td>
				<td><?php echo ueb_ipes_badge_bordereau( $b->statut ); // phpcs:ignore -- HTML échappé par la fonction ?></td>
				<td class="actions-ligne"><a class="btn btn--lien btn--petit" href="<?php echo $ici( array( 'vue' => 'bordereaux', 'bordereau' => (int) $b->id ) ); ?>">Ouvrir<?php echo ueb_icone( 'fleche', 16 ); ?></a></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</div>
