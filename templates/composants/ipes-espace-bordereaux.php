<?php
/**
 * Espace IPES, bordereaux :
 *   - sans « bordereau » : la liste de l'année et la création d'un brouillon ;
 *   - ?bordereau={id}    : la fiche. Modifiable (brouillon ou rejeté) : les
 *     versements à cocher, avec le total en direct, puis l'envoi. Envoyé ou
 *     vérifié : lecture seule, total figé.
 * Attend $ipes, $annee et $ici (page-ipes.php).
 */
defined( 'ABSPATH' ) || exit;

$bordereau_demande = (int) ( $_GET['bordereau'] ?? 0 );
$pastille          = static function ( $sigle ) {
	$e = ueb_etablissement( $sigle );
	return sprintf( '<span class="pastille-etab" style="--etab: %s" title="%s">%s</span>', esc_attr( $e['couleur'] ?? 'var(--vert)' ), esc_attr( $e['fr'] ?? '' ), esc_html( $sigle ) );
};
?>

<?php if ( ! $bordereau_demande ) : ?>

	<?php
	$recents = ueb_ipes_bordereaux( $ipes->id );
	$libre   = ueb_ipes_totaux( $ipes->id )['libre'];
	?>
	<header class="bo-entete">
		<div class="bo-entete__texte">
			<p class="bo-entete__contexte"><span><?php echo esc_html( $ipes->sigle ); ?></span><span class="bo-entete__annee"><?php echo esc_html( $annee['libelle'] ); ?></span></p>
			<h1>Bordereaux</h1>
			<p class="bo-entete__sous-titre">Un bordereau reverse des versements de pension à ta tutelle. Une fois envoyé, il est figé jusqu’à la décision de l’UEb.</p>
		</div>
	</header>
	<?php ueb_afficher_flash(); ?>

	<section class="carte bo-panneau" aria-labelledby="ipes-nouveau-bordereau">
		<header class="bo-panneau__entete">
			<span class="bo-panneau__icone"><?php echo ueb_icone( 'plus', 20 ); ?></span>
			<div><h2 id="ipes-nouveau-bordereau">Nouveau bordereau</h2><p><?php echo esc_html( ueb_fcfa( $libre ) ); ?> de versements ne figurent encore dans aucun bordereau.</p></div>
		</header>
		<form class="formulaire ipes-bordereau-nouveau" method="post" action="<?php echo esc_url( ueb_url_espace_ipes() ); ?>" data-formulaire novalidate>
			<?php ueb_champ_csrf(); ?>
			<input type="hidden" name="ueb_action" value="ipes_bordereau_creer">
			<?php if ( count( $ipes->tutelles ) > 1 ) : ?>
				<?php ueb_champ( array( 'nom' => 'etablissement', 'libelle' => 'Tutelle destinataire', 'type' => 'select', 'icone' => 'ecole', 'options' => array_combine( $ipes->tutelles, array_map( static fn( $s ) => $s . ' — ' . ( ueb_etablissement( $s )['fr'] ?? $s ), $ipes->tutelles ) ) ) ); ?>
			<?php else : ?>
				<p class="texte-discret">Destinataire : <?php echo $pastille( $ipes->tutelles[0] ?? '' ); // phpcs:ignore -- échappé ci-dessus ?> <?php echo esc_html( ueb_etablissement( $ipes->tutelles[0] ?? '' )['fr'] ?? '' ); ?></p>
			<?php endif; ?>
			<button class="btn btn--primaire" type="submit" <?php disabled( $libre <= 0 ); ?>><?php echo ueb_icone( 'plus', 18 ); ?>Créer le brouillon</button>
		</form>
	</section>

	<?php if ( ! $recents ) : ?>
		<div class="bo-vide"><span><?php echo ueb_icone( 'recu', 22 ); ?></span><p>Aucun bordereau cette année.</p></div>
	<?php else : ?>
		<?php include UEB_INSC_DIR . '/templates/composants/ipes-espace-tableau-bordereaux.php'; ?>
	<?php endif; ?>

