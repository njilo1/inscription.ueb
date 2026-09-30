<?php
/**
 * Espace IPES, bordereaux :
 *   - sans « bordereau » : la création d'un brouillon pour une tutelle, puis
 *     la liste de l'année ;
 *   - ?bordereau={id}    : la fiche. Modifiable (brouillon ou rejeté) : les
 *     reçus bancaires à joindre, puis les étudiants à cocher (ceux des filières de sa tutelle, dans aucun autre
 *     bordereau), avec le total en direct dans une barre d'envoi collée au
 *     bas de l'écran. Envoyé ou vérifié : lecture seule, total figé.
 * Attend $ipes, $annee, $ici et $url (page-ipes.php).
 */
defined( 'ABSPATH' ) || exit;

$bordereau_demande = (int) ( $_GET['bordereau'] ?? 0 );
$liste_url         = $url( array( 'vue' => 'bordereaux' ) );
$unitaire          = UEB_IPES_REVERSEMENT_PAR_ETUDIANT;
?>

<?php if ( ! $bordereau_demande ) : ?>

	<?php
	$bordereaux = ueb_ipes_bordereaux( $ipes->id );
	$jauge      = ueb_ipes_jauge( $ipes->id );
	$libres     = array_map( static fn( $t ) => (int) $t['libres'], $jauge['par_tutelle'] );
	ueb_adm_tete( array(
		'titre'      => 'Bordereaux',
		'sous_titre' => 'Un bordereau reverse des étudiants à une tutelle, ' . ueb_fcfa( $unitaire ) . ' chacun. Une fois envoyé, il est figé jusqu’à sa vérification.',
	) );
	ueb_afficher_flash();
	?>

	<section id="nouveau-bordereau" class="adm-panneau ipes-nouveau" aria-labelledby="ipes-nouveau-titre" tabindex="-1">
		<span class="ipes-doc" aria-hidden="true"><?php echo ueb_icone( 'plus', 18 ); ?></span>
		<div class="ipes-nouveau__texte">
			<h2 id="ipes-nouveau-titre">Nouveau bordereau</h2>
			<p>
				<?php if ( $jauge['libres'] > 0 ) : ?>
					<b><?php echo esc_html( ueb_ipes_pluriel( $jauge['libres'], 'étudiant' ) ); ?></b> ne <?php echo $jauge['libres'] > 1 ? 'figurent' : 'figure'; ?> encore dans aucun bordereau, soit <?php echo esc_html( ueb_fcfa( $jauge['libres'] * $unitaire ) ); ?>. Crée un brouillon pour une tutelle, coche ses étudiants, puis envoie-le.
				<?php else : ?>
					Tous tes étudiants figurent déjà dans un bordereau. Ajoute de nouveaux étudiants pour en préparer un autre.
				<?php endif; ?>
			</p>
		</div>
		<form method="post" action="<?php echo esc_url( ueb_url_espace_ipes() ); ?>" data-formulaire novalidate>
			<?php ueb_champ_csrf(); ?>
			<input type="hidden" name="ueb_action" value="ipes_bordereau_creer">
			<?php if ( count( $ipes->tutelles ) > 1 ) : ?>
				<label class="ipes-selecteur"><span class="sr">Tutelle destinataire</span>
					<select name="etablissement">
						<?php foreach ( $ipes->tutelles as $s ) : ?>
							<option value="<?php echo esc_attr( $s ); ?>" <?php disabled( empty( $libres[ $s ] ) ); ?>><?php echo esc_html( 'Pour la ' . $s . ' (' . ( empty( $libres[ $s ] ) ? 'aucun étudiant à reverser' : ueb_ipes_pluriel( $libres[ $s ], 'étudiant' ) ) . ')' ); ?></option>
						<?php endforeach; ?>
					</select><?php echo ueb_icone( 'chevron', 16 ); ?>
				</label>
			<?php else : ?>
				<span class="ipes-destinataire">Pour <?php echo ueb_ipes_pastilles_html( $ipes->tutelles ); // phpcs:ignore -- échappé ?></span>
			<?php endif; ?>
			<button class="adm-bouton adm-bouton--primaire" type="submit" <?php disabled( $jauge['libres'] <= 0 ); ?>><?php echo ueb_icone( 'plus', 16 ); ?>Créer le brouillon</button>
		</form>
	</section>

	<section class="adm-panneau ipes-registre" aria-labelledby="ipes-liste-titre">
		<header class="adm-panneau__tete">
			<div>
				<h2 id="ipes-liste-titre">Bordereaux <?php echo esc_html( $annee['libelle'] ); ?></h2>
				<p><?php echo esc_html( $bordereaux ? ueb_ipes_pluriel( count( $bordereaux ), 'bordereau', 'bordereaux' ) . ', du plus récent au plus ancien.' : 'Aucun bordereau cette année.' ); ?></p>
			</div>
		</header>
		<?php if ( ! $bordereaux ) : ?>
			<div class="bo-vide ipes-vide"><span><?php echo ueb_icone( 'recu', 22 ); ?></span><p><b>Aucun bordereau pour l’instant.</b> Ton premier brouillon apparaîtra ici.</p></div>
		<?php else : ?>
			<?php ueb_ipes_bordereaux_liste( $bordereaux, array( 'url' => static fn( $b ) => $url( array( 'vue' => 'bordereaux', 'bordereau' => (int) $b->id ) ) ) ); ?>
		<?php endif; ?>
	</section>

