<?php
/**
 * Espace IPES, étudiants :
 *   - sans « etudiant » : la liste de l'année (filtres en direct) et, avec
 *     ?ajout=1, le formulaire d'ajout ;
 *   - ?etudiant={id}    : la fiche, ses informations et ses versements.
 * Attend $ipes, $annee et $ici (page-ipes.php). Toutes les lectures passent
 * par l'IPES du compte : un identifiant d'un autre IPES ne donne rien.
 */
defined( 'ABSPATH' ) || exit;

list( $saisie, $erreurs ) = ueb_reprendre_saisie();
/* Les erreurs d'un versement portent « paiement_id » dans la saisie : elles restent au bloc Versements. */
$erreurs_versement = array_key_exists( 'paiement_id', $saisie ) ? $erreurs : array();
$erreurs_etudiant  = $erreurs_versement ? array() : $erreurs;

$filieres        = ueb_ipes_filieres( $ipes->id );
$filieres_actives = array_filter( $filieres, static fn( $f ) => (int) $f->actif );
$etudiant_demande = (int) ( $_GET['etudiant'] ?? 0 );

/**
 * Champs d'un étudiant (ajout ou modification).
 * $valeur : callable champ => valeur affichée ; $garder : filière retirée à garder dans la liste.
 */
$champs_etudiant = static function ( callable $valeur, array $erreurs, $garder = null ) use ( $filieres_actives, $filieres ) {
	$options = array();
	foreach ( $filieres as $f ) {
		if ( (int) $f->actif || (int) $f->id === (int) $garder ) {
			$options[ $f->id ] = $f->libelle . ( (int) $f->actif ? '' : ' (retirée)' );
		}
	}
	?>
	<div class="formulaire__rangee">
		<?php
		ueb_champ( array( 'nom' => 'matricule', 'libelle' => 'Matricule', 'icone' => 'qr', 'valeur' => $valeur( 'matricule' ), 'erreur' => $erreurs['matricule'] ?? '', 'aide' => 'Celui que l’IPES a attribué à l’étudiant.', 'attrs' => array( 'maxlength' => 30, 'autocomplete' => 'off', 'autocapitalize' => 'characters', 'spellcheck' => 'false' ) ) );
		ueb_champ( array( 'nom' => 'telephone', 'libelle' => 'Téléphone', 'type' => 'tel', 'icone' => 'telephone', 'requis' => false, 'valeur' => $valeur( 'telephone' ) ? ueb_formater_telephone( $valeur( 'telephone' ) ) : '', 'erreur' => $erreurs['telephone'] ?? '', 'attrs' => array( 'inputmode' => 'tel', 'autocomplete' => 'off' ) ) );
		?>
	</div>
	<div class="formulaire__rangee">
		<?php
		ueb_champ( array( 'nom' => 'nom', 'libelle' => 'Nom', 'valeur' => $valeur( 'nom' ), 'erreur' => $erreurs['nom'] ?? '', 'attrs' => array( 'maxlength' => 100, 'autocomplete' => 'off' ) ) );
		ueb_champ( array( 'nom' => 'prenom', 'libelle' => 'Prénom', 'valeur' => $valeur( 'prenom' ), 'erreur' => $erreurs['prenom'] ?? '', 'attrs' => array( 'maxlength' => 150, 'autocomplete' => 'off' ) ) );
		?>
	</div>
	<div class="formulaire__rangee">
		<?php
		ueb_champ( array( 'nom' => 'filiere_id', 'libelle' => 'Filière', 'type' => 'select', 'icone' => 'fichier', 'options' => $options, 'valeur' => $valeur( 'filiere_id' ), 'erreur' => $erreurs['filiere_id'] ?? '' ) );
		ueb_champ( array( 'nom' => 'niveau', 'libelle' => 'Niveau', 'type' => 'select', 'icone' => 'ecole', 'options' => UEB_NIVEAUX_INSCRIPTION, 'valeur' => $valeur( 'niveau' ), 'erreur' => $erreurs['niveau'] ?? '' ) );
		?>
	</div>
	<?php
};

