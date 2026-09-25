<?php
/**
 * Une ligne de « Mes reçus » : vignette du fichier (format), nom, quitus et
 * date d'envoi, statut du paiement, puis téléchargement de la copie. Un
 * reçu à corriger affiche le motif et le bouton pour en renvoyer un.
 * Attend $recu (ueb_recus_du_compte) et $rang.
 */
defined( 'ABSPATH' ) || exit;
$statut   = $recu->statut_quitus;
$libelle  = array( 'verifie' => 'Validé', 'rejete' => 'À corriger' )[ $statut ] ?? 'En vérification';
$badge    = array( 'verifie' => 'verifie', 'rejete' => 'rejete' )[ $statut ] ?? 'recu_envoye';
$ext      = strtoupper( substr( (string) pathinfo( $recu->nom_original, PATHINFO_EXTENSION ), 0, 4 ) ) ?: 'FICH';
$etab     = ueb_etablissement( $recu->etablissement );
?>
<li class="ligne-recu ligne-recu--<?php echo esc_attr( $statut ); ?>" style="--etab: <?php echo esc_attr( $etab['couleur'] ?? '#13351a' ); ?>; --i: <?php echo (int) ( $rang ?? 0 ); ?>">
	<span class="ligne-recu__fichier<?php echo 'PDF' === $ext ? ' ligne-recu__fichier--pdf' : ''; ?>" aria-hidden="true"><?php echo ueb_icone( 'PDF' === $ext ? 'fichier' : 'appareil', 18 ); ?><b><?php echo esc_html( $ext ); ?></b></span>

	<div class="ligne-recu__corps">
		<p class="ligne-recu__nom" title="<?php echo esc_attr( $recu->nom_original ); ?>"><?php echo esc_html( $recu->nom_original ); ?></p>
		<p class="ligne-recu__meta">
			<span class="ligne-recu__quitus"><img src="<?php echo esc_url( ueb_logo_url( $recu->etablissement ) ); ?>" alt="" width="18" height="18" loading="lazy"><?php echo esc_html( ueb_libelle_objet_recu( $recu, $recu->type_quitus ) ); ?> · <b><?php echo esc_html( $recu->numero ); ?></b></span>
			<span>Envoyé le <?php echo esc_html( mysql2date( 'j F Y à H:i', $recu->date_envoi ) ); ?><?php echo $recu->taille ? ' · ' . esc_html( size_format( (int) $recu->taille, 1 ) ) : ''; ?></span>
		</p>
		<?php if ( 'rejete' === $statut && $recu->motif_rejet ) : ?>
			<p class="ligne-quitus__motif">Motif : <?php echo esc_html( $recu->motif_rejet ); ?></p>
		<?php endif; ?>
	</div>

	<span class="badge badge--<?php echo esc_attr( $badge ); ?>"><i aria-hidden="true"></i><?php echo esc_html( $libelle ); ?></span>

	<div class="ligne-recu__actions">
		<?php if ( 'rejete' === $statut ) : ?>
			<a class="btn btn--primaire btn--petit" href="<?php echo esc_url( ueb_url( 'mon-espace/recus/' . $recu->numero ) ); ?>"><?php echo ueb_icone( 'envoyer', 17 ); ?>Renvoyer</a>
		<?php endif; ?>
		<a class="btn btn--fantome btn--petit" href="<?php echo esc_url( ueb_url_recu( $recu->id ) ); ?>" target="_blank" rel="noopener" aria-label="<?php echo esc_attr( 'Voir ' . $recu->nom_original ); ?>"><?php echo ueb_icone( 'oeil', 17 ); ?>Voir</a>
		<a class="btn btn--fantome btn--petit" href="<?php echo esc_url( ueb_url_recu( $recu->id, true ) ); ?>" aria-label="<?php echo esc_attr( 'Télécharger ' . $recu->nom_original ); ?>"><?php echo ueb_icone( 'telecharger', 17 ); ?>Télécharger</a>
	</div>
</li>
