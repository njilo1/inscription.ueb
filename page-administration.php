<?php
/**
 * Template Name: Administration UEb
 *
 * Espace de l'administration, avec barre latérale :
 *   - Tableau de bord : chiffres de toute l'université, trois graphiques et
 *     le tableau « Par établissement », dont chaque ligne s'ouvre ;
 *   - Vue d'un établissement (?etab=FS) : ses cartes, ses graphiques et ses
 *     effectifs par filière ;
 *   - Scolarités : créer, rattacher, réinitialiser, suspendre, supprimer.
 *
 * Accès : capacité « manage_options ». La connexion se fait ici ou par la
 * page de connexion WordPress.
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
		$utilisateur = ueb_connexion_gestion_bloquee( $identifiant ) ? new WP_Error( 'ueb_rate_limited' ) : wp_signon( array(
			'user_login'    => $identifiant,
			'user_password' => (string) wp_unslash( $_POST['mot_de_passe'] ?? '' ),
			'remember'      => false,
		), is_ssl() );
		if ( is_wp_error( $utilisateur ) ) {
			ueb_noter_echec_gestion( $identifiant );
			$erreur_connexion = 'Identifiant ou mot de passe incorrect.';
		} elseif ( ! user_can( $utilisateur, 'manage_options' ) ) {
			wp_logout();
			$erreur_connexion = "Ce compte n'est pas administrateur.";
		} else {
			ueb_reinitialiser_echecs_gestion( $identifiant );
			wp_safe_redirect( get_permalink() );
			exit;
		}
	}
}

$autorise = ueb_est_admin_ueb();
$annee    = ueb_annee_academique();

if ( $autorise ) {
	$vue     = sanitize_key( $_GET['vue'] ?? 'bord' );
	$focus   = strtoupper( sanitize_text_field( wp_unslash( $_GET['etab'] ?? '' ) ) );
	$focus   = ueb_etablissement( $focus ) ? $focus : '';
	$ici     = static fn( array $args = array() ) => esc_url( add_query_arg( $args, ueb_url_administration() ) );
	$chiffres       = ueb_gestion_chiffres( $annee['code'], $focus );
	$stats          = ueb_gestion_stats( $annee['code'], $focus );
	$etudiants_etab = ueb_gestion_etudiants_par_etab( $annee['code'] );
	$niveaux_etab   = ueb_gestion_niveaux_par_etab( $annee['code'] );
	$prov     = $_SESSION['ueb_mdp_agent'] ?? null;
	unset( $_SESSION['ueb_mdp_agent'] );
	if ( 'scolarites' === $vue ) {
		$agents = ueb_agents_scolarite();
	}
}

/* Coque plein écran une fois connecté ; en-tête de site conservé sur l'écran
   de connexion, qui n'a pas encore de barre latérale pour porter la marque. */
