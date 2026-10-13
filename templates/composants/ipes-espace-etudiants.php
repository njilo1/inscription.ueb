<?php
/**
 * Espace IPES, étudiants :
 *   - sans « etudiant » : la liste de l'année (filtres en direct) et, à côté,
 *     le formulaire d'ajout (?ajout=1 y place le curseur) ;
 *   - ?etudiant={id}    : la fiche : son reversement, puis ses informations.
 * Attend $ipes, $annee, $ici et $url (page-ipes.php). Toutes les lectures
 * passent par l'IPES du compte : un identifiant d'un autre IPES ne donne rien.
 */
defined( 'ABSPATH' ) || exit;

list( $saisie, $erreurs ) = ueb_reprendre_saisie();
$erreurs_etudiant = $erreurs;

$filieres         = ueb_ipes_filieres( $ipes->id );
$filieres_actives = array_filter( $filieres, static fn( $f ) => (int) $f->actif );
/* Plusieurs tutelles : la filière porte la sienne, c'est elle qui reçoit le reversement. */
$libelle_filiere  = static fn( $f ) => $f->libelle . ( count( $ipes->tutelles ) > 1 ? ' — ' . $f->etablissement : '' );
/* Faculté, filière et niveau du dernier étudiant ajouté : repris pour saisir le suivant. */
$dernier_ajout    = (array) ( $_SESSION['ueb_ipes_dernier_ajout'] ?? array() );
$etudiant_demande = (int) ( $_GET['etudiant'] ?? 0 );

/**
 * Champs d'un étudiant (ajout ou modification), sur une colonne.
 * $valeur : callable champ => valeur affichée ; $garder : filière retirée à garder dans la liste.
 */
