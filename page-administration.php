<?php
/**
 * Template Name: Administration UEb
 *
 * Espace de l'administration, avec barre latérale :
 *   - Tableau de bord : cinq indicateurs, anneau du recouvrement, évolution
 *     cumulée sur 7, 30 ou 90 jours, statuts et comparaison des établissements ;
 *   - Vue d'un établissement (?etab=FS) : mêmes indicateurs filtrés, avec
 *     ses niveaux et ses filières ;
 *   - Paiements (?vue=paiements[&etab=FS]) : suivi complet du recouvrement ;
 *   - Personnel (?vue=scolarites) : créer, rattacher, réinitialiser,
 *     suspendre, supprimer ;
 *   - IPES (?vue=ipes) : établissements privés sous tutelle
 *     (templates/composants/ipes-admin.php).
 *
 * Thème clair par défaut, sombre au choix (bascule mémorisée, voir
 * inc/administration.php et assets/js/administration.js).
 *
 * Interface unique du back-office (inc/espaces.php) : tout compte du
 * personnel s'y connecte — super-administrateur, scolarité, Régisseur CMS,
 * Direction, comptes étudiants, administrateur d'un IPES — et n'y voit que
 * les onglets de ses permissions. Les écrans autres que le pilotage
 * (espace « admin », ci-dessous) sont dans templates/espaces/.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

/* ---------- Connexion, avant tout affichage ---------- */
$erreur_connexion = '';
if ( isset( $_POST['ueb_connexion_admin'] ) ) {
	if ( ! isset( $_POST['ueb_connexion_nonce'] ) || ! wp_verify_nonce( $_POST['ueb_connexion_nonce'], 'ueb_connexion_admin' ) ) {
		$erreur_connexion = 'Ta session a expiré. Recommence.';
	} else {
		$identifiant = sanitize_text_field( wp_unslash( $_POST['identifiant'] ?? '' ) );
		/* L'adresse e-mail du compte est acceptée à la place de l'identifiant. */
		if ( is_email( $identifiant ) && ( $par_email = get_user_by( 'email', $identifiant ) ) ) {
			$identifiant = $par_email->user_login;
		}
		$utilisateur = ueb_connexion_gestion_bloquee( $identifiant ) ? new WP_Error( 'ueb_rate_limited' ) : wp_signon( array(
			'user_login'    => $identifiant,
			'user_password' => (string) wp_unslash( $_POST['mot_de_passe'] ?? '' ),
			'remember'      => false,
		), is_ssl() );
		if ( is_wp_error( $utilisateur ) ) {
			ueb_noter_echec_gestion( $identifiant );
			$erreur_connexion = ueb_message_echec_connexion( $utilisateur, 'Identifiant ou mot de passe incorrect.' );
		} elseif ( ! ueb_espaces_du_compte( $utilisateur->ID ) ) {
			wp_logout();
			$erreur_connexion = "Ce compte n'a accès à aucun espace de gestion.";
		} else {
			ueb_reinitialiser_echecs_gestion( $identifiant );
			wp_set_current_user( $utilisateur->ID );
			wp_set_auth_cookie( $utilisateur->ID, false, is_ssl() );
			wp_safe_redirect( get_permalink() );
			exit;
		}
	}
}

/* Compte connecté avec un espace autre que le pilotage : son écran. */
$espace = ueb_espace_courant();
if ( $espace && 'admin' !== $espace ) {
	require UEB_INSC_DIR . '/templates/espaces/' . $espace . '.php';
	return;
}

$autorise = 'admin' === $espace;
$annee    = ueb_annee_academique();

