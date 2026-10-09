<?php
/**
 * Onglet « IPES » de l'administration (page-administration.php, ?vue=ipes) :
 *   - sans « ipes » : le registre des IPES, filtrable en direct, et la file
 *     des bordereaux à vérifier ;
 *   - ?ipes=nouveau : la fiche de création ;
 *   - ?ipes={id}    : la fiche d'un IPES : reversements (héros), bordereaux
 *     et décision, compte administrateur, filières, informations.
 *
 * Les formulaires postent sur les actions de inc/ipes.php et
 * inc/ipes-filieres.php, qui revérifient tout côté serveur. Composants
 * partagés : inc/ipes-vues.php ; styles : assets/css/ipes.css.
 */
defined( 'ABSPATH' ) || exit;

$ipes_demande = sanitize_key( wp_unslash( $_GET['ipes'] ?? '' ) );

/* Bloc de la fiche où afficher les messages (voir ueb_ipes_retour_bloc()) ;
   lu une seule fois, quelle que soit la vue, pour ne jamais servir deux fois. */
$bloc_messages = in_array( $_SESSION['ueb_ipes_bloc'] ?? '', array( 'filieres', 'bordereaux', 'comptes' ), true ) ? $_SESSION['ueb_ipes_bloc'] : '';
unset( $_SESSION['ueb_ipes_bloc'] );
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
	$a_verifier   = ueb_ipes_bordereaux_a_verifier(); // ipes_id => nombre de bordereaux envoyés
	$tous         = $filtre_actif ? ueb_ipes_liste( array() ) : $liste_ipes;
	$inactifs     = count( array_filter( $tous, static fn( $i ) => ! (int) $i->actif ) );

	ueb_adm_tete( array(
		'titre'      => 'Établissements privés sous tutelle',
		'sous_titre' => 'Les IPES liés par convention à un ou plusieurs établissements de l’UEb : leur fiche, leurs filières, leur compte et leurs reversements.',
		'actions'    => ueb_adm_action( ueb_url_ipes( 'nouveau' ), 'Créer un IPES', 'plus', true ),
	) );
	ueb_afficher_flash();
	ueb_ipes_bandeau_a_verifier( ueb_ipes_bordereaux_envoyes(), static fn( $b ) => ueb_url_ipes( (int) $b->ipes_id ) . '#bordereaux' );
	?>

	<section class="adm-panneau ipes-registre" aria-labelledby="ipes-registre-titre">
		<header class="adm-panneau__tete">
			<div>
				<h2 id="ipes-registre-titre">Registre des IPES</h2>
				<p><?php echo esc_html( count( $tous ) ? ueb_ipes_pluriel( count( $tous ), 'IPES', 'IPES' ) . ( $inactifs ? ', dont ' . ueb_ipes_pluriel( $inactifs, 'désactivé' ) : ( count( $tous ) > 1 ? ', tous actifs' : ', actif' ) ) . '.' : 'Aucun IPES pour l’instant.' ); ?></p>
			</div>
			<?php if ( $tous ) : ?>
				<form class="ipes-outils" method="get" action="<?php echo esc_url( ueb_url_administration() ); ?>" role="search" aria-label="Filtrer les IPES" data-filtres-direct="ipes-resultats">
					<input type="hidden" name="vue" value="ipes">
					<label class="ipes-recherche"><span class="sr">Rechercher un IPES</span><?php echo ueb_icone( 'loupe', 17 ); ?><input type="search" name="q" value="<?php echo esc_attr( $filtres_ipes['recherche'] ); ?>" placeholder="Sigle ou nom" enterkeyhint="search" autocomplete="off"<?php echo ueb_attr_suggestions_liste( array_map( static fn( $i ) => array( $i->nom_fr, $i->sigle . ( $i->ville ? ', ' . $i->ville : '' ), $i->sigle ), $tous ) ); // phpcs:ignore -- échappé ?>></label>
					<label class="ipes-selecteur"><span class="sr">Tutelle</span>
						<select name="tutelle">
							<option value="">Toutes les tutelles</option>
							<?php foreach ( ueb_etablissements() as $sigle => $e ) : ?>
								<option value="<?php echo esc_attr( $sigle ); ?>" <?php selected( $filtres_ipes['etablissement'], $sigle ); ?>><?php echo esc_html( $sigle ); ?></option>
							<?php endforeach; ?>
						</select><?php echo ueb_icone( 'chevron', 16 ); ?>
					</label>
					<label class="ipes-selecteur"><span class="sr">État</span>
						<select name="etat">
							<option value="">Actifs et désactivés</option>
							<option value="actif" <?php selected( $filtres_ipes['etat'], 'actif' ); ?>>Actifs</option>
							<option value="inactif" <?php selected( $filtres_ipes['etat'], 'inactif' ); ?>>Désactivés</option>
						</select><?php echo ueb_icone( 'chevron', 16 ); ?>
					</label>
					<button class="adm-bouton" type="submit" data-filtres-bouton><?php echo ueb_icone( 'loupe', 16 ); ?>Rechercher</button>
				</form>
			<?php endif; ?>
		</header>

		<div id="ipes-resultats" class="ipes-resultats" aria-live="polite">
			<?php if ( $filtre_actif ) : ?>
				<p class="ipes-compte"><b><?php echo esc_html( ueb_ipes_pluriel( count( $liste_ipes ), 'IPES', 'IPES' ) ); ?></b> <?php echo count( $liste_ipes ) > 1 ? 'correspondent' : 'correspond'; ?> aux filtres.</p>
			<?php endif; ?>
			<?php if ( ! $liste_ipes ) : ?>
				<div class="bo-vide ipes-vide">
					<span><?php echo ueb_icone( $filtre_actif ? 'loupe' : 'ecole', 22 ); ?></span>
					<p><?php echo $filtre_actif ? '<b>Aucun IPES ne correspond à ces filtres.</b> Élargis la recherche ou choisis une autre tutelle.' : '<b>Aucun IPES enregistré.</b> Crée la fiche du premier institut placé sous la tutelle de l’UEb.'; ?></p>
					<?php if ( ! $filtre_actif ) : ?><a class="adm-bouton adm-bouton--primaire" href="<?php echo esc_url( ueb_url_ipes( 'nouveau' ) ); ?>"><?php echo ueb_icone( 'plus', 16 ); ?>Créer un IPES</a><?php endif; ?>
				</div>
			<?php else : ?>
				<?php
				ueb_ipes_registre( $liste_ipes, array(
					'url'        => static fn( $ipes ) => ueb_url_ipes( (int) $ipes->id ),
					'a_verifier' => static fn( $ipes ) => $a_verifier[ (int) $ipes->id ] ?? 0,
				) );
				?>
			<?php endif; ?>
		</div>
	</section>

