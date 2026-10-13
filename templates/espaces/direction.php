<?php
/**
 *
 * Espace de gestion : rôles dynamiques et comptes du personnel. Même rendu
 * que l'espace de gestion de la préinscription (templates/access-portal.php),
 * adapté aux rôles de l'inscription. Accès : capacité « ueb_diriger » (les
 * administrateurs l'ont d'office). Vues par ?vue= :
 *   - roles (défaut) : les rôles en cartes (portée, comptes, permissions),
 *                      assistant en fenêtre (nom, portée, permissions) avec
 *                      l'aperçu de la barre latérale du titulaire ;
 *   - role           : la même page, assistant ouvert (création, ?role=
 *                      pour une modification, retour après une erreur) ;
 *   - personnel      : les comptes de l'équipe et le panneau « Créer un
 *                      compte » / « Changer le rôle » (?compte=ID) ;
 *   - securite       : son propre mot de passe.
 *
 * Aucun nom de rôle n'est écrit ici : tout vient du registre (inc/roles.php).
 * Chaque action est revérifiée côté serveur (inc/direction.php).
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

/* ---------- Connexion, avant tout affichage ---------- */
$erreur_connexion = '';
if ( isset( $_POST['ueb_connexion_direction'] ) ) {
	if ( ! isset( $_POST['ueb_connexion_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['ueb_connexion_nonce'] ) ), 'ueb_connexion_direction' ) ) {
		$erreur_connexion = 'Ta session a expiré. Recommence.';
	} else {
		$identifiant = sanitize_user( sanitize_text_field( wp_unslash( $_POST['identifiant'] ?? '' ) ), true );
		$utilisateur = ueb_connexion_gestion_bloquee( $identifiant ) ? new WP_Error( 'ueb_rate_limited' ) : wp_signon( array(
			'user_login'    => $identifiant,
			'user_password' => (string) wp_unslash( $_POST['mot_de_passe'] ?? '' ),
			'remember'      => false,
		), is_ssl() );
		if ( is_wp_error( $utilisateur ) ) {
			ueb_noter_echec_gestion( $identifiant );
			$erreur_connexion = ueb_message_echec_connexion( $utilisateur, 'Identifiant ou mot de passe incorrect.' );
		} elseif ( ! user_can( $utilisateur, UEB_CAP_DIRECTION ) || ueb_agent_suspendu( $utilisateur->ID ) ) {
			wp_logout();
			$erreur_connexion = "Ce compte n'a pas accès à l'espace Direction.";
		} else {
			ueb_reinitialiser_echecs_gestion( $identifiant );
			wp_set_current_user( $utilisateur->ID );
			wp_set_auth_cookie( $utilisateur->ID, false, is_ssl() );
			wp_safe_redirect( ueb_url_direction() );
			exit;
		}
	}
}


$autorise = ueb_peut( UEB_CAP_DIRECTION );

if ( $autorise ) {
	$vue          = sanitize_key( $_GET['vue'] ?? 'roles' );
	$vue          = in_array( $vue, array( 'roles', 'role', 'personnel', 'securite' ), true ) || ( 'etudiants' === $vue && ueb_peut( 'ueb_voir_etudiants' ) ) ? $vue : 'roles';
	$ici          = static fn( array $args = array() ) => esc_url( add_query_arg( $args, ueb_url_direction() ) );
	$catalogue    = ueb_permissions();
	$roles        = ueb_roles();
	$nombres      = ueb_comptes_par_role();
	$attribuables = ueb_roles_attribuables();
	$mes_etabs    = ueb_etabs_autorises();
	$totale       = ueb_portee_totale();
	$prov         = $_SESSION['ueb_mdp_direction'] ?? null;
	unset( $_SESSION['ueb_mdp_direction'] );
	$titres = array( 'roles' => 'Rôles et accès', 'role' => 'Rôles et accès', 'personnel' => 'Comptes du personnel', 'etudiants' => 'Étudiants UEB', 'securite' => 'Sécurité' );
}

/* Connecté : coque plein écran et thème clair / sombre, comme l'administration.
   Sinon : l'écran de connexion occupe toute la page, sans en-tête de site. */
ueb_page_debut( array(
	'titre'    => $autorise ? $titres[ $vue ] : 'Espace de gestion',
	'variante' => $autorise ? 'bo' : 'auth',
	'classe'   => $autorise ? 'espace-admin espace-direction' : 'espace-direction espace-direction--connexion',
	'theme'    => $autorise,
) );
?>
<?php if ( ! $autorise ) : ?>

	<main id="contenu" class="gestion-connexion">
		<section class="gestion-connexion__marque">
			<a class="gestion-connexion__logo" href="<?php echo esc_url( home_url( '/' ) ); ?>">
				<span class="gestion-connexion__sceau"><?php ueb_animation( 'embleme', ueb_props_embleme(), 'animation--embleme', 'Sceau de l’Université d’Ebolowa' ); ?></span>
				<span>Université d’Ebolowa<small>Plateforme d’inscription</small></span>
			</a>
			<div class="gestion-connexion__propos">
				<h1>
					<span class="gestion-connexion__ligne"><span>Espace</span></span>
					<span class="gestion-connexion__ligne"><span>de gestion</span></span>
				</h1>
				<span class="gestion-connexion__filet" aria-hidden="true"></span>
				<p class="gestion-connexion__chapo">Les rôles et le personnel de la plateforme d’inscription, établissement par établissement, réservés aux personnels habilités.</p>
				<a class="gestion-connexion__public" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo ueb_icone( 'fleche-g', 16 ); ?>Retour au site public</a>
			</div>
			<p class="gestion-connexion__devise"><span>Savoir</span><span>Savoir-faire</span><span>Savoir-être</span></p>
		</section>

		<section class="gestion-connexion__panneau" aria-labelledby="titre-connexion">
			<div class="gestion-connexion__verre">
				<h2 id="titre-connexion">Connexion</h2>
				<p class="gestion-connexion__intro">Connecte-toi avec ton compte professionnel.</p>
				<?php if ( is_user_logged_in() ) : ?><?php ueb_alerte( 'erreur', "Ce compte n'a pas accès à l'espace Direction." ); ?><?php endif; ?>
				<?php if ( $erreur_connexion ) : ?><?php ueb_alerte( 'erreur', $erreur_connexion ); ?><?php endif; ?>
				<form class="gestion-form" method="post" action="<?php echo esc_url( get_permalink() ); ?>" novalidate>
					<?php wp_nonce_field( 'ueb_connexion_direction', 'ueb_connexion_nonce' ); ?>
					<input type="hidden" name="ueb_connexion_direction" value="1">
					<label>Identifiant ou adresse e-mail<input name="identifiant" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus></label>
					<label>Mot de passe<span class="gestion-mdp"><input name="mot_de_passe" type="password" autocomplete="current-password" required><button type="button" data-voir-mdp aria-label="Afficher le mot de passe" aria-pressed="false"><?php echo ueb_icone( 'oeil', 18 ); ?></button></span></label>
					<button class="gestion-connexion__envoyer" type="submit">Se connecter</button>
				</form>
				<p class="gestion-connexion__confiance"><?php echo ueb_icone( 'bouclier', 16 ); ?><span>Utilise le compte que la Direction t’a attribué.</span></p>
			</div>
		</section>
	</main>

<?php else : ?>

	<main id="contenu" class="page-app gestion page-app--bo">
		<div class="bo">
			<?php
			$autres = array_filter( array(
				ueb_est_admin_ueb() ? array( 'url' => ueb_url_administration(), 'libelle' => 'Administration', 'icone' => 'tableau', 'actif' => false ) : null,
				ueb_est_scolarite() && ( ueb_peut( UEB_CAP_GESTION ) || ueb_peut( 'ueb_voir_paiements' ) ) ? array( 'url' => ueb_url_scolarite(), 'libelle' => 'Espace scolarité', 'icone' => 'recu', 'actif' => false ) : null,
				ueb_est_cellule() && ueb_peut( UEB_CAP_COMPTES ) ? array( 'url' => ueb_url_cellule(), 'libelle' => 'Comptes étudiants', 'icone' => 'utilisateur', 'actif' => false ) : null,
			) );
			ueb_bo_barre(
				'Espace de gestion',
				array_merge(
					array(
						array( 'url' => $ici(), 'libelle' => 'Rôles et accès', 'icone' => 'bouclier', 'actif' => in_array( $vue, array( 'roles', 'role' ), true ) ),
						array( 'url' => $ici( array( 'vue' => 'personnel' ) ), 'libelle' => 'Comptes', 'icone' => 'groupe', 'actif' => 'personnel' === $vue ),
						ueb_peut( 'ueb_voir_etudiants' ) ? array( 'url' => $ici( array( 'vue' => 'etudiants' ) ), 'libelle' => 'Étudiants UEB', 'icone' => 'diplome', 'actif' => 'etudiants' === $vue ) : null,
						ueb_est_admin_ueb() ? null : array( 'url' => $ici( array( 'vue' => 'securite' ) ), 'libelle' => 'Sécurité', 'icone' => 'cadenas', 'actif' => 'securite' === $vue ),
					),
					$autres ? array_merge( array( array( 'groupe' => 'Autres espaces' ) ), $autres ) : array()
				),
				array( 'titre' => $totale ? 'Université' : implode( ', ', $mes_etabs ), 'note' => $totale ? 'Tous les établissements' : 'Ta portée' )
			);
			?>

			<div class="bo-contenu gestion-contenu">
				<?php ueb_gestion_tete( $titres[ $vue ] ); ?>
				<?php ueb_afficher_flash(); ?>

				<?php if ( in_array( $vue, array( 'roles', 'role' ), true ) ) : ?>

					<?php
					/* Assistant ouvert d'emblée : ?vue=role (création, modification,
					   retour après une erreur de saisie, rôle tout juste dupliqué). */
					$ouvrir     = 'role' === $vue;
					$slug_edite = $ouvrir ? sanitize_key( wp_unslash( $_GET['role'] ?? '' ) ) : '';
					$existant   = $slug_edite ? ueb_role( $slug_edite ) : null;
					$interdit   = $slug_edite && ( ! $existant || ! ueb_role_modifiable( $slug_edite ) );
					$saisi      = $_SESSION['ueb_role_saisi'] ?? null;
					unset( $_SESSION['ueb_role_saisi'] );
					if ( $interdit ) {
						$ouvrir     = false;
						$slug_edite = '';
					}
					$def = $saisi ?: ( $existant && ! $interdit ? $existant : array( 'nom' => '', 'portee' => 'un', 'etablissements' => array(), 'permissions' => array() ) );

					/* Données de l'assistant : rôles modifiables ou duplicables, catalogue, écrans. */
					$donnees_roles = array();
					foreach ( $roles as $slug => $r ) {
						if ( ueb_role_dans_mes_droits( $r ) ) {
							$donnees_roles[ $slug ] = array( 'nom' => $r['nom'], 'portee' => $r['portee'], 'etablissements' => array_values( (array) $r['etablissements'] ), 'permissions' => array_values( $r['permissions'] ) );
						}
					}
					$groupes = array();
					foreach ( $catalogue as $cap => $p ) {
						$groupes[ $p['groupe'] ][ $cap ] = $p;
					}
					?>

					<?php if ( $interdit ) : ?>
						<?php ueb_alerte( 'erreur', 'Ce rôle ne peut pas être modifié depuis ton compte : c’est le tien, ou il donne plus de droits que les tiens.' ); ?>
					<?php endif; ?>

					<div class="gestion-section">
						<div>
							<h2>Les rôles de ton équipe</h2>
							<p>Un rôle, c’est une portée (quels établissements) et des permissions (quoi y faire).</p>
						</div>
						<a class="adm-bouton adm-bouton--primaire" href="<?php echo $ici( array( 'vue' => 'role' ) ); ?>" data-nouveau-role><?php echo ueb_icone( 'plus', 17 ); ?>Créer un rôle</a>
					</div>

					<?php if ( ! $roles ) : ?>
						<div class="gestion-panneau">
							<div class="gestion-vide"><?php echo ueb_icone( 'bouclier', 30 ); ?><strong>Aucun rôle pour l’instant</strong><p>Crée ton premier rôle : donne-lui un nom, choisis les établissements qu’il couvre, puis coche ce qu’il peut faire.</p></div>
						</div>
					<?php else : ?>
						<div class="gestion-roles">
							<?php foreach ( $roles as $slug => $r ) :
								$n          = (int) ( $nombres[ $slug ] ?? 0 );
								$modifiable = ueb_role_modifiable( $slug );
								$mien       = ueb_role_du_compte() === $slug;
								$perms      = array_values( array_intersect( array_keys( $catalogue ), $r['permissions'] ) );
								?>
								<article class="gestion-panneau gestion-role" aria-labelledby="role-<?php echo esc_attr( $slug ); ?>">
									<div>
										<h3 id="role-<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $r['nom'] ); ?></h3>
										<div class="gestion-role__meta">
											<span class="gestion-etiquette gestion-etiquette--portee"><?php echo ueb_icone( 'ecole', 14 ); ?><?php echo esc_html( ueb_gestion_portee_courte( $r ) ); ?></span>
											<span class="gestion-etiquette"><?php echo ueb_icone( 'groupe', 14 ); ?><?php echo esc_html( $n . ' compte' . ( $n > 1 ? 's' : '' ) ); ?></span>
											<?php if ( $mien ) : ?>
												<span class="gestion-etiquette gestion-etiquette--verrou">Ton rôle</span>
											<?php elseif ( ! $modifiable ) : ?>
												<span class="gestion-etiquette gestion-etiquette--verrou" title="Il donne plus de droits que les tiens">Protégé</span>
											<?php endif; ?>
											<?php if ( ! empty( $r['historique'] ) ) : ?>
												<span class="gestion-etiquette" title="Rôle repris de l’ancienne configuration, modifiable comme les autres">Repris de l’existant</span>
											<?php endif; ?>
										</div>
									</div>
									<ul class="gestion-role__droits" aria-label="Permissions">
										<?php foreach ( array_slice( $perms, 0, 4 ) as $cap ) : ?>
											<li><?php echo ueb_icone( 'check', 14 ); ?><?php echo esc_html( $catalogue[ $cap ]['libelle'] ); ?></li>
										<?php endforeach; ?>
										<?php if ( count( $perms ) > 4 ) : $reste = count( $perms ) - 4; ?>
											<li class="gestion-role__plus"><?php echo ueb_icone( 'plus', 14 ); ?><?php echo esc_html( $reste . ( $reste > 1 ? ' autres accès' : ' autre accès' ) ); ?></li>
										<?php endif; ?>
									</ul>
									<div class="gestion-role__actions">
										<?php if ( $modifiable ) : ?>
											<a class="adm-bouton" href="<?php echo $ici( array( 'vue' => 'role', 'role' => $slug ) ); ?>" data-modifier-role="<?php echo esc_attr( $slug ); ?>"><?php echo ueb_icone( 'crayon', 16 ); ?>Modifier</a>
										<?php endif; ?>
										<?php if ( isset( $donnees_roles[ $slug ] ) ) : ?>
											<form method="post" action="<?php echo esc_url( ueb_url_direction() ); ?>" data-dupliquer-role="<?php echo esc_attr( $slug ); ?>">
												<?php ueb_champs_direction( 'direction_role_dupliquer' ); ?>
												<input type="hidden" name="role" value="<?php echo esc_attr( $slug ); ?>">
												<button class="adm-bouton" type="submit"><?php echo ueb_icone( 'copier', 16 ); ?>Dupliquer</button>
											</form>
										<?php endif; ?>
										<?php if ( $modifiable ) : ?>
											<button class="adm-bouton" type="button" data-ouvrir-dialogue="suppr-<?php echo esc_attr( $slug ); ?>"><?php echo ueb_icone( 'corbeille', 16 ); ?>Supprimer</button>
										<?php endif; ?>
									</div>
								</article>

								<?php if ( $modifiable ) : $autres_roles = array_diff_key( $attribuables, array( $slug => 1 ) ); ?>
									<dialog class="gestion-dialogue gestion-dialogue--petit" id="suppr-<?php echo esc_attr( $slug ); ?>" aria-labelledby="suppr-titre-<?php echo esc_attr( $slug ); ?>">
										<form method="post" action="<?php echo esc_url( ueb_url_direction() ); ?>">
											<?php ueb_champs_direction( 'direction_role_supprimer' ); ?>
											<input type="hidden" name="role" value="<?php echo esc_attr( $slug ); ?>">
											<div class="gestion-dialogue__tete">
												<h2 id="suppr-titre-<?php echo esc_attr( $slug ); ?>">Supprimer « <?php echo esc_html( $r['nom'] ); ?> » ?</h2>
												<button type="button" class="adm-bouton adm-bouton--icone" data-fermer-dialogue aria-label="Fermer"><?php echo ueb_icone( 'croix', 16 ); ?></button>
											</div>
											<div class="gestion-dialogue__corps">
												<p><?php echo $n ? esc_html( sprintf( '« %s » sera supprimé. %d compte%s y %s rattaché%s : choisis le rôle qui %s reprendra. Aucun compte n’est supprimé.', $r['nom'], $n, $n > 1 ? 's' : '', $n > 1 ? 'sont' : 'est', $n > 1 ? 's' : '', $n > 1 ? 'les' : 'le' ) ) : esc_html( sprintf( '« %s » sera supprimé. Aucun compte n’y est rattaché.', $r['nom'] ) ); ?></p>
												<div class="gestion-form">
													<?php if ( $n ) : ?>
														<label>Réaffecter les comptes à
															<select name="remplacant" required>
																<option value="">Choisir un rôle</option>
																<?php foreach ( $autres_roles as $s => $a ) : ?><option value="<?php echo esc_attr( $s ); ?>"><?php echo esc_html( $a['nom'] ); ?></option><?php endforeach; ?>
															</select>
														</label>
													<?php endif; ?>
													<label>Pour confirmer, saisis SUPPRIMER<input type="text" name="confirmation" autocomplete="off" required pattern="[Ss][Uu][Pp][Pp][Rr][Ii][Mm][Ee][Rr]"></label>
												</div>
											</div>
											<div class="gestion-dialogue__actions">
												<button type="button" class="adm-bouton" data-fermer-dialogue>Annuler</button>
												<button type="submit" class="adm-bouton adm-bouton--danger-plein"><?php echo ueb_icone( 'corbeille', 16 ); ?>Supprimer le rôle</button>
											</div>
										</form>
									</dialog>
								<?php endif; ?>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

					<!-- Assistant en trois temps : nommer, délimiter, autoriser. Rien
					     n'est enregistré avant la dernière étape. -->
					<dialog class="gestion-dialogue" id="dialogue-role" aria-labelledby="dialogue-role-titre"<?php echo $ouvrir ? ' open data-ouvert' : ''; ?>>
						<form method="post" action="<?php echo esc_url( ueb_url_direction() ); ?>" data-assistant novalidate>
							<?php ueb_champs_direction( 'direction_role_enregistrer' ); ?>
							<input type="hidden" name="role" value="<?php echo esc_attr( $slug_edite ); ?>">
							<div class="gestion-dialogue__tete">
								<div>
									<h2 id="dialogue-role-titre"><?php echo $existant && ! $interdit ? 'Modifier le rôle' : 'Créer un rôle'; ?></h2>
									<p>Trois étapes. Rien n’est enregistré avant la dernière.</p>
								</div>
								<button type="button" class="adm-bouton adm-bouton--icone" data-fermer-dialogue aria-label="Fermer l’assistant"><?php echo ueb_icone( 'croix', 16 ); ?></button>
							</div>

							<div class="gestion-dialogue__corps">
								<ol class="gestion-etapes">
									<li aria-current="step"><span>Nom</span></li>
									<li><span>Portée</span></li>
									<li><span>Permissions</span></li>
								</ol>
								<div class="alerte alerte--erreur" role="alert" data-erreur-role hidden><?php echo ueb_icone( 'alerte', 18 ); ?><p></p></div>

								<div class="gestion-assistant">
									<div>
										<fieldset data-etape="0">
											<legend>Comment s’appelle ce rôle ?</legend>
											<div class="gestion-form">
												<label>Nom du rôle<input name="nom" maxlength="60" value="<?php echo esc_attr( $def['nom'] ); ?>" placeholder="Scolarité, Coordination pédagogique, Suivi financier…" autocomplete="off" required data-champ-nom></label>
												<p class="gestion-aide">Choisis le nom que ton équipe emploie déjà. Il apparaîtra sur chaque compte qui porte ce rôle.</p>
											</div>
										</fieldset>

										<fieldset data-etape="1">
											<legend>Sur quels établissements agit-il ?</legend>
											<div class="gestion-portees">
												<?php
												$portees = array(
													'un'        => array( 'Un seul établissement', 'Choisi sur chaque compte, à sa création. Le rôle ne voit jamais les autres.' ),
													'plusieurs' => array( 'Plusieurs établissements', 'Les mêmes pour tous ses comptes. Choisis-en au moins deux.' ),
													'tous'      => array( 'Tous les établissements', 'Y compris ceux qui seraient ajoutés plus tard.' ),
												);
												foreach ( $portees as $cle => $p ) :
													if ( 'tous' === $cle && ! $totale ) {
														continue;
													}
													?>
													<label class="gestion-portee">
														<input type="radio" name="portee" value="<?php echo esc_attr( $cle ); ?>" <?php checked( $def['portee'], $cle ); ?> data-portee>
														<span><?php echo esc_html( $p[0] ); ?><small><?php echo esc_html( $p[1] ); ?></small></span>
													</label>
												<?php endforeach; ?>
											</div>
											<div class="gestion-choix" data-etabs-choix>
												<?php foreach ( ueb_etablissements() as $sigle => $e ) : $possible = in_array( $sigle, $mes_etabs, true ); ?>
													<label class="gestion-case<?php echo $possible ? '' : ' est-hors'; ?>">
														<input type="checkbox" name="etablissements[]" value="<?php echo esc_attr( $sigle ); ?>" <?php checked( in_array( $sigle, (array) $def['etablissements'], true ) ); ?> <?php disabled( ! $possible ); ?>>
														<?php echo esc_html( $sigle . ' : ' . $e['fr'] ); ?>
													</label>
												<?php endforeach; ?>
											</div>
										</fieldset>

										<fieldset data-etape="2">
											<legend>Quels accès lui accorder ?</legend>
											<div class="gestion-form">
												<label>Partir d’un modèle
													<select data-modele-role>
														<option value="">Tout décocher et composer librement</option>
														<?php foreach ( ueb_modeles_roles() as $cle => $m ) : ?>
															<option value="<?php echo esc_attr( $cle ); ?>"><?php echo esc_html( $m['titre'] ); ?></option>
														<?php endforeach; ?>
													</select>
												</label>
												<p class="gestion-aide">Un modèle ne fait que cocher des cases : tout reste modifiable ensuite. Tu ne peux accorder que les permissions que tu as toi-même.</p>
											</div>
											<div class="gestion-droits">
												<?php foreach ( $groupes as $groupe => $perms ) : ?>
													<div class="gestion-droits__groupe">
														<h3><?php echo esc_html( $groupe ); ?></h3>
														<div>
															<?php foreach ( $perms as $cap => $p ) : $possible = current_user_can( $cap ); ?>
																<label class="gestion-case<?php echo $possible ? '' : ' est-hors'; ?>" title="<?php echo esc_attr( $possible ? $p['aide'] : 'Tu n’as pas cette permission : tu ne peux pas l’accorder.' ); ?>">
																	<input type="checkbox" name="permissions[]" value="<?php echo esc_attr( $cap ); ?>" <?php checked( in_array( $cap, $def['permissions'], true ) ); ?> <?php disabled( ! $possible ); ?> <?php echo empty( $p['requiert'] ) ? '' : 'data-requiert="' . esc_attr( $p['requiert'] ) . '"'; ?>>
																	<?php echo esc_html( $p['libelle'] ); ?>
																</label>
															<?php endforeach; ?>
														</div>
													</div>
												<?php endforeach; ?>
											</div>
										</fieldset>
									</div>

									<!-- Aperçu : la barre latérale que verra le titulaire.
									     On montre l'interface plutôt que de la décrire. -->
									<aside class="gestion-apercu" aria-live="polite">
										<p class="gestion-apercu__tete">Ce que ce rôle verra</p>
										<div class="gestion-apercu__ecran">
											<span class="gestion-apercu__nom" data-apercu-nom><?php echo esc_html( $def['nom'] ?: 'Nouveau rôle' ); ?></span>
											<div class="gestion-apercu__nav" data-apercu-nav></div>
										</div>
										<p class="gestion-apercu__pied" data-apercu-pied></p>
									</aside>
								</div>
							</div>

							<div class="gestion-dialogue__actions">
								<button type="button" class="adm-bouton gestion-dialogue__espace" data-etape-precedente hidden><?php echo ueb_icone( 'fleche-g', 16 ); ?>Précédent</button>
								<button type="button" class="adm-bouton" data-fermer-dialogue>Annuler</button>
								<button type="button" class="adm-bouton adm-bouton--primaire" data-etape-suivante hidden>Continuer<?php echo ueb_icone( 'fleche', 16 ); ?></button>
								<button type="submit" class="adm-bouton adm-bouton--primaire" data-enregistrer><?php echo ueb_icone( 'check', 16 ); ?>Enregistrer le rôle</button>
							</div>
						</form>
					</dialog>
					<script type="application/json" id="donnees-roles"><?php echo wp_json_encode( array(
						'roles'       => $donnees_roles,
						'permissions' => array_map( static fn( $p ) => array( 'libelle' => $p['libelle'], 'requiert' => $p['requiert'] ?? '' ), $catalogue ),
						'ecrans'      => array_map( static fn( $e ) => array( $e[0], $e[1], ueb_icone( $e[2], 15 ) ), ueb_gestion_ecrans() ),
						'verrou'      => ueb_icone( 'cadenas', 15 ),
						'modeles'     => array_map( static fn( $m ) => $m['permissions'], ueb_modeles_roles() ),
						'etabs'       => array_keys( ueb_etablissements() ),
					), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?></script>

				<?php elseif ( 'etudiants' === $vue ) : ?>

					<?php ueb_vue_etudiants( array( 'url' => ueb_url_direction(), 'params' => array( 'vue' => 'etudiants' ), 'etabs' => ueb_etabs_autorises() ) ); ?>

				<?php elseif ( 'personnel' === $vue ) : ?>

					<?php
					/* Comptes visibles : ceux dont la portée croise la sienne (tous pour une portée totale). */
					$recherche = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );
					$agents    = array_values( array_filter( ueb_agents(), static function ( $u ) use ( $totale, $mes_etabs, $recherche ) {
						if ( ! $totale && ! array_intersect( ueb_etabs_autorises( $u->ID ), $mes_etabs ) ) {
							return false;
						}
						return '' === $recherche || false !== mb_stripos( $u->display_name . ' ' . $u->user_login . ' ' . $u->user_email, $recherche );
					} ) );
					/* Changement de rôle ouvert par ?compte=ID (sans script) ou par le bouton (avec). */
					$cible = (int) ( $_GET['compte'] ?? 0 );
					$cible = $cible && ueb_agent_gerable( $cible ) ? get_userdata( $cible ) : null;
					?>

					<div class="gestion-deux">
						<section class="gestion-panneau" aria-labelledby="titre-comptes">
							<div class="gestion-panneau__tete">
								<h2 id="titre-comptes">Les comptes de ton équipe</h2>
								<p>Les comptes du personnel de ta portée. Seuls ceux dont le rôle est dans tes droits sont modifiables.</p>
							</div>

							<?php if ( $prov ) : ?>
								<div class="gestion-provisoire" role="status">
									<p>Mot de passe provisoire pour <b><?php echo esc_html( $prov['compte'] ); ?></b>, à transmettre maintenant : il ne sera plus affiché.</p>
									<p class="gestion-provisoire__mdp"><?php echo esc_html( $prov['mdp'] ); ?></p>
									<button type="button" class="adm-bouton adm-bouton--petit provisoire__copier" data-copier-mot-de-passe="<?php echo esc_attr( $prov['mdp'] ); ?>"><?php echo ueb_icone( 'copier', 15 ); ?><span>Copier le mot de passe</span></button>
								</div>
							<?php endif; ?>

							<form method="get" action="<?php echo esc_url( ueb_url_direction() ); ?>" class="gestion-filtres"><?php ueb_champ_espace(); ?>
								<input type="hidden" name="vue" value="personnel">
								<label class="gestion-champ gestion-champ--large">
									<span>Rechercher</span>
									<span class="gestion-recherche"><?php echo ueb_icone( 'loupe', 16 ); ?><input type="search" name="q" value="<?php echo esc_attr( $recherche ); ?>" placeholder="Nom, identifiant ou e-mail" autocomplete="off"<?php echo ueb_attr_suggestions_liste( array_map( static fn( $u ) => array( $u->display_name ?: $u->user_login, $u->user_login, $u->user_login ), array_filter( ueb_agents(), static fn( $u ) => $totale || array_intersect( ueb_etabs_autorises( $u->ID ), $mes_etabs ) ) ) ); // phpcs:ignore -- échappé ?>></span>
								</label>
								<button class="adm-bouton" type="submit">Rechercher</button>
							</form>

							<?php if ( ! $agents ) : ?>
								<div class="gestion-vide"><?php echo ueb_icone( 'groupe', 30 ); ?><strong>Aucun compte à afficher</strong><p><?php echo $recherche ? 'Aucun compte ne correspond à cette recherche.' : 'Crée un compte avec le formulaire à côté pour démarrer.'; ?></p></div>
							<?php else : ?>
								<div class="gestion-table">
									<table>
										<thead><tr><th>Compte</th><th>Rôle</th><th>État</th><th><span class="sr">Actions</span></th></tr></thead>
										<tbody>
										<?php foreach ( $agents as $u ) :
											$gerable  = ueb_agent_gerable( $u->ID );
											$slug_u   = ueb_role_du_compte( $u->ID );
											$def_u    = ueb_role( $slug_u );
											$suspendu = ueb_agent_suspendu( $u->ID );
											$etab_u   = strtoupper( (string) get_user_meta( $u->ID, 'ueb_etablissement', true ) );
											?>
											<tr>
												<td>
													<strong><?php echo esc_html( $u->display_name ?: $u->user_login ); ?></strong>
													<small><?php echo esc_html( $u->user_login ); ?></small>
													<?php if ( $u->user_email ) : ?><small><?php echo esc_html( $u->user_email ); ?></small><?php endif; ?>
												</td>
												<td>
													<?php echo esc_html( ueb_nom_role_du_compte( $u->ID ) ); ?>
													<small><?php echo esc_html( $def_u && 'un' !== $def_u['portee'] ? ueb_gestion_portee_courte( $def_u ) : implode( ', ', ueb_etabs_autorises( $u->ID ) ) ); ?></small>
												</td>
												<td><span class="gestion-statut<?php echo $suspendu ? ' gestion-statut--suspendu' : ''; ?>"><?php echo $suspendu ? 'Suspendu' : 'Actif'; ?></span></td>
												<td class="gestion-table__actions">
													<?php if ( $gerable ) : ?>
														<a class="adm-bouton adm-bouton--petit" href="<?php echo $ici( array( 'vue' => 'personnel', 'compte' => $u->ID ) ); ?>#panneau-compte" data-changer-role="<?php echo (int) $u->ID; ?>" data-nom="<?php echo esc_attr( $u->display_name ?: $u->user_login ); ?>" data-role="<?php echo esc_attr( $slug_u ); ?>" data-etab="<?php echo esc_attr( $etab_u ); ?>">Changer de rôle</a>
														<form method="post" action="<?php echo esc_url( ueb_url_direction() ); ?>" data-confirmer="Créer un nouveau mot de passe provisoire pour <?php echo esc_attr( $u->user_login ); ?> ? L’ancien ne fonctionnera plus.">
															<?php ueb_champs_direction( 'direction_compte_mdp' ); ?>
															<input type="hidden" name="agent_id" value="<?php echo (int) $u->ID; ?>">
															<button class="adm-bouton adm-bouton--petit adm-bouton--icone" type="submit" aria-label="Nouveau mot de passe pour <?php echo esc_attr( $u->user_login ); ?>" title="Nouveau mot de passe provisoire"><?php echo ueb_icone( 'cle', 15 ); ?></button>
														</form>
														<form method="post" action="<?php echo esc_url( ueb_url_direction() ); ?>" data-confirmer="<?php echo $suspendu ? 'Rétablir l’accès de ce compte ?' : 'Suspendre ce compte ? Il est conservé, ses décisions restent tracées.'; ?>">
															<?php ueb_champs_direction( 'direction_compte_etat' ); ?>
															<input type="hidden" name="agent_id" value="<?php echo (int) $u->ID; ?>">
															<button class="adm-bouton adm-bouton--petit adm-bouton--icone" type="submit" aria-label="<?php echo esc_attr( ( $suspendu ? 'Rétablir ' : 'Suspendre ' ) . $u->user_login ); ?>" title="<?php echo $suspendu ? 'Rétablir l’accès' : 'Suspendre l’accès'; ?>"><?php echo ueb_icone( $suspendu ? 'lecture' : 'pause', 15 ); ?></button>
														</form>
													<?php else : ?>
														<span class="gestion-verrou"><?php echo ueb_icone( 'cadenas', 14 ); ?><?php echo get_current_user_id() === $u->ID ? 'Ton compte' : 'Hors de tes droits'; ?></span>
													<?php endif; ?>
												</td>
											</tr>
										<?php endforeach; ?>
										</tbody>
									</table>
								</div>
								<p class="gestion-compte-total"><?php echo esc_html( count( $agents ) . ( count( $agents ) > 1 ? ' comptes' : ' compte' ) ); ?></p>
							<?php endif; ?>
						</section>

						<section class="gestion-panneau" id="panneau-compte" aria-live="polite">
							<div data-panneau-creer<?php echo $cible ? ' hidden' : ''; ?>>
								<div class="gestion-panneau__tete">
									<h2>Créer un compte</h2>
									<p>Un mot de passe provisoire s’affichera une seule fois, à transmettre au titulaire.</p>
								</div>
								<?php if ( ! $attribuables ) : ?>
									<div class="gestion-vide"><?php echo ueb_icone( 'bouclier', 30 ); ?><strong>Aucun rôle attribuable</strong><p>Crée d’abord un rôle dans « Rôles et accès ».</p></div>
								<?php else : ?>
									<form class="gestion-form" method="post" action="<?php echo esc_url( ueb_url_direction() ); ?>" data-compte-role novalidate>
										<?php ueb_champs_direction( 'direction_compte_creer' ); ?>
										<label><span>Nom complet <span class="gestion-facultatif">(facultatif)</span></span><input name="nom" autocomplete="off"></label>
										<label>Identifiant de connexion<input name="login" placeholder="prenom.nom" autocapitalize="none" spellcheck="false" autocomplete="off" required></label>
										<label><span>Adresse e-mail <span class="gestion-facultatif">(facultatif)</span></span><input name="email" type="email" autocomplete="off"></label>
										<label>Rôle
											<select name="role" required data-choix-role>
												<?php foreach ( $attribuables as $s => $a ) : ?><option value="<?php echo esc_attr( $s ); ?>" data-portee="<?php echo esc_attr( $a['portee'] ); ?>"><?php echo esc_html( $a['nom'] . ( 'un' === $a['portee'] ? '' : ' (' . lcfirst( ueb_gestion_portee_courte( $a ) ) . ')' ) ); ?></option><?php endforeach; ?>
											</select>
										</label>
										<label data-champ-etab>Établissement
											<select name="etablissement">
												<?php foreach ( $mes_etabs as $sigle ) : ?><option value="<?php echo esc_attr( $sigle ); ?>"><?php echo esc_html( $sigle . ' : ' . ueb_etablissement( $sigle )['fr'] ); ?></option><?php endforeach; ?>
											</select>
										</label>
										<div class="gestion-form__actions">
											<button class="adm-bouton adm-bouton--primaire" type="submit"><?php echo ueb_icone( 'ajout-compte', 16 ); ?>Enregistrer le compte</button>
											<button class="adm-bouton" type="reset">Nouveau compte</button>
										</div>
									</form>
								<?php endif; ?>
							</div>

							<div data-panneau-role<?php echo $cible ? '' : ' hidden'; ?>>
								<div class="gestion-panneau__tete">
									<h2 data-titre-role><?php echo $cible ? esc_html( 'Changer le rôle de ' . ( $cible->display_name ?: $cible->user_login ) ) : 'Changer de rôle'; ?></h2>
									<p>Le nouveau rôle s’applique dès la prochaine page de ce compte, sans reconnexion.</p>
								</div>
								<form class="gestion-form" method="post" action="<?php echo esc_url( ueb_url_direction() ); ?>" data-compte-role novalidate>
									<?php ueb_champs_direction( 'direction_compte_role' ); ?>
									<input type="hidden" name="agent_id" value="<?php echo $cible ? (int) $cible->ID : ''; ?>">
									<label>Rôle
										<select name="role" required data-choix-role>
											<?php foreach ( $attribuables as $s => $a ) : ?><option value="<?php echo esc_attr( $s ); ?>" data-portee="<?php echo esc_attr( $a['portee'] ); ?>" <?php selected( $cible ? ueb_role_du_compte( $cible->ID ) : '', $s ); ?>><?php echo esc_html( $a['nom'] . ( 'un' === $a['portee'] ? '' : ' (' . lcfirst( ueb_gestion_portee_courte( $a ) ) . ')' ) ); ?></option><?php endforeach; ?>
										</select>
									</label>
									<label data-champ-etab>Établissement
										<select name="etablissement">
											<?php foreach ( $mes_etabs as $sigle ) : ?><option value="<?php echo esc_attr( $sigle ); ?>" <?php selected( $cible ? strtoupper( (string) get_user_meta( $cible->ID, 'ueb_etablissement', true ) ) : '', $sigle ); ?>><?php echo esc_html( $sigle . ' : ' . ueb_etablissement( $sigle )['fr'] ); ?></option><?php endforeach; ?>
										</select>
									</label>
									<div class="gestion-form__actions">
										<button class="adm-bouton adm-bouton--primaire" type="submit"><?php echo ueb_icone( 'check', 16 ); ?>Enregistrer le rôle</button>
										<a class="adm-bouton" href="<?php echo $ici( array( 'vue' => 'personnel' ) ); ?>" data-annuler-role>Annuler</a>
									</div>
								</form>
							</div>
						</section>
					</div>

				<?php else : ?>

					<?php
					ueb_bloc_mot_de_passe( array(
						'action'  => ueb_url_direction(),
						'titre'   => 'Ton accès règle les droits des autres',
						'conseil' => array( 'bouclier', 'Tu ne donnes jamais plus que tes propres droits', ' : chaque rôle reste dans les limites du tien.' ),
					) );
					?>

				<?php endif; ?>

				<footer class="gestion-pied"><span>Université d’Ebolowa</span><span>Plateforme d’inscription</span></footer>
			</div>
		</div>
	</main>

<?php endif; ?>
<?php
ueb_page_fin( 'gestion' );
