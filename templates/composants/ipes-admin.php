<?php
/**
 * Onglet « IPES » de l'administration (page-administration.php, ?vue=ipes) :
 *   - sans « ipes » : la liste des IPES, avec ses filtres ;
 *   - ?ipes=nouveau : la fiche de création ;
 *   - ?ipes={id}    : la fiche d'un IPES, ses filières et ses comptes.
 *
 * Les formulaires postent sur les actions de inc/ipes.php et
 * inc/ipes-filieres.php, qui revérifient tout côté serveur.
 */
defined( 'ABSPATH' ) || exit;

$ipes_demande = sanitize_key( wp_unslash( $_GET['ipes'] ?? '' ) );

/* Bloc de la fiche où afficher les messages (voir ueb_ipes_retour_bloc()) ;
   lu une seule fois, quelle que soit la vue, pour ne jamais servir deux fois. */
$bloc_messages = in_array( $_SESSION['ueb_ipes_bloc'] ?? '', array( 'filieres', 'comptes' ), true ) ? $_SESSION['ueb_ipes_bloc'] : '';
unset( $_SESSION['ueb_ipes_bloc'] );

/** Date AAAA-MM-JJ affichée JJ/MM/AAAA, ou tiret. */
$ipes_date = static fn( $date ) => $date ? mysql2date( 'd/m/Y', $date ) : '—';
?>

