<?php
/**
 * Template Name: Espace Direction
 *
 * Rôles dynamiques et personnel. Accès : capacité « ueb_diriger » (les
 * administrateurs l'ont d'office). Vues par ?vue= :
 *   - roles (défaut) : les rôles en cartes (portée, permissions, comptes) ;
 *   - role           : assistant en trois étapes (nom → portée →
 *                      permissions) avec aperçu en direct ;
 *   - personnel      : créer un compte, changer son rôle, le suspendre,
 *                      lui donner un mot de passe provisoire ;
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
			$erreur_connexion = 'Identifiant ou mot de passe incorrect.';
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
	$vue         = sanitize_key( $_GET['vue'] ?? 'roles' );
	$vue         = in_array( $vue, array( 'roles', 'role', 'personnel', 'securite' ), true ) ? $vue : 'roles';
	$ici         = static fn( array $args = array() ) => esc_url( add_query_arg( $args, ueb_url_direction() ) );
	$catalogue   = ueb_permissions();
	$roles       = ueb_roles();
	$nombres     = ueb_comptes_par_role();
	$attribuables = ueb_roles_attribuables();
	$mes_etabs   = ueb_etabs_autorises();
	$totale      = ueb_portee_totale();
	$prov        = $_SESSION['ueb_mdp_direction'] ?? null;
	unset( $_SESSION['ueb_mdp_direction'] );
}

ueb_page_debut( array( 'titre' => 'Direction', 'variante' => $autorise ? 'bo' : 'gestion' ) );
?>
<main id="contenu" class="page-app gestion direction<?php echo $autorise ? ' page-app--bo' : ''; ?>">

	<?php if ( ! $autorise ) : ?>

		<div class="conteneur">
			<div class="bo-connexion">
				<aside class="bo-connexion__volet">
					<?php ueb_animation( 'embleme', ueb_props_embleme(), 'animation--embleme bo-connexion__embleme', 'Sceau de l’Université d’Ebolowa' ); ?>
					<p class="bo-connexion__marque">Université d’Ebolowa</p>
					<h1 id="titre-connexion">Direction</h1>
					<p class="bo-connexion__intro">Les rôles et le personnel de la plateforme d’inscription.</p>
					<ul class="bo-connexion__points">
						<li><?php echo ueb_icone( 'cle', 17 ); ?>Créer des rôles et choisir leurs accès</li>
						<li><?php echo ueb_icone( 'ecole', 17 ); ?>Définir sur quels établissements ils agissent</li>
						<li><?php echo ueb_icone( 'utilisateur', 17 ); ?>Créer les comptes du personnel</li>
					</ul>
				</aside>
				<section class="bo-connexion__formulaire" aria-labelledby="titre-connexion">
					<h2>Connexion</h2>
					<p class="bo-connexion__aide">Réservé aux comptes autorisés par la Direction.</p>
					<?php if ( is_user_logged_in() ) : ?><?php ueb_alerte( 'erreur', "Ce compte n'a pas accès à l'espace Direction." ); ?><?php endif; ?>
					<?php if ( $erreur_connexion ) : ?><?php ueb_alerte( 'erreur', $erreur_connexion ); ?><?php endif; ?>
					<form class="formulaire" method="post" action="<?php echo esc_url( get_permalink() ); ?>" data-formulaire novalidate>
						<?php wp_nonce_field( 'ueb_connexion_direction', 'ueb_connexion_nonce' ); ?>
						<input type="hidden" name="ueb_connexion_direction" value="1">
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
				'Direction',
				array(
					array( 'url' => $ici(), 'libelle' => 'Rôles', 'icone' => 'cle', 'actif' => in_array( $vue, array( 'roles', 'role' ), true ) ),
					array( 'url' => $ici( array( 'vue' => 'personnel' ) ), 'libelle' => 'Personnel', 'icone' => 'utilisateur', 'actif' => 'personnel' === $vue ),
					ueb_est_admin_ueb() ? array( 'url' => ueb_url_administration(), 'libelle' => 'Administration', 'icone' => 'tampon', 'actif' => false ) : null,
					ueb_est_scolarite() && ( ueb_peut( UEB_CAP_GESTION ) || ueb_peut( 'ueb_voir_paiements' ) ) ? array( 'url' => ueb_url_scolarite(), 'libelle' => 'Espace scolarité', 'icone' => 'recu', 'actif' => false ) : null,
					ueb_est_cellule() && ueb_peut( UEB_CAP_COMPTES ) ? array( 'url' => ueb_url_cellule(), 'libelle' => 'Comptes étudiants', 'icone' => 'utilisateur', 'actif' => false ) : null,
					ueb_est_admin_ueb() ? null : array( 'url' => $ici( array( 'vue' => 'securite' ) ), 'libelle' => 'Sécurité', 'icone' => 'cadenas', 'actif' => 'securite' === $vue ),
				),
				array( 'titre' => $totale ? 'Université' : implode( ', ', $mes_etabs ), 'note' => $totale ? 'Tous les établissements' : 'Ta portée' )
			);
			?>

			<div class="bo-contenu">

				<?php if ( 'roles' === $vue ) : ?>

					<header class="bo-entete">
						<div class="bo-entete__texte">
							<p class="bo-entete__contexte"><span>Direction</span><span class="bo-entete__annee"><?php echo count( $roles ); ?> rôle<?php echo count( $roles ) > 1 ? 's' : ''; ?></span></p>
							<h1>Rôles et accès</h1>
							<p class="bo-entete__sous-titre">Chaque rôle réunit une portée et des permissions. Les changements s’appliquent aux comptes dès leur page suivante, sans reconnexion.</p>
						</div>
						<a class="btn btn--primaire bo-entete__action" href="<?php echo $ici( array( 'vue' => 'role' ) ); ?>"><?php echo ueb_icone( 'plus', 18 ); ?>Nouveau rôle</a>
					</header>
					<?php ueb_afficher_flash(); ?>

					<div class="roles-grille">
						<?php foreach ( $roles as $slug => $def ) :
							$n          = (int) ( $nombres[ $slug ] ?? 0 );
							$modifiable = ueb_role_modifiable( $slug );
							$comptes    = array_slice( ueb_agents( $slug ), 0, 3 );
							?>
							<article class="carte role-carte" aria-labelledby="role-<?php echo esc_attr( $slug ); ?>">
								<header class="role-carte__tete">
									<h2 id="role-<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $def['nom'] ); ?></h2>
									<?php if ( ! empty( $def['historique'] ) ) : ?><span class="role-carte__marque" title="Rôle repris de l’ancienne configuration, modifiable comme les autres">Repris de l’existant</span><?php endif; ?>
								</header>
								<p class="role-carte__portee"><?php echo ueb_icone( 'ecole', 16 ); ?><?php echo esc_html( ueb_phrase_portee( $def ) ); ?></p>
								<ul class="role-carte__droits" aria-label="Permissions">
									<?php foreach ( $catalogue as $cap => $p ) : $a = in_array( $cap, $def['permissions'], true ); ?>
										<li class="<?php echo $a ? 'est-accorde' : 'est-refuse'; ?>"><?php echo ueb_icone( $a ? 'check' : 'croix', 14 ); ?><span><?php echo esc_html( $p['libelle'] ); ?></span><span class="sr"><?php echo $a ? ' : oui' : ' : non'; ?></span></li>
									<?php endforeach; ?>
								</ul>
								<footer class="role-carte__pied">
									<span class="role-carte__comptes">
										<?php if ( $comptes ) : ?>
											<span class="role-carte__avatars" aria-hidden="true"><?php foreach ( $comptes as $u ) : ?><span class="bo-avatar"><?php echo esc_html( ueb_initiales( $u->display_name ?: $u->user_login ) ); ?></span><?php endforeach; ?></span>
										<?php endif; ?>
										<?php echo $n ? $n . ' compte' . ( $n > 1 ? 's' : '' ) : 'Aucun compte'; ?>
									</span>
									<?php if ( $modifiable ) : ?>
										<span class="role-carte__actions">
											<a class="btn btn--fantome btn--petit" href="<?php echo $ici( array( 'vue' => 'role', 'role' => $slug ) ); ?>"><?php echo ueb_icone( 'crayon', 16 ); ?>Modifier</a>
											<form method="post" action="<?php echo esc_url( ueb_url_direction() ); ?>">
												<?php ueb_champs_direction( 'direction_role_dupliquer' ); ?>
												<input type="hidden" name="role" value="<?php echo esc_attr( $slug ); ?>">
												<button class="role-carte__icone" type="submit" title="Dupliquer" aria-label="Dupliquer le rôle <?php echo esc_attr( $def['nom'] ); ?>"><?php echo ueb_icone( 'fichier', 17 ); ?></button>
											</form>
											<button class="role-carte__icone role-carte__icone--danger" type="button" data-ouvrir-dialogue="suppr-<?php echo esc_attr( $slug ); ?>" title="Supprimer" aria-label="Supprimer le rôle <?php echo esc_attr( $def['nom'] ); ?>"><?php echo ueb_icone( 'corbeille', 17 ); ?></button>
										</span>
									<?php else : ?>
										<span class="role-carte__verrou"><?php echo ueb_icone( 'cadenas', 14 ); ?><?php echo ueb_role_du_compte() === $slug ? 'Ton rôle' : 'Au-delà de tes droits'; ?></span>
									<?php endif; ?>
								</footer>
							</article>

							<?php if ( $modifiable ) : $autres = array_diff_key( $attribuables, array( $slug => 1 ) ); ?>
								<dialog class="dialogue" id="suppr-<?php echo esc_attr( $slug ); ?>" aria-labelledby="suppr-titre-<?php echo esc_attr( $slug ); ?>">
									<form method="post" action="<?php echo esc_url( ueb_url_direction() ); ?>" class="dialogue__contenu">
										<?php ueb_champs_direction( 'direction_role_supprimer' ); ?>
										<input type="hidden" name="role" value="<?php echo esc_attr( $slug ); ?>">
										<span class="dialogue__icone" aria-hidden="true"><?php echo ueb_icone( 'alerte', 22 ); ?></span>
										<h2 id="suppr-titre-<?php echo esc_attr( $slug ); ?>">Supprimer « <?php echo esc_html( $def['nom'] ); ?> » ?</h2>
										<?php if ( $n ) : ?>
											<p><?php echo (int) $n; ?> compte<?php echo $n > 1 ? 's utilisent' : ' utilise'; ?> ce rôle. Aucun compte n’est supprimé : choisis le rôle qui <?php echo $n > 1 ? 'les' : 'le'; ?> reprendra.</p>
											<label class="dialogue__champ"><span>Nouveau rôle de ces comptes</span>
												<select name="remplacant" required>
													<option value="">Choisir…</option>
													<?php foreach ( $autres as $s => $r ) : ?><option value="<?php echo esc_attr( $s ); ?>"><?php echo esc_html( $r['nom'] . ' — ' . ueb_phrase_portee( $r ) ); ?></option><?php endforeach; ?>
												</select>
											</label>
										<?php else : ?>
											<p>Aucun compte n’utilise ce rôle. Cette action est définitive.</p>
										<?php endif; ?>
										<label class="dialogue__champ"><span>Pour confirmer, saisis <b>SUPPRIMER</b></span><input type="text" name="confirmation" autocomplete="off" required pattern="[Ss][Uu][Pp][Pp][Rr][Ii][Mm][Ee][Rr]"></label>
										<div class="dialogue__actions">
											<button class="btn btn--fantome btn--petit" type="button" data-fermer-dialogue>Annuler</button>
											<button class="btn btn--danger btn--petit" type="submit"><?php echo ueb_icone( 'corbeille', 16 ); ?>Supprimer le rôle</button>
										</div>
									</form>
								</dialog>
							<?php endif; ?>
						<?php endforeach; ?>

						<a class="role-carte role-carte--nouveau" href="<?php echo $ici( array( 'vue' => 'role' ) ); ?>">
							<span><?php echo ueb_icone( 'plus', 24 ); ?></span>
							<b>Créer un rôle</b>
							<small>Nom, portée, permissions : trois étapes.</small>
						</a>
					</div>

				<?php elseif ( 'role' === $vue ) : ?>

					<?php
					$slug       = sanitize_key( wp_unslash( $_GET['role'] ?? '' ) );
					$existant   = $slug ? ueb_role( $slug ) : null;
					$saisi      = $_SESSION['ueb_role_saisi'] ?? null;
					unset( $_SESSION['ueb_role_saisi'] );
					$interdit   = $slug && ( ! $existant || ! ueb_role_modifiable( $slug ) );
					$def        = $saisi ?: ( $existant ?: array( 'nom' => '', 'portee' => 'un', 'etablissements' => array(), 'permissions' => array() ) );
					$n          = $slug ? (int) ( $nombres[ $slug ] ?? 0 ) : 0;
					?>
					<a class="fil" href="<?php echo $ici(); ?>"><?php echo ueb_icone( 'fleche-g', 18 ); ?>Tous les rôles</a>
					<header class="bo-entete">
						<div class="bo-entete__texte">
							<h1><?php echo $existant ? 'Modifier « ' . esc_html( $existant['nom'] ) . ' »' : 'Nouveau rôle'; ?></h1>
							<p class="bo-entete__sous-titre"><?php echo $n ? $n . ' compte' . ( $n > 1 ? 's utilisent' : ' utilise' ) . ' ce rôle : tes changements s’appliquent à ' . ( $n > 1 ? 'eux' : 'lui' ) . ' immédiatement.' : 'Trois étapes : son nom, les établissements concernés, ce qu’il permet de faire.'; ?></p>
						</div>
					</header>
					<?php ueb_afficher_flash(); ?>

					<?php if ( $interdit ) : ?>
						<div class="bo-vide bo-vide--large"><span><?php echo ueb_icone( 'cadenas', 24 ); ?></span><p><b>Ce rôle ne peut pas être modifié depuis ton compte.</b> C’est le tien, ou il donne plus de droits que les tiens.</p></div>
					<?php else : ?>
					<form class="assistant" method="post" action="<?php echo esc_url( ueb_url_direction() ); ?>" data-assistant novalidate>
						<?php ueb_champs_direction( 'direction_role_enregistrer' ); ?>
						<input type="hidden" name="role" value="<?php echo esc_attr( $slug ); ?>">

						<ol class="assistant__etapes">
							<?php foreach ( array( 1 => 'Nom', 2 => 'Portée', 3 => 'Permissions' ) as $num => $libelle ) : ?>
								<li><button type="button" class="assistant__etape" data-aller-etape="<?php echo (int) $num; ?>" <?php echo 1 === $num ? 'aria-current="step"' : ''; ?>><span><?php echo (int) $num; ?></span><?php echo esc_html( $libelle ); ?></button></li>
							<?php endforeach; ?>
						</ol>

						<div class="assistant__corps">
							<div class="carte assistant__panneaux">

								<section class="assistant__panneau" data-etape="1" aria-labelledby="etape-1-titre">
									<h2 id="etape-1-titre">Comment s’appelle ce rôle ?</h2>
									<p class="assistant__aide">Le nom que verront les comptes et la Direction. Tu pourras le changer plus tard.</p>
									<?php ueb_champ( array( 'nom' => 'nom', 'libelle' => 'Nom du rôle', 'icone' => 'cle', 'valeur' => $def['nom'], 'attrs' => array( 'maxlength' => 60, 'autocomplete' => 'off', 'placeholder' => 'Par exemple : Scolarité, Coordination pédagogique…', 'data-champ-nom' => true ) ) ); ?>
									<?php if ( ! $existant ) : ?>
										<fieldset class="modeles">
											<legend>Ou pars d’un modèle, modifiable ensuite</legend>
											<div class="modeles__grille">
												<?php foreach ( ueb_modeles_roles() as $cle => $m ) :
													$possible = ueb_role_dans_mes_droits( array( 'portee' => $m['portee'], 'etablissements' => array(), 'permissions' => $m['permissions'] ) );
													?>
													<button type="button" class="modele" data-modele="<?php echo esc_attr( $cle ); ?>" <?php disabled( ! $possible ); ?>>
														<span class="modele__icone"><?php echo ueb_icone( $m['icone'], 18 ); ?></span>
														<b><?php echo esc_html( $m['titre'] ); ?></b>
														<small><?php echo esc_html( $possible ? $m['texte'] : 'Dépasse tes propres droits.' ); ?></small>
													</button>
												<?php endforeach; ?>
											</div>
										</fieldset>
									<?php endif; ?>
								</section>

								<section class="assistant__panneau" data-etape="2" aria-labelledby="etape-2-titre">
									<h2 id="etape-2-titre">Sur quels établissements agit-il ?</h2>
									<p class="assistant__aide">La portée limite tout ce que le rôle voit et fait, vérifiée par le serveur à chaque page.</p>
									<div class="portees">
										<?php
										$portees = array(
											'un'        => array( 'Un établissement', 'Choisi pour chaque compte, à sa création. Idéal pour une scolarité ou une cellule.', 'utilisateur' ),
											'plusieurs' => array( 'Plusieurs établissements', 'Les mêmes pour tous les comptes de ce rôle. L’agent passe de l’un à l’autre.', 'ecole' ),
											'tous'      => array( 'Tous les établissements', 'Y compris ceux qui seraient ajoutés plus tard à la plateforme.', 'bouclier' ),
										);
										foreach ( $portees as $cle => $p ) :
											$possible = 'tous' !== $cle || $totale;
											?>
											<label class="portee">
												<input type="radio" name="portee" value="<?php echo esc_attr( $cle ); ?>" <?php checked( $def['portee'], $cle ); ?> <?php disabled( ! $possible ); ?> data-portee>
												<span class="portee__carte">
													<span class="portee__icone"><?php echo ueb_icone( $p[2], 18 ); ?></span>
													<b><?php echo esc_html( $p[0] ); ?></b>
													<small><?php echo esc_html( $possible ? $p[1] : 'Dépasse ta propre portée.' ); ?></small>
												</span>
											</label>
										<?php endforeach; ?>
									</div>
									<fieldset class="etabs-choix" data-etabs-choix <?php echo 'plusieurs' === $def['portee'] ? '' : 'hidden'; ?>>
										<legend>Établissements concernés</legend>
										<div class="etabs-choix__grille">
											<?php foreach ( ueb_etablissements() as $sigle => $e ) : $possible = in_array( $sigle, $mes_etabs, true ); ?>
												<label class="etab-case" style="--etab: <?php echo esc_attr( $e['couleur'] ); ?>">
													<input type="checkbox" name="etablissements[]" value="<?php echo esc_attr( $sigle ); ?>" <?php checked( in_array( $sigle, (array) $def['etablissements'], true ) ); ?> <?php disabled( ! $possible ); ?>>
													<span><img src="<?php echo esc_url( ueb_logo_url( $sigle ) ); ?>" alt="" width="26" height="26"><b><?php echo esc_html( $sigle ); ?></b></span>
												</label>
											<?php endforeach; ?>
										</div>
									</fieldset>
								</section>

								<section class="assistant__panneau" data-etape="3" aria-labelledby="etape-3-titre">
									<h2 id="etape-3-titre">Que peut-il faire ?</h2>
									<p class="assistant__aide">Tu ne peux accorder que les permissions que tu as toi-même.</p>
									<?php
									$groupes = array();
									foreach ( $catalogue as $cap => $p ) {
										$groupes[ $p['groupe'] ][ $cap ] = $p;
									}
									foreach ( $groupes as $groupe => $perms ) :
										?>
										<fieldset class="droits">
											<legend><?php echo esc_html( $groupe ); ?></legend>
											<?php foreach ( $perms as $cap => $p ) : $possible = current_user_can( $cap ); ?>
												<label class="droit">
													<input type="checkbox" name="permissions[]" value="<?php echo esc_attr( $cap ); ?>" <?php checked( in_array( $cap, $def['permissions'], true ) ); ?> <?php disabled( ! $possible ); ?> data-permission <?php echo empty( $p['requiert'] ) ? '' : 'data-requiert="' . esc_attr( $p['requiert'] ) . '"'; ?>>
													<span class="droit__carte">
														<span class="droit__icone"><?php echo ueb_icone( $p['icone'], 17 ); ?></span>
														<span class="droit__texte"><b><?php echo esc_html( $p['libelle'] ); ?></b><small><?php echo esc_html( $possible ? $p['aide'] : 'Tu n’as pas cette permission : tu ne peux pas l’accorder.' ); ?></small></span>
														<span class="droit__interrupteur" aria-hidden="true"><i></i></span>
													</span>
												</label>
											<?php endforeach; ?>
										</fieldset>
									<?php endforeach; ?>
								</section>

								<footer class="assistant__nav">
									<button type="button" class="btn btn--fantome" data-etape-precedente><?php echo ueb_icone( 'fleche-g', 18 ); ?>Retour</button>
									<span class="assistant__compteur" data-compteur-etape>Étape 1 sur 3</span>
									<button type="button" class="btn btn--primaire" data-etape-suivante>Continuer<?php echo ueb_icone( 'fleche', 18 ); ?></button>
									<button type="submit" class="btn btn--primaire" data-enregistrer><?php echo ueb_icone( 'check', 18 ); ?><?php echo $existant ? 'Enregistrer les changements' : 'Créer le rôle'; ?></button>
								</footer>
							</div>

							<aside class="carte apercu" aria-labelledby="apercu-titre">
								<p class="apercu__sur">Aperçu en direct</p>
								<h2 id="apercu-titre" data-apercu-nom><?php echo esc_html( $def['nom'] ?: 'Nouveau rôle' ); ?></h2>
								<p class="apercu__portee" data-apercu-portee><?php echo ueb_icone( 'ecole', 16 ); ?><span><?php echo esc_html( ueb_phrase_portee( $def ) ); ?></span></p>
								<h3>Ce que ce rôle pourra faire</h3>
								<ul class="apercu__liste apercu__liste--oui" data-apercu-oui aria-live="polite">
									<?php foreach ( $def['permissions'] as $cap ) : ?><li><?php echo esc_html( ucfirst( $catalogue[ $cap ]['phrase'] ?? $cap ) ); ?></li><?php endforeach; ?>
								</ul>
								<p class="apercu__vide" data-apercu-vide <?php echo $def['permissions'] ? 'hidden' : ''; ?>>Rien pour l’instant : coche des permissions à l’étape 3.</p>
								<h3>Ce qu’il ne pourra pas faire</h3>
								<ul class="apercu__liste apercu__liste--non" data-apercu-non>
									<?php foreach ( $catalogue as $cap => $p ) : if ( in_array( $cap, $def['permissions'], true ) ) { continue; } ?><li><?php echo esc_html( ucfirst( $p['phrase'] ) ); ?></li><?php endforeach; ?>
								</ul>
								<p class="apercu__note"><?php echo ueb_icone( 'bouclier', 15 ); ?>Aucun rôle ne peut modifier les établissements, leurs comptes bancaires ni les administrateurs.</p>
							</aside>
						</div>
						<script type="application/json" id="donnees-roles"><?php echo wp_json_encode( array(
							'permissions' => array_map( static fn( $p ) => array( 'phrase' => ucfirst( $p['phrase'] ), 'requiert' => $p['requiert'] ?? '' ), $catalogue ),
							'modeles'     => ueb_modeles_roles(),
							'etabs'       => array_map( static fn( $e ) => $e['fr'], ueb_etablissements() ),
						), JSON_HEX_TAG | JSON_HEX_AMP ); ?></script>
					</form>
					<?php endif; ?>

				<?php elseif ( 'personnel' === $vue ) : ?>

					<?php
					/* Comptes visibles : ceux dont la portée croise la sienne (tous pour une portée totale). */
					$agents = array_values( array_filter( ueb_agents(), static fn( $u ) => $totale || array_intersect( ueb_etabs_autorises( $u->ID ), $mes_etabs ) ) );
					?>
					<header class="bo-entete">
						<div class="bo-entete__texte">
							<p class="bo-entete__contexte"><span>Direction</span><span class="bo-entete__annee"><?php echo count( $agents ); ?> compte<?php echo count( $agents ) > 1 ? 's' : ''; ?></span></p>
							<h1>Personnel</h1>
							<p class="bo-entete__sous-titre">Crée les comptes du personnel et attribue-leur un rôle. Un compte suspendu est conservé : ses décisions restent tracées.</p>
						</div>
					</header>
					<?php ueb_afficher_flash(); ?>

					<?php if ( $prov ) : ?>
						<div class="provisoire carte" role="status"><?php echo ueb_icone( 'cle', 26 ); ?><div><p>Mot de passe provisoire pour <b><?php echo esc_html( $prov['compte'] ); ?></b> — à transmettre maintenant, il ne sera plus affiché :</p><p class="provisoire__mdp"><?php echo esc_html( $prov['mdp'] ); ?></p><button type="button" class="btn btn--fantome btn--petit provisoire__copier" data-copier-mot-de-passe="<?php echo esc_attr( $prov['mdp'] ); ?>"><?php echo ueb_icone( 'fichier', 16 ); ?><span>Copier le mot de passe</span></button></div></div>
					<?php endif; ?>

					<details class="carte bo-panneau nouveau-compte" <?php echo $agents ? '' : 'open'; ?>>
						<summary><span class="bo-panneau__icone"><?php echo ueb_icone( 'plus', 20 ); ?></span><span><b>Nouveau compte</b><small>Identifiant, rôle, et l’établissement si le rôle en demande un.</small></span><?php echo ueb_icone( 'chevron', 18 ); ?></summary>
						<?php if ( ! $attribuables ) : ?>
							<p class="texte-discret">Crée d’abord un rôle que tu peux attribuer.</p>
						<?php else : ?>
							<form class="formulaire bo-formulaire" method="post" action="<?php echo esc_url( ueb_url_direction() ); ?>" data-formulaire data-compte-role novalidate>
								<?php ueb_champs_direction( 'direction_compte_creer' ); ?>
								<div class="formulaire__rangee">
									<?php ueb_champ( array( 'nom' => 'login', 'libelle' => 'Identifiant de connexion', 'icone' => 'utilisateur', 'aide' => 'Minuscules, sans espace.', 'attrs' => array( 'placeholder' => 'prenom.nom', 'autocapitalize' => 'none', 'spellcheck' => 'false', 'autocomplete' => 'off' ) ) ); ?>
									<?php ueb_champ( array( 'nom' => 'nom', 'libelle' => 'Nom affiché', 'icone' => 'utilisateur', 'requis' => false ) ); ?>
								</div>
								<div class="formulaire__rangee">
									<div class="champ">
										<label for="nc-role">Rôle</label>
										<div class="champ__select">
											<select id="nc-role" name="role" required data-choix-role>
												<?php foreach ( $attribuables as $s => $r ) : ?><option value="<?php echo esc_attr( $s ); ?>" data-portee="<?php echo esc_attr( $r['portee'] ); ?>"><?php echo esc_html( $r['nom'] . ' — ' . ueb_phrase_portee( $r ) ); ?></option><?php endforeach; ?>
											</select><?php echo ueb_icone( 'chevron', 18 ); ?>
										</div>
									</div>
									<div class="champ" data-champ-etab>
										<label for="nc-etab">Établissement</label>
										<div class="champ__select">
											<select id="nc-etab" name="etablissement">
												<?php foreach ( $mes_etabs as $sigle ) : ?><option value="<?php echo esc_attr( $sigle ); ?>"><?php echo esc_html( $sigle . ' — ' . ueb_etablissement( $sigle )['fr'] ); ?></option><?php endforeach; ?>
											</select><?php echo ueb_icone( 'chevron', 18 ); ?>
										</div>
									</div>
								</div>
								<?php ueb_champ( array( 'nom' => 'email', 'libelle' => 'Adresse e-mail', 'type' => 'email', 'icone' => 'courriel', 'requis' => false, 'attrs' => array( 'autocomplete' => 'off' ) ) ); ?>
								<div class="bo-formulaire__actions"><button class="btn btn--primaire" type="submit"><?php echo ueb_icone( 'plus', 18 ); ?>Créer le compte</button><span class="texte-discret">Un mot de passe provisoire s’affichera une seule fois.</span></div>
							</form>
						<?php endif; ?>
					</details>

					<section class="carte registre" aria-label="Comptes du personnel">
						<?php if ( ! $agents ) : ?>
							<div class="bo-vide bo-vide--large"><span><?php echo ueb_icone( 'utilisateur', 24 ); ?></span><p><b>Aucun compte pour l’instant.</b> Crée le premier avec le formulaire ci-dessus.</p></div>
						<?php else : ?>
							<div class="tableau-conteneur">
								<table class="tableau personnel-table">
									<thead><tr><th>Compte</th><th>Rôle et portée</th><th>État</th><th><span class="sr">Actions</span></th></tr></thead>
									<tbody>
									<?php foreach ( $agents as $u ) :
										$gerable  = ueb_agent_gerable( $u->ID );
										$slug_u   = ueb_role_du_compte( $u->ID );
										$suspendu = ueb_agent_suspendu( $u->ID );
										$etab_u   = strtoupper( (string) get_user_meta( $u->ID, 'ueb_etablissement', true ) );
										?>
										<tr>
											<td>
												<span class="registre__qui">
													<span class="bo-avatar" aria-hidden="true"><?php echo esc_html( ueb_initiales( $u->display_name ?: $u->user_login ) ); ?></span>
													<span><b><?php echo esc_html( $u->display_name ?: $u->user_login ); ?></b><small><?php echo esc_html( $u->user_login ); ?><?php echo $u->user_email ? ' · ' . esc_html( $u->user_email ) : ''; ?></small></span>
												</span>
											</td>
											<td>
												<?php if ( $gerable ) : ?>
													<form class="personnel-role" method="post" action="<?php echo esc_url( ueb_url_direction() ); ?>" data-compte-role>
														<?php ueb_champs_direction( 'direction_compte_role' ); ?>
														<input type="hidden" name="agent_id" value="<?php echo (int) $u->ID; ?>">
														<div class="champ__select"><select name="role" aria-label="Rôle de <?php echo esc_attr( $u->user_login ); ?>" data-choix-role>
															<?php foreach ( $attribuables as $s => $r ) : ?><option value="<?php echo esc_attr( $s ); ?>" data-portee="<?php echo esc_attr( $r['portee'] ); ?>" <?php selected( $slug_u, $s ); ?>><?php echo esc_html( $r['nom'] ); ?></option><?php endforeach; ?>
														</select><?php echo ueb_icone( 'chevron', 16 ); ?></div>
														<div class="champ__select" data-champ-etab><select name="etablissement" aria-label="Établissement de <?php echo esc_attr( $u->user_login ); ?>">
															<?php foreach ( $mes_etabs as $sigle ) : ?><option value="<?php echo esc_attr( $sigle ); ?>" <?php selected( $etab_u, $sigle ); ?>><?php echo esc_html( $sigle ); ?></option><?php endforeach; ?>
														</select><?php echo ueb_icone( 'chevron', 16 ); ?></div>
														<button class="btn btn--lien btn--petit" type="submit">Enregistrer</button>
													</form>
												<?php else : ?>
													<b><?php echo esc_html( ueb_nom_role_du_compte( $u->ID ) ); ?></b><br><small class="texte-discret"><?php echo esc_html( implode( ', ', ueb_etabs_autorises( $u->ID ) ) ); ?></small>
												<?php endif; ?>
											</td>
											<td><?php echo $suspendu ? '<span class="badge badge--rejete"><i></i>Suspendu</span>' : '<span class="badge badge--verifie"><i></i>Actif</span>'; ?></td>
											<td class="actions-ligne">
												<?php if ( $gerable ) : ?>
													<form method="post" action="<?php echo esc_url( ueb_url_direction() ); ?>" data-confirmer="Créer un nouveau mot de passe provisoire pour <?php echo esc_attr( $u->user_login ); ?> ? L’ancien ne fonctionnera plus.">
														<?php ueb_champs_direction( 'direction_compte_mdp' ); ?>
														<input type="hidden" name="agent_id" value="<?php echo (int) $u->ID; ?>">
														<button class="btn btn--fantome btn--petit" type="submit"><?php echo ueb_icone( 'cle', 16 ); ?>Mot de passe</button>
													</form>
													<form method="post" action="<?php echo esc_url( ueb_url_direction() ); ?>" data-confirmer="<?php echo $suspendu ? 'Rétablir l’accès de ce compte ?' : 'Suspendre ce compte ? Il est conservé, ses décisions restent tracées.'; ?>">
														<?php ueb_champs_direction( 'direction_compte_etat' ); ?>
														<input type="hidden" name="agent_id" value="<?php echo (int) $u->ID; ?>">
														<button class="btn btn--lien btn--petit" type="submit"><?php echo $suspendu ? 'Rétablir' : 'Suspendre'; ?></button>
													</form>
												<?php else : ?>
													<span class="role-carte__verrou"><?php echo ueb_icone( 'cadenas', 14 ); ?><?php echo get_current_user_id() === $u->ID ? 'Ton compte' : 'Hors de tes droits'; ?></span>
												<?php endif; ?>
											</td>
										</tr>
									<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						<?php endif; ?>
					</section>

				<?php else : ?>

					<header class="bo-entete"><div class="bo-entete__texte"><h1>Sécurité</h1><p class="bo-entete__sous-titre">Le mot de passe de ton accès à l’espace Direction.</p></div></header>
					<?php ueb_afficher_flash(); ?>
					<section class="carte bo-panneau direction-securite" aria-labelledby="titre-mdp-direction">
						<header class="bo-panneau__entete"><span class="bo-panneau__icone"><?php echo ueb_icone( 'cadenas', 20 ); ?></span><div><h2 id="titre-mdp-direction">Modifier mon mot de passe</h2><p>Tu seras déconnecté ensuite : reconnecte-toi avec le nouveau.</p></div></header>
						<form class="formulaire bo-formulaire" method="post" action="<?php echo esc_url( ueb_url_direction() ); ?>" data-formulaire novalidate>
							<?php ueb_champ_csrf(); ?>
							<input type="hidden" name="ueb_action" value="gestion_changer_mdp_personnel">
							<?php ueb_champ( array( 'nom' => 'mot_de_passe_actuel', 'libelle' => 'Mot de passe actuel', 'type' => 'password', 'icone' => 'cadenas', 'attrs' => array( 'autocomplete' => 'current-password' ) ) ); ?>
							<div class="formulaire__rangee">
								<?php ueb_champ( array( 'nom' => 'mot_de_passe_nouveau', 'libelle' => 'Nouveau mot de passe', 'type' => 'password', 'icone' => 'cle', 'attrs' => array( 'autocomplete' => 'new-password', 'minlength' => 8 ) ) ); ?>
								<?php ueb_champ( array( 'nom' => 'mot_de_passe_confirmation', 'libelle' => 'Confirmation', 'type' => 'password', 'icone' => 'cle', 'attrs' => array( 'autocomplete' => 'new-password', 'minlength' => 8, 'data-confirme' => 'champ-mot_de_passe_nouveau' ) ) ); ?>
							</div>
							<div class="force-mdp" data-force-mdp="champ-mot_de_passe_nouveau" data-niveau="0"><div class="force-mdp__jauge" aria-hidden="true"><i></i><i></i><i></i><i></i></div><p class="force-mdp__libelle" aria-live="polite">Solidité : <b data-force-libelle>à saisir</b></p></div>
							<div class="bo-formulaire__actions"><button class="btn btn--primaire" type="submit"><?php echo ueb_icone( 'bouclier', 18 ); ?>Changer le mot de passe</button></div>
						</form>
					</section>

				<?php endif; ?>
			</div>
		</div>

	<?php endif; ?>

</main>
<?php
ueb_page_fin( 'gestion' );