<?php else : ?>

	<?php $bordereau = ueb_ipes_bordereau( $ipes->id, $bordereau_demande ); ?>
	<a class="fil" href="<?php echo $ici( array( 'vue' => 'bordereaux' ) ); ?>"><?php echo ueb_icone( 'fleche-g', 18 ); ?>Tous les bordereaux</a>

	<?php if ( ! $bordereau ) : ?>

		<header class="bo-entete"><div class="bo-entete__texte"><h1>Bordereau introuvable</h1></div></header>
		<?php ueb_afficher_flash(); ?>
		<div class="bo-vide bo-vide--large"><span><?php echo ueb_icone( 'recu', 24 ); ?></span><p><b>Ce bordereau n’existe pas dans ton IPES.</b> Retrouve-le dans la liste.</p></div>

	<?php else : ?>

		<?php
		$modifiable = in_array( $bordereau->statut, UEB_IPES_BORDEREAU_MODIFIABLE, true );
		$dedans     = ueb_ipes_bordereau_paiements( $ipes->id, $bordereau->id );
		$libres     = $modifiable ? ueb_ipes_paiements_libres( $ipes->id, $bordereau->annee_academique ) : array();
		$numero     = str_starts_with( $bordereau->numero, 'BROUILLON-' ) ? 'Brouillon n° ' . $bordereau->id : $bordereau->numero;
		$lignes     = array_merge( $dedans, $libres );
		usort( $lignes, static fn( $a, $b ) => array( $a->nom, $a->prenom, $a->date_paiement ) <=> array( $b->nom, $b->prenom, $b->date_paiement ) );
		?>
		<header class="bo-entete">
			<div class="bo-entete__texte">
				<p class="bo-entete__contexte"><span><?php echo esc_html( $ipes->sigle ); ?></span><span class="bo-entete__annee"><?php echo esc_html( str_replace( '-', ' – ', $bordereau->annee_academique ) ); ?></span></p>
				<h1><?php echo esc_html( $numero ); ?></h1>
				<p class="bo-entete__sous-titre">Pour <?php echo $pastille( $bordereau->etablissement ); // phpcs:ignore -- échappé ci-dessus ?> · <?php echo ueb_ipes_badge_bordereau( $bordereau->statut ); // phpcs:ignore -- échappé par la fonction ?></p>
			</div>
			<?php if ( 'brouillon' === $bordereau->statut ) : ?>
				<form method="post" action="<?php echo esc_url( ueb_url_espace_ipes() ); ?>" data-confirmer="Supprimer ce brouillon ? Ses versements redeviendront libres.">
					<?php ueb_champ_csrf(); ?>
					<input type="hidden" name="ueb_action" value="ipes_bordereau_supprimer">
					<input type="hidden" name="bordereau_id" value="<?php echo (int) $bordereau->id; ?>">
					<button class="btn btn--fantome" type="submit"><?php echo ueb_icone( 'corbeille', 18 ); ?>Supprimer le brouillon</button>
				</form>
			<?php endif; ?>
		</header>
		<?php ueb_afficher_flash(); ?>

		<?php if ( 'rejete' === $bordereau->statut ) : ?>
			<?php ueb_alerte( 'erreur', 'Rejeté par l’UEb : « ' . $bordereau->motif_rejet . ' » Corrige la sélection puis renvoie le bordereau ; il garde son numéro.' ); ?>
		<?php elseif ( 'envoye' === $bordereau->statut ) : ?>
			<div class="alerte alerte--info" role="status"><?php echo ueb_icone( 'horloge', 20 ); ?><p>Envoyé le <?php echo esc_html( mysql2date( 'd/m/Y à H:i', $bordereau->date_envoi ) ); ?>, en attente de vérification par l’UEb. Il n’est plus modifiable.</p></div>
		<?php elseif ( 'verifie' === $bordereau->statut ) : ?>
			<div class="alerte alerte--succes" role="status"><?php echo ueb_icone( 'check', 20 ); ?><p>Vérifié par l’UEb le <?php echo esc_html( mysql2date( 'd/m/Y', $bordereau->date_verification ) ); ?>.</p></div>
		<?php endif; ?>

		<?php if ( $modifiable ) : ?>

			<form class="carte bo-panneau ipes-bordereau" method="post" action="<?php echo esc_url( ueb_url_espace_ipes() ); ?>" data-total-coches>
				<?php ueb_champ_csrf(); ?>
				<input type="hidden" name="ueb_action" value="ipes_bordereau_enregistrer">
				<input type="hidden" name="bordereau_id" value="<?php echo (int) $bordereau->id; ?>">
				<header class="bo-panneau__entete">
					<span class="bo-panneau__icone"><?php echo ueb_icone( 'check', 20 ); ?></span>
					<div><h2>Versements à reverser</h2><p>Coche les versements de ce bordereau. Ceux d’un autre bordereau n’apparaissent pas.</p></div>
				</header>
				<?php if ( ! $lignes ) : ?>
					<div class="bo-vide"><span><?php echo ueb_icone( 'banque', 22 ); ?></span><p>Aucun versement libre pour cette année. Enregistre d’abord les versements de tes étudiants.</p></div>
				<?php else : ?>
					<div class="tableau-conteneur">
						<table class="tableau">
							<thead><tr>
								<th class="ipes-case"><label class="sr" for="tout-cocher">Tout cocher</label><input id="tout-cocher" type="checkbox" data-tout-cocher></th>
								<th>Étudiant</th><th>Filière</th><th>Date</th><th class="num">Montant</th>
							</tr></thead>
							<tbody>
							<?php foreach ( $lignes as $p ) : ?>
								<tr>
									<td class="ipes-case"><input id="versement-<?php echo (int) $p->id; ?>" type="checkbox" name="paiements[]" value="<?php echo (int) $p->id; ?>" data-montant="<?php echo (int) $p->montant; ?>" <?php checked( (int) $p->bordereau_id, (int) $bordereau->id ); ?>></td>
									<td><label for="versement-<?php echo (int) $p->id; ?>"><b><?php echo esc_html( $p->nom . ' ' . $p->prenom ); ?></b></label><br><small class="texte-discret"><?php echo esc_html( $p->matricule ); ?></small></td>
									<td><?php echo esc_html( $p->filiere ); ?> <small class="texte-discret"><?php echo esc_html( $p->niveau ); ?></small></td>
									<td class="num"><?php echo esc_html( mysql2date( 'd/m/Y', $p->date_paiement ) ); ?></td>
									<td class="num"><b><?php echo esc_html( ueb_fcfa( $p->montant ) ); ?></b></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
				<footer class="ipes-bordereau__pied">
					<p class="ipes-bordereau__total" aria-live="polite">Total : <b data-total-affiche><?php echo esc_html( ueb_fcfa( array_sum( array_map( static fn( $p ) => (int) $p->montant, $dedans ) ) ) ); ?></b> <span class="texte-discret" data-total-nombre><?php echo count( $dedans ); ?> versement<?php echo count( $dedans ) > 1 ? 's' : ''; ?></span></p>
					<div class="ipes-fiche__actions">
						<button class="btn btn--fantome" type="submit"><?php echo ueb_icone( 'check', 18 ); ?>Enregistrer la sélection</button>
						<button class="btn btn--primaire" type="submit" name="envoyer" value="1" data-confirmer="Envoyer ce bordereau à l’UEb ? Il ne sera plus modifiable, sauf s’il est rejeté."><?php echo ueb_icone( 'envoyer', 18 ); ?><?php echo 'rejete' === $bordereau->statut ? 'Renvoyer à l’UEb' : 'Envoyer à l’UEb'; ?></button>
					</div>
				</footer>
			</form>

		<?php else : ?>

			<section class="carte bo-panneau" aria-labelledby="ipes-bordereau-contenu">
				<header class="bo-panneau__entete">
					<span class="bo-panneau__icone"><?php echo ueb_icone( 'recu', 20 ); ?></span>
					<div><h2 id="ipes-bordereau-contenu"><?php echo count( $dedans ); ?> versement<?php echo count( $dedans ) > 1 ? 's' : ''; ?> · <?php echo esc_html( ueb_fcfa( $bordereau->total ) ); ?></h2><p>Total figé à l’envoi, en lettres : <?php echo esc_html( ueb_nombre_en_lettres( (int) $bordereau->total ) ); ?> francs CFA.</p></div>
				</header>
				<div class="tableau-conteneur">
					<table class="tableau">
						<thead><tr><th>Étudiant</th><th>Filière</th><th>Date</th><th class="num">Montant</th></tr></thead>
						<tbody>
						<?php foreach ( $dedans as $p ) : ?>
							<tr>
								<td><b><?php echo esc_html( $p->nom . ' ' . $p->prenom ); ?></b><br><small class="texte-discret"><?php echo esc_html( $p->matricule ); ?></small></td>
								<td><?php echo esc_html( $p->filiere ); ?> <small class="texte-discret"><?php echo esc_html( $p->niveau ); ?></small></td>
								<td class="num"><?php echo esc_html( mysql2date( 'd/m/Y', $p->date_paiement ) ); ?></td>
								<td class="num"><b><?php echo esc_html( ueb_fcfa( $p->montant ) ); ?></b></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</section>

		<?php endif; ?>

	<?php endif; ?>

<?php endif; ?>
