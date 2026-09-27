<?php
/**
 * Espace IPES, étudiants :
 *   - sans « etudiant » : la liste de l'année (filtres en direct) et, à côté,
 *     le formulaire d'ajout (?ajout=1 y place le curseur) ;
 *   - ?etudiant={id}    : la fiche : ses versements, puis ses informations.
 * Attend $ipes, $annee, $ici et $url (page-ipes.php). Toutes les lectures
 * passent par l'IPES du compte : un identifiant d'un autre IPES ne donne rien.
 */
defined( 'ABSPATH' ) || exit;

list( $saisie, $erreurs ) = ueb_reprendre_saisie();
/* Les erreurs d'un versement portent « paiement_id » dans la saisie : elles restent au bloc Versements. */
$erreurs_versement = array_key_exists( 'paiement_id', $saisie ) ? $erreurs : array();
$erreurs_etudiant  = $erreurs_versement ? array() : $erreurs;

$filieres         = ueb_ipes_filieres( $ipes->id );
$filieres_actives = array_filter( $filieres, static fn( $f ) => (int) $f->actif );
$etudiant_demande = (int) ( $_GET['etudiant'] ?? 0 );

/**
 * Champs d'un étudiant (ajout ou modification), sur une colonne.
 * $valeur : callable champ => valeur affichée ; $garder : filière retirée à garder dans la liste.
 */
$champs_etudiant = static function ( callable $valeur, array $erreurs, $garder = null, $focus = false ) use ( $filieres ) {
	$options = array();
	foreach ( $filieres as $f ) {
		if ( (int) $f->actif || (int) $f->id === (int) $garder ) {
			$options[ $f->id ] = $f->libelle . ( (int) $f->actif ? '' : ' (retirée)' );
		}
	}
	$niveaux = array_combine( array_keys( UEB_NIVEAUX_INSCRIPTION ), array_map( 'ueb_ipes_niveau', array_keys( UEB_NIVEAUX_INSCRIPTION ) ) );
	ueb_champ( array( 'nom' => 'matricule', 'libelle' => 'Matricule', 'icone' => 'qr', 'valeur' => $valeur( 'matricule' ), 'erreur' => $erreurs['matricule'] ?? '', 'aide' => 'Celui que l’IPES a attribué à l’étudiant.', 'attrs' => array_filter( array( 'maxlength' => 30, 'autocomplete' => 'off', 'autocapitalize' => 'characters', 'spellcheck' => 'false', 'autofocus' => $focus ) ) ) );
	?>
	<div class="formulaire__rangee">
		<?php
		ueb_champ( array( 'nom' => 'nom', 'libelle' => 'Nom', 'valeur' => $valeur( 'nom' ), 'erreur' => $erreurs['nom'] ?? '', 'attrs' => array( 'maxlength' => 100, 'autocomplete' => 'off' ) ) );
		ueb_champ( array( 'nom' => 'prenom', 'libelle' => 'Prénom', 'valeur' => $valeur( 'prenom' ), 'erreur' => $erreurs['prenom'] ?? '', 'attrs' => array( 'maxlength' => 150, 'autocomplete' => 'off' ) ) );
		?>
	</div>
	<?php
	ueb_champ( array( 'nom' => 'filiere_id', 'libelle' => 'Filière', 'type' => 'select', 'icone' => 'fichier', 'options' => $options, 'valeur' => $valeur( 'filiere_id' ), 'erreur' => $erreurs['filiere_id'] ?? '' ) );
	?>
	<div class="formulaire__rangee">
		<?php
		ueb_champ( array( 'nom' => 'niveau', 'libelle' => 'Niveau', 'type' => 'select', 'icone' => 'ecole', 'options' => $niveaux, 'valeur' => $valeur( 'niveau' ), 'erreur' => $erreurs['niveau'] ?? '' ) );
		ueb_champ( array( 'nom' => 'telephone', 'libelle' => 'Téléphone', 'type' => 'tel', 'icone' => 'telephone', 'requis' => false, 'valeur' => $valeur( 'telephone' ) ? ueb_formater_telephone( $valeur( 'telephone' ) ) : '', 'erreur' => $erreurs['telephone'] ?? '', 'attrs' => array( 'inputmode' => 'tel', 'autocomplete' => 'off' ) ) );
		?>
	</div>
	<?php
};
?>

