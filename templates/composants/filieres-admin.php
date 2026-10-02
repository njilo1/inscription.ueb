<?php
/**
 * Onglet « Filières » de l'administration (page-administration.php,
 * ?vue=filieres) : le catalogue proposé dans le quitus, établissement par
 * établissement.
 *   - à gauche : la liste, filtrable en direct (recherche, établissement, état) ;
 *   - à droite : l'ajout d'une filière ; avec ?modifier={id}, sa modification.
 * Les formulaires postent sur les actions de inc/filieres-catalogue.php, qui
 * revérifient tout. Composants et styles de l'onglet IPES (assets/css/ipes.css).
 */
defined( 'ABSPATH' ) || exit;

list( $saisie, $erreurs ) = ueb_reprendre_saisie();
$filtres = array(
	'recherche'     => sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) ),
	'etablissement' => ueb_etablissement( sanitize_key( $_GET['etab'] ?? '' ) ) ? strtoupper( sanitize_key( $_GET['etab'] ) ) : '',
	'etat'          => sanitize_key( $_GET['etat'] ?? '' ),
);
$filtre_actif = '' !== $filtres['recherche'] || '' !== $filtres['etablissement'] || in_array( $filtres['etat'], array( 'ouverte', 'fermee' ), true );
$liste        = ueb_catalogue_filieres( $filtres );
$comptes      = ueb_catalogue_compte_par_etablissement();
$total        = array_sum( array_map( static fn( $c ) => $c[0] + $c[1], $comptes ) );
$fermees      = array_sum( array_map( static fn( $c ) => $c[1], $comptes ) );

/* Modification : la filière demandée ; les erreurs d'un envoi portent « filiere_id ». */
$modifiee = isset( $_GET['modifier'] ) ? ueb_catalogue_filiere( (int) $_GET['modifier'] ) : null;
$erreurs_form = ( $erreurs && (int) ( $saisie['filiere_id'] ?? 0 ) === (int) ( $modifiee->id ?? 0 ) ) ? $erreurs : array();
$valeur = static function ( $champ, $defaut = '' ) use ( $saisie, $erreurs_form, $modifiee ) {
	if ( $erreurs_form ) {
		return (string) ( $saisie[ $champ ] ?? $defaut );
	}
	return (string) ( $modifiee->$champ ?? $defaut );
};

ueb_adm_tete( array(
	'titre'      => 'Filières',
	'sous_titre' => 'Le catalogue proposé aux étudiants dans le quitus. Une filière fermée n’est plus proposée ; les quitus déjà établis la gardent.',
	'actions'    => ueb_catalogue_disponible() ? ueb_adm_action( ueb_url_filieres( array( 'ajout' => 1 ) ) . '#ajout', 'Ajouter une filière', 'plus', true ) : '',
) );
ueb_afficher_flash();
?>

<?php if ( ! ueb_catalogue_disponible() ) : ?>

	<div class="bo-vide bo-vide--large"><span><?php echo ueb_icone( 'fichier', 24 ); ?></span><p><b>Le catalogue des filières n’est pas encore dans cette base.</b> Importe les tables <code>ueb_facultes</code> et <code>ueb_filieres</code> depuis la base de la préinscription (phpMyAdmin), puis recharge cette page.</p></div>