if ( $autorise ) {
	$vue   = sanitize_key( $_GET['vue'] ?? 'bord' );
	$vue   = in_array( $vue, array( 'bord', 'paiements', 'etudiants', 'scolarites', 'ipes', 'filieres' ), true ) ? $vue : 'bord';
	$focus = strtoupper( sanitize_text_field( wp_unslash( $_GET['etab'] ?? '' ) ) );
	$focus = ueb_etablissement( $focus ) ? $focus : '';
	$ici   = static fn( array $args = array() ) => esc_url( add_query_arg( $args, ueb_url_administration() ) );
	$url   = static fn( array $args = array() ) => add_query_arg( $args, ueb_url_administration() );
	$prov  = $_SESSION['ueb_mdp_agent'] ?? null;
	unset( $_SESSION['ueb_mdp_agent'] );

	if ( 'bord' === $vue ) {
		$periode  = (int) ( $_GET['periode'] ?? 30 );
		$periode  = in_array( $periode, array( 7, 30, 90 ), true ) ? $periode : 30;
		$chiffres = ueb_gestion_chiffres( $annee['code'], $focus );
		$suivi    = ueb_suivi_paiements( $annee['code'], $focus, $periode );
		$activite = ueb_gestion_activite( $annee['code'], $focus, $periode );
		$niveaux  = ueb_gestion_niveaux_par_etab( $annee['code'] );
		$vide_niv = array_fill_keys( array_keys( UEB_NIVEAUX_INSCRIPTION ), 0 );
	} elseif ( 'paiements' === $vue ) {
		$suivi = ueb_suivi_paiements( $annee['code'], $focus, 366 );
	} elseif ( 'scolarites' === $vue ) {
		$agents = ueb_agents(); // tous les comptes du personnel, quel que soit leur rôle
	}
}

/* Coque plein écran et bascule de thème une fois connecté ; l'écran de
   connexion garde l'en-tête du site. */