<?php if ( ! $etudiant_demande ) : ?>

	<?php
	$filtres = array(
		'recherche'  => sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) ),
		'filiere_id' => (int) ( $_GET['filiere'] ?? 0 ),
	);
	$liste  = ueb_ipes_etudiants( $ipes->id, $filtres );
	$filtre = '' !== $filtres['recherche'] || $filtres['filiere_id'];
	$totaux = ueb_ipes_totaux( $ipes->id );

	ueb_adm_tete( array(
		'titre'      => 'Étudiants',
		'sous_titre' => 'Les étudiants de l’année et leurs versements de pension. Un étudiant se saisit chaque année, avec le même matricule.',
	) );
	ueb_afficher_flash();
	?>

	<?php if ( ! $filieres_actives ) : ?>
		<div class="bo-vide bo-vide--large"><span><?php echo ueb_icone( 'fichier', 24 ); ?></span><p><b>Aucune filière pour ton IPES.</b> Les filières sont enregistrées par l’administration de l’UEb : communique-lui la liste des tiennes.</p></div>
	<?php else : ?>
		<div class="ipes-deux">
			<section class="adm-panneau ipes-registre" aria-labelledby="ipes-etudiants-titre">
				<header class="adm-panneau__tete">
					<div>
						<h2 id="ipes-etudiants-titre">Étudiants <?php echo esc_html( $annee['libelle'] ); ?></h2>
						<p><?php echo esc_html( $totaux['etudiants'] ? ueb_ipes_pluriel( $totaux['etudiants'], 'étudiant' ) . ', ' . ueb_fcfa( $totaux['encaisse'] ) . ' de pensions encaissées.' : 'Aucun étudiant pour l’instant.' ); ?></p>
					</div>
					<?php if ( $totaux['etudiants'] ) : ?>
						<form class="ipes-outils" method="get" action="<?php echo esc_url( ueb_url_espace_ipes() ); ?>" role="search" aria-label="Filtrer les étudiants" data-filtres-direct="ipes-etudiants-resultats">
							<input type="hidden" name="vue" value="etudiants">
							<label class="ipes-recherche"><span class="sr">Rechercher un étudiant</span><?php echo ueb_icone( 'loupe', 17 ); ?><input type="search" name="q" value="<?php echo esc_attr( $filtres['recherche'] ); ?>" placeholder="Matricule, nom ou prénom" enterkeyhint="search" autocomplete="off"></label>
							<label class="ipes-selecteur"><span class="sr">Filière</span>
								<select name="filiere">
									<option value="">Toutes les filières</option>
									<?php foreach ( $filieres as $f ) : ?>
										<option value="<?php echo (int) $f->id; ?>" <?php selected( $filtres['filiere_id'], (int) $f->id ); ?>><?php echo esc_html( $f->libelle ); ?></option>
									<?php endforeach; ?>
								</select><?php echo ueb_icone( 'chevron', 16 ); ?>
							</label>
							<button class="adm-bouton" type="submit" data-filtres-bouton><?php echo ueb_icone( 'loupe', 16 ); ?>Rechercher</button>
						</form>
					<?php endif; ?>
				</header>
				<div id="ipes-etudiants-resultats" class="ipes-resultats" aria-live="polite">
					<?php if ( $filtre ) : ?>
						<p class="ipes-compte"><b><?php echo esc_html( ueb_ipes_pluriel( count( $liste ), 'étudiant' ) ); ?></b> <?php echo count( $liste ) > 1 ? 'correspondent' : 'correspond'; ?> aux filtres.</p>
					<?php endif; ?>
					<?php if ( ! $liste ) : ?>
						<div class="bo-vide ipes-vide"><span><?php echo ueb_icone( $filtre ? 'loupe' : 'groupe', 22 ); ?></span><p><?php echo $filtre ? '<b>Aucun étudiant ne correspond.</b> Vérifie l’orthographe, ou cherche par matricule.' : '<b>Aucun étudiant cette année.</b> Ajoute le premier avec le formulaire.'; ?></p></div>
					<?php else : ?>
						<?php ueb_ipes_etudiants_liste( $liste, static fn( $e ) => $url( array( 'vue' => 'etudiants', 'etudiant' => (int) $e->id ) ) ); ?>
					<?php endif; ?>
				</div>
			</section>

			<aside class="ipes-cote">
				<section id="ajout" class="adm-panneau" aria-labelledby="ipes-ajout-titre" tabindex="-1">
					<header class="adm-panneau__tete">
						<div><h2 id="ipes-ajout-titre">Ajouter un étudiant</h2><p>Inscrit pour l’année <?php echo esc_html( $annee['libelle'] ); ?>. Ouvre ensuite sa fiche pour enregistrer ses versements.</p></div>
					</header>
					<?php ueb_ipes_resume_erreurs( $erreurs_etudiant, 'Vérifie les informations suivantes.' ); ?>
					<form class="formulaire" method="post" action="<?php echo esc_url( ueb_url_espace_ipes() ); ?>" data-formulaire novalidate>
						<?php ueb_champ_csrf(); ?>
						<input type="hidden" name="ueb_action" value="ipes_etudiant_enregistrer">
						<?php $champs_etudiant( static fn( $c ) => (string) ( $saisie[ $c ] ?? '' ), $erreurs_etudiant, null, isset( $_GET['ajout'] ) && ! $erreurs_etudiant ); ?>
						<button class="adm-bouton adm-bouton--primaire ipes-bouton-large" type="submit"><?php echo ueb_icone( 'plus', 16 ); ?>Ajouter l’étudiant</button>
					</form>
				</section>
			</aside>
		</div>
	<?php endif; ?>