<?php else : ?>

	<div class="ipes-deux">
		<section class="adm-panneau ipes-registre" aria-labelledby="filieres-titre">
			<header class="adm-panneau__tete">
				<div>
					<h2 id="filieres-titre">Catalogue</h2>
					<p><?php echo esc_html( $total ? ueb_ipes_pluriel( $total, 'filière' ) . ( $fermees ? ', dont ' . ueb_ipes_pluriel( $fermees, 'fermée' ) : ', toutes ouvertes' ) . '.' : 'Aucune filière pour l’instant.' ); ?></p>
				</div>
				<form class="ipes-outils" method="get" action="<?php echo esc_url( ueb_url_administration() ); ?>" role="search" aria-label="Filtrer les filières" data-filtres-direct="filieres-resultats">
					<input type="hidden" name="vue" value="filieres">
					<label class="ipes-recherche"><span class="sr">Rechercher une filière</span><?php echo ueb_icone( 'loupe', 17 ); ?><input type="search" name="q" value="<?php echo esc_attr( $filtres['recherche'] ); ?>" placeholder="Nom ou code" enterkeyhint="search" autocomplete="off"></label>
					<label class="ipes-selecteur"><span class="sr">Établissement</span>
						<select name="etab">
							<option value="">Tous les établissements</option>
							<?php foreach ( ueb_etablissements() as $sigle => $e ) : $c = $comptes[ $sigle ] ?? array( 0, 0 ); ?>
								<option value="<?php echo esc_attr( strtolower( $sigle ) ); ?>" <?php selected( $filtres['etablissement'], $sigle ); ?>><?php echo esc_html( $sigle . ' (' . ( $c[0] + $c[1] ) . ')' ); ?></option>
							<?php endforeach; ?>
						</select><?php echo ueb_icone( 'chevron', 16 ); ?>
					</label>
					<label class="ipes-selecteur"><span class="sr">État</span>
						<select name="etat">
							<option value="">Ouvertes et fermées</option>
							<option value="ouverte" <?php selected( $filtres['etat'], 'ouverte' ); ?>>Ouvertes</option>
							<option value="fermee" <?php selected( $filtres['etat'], 'fermee' ); ?>>Fermées</option>
						</select><?php echo ueb_icone( 'chevron', 16 ); ?>
					</label>
					<button class="adm-bouton" type="submit" data-filtres-bouton><?php echo ueb_icone( 'loupe', 16 ); ?>Rechercher</button>
				</form>
			</header>
			<div id="filieres-resultats" class="ipes-resultats" aria-live="polite">
				<?php if ( $filtre_actif ) : ?>
					<p class="ipes-compte"><b><?php echo esc_html( ueb_ipes_pluriel( count( $liste ), 'filière' ) ); ?></b> <?php echo count( $liste ) > 1 ? 'correspondent' : 'correspond'; ?> aux filtres.</p>
				<?php endif; ?>
				<?php if ( ! $liste ) : ?>
					<div class="bo-vide ipes-vide"><span><?php echo ueb_icone( $filtre_actif ? 'loupe' : 'fichier', 22 ); ?></span><p><?php echo $filtre_actif ? '<b>Aucune filière ne correspond.</b> Cherche par nom ou par code.' : '<b>Aucune filière.</b> Ajoute la première avec le formulaire.'; ?></p></div>
				<?php else : ?>
					<table class="adm-registre__table ipes-table catalogue-table">
						<thead><tr>
							<th scope="col">Filière</th>
							<th scope="col">Type · niveaux</th>
							<th scope="col" class="num">Quitus</th>
							<th scope="col">État</th>
							<th scope="col"><span class="sr">Actions</span></th>
						</tr></thead>
						<?php
						$etab_courant = null;
						foreach ( $liste as $f ) :
							$fid    = (int) $f->id;
							$active = (int) $f->actif;
							if ( $f->etablissement !== $etab_courant ) :
								if ( null !== $etab_courant ) {
									echo '</tbody>';
								}
								$etab_courant = $f->etablissement;
								$e            = ueb_etablissement( $f->etablissement );
								?>
								<tbody class="catalogue-groupe" style="--etab: <?php echo esc_attr( $e['couleur'] ?? 'var(--vert)' ); ?>">
									<tr class="catalogue-groupe__tete"><th scope="rowgroup" colspan="5"><?php echo ueb_ipes_pastilles_html( array( $f->etablissement ) ); // phpcs:ignore -- échappé ?><span><?php echo esc_html( $e['fr'] ?? $f->etablissement ); ?></span></th></tr>
							<?php endif; ?>
							<tr id="filiere-<?php echo $fid; ?>" class="ipes-ligne<?php echo $active ? '' : ' est-inactif'; ?><?php echo $modifiee && (int) $modifiee->id === $fid ? ' est-selectionnee' : ''; ?>">
								<td class="ipes-c-qui"><span class="ipes-ligne__texte"><b><?php echo esc_html( $f->libelle ); ?></b><small><?php echo esc_html( $f->code ); ?></small></span></td>
								<td data-titre="Type · niveaux"><span class="ipes-ligne__texte"><span><?php echo esc_html( UEB_TYPES_FORMATION[ $f->type_formation ] ?? $f->type_formation ); ?></span><small><?php echo esc_html( $f->niveaux ?: 'Aucun niveau : pas proposée au quitus' ); ?></small></span></td>
								<td class="num ipes-ligne__nombre" data-titre="Quitus"><b><?php echo (int) $f->nb_quitus; ?></b></td>
								<td data-titre="État"><?php echo $active ? '<span class="ipes-statut ipes-statut--verifie">' . ueb_icone( 'check', 14 ) . 'Ouverte</span>' : '<span class="ipes-statut ipes-statut--libre">' . ueb_icone( 'pause', 14 ) . 'Fermée</span>'; // phpcs:ignore -- SVG interne ?></td>
								<td class="ipes-ligne__actions">
									<div class="ipes-actions">
										<a class="adm-bouton adm-bouton--petit adm-bouton--icone" href="<?php echo esc_url( ueb_url_filieres( array_filter( array( 'modifier' => $fid, 'q' => $filtres['recherche'], 'etab' => strtolower( $filtres['etablissement'] ), 'etat' => $filtres['etat'] ) ) ) . '#modifier' ); ?>" aria-label="<?php echo esc_attr( 'Modifier ' . $f->libelle ); ?>" title="Modifier"><?php echo ueb_icone( 'crayon', 15 ); ?></a>
										<form method="post" action="<?php echo esc_url( ueb_url_administration() ); ?>"<?php echo $active ? ' data-confirmer="' . esc_attr( 'Fermer « ' . $f->libelle . ' » (' . $f->etablissement . ') ? Elle ne sera plus proposée dans le quitus ; les quitus déjà établis la gardent.' ) . '"' : ''; ?>>
											<?php ueb_champ_csrf(); ?>
											<input type="hidden" name="ueb_action" value="catalogue_filiere_etat">
											<input type="hidden" name="filiere_id" value="<?php echo $fid; ?>">
											<button class="adm-bouton adm-bouton--petit" type="submit"><?php echo $active ? 'Fermer' : 'Rouvrir'; ?></button>
										</form>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</section>

		<aside class="ipes-cote">
			<?php if ( $modifiee ) : ?>
				<section id="modifier" class="adm-panneau" aria-labelledby="filiere-modif-titre" tabindex="-1">
					<header class="adm-panneau__tete">
						<div><h2 id="filiere-modif-titre">Modifier la filière</h2><p><?php echo esc_html( $modifiee->etablissement . ' · ' . ueb_ipes_pluriel( (int) ( $modifiee->nb_quitus ?? 0 ), 'quitus', 'quitus' ) ); ?>. L’établissement d’une filière ne change pas.</p></div>
					</header>
					<?php ueb_ipes_resume_erreurs( $erreurs_form, 'La filière n’a pas été modifiée.' ); ?>
					<form class="formulaire" method="post" action="<?php echo esc_url( ueb_url_administration() ); ?>" data-formulaire novalidate>
						<?php ueb_champ_csrf(); ?>
						<input type="hidden" name="ueb_action" value="catalogue_filiere_enregistrer">
						<input type="hidden" name="filiere_id" value="<?php echo (int) $modifiee->id; ?>">
						<?php
						ueb_champ( array( 'nom' => 'libelle', 'libelle' => 'Nom de la filière', 'valeur' => $valeur( 'libelle' ), 'erreur' => $erreurs_form['libelle'] ?? '', 'attrs' => array( 'maxlength' => 150, 'autocomplete' => 'off' ) ) );
						ueb_champ( array( 'nom' => 'code', 'libelle' => 'Code', 'valeur' => $valeur( 'code' ), 'erreur' => $erreurs_form['code'] ?? '', 'aide' => 'Lettres, chiffres, « _ » ou « - ».', 'attrs' => array( 'maxlength' => 30, 'autocomplete' => 'off', 'autocapitalize' => 'characters', 'spellcheck' => 'false' ) ) );
						ueb_champ( array( 'nom' => 'type_formation', 'libelle' => 'Type de formation', 'type' => 'select', 'options' => UEB_TYPES_FORMATION, 'valeur' => $valeur( 'type_formation', 'classique' ), 'erreur' => $erreurs_form['type_formation'] ?? '', 'aide' => 'Classique : droits fixés par l’université. Professionnelle : montant communiqué par l’établissement.' ) );
						ueb_champ( array( 'nom' => 'cycle', 'libelle' => 'Cycle', 'type' => 'select', 'options' => UEB_CYCLES_FILIERE, 'valeur' => $valeur( 'cycle', 'tous' ), 'erreur' => $erreurs_form['cycle'] ?? '' ) );
						?>
						<div class="ipes-boutons">
							<a class="adm-bouton" href="<?php echo esc_url( ueb_url_filieres( array_filter( array( 'q' => $filtres['recherche'], 'etab' => strtolower( $filtres['etablissement'] ), 'etat' => $filtres['etat'] ) ) ) ); ?>">Annuler</a>
							<button class="adm-bouton adm-bouton--primaire" type="submit"><?php echo ueb_icone( 'check', 16 ); ?>Enregistrer</button>
						</div>
					</form>
				</section>
			<?php else : ?>
				<?php
				$erreurs_ajout = ( $erreurs && empty( $saisie['filiere_id'] ) ) ? $erreurs : array();
				$etab_ajout    = $erreurs_ajout ? (string) ( $saisie['etablissement'] ?? '' ) : strtoupper( sanitize_key( $_GET['etab_ajout'] ?? $filtres['etablissement'] ) );
				$ajout         = static fn( $champ, $defaut = '' ) => (string) ( $erreurs_ajout ? ( $saisie[ $champ ] ?? $defaut ) : $defaut );
				?>
				<section id="ajout" class="adm-panneau" aria-labelledby="filiere-ajout-titre" tabindex="-1">
					<header class="adm-panneau__tete">
						<div><h2 id="filiere-ajout-titre">Ajouter une filière</h2><p>Elle est aussitôt proposée dans le quitus de son établissement.</p></div>
					</header>
					<?php ueb_ipes_resume_erreurs( $erreurs_ajout, 'La filière n’a pas été ajoutée.' ); ?>
					<form class="formulaire" method="post" action="<?php echo esc_url( ueb_url_administration() ); ?>" data-formulaire novalidate>
						<?php ueb_champ_csrf(); ?>
						<input type="hidden" name="ueb_action" value="catalogue_filiere_enregistrer">
						<?php
						ueb_champ( array( 'nom' => 'etablissement', 'libelle' => 'Établissement', 'type' => 'select', 'icone' => 'ecole', 'options' => array_map( static fn( $e ) => $e['fr'], ueb_etablissements() ), 'valeur' => $etab_ajout, 'erreur' => $erreurs_ajout['etablissement'] ?? '' ) );
						ueb_champ( array( 'nom' => 'libelle', 'libelle' => 'Nom de la filière', 'valeur' => $ajout( 'libelle' ), 'erreur' => $erreurs_ajout['libelle'] ?? '', 'attrs' => array_filter( array( 'maxlength' => 150, 'autocomplete' => 'off', 'placeholder' => 'Génie logiciel', 'autofocus' => isset( $_GET['ajout'] ) && ! $erreurs_ajout ) ) ) );
						ueb_champ( array( 'nom' => 'code', 'libelle' => 'Code', 'valeur' => $ajout( 'code' ), 'erreur' => $erreurs_ajout['code'] ?? '', 'aide' => 'Court et unique dans l’établissement, par exemple GL ou GL_M.', 'attrs' => array( 'maxlength' => 30, 'autocomplete' => 'off', 'autocapitalize' => 'characters', 'spellcheck' => 'false', 'placeholder' => 'GL' ) ) );
						ueb_champ( array( 'nom' => 'type_formation', 'libelle' => 'Type de formation', 'type' => 'select', 'options' => UEB_TYPES_FORMATION, 'valeur' => $ajout( 'type_formation', 'classique' ), 'erreur' => $erreurs_ajout['type_formation'] ?? '', 'aide' => 'Classique : droits fixés par l’université. Professionnelle : montant communiqué par l’établissement.' ) );
						ueb_champ( array( 'nom' => 'cycle', 'libelle' => 'Cycle', 'type' => 'select', 'options' => UEB_CYCLES_FILIERE, 'valeur' => $ajout( 'cycle', 'tous' ), 'erreur' => $erreurs_ajout['cycle'] ?? '' ) );
						?>
						<button class="adm-bouton adm-bouton--primaire ipes-bouton-large" type="submit"><?php echo ueb_icone( 'plus', 16 ); ?>Ajouter la filière</button>
					</form>
				</section>
			<?php endif; ?>
		</aside>
	</div>

<?php endif; ?>