<?php else : ?>

	<?php $bordereau = ueb_ipes_bordereau( $ipes->id, $bordereau_demande ); ?>

	<?php if ( ! $bordereau ) : ?>

		<?php
		ueb_adm_tete( array(
			'fil'   => array( array( $liste_url, 'Bordereaux' ), array( '', 'Introuvable' ) ),
			'titre' => 'Bordereau introuvable',
		) );
		ueb_afficher_flash();
		?>
		<div class="bo-vide bo-vide--large"><span><?php echo ueb_icone( 'recu', 24 ); ?></span><p><b>Ce bordereau n’existe pas dans ton IPES.</b> Il a peut-être été supprimé : retrouve les tiens dans la liste.</p><a class="adm-bouton" href="<?php echo esc_url( $liste_url ); ?>"><?php echo ueb_icone( 'fleche-g', 16 ); ?>Tous les bordereaux</a></div>

	<?php else : ?>

		<?php
		$modifiable = in_array( $bordereau->statut, UEB_IPES_BORDEREAU_MODIFIABLE, true );
		$dedans     = ueb_ipes_bordereau_etudiants( $ipes->id, $bordereau->id );
		$libres     = $modifiable ? ueb_ipes_etudiants_libres( $ipes->id, $bordereau->etablissement, $bordereau->annee_academique ) : array();
		$numero     = ueb_ipes_numero( $bordereau );
		$lignes     = array_merge( $dedans, $libres );
		usort( $lignes, static fn( $a, $b ) => array( $a->nom, $a->prenom ) <=> array( $b->nom, $b->prenom ) );
		$tutelle    = ueb_etablissement( $bordereau->etablissement );
		/* Envoyé : montant figé à l'envoi ; sinon celui en vigueur. */
		$prix       = $modifiable ? $unitaire : (int) $bordereau->montant_unitaire;
		$nb_recus   = ueb_ipes_nb_recus( $ipes->id, $bordereau->id );

		ob_start();
		if ( ueb_ipes_bordereau_a_pdf( $bordereau ) ) {
			echo ueb_adm_action( $url( array( 'vue' => 'bordereaux', 'bordereau' => (int) $bordereau->id, 'pdf' => 1 ) ), 'Télécharger le PDF', 'telecharger' ); // phpcs:ignore -- échappé
		}
		if ( 'brouillon' === $bordereau->statut ) :
			?>
			<form method="post" action="<?php echo esc_url( ueb_url_espace_ipes() ); ?>" data-confirmer="Supprimer ce brouillon ? Ses étudiants redeviendront à reverser.">
				<?php ueb_champ_csrf(); ?>
				<input type="hidden" name="ueb_action" value="ipes_bordereau_supprimer">
				<input type="hidden" name="bordereau_id" value="<?php echo (int) $bordereau->id; ?>">
				<button class="adm-bouton adm-bouton--danger" type="submit"><?php echo ueb_icone( 'corbeille', 16 ); ?>Supprimer le brouillon</button>
			</form>
			<?php
		endif;
		$actions = ob_get_clean();

		ueb_adm_tete( array(
			'fil'        => array( array( $liste_url, 'Bordereaux' ), array( '', $numero ) ),
			'titre'      => $numero,
			'sous_titre' => 'Pour ' . ( $tutelle['fr'] ?? $bordereau->etablissement ) . ', année ' . str_replace( '-', ' – ', $bordereau->annee_academique ) . '.',
			'apres'      => '<p class="ipes-tete-statut">' . ueb_ipes_statut( $bordereau->statut ) . '</p>',
			'actions'    => $actions,
		) );
		ueb_afficher_flash();
		?>

		<?php if ( 'rejete' === $bordereau->statut ) : ?>
			<?php ueb_alerte( 'erreur', 'Rejeté : « ' . $bordereau->motif_rejet . ' » Corrige les étudiants ou remplace les reçus, puis renvoie le bordereau ; il garde son numéro.' ); ?>
		<?php elseif ( 'envoye' === $bordereau->statut ) : ?>
			<div class="alerte alerte--info" role="status"><?php echo ueb_icone( 'horloge', 20 ); ?><p>Envoyé le <?php echo esc_html( mysql2date( 'd/m/Y à H:i', $bordereau->date_envoi ) ); ?>, en attente de vérification. Il n’est plus modifiable.</p></div>
		<?php elseif ( 'verifie' === $bordereau->statut ) : ?>
			<div class="alerte alerte--succes" role="status"><?php echo ueb_icone( 'check', 20 ); ?><p>Vérifié le <?php echo esc_html( mysql2date( 'd/m/Y', $bordereau->date_verification ) ); ?>.</p></div>
		<?php endif; ?>

		<?php if ( $modifiable ) : ?>

			<ol class="ipes-etapes" aria-label="Préparer le bordereau">
				<li class="<?php echo $nb_recus ? 'est-fait' : ''; ?>"><span aria-hidden="true"><?php echo $nb_recus ? ueb_icone( 'check', 14 ) : '1'; ?></span><a href="#recus">Reçu bancaire</a><small><?php echo esc_html( $nb_recus ? ueb_ipes_pluriel( $nb_recus, 'reçu joint', 'reçus joints' ) : 'à joindre' ); ?></small></li>
				<li class="<?php echo $dedans ? 'est-fait' : ''; ?>"><span aria-hidden="true"><?php echo $dedans ? ueb_icone( 'check', 14 ) : '2'; ?></span><a href="#etudiants-a-reverser">Étudiants</a><small><?php echo esc_html( $dedans ? ueb_ipes_pluriel( count( $dedans ), 'étudiant' ) . ' · ' . ueb_fcfa( count( $dedans ) * $unitaire ) : 'à cocher' ); ?></small></li>
				<li><span aria-hidden="true">3</span><span>Envoi</span><small><?php echo esc_html( $nb_recus && $dedans ? 'prêt' : 'après les deux premières étapes' ); ?></small></li>
			</ol>

			<?php ueb_ipes_recus_panneau( $bordereau, array( 'modifiable' => true, 'action' => ueb_url_espace_ipes(), 'selection' => '#etudiants-a-reverser' ) ); ?>

			<form id="etudiants-a-reverser" class="adm-panneau ipes-bordereau" method="post" action="<?php echo esc_url( ueb_url_espace_ipes() ); ?>" data-total-coches data-total-unite="étudiant">
				<?php ueb_champ_csrf(); ?>
				<input type="hidden" name="ueb_action" value="ipes_bordereau_enregistrer">
				<input type="hidden" name="bordereau_id" value="<?php echo (int) $bordereau->id; ?>">
				<header class="adm-panneau__tete">
					<div><h2>Étudiants à reverser</h2><p><?php echo esc_html( 'Coche les étudiants de ce bordereau, ' . ueb_fcfa( $unitaire ) . ' chacun. Seuls ceux des filières de la ' . $bordereau->etablissement . ' apparaissent, et pas ceux d’un autre bordereau.' ); ?></p></div>
				</header>
				<?php if ( ! $lignes ) : ?>
					<div class="bo-vide ipes-vide"><span><?php echo ueb_icone( 'groupe', 22 ); ?></span><p><b><?php echo esc_html( 'Aucun étudiant à reverser à la ' . $bordereau->etablissement . ' cette année.' ); ?></b> Ajoute d’abord des étudiants dans ses filières.</p><a class="adm-bouton" href="<?php echo esc_url( $url( array( 'vue' => 'etudiants' ) ) ); ?>"><?php echo ueb_icone( 'groupe', 16 ); ?>Mes étudiants</a></div>
				<?php else : ?>
					<table class="adm-registre__table ipes-table ipes-table--selection">
						<thead><tr>
							<th class="ipes-case" scope="col"><label class="sr" for="tout-cocher">Tout cocher</label><input id="tout-cocher" type="checkbox" data-tout-cocher></th>
							<th scope="col">Étudiant</th><th scope="col">Filière</th><th scope="col" class="num">Montant</th>
						</tr></thead>
						<tbody>
						<?php foreach ( $lignes as $e ) : $eid = (int) $e->id; ?>
							<tr class="ipes-ligne">
								<td class="ipes-case"><input id="etudiant-<?php echo $eid; ?>" type="checkbox" name="etudiants[]" value="<?php echo $eid; ?>" data-montant="<?php echo (int) $unitaire; ?>" <?php checked( (int) $e->bordereau_id, (int) $bordereau->id ); ?>></td>
								<td class="ipes-c-qui"><span class="ipes-ligne__qui"><label for="etudiant-<?php echo $eid; ?>" class="ipes-ligne__texte"><b><?php echo esc_html( $e->nom . ' ' . $e->prenom ); ?></b><small><?php echo esc_html( $e->matricule ); ?></small></label></span></td>
								<td data-titre="Filière"><span class="ipes-ligne__texte"><span><?php echo esc_html( $e->filiere ); ?></span><small><?php echo esc_html( ueb_ipes_niveau( $e->niveau ) ); ?></small></span></td>
								<td class="num ipes-ligne__montant" data-titre="Montant"><?php echo esc_html( ueb_formater_montant( $unitaire ) ); ?> <small>FCFA</small></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
				<footer class="ipes-envoi">
					<p class="ipes-envoi__total" aria-live="polite"><span>Total du bordereau</span><b data-total-affiche><?php echo esc_html( ueb_fcfa( count( $dedans ) * $unitaire ) ); ?></b><small data-total-nombre><?php echo esc_html( ueb_ipes_pluriel( count( $dedans ), 'étudiant' ) ); ?></small></p>
					<?php if ( ! $nb_recus ) : ?>
						<p class="ipes-envoi__manque"><?php echo ueb_icone( 'info', 16 ); ?><a href="#recus">Joins d’abord un reçu bancaire</a> pour pouvoir envoyer.</p>
					<?php endif; ?>
					<div class="ipes-envoi__actions">
						<button class="adm-bouton" type="submit"><?php echo ueb_icone( 'check', 16 ); ?>Enregistrer la sélection</button>
						<button class="adm-bouton adm-bouton--primaire" type="submit" name="envoyer" value="1" <?php disabled( ! $nb_recus ); ?> data-confirmer="Envoyer ce bordereau ? Il ne sera plus modifiable, sauf s’il est rejeté."><?php echo ueb_icone( 'envoyer', 16 ); ?><?php echo 'rejete' === $bordereau->statut ? 'Renvoyer le bordereau' : 'Envoyer le bordereau'; ?></button>
					</div>
				</footer>
			</form>

		<?php else : ?>

			<section class="adm-panneau ipes-registre" aria-labelledby="ipes-bordereau-contenu">
				<header class="adm-panneau__tete">
					<div><h2 id="ipes-bordereau-contenu"><?php echo esc_html( ueb_ipes_pluriel( count( $dedans ), 'étudiant' ) ); ?></h2><p><?php echo esc_html( ueb_fcfa( $prix ) . ' par étudiant. Le total a été figé à l’envoi ; il figure sur le PDF.' ); ?></p></div>
				</header>
				<div class="ipes-recap">
					<div><span>Total reversé</span><b><?php echo esc_html( ueb_fcfa( $bordereau->total ) ); ?></b></div>
					<p class="ipes-recap__lettres"><span><?php echo esc_html( ucfirst( ueb_nombre_en_lettres( (int) $bordereau->total ) ) . ' francs CFA' ); ?></span></p>
				</div>
				<table class="adm-registre__table ipes-table ipes-table--versements">
					<thead><tr><th scope="col">Étudiant</th><th scope="col">Filière</th><th scope="col" class="num">Montant</th></tr></thead>
					<tbody>
					<?php foreach ( $dedans as $e ) : ?>
						<tr class="ipes-ligne">
							<td class="ipes-c-qui"><span class="ipes-ligne__qui"><span class="ipes-ligne__texte"><b><?php echo esc_html( $e->nom . ' ' . $e->prenom ); ?></b><small><?php echo esc_html( $e->matricule ); ?></small></span></span></td>
							<td data-titre="Filière"><span class="ipes-ligne__texte"><span><?php echo esc_html( $e->filiere ); ?></span><small><?php echo esc_html( ueb_ipes_niveau( $e->niveau ) ); ?></small></span></td>
							<td class="num ipes-ligne__montant" data-titre="Montant"><?php echo esc_html( ueb_formater_montant( $prix ) ); ?> <small>FCFA</small></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</section>

			<?php ueb_ipes_recus_panneau( $bordereau ); ?>

		<?php endif; ?>

	<?php endif; ?>

<?php endif; ?>