<?php else : ?>

	<?php $etudiant = ueb_ipes_etudiant( $ipes->id, $etudiant_demande ); ?>

	<?php if ( ! $etudiant ) : ?>

		<?php
		ueb_adm_tete( array(
			'fil'   => array( array( $url( array( 'vue' => 'etudiants' ) ), 'Étudiants' ), array( '', 'Introuvable' ) ),
			'titre' => 'Étudiant introuvable',
		) );
		ueb_afficher_flash();
		ueb_ipes_resume_erreurs( $erreurs, 'L’opération n’a pas été faite.' );
		?>
		<div class="bo-vide bo-vide--large"><span><?php echo ueb_icone( 'utilisateur', 24 ); ?></span><p><b>Cet étudiant n’existe pas dans ton IPES.</b> Retrouve-le dans la liste.</p><a class="adm-bouton" href="<?php echo esc_url( $url( array( 'vue' => 'etudiants' ) ) ); ?>"><?php echo ueb_icone( 'fleche-g', 16 ); ?>Tous les étudiants</a></div>

	<?php else : ?>

		<?php
		$versements = ueb_ipes_paiements_etudiant( $ipes->id, $etudiant->id );
		$total      = array_sum( array_map( static fn( $v ) => (int) $v->montant, $versements ) );
		$filiere    = ueb_ipes_filiere( $etudiant->filiere_id );
		$nom        = trim( $etudiant->nom . ' ' . $etudiant->prenom );
		$bordereaux = array();
		foreach ( $versements as $v ) {
			if ( $v->bordereau_id && ! isset( $bordereaux[ $v->bordereau_id ] ) ) {
				$bordereaux[ $v->bordereau_id ] = ueb_ipes_bordereau( $ipes->id, $v->bordereau_id );
			}
		}

		$actions = '';
		if ( ! $versements ) {
			ob_start();
			?>
			<form method="post" action="<?php echo esc_url( ueb_url_espace_ipes() ); ?>" data-confirmer="<?php echo esc_attr( 'Supprimer ' . $nom . ' ? À faire seulement s’il a été saisi par erreur.' ); ?>">
				<?php ueb_champ_csrf(); ?>
				<input type="hidden" name="ueb_action" value="ipes_etudiant_supprimer">
				<input type="hidden" name="etudiant_id" value="<?php echo (int) $etudiant->id; ?>">
				<button class="adm-bouton adm-bouton--danger" type="submit"><?php echo ueb_icone( 'corbeille', 16 ); ?>Supprimer</button>
			</form>
			<?php
			$actions = ob_get_clean();
		}
		$reperes  = '<ul class="ipes-reperes">';
		$reperes .= '<li>' . ueb_icone( 'qr', 14 ) . esc_html( $etudiant->matricule ) . '</li>';
		$reperes .= '<li>' . ueb_icone( 'fichier', 14 ) . esc_html( $filiere->libelle ?? 'Filière inconnue' ) . '</li>';
		$reperes .= '<li>' . ueb_icone( 'ecole', 14 ) . esc_html( ueb_ipes_niveau( $etudiant->niveau ) ) . '</li>';
		if ( $etudiant->telephone ) {
			$reperes .= '<li>' . ueb_icone( 'telephone', 14 ) . esc_html( ueb_formater_telephone( $etudiant->telephone ) ) . '</li>';
		}
		$reperes .= '</ul>';
		ueb_adm_tete( array(
			'fil'     => array( array( $url( array( 'vue' => 'etudiants' ) ), 'Étudiants' ), array( '', $nom ) ),
			'titre'   => $nom,
			'visuel'  => '<span class="bo-avatar ipes-avatar" aria-hidden="true">' . esc_html( ueb_initiales( $etudiant->prenom, $etudiant->nom ) ) . '</span>',
			'apres'   => $reperes,
			'actions' => $actions,
		) );
		ueb_afficher_flash();
		?>

		<div class="ipes-grille">
			<section id="versements" class="adm-panneau ipes-registre" aria-labelledby="ipes-versements-titre" tabindex="-1">
				<header class="adm-panneau__tete">
					<div><h2 id="ipes-versements-titre">Versements de pension</h2><p>Un versement placé dans un bordereau n’est plus modifiable : il est reversé à la tutelle, ou en passe de l’être.</p></div>
					<p class="ipes-total"><b><?php echo esc_html( ueb_formater_montant( $total ) ); ?></b><small>FCFA payés<?php echo $versements ? ' en ' . esc_html( ueb_ipes_pluriel( count( $versements ), 'versement' ) ) : ''; ?></small></p>
				</header>
				<div class="ipes-corps">
					<?php ueb_ipes_resume_erreurs( $erreurs_versement, 'Le versement n’a pas été enregistré.' ); ?>
					<?php $nouveau_versement = $erreurs_versement && empty( $saisie['paiement_id'] ); ?>
					<form class="formulaire ipes-versement-ajout<?php echo $nouveau_versement ? ' a-erreur' : ''; ?>" method="post" action="<?php echo esc_url( ueb_url_espace_ipes() ); ?>" data-formulaire novalidate>
						<?php ueb_champ_csrf(); ?>
						<input type="hidden" name="ueb_action" value="ipes_paiement_enregistrer">
						<input type="hidden" name="etudiant_id" value="<?php echo (int) $etudiant->id; ?>">
						<?php
						ueb_champ( array( 'nom' => 'montant', 'libelle' => 'Montant versé (FCFA)', 'icone' => 'banque', 'valeur' => $nouveau_versement ? (string) ( $saisie['montant'] ?? '' ) : '', 'erreur' => $nouveau_versement ? ( $erreurs_versement['montant'] ?? '' ) : '', 'attrs' => array( 'inputmode' => 'numeric', 'autocomplete' => 'off', 'placeholder' => '25 000' ) ) );
						ueb_champ( array( 'nom' => 'date_paiement', 'libelle' => 'Date du versement', 'type' => 'date', 'valeur' => $nouveau_versement ? (string) ( $saisie['date_paiement'] ?? '' ) : current_time( 'Y-m-d' ), 'erreur' => $nouveau_versement ? ( $erreurs_versement['date_paiement'] ?? '' ) : '', 'attrs' => array( 'max' => current_time( 'Y-m-d' ) ) ) );
						?>
						<button class="adm-bouton adm-bouton--primaire" type="submit"><?php echo ueb_icone( 'plus', 16 ); ?>Enregistrer</button>
					</form>
				</div>

				<?php if ( ! $versements ) : ?>
					<div class="bo-vide ipes-vide"><span><?php echo ueb_icone( 'banque', 22 ); ?></span><p><b>Aucun versement pour l’instant.</b> Enregistre le premier avec le formulaire ci-dessus.</p></div>
				<?php else : ?>
					<table class="adm-registre__table ipes-table ipes-table--versements">
						<thead><tr><th scope="col">Date</th><th scope="col" class="num">Montant</th><th scope="col">Reversement</th><th scope="col"><span class="sr">Actions</span></th></tr></thead>
						<tbody>
						<?php foreach ( $versements as $v ) : $b = $v->bordereau_id ? ( $bordereaux[ $v->bordereau_id ] ?? null ) : null; $vid = (int) $v->id; ?>
							<tr class="ipes-ligne">
								<td class="ipes-c-qui ipes-ligne__date"><b><?php echo esc_html( mysql2date( 'd/m/Y', $v->date_paiement ) ); ?></b></td>
								<td class="num ipes-ligne__montant" data-titre="Montant"><?php echo esc_html( ueb_formater_montant( (int) $v->montant ) ); ?> <small>FCFA</small></td>
								<td data-titre="Reversement">
									<?php if ( $b ) : ?>
										<a class="ipes-lien-bordereau" href="<?php echo esc_url( $url( array( 'vue' => 'bordereaux', 'bordereau' => (int) $b->id ) ) ); ?>"><?php echo esc_html( ueb_ipes_numero( $b ) ); ?></a>
										<?php echo ueb_ipes_statut( $b->statut ); // phpcs:ignore -- échappé ?>
									<?php else : ?>
										<span class="ipes-statut ipes-statut--libre"><?php echo ueb_icone( 'recu', 14 ); ?>À reverser</span>
									<?php endif; ?>
								</td>
								<td class="ipes-ligne__actions">
									<?php if ( ! $v->bordereau_id ) : ?>
										<div class="ipes-actions">
											<button class="adm-bouton adm-bouton--petit" type="button" data-ouvrir-agent-mdp="versement-<?php echo $vid; ?>"><?php echo ueb_icone( 'crayon', 15 ); ?>Modifier</button>
											<dialog class="bo-agent-mdp ipes-dialogue" id="versement-<?php echo $vid; ?>" aria-labelledby="versement-titre-<?php echo $vid; ?>">
												<h2 id="versement-titre-<?php echo $vid; ?>">Modifier le versement</h2>
												<form method="post" action="<?php echo esc_url( ueb_url_espace_ipes() ); ?>">
													<?php ueb_champ_csrf(); ?>
													<input type="hidden" name="ueb_action" value="ipes_paiement_enregistrer">
													<input type="hidden" name="etudiant_id" value="<?php echo (int) $etudiant->id; ?>">
													<input type="hidden" name="paiement_id" value="<?php echo $vid; ?>">
													<label><span>Montant (FCFA)</span><input type="text" name="montant" value="<?php echo esc_attr( ueb_formater_montant( (int) $v->montant ) ); ?>" inputmode="numeric" autocomplete="off" required></label>
													<label><span>Date</span><input type="date" name="date_paiement" value="<?php echo esc_attr( $v->date_paiement ); ?>" max="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" required></label>
													<div class="bo-agent-mdp__actions"><button class="btn btn--lien btn--petit" type="button" data-fermer-agent-mdp>Annuler</button><button class="btn btn--primaire btn--petit" type="submit">Enregistrer</button></div>
												</form>
											</dialog>
											<form method="post" action="<?php echo esc_url( ueb_url_espace_ipes() ); ?>" data-confirmer="<?php echo esc_attr( 'Supprimer ce versement de ' . ueb_fcfa( $v->montant ) . ' ?' ); ?>">
												<?php ueb_champ_csrf(); ?>
												<input type="hidden" name="ueb_action" value="ipes_paiement_supprimer">
												<input type="hidden" name="etudiant_id" value="<?php echo (int) $etudiant->id; ?>">
												<input type="hidden" name="paiement_id" value="<?php echo $vid; ?>">
												<button class="adm-bouton adm-bouton--petit adm-bouton--danger adm-bouton--icone" type="submit" aria-label="<?php echo esc_attr( 'Supprimer le versement du ' . mysql2date( 'd/m/Y', $v->date_paiement ) ); ?>" title="Supprimer"><?php echo ueb_icone( 'corbeille', 15 ); ?></button>
											</form>
										</div>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</section>

			<section class="adm-panneau ipes-etroit" aria-labelledby="ipes-infos-titre">
				<header class="adm-panneau__tete">
					<div><h2 id="ipes-infos-titre">Informations</h2><p>Corrige une faute de saisie. Pour l’année suivante, ajoute l’étudiant de nouveau, avec le même matricule.</p></div>
				</header>
				<?php ueb_ipes_resume_erreurs( $erreurs_etudiant, 'Vérifie les informations suivantes.' ); ?>
				<form class="formulaire" method="post" action="<?php echo esc_url( ueb_url_espace_ipes() ); ?>" data-formulaire novalidate>
					<?php ueb_champ_csrf(); ?>
					<input type="hidden" name="ueb_action" value="ipes_etudiant_enregistrer">
					<input type="hidden" name="etudiant_id" value="<?php echo (int) $etudiant->id; ?>">
					<?php $champs_etudiant( static fn( $c ) => (string) ( $erreurs_etudiant ? ( $saisie[ $c ] ?? '' ) : ( $etudiant->$c ?? '' ) ), $erreurs_etudiant, $etudiant->filiere_id ); ?>
					<button class="adm-bouton adm-bouton--primaire ipes-bouton-large" type="submit"><?php echo ueb_icone( 'check', 16 ); ?>Enregistrer</button>
				</form>
			</section>
		</div>

	<?php endif; ?>

<?php endif; ?>