<?php else : ?>

	<?php
	$nouveau = 'nouveau' === $ipes_demande;
	$ipes    = $nouveau ? null : ueb_ipes( (int) $ipes_demande );
	list( $saisie, $erreurs ) = ueb_reprendre_saisie();
	?>

	<?php if ( ! $nouveau && ! $ipes ) : ?>

		<?php
		ueb_adm_tete( array(
			'fil'   => array( array( ueb_url_ipes(), 'IPES' ), array( '', 'Introuvable' ) ),
			'titre' => 'IPES introuvable',
		) );
		ueb_afficher_flash();
		?>
		<div class="bo-vide bo-vide--large"><span><?php echo ueb_icone( 'ecole', 24 ); ?></span><p><b>Cet IPES n’existe pas.</b> Il a peut-être été retiré : retrouve-le dans le registre.</p><a class="adm-bouton" href="<?php echo esc_url( ueb_url_ipes() ); ?>"><?php echo ueb_icone( 'fleche-g', 16 ); ?>Tous les IPES</a></div>

	<?php else : ?>

		<?php
		/* Après un échec, la saisie prime ; sinon les valeurs enregistrées. */
		$valeur = static fn( $champ ) => (string) ( $saisie[ $champ ] ?? ( $ipes->$champ ?? '' ) );
		$erreur = static fn( $champ ) => (string) ( $erreurs[ $champ ] ?? '' );
		/* L'erreur d'ajout d'une filière s'affiche sous son champ, pas dans le résumé de la fiche. */
		$erreur_filiere = $erreur( 'libelle' );
		$erreurs        = array_diff_key( $erreurs, array( 'libelle' => 1 ) );
		$tutelles       = array_key_exists( 'tutelles', $saisie ) ? (array) $saisie['tutelles'] : ( $ipes->tutelles ?? array() );
		$logo           = $ipes ? ueb_ipes_logo_url( $ipes ) : null;

		/* Barre du haut : logo, sigle, nom ; état, modification, activation. */
		$actions = '';
		if ( $ipes ) {
			ob_start();
			?>
			<a class="adm-bouton" href="#fiche"><?php echo ueb_icone( 'crayon', 16 ); ?>Modifier la fiche</a>
			<form method="post" action="<?php echo esc_url( ueb_url_administration() ); ?>" data-confirmer="<?php echo (int) $ipes->actif ? 'Désactiver cet IPES ? Ses administrateurs perdront leur accès. Rien n’est supprimé.' : 'Réactiver cet IPES ? Ses administrateurs retrouveront leur accès.'; ?>">
				<?php ueb_champ_csrf(); ?>
				<input type="hidden" name="ueb_action" value="ipes_etat">
				<input type="hidden" name="ipes_id" value="<?php echo (int) $ipes->id; ?>">
				<button class="adm-bouton<?php echo (int) $ipes->actif ? ' adm-bouton--danger' : ' adm-bouton--primaire'; ?>" type="submit"><?php echo ueb_icone( (int) $ipes->actif ? 'pause' : 'lecture', 16 ); ?><?php echo (int) $ipes->actif ? 'Désactiver' : 'Réactiver'; ?></button>
			</form>
			<?php
			$actions = ob_get_clean();
		}
		$reperes = '';
		if ( $ipes ) {
			$reperes  = '<ul class="ipes-reperes">';
			$reperes .= '<li>' . ( (int) $ipes->actif ? ueb_icone( 'check', 14 ) . 'Actif' : ueb_icone( 'pause', 14 ) . 'Désactivé' ) . '</li>';
			$reperes .= '<li>' . ueb_icone( 'ecole', 14 ) . esc_html( 'Tutelle : ' . implode( ', ', $ipes->tutelles ) ) . '</li>';
			if ( $ipes->ville ) {
				$reperes .= '<li>' . ueb_icone( 'lieu', 14 ) . esc_html( $ipes->ville ) . '</li>';
			}
			if ( $ipes->convention_fin_le ) {
				$reperes .= '<li>' . ueb_icone( 'calendrier', 14 ) . esc_html( 'Convention jusqu’au ' . mysql2date( 'd/m/Y', $ipes->convention_fin_le ) ) . '</li>';
			}
			$reperes .= '</ul>';
		}
		ueb_adm_tete( array(
			'fil'        => array( array( ueb_url_ipes(), 'IPES' ), array( '', $ipes ? $ipes->sigle : 'Nouvel IPES' ) ),
			'titre'      => $ipes ? $ipes->sigle : 'Nouvel IPES',
			'sous_titre' => $ipes ? $ipes->nom_fr : 'Renseigne l’institut, sa convention et le ou les établissements de l’UEb qui en assurent la tutelle.',
			'visuel'     => $ipes ? ueb_ipes_logo_html( $ipes, 'ipes-logo--grand' ) : '',
			'apres'      => $reperes,
			'actions'    => $actions,
		) );
		if ( ! $bloc_messages || ! $ipes ) {
			ueb_afficher_flash();
		}
		?>

		<?php if ( $ipes ) : ?>

			<?php ueb_ipes_hero( $ipes, array( 'pour' => 'ueb' ) ); ?>

			<?php
				$bordereaux = ueb_ipes_bordereaux_pour_ueb( $ipes->id );
				$en_attente = count( array_filter( $bordereaux, static fn( $b ) => 'envoye' === $b->statut ) );
				?>
				<section id="bordereaux" class="adm-panneau ipes-registre" aria-labelledby="ipes-bordereaux-titre" tabindex="-1">
					<header class="adm-panneau__tete">
						<div>
							<h2 id="ipes-bordereaux-titre">Bordereaux de reversement</h2>
							<p>Vérifie chaque bordereau avec son PDF et les pièces reçues, ou rejette-le avec un motif : l’IPES le corrigera et le renverra.</p>
						</div>
						<?php if ( $en_attente ) : ?><span class="adm-a-traiter adm-a-traiter--recu"><?php echo ueb_icone( 'horloge', 14 ); ?><?php echo esc_html( $en_attente . ' à vérifier' ); ?></span><?php endif; ?>
					</header>
					<?php if ( 'bordereaux' === $bloc_messages ) : ?><div class="ipes-vide"><?php ueb_afficher_flash(); ?></div><?php endif; ?>
					<?php if ( ! $bordereaux ) : ?>
						<div class="bo-vide ipes-vide"><span><?php echo ueb_icone( 'recu', 22 ); ?></span><p><b>Aucun bordereau reçu.</b> Les bordereaux apparaîtront ici dès que l’IPES les enverra ; ses brouillons restent chez lui.</p></div>
					<?php else : ?>
						<?php
						ueb_ipes_bordereaux_liste( $bordereaux, array(
							'annee'   => true,
							'actions' => static function ( $b ) use ( $ipes ) {
								ueb_ipes_decision( $b, array(
									'action'       => 'ipes_bordereau_decider',
									'url'          => ueb_url_administration(),
									'pdf'          => add_query_arg( array( 'bordereau' => (int) $b->id, 'pdf' => 1 ), ueb_url_ipes( (int) $ipes->id ) ),
									'peut_decider' => true,
									'champs'       => array( 'ipes_id' => (int) $ipes->id ),
								) );
							},
						) );
						?>
					<?php endif; ?>
				</section>

				<div class="ipes-duo">
					<?php
					$comptes = ueb_ipes_comptes( $ipes->id );
					/* Mot de passe provisoire : affiché une seule fois, juste après sa création. */
					$prov_ipes = $_SESSION['ueb_mdp_ipes'] ?? null;
					unset( $_SESSION['ueb_mdp_ipes'] );
					?>
					<section id="comptes" class="adm-panneau" aria-labelledby="ipes-comptes-titre" tabindex="-1">
						<header class="adm-panneau__tete">
							<div>
								<h2 id="ipes-comptes-titre">Administrateur de l’IPES</h2>
								<p>Le compte qui déclare les étudiants et les reversements. Il n’a accès à rien d’autre.</p>
							</div>
						</header>
						<div class="ipes-panneau-corps">
							<?php if ( 'comptes' === $bloc_messages ) { ueb_afficher_flash(); } ?>
							<?php if ( $prov_ipes ) : ?>
								<div class="provisoire carte ipes-provisoire" role="status">
									<?php echo ueb_icone( 'cle', 24 ); ?>
									<div>
										<p>Mot de passe provisoire pour <b><?php echo esc_html( $prov_ipes['compte'] ); ?></b>, à communiquer à l’IPES. Il ne sera plus affiché :</p>
										<p class="provisoire__mdp"><?php echo esc_html( $prov_ipes['mdp'] ); ?></p>
										<button type="button" class="btn btn--fantome btn--petit provisoire__copier" data-copier-mot-de-passe="<?php echo esc_attr( $prov_ipes['mdp'] ); ?>"><?php echo ueb_icone( 'fichier', 16 ); ?><span>Copier le mot de passe</span></button>
									</div>
								</div>
							<?php endif; ?>

							<?php if ( $comptes ) : ?>
								<div>
									<?php foreach ( $comptes as $compte ) :
										$suspendu  = ueb_agent_suspendu( $compte->ID );
										$avec_ipes = $suspendu && get_user_meta( $compte->ID, 'ueb_suspendu_avec_ipes', true );
										?>
										<div class="ipes-compte-carte<?php echo $suspendu ? ' est-suspendu' : ''; ?>">
											<span class="bo-avatar" aria-hidden="true"><?php echo esc_html( ueb_initiales( $compte->display_name ?: $compte->user_login, '' ) ); ?></span>
											<div class="ipes-compte-carte__identite">
												<b><?php echo esc_html( $compte->display_name ); ?></b>
												<small><?php echo esc_html( $compte->user_login . ( $compte->user_email ? ', ' . $compte->user_email : '' ) ); ?></small>
												<small>Créé le <?php echo esc_html( mysql2date( 'd/m/Y', $compte->user_registered ) ); ?></small>
											</div>
											<span class="adm-etat<?php echo $suspendu ? ' adm-etat--suspendu' : ''; ?>"><?php echo ueb_icone( $suspendu ? 'pause' : 'check', 14 ); ?><?php echo $suspendu ? ( $avec_ipes ? 'Suspendu avec l’IPES' : 'Suspendu' ) : 'Actif'; ?></span>
											<div class="ipes-compte-carte__outils">
												<form method="post" action="<?php echo esc_url( ueb_url_administration() ); ?>" data-confirmer="<?php echo esc_attr( 'Créer un nouveau mot de passe provisoire pour ' . $compte->user_login . ' ? L’ancien ne fonctionnera plus et ses sessions seront fermées.' ); ?>">
													<?php ueb_champ_csrf(); ?>
													<input type="hidden" name="ueb_action" value="ipes_compte_mdp">
													<input type="hidden" name="ipes_id" value="<?php echo (int) $ipes->id; ?>">
													<input type="hidden" name="compte_id" value="<?php echo (int) $compte->ID; ?>">
													<button class="adm-bouton adm-bouton--petit" type="submit"><?php echo ueb_icone( 'cle', 15 ); ?>Nouveau mot de passe</button>
												</form>
												<?php if ( ! $suspendu || (int) $ipes->actif ) : ?>
													<form method="post" action="<?php echo esc_url( ueb_url_administration() ); ?>" data-confirmer="<?php echo $suspendu ? 'Rétablir l’accès de ce compte ?' : 'Suspendre ce compte ? Il ne pourra plus se connecter ; il est conservé.'; ?>">
														<?php ueb_champ_csrf(); ?>
														<input type="hidden" name="ueb_action" value="ipes_compte_etat">
														<input type="hidden" name="ipes_id" value="<?php echo (int) $ipes->id; ?>">
														<input type="hidden" name="compte_id" value="<?php echo (int) $compte->ID; ?>">
														<button class="adm-bouton adm-bouton--petit" type="submit"><?php echo ueb_icone( $suspendu ? 'lecture' : 'pause', 15 ); ?><?php echo $suspendu ? 'Rétablir' : 'Suspendre'; ?></button>
													</form>
												<?php endif; ?>
											</div>
										</div>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>

							<?php if ( ! (int) $ipes->actif ) : ?>
								<div class="bo-vide"><span><?php echo ueb_icone( 'cadenas', 22 ); ?></span><p><b>Cet IPES est désactivé.</b> Réactive-le pour lui créer un compte ou rétablir ses accès.</p></div>
							<?php else : ?>
								<?php
								$formulaire_compte = static function () use ( $ipes ) {
									?>
									<form class="formulaire" method="post" action="<?php echo esc_url( ueb_url_administration() ); ?>" data-formulaire novalidate>
										<?php ueb_champ_csrf(); ?>
										<input type="hidden" name="ueb_action" value="ipes_compte_creer">
										<input type="hidden" name="ipes_id" value="<?php echo (int) $ipes->id; ?>">
										<?php
										ueb_champ( array( 'nom' => 'login', 'id' => 'compte-login', 'libelle' => 'Identifiant de connexion', 'icone' => 'utilisateur', 'attrs' => array( 'placeholder' => 'admin.' . strtolower( $ipes->sigle ), 'autocapitalize' => 'none', 'spellcheck' => 'false', 'autocomplete' => 'off' ) ) );
										ueb_champ( array( 'nom' => 'nom', 'id' => 'compte-nom', 'libelle' => 'Nom du responsable', 'icone' => 'utilisateur', 'requis' => false, 'attrs' => array( 'placeholder' => 'Nom et prénom', 'autocomplete' => 'off' ) ) );
										ueb_champ( array( 'nom' => 'email', 'id' => 'compte-email', 'libelle' => 'Adresse e-mail', 'type' => 'email', 'icone' => 'courriel', 'requis' => false, 'aide' => 'Utile pour récupérer un mot de passe oublié.', 'attrs' => array( 'autocomplete' => 'off' ) ) );
										?>
										<p class="champ__aide">Le mot de passe provisoire est créé automatiquement et affiché une seule fois.</p>
										<button class="adm-bouton adm-bouton--primaire" type="submit"><?php echo ueb_icone( 'plus', 16 ); ?>Créer le compte</button>
									</form>
									<?php
								};
								?>
								<div class="ipes-creer-compte">
									<?php if ( $comptes ) : ?>
										<details>
											<summary><?php echo ueb_icone( 'plus', 16 ); ?>Ajouter un autre compte</summary>
											<?php $formulaire_compte(); ?>
										</details>
									<?php else : ?>
										<?php $formulaire_compte(); ?>
									<?php endif; ?>
								</div>
							<?php endif; ?>
						</div>
					</section>

					<?php
					$filieres = ueb_ipes_filieres( $ipes->id );
					$retirees = count( array_filter( $filieres, static fn( $f ) => ! (int) $f->actif ) );
					/* Une filière par tutelle : les étudiants d'une filière sont reversés à sa tutelle. */
					$groupes  = ueb_ipes_filieres_par_tutelle( $ipes );
					/* Tutelle de la dernière saisie refusée : c'est sous elle que l'erreur s'affiche. */
					$tutelle_erreur = $erreur_filiere ? strtoupper( (string) ( $saisie['etablissement'] ?? '' ) ) : '';
					if ( $erreur_filiere && ! isset( $groupes[ $tutelle_erreur ] ) ) {
						$tutelle_erreur = (string) array_key_first( $groupes );
					}
					/* Une ligne de filière : renommer, retirer ou rétablir. */
					$ligne_filiere = static function ( $filiere ) use ( $ipes ) {
						$active = (int) $filiere->actif;
						$fid    = (int) $filiere->id;
						?>
						<li class="<?php echo $active ? '' : 'est-retiree'; ?>">
							<span class="ipes-liste__nom"><?php echo ueb_icone( 'fichier', 16 ); ?><span><?php echo esc_html( $filiere->libelle ); ?><?php echo $active ? '' : '<span class="sr"> (retirée)</span>'; ?></span></span>
							<span class="ipes-liste__actions">
								<button class="adm-bouton adm-bouton--petit adm-bouton--icone" type="button" data-ouvrir-agent-mdp="filiere-<?php echo $fid; ?>" aria-label="<?php echo esc_attr( 'Renommer ' . $filiere->libelle ); ?>" title="Renommer"><?php echo ueb_icone( 'crayon', 15 ); ?></button>
								<dialog class="bo-agent-mdp ipes-dialogue" id="filiere-<?php echo $fid; ?>" aria-labelledby="filiere-titre-<?php echo $fid; ?>">
									<h2 id="filiere-titre-<?php echo $fid; ?>">Renommer la filière</h2>
									<p>Le nouveau nom remplace « <?php echo esc_html( $filiere->libelle ); ?> » partout.</p>
									<form method="post" action="<?php echo esc_url( ueb_url_administration() ); ?>">
										<?php ueb_champ_csrf(); ?>
										<input type="hidden" name="ueb_action" value="ipes_filiere_renommer">
										<input type="hidden" name="ipes_id" value="<?php echo (int) $ipes->id; ?>">
										<input type="hidden" name="filiere_id" value="<?php echo $fid; ?>">
										<label><span>Nouveau nom</span><input type="text" name="libelle" value="<?php echo esc_attr( $filiere->libelle ); ?>" minlength="<?php echo (int) UEB_IPES_FILIERE_MIN; ?>" maxlength="<?php echo (int) UEB_IPES_FILIERE_MAX; ?>" autocomplete="off" required></label>
										<div class="bo-agent-mdp__actions"><button class="btn btn--lien btn--petit" type="button" data-fermer-agent-mdp>Annuler</button><button class="btn btn--primaire btn--petit" type="submit">Enregistrer</button></div>
									</form>
								</dialog>
								<form method="post" action="<?php echo esc_url( ueb_url_administration() ); ?>"<?php echo $active ? ' data-confirmer="' . esc_attr( 'Retirer la filière « ' . $filiere->libelle . ' » ? Elle reste dans l’historique et peut être rétablie.' ) . '"' : ''; ?>>
									<?php ueb_champ_csrf(); ?>
									<input type="hidden" name="ueb_action" value="ipes_filiere_etat">
									<input type="hidden" name="ipes_id" value="<?php echo (int) $ipes->id; ?>">
									<input type="hidden" name="filiere_id" value="<?php echo $fid; ?>">
									<button class="adm-bouton adm-bouton--petit" type="submit"><?php echo $active ? 'Retirer' : 'Rétablir'; ?></button>
								</form>
							</span>
						</li>
						<?php
					};
					?>
					<section id="filieres" class="adm-panneau" aria-labelledby="ipes-filieres-titre" tabindex="-1">
						<header class="adm-panneau__tete">
							<div>
								<h2 id="ipes-filieres-titre">Filières</h2>
								<p><?php echo esc_html( ( $filieres ? ueb_ipes_pluriel( count( $filieres ), 'filière' ) . ( $retirees ? ', dont ' . ueb_ipes_pluriel( $retirees, 'retirée' ) : '' ) . '. ' : '' ) . 'Saisies tutelle par tutelle : les étudiants d’une filière sont reversés à sa tutelle.' ); ?></p>
							</div>
						</header>
						<div class="ipes-panneau-corps ipes-filieres">
							<?php if ( 'filieres' === $bloc_messages ) { ueb_afficher_flash(); } ?>
							<?php foreach ( $groupes as $sigle => $liste ) :
								$etab     = ueb_etablissement( $sigle );
								$tutelle  = in_array( $sigle, $ipes->tutelles, true );
								$actives  = count( array_filter( $liste, static fn( $f ) => (int) $f->actif ) );
								$ici      = $tutelle_erreur === $sigle;
								$id_groupe = 'filieres-' . strtolower( $sigle );
								?>
								<section class="ipes-filieres__groupe" style="--etab: <?php echo esc_attr( $etab['couleur'] ?? 'var(--vert)' ); ?>" aria-labelledby="<?php echo esc_attr( $id_groupe ); ?>">
									<header class="ipes-filieres__tete">
										<?php echo ueb_ipes_pastilles_html( array( $sigle ) ); // phpcs:ignore -- échappé ?>
										<h3 id="<?php echo esc_attr( $id_groupe ); ?>"><?php echo esc_html( $etab['fr'] ?? $sigle ); ?></h3>
										<small><?php echo esc_html( $tutelle ? ueb_ipes_pluriel( $actives, 'filière active', 'filières actives' ) : 'N’est plus tutelle : historique seulement' ); ?></small>
									</header>
									<?php if ( $tutelle ) : ?>
										<form class="formulaire ipes-ajout<?php echo $ici ? ' a-erreur' : ''; ?>" method="post" action="<?php echo esc_url( ueb_url_administration() ); ?>" data-formulaire novalidate>
											<?php ueb_champ_csrf(); ?>
											<input type="hidden" name="ueb_action" value="ipes_filiere_ajouter">
											<input type="hidden" name="ipes_id" value="<?php echo (int) $ipes->id; ?>">
											<input type="hidden" name="etablissement" value="<?php echo esc_attr( $sigle ); ?>">
											<?php ueb_champ( array( 'nom' => 'libelle', 'id' => 'libelle-' . strtolower( $sigle ), 'libelle' => 'Nouvelle filière sous la ' . $sigle, 'valeur' => $ici ? (string) ( $saisie['libelle'] ?? '' ) : '', 'erreur' => $ici ? $erreur_filiere : '', 'attrs' => array( 'maxlength' => UEB_IPES_FILIERE_MAX, 'autocomplete' => 'off', 'placeholder' => 'FSJP' === $sigle ? 'Droit des affaires' : ( 'FS' === $sigle ? 'Physique' : 'Génie logiciel' ) ) ) ); ?>
											<button class="adm-bouton adm-bouton--primaire" type="submit"><?php echo ueb_icone( 'plus', 16 ); ?>Ajouter</button>
										</form>
									<?php endif; ?>
									<?php if ( $liste ) : ?>
										<ul class="ipes-liste">
											<?php array_map( $ligne_filiere, $liste ); ?>
										</ul>
									<?php else : ?>
										<p class="ipes-filieres__vide"><?php echo esc_html( 'Aucune filière sous la ' . $sigle . ' : ajoute celles que l’IPES y rattache.' ); ?></p>
									<?php endif; ?>
								</section>
							<?php endforeach; ?>
						</div>
					</section>
				</div>

		<?php endif; ?>

		<form id="fiche" class="adm-panneau ipes-fiche-form" method="post" action="<?php echo esc_url( ueb_url_administration() ); ?>" enctype="multipart/form-data" data-formulaire novalidate tabindex="-1">
			<?php ueb_champ_csrf(); ?>
			<input type="hidden" name="ueb_action" value="ipes_enregistrer">
			<input type="hidden" name="ipes_id" value="<?php echo $ipes ? (int) $ipes->id : ''; ?>">

			<header class="adm-panneau__tete">
				<div>
					<h2><?php echo $ipes ? 'Informations de l’IPES' : 'Fiche du nouvel IPES'; ?></h2>
					<p><?php echo $ipes ? 'Identité, contacts, convention, tutelles et logo. Les modifications s’appliquent dès l’enregistrement.' : 'Seuls le sigle, le nom et au moins une tutelle sont obligatoires ; le reste peut attendre.'; ?></p>
				</div>
			</header>

			<?php if ( $erreurs ) : ?>
				<div class="ipes-vide">
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
				</div>
			<?php endif; ?>

			<div class="ipes-groupes formulaire">
				<fieldset class="ipes-groupe">
					<legend class="sr">Identité</legend>
					<div class="ipes-groupe__tete"><span class="ipes-groupe__icone" aria-hidden="true"><?php echo ueb_icone( 'ecole', 17 ); ?></span><div><h3>Identité</h3><p>Le sigle identifie l’IPES partout. Il ne peut pas reprendre celui d’un établissement de l’UEb.</p></div></div>
					<div class="formulaire__rangee">
						<?php
						ueb_champ( array( 'nom' => 'sigle', 'libelle' => 'Sigle', 'valeur' => $valeur( 'sigle' ), 'erreur' => $erreur( 'sigle' ), 'aide' => '2 à 20 caractères : lettres, chiffres ou tiret.', 'attrs' => array( 'maxlength' => 20, 'autocomplete' => 'off', 'autocapitalize' => 'characters', 'spellcheck' => 'false', 'placeholder' => 'ISTAE' ) ) );
						ueb_champ( array( 'nom' => 'ville', 'libelle' => 'Ville', 'icone' => 'lieu', 'requis' => false, 'valeur' => $valeur( 'ville' ), 'erreur' => $erreur( 'ville' ), 'attrs' => array( 'maxlength' => 100, 'autocomplete' => 'off' ) ) );
						?>
					</div>
					<?php
					ueb_champ( array( 'nom' => 'nom_fr', 'libelle' => 'Nom complet', 'valeur' => $valeur( 'nom_fr' ), 'erreur' => $erreur( 'nom_fr' ), 'attrs' => array( 'maxlength' => 150, 'autocomplete' => 'off', 'placeholder' => 'Institut supérieur de…' ) ) );
					ueb_champ( array( 'nom' => 'nom_en', 'libelle' => 'Nom en anglais', 'requis' => false, 'valeur' => $valeur( 'nom_en' ), 'erreur' => $erreur( 'nom_en' ), 'attrs' => array( 'maxlength' => 150, 'autocomplete' => 'off', 'lang' => 'en' ) ) );
					?>
				</fieldset>

				<fieldset class="ipes-groupe">
					<legend class="sr">Contacts</legend>
					<div class="ipes-groupe__tete"><span class="ipes-groupe__icone" aria-hidden="true"><?php echo ueb_icone( 'telephone', 17 ); ?></span><div><h3>Contacts</h3><p>Pour joindre l’IPES au sujet de sa convention et de ses reversements.</p></div></div>
					<?php
					ueb_champ( array( 'nom' => 'telephone', 'libelle' => 'Téléphone', 'type' => 'tel', 'icone' => 'telephone', 'requis' => false, 'valeur' => $valeur( 'telephone' ) ? ueb_formater_telephone( $valeur( 'telephone' ) ) : '', 'erreur' => $erreur( 'telephone' ), 'aide' => 'Mobile à 9 chiffres, avec ou sans +237.', 'attrs' => array( 'inputmode' => 'tel', 'autocomplete' => 'off' ) ) );
					ueb_champ( array( 'nom' => 'email', 'libelle' => 'Adresse e-mail', 'type' => 'email', 'icone' => 'courriel', 'requis' => false, 'valeur' => $valeur( 'email' ), 'erreur' => $erreur( 'email' ), 'attrs' => array( 'maxlength' => 150, 'autocomplete' => 'off' ) ) );
					?>
				</fieldset>

				<fieldset class="ipes-groupe">
					<legend class="sr">Convention</legend>
					<div class="ipes-groupe__tete"><span class="ipes-groupe__icone" aria-hidden="true"><?php echo ueb_icone( 'fichier', 17 ); ?></span><div><h3>Convention</h3><p>La convention qui place l’IPES sous la tutelle de l’UEb.</p></div></div>
					<?php ueb_champ( array( 'nom' => 'convention_ref', 'libelle' => 'Référence', 'requis' => false, 'valeur' => $valeur( 'convention_ref' ), 'erreur' => $erreur( 'convention_ref' ), 'attrs' => array( 'maxlength' => 100, 'autocomplete' => 'off', 'placeholder' => 'CONV-FS-2026-01' ) ) ); ?>
					<div class="formulaire__rangee">
						<?php
						ueb_champ( array( 'nom' => 'convention_signee_le', 'libelle' => 'Signée le', 'type' => 'date', 'requis' => false, 'valeur' => $valeur( 'convention_signee_le' ), 'erreur' => $erreur( 'convention_signee_le' ) ) );
						ueb_champ( array( 'nom' => 'convention_fin_le', 'libelle' => 'Fin', 'type' => 'date', 'requis' => false, 'valeur' => $valeur( 'convention_fin_le' ), 'erreur' => $erreur( 'convention_fin_le' ), 'aide' => 'Vide si la convention n’a pas de terme.' ) );
						?>
					</div>
				</fieldset>

				<div class="ipes-groupe">
					<div class="ipes-groupe__tete"><span class="ipes-groupe__icone" aria-hidden="true"><?php echo ueb_icone( 'bouclier', 17 ); ?></span><div><h3>Tutelle</h3><p>Un ou plusieurs établissements de l’UEb. Leurs scolarités verront cet IPES et ses reversements.</p></div></div>
					<fieldset id="champ-tutelles" tabindex="-1" class="ipes-etabs champ<?php echo $erreur( 'tutelles' ) ? ' champ--invalide' : ''; ?>"<?php echo $erreur( 'tutelles' ) ? ' aria-describedby="champ-tutelles-erreur"' : ''; ?>>
						<legend>Établissements de tutelle</legend>
						<div class="ipes-etabs__grille">
							<?php foreach ( ueb_etablissements() as $sigle => $e ) : ?>
								<label class="ipes-etab" title="<?php echo esc_attr( $e['fr'] ); ?>">
									<input type="checkbox" name="tutelles[]" value="<?php echo esc_attr( $sigle ); ?>" <?php checked( in_array( $sigle, $tutelles, true ) ); ?>>
									<span><img src="<?php echo esc_url( ueb_logo_url( $sigle ) ); ?>" alt="" width="26" height="26"><?php echo esc_html( $sigle ); ?><?php echo ueb_icone( 'check', 15 ); ?></span>
								</label>
							<?php endforeach; ?>
						</div>
						<?php if ( $erreur( 'tutelles' ) ) : ?>
							<p class="champ__erreur" id="champ-tutelles-erreur"><?php echo ueb_icone( 'alerte', 16 ); ?><?php echo esc_html( $erreur( 'tutelles' ) ); ?></p>
						<?php endif; ?>
					</fieldset>
				</div>

				<div class="ipes-groupe ipes-groupe--large">
					<div class="ipes-groupe__tete"><span class="ipes-groupe__icone" aria-hidden="true"><?php echo ueb_icone( 'image', 17 ); ?></span><div><h3>Logo <span class="facultatif">(facultatif)</span></h3><p>PNG ou JPEG, 1 Mo au plus. Il est redimensionné et sa transparence est conservée.</p></div></div>
					<div id="champ-logo" tabindex="-1" class="ipes-logo-choix champ<?php echo $erreur( 'logo' ) ? ' champ--invalide' : ''; ?>" data-logo-ipes>
						<span class="ipes-logo ipes-logo--apercu" aria-hidden="true"><img <?php echo $logo ? 'src="' . esc_url( $logo ) . '"' : 'hidden'; ?> alt="" width="64" height="64" data-logo-apercu><span data-logo-vide <?php echo $logo ? 'hidden' : ''; ?>><?php echo ueb_icone( 'ecole', 26 ); ?></span></span>
						<label class="depot">
							<input type="file" name="logo" accept="image/png,image/jpeg" aria-describedby="logo-aide<?php echo $erreur( 'logo' ) ? ' champ-logo-erreur' : ''; ?>">
							<span class="depot__titre" data-logo-titre><?php echo $logo ? 'Remplacer le logo' : 'Choisir le logo'; ?></span>
							<span class="depot__aide" id="logo-aide"><?php echo $logo ? 'Laisse vide pour garder le logo actuel.' : 'Clique ou glisse l’image ici.'; ?></span>
						</label>
						<?php if ( $erreur( 'logo' ) ) : ?>
							<p class="champ__erreur" id="champ-logo-erreur"><?php echo ueb_icone( 'alerte', 16 ); ?><?php echo esc_html( $erreur( 'logo' ) ); ?></p>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<footer class="ipes-fiche-form__pied">
				<a class="adm-bouton" href="<?php echo esc_url( $ipes ? ueb_url_ipes( (int) $ipes->id ) : ueb_url_ipes() ); ?>">Annuler</a>
				<button class="adm-bouton adm-bouton--primaire" type="submit"><?php echo ueb_icone( 'check', 16 ); ?><?php echo $ipes ? 'Enregistrer les modifications' : 'Créer l’IPES'; ?></button>
			</footer>
		</form>

	<?php endif; ?>

<?php endif; ?>