ueb_page_debut( array(
	'titre'    => 'Administration',
	'variante' => $autorise ? 'bo' : 'gestion',
	'classe'   => $autorise ? 'espace-admin' : '',
	'theme'    => $autorise,
) );
?>
<main id="contenu" class="page-app gestion<?php echo $autorise ? ' page-app--bo' : ''; ?>">

	<?php if ( ! $autorise ) : ?>

		<div class="conteneur">
			<div class="bo-connexion">
				<aside class="bo-connexion__volet">
					<?php ueb_animation( 'embleme', ueb_props_embleme(), 'animation--embleme bo-connexion__embleme', 'Sceau de l’Université d’Ebolowa' ); ?>
					<p class="bo-connexion__marque">Université d’Ebolowa</p>
					<h1 id="titre-connexion">Administration</h1>
					<p class="bo-connexion__intro">L’espace de travail du personnel des inscriptions : chacun y retrouve ses outils.</p>
					<ul class="bo-connexion__points">
						<li><?php echo ueb_icone( 'recu', 17 ); ?>Vérifier les reçus de paiement</li>
						<li><?php echo ueb_icone( 'ecole', 17 ); ?>Suivre les IPES et leurs reversements</li>
						<li><?php echo ueb_icone( 'utilisateur', 17 ); ?>Gérer les rôles et les comptes</li>
					</ul>
				</aside>
				<section class="bo-connexion__formulaire" aria-labelledby="titre-connexion">
					<h2>Connexion</h2>
					<p class="bo-connexion__aide">Avec l’identifiant communiqué par l’administration de la plateforme.</p>
					<?php if ( is_user_logged_in() ) : ?>
						<?php ueb_alerte( 'erreur', "Ce compte n'a accès à aucun espace de gestion." ); ?>
					<?php endif; ?>
					<?php if ( $erreur_connexion ) : ?>
						<?php ueb_alerte( 'erreur', $erreur_connexion ); ?>
					<?php endif; ?>
					<form class="formulaire" method="post" action="<?php echo esc_url( get_permalink() ); ?>" data-formulaire novalidate>
						<?php wp_nonce_field( 'ueb_connexion_admin', 'ueb_connexion_nonce' ); ?>
						<input type="hidden" name="ueb_connexion_admin" value="1">
						<?php
						ueb_champ( array( 'nom' => 'identifiant', 'libelle' => 'Identifiant', 'icone' => 'utilisateur', 'attrs' => array( 'autocomplete' => 'username', 'autocapitalize' => 'none', 'spellcheck' => 'false', 'autofocus' => true ) ) );
						ueb_champ( array( 'nom' => 'mot_de_passe', 'libelle' => 'Mot de passe', 'type' => 'password', 'icone' => 'cadenas', 'attrs' => array( 'autocomplete' => 'current-password' ) ) );
						?>
						<button class="btn btn--primaire btn--large" type="submit"><?php echo ueb_icone( 'bouclier', 18 ); ?>Se connecter</button>
					</form>
				</section>
			</div>
		</div>

	<?php else : ?>

		<div class="bo">
			<?php
			ueb_bo_barre(
				'Administration',
				array(
					array( 'url' => $ici(), 'libelle' => 'Tableau de bord', 'icone' => 'tableau', 'actif' => 'bord' === $vue ),
					array( 'url' => $ici( array( 'vue' => 'paiements' ) ), 'libelle' => 'Paiements', 'icone' => 'banque', 'actif' => 'paiements' === $vue ),
					array( 'url' => $ici( array( 'vue' => 'etudiants' ) ), 'libelle' => 'Étudiants UEB', 'icone' => 'diplome', 'actif' => 'etudiants' === $vue ),
					array( 'url' => $ici( array( 'vue' => 'scolarites' ) ), 'libelle' => 'Personnel', 'icone' => 'groupe', 'actif' => 'scolarites' === $vue ),
					array( 'url' => $ici( array( 'vue' => 'filieres' ) ), 'libelle' => 'Filières', 'icone' => 'fichier', 'actif' => 'filieres' === $vue ),
					array( 'url' => $ici( array( 'vue' => 'ipes' ) ), 'libelle' => 'IPES', 'icone' => 'ecole', 'actif' => 'ipes' === $vue ),
					array( 'url' => ueb_url_direction(), 'libelle' => 'Rôles (Direction)', 'icone' => 'bouclier', 'actif' => false ),
				),
				array(
					'titre' => UEB_UNIVERSITE['fr'],
					'note'  => count( ueb_etablissements() ) . ' établissements',
				)
			);
			?>

			<div class="bo-contenu adm<?php echo 'bord' === $vue ? ' adm-dashboard' : ( 'paiements' === $vue ? ' adm-paiements' : '' ); ?>">

				<?php if ( 'scolarites' === $vue ) : ?>

					<?php
					$suspendus = count( array_filter( $agents, static fn( $a ) => ueb_agent_suspendu( $a->ID ) ) );
					ueb_adm_tete( array(
						'titre'      => 'Personnel',
						'sous_titre' => 'Chaque compte agit selon son rôle et sa portée. Les rôles se définissent dans l’espace Direction.',
						'actions'    => ueb_adm_action( ueb_url_direction(), 'Gérer les rôles', 'bouclier' ),
					) );
					ueb_afficher_flash();
					?>

					<?php if ( $prov ) : ?>
						<div class="provisoire carte" role="status">
							<?php echo ueb_icone( 'cle', 26 ); ?>
							<div>
								<p>Mot de passe initial pour <b><?php echo esc_html( $prov['compte'] ); ?></b>, à communiquer à l’établissement. Il ne sera plus affiché :</p>
								<p class="provisoire__mdp"><?php echo esc_html( $prov['mdp'] ); ?></p>
								<button type="button" class="btn btn--fantome btn--petit provisoire__copier" data-copier-mot-de-passe="<?php echo esc_attr( $prov['mdp'] ); ?>"><?php echo ueb_icone( 'fichier', 16 ); ?><span>Copier le mot de passe</span></button>
								<p class="champ__aide">L’agent se connecte ensuite sur son espace.</p>
							</div>
						</div>
					<?php endif; ?>

					<div class="adm-personnel">
						<section class="adm-panneau adm-comptes" aria-labelledby="adm-comptes-titre">
							<header class="adm-panneau__tete">
								<div>
									<h2 id="adm-comptes-titre">Comptes du personnel</h2>
									<p>
										<?php
										$nb = count( $agents );
										echo esc_html( $nb ? sprintf( '%d compte%s, %s.', $nb, $nb > 1 ? 's' : '', $suspendus ? sprintf( 'dont %d suspendu%s', $suspendus, $suspendus > 1 ? 's' : '' ) : ( $nb > 1 ? 'tous actifs' : 'actif' ) ) : 'Aucun compte pour l’instant.' );
										?>
									</p>
								</div>
							</header>

							<?php if ( ! $agents ) : ?>
								<div class="bo-vide"><span><?php echo ueb_icone( 'groupe', 22 ); ?></span><p>Aucun compte du personnel pour l’instant. Crée le premier avec le formulaire.</p></div>
							<?php else : ?>
								<ul class="adm-comptes__liste">
									<?php foreach ( $agents as $agent ) :
										$sigle    = ueb_etab_agent( $agent->ID );
										$suspendu = ueb_agent_suspendu( $agent->ID );
										$id       = (int) $agent->ID;
										?>
										<li class="adm-compte<?php echo $suspendu ? ' est-suspendu' : ''; ?>">
											<span class="bo-avatar adm-compte__avatar" aria-hidden="true"><?php echo esc_html( ueb_initiales( $agent->display_name ?: $agent->user_login ) ); ?></span>
											<div class="adm-compte__identite">
												<p class="adm-compte__nom"><b><?php echo esc_html( $agent->display_name ); ?></b><span class="adm-role"><?php echo esc_html( ueb_nom_role_du_compte( $agent->ID ) ); ?></span></p>
												<p class="adm-compte__meta">
													<span><?php echo ueb_icone( 'utilisateur', 14 ); ?><?php echo esc_html( $agent->user_login ); ?></span>
													<?php if ( $agent->user_email ) : ?><span><?php echo ueb_icone( 'courriel', 14 ); ?><?php echo esc_html( $agent->user_email ); ?></span><?php endif; ?>
													<span><?php echo ueb_icone( 'calendrier', 14 ); ?>Créé le <?php echo esc_html( $agent->user_registered ? mysql2date( 'd/m/Y', $agent->user_registered ) : '' ); ?></span>
												</p>
											</div>
											<span class="adm-etat<?php echo $suspendu ? ' adm-etat--suspendu' : ''; ?>"><?php echo ueb_icone( $suspendu ? 'pause' : 'check', 14 ); ?><?php echo $suspendu ? 'Suspendu' : 'Actif'; ?></span>
											<div class="adm-compte__outils">
												<form method="post" action="<?php echo esc_url( ueb_url_administration() ); ?>" class="adm-compte__etab">
													<?php ueb_champ_csrf(); ?>
													<input type="hidden" name="ueb_action" value="gestion_agent_modifier">
													<input type="hidden" name="agent_id" value="<?php echo $id; ?>">
													<div class="champ__select">
														<select name="etablissement" aria-label="Établissement de <?php echo esc_attr( $agent->user_login ); ?>">
															<?php foreach ( ueb_etablissements() as $s => $e ) : ?>
																<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $sigle, $s ); ?>><?php echo esc_html( $s ); ?></option>
															<?php endforeach; ?>
														</select><?php echo ueb_icone( 'chevron', 16 ); ?>
													</div>
													<button class="adm-bouton adm-bouton--petit" type="submit">Changer</button>
												</form>
												<div class="adm-compte__actions">
													<button class="adm-bouton adm-bouton--petit" type="button" data-ouvrir-agent-mdp="agent-mdp-<?php echo $id; ?>"><?php echo ueb_icone( 'cle', 15 ); ?>Mot de passe</button>
													<dialog class="bo-agent-mdp" id="agent-mdp-<?php echo $id; ?>" aria-labelledby="agent-mdp-titre-<?php echo $id; ?>">
														<h2 id="agent-mdp-titre-<?php echo $id; ?>">Nouveau mot de passe</h2>
														<p>Définis le mot de passe que <?php echo esc_html( $agent->display_name ?: $agent->user_login ); ?> utilisera pour se connecter.</p>
														<form method="post" action="<?php echo esc_url( ueb_url_administration() ); ?>">
															<?php ueb_champ_csrf(); ?>
															<input type="hidden" name="ueb_action" value="gestion_agent_mdp">
															<input type="hidden" name="agent_id" value="<?php echo $id; ?>">
															<label><span>Nouveau mot de passe</span><input type="password" name="mot_de_passe" minlength="8" autocomplete="new-password" required></label>
															<label><span>Confirmer</span><input type="password" name="mot_de_passe_confirmation" minlength="8" autocomplete="new-password" required></label>
															<div class="bo-agent-mdp__actions"><button class="btn btn--lien btn--petit" type="button" data-fermer-agent-mdp>Annuler</button><button class="btn btn--primaire btn--petit" type="submit">Enregistrer</button></div>
														</form>
													</dialog>
													<form method="post" action="<?php echo esc_url( ueb_url_administration() ); ?>" data-confirmer="<?php echo $suspendu ? 'Rétablir l’accès de cet agent ?' : 'Suspendre l’accès de cet agent ? Son compte est conservé.'; ?>">
														<?php ueb_champ_csrf(); ?>
														<input type="hidden" name="ueb_action" value="gestion_agent_etat">
														<input type="hidden" name="agent_id" value="<?php echo $id; ?>">
														<button class="adm-bouton adm-bouton--petit" type="submit"><?php echo ueb_icone( $suspendu ? 'lecture' : 'pause', 15 ); ?><?php echo $suspendu ? 'Rétablir' : 'Suspendre'; ?></button>
													</form>
													<form method="post" action="<?php echo esc_url( ueb_url_administration() ); ?>" data-confirmer="Supprimer définitivement le compte <?php echo esc_attr( $agent->user_login ); ?> ? Les décisions qu'il a prises resteront enregistrées.">
														<?php ueb_champ_csrf(); ?>
														<input type="hidden" name="ueb_action" value="gestion_agent_supprimer">
														<input type="hidden" name="agent_id" value="<?php echo $id; ?>">
														<button class="adm-bouton adm-bouton--petit adm-bouton--danger" type="submit" aria-label="Supprimer le compte <?php echo esc_attr( $agent->user_login ); ?>" title="Supprimer"><?php echo ueb_icone( 'corbeille', 15 ); ?></button>
													</form>
												</div>
											</div>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</section>

						<section class="adm-panneau adm-creer" aria-labelledby="adm-creer-titre">
							<header class="adm-panneau__tete">
								<div>
									<h2 id="adm-creer-titre">Créer un compte</h2>
									<p>L’agent ne verra que l’établissement choisi, avec les droits de son rôle.</p>
								</div>
							</header>
							<form class="formulaire adm-creer__form" method="post" action="<?php echo esc_url( ueb_url_administration() ); ?>" data-formulaire novalidate>
								<?php ueb_champ_csrf(); ?>
								<input type="hidden" name="ueb_action" value="gestion_creer_agent">
								<?php
								ueb_champ( array( 'nom' => 'login', 'libelle' => 'Identifiant de connexion', 'icone' => 'utilisateur', 'attrs' => array( 'placeholder' => 'scolarite.fs', 'autocapitalize' => 'none', 'spellcheck' => 'false', 'autocomplete' => 'off' ) ) );
								ueb_champ( array( 'nom' => 'nom', 'libelle' => 'Nom de l’agent', 'icone' => 'utilisateur', 'requis' => false, 'attrs' => array( 'placeholder' => 'Nom et prénom', 'autocomplete' => 'off' ) ) );
								ueb_champ( array( 'nom' => 'mot_de_passe', 'libelle' => 'Mot de passe initial', 'type' => 'password', 'icone' => 'cadenas', 'aide' => '8 caractères minimum, avec une lettre et un chiffre.', 'attrs' => array( 'autocomplete' => 'new-password', 'minlength' => 8 ) ) );
								ueb_champ( array( 'nom' => 'email', 'libelle' => 'Adresse e-mail', 'type' => 'email', 'icone' => 'courriel', 'requis' => false, 'aide' => 'Utile pour récupérer un mot de passe oublié.', 'attrs' => array( 'autocomplete' => 'off' ) ) );
								ueb_champ( array( 'nom' => 'etablissement', 'libelle' => 'Établissement', 'type' => 'select', 'icone' => 'ecole', 'options' => array_map( static fn( $e ) => $e['fr'], ueb_etablissements() ) ) );
								ueb_champ( array( 'nom' => 'role', 'libelle' => 'Rôle', 'type' => 'select', 'icone' => 'cle', 'options' => array_map( static fn( $r ) => $r['nom'], ueb_roles_attribuables() ), 'valeur' => ueb_role_par_defaut( UEB_CAP_GESTION ) ) );
								?>
								<button class="btn btn--primaire btn--large" type="submit"><?php echo ueb_icone( 'plus', 18 ); ?>Créer le compte</button>
							</form>
						</section>
					</div>

				<?php elseif ( 'etudiants' === $vue ) : ?>

					<?php
					ueb_adm_tete( array(
						'titre'      => 'Étudiants UEB',
						'sous_titre' => 'Les étudiants inscrits dans les neuf établissements et l’état de leurs droits de l’année.',
					) );
					ueb_afficher_flash();
					ueb_vue_etudiants( array( 'url' => ueb_url_administration(), 'params' => array( 'vue' => 'etudiants' ), 'etabs' => array_keys( ueb_etablissements() ) ) );
					?>

				<?php elseif ( 'ipes' === $vue ) : ?>

					<?php include UEB_INSC_DIR . '/templates/composants/ipes-admin.php'; ?>

				<?php elseif ( 'filieres' === $vue ) : ?>

					<?php include UEB_INSC_DIR . '/templates/composants/filieres-admin.php'; ?>

				<?php elseif ( 'paiements' === $vue ) : ?>

					<?php
					$e_suivi = $focus ? ueb_etablissement( $focus ) : null;
					ueb_adm_tete( array(
						'fil'        => $focus ? array( array( $url( array( 'vue' => 'paiements' ) ), 'Paiements' ), array( '', $focus ) ) : array(),
						'titre'      => $focus ? 'Paiements de ' . $focus : 'Suivi des paiements',
						'sous_titre' => $focus ? 'Droits universitaires et frais médicaux, ' . $e_suivi['fr'] . '.' : 'Droits universitaires et frais médicaux, du bilan global au détail des établissements.',
						'actions'    => ( $focus ? ueb_adm_action( $url( array( 'etab' => $focus ) ), 'Tableau de bord de ' . $focus, 'tableau' ) : '' ) . ueb_adm_exports_menu( $focus ),
					) );
					ueb_afficher_flash();
					ueb_adm_paiements( $suivi, $focus );
					?>

				<?php else : ?>

					<?php
					$e = $focus ? ueb_etablissement( $focus ) : null;
					ueb_adm_tete( $focus
						? array(
							'fil'        => array( array( $url(), 'Tableau de bord' ), array( '', $focus ) ),
							'titre'      => $e['fr'],
							'sous_titre' => sprintf( '%s, à %s : %d quitus pour %s cette année.', $focus, $e['ville'], $chiffres['quitus'], ueb_suivi_etudiants( $chiffres['etudiants'] ) ),
							'actions'    => ueb_adm_action( $url( array( 'vue' => 'paiements', 'etab' => $focus ) ), 'Paiements de ' . $focus, 'banque', true ),
						)
						: array(
							'titre'      => 'Tableau de bord',
							'sous_titre' => 'Inscriptions, paiements et activité des établissements en un regard.',
							'actions'    => ueb_adm_action( $url( array( 'vue' => 'paiements' ) ), 'Suivi des paiements', 'banque', true ),
						)
					);
					ueb_afficher_flash();

					ueb_adm_dashboard( $chiffres, $suivi, $activite, $focus, $periode );
					?>

					<div class="adm-grille adm-grille--graphes adm-complements">
						<?php
						ueb_adm_statistiques( $chiffres );
						ueb_adm_comparaison( $suivi, $focus, $periode );
						ueb_graphe_anneau( 'Répartition par sexe', 'Étudiants ayant au moins un quitus', array(
							'Masculin' => array( 'valeur' => $chiffres['sexe']['M'], 'couleur' => 'var(--viz-id-1)' ),
							'Féminin' => array( 'valeur' => $chiffres['sexe']['F'], 'couleur' => 'var(--viz-id-2)' ),
						) );
						?>
					</div>

					<?php if ( $focus ) : ?>

						<div class="adm-grille adm-grille--etab">
							<?php
							ueb_adm_niveaux( array_merge( $vide_niv, array_intersect_key( $niveaux[ $focus ] ?? array(), $vide_niv ) ), $chiffres['etudiants'] );
							ueb_adm_filieres( ueb_gestion_par_filiere( $annee['code'], $focus ) );
							?>
						</div>
						<?php ueb_graphe_filieres( 'Recouvrement par filière', 'Part encaissée des droits attendus, les plus gros montants d’abord', $suivi['filieres'], $url( array( 'vue' => 'paiements', 'etab' => $focus ) ) ); ?>

					<?php else : ?>

						<?php
						$stats          = ueb_gestion_stats( $annee['code'] );
						$etudiants_etab = ueb_gestion_etudiants_par_etab( $annee['code'] );
						$lignes         = array();
						$rang           = 0;
						foreach ( ueb_etablissements() as $sigle => $etab ) {
							$statuts  = $stats['etabs'][ $sigle ] ?? array();
							$lignes[] = array(
								'sigle'     => $sigle,
								'etab'      => $etab,
								'etudiants' => (int) ( $etudiants_etab[ $sigle ] ?? 0 ),
								'niveaux'   => array_merge( $vide_niv, array_intersect_key( $niveaux[ $sigle ] ?? array(), $vide_niv ) ),
								'statuts'   => $statuts,
								'quitus'    => (int) array_sum( $statuts ),
								'suivi'     => $suivi['etabs'][ $sigle ] ?? null,
								'url'       => $url( array( 'etab' => $sigle, 'periode' => $periode ) ),
								'rang'      => $rang++,
							);
						}
						/* Du plus grand effectif au plus petit ; à égalité, l'ordre de la configuration. */
						usort( $lignes, static fn( $a, $b ) => $b['etudiants'] <=> $a['etudiants'] ?: $a['rang'] <=> $b['rang'] );
						ueb_adm_etablissements( $lignes );
						?>

					<?php endif; ?>

				<?php endif; ?>
			</div>
		</div>

	<?php endif; ?>

</main>
<?php
ueb_page_fin( 'gestion' );