/** Résumé d'erreurs accessible, avec liens vers les champs (même rendu que le quitus). */
$resume_erreurs = static function ( array $erreurs, $titre ) {
	if ( ! $erreurs ) {
		return;
	}
	?>
	<div class="alerte alerte--erreur ipes-erreurs" role="alert" tabindex="-1" data-resume-erreurs>
		<?php echo ueb_icone( 'alerte', 20 ); ?>
		<div>
			<p><strong><?php echo esc_html( $titre ); ?></strong></p>
			<ul>
				<?php foreach ( $erreurs as $champ => $message ) : ?>
					<li><?php if ( 'general' === $champ ) : ?><?php echo esc_html( $message ); ?><?php else : ?><a href="#champ-<?php echo esc_attr( $champ ); ?>" data-lien-erreur><?php echo esc_html( $message ); ?></a><?php endif; ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	</div>
	<?php
};
?>

<?php if ( ! $etudiant_demande ) : ?>

	<?php
	$filtres  = array(
		'recherche'  => sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) ),
		'filiere_id' => (int) ( $_GET['filiere'] ?? 0 ),
	);
	$liste    = ueb_ipes_etudiants( $ipes->id, $filtres );
	$filtre   = '' !== $filtres['recherche'] || $filtres['filiere_id'];
	$ajout    = isset( $_GET['ajout'] ) || $erreurs_etudiant;
	?>
	<header class="bo-entete">
		<div class="bo-entete__texte">
			<p class="bo-entete__contexte"><span><?php echo esc_html( $ipes->sigle ); ?></span><span class="bo-entete__annee"><?php echo esc_html( $annee['libelle'] ); ?></span></p>
			<h1>Étudiants</h1>
			<p class="bo-entete__sous-titre">Les étudiants de l’année et leurs versements de pension. Un étudiant se saisit chaque année, avec le même matricule.</p>
		</div>
		<?php if ( ! $ajout && $filieres_actives ) : ?>
			<a class="btn btn--primaire bo-entete__action" href="<?php echo $ici( array( 'vue' => 'etudiants', 'ajout' => 1 ) ); ?>#ajout"><?php echo ueb_icone( 'plus', 18 ); ?>Ajouter un étudiant</a>
		<?php endif; ?>
	</header>
	<?php ueb_afficher_flash(); ?>

	<?php if ( ! $filieres_actives ) : ?>
		<div class="bo-vide bo-vide--large"><span><?php echo ueb_icone( 'fichier', 24 ); ?></span><p><b>Aucune filière pour ton IPES.</b> Les filières sont enregistrées par l’administration de l’UEb : communique-lui la liste des tiennes.</p></div>
	<?php elseif ( $ajout ) : ?>
		<?php $resume_erreurs( $erreurs_etudiant, 'Vérifie les informations suivantes.' ); ?>
		<section id="ajout" class="carte section-form" aria-labelledby="ipes-ajout-titre">
			<header class="section-form__entete">
				<span class="section-form__num"><?php echo ueb_icone( 'plus', 18 ); ?></span>
				<div><h2 id="ipes-ajout-titre">Ajouter un étudiant</h2><p>Inscrit pour l’année <?php echo esc_html( $annee['libelle'] ); ?>. Tu enregistreras ses versements juste après.</p></div>
			</header>
			<div class="section-form__corps">
				<form class="formulaire" method="post" action="<?php echo esc_url( ueb_url_espace_ipes() ); ?>" data-formulaire novalidate>
					<?php ueb_champ_csrf(); ?>
					<input type="hidden" name="ueb_action" value="ipes_etudiant_enregistrer">
					<?php $champs_etudiant( static fn( $c ) => (string) ( $saisie[ $c ] ?? '' ), $erreurs_etudiant ); ?>
					<div class="ipes-fiche__actions">
						<a class="btn btn--fantome" href="<?php echo $ici( array( 'vue' => 'etudiants' ) ); ?>">Annuler</a>
						<button class="btn btn--primaire" type="submit"><?php echo ueb_icone( 'check', 18 ); ?>Ajouter l’étudiant</button>
					</div>
				</form>
			</div>
		</section>
	<?php endif; ?>

	<?php if ( $filieres ) : ?>
		<form class="filtres carte" method="get" action="<?php echo esc_url( ueb_url_espace_ipes() ); ?>" role="search" data-filtres-direct="ipes-etudiants-resultats">
			<input type="hidden" name="vue" value="etudiants">
			<div class="champ">
				<label for="etu-q">Rechercher</label>
				<input id="etu-q" type="search" name="q" value="<?php echo esc_attr( $filtres['recherche'] ); ?>" placeholder="Matricule, nom ou prénom" enterkeyhint="search" autocomplete="off">
			</div>
			<div class="champ">
				<label for="etu-filiere">Filière</label>
				<div class="champ__select">
					<select id="etu-filiere" name="filiere">
						<option value="">Toutes les filières</option>
						<?php foreach ( $filieres as $f ) : ?>
							<option value="<?php echo (int) $f->id; ?>" <?php selected( $filtres['filiere_id'], (int) $f->id ); ?>><?php echo esc_html( $f->libelle ); ?></option>
						<?php endforeach; ?>
					</select><?php echo ueb_icone( 'chevron', 18 ); ?>
				</div>
			</div>
			<button class="btn btn--primaire" type="submit" data-filtres-bouton><?php echo ueb_icone( 'loupe', 18 ); ?>Rechercher</button>
		</form>

		<div id="ipes-etudiants-resultats" class="ipes-resultats">
			<p class="texte-discret ipes-resultats__nombre" role="status"><?php echo esc_html( count( $liste ) . ' étudiant' . ( count( $liste ) > 1 ? 's' : '' ) . ( $filtre ? ' correspondant' . ( count( $liste ) > 1 ? 's' : '' ) . ' aux filtres' : '' ) ); ?></p>
			<div class="tableau-conteneur">
				<table class="tableau">
					<thead><tr><th>Étudiant</th><th>Filière</th><th class="num">Versements</th><th class="num">Total payé</th><th><span class="sr">Actions</span></th></tr></thead>
					<tbody>
					<?php if ( ! $liste ) : ?>
						<tr><td colspan="5" class="texte-discret"><?php echo $filtre ? 'Aucun étudiant ne correspond à ces filtres.' : 'Aucun étudiant pour cette année.'; ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $liste as $e ) : ?>
						<tr>
							<td><b><?php echo esc_html( $e->nom . ' ' . $e->prenom ); ?></b><br><small class="texte-discret"><?php echo esc_html( $e->matricule ); ?></small></td>
							<td><?php echo esc_html( $e->filiere ); ?><br><small class="texte-discret"><?php echo esc_html( $e->niveau ); ?></small></td>
							<td class="num"><?php echo (int) $e->nb_versements; ?></td>
							<td class="num"><?php echo esc_html( ueb_fcfa( $e->total_paye ) ); ?></td>
							<td class="actions-ligne"><a class="btn btn--lien btn--petit" href="<?php echo $ici( array( 'vue' => 'etudiants', 'etudiant' => (int) $e->id ) ); ?>">Ouvrir<?php echo ueb_icone( 'fleche', 16 ); ?></a></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	<?php endif; ?>

