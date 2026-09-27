<?php
/**
 * Espace scolarité, vue « IPES » : les IPES sous la tutelle des
 * établissements consultés (inc/ipes-tutelle.php), en lecture seule.
 *   - sans « ipes »       : la liste, filtrable en direct ;
 *   - ?ipes={id}          : la fiche (chiffres, étudiants, bordereaux) ;
 *   - &etudiant={id}      : les versements d'un étudiant.
 * La décision sur un bordereau (« ueb_verifier_ipes ») s'ajoute dans le bloc
 * Bordereaux. Attend $ici et $annee (page-scolarite.php).
 */
defined( 'ABSPATH' ) || exit;

$ipes_demande = (int) ( $_GET['ipes'] ?? 0 );
$pastilles    = static function ( array $sigles ) {
	foreach ( $sigles as $sigle ) {
		$e = ueb_etablissement( $sigle );
		printf( '<span class="pastille-etab" style="--etab: %s" title="%s">%s</span> ', esc_attr( $e['couleur'] ?? 'var(--vert)' ), esc_attr( $e['fr'] ?? '' ), esc_html( $sigle ) );
	}
};
$logo_ipes = static function ( $ipes, $classe = '' ) {
	$logo = ueb_ipes_logo_url( $ipes );
	echo '<span class="ipes-logo ' . esc_attr( $classe ) . '" aria-hidden="true">';
	echo $logo ? '<img src="' . esc_url( $logo ) . '" alt="" width="26" height="26" loading="lazy">' : ueb_icone( 'ecole', 18 ); // phpcs:ignore -- échappé
	echo '</span>';
};
?>

<?php if ( ! $ipes_demande ) : ?>

	<?php
	$recherche = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );
	$liste     = ueb_ipes_sous_tutelle( array( 'recherche' => $recherche ) );
	?>
	<form class="filtres carte" method="get" action="<?php echo esc_url( ueb_url_scolarite() ); ?>" role="search" data-filtres-direct="scolarite-ipes-resultats">
		<input type="hidden" name="vue" value="ipes">
		<div class="champ">
			<label for="sco-ipes-q">Rechercher</label>
			<input id="sco-ipes-q" type="search" name="q" value="<?php echo esc_attr( $recherche ); ?>" placeholder="Sigle ou nom de l’IPES" enterkeyhint="search" autocomplete="off">
		</div>
		<button class="btn btn--primaire" type="submit" data-filtres-bouton><?php echo ueb_icone( 'loupe', 18 ); ?>Rechercher</button>
	</form>

	<div id="scolarite-ipes-resultats" class="ipes-resultats">
		<p class="texte-discret ipes-resultats__nombre" role="status"><?php echo esc_html( count( $liste ) . ' IPES' . ( '' !== $recherche ? ' correspondant' . ( count( $liste ) > 1 ? 's' : '' ) . ' à la recherche' : ' sous tutelle' ) ); ?></p>
		<div class="tableau-conteneur">
			<table class="tableau ipes-tableau">
				<thead><tr><th>IPES</th><th>Tutelle</th><th class="num">Étudiants</th><th class="num">Encaissé</th><th class="num">Vérifié</th><th><span class="sr">Actions</span></th></tr></thead>
				<tbody>
				<?php if ( ! $liste ) : ?>
					<tr><td colspan="6" class="texte-discret"><?php echo '' !== $recherche ? 'Aucun IPES ne correspond à cette recherche.' : 'Aucun IPES n’est placé sous la tutelle de ton établissement.'; ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $liste as $ipes ) :
					$totaux     = ueb_ipes_totaux( $ipes->id );
					$jauge      = ueb_ipes_jauge( $ipes->id );
					$a_verifier = count( array_filter( ueb_ipes_bordereaux_pour_tutelle( $ipes->id ), static fn( $b ) => 'envoye' === $b->statut ) );
					?>
					<tr>
						<td>
							<span class="ipes-nom"><?php $logo_ipes( $ipes ); ?><span><b><?php echo esc_html( $ipes->sigle ); ?></b><br><small class="texte-discret"><?php echo esc_html( $ipes->nom_fr ); ?></small></span></span>
						</td>
						<td>
							<?php $pastilles( $ipes->tutelles ); ?>
							<?php if ( ! (int) $ipes->actif ) : ?><br><span class="badge badge--rejete"><i></i>Désactivé</span><?php endif; ?>
							<?php if ( $a_verifier ) : ?><br><a class="badge badge--recu_envoye" href="<?php echo $ici( array( 'vue' => 'ipes', 'ipes' => (int) $ipes->id ) ); ?>#bordereaux"><i></i><?php echo (int) $a_verifier . ' à vérifier'; ?></a><?php endif; ?>
						</td>
						<td class="num"><?php echo (int) $totaux['etudiants']; ?></td>
						<td class="num"><?php echo esc_html( ueb_fcfa( $totaux['encaisse'] ) ); ?></td>
						<td class="num"><?php echo esc_html( ueb_fcfa( $jauge['verifie'] ) ); ?></td>
						<td class="actions-ligne"><a class="btn btn--lien btn--petit" href="<?php echo $ici( array( 'vue' => 'ipes', 'ipes' => (int) $ipes->id ) ); ?>">Ouvrir<?php echo ueb_icone( 'fleche', 16 ); ?></a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>