<?php if ( '' === $ipes_demande ) : ?>

	<?php
	$filtres_ipes = array(
		'recherche'     => sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) ),
		'etablissement' => strtoupper( sanitize_key( wp_unslash( $_GET['tutelle'] ?? '' ) ) ),
		'etat'          => sanitize_key( wp_unslash( $_GET['etat'] ?? '' ) ),
	);
	$liste_ipes = ueb_ipes_liste( array(
		'recherche'     => $filtres_ipes['recherche'],
		'etablissement' => $filtres_ipes['etablissement'],
		'actif'         => array( 'actif' => 1, 'inactif' => 0 )[ $filtres_ipes['etat'] ] ?? '',
	) );
	$filtre_actif = '' !== $filtres_ipes['recherche'] || '' !== $filtres_ipes['etablissement'] || '' !== $filtres_ipes['etat'];
	?>

	<header class="page-app__entete">
		<div>
			<h1>Établissements sous tutelle (IPES)</h1>
			<p class="page-app__sous-titre">Instituts privés liés par convention à un ou plusieurs établissements de l’UEb.</p>
		</div>
		<a class="btn btn--primaire" href="<?php echo esc_url( ueb_url_ipes( 'nouveau' ) ); ?>"><?php echo ueb_icone( 'plus', 18 ); ?>Créer un IPES</a>
	</header>
	<?php ueb_afficher_flash(); ?>

	<form class="filtres carte" method="get" action="<?php echo esc_url( ueb_url_administration() ); ?>" role="search" data-filtres-direct="ipes-resultats">
		<input type="hidden" name="vue" value="ipes">
		<div class="champ">
			<label for="ipes-q">Rechercher</label>
			<input id="ipes-q" type="search" name="q" value="<?php echo esc_attr( $filtres_ipes['recherche'] ); ?>" placeholder="Sigle ou nom de l’IPES" enterkeyhint="search" autocomplete="off">
		</div>
		<div class="champ">
			<label for="ipes-tutelle">Tutelle</label>
			<div class="champ__select">
				<select id="ipes-tutelle" name="tutelle">
					<option value="">Tous les établissements</option>
					<?php foreach ( ueb_etablissements() as $sigle => $e ) : ?>
						<option value="<?php echo esc_attr( $sigle ); ?>" <?php selected( $filtres_ipes['etablissement'], $sigle ); ?>><?php echo esc_html( $sigle . ' — ' . $e['fr'] ); ?></option>
					<?php endforeach; ?>
				</select><?php echo ueb_icone( 'chevron', 18 ); ?>
			</div>
		</div>
		<div class="champ">
			<label for="ipes-etat">État</label>
			<div class="champ__select">
				<select id="ipes-etat" name="etat">
					<option value="">Tous</option>
					<option value="actif" <?php selected( $filtres_ipes['etat'], 'actif' ); ?>>Actifs</option>
					<option value="inactif" <?php selected( $filtres_ipes['etat'], 'inactif' ); ?>>Désactivés</option>
				</select><?php echo ueb_icone( 'chevron', 18 ); ?>
			</div>
		</div>
		<button class="btn btn--primaire" type="submit" data-filtres-bouton><?php echo ueb_icone( 'loupe', 18 ); ?>Rechercher</button>
	</form>

	<div id="ipes-resultats" class="ipes-resultats">
	<p class="texte-discret ipes-resultats__nombre" role="status"><?php echo esc_html( count( $liste_ipes ) . ' IPES' . ( $filtre_actif ? ' correspondant' . ( count( $liste_ipes ) > 1 ? 's' : '' ) . ' aux filtres' : '' ) ); ?></p>
	<div class="tableau-conteneur">
		<table class="tableau ipes-tableau">
			<thead><tr><th>IPES</th><th>Tutelle</th><th>Ville</th><th>Convention</th><th>État</th><th><span class="sr">Actions</span></th></tr></thead>
			<tbody>
			<?php if ( ! $liste_ipes ) : ?>
				<tr><td colspan="6" class="texte-discret"><?php echo $filtre_actif ? 'Aucun IPES ne correspond à ces filtres.' : 'Aucun IPES enregistré pour l’instant.'; ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $liste_ipes as $ipes ) : $logo = ueb_ipes_logo_url( $ipes ); ?>
				<tr>
					<td>
						<span class="ipes-nom">
							<span class="ipes-logo" aria-hidden="true"><?php if ( $logo ) : ?><img src="<?php echo esc_url( $logo ); ?>" alt="" width="26" height="26" loading="lazy"><?php else : ?><?php echo ueb_icone( 'ecole', 18 ); ?><?php endif; ?></span>
							<span><b><?php echo esc_html( $ipes->sigle ); ?></b><br><small class="texte-discret"><?php echo esc_html( $ipes->nom_fr ); ?></small></span>
						</span>
					</td>
					<td>
						<?php foreach ( $ipes->tutelles as $sigle ) : $e = ueb_etablissement( $sigle ); ?>
							<span class="pastille-etab" style="--etab: <?php echo esc_attr( $e['couleur'] ?? 'var(--vert)' ); ?>" title="<?php echo esc_attr( $e['fr'] ?? $sigle ); ?>"><?php echo esc_html( $sigle ); ?></span>
						<?php endforeach; ?>
					</td>
					<td><?php echo esc_html( $ipes->ville ?: '—' ); ?></td>
					<td><?php echo esc_html( $ipes->convention_ref ?: '—' ); ?><?php if ( $ipes->convention_signee_le ) : ?><br><small class="texte-discret">signée le <?php echo esc_html( $ipes_date( $ipes->convention_signee_le ) ); ?></small><?php endif; ?></td>
					<td><?php echo (int) $ipes->actif ? '<span class="badge badge--verifie"><i></i>Actif</span>' : '<span class="badge badge--rejete"><i></i>Désactivé</span>'; ?></td>
					<td class="actions-ligne"><a class="btn btn--lien btn--petit" href="<?php echo esc_url( ueb_url_ipes( (int) $ipes->id ) ); ?>">Ouvrir<?php echo ueb_icone( 'fleche', 16 ); ?></a></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	</div>

