<?php
/**
 * Fiche d'un établissement (RIB et contacts), ouverte depuis les cartes
 * [data-fiche="SIGLE"] de chaque modèle de landing.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;
?>
<dialog class="fenetre fiche-etab" id="fiche-etab" aria-labelledby="fiche-etab-titre">
	<div class="fiche-etab__bande" data-fiche-bande></div>
	<div class="fenetre__contenu">
		<div class="fiche-etab__tete">
			<img data-fiche-logo src="" alt="" width="64" height="64">
			<div>
				<h2 class="fenetre__titre" id="fiche-etab-titre" data-fiche-nom></h2>
				<p class="fiche-etab__en" data-fiche-en></p>
			</div>
		</div>
		<dl class="fiche">
			<div><dt>Compte CCA Bank</dt><dd><span class="fiche-etab__rib" data-fiche-rib></span> <button type="button" class="btn btn--lien btn--petit" data-copier>Copier</button></dd></div>
			<div><dt>Adresse</dt><dd data-fiche-bp></dd></div>
			<div><dt>Téléphone</dt><dd data-fiche-tel></dd></div>
			<div><dt>Email</dt><dd data-fiche-email></dd></div>
		</dl>
		<form method="dialog" class="fenetre__actions"><button class="btn btn--primaire">Fermer</button></form>
	</div>
</dialog>
<script type="application/json" id="donnees-fiches"><?php echo wp_json_encode( $args['fiches'] ); ?></script>