<?php else : ?>

	<?php $ipes = ueb_ipes_sous_tutelle_par_id( $ipes_demande ); ?>
	<a class="fil" href="<?php echo $ici( array( 'vue' => 'ipes' ) ); ?>"><?php echo ueb_icone( 'fleche-g', 18 ); ?>Tous les IPES</a>

	<?php if ( ! $ipes ) : ?>

		<div class="bo-vide bo-vide--large"><span><?php echo ueb_icone( 'ecole', 24 ); ?></span><p><b>Cet IPES n’est pas sous la tutelle de ton établissement.</b> Retrouve les tiens dans la liste.</p></div>

	<?php elseif ( isset( $_GET['etudiant'] ) ) : ?>

		<?php
		$etudiant   = ueb_ipes_etudiant( $ipes->id, (int) $_GET['etudiant'] );
		$versements = $etudiant ? ueb_ipes_paiements_etudiant( $ipes->id, $etudiant->id ) : array();
		?>
		<?php if ( ! $etudiant ) : ?>
			<div class="bo-vide bo-vide--large"><span><?php echo ueb_icone( 'utilisateur', 24 ); ?></span><p><b>Cet étudiant n’existe pas dans cet IPES.</b></p></div>
		<?php else : ?>
			<section class="carte bo-panneau" aria-labelledby="sco-etudiant-titre">
				<header class="bo-panneau__entete">
					<span class="bo-panneau__icone"><?php echo ueb_icone( 'utilisateur', 20 ); ?></span>
					<div>
						<h2 id="sco-etudiant-titre"><?php echo esc_html( $etudiant->nom . ' ' . $etudiant->prenom ); ?></h2>
						<p><?php echo esc_html( $etudiant->matricule . ' · ' . ( ueb_ipes_filiere( $etudiant->filiere_id )->libelle ?? '' ) . ' · ' . $etudiant->niveau . ' · ' . $ipes->sigle . ' · ' . str_replace( '-', ' – ', $etudiant->annee_academique ) ); ?></p>
					</div>
				</header>
				<?php if ( ! $versements ) : ?>
					<div class="bo-vide"><span><?php echo ueb_icone( 'banque', 22 ); ?></span><p>Aucun versement enregistré par l’IPES.</p></div>
				<?php else : ?>
					<div class="tableau-conteneur">
						<table class="tableau">
							<thead><tr><th>Date</th><th class="num">Montant</th><th>Reversement</th></tr></thead>
							<tbody>
							<?php foreach ( $versements as $v ) : $b = $v->bordereau_id ? ueb_ipes_bordereau( $ipes->id, $v->bordereau_id ) : null; ?>
								<tr>
									<td class="num"><?php echo esc_html( mysql2date( 'd/m/Y', $v->date_paiement ) ); ?></td>
									<td class="num"><b><?php echo esc_html( ueb_fcfa( $v->montant ) ); ?></b></td>
									<td><?php echo $b && ueb_ipes_bordereau_a_pdf( $b ) ? esc_html( $b->numero ) . ' ' . ueb_ipes_badge_bordereau( $b->statut ) : '<span class="texte-discret">Pas encore reversé</span>'; // phpcs:ignore -- échappé ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</section>
		<?php endif; ?>

	<?php else : ?>

		<?php
		$totaux     = ueb_ipes_totaux( $ipes->id );
		$jauge      = ueb_ipes_jauge( $ipes->id );
		$bordereaux = ueb_ipes_bordereaux_pour_tutelle( $ipes->id );
		$recherche  = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );
		$etudiants  = ueb_ipes_etudiants( $ipes->id, array( 'recherche' => $recherche ) );
		?>
		<section class="carte bo-panneau" aria-labelledby="sco-ipes-titre">
			<header class="bo-panneau__entete">
				<?php $logo_ipes( $ipes, 'ipes-logo--grand' ); ?>
				<div>
					<h2 id="sco-ipes-titre"><?php echo esc_html( $ipes->sigle ); ?> <?php echo (int) $ipes->actif ? '' : '<span class="badge badge--rejete"><i></i>Désactivé</span>'; ?></h2>
					<p><?php echo esc_html( $ipes->nom_fr . ( $ipes->ville ? ' · ' . $ipes->ville : '' ) . ( $ipes->convention_ref ? ' · convention ' . $ipes->convention_ref : '' ) ); ?></p>
					<p><?php $pastilles( $ipes->tutelles ); ?></p>
				</div>
			</header>
		</section>

		<div class="bo-tete">
			<?php ueb_carte_hero( ueb_fcfa( $totaux['encaisse'] ), 'Pensions encaissées par l’IPES', $totaux['etudiants'] . ' étudiant' . ( $totaux['etudiants'] > 1 ? 's' : '' ) . ' · ' . $annee['libelle'] ); ?>
			<div class="bo-chiffres">
				<?php
				ueb_carte_chiffre( ueb_fcfa( $totaux['libre'] ), 'Pas encore reversé', 'horloge', 'attente', array( 'note' => 'Versements dans aucun bordereau envoyé' ) );
				ueb_carte_chiffre( ueb_fcfa( $jauge['envoye'] ), 'Reversé', 'envoyer', '', array( 'note' => 'Bordereaux envoyés ou vérifiés' ) );
				ueb_carte_chiffre( ueb_fcfa( $jauge['verifie'] ), 'Vérifié', 'check', 'verifie', null === $jauge['du']
					? array( 'note' => 'Montant annuel dû non renseigné' )
					: array( 'part' => $jauge['du'] ? 100 * $jauge['verifie'] / $jauge['du'] : 100, 'note' => 'Sur ' . ueb_fcfa( $jauge['du'] ) . ' dus (indicatif)' ) );
				ueb_carte_chiffre( null === $jauge['reste'] ? '—' : ueb_fcfa( $jauge['reste'] ), 'Reste dû', 'banque', $jauge['reste'] ? 'rejete' : '', array( 'note' => null === $jauge['reste'] ? 'Selon la convention, à préciser' : 'Montant dû moins vérifié' ) );
				?>
			</div>
		</div>

		<section id="bordereaux" class="carte bo-panneau" aria-labelledby="sco-bordereaux-titre">
			<header class="bo-panneau__entete">
				<span class="bo-panneau__icone"><?php echo ueb_icone( 'recu', 20 ); ?></span>
				<div><h2 id="sco-bordereaux-titre">Bordereaux adressés à ton établissement</h2><p>Les reversements de l’IPES, avec leur statut. Les brouillons de l’IPES n’apparaissent pas.</p></div>
			</header>
			<?php if ( ! $bordereaux ) : ?>
				<div class="bo-vide"><span><?php echo ueb_icone( 'recu', 22 ); ?></span><p>Aucun bordereau reçu de cet IPES.</p></div>
			<?php else : ?>
				<div class="tableau-conteneur">
					<table class="tableau">
						<thead><tr><th>Bordereau</th><th>Tutelle</th><th class="num">Total</th><th>État</th><th><span class="sr">Actions</span></th></tr></thead>
						<tbody>
						<?php foreach ( $bordereaux as $b ) : ?>
							<tr>
								<td><b><?php echo esc_html( $b->numero ); ?></b><br><small class="texte-discret"><?php echo esc_html( str_replace( '-', ' – ', $b->annee_academique ) . ' · ' . (int) $b->nb_versements . ' versement' . ( (int) $b->nb_versements > 1 ? 's' : '' ) . ( $b->date_envoi ? ' · envoyé le ' . mysql2date( 'd/m/Y', $b->date_envoi ) : '' ) ); ?></small>
									<?php if ( 'rejete' === $b->statut && $b->motif_rejet ) : ?><br><small class="texte-discret">Motif : <?php echo esc_html( $b->motif_rejet ); ?></small><?php endif; ?></td>
								<td><?php $pastilles( array( $b->etablissement ) ); ?></td>
								<td class="num"><b><?php echo esc_html( ueb_fcfa( $b->total ) ); ?></b></td>
								<td><?php echo ueb_ipes_badge_bordereau( $b->statut ); // phpcs:ignore -- échappé par la fonction ?></td>
								<td class="actions-ligne">
									<a class="btn btn--fantome btn--petit" href="<?php echo $ici( array( 'vue' => 'ipes', 'ipes' => (int) $ipes->id, 'bordereau' => (int) $b->id, 'pdf' => 1 ) ); ?>"><?php echo ueb_icone( 'telecharger', 16 ); ?>PDF</a>
									<?php if ( ueb_peut_verifier_bordereau( $b ) ) : ?>
										<form method="post" action="<?php echo esc_url( ueb_url_scolarite() ); ?>" data-confirmer="Marquer le bordereau <?php echo esc_attr( $b->numero ); ?> (<?php echo esc_attr( ueb_fcfa( $b->total ) ); ?>) comme vérifié ? La décision est définitive.">
											<?php ueb_champ_csrf(); ?>
											<input type="hidden" name="ueb_action" value="ipes_bordereau_decider_tutelle">
											<input type="hidden" name="ipes_id" value="<?php echo (int) $ipes->id; ?>">
											<input type="hidden" name="bordereau_id" value="<?php echo (int) $b->id; ?>">
											<input type="hidden" name="decision" value="verifie">
											<button class="btn btn--primaire btn--petit" type="submit"><?php echo ueb_icone( 'check', 16 ); ?>Vérifié</button>
										</form>
										<button class="btn btn--lien btn--petit" type="button" data-ouvrir-agent-mdp="rejet-<?php echo (int) $b->id; ?>">Rejeter</button>
										<dialog class="bo-agent-mdp" id="rejet-<?php echo (int) $b->id; ?>" aria-labelledby="rejet-titre-<?php echo (int) $b->id; ?>">
											<h2 id="rejet-titre-<?php echo (int) $b->id; ?>">Rejeter <?php echo esc_html( $b->numero ); ?></h2>
											<p>L’IPES verra ce motif, corrigera son bordereau et le renverra avec le même numéro.</p>
											<form method="post" action="<?php echo esc_url( ueb_url_scolarite() ); ?>">
												<?php ueb_champ_csrf(); ?>
												<input type="hidden" name="ueb_action" value="ipes_bordereau_decider_tutelle">
												<input type="hidden" name="ipes_id" value="<?php echo (int) $ipes->id; ?>">
												<input type="hidden" name="bordereau_id" value="<?php echo (int) $b->id; ?>">
												<input type="hidden" name="decision" value="rejete">
												<label><span>Motif du rejet</span><textarea name="motif" rows="3" minlength="5" maxlength="255" required></textarea></label>
												<div class="bo-agent-mdp__actions"><button class="btn btn--lien btn--petit" type="button" data-fermer-agent-mdp>Annuler</button><button class="btn btn--danger btn--petit" type="submit">Rejeter le bordereau</button></div>
											</form>
										</dialog>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</section>

		<section class="carte bo-panneau" aria-labelledby="sco-etudiants-titre">
			<header class="bo-panneau__entete">
				<span class="bo-panneau__icone"><?php echo ueb_icone( 'utilisateur', 20 ); ?></span>
				<div><h2 id="sco-etudiants-titre">Étudiants de l’année</h2><p>Déclarés par l’IPES, avec le total de leurs versements.</p></div>
			</header>
			<form class="filtres" method="get" action="<?php echo esc_url( ueb_url_scolarite() ); ?>" role="search" data-filtres-direct="sco-ipes-etudiants">
				<input type="hidden" name="vue" value="ipes">
				<input type="hidden" name="ipes" value="<?php echo (int) $ipes->id; ?>">
				<div class="champ">
					<label for="sco-etu-q">Rechercher un étudiant</label>
					<input id="sco-etu-q" type="search" name="q" value="<?php echo esc_attr( $recherche ); ?>" placeholder="Matricule, nom ou prénom" enterkeyhint="search" autocomplete="off">
				</div>
				<button class="btn btn--primaire" type="submit" data-filtres-bouton><?php echo ueb_icone( 'loupe', 18 ); ?>Rechercher</button>
			</form>
			<div id="sco-ipes-etudiants" class="ipes-resultats">
				<p class="texte-discret ipes-resultats__nombre" role="status"><?php echo esc_html( count( $etudiants ) . ' étudiant' . ( count( $etudiants ) > 1 ? 's' : '' ) ); ?></p>
				<div class="tableau-conteneur">
					<table class="tableau">
						<thead><tr><th>Étudiant</th><th>Filière</th><th class="num">Versements</th><th class="num">Total payé</th><th><span class="sr">Actions</span></th></tr></thead>
						<tbody>
						<?php if ( ! $etudiants ) : ?>
							<tr><td colspan="5" class="texte-discret"><?php echo '' !== $recherche ? 'Aucun étudiant ne correspond.' : 'L’IPES n’a déclaré aucun étudiant cette année.'; ?></td></tr>
						<?php endif; ?>
						<?php foreach ( $etudiants as $e ) : ?>
							<tr>
								<td><b><?php echo esc_html( $e->nom . ' ' . $e->prenom ); ?></b><br><small class="texte-discret"><?php echo esc_html( $e->matricule ); ?></small></td>
								<td><?php echo esc_html( $e->filiere ); ?><br><small class="texte-discret"><?php echo esc_html( $e->niveau ); ?></small></td>
								<td class="num"><?php echo (int) $e->nb_versements; ?></td>
								<td class="num"><?php echo esc_html( ueb_fcfa( $e->total_paye ) ); ?></td>
								<td class="actions-ligne"><a class="btn btn--lien btn--petit" href="<?php echo $ici( array( 'vue' => 'ipes', 'ipes' => (int) $ipes->id, 'etudiant' => (int) $e->id ) ); ?>">Versements<?php echo ueb_icone( 'fleche', 16 ); ?></a></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		</section>

	<?php endif; ?>

<?php endif; ?>