<?php else : ?>

	<?php $etudiant = ueb_ipes_etudiant( $ipes->id, $etudiant_demande ); ?>
	<a class="fil" href="<?php echo $ici( array( 'vue' => 'etudiants' ) ); ?>"><?php echo ueb_icone( 'fleche-g', 18 ); ?>Tous les étudiants</a>

	<?php if ( ! $etudiant ) : ?>

		<header class="bo-entete"><div class="bo-entete__texte"><h1>Étudiant introuvable</h1></div></header>
		<?php ueb_afficher_flash(); ?>
		<?php $resume_erreurs( $erreurs, 'L’opération n’a pas été faite.' ); ?>
		<div class="bo-vide bo-vide--large"><span><?php echo ueb_icone( 'utilisateur', 24 ); ?></span><p><b>Cet étudiant n’existe pas dans ton IPES.</b> Retrouve-le dans la liste.</p></div>

	<?php else : ?>

		<?php
		$versements = ueb_ipes_paiements_etudiant( $ipes->id, $etudiant->id );
		$total      = array_sum( array_map( static fn( $v ) => (int) $v->montant, $versements ) );
		$filiere    = ueb_ipes_filiere( $etudiant->filiere_id );
		$numeros    = array();
		foreach ( $versements as $v ) {
			if ( $v->bordereau_id && ! isset( $numeros[ $v->bordereau_id ] ) ) {
				$b = ueb_ipes_bordereau( $ipes->id, $v->bordereau_id );
				$numeros[ $v->bordereau_id ] = $b ? ( str_starts_with( $b->numero, 'BROUILLON-' ) ? 'brouillon n° ' . $b->id : $b->numero ) : '';
			}
		}
		?>
		<header class="bo-entete">
			<div class="bo-entete__texte">
				<p class="bo-entete__contexte"><span><?php echo esc_html( $etudiant->matricule ); ?></span><span class="bo-entete__annee"><?php echo esc_html( str_replace( '-', ' – ', $etudiant->annee_academique ) ); ?></span></p>
				<h1><?php echo esc_html( $etudiant->nom . ' ' . $etudiant->prenom ); ?></h1>
				<p class="bo-entete__sous-titre"><?php echo esc_html( ( $filiere->libelle ?? '' ) . ' · ' . $etudiant->niveau . ' · ' . ueb_fcfa( $total ) . ' payés' ); ?></p>
			</div>
			<?php if ( ! $versements ) : ?>
				<form method="post" action="<?php echo esc_url( ueb_url_espace_ipes() ); ?>" data-confirmer="Supprimer <?php echo esc_attr( $etudiant->nom . ' ' . $etudiant->prenom ); ?> ? À faire seulement s’il a été saisi par erreur.">
					<?php ueb_champ_csrf(); ?>
					<input type="hidden" name="ueb_action" value="ipes_etudiant_supprimer">
					<input type="hidden" name="etudiant_id" value="<?php echo (int) $etudiant->id; ?>">
					<button class="btn btn--fantome" type="submit"><?php echo ueb_icone( 'corbeille', 18 ); ?>Supprimer</button>
				</form>
			<?php endif; ?>
		</header>
		<?php ueb_afficher_flash(); ?>

		<section id="versements" class="carte section-form" aria-labelledby="ipes-versements-titre">
			<header class="section-form__entete">
				<span class="section-form__num"><?php echo ueb_icone( 'banque', 18 ); ?></span>
				<div><h2 id="ipes-versements-titre">Versements de pension</h2><p>Un versement placé dans un bordereau n’est plus modifiable : il est reversé à la tutelle, ou en passe de l’être.</p></div>
			</header>
			<div class="section-form__corps">
				<?php $resume_erreurs( $erreurs_versement, 'Le versement n’a pas été enregistré.' ); ?>
				<form class="formulaire ipes-versement-ajout" method="post" action="<?php echo esc_url( ueb_url_espace_ipes() ); ?>" data-formulaire novalidate>
					<?php ueb_champ_csrf(); ?>
					<input type="hidden" name="ueb_action" value="ipes_paiement_enregistrer">
					<input type="hidden" name="etudiant_id" value="<?php echo (int) $etudiant->id; ?>">
					<?php
					$nouveau_versement = $erreurs_versement && empty( $saisie['paiement_id'] );
					ueb_champ( array( 'nom' => 'montant', 'libelle' => 'Montant versé (FCFA)', 'icone' => 'banque', 'valeur' => $nouveau_versement ? (string) ( $saisie['montant'] ?? '' ) : '', 'erreur' => $nouveau_versement ? ( $erreurs_versement['montant'] ?? '' ) : '', 'attrs' => array( 'inputmode' => 'numeric', 'autocomplete' => 'off', 'placeholder' => '25 000' ) ) );
					ueb_champ( array( 'nom' => 'date_paiement', 'libelle' => 'Date du versement', 'type' => 'date', 'valeur' => $nouveau_versement ? (string) ( $saisie['date_paiement'] ?? '' ) : current_time( 'Y-m-d' ), 'erreur' => $nouveau_versement ? ( $erreurs_versement['date_paiement'] ?? '' ) : '', 'attrs' => array( 'max' => current_time( 'Y-m-d' ) ) ) );
					?>
					<button class="btn btn--primaire" type="submit"><?php echo ueb_icone( 'plus', 18 ); ?>Enregistrer</button>
				</form>

				<?php if ( ! $versements ) : ?>
					<div class="bo-vide"><span><?php echo ueb_icone( 'banque', 22 ); ?></span><p>Aucun versement pour l’instant.</p></div>
				<?php else : ?>
					<div class="tableau-conteneur">
						<table class="tableau">
							<thead><tr><th>Date</th><th class="num">Montant</th><th>Reversement</th><th><span class="sr">Actions</span></th></tr></thead>
							<tbody>
							<?php foreach ( $versements as $v ) : ?>
								<tr>
									<td class="num"><?php echo esc_html( mysql2date( 'd/m/Y', $v->date_paiement ) ); ?></td>
									<td class="num"><b><?php echo esc_html( ueb_fcfa( $v->montant ) ); ?></b></td>
									<td><?php if ( $v->bordereau_id ) : ?><a href="<?php echo $ici( array( 'vue' => 'bordereaux', 'bordereau' => (int) $v->bordereau_id ) ); ?>"><?php echo esc_html( 'Dans le ' . $numeros[ $v->bordereau_id ] ); ?></a><?php else : ?><span class="badge badge--genere"><i></i>À reverser</span><?php endif; ?></td>
									<td class="actions-ligne">
										<?php if ( ! $v->bordereau_id ) : ?>
											<button class="btn btn--fantome btn--petit" type="button" data-ouvrir-agent-mdp="versement-<?php echo (int) $v->id; ?>"><?php echo ueb_icone( 'crayon', 16 ); ?>Modifier</button>
											<dialog class="bo-agent-mdp" id="versement-<?php echo (int) $v->id; ?>" aria-labelledby="versement-titre-<?php echo (int) $v->id; ?>">
												<h2 id="versement-titre-<?php echo (int) $v->id; ?>">Modifier le versement</h2>
												<form method="post" action="<?php echo esc_url( ueb_url_espace_ipes() ); ?>">
													<?php ueb_champ_csrf(); ?>
													<input type="hidden" name="ueb_action" value="ipes_paiement_enregistrer">
													<input type="hidden" name="etudiant_id" value="<?php echo (int) $etudiant->id; ?>">
													<input type="hidden" name="paiement_id" value="<?php echo (int) $v->id; ?>">
													<label><span>Montant (FCFA)</span><input type="text" name="montant" value="<?php echo esc_attr( ueb_formater_montant( (int) $v->montant ) ); ?>" inputmode="numeric" autocomplete="off" required></label>
													<label><span>Date</span><input type="date" name="date_paiement" value="<?php echo esc_attr( $v->date_paiement ); ?>" max="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" required></label>
													<div class="bo-agent-mdp__actions"><button class="btn btn--lien btn--petit" type="button" data-fermer-agent-mdp>Annuler</button><button class="btn btn--primaire btn--petit" type="submit">Enregistrer</button></div>
												</form>
											</dialog>
											<form method="post" action="<?php echo esc_url( ueb_url_espace_ipes() ); ?>" data-confirmer="Supprimer ce versement de <?php echo esc_attr( ueb_fcfa( $v->montant ) ); ?> ?">
												<?php ueb_champ_csrf(); ?>
												<input type="hidden" name="ueb_action" value="ipes_paiement_supprimer">
												<input type="hidden" name="etudiant_id" value="<?php echo (int) $etudiant->id; ?>">
												<input type="hidden" name="paiement_id" value="<?php echo (int) $v->id; ?>">
												<button class="btn btn--lien btn--petit" type="submit">Supprimer</button>
											</form>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</div>
		</section>

		<?php $resume_erreurs( $erreurs_etudiant, 'Vérifie les informations suivantes.' ); ?>
		<section class="carte section-form" aria-labelledby="ipes-infos-titre">
			<header class="section-form__entete">
				<span class="section-form__num"><?php echo ueb_icone( 'utilisateur', 18 ); ?></span>
				<div><h2 id="ipes-infos-titre">Informations</h2><p>Corrige une faute de saisie. Pour l’année suivante, ajoute l’étudiant de nouveau, avec le même matricule.</p></div>
			</header>
			<div class="section-form__corps">
				<form class="formulaire" method="post" action="<?php echo esc_url( ueb_url_espace_ipes() ); ?>" data-formulaire novalidate>
					<?php ueb_champ_csrf(); ?>
					<input type="hidden" name="ueb_action" value="ipes_etudiant_enregistrer">
					<input type="hidden" name="etudiant_id" value="<?php echo (int) $etudiant->id; ?>">
					<?php $champs_etudiant( static fn( $c ) => (string) ( $erreurs_etudiant ? ( $saisie[ $c ] ?? '' ) : ( $etudiant->$c ?? '' ) ), $erreurs_etudiant, $etudiant->filiere_id ); ?>
					<div class="ipes-fiche__actions"><button class="btn btn--primaire" type="submit"><?php echo ueb_icone( 'check', 18 ); ?>Enregistrer</button></div>
				</form>
			</div>
		</section>

	<?php endif; ?>

<?php endif; ?>