ueb_page_debut( array( 'titre' => 'Administration', 'variante' => $autorise ? 'bo' : 'gestion' ) );
?>
<main id="contenu" class="page-app gestion<?php echo $autorise ? ' page-app--bo' : ''; ?>">

	<?php if ( ! $autorise ) : ?>

		<div class="conteneur">
		<div class="espace-connexion">
			<section class="carte carte__corps" aria-labelledby="titre-connexion">
				<h1 id="titre-connexion">Administration</h1>
				<p class="page-app__sous-titre">Réservé aux administrateurs de la plateforme.</p>

				<?php if ( is_user_logged_in() ) : ?>
					<?php ueb_alerte( 'erreur', "Ce compte n'est pas administrateur." ); ?>
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
					array( 'url' => $ici(), 'libelle' => 'Tableau de bord', 'icone' => 'tampon', 'actif' => 'bord' === $vue && ! $focus ),
					array( 'url' => $ici( array( 'vue' => 'scolarites' ) ), 'libelle' => 'Scolarités', 'icone' => 'bouclier', 'actif' => 'scolarites' === $vue ),
				),
				array(
					'titre' => UEB_UNIVERSITE['fr'],
					'note'  => count( ueb_etablissements() ) . ' établissements',
				)
			);
			?>

			<div class="bo-contenu">

				<?php if ( 'scolarites' === $vue ) : ?>

					<header class="page-app__entete">
						<div>
							<h1>Comptes de scolarité</h1>
							<p class="page-app__sous-titre">Chaque agent ne voit que les quitus de son établissement.</p>
						</div>
					</header>
					<?php ueb_afficher_flash(); ?>

					<?php if ( $prov ) : ?>
						<div class="provisoire carte" role="status">
							<?php echo ueb_icone( 'cle', 26 ); ?>
							<div>
								<p>Mot de passe initial pour <b><?php echo esc_html( $prov['compte'] ); ?></b> — à communiquer à l’établissement, il ne sera plus affiché :</p>
							<p class="provisoire__mdp"><?php echo esc_html( $prov['mdp'] ); ?></p>
							<button type="button" class="btn btn--fantome btn--petit provisoire__copier" data-copier-mot-de-passe="<?php echo esc_attr( $prov['mdp'] ); ?>"><?php echo ueb_icone( 'fichier', 16 ); ?><span>Copier le mot de passe</span></button>
								<p class="champ__aide">L'agent se connecte ensuite sur l'espace scolarité.</p>
							</div>
						</div>
					<?php endif; ?>

					<section class="carte section-form" aria-labelledby="titre-creer">
						<header class="section-form__entete">
							<span class="section-form__num"><?php echo ueb_icone( 'plus', 18 ); ?></span>
							<div>
								<h2 id="titre-creer">Créer un compte</h2>
								<p>L'agent ne verra que les quitus de l'établissement choisi.</p>
							</div>
						</header>
						<div class="section-form__corps formulaire">
							<form class="formulaire administration-agent-form" method="post" action="<?php echo esc_url( ueb_url_administration() ); ?>" data-formulaire novalidate>
								<?php ueb_champ_csrf(); ?>
								<input type="hidden" name="ueb_action" value="gestion_creer_agent">
								<div class="formulaire__rangee">
									<?php
									ueb_champ( array( 'nom' => 'login', 'libelle' => 'Identifiant de connexion', 'icone' => 'utilisateur', 'attrs' => array( 'placeholder' => 'scolarite.fs', 'autocapitalize' => 'none', 'spellcheck' => 'false', 'autocomplete' => 'off' ) ) );
									ueb_champ( array( 'nom' => 'nom', 'libelle' => 'Nom de l’agent', 'icone' => 'utilisateur', 'requis' => false, 'attrs' => array( 'placeholder' => 'Nom et prénom', 'autocomplete' => 'off' ) ) );
									?>
								</div>
								<?php ueb_champ( array( 'nom' => 'mot_de_passe', 'libelle' => 'Mot de passe initial', 'type' => 'password', 'icone' => 'cadenas', 'aide' => '8 caractères minimum, avec une lettre et un chiffre.', 'attrs' => array( 'autocomplete' => 'new-password', 'minlength' => 8 ) ) ); ?>
								<div class="formulaire__rangee">
									<?php
									ueb_champ( array( 'nom' => 'email', 'libelle' => 'Adresse e-mail', 'type' => 'email', 'icone' => 'courriel', 'requis' => false, 'aide' => 'Utile pour récupérer un mot de passe oublié.', 'attrs' => array( 'autocomplete' => 'off' ) ) );
									ueb_champ( array( 'nom' => 'etablissement', 'libelle' => 'Établissement', 'type' => 'select', 'icone' => 'ecole', 'options' => array_map( static fn( $e ) => $e['fr'], ueb_etablissements() ) ) );
									?>
								</div>
								<div class="securite-form__actions">
									<button class="btn btn--primaire" type="submit"><?php echo ueb_icone( 'plus', 18 ); ?>Créer le compte</button>
								</div>
							</form>
						</div>
					</section>

					<div class="tableau-conteneur">
						<table class="tableau">
							<thead><tr><th>Agent</th><th>Établissement</th><th>Compte créé le</th><th>État</th><th><span class="sr">Actions</span></th></tr></thead>
							<tbody>
							<?php if ( ! $agents ) : ?>
								<tr><td colspan="5" class="texte-discret">Aucun compte de scolarité pour l'instant.</td></tr>
							<?php endif; ?>
							<?php foreach ( $agents as $agent ) :
								$sigle    = ueb_etab_agent( $agent->ID );
								$suspendu = ueb_agent_suspendu( $agent->ID );
								?>
								<tr>
									<td><b><?php echo esc_html( $agent->display_name ); ?></b><br><small class="texte-discret"><?php echo esc_html( $agent->user_login ); ?><?php echo $agent->user_email ? ' · ' . esc_html( $agent->user_email ) : ''; ?></small></td>
									<td>
										<form method="post" action="<?php echo esc_url( ueb_url_administration() ); ?>" class="actions-ligne">
											<?php ueb_champ_csrf(); ?>
											<input type="hidden" name="ueb_action" value="gestion_agent_modifier">
											<input type="hidden" name="agent_id" value="<?php echo (int) $agent->ID; ?>">
											<div class="champ__select">
												<select name="etablissement" aria-label="Établissement de <?php echo esc_attr( $agent->user_login ); ?>">
													<?php foreach ( ueb_etablissements() as $s => $e ) : ?>
														<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $sigle, $s ); ?>><?php echo esc_html( $s ); ?></option>
													<?php endforeach; ?>
												</select><?php echo ueb_icone( 'chevron', 18 ); ?>
											</div>
											<button class="btn btn--lien btn--petit" type="submit">Changer</button>
										</form>
									</td>
									<td class="num"><?php echo esc_html( $agent->user_registered ? mysql2date( 'd/m/Y', $agent->user_registered ) : '—' ); ?></td>
									<td><?php echo $suspendu ? '<span class="badge badge--rejete"><i></i>Suspendu</span>' : '<span class="badge badge--verifie"><i></i>Actif</span>'; ?></td>
					<td class="actions-ligne">
						<button class="btn btn--fantome btn--petit" type="button" data-ouvrir-agent-mdp="agent-mdp-<?php echo (int) $agent->ID; ?>"><?php echo ueb_icone( 'cle', 16 ); ?>Mot de passe</button>
						<dialog class="bo-agent-mdp" id="agent-mdp-<?php echo (int) $agent->ID; ?>" aria-labelledby="agent-mdp-titre-<?php echo (int) $agent->ID; ?>">
							<h2 id="agent-mdp-titre-<?php echo (int) $agent->ID; ?>">Nouveau mot de passe</h2>
							<p>Définis le mot de passe que l’agent utilisera pour se connecter à la scolarité.</p>
							<form method="post" action="<?php echo esc_url( ueb_url_administration() ); ?>">
								<?php ueb_champ_csrf(); ?>
								<input type="hidden" name="ueb_action" value="gestion_agent_mdp">
								<input type="hidden" name="agent_id" value="<?php echo (int) $agent->ID; ?>">
								<label><span>Nouveau mot de passe</span><input type="password" name="mot_de_passe" minlength="8" autocomplete="new-password" required></label>
								<label><span>Confirmer</span><input type="password" name="mot_de_passe_confirmation" minlength="8" autocomplete="new-password" required></label>
								<div class="bo-agent-mdp__actions"><button class="btn btn--lien btn--petit" type="button" data-fermer-agent-mdp>Annuler</button><button class="btn btn--primaire btn--petit" type="submit">Enregistrer</button></div>
							</form>
						</dialog>
										<form method="post" action="<?php echo esc_url( ueb_url_administration() ); ?>" data-confirmer="<?php echo $suspendu ? 'Rétablir l’accès de cet agent ?' : 'Suspendre l’accès de cet agent ? Son compte est conservé.'; ?>">
											<?php ueb_champ_csrf(); ?>
											<input type="hidden" name="ueb_action" value="gestion_agent_etat">
											<input type="hidden" name="agent_id" value="<?php echo (int) $agent->ID; ?>">
											<button class="btn btn--lien btn--petit" type="submit"><?php echo $suspendu ? 'Rétablir' : 'Suspendre'; ?></button>
										</form>
										<form method="post" action="<?php echo esc_url( ueb_url_administration() ); ?>" data-confirmer="Supprimer définitivement le compte <?php echo esc_attr( $agent->user_login ); ?> ? Les décisions qu'il a prises resteront enregistrées.">
											<?php ueb_champ_csrf(); ?>
											<input type="hidden" name="ueb_action" value="gestion_agent_supprimer">
											<input type="hidden" name="agent_id" value="<?php echo (int) $agent->ID; ?>">
											<button class="btn btn--lien btn--petit" type="submit"><?php echo ueb_icone( 'corbeille', 16 ); ?>Supprimer</button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>

				<?php elseif ( $focus ) : ?>

					<?php $e = ueb_etablissement( $focus ); ?>
					<a class="fil" href="<?php echo $ici(); ?>"><?php echo ueb_icone( 'fleche-g', 18 ); ?>Tous les établissements</a>
					<header class="page-app__entete">
						<div>
							<h1><?php echo esc_html( $e['fr'] ); ?></h1>
							<p class="page-app__sous-titre"><?php echo esc_html( $focus ); ?> · <?php echo esc_html( $e['ville'] ); ?> · année <?php echo esc_html( $annee['libelle'] ); ?></p>
						</div>
					</header>
					<?php ueb_afficher_flash(); ?>

					<?php ueb_bo_palier( $chiffres, $e['fr'] ); ?>

					<section class="carte section-form" aria-labelledby="titre-filieres">
						<header class="section-form__entete">
							<span class="section-form__num"><?php echo ueb_icone( 'ecole', 18 ); ?></span>
							<div>
								<h2 id="titre-filieres">Effectifs par filière</h2>
								<p>D'après le département saisi par l'étudiant sur son quitus.</p>
							</div>
						</header>
						<div class="section-form__corps">
							<?php $filieres = ueb_gestion_par_filiere( $annee['code'], $focus ); ?>
							<?php if ( ! $filieres ) : ?>
								<p class="texte-discret">Aucun quitus dans cet établissement pour l'instant.</p>
							<?php else : ?>
								<div class="tableau-conteneur">
									<table class="tableau">
										<thead><tr><th>Filière ou département</th><th>Étudiants</th></tr></thead>
										<tbody>
										<?php foreach ( $filieres as $f ) : ?>
											<tr><td><?php echo esc_html( $f->filiere ); ?></td><td class="num"><b><?php echo (int) $f->n; ?></b></td></tr>
										<?php endforeach; ?>
										</tbody>
									</table>
								</div>
							<?php endif; ?>
						</div>
					</section>

				<?php else : ?>

					<header class="page-app__entete">
						<div>
							<h1>Tableau de bord</h1>
							<p class="page-app__sous-titre">Tous les établissements · année <?php echo esc_html( $annee['libelle'] ); ?></p>
						</div>
					</header>
					<?php ueb_afficher_flash(); ?>

					<?php ueb_bo_palier( $chiffres, 'Tous les établissements' ); ?>

					<div class="bo-etabs bo-anim">
						<?php foreach ( ueb_etablissements() as $sigle => $etab ) : ?>
							<a class="bo-etab" href="<?php echo $ici( array( 'etab' => $sigle ) ); ?>" style="--etab: <?php echo esc_attr( $etab['couleur'] ); ?>">
								<b><?php echo (int) ( $etudiants_etab[ $sigle ] ?? 0 ); ?></b>
								<span class="bo-etab__sigle"><?php echo esc_html( $sigle ); ?></span>
								<span class="bo-etab__niveaux" aria-label="Effectifs par niveau">
									<?php foreach ( UEB_NIVEAUX_INSCRIPTION as $niveau => $libelle ) : ?>
										<span><b><?php echo esc_html( $niveau ); ?></b> : <?php echo esc_html( number_format( (int) ( $niveaux_etab[ $sigle ][ $niveau ] ?? 0 ), 0, ',', ' ' ) ); ?></span>
									<?php endforeach; ?>
								</span>
								<span class="bo-etab__note">étudiants inscrits</span>
							</a>
						<?php endforeach; ?>
					</div>

					<section class="carte section-form" aria-labelledby="titre-etabs">
						<header class="section-form__entete">
							<span class="section-form__num"><?php echo ueb_icone( 'ecole', 18 ); ?></span>
							<div>
								<h2 id="titre-etabs">Par établissement</h2>
								<p>Ouvre un établissement pour voir ses chiffres et ses effectifs par filière.</p>
							</div>
						</header>
						<div class="tableau-conteneur">
							<table class="tableau">
								<thead>
									<tr>
										<th>Établissement</th>
										<th>Étudiants</th>
										<th>Total</th>
										<?php foreach ( UEB_STATUTS_QUITUS as $s ) : ?>
											<th><?php echo esc_html( $s['libelle'] ); ?></th>
										<?php endforeach; ?>
										<th><span class="sr">Ouvrir</span></th>
									</tr>
								</thead>
								<tbody>
								<?php foreach ( ueb_etablissements() as $sigle => $etab ) :
									$lignes = $stats['etabs'][ $sigle ] ?? array();
									?>
									<tr>
										<td><a href="<?php echo $ici( array( 'etab' => $sigle ) ); ?>"><span class="pastille-etab" style="--etab: <?php echo esc_attr( $etab['couleur'] ); ?>"><?php echo esc_html( $sigle ); ?></span></a><br><small class="texte-discret"><?php echo esc_html( $etab['fr'] ); ?></small></td>
										<td class="num"><b><?php echo (int) ( $etudiants_etab[ $sigle ] ?? 0 ); ?></b></td>
										<td class="num"><?php echo (int) array_sum( $lignes ); ?></td>
										<?php foreach ( UEB_STATUTS_QUITUS as $cle => $s ) : ?>
											<td class="num"><?php echo (int) ( $lignes[ $cle ] ?? 0 ); ?></td>
										<?php endforeach; ?>
										<td><a class="btn btn--fantome btn--petit" href="<?php echo $ici( array( 'etab' => $sigle ) ); ?>">Ouvrir</a></td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					</section>

				<?php endif; ?>
			</div>
		</div>

	<?php endif; ?>

</main>
<?php
ueb_page_fin( 'gestion' );