<?php else : ?>

	<?php
	$nouveau = 'nouveau' === $ipes_demande;
	$ipes    = $nouveau ? null : ueb_ipes( (int) $ipes_demande );
	list( $saisie, $erreurs ) = ueb_reprendre_saisie();
	?>
	<a class="fil" href="<?php echo esc_url( ueb_url_ipes() ); ?>"><?php echo ueb_icone( 'fleche-g', 18 ); ?>Tous les IPES</a>

	<?php if ( ! $nouveau && ! $ipes ) : ?>

		<header class="page-app__entete"><div><h1>IPES introuvable</h1></div></header>
		<?php ueb_afficher_flash(); ?>
		<div class="bo-vide bo-vide--large"><span><?php echo ueb_icone( 'ecole', 24 ); ?></span><p><b>Cet IPES n’existe pas.</b> Il a peut-être été saisi avec une autre adresse : retrouve-le dans la liste.</p></div>

	<?php else : ?>

		<?php
		/* Après un échec, la saisie prime ; sinon les valeurs enregistrées. */
		$valeur   = static fn( $champ ) => (string) ( $saisie[ $champ ] ?? ( $ipes->$champ ?? '' ) );
		$erreur   = static fn( $champ ) => (string) ( $erreurs[ $champ ] ?? '' );
		/* L'erreur d'ajout d'une filière s'affiche sous son champ, pas dans le résumé de la fiche. */
		$erreur_filiere = $erreur( 'libelle' );
		$erreurs        = array_diff_key( $erreurs, array( 'libelle' => 1 ) );
		$tutelles = array_key_exists( 'tutelles', $saisie ) ? (array) $saisie['tutelles'] : ( $ipes->tutelles ?? array() );
		$logo     = $ipes ? ueb_ipes_logo_url( $ipes ) : null;
		?>
		<header class="page-app__entete">
			<div class="ipes-nom">
				<span class="ipes-logo ipes-logo--grand" aria-hidden="true"><?php if ( $logo ) : ?><img src="<?php echo esc_url( $logo ); ?>" alt="" width="40" height="40"><?php else : ?><?php echo ueb_icone( 'ecole', 22 ); ?><?php endif; ?></span>
				<div>
					<h1><?php echo $ipes ? esc_html( $ipes->sigle ) : 'Nouvel IPES'; ?></h1>
					<p class="page-app__sous-titre">
						<?php if ( $ipes ) : ?>
							<?php echo esc_html( $ipes->nom_fr ); ?>
							· <?php echo (int) $ipes->actif ? '<span class="badge badge--verifie"><i></i>Actif</span>' : '<span class="badge badge--rejete"><i></i>Désactivé</span>'; ?>
						<?php else : ?>
							Renseigne l’institut, sa convention et le ou les établissements de l’UEb qui en assurent la tutelle.
						<?php endif; ?>
					</p>
				</div>
			</div>
			<?php if ( $ipes ) : ?>
				<form method="post" action="<?php echo esc_url( ueb_url_administration() ); ?>" data-confirmer="<?php echo (int) $ipes->actif ? 'Désactiver cet IPES ? Ses administrateurs perdront leur accès. Rien n’est supprimé.' : 'Réactiver cet IPES ? Ses administrateurs retrouveront leur accès.'; ?>">
					<?php ueb_champ_csrf(); ?>
					<input type="hidden" name="ueb_action" value="ipes_etat">
					<input type="hidden" name="ipes_id" value="<?php echo (int) $ipes->id; ?>">
					<button class="btn btn--fantome" type="submit"><?php echo ueb_icone( (int) $ipes->actif ? 'pause' : 'lecture', 18 ); ?><?php echo (int) $ipes->actif ? 'Désactiver' : 'Réactiver'; ?></button>
				</form>
			<?php endif; ?>
		</header>
		<?php if ( ! $bloc_messages || ! $ipes ) { ueb_afficher_flash(); } ?>

		<?php if ( $erreurs ) : ?>
			<div class="alerte alerte--erreur ipes-erreurs" role="alert" tabindex="-1" aria-labelledby="ipes-erreurs-titre" data-resume-erreurs>
				<?php echo ueb_icone( 'alerte', 20 ); ?>
				<div>
					<p id="ipes-erreurs-titre"><strong>Vérifie les informations suivantes.</strong></p>
					<ul>
						<?php foreach ( $erreurs as $champ => $message ) : ?>
							<li><?php if ( 'general' === $champ ) : ?><?php echo esc_html( $message ); ?><?php else : ?><a href="#champ-<?php echo esc_attr( $champ ); ?>" data-lien-erreur><?php echo esc_html( $message ); ?></a><?php endif; ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			</div>
		<?php endif; ?>

		<form class="ipes-fiche" method="post" action="<?php echo esc_url( ueb_url_administration() ); ?>" enctype="multipart/form-data" data-formulaire novalidate>
			<?php ueb_champ_csrf(); ?>
			<input type="hidden" name="ueb_action" value="ipes_enregistrer">
			<input type="hidden" name="ipes_id" value="<?php echo $ipes ? (int) $ipes->id : ''; ?>">

			<section class="carte section-form" aria-labelledby="ipes-identite">
				<header class="section-form__entete">
					<span class="section-form__num">1</span>
					<div><h2 id="ipes-identite">Identité</h2><p>Le sigle identifie l’IPES partout sur la plateforme. Il ne peut pas reprendre celui d’un établissement de l’UEb.</p></div>
				</header>
				<div class="section-form__corps formulaire">
					<div class="formulaire__rangee">
						<?php
						ueb_champ( array( 'nom' => 'sigle', 'libelle' => 'Sigle', 'icone' => 'ecole', 'valeur' => $valeur( 'sigle' ), 'erreur' => $erreur( 'sigle' ), 'aide' => '2 à 20 caractères : lettres, chiffres ou tiret.', 'attrs' => array( 'maxlength' => 20, 'autocomplete' => 'off', 'autocapitalize' => 'characters', 'spellcheck' => 'false', 'placeholder' => 'SIANTOU' ) ) );
						ueb_champ( array( 'nom' => 'ville', 'libelle' => 'Ville', 'icone' => 'lieu', 'requis' => false, 'valeur' => $valeur( 'ville' ), 'erreur' => $erreur( 'ville' ), 'attrs' => array( 'maxlength' => 100, 'autocomplete' => 'off' ) ) );
						?>
					</div>
					<?php
					ueb_champ( array( 'nom' => 'nom_fr', 'libelle' => 'Nom complet', 'valeur' => $valeur( 'nom_fr' ), 'erreur' => $erreur( 'nom_fr' ), 'attrs' => array( 'maxlength' => 150, 'autocomplete' => 'off', 'placeholder' => 'Institut Supérieur Siantou' ) ) );
					ueb_champ( array( 'nom' => 'nom_en', 'libelle' => 'Nom en anglais', 'requis' => false, 'valeur' => $valeur( 'nom_en' ), 'erreur' => $erreur( 'nom_en' ), 'attrs' => array( 'maxlength' => 150, 'autocomplete' => 'off', 'lang' => 'en' ) ) );
					?>
				</div>
			</section>

			<section class="carte section-form" aria-labelledby="ipes-contacts">
				<header class="section-form__entete">
					<span class="section-form__num">2</span>
					<div><h2 id="ipes-contacts">Contacts</h2><p>Pour joindre l’IPES au sujet de sa convention et de ses reversements.</p></div>
				</header>
				<div class="section-form__corps formulaire">
					<div class="formulaire__rangee">
						<?php
						ueb_champ( array( 'nom' => 'telephone', 'libelle' => 'Téléphone', 'type' => 'tel', 'icone' => 'telephone', 'requis' => false, 'valeur' => $valeur( 'telephone' ) ? ueb_formater_telephone( $valeur( 'telephone' ) ) : '', 'erreur' => $erreur( 'telephone' ), 'aide' => 'Mobile à 9 chiffres, avec ou sans +237.', 'attrs' => array( 'inputmode' => 'tel', 'autocomplete' => 'off' ) ) );
						ueb_champ( array( 'nom' => 'email', 'libelle' => 'Adresse e-mail', 'type' => 'email', 'icone' => 'courriel', 'requis' => false, 'valeur' => $valeur( 'email' ), 'erreur' => $erreur( 'email' ), 'attrs' => array( 'maxlength' => 150, 'autocomplete' => 'off' ) ) );
						?>
					</div>
				</div>
			</section>

			<section class="carte section-form" aria-labelledby="ipes-convention">
				<header class="section-form__entete">
					<span class="section-form__num">3</span>
					<div><h2 id="ipes-convention">Convention</h2><p>La convention qui place l’IPES sous la tutelle de l’UEb.</p></div>
				</header>
				<div class="section-form__corps formulaire">
					<?php ueb_champ( array( 'nom' => 'convention_ref', 'libelle' => 'Référence de la convention', 'icone' => 'fichier', 'requis' => false, 'valeur' => $valeur( 'convention_ref' ), 'erreur' => $erreur( 'convention_ref' ), 'attrs' => array( 'maxlength' => 100, 'autocomplete' => 'off', 'placeholder' => 'CONV-FS-2026-01' ) ) ); ?>
					<div class="formulaire__rangee">
						<?php
						ueb_champ( array( 'nom' => 'convention_signee_le', 'libelle' => 'Signée le', 'type' => 'date', 'requis' => false, 'valeur' => $valeur( 'convention_signee_le' ), 'erreur' => $erreur( 'convention_signee_le' ) ) );
						ueb_champ( array( 'nom' => 'convention_fin_le', 'libelle' => 'Fin de la convention', 'type' => 'date', 'requis' => false, 'valeur' => $valeur( 'convention_fin_le' ), 'erreur' => $erreur( 'convention_fin_le' ), 'aide' => 'Laisse vide si la convention n’a pas de terme.' ) );
						?>
					</div>
				</div>
			</section>

			<section class="carte section-form" aria-labelledby="ipes-tutelles">
				<header class="section-form__entete">
					<span class="section-form__num">4</span>
					<div><h2 id="ipes-tutelles">Établissements de tutelle</h2><p>Un ou plusieurs établissements de l’UEb. Leurs administrateurs verront cet IPES.</p></div>
				</header>
				<div class="section-form__corps">
					<fieldset id="champ-tutelles" tabindex="-1" class="etabs-choix champ<?php echo $erreur( 'tutelles' ) ? ' champ--invalide' : ''; ?>"<?php echo $erreur( 'tutelles' ) ? ' aria-describedby="champ-tutelles-erreur"' : ''; ?>>
						<legend>Tutelle</legend>
						<div class="etabs-choix__grille">
							<?php foreach ( ueb_etablissements() as $sigle => $e ) : ?>
								<label class="etab-case" style="--etab: <?php echo esc_attr( $e['couleur'] ); ?>" title="<?php echo esc_attr( $e['fr'] ); ?>">
									<input type="checkbox" name="tutelles[]" value="<?php echo esc_attr( $sigle ); ?>" <?php checked( in_array( $sigle, $tutelles, true ) ); ?>>
									<span><img src="<?php echo esc_url( ueb_logo_url( $sigle ) ); ?>" alt="" width="26" height="26"><b><?php echo esc_html( $sigle ); ?></b></span>
								</label>
							<?php endforeach; ?>
						</div>
						<?php if ( $erreur( 'tutelles' ) ) : ?>
							<p class="champ__erreur" id="champ-tutelles-erreur"><?php echo ueb_icone( 'alerte', 16 ); ?><?php echo esc_html( $erreur( 'tutelles' ) ); ?></p>
						<?php endif; ?>
					</fieldset>
				</div>
			</section>

			<section class="carte section-form" aria-labelledby="ipes-logo-titre">
				<header class="section-form__entete">
					<span class="section-form__num">5</span>
					<div><h2 id="ipes-logo-titre">Logo <span class="facultatif">(facultatif)</span></h2><p>PNG ou JPEG, 1 Mo au plus. Il est redimensionné et sa transparence est conservée.</p></div>
				</header>
				<div class="section-form__corps">
					<div id="champ-logo" tabindex="-1" class="ipes-logo-choix champ<?php echo $erreur( 'logo' ) ? ' champ--invalide' : ''; ?>" data-logo-ipes>
						<span class="ipes-logo ipes-logo--apercu" aria-hidden="true"><img <?php echo $logo ? 'src="' . esc_url( $logo ) . '"' : 'hidden'; ?> alt="" width="64" height="64" data-logo-apercu><span data-logo-vide <?php echo $logo ? 'hidden' : ''; ?>><?php echo ueb_icone( 'ecole', 26 ); ?></span></span>
						<label class="depot ipes-depot">
							<input type="file" name="logo" accept="image/png,image/jpeg" aria-describedby="logo-aide<?php echo $erreur( 'logo' ) ? ' champ-logo-erreur' : ''; ?>">
							<span class="depot__titre" data-logo-titre><?php echo $logo ? 'Remplacer le logo' : 'Choisir le logo'; ?></span>
							<span class="depot__aide" id="logo-aide"><?php echo $logo ? 'Laisse vide pour garder le logo actuel.' : 'Clique ou glisse l’image ici.'; ?></span>
						</label>
						<?php if ( $erreur( 'logo' ) ) : ?>
							<p class="champ__erreur" id="champ-logo-erreur"><?php echo ueb_icone( 'alerte', 16 ); ?><?php echo esc_html( $erreur( 'logo' ) ); ?></p>
						<?php endif; ?>
					</div>
				</div>
			</section>

			<div class="ipes-fiche__actions">
				<a class="btn btn--fantome" href="<?php echo esc_url( ueb_url_ipes() ); ?>">Annuler</a>
				<button class="btn btn--primaire" type="submit"><?php echo ueb_icone( 'check', 18 ); ?><?php echo $ipes ? 'Enregistrer les modifications' : 'Créer l’IPES'; ?></button>
			</div>
		</form>

		<?php if ( $ipes ) :
			$filieres = ueb_ipes_filieres( $ipes->id );
			$retirees = count( array_filter( $filieres, static fn( $f ) => ! (int) $f->actif ) );
			?>
			<section id="filieres" class="carte section-form" aria-labelledby="ipes-filieres-titre">
				<header class="section-form__entete">
					<span class="section-form__num"><?php echo ueb_icone( 'fichier', 18 ); ?></span>
					<div>
						<h2 id="ipes-filieres-titre">Filières</h2>
						<p>Les formations que l’IPES a communiquées. Une filière retirée reste dans l’historique : ses étudiants y resteront rattachés.</p>
					</div>
				</header>
				<div class="section-form__corps">
					<?php if ( 'filieres' === $bloc_messages ) { ueb_afficher_flash(); } ?>
					<form class="formulaire ipes-filiere-ajout" method="post" action="<?php echo esc_url( ueb_url_administration() ); ?>" data-formulaire novalidate>
						<?php ueb_champ_csrf(); ?>
						<input type="hidden" name="ueb_action" value="ipes_filiere_ajouter">
						<input type="hidden" name="ipes_id" value="<?php echo (int) $ipes->id; ?>">
						<?php ueb_champ( array( 'nom' => 'libelle', 'libelle' => 'Nouvelle filière', 'icone' => 'plus', 'valeur' => $erreur_filiere ? (string) ( $saisie['libelle'] ?? '' ) : '', 'erreur' => $erreur_filiere, 'attrs' => array( 'maxlength' => UEB_IPES_FILIERE_MAX, 'autocomplete' => 'off', 'placeholder' => 'Génie logiciel' ) ) ); ?>
						<button class="btn btn--primaire" type="submit"><?php echo ueb_icone( 'plus', 18 ); ?>Ajouter</button>
					</form>

					<?php if ( ! $filieres ) : ?>
						<div class="bo-vide"><span><?php echo ueb_icone( 'fichier', 22 ); ?></span><p>Aucune filière pour l’instant. Ajoute celles que l’IPES t’a communiquées.</p></div>
					<?php else : ?>
						<p class="texte-discret ipes-resultats__nombre"><?php echo esc_html( count( $filieres ) . ' filière' . ( count( $filieres ) > 1 ? 's' : '' ) . ( $retirees ? ', dont ' . $retirees . ' retirée' . ( $retirees > 1 ? 's' : '' ) : '' ) ); ?></p>
						<div class="tableau-conteneur">
							<table class="tableau">
								<thead><tr><th>Filière</th><th>État</th><th><span class="sr">Actions</span></th></tr></thead>
								<tbody>
								<?php foreach ( $filieres as $filiere ) : $active = (int) $filiere->actif; ?>
									<tr>
										<td><b><?php echo esc_html( $filiere->libelle ); ?></b></td>
										<td><?php echo $active ? '<span class="badge badge--verifie"><i></i>Active</span>' : '<span class="badge badge--rejete"><i></i>Retirée</span>'; ?></td>
										<td class="actions-ligne">
											<button class="btn btn--fantome btn--petit" type="button" data-ouvrir-agent-mdp="filiere-<?php echo (int) $filiere->id; ?>"><?php echo ueb_icone( 'crayon', 16 ); ?>Renommer</button>
											<dialog class="bo-agent-mdp" id="filiere-<?php echo (int) $filiere->id; ?>" aria-labelledby="filiere-titre-<?php echo (int) $filiere->id; ?>">
												<h2 id="filiere-titre-<?php echo (int) $filiere->id; ?>">Renommer la filière</h2>
												<p>Le nouveau nom remplace « <?php echo esc_html( $filiere->libelle ); ?> » partout.</p>
												<form method="post" action="<?php echo esc_url( ueb_url_administration() ); ?>">
													<?php ueb_champ_csrf(); ?>
													<input type="hidden" name="ueb_action" value="ipes_filiere_renommer">
													<input type="hidden" name="ipes_id" value="<?php echo (int) $ipes->id; ?>">
													<input type="hidden" name="filiere_id" value="<?php echo (int) $filiere->id; ?>">
													<label><span>Nouveau nom</span><input type="text" name="libelle" value="<?php echo esc_attr( $filiere->libelle ); ?>" minlength="<?php echo (int) UEB_IPES_FILIERE_MIN; ?>" maxlength="<?php echo (int) UEB_IPES_FILIERE_MAX; ?>" autocomplete="off" required></label>
													<div class="bo-agent-mdp__actions"><button class="btn btn--lien btn--petit" type="button" data-fermer-agent-mdp>Annuler</button><button class="btn btn--primaire btn--petit" type="submit">Enregistrer</button></div>
												</form>
											</dialog>
											<form method="post" action="<?php echo esc_url( ueb_url_administration() ); ?>"<?php echo $active ? ' data-confirmer="Retirer la filière « ' . esc_attr( $filiere->libelle ) . ' » ? Elle reste dans l’historique et peut être rétablie."' : ''; ?>>
												<?php ueb_champ_csrf(); ?>
												<input type="hidden" name="ueb_action" value="ipes_filiere_etat">
												<input type="hidden" name="ipes_id" value="<?php echo (int) $ipes->id; ?>">
												<input type="hidden" name="filiere_id" value="<?php echo (int) $filiere->id; ?>">
												<button class="btn btn--lien btn--petit" type="submit"><?php echo $active ? 'Retirer' : 'Rétablir'; ?></button>
											</form>
										</td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</div>
			</section>

			<?php
			$comptes = ueb_ipes_comptes( $ipes->id );
			/* Mot de passe provisoire : affiché une seule fois, juste après sa création. */
			$prov_ipes = $_SESSION['ueb_mdp_ipes'] ?? null;
			unset( $_SESSION['ueb_mdp_ipes'] );
			?>
			<section id="comptes" class="carte section-form" aria-labelledby="ipes-comptes-titre">
				<header class="section-form__entete">
					<span class="section-form__num"><?php echo ueb_icone( 'utilisateur', 18 ); ?></span>
					<div>
						<h2 id="ipes-comptes-titre">Administrateur de l’IPES</h2>
						<p>Le compte avec lequel l’IPES déclarera ses étudiants et ses reversements. Il n’a accès à rien d’autre sur la plateforme.</p>
					</div>
				</header>
				<div class="section-form__corps">
					<?php if ( 'comptes' === $bloc_messages ) { ueb_afficher_flash(); } ?>
					<?php if ( $prov_ipes ) : ?>
						<div class="provisoire carte" role="status">
							<?php echo ueb_icone( 'cle', 26 ); ?>
							<div>
								<p>Mot de passe provisoire pour <b><?php echo esc_html( $prov_ipes['compte'] ); ?></b> — à communiquer à l’IPES, il ne sera plus affiché :</p>
								<p class="provisoire__mdp"><?php echo esc_html( $prov_ipes['mdp'] ); ?></p>
								<button type="button" class="btn btn--fantome btn--petit provisoire__copier" data-copier-mot-de-passe="<?php echo esc_attr( $prov_ipes['mdp'] ); ?>"><?php echo ueb_icone( 'fichier', 16 ); ?><span>Copier le mot de passe</span></button>
							</div>
						</div>
					<?php endif; ?>

					<?php if ( (int) $ipes->actif ) : ?>
						<form class="formulaire ipes-compte-form" method="post" action="<?php echo esc_url( ueb_url_administration() ); ?>" data-formulaire novalidate>
							<?php ueb_champ_csrf(); ?>
							<input type="hidden" name="ueb_action" value="ipes_compte_creer">
							<input type="hidden" name="ipes_id" value="<?php echo (int) $ipes->id; ?>">
							<div class="formulaire__rangee">
								<?php
								ueb_champ( array( 'nom' => 'login', 'id' => 'compte-login', 'libelle' => 'Identifiant de connexion', 'icone' => 'utilisateur', 'attrs' => array( 'placeholder' => 'admin.' . strtolower( $ipes->sigle ), 'autocapitalize' => 'none', 'spellcheck' => 'false', 'autocomplete' => 'off' ) ) );
								ueb_champ( array( 'nom' => 'nom', 'id' => 'compte-nom', 'libelle' => 'Nom du responsable', 'icone' => 'utilisateur', 'requis' => false, 'attrs' => array( 'placeholder' => 'Nom et prénom', 'autocomplete' => 'off' ) ) );
								?>
							</div>
							<?php ueb_champ( array( 'nom' => 'email', 'id' => 'compte-email', 'libelle' => 'Adresse e-mail', 'type' => 'email', 'icone' => 'courriel', 'requis' => false, 'aide' => 'Utile pour récupérer un mot de passe oublié.', 'attrs' => array( 'autocomplete' => 'off' ) ) ); ?>
							<p class="champ__aide">Le mot de passe provisoire est créé automatiquement et affiché une seule fois.</p>
							<div class="securite-form__actions">
								<button class="btn btn--primaire" type="submit"><?php echo ueb_icone( 'plus', 18 ); ?>Créer le compte</button>
							</div>
						</form>
					<?php else : ?>
						<div class="bo-vide"><span><?php echo ueb_icone( 'cadenas', 22 ); ?></span><p><b>Cet IPES est désactivé.</b> Réactive-le pour lui créer un compte ou rétablir ses accès.</p></div>
					<?php endif; ?>

					<?php if ( $comptes ) : ?>
						<div class="tableau-conteneur ipes-comptes">
							<table class="tableau">
								<thead><tr><th>Compte</th><th>Créé le</th><th>État</th><th><span class="sr">Actions</span></th></tr></thead>
								<tbody>
								<?php foreach ( $comptes as $compte ) :
									$suspendu   = ueb_agent_suspendu( $compte->ID );
									$avec_ipes  = $suspendu && get_user_meta( $compte->ID, 'ueb_suspendu_avec_ipes', true );
									?>
									<tr>
										<td><b><?php echo esc_html( $compte->display_name ); ?></b><br><small class="texte-discret"><?php echo esc_html( $compte->user_login ); ?><?php echo $compte->user_email ? ' · ' . esc_html( $compte->user_email ) : ''; ?></small></td>
										<td class="num"><?php echo esc_html( mysql2date( 'd/m/Y', $compte->user_registered ) ); ?></td>
										<td><?php echo $suspendu ? '<span class="badge badge--rejete"><i></i>' . ( $avec_ipes ? 'Suspendu avec l’IPES' : 'Suspendu' ) . '</span>' : '<span class="badge badge--verifie"><i></i>Actif</span>'; ?></td>
										<td class="actions-ligne">
											<form method="post" action="<?php echo esc_url( ueb_url_administration() ); ?>" data-confirmer="Créer un nouveau mot de passe provisoire pour <?php echo esc_attr( $compte->user_login ); ?> ? L’ancien ne fonctionnera plus et ses sessions seront fermées.">
												<?php ueb_champ_csrf(); ?>
												<input type="hidden" name="ueb_action" value="ipes_compte_mdp">
												<input type="hidden" name="ipes_id" value="<?php echo (int) $ipes->id; ?>">
												<input type="hidden" name="compte_id" value="<?php echo (int) $compte->ID; ?>">
												<button class="btn btn--fantome btn--petit" type="submit"><?php echo ueb_icone( 'cle', 16 ); ?>Nouveau mot de passe</button>
											</form>
											<?php if ( ! $suspendu || (int) $ipes->actif ) : ?>
												<form method="post" action="<?php echo esc_url( ueb_url_administration() ); ?>" data-confirmer="<?php echo $suspendu ? 'Rétablir l’accès de ce compte ?' : 'Suspendre ce compte ? Il ne pourra plus se connecter ; il est conservé.'; ?>">
													<?php ueb_champ_csrf(); ?>
													<input type="hidden" name="ueb_action" value="ipes_compte_etat">
													<input type="hidden" name="ipes_id" value="<?php echo (int) $ipes->id; ?>">
													<input type="hidden" name="compte_id" value="<?php echo (int) $compte->ID; ?>">
													<button class="btn btn--lien btn--petit" type="submit"><?php echo $suspendu ? 'Rétablir' : 'Suspendre'; ?></button>
												</form>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php elseif ( (int) $ipes->actif ) : ?>
						<p class="texte-discret">Aucun compte pour l’instant.</p>
					<?php endif; ?>
				</div>
			</section>
		<?php endif; ?>

	<?php endif; ?>

<?php endif; ?>