$champs_etudiant = static function ( callable $valeur, array $erreurs, $garder = null, $focus = false ) use ( $filieres, $ipes ) {
	$plusieurs = count( $ipes->tutelles ) > 1;
	$options   = array();
	$carte     = array(); // filière => tutelle, pour ne proposer que les filières de la faculté choisie
	foreach ( $filieres as $f ) {
		if ( (int) $f->actif || (int) $f->id === (int) $garder ) {
			$options[ $f->id ] = $f->libelle . ( (int) $f->actif ? '' : ' (retirée)' );
			$carte[ $f->id ]   = $f->etablissement;
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
	if ( $plusieurs ) {
		$facultes = array();
		foreach ( $ipes->tutelles as $s ) {
			$facultes[ $s ] = $s . ' — ' . ( ueb_etablissement( $s )['fr'] ?? $s );
		}
		/* Faculté de l'étudiant : saisie, sinon celle de sa filière. */
		$tutelle = $valeur( 'tutelle' ) ?: ( $carte[ (int) $valeur( 'filiere_id' ) ] ?? '' );
		ueb_champ( array( 'nom' => 'tutelle', 'libelle' => 'Faculté de tutelle', 'type' => 'select', 'icone' => 'bouclier', 'options' => $facultes, 'valeur' => $tutelle, 'erreur' => $erreurs['tutelle'] ?? '', 'aide' => 'Celle qui reçoit le reversement : elle détermine les filières proposées.' ) );
		printf( '<script type="application/json" data-filieres-tutelle>%s</script>', wp_json_encode( array_map( 'strval', $carte ) ) );
	}
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
		'tutelle'    => in_array( strtoupper( sanitize_key( $_GET['tutelle'] ?? '' ) ), $ipes->tutelles, true ) ? strtoupper( sanitize_key( $_GET['tutelle'] ?? '' ) ) : '',
	);
	$liste  = ueb_ipes_etudiants( $ipes->id, $filtres );
	$filtre = '' !== $filtres['recherche'] || $filtres['filiere_id'] || '' !== $filtres['tutelle'];
	$jauge  = ueb_ipes_jauge( $ipes->id );

	ueb_adm_tete( array(
		'titre'      => 'Étudiants',
		'sous_titre' => 'Les étudiants de l’année. Chacun vaut ' . ueb_fcfa( UEB_IPES_REVERSEMENT_PAR_ETUDIANT ) . ' à reverser à la tutelle de sa filière. Un étudiant se saisit chaque année, avec le même matricule.',
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
						<p><?php echo esc_html( $jauge['etudiants'] ? ueb_ipes_pluriel( $jauge['etudiants'], 'étudiant' ) . ', dont ' . $jauge['libres'] . ' encore à reverser.' : 'Aucun étudiant pour l’instant.' ); ?></p>
					</div>
					<?php if ( $jauge['etudiants'] ) : ?>
						<form class="ipes-outils" method="get" action="<?php echo esc_url( ueb_url_espace_ipes() ); ?>" role="search" aria-label="Filtrer les étudiants" data-filtres-direct="ipes-etudiants-resultats"><?php ueb_champ_espace(); ?>
							<input type="hidden" name="vue" value="etudiants">
							<label class="ipes-recherche"><span class="sr">Rechercher un étudiant</span><?php echo ueb_icone( 'loupe', 17 ); ?><input type="search" name="q" value="<?php echo esc_attr( $filtres['recherche'] ); ?>" placeholder="Matricule, nom ou prénom" enterkeyhint="search" autocomplete="off"<?php echo ueb_attr_suggestions_liste( array_map( static fn( $e ) => array( trim( $e->nom . ' ' . $e->prenom ), $e->matricule, $e->matricule ?: trim( $e->nom ) ), $filtre ? ueb_ipes_etudiants( $ipes->id ) : $liste ) ); // phpcs:ignore -- échappé ?>></label>
							<label class="ipes-selecteur"><span class="sr">Filière</span>
								<select name="filiere">
									<option value="">Toutes les filières</option>
									<?php foreach ( $filieres as $f ) : ?>
										<option value="<?php echo (int) $f->id; ?>" <?php selected( $filtres['filiere_id'], (int) $f->id ); ?>><?php echo esc_html( $libelle_filiere( $f ) ); ?></option>
									<?php endforeach; ?>
								</select><?php echo ueb_icone( 'chevron', 16 ); ?>
							</label>
							<?php if ( count( $ipes->tutelles ) > 1 ) : ?>
								<label class="ipes-selecteur"><span class="sr">Tutelle</span>
									<select name="tutelle">
										<option value="">Toutes les tutelles</option>
										<?php foreach ( $ipes->tutelles as $s ) : ?>
											<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $filtres['tutelle'], $s ); ?>><?php echo esc_html( 'Reversés à la ' . $s ); ?></option>
										<?php endforeach; ?>
									</select><?php echo ueb_icone( 'chevron', 16 ); ?>
								</label>
							<?php endif; ?>
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
						<?php ueb_ipes_etudiants_liste( $liste, static fn( $e ) => $url( array( 'vue' => 'etudiants', 'etudiant' => (int) $e->id ) ), 'Ouvrir la fiche de', true ); ?>
					<?php endif; ?>
				</div>
			</section>

			<aside class="ipes-cote">
				<section id="ajout" class="adm-panneau" aria-labelledby="ipes-ajout-titre" tabindex="-1">
					<header class="adm-panneau__tete">
						<div><h2 id="ipes-ajout-titre">Ajouter un étudiant</h2><p>Inscrit pour l’année <?php echo esc_html( $annee['libelle'] ); ?>. Il sera à reverser à la tutelle de sa filière, dans un bordereau.</p></div>
					</header>
					<?php ueb_ipes_resume_erreurs( $erreurs_etudiant, 'Vérifie les informations suivantes.' ); ?>
					<form class="formulaire" method="post" action="<?php echo esc_url( ueb_url_espace_ipes() ); ?>" data-formulaire novalidate>
						<?php ueb_champ_csrf(); ?>
						<input type="hidden" name="ueb_action" value="ipes_etudiant_enregistrer">
						<?php
						/* Après une erreur, la saisie ; sinon faculté, filière et niveau du dernier ajout. */
						$valeur_ajout = $erreurs_etudiant ? static fn( $c ) => (string) ( $saisie[ $c ] ?? '' ) : static fn( $c ) => (string) ( $dernier_ajout[ $c ] ?? '' );
						$champs_etudiant( $valeur_ajout, $erreurs_etudiant, null, isset( $_GET['ajout'] ) && ! $erreurs_etudiant );
						?>
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
		$filiere   = ueb_ipes_filiere( $etudiant->filiere_id );
		$nom       = trim( $etudiant->nom . ' ' . $etudiant->prenom );
		$bordereau = $etudiant->bordereau_id ? ueb_ipes_bordereau( $ipes->id, $etudiant->bordereau_id ) : null;
		$fige      = ueb_ipes_etudiant_fige( $etudiant );

		$actions = '';
		if ( ! $etudiant->bordereau_id ) {
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
		if ( $etudiant->tutelle ) {
			$reperes .= '<li>' . ueb_icone( 'bouclier', 14 ) . esc_html( 'Tutelle : ' . $etudiant->tutelle ) . '</li>';
		}
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
			<section id="reversement" class="adm-panneau ipes-registre" aria-labelledby="ipes-reversement-titre" tabindex="-1">
				<header class="adm-panneau__tete">
					<div><h2 id="ipes-reversement-titre">Reversement</h2><p>L’étudiant est reversé à la tutelle de sa filière dans un bordereau, pour <?php echo esc_html( ueb_fcfa( UEB_IPES_REVERSEMENT_PAR_ETUDIANT ) ); ?>.</p></div>
				</header>
				<div class="ipes-corps">
					<?php if ( ! $bordereau ) : ?>
						<p class="ipes-reversement-etat"><?php echo ueb_ipes_reversement_etudiant( $etudiant, true ); // phpcs:ignore -- échappé ?><span>Il ne figure encore dans aucun bordereau<?php echo $etudiant->tutelle ? esc_html( ' pour la ' . $etudiant->tutelle ) : ''; ?>.</span></p>
						<a class="adm-bouton" href="<?php echo esc_url( $url( array( 'vue' => 'bordereaux' ) ) . '#nouveau-bordereau' ); ?>"><?php echo ueb_icone( 'recu', 16 ); ?>Préparer un bordereau</a>
					<?php else : ?>
						<p class="ipes-reversement-etat">
							<a class="ipes-lien-bordereau" href="<?php echo esc_url( $url( array( 'vue' => 'bordereaux', 'bordereau' => (int) $bordereau->id ) ) ); ?>"><?php echo esc_html( ueb_ipes_numero( $bordereau ) ); ?></a>
							<?php echo ueb_ipes_statut( $bordereau->statut ); // phpcs:ignore -- échappé ?>
							<span><?php echo esc_html( $fige ? 'Bordereau envoyé : l’étudiant n’est plus modifiable.' : 'Bordereau en préparation : l’étudiant reste modifiable, dans une filière de la même tutelle.' ); ?></span>
						</p>
					<?php endif; ?>
				</div>
			</section>
			<section class="adm-panneau ipes-etroit" aria-labelledby="ipes-infos-titre">
				<header class="adm-panneau__tete">
					<div><h2 id="ipes-infos-titre">Informations</h2><p><?php echo esc_html( $fige ? 'Figées : l’étudiant figure dans un bordereau envoyé.' : 'Corrige une faute de saisie. Pour l’année suivante, ajoute l’étudiant de nouveau, avec le même matricule.' ); ?></p></div>
				</header>
				<?php if ( $fige ) : ?>
					<dl class="ipes-faits ipes-faits--pile ipes-corps">
						<div><dt>Matricule</dt><dd><?php echo esc_html( $etudiant->matricule ); ?></dd></div>
						<div><dt>Filière</dt><dd><?php echo esc_html( $filiere ? $libelle_filiere( $filiere ) : 'Filière inconnue' ); ?></dd></div>
						<div><dt>Niveau</dt><dd><?php echo esc_html( ueb_ipes_niveau( $etudiant->niveau ) ); ?></dd></div>
						<div><dt>Téléphone</dt><dd><?php echo esc_html( $etudiant->telephone ? ueb_formater_telephone( $etudiant->telephone ) : 'Non renseigné' ); ?></dd></div>
					</dl>
				<?php else : ?>
					<?php ueb_ipes_resume_erreurs( $erreurs_etudiant, 'Vérifie les informations suivantes.' ); ?>
					<form class="formulaire" method="post" action="<?php echo esc_url( ueb_url_espace_ipes() ); ?>" data-formulaire novalidate>
						<?php ueb_champ_csrf(); ?>
						<input type="hidden" name="ueb_action" value="ipes_etudiant_enregistrer">
						<input type="hidden" name="etudiant_id" value="<?php echo (int) $etudiant->id; ?>">
						<?php $champs_etudiant( static fn( $c ) => (string) ( $erreurs_etudiant ? ( $saisie[ $c ] ?? '' ) : ( $etudiant->$c ?? '' ) ), $erreurs_etudiant, $etudiant->filiere_id ); ?>
						<button class="adm-bouton adm-bouton--primaire ipes-bouton-large" type="submit"><?php echo ueb_icone( 'check', 16 ); ?>Enregistrer</button>
					</form>
				<?php endif; ?>
			</section>
		</div>

	<?php endif; ?>

<?php endif; ?>
