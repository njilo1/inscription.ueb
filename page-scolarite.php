<?php
/**
 * Template Name: Espace scolarité
 *
 * Espace de la scolarité d'un établissement, avec barre latérale :
 *   - Tableau de bord : chiffres de l'établissement et trois graphiques ;
 *   - Quitus : liste filtrable, fiche et décision ;
 *   - Comptes étudiants : recherche, réinitialisation, ajout d'un compte.
 *
 * Tout est limité à l'établissement du compte connecté (méta
 * « ueb_etablissement ») ; un administrateur voit tous les établissements.
 *
 * Accès : capacité « ueb_gerer_quitus ». La connexion se fait ici ou par la
 * page de connexion WordPress.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

/* ---------- Connexion, avant tout affichage ---------- */
$erreur_connexion = '';
if ( isset( $_POST['ueb_connexion_gestion'] ) ) {
	if ( ! isset( $_POST['ueb_connexion_nonce'] ) || ! wp_verify_nonce( $_POST['ueb_connexion_nonce'], 'ueb_connexion_gestion' ) ) {
		$erreur_connexion = 'Ta session a expiré. Recommence.';
	} else {
		$identifiant = sanitize_text_field( wp_unslash( $_POST['identifiant'] ?? '' ) );
		/* L'identifiant affiché à l'administration reste la référence, mais
		   accepter l'e-mail du compte évite un échec de connexion lorsque
		   l'établissement utilise l'adresse communiquée à la création. */
		if ( is_email( $identifiant ) ) {
			$par_email = get_user_by( 'email', $identifiant );
			if ( $par_email ) {
				$identifiant = $par_email->user_login;
			}
		}
		$identifiant = sanitize_user( $identifiant, true );
		$mot_de_passe = (string) wp_unslash( $_POST['mot_de_passe'] ?? '' );
		$utilisateur = ueb_connexion_gestion_bloquee( $identifiant ) ? new WP_Error( 'ueb_rate_limited' ) : wp_signon( array(
			'user_login'    => $identifiant,
			'user_password' => $mot_de_passe,
			'remember'      => false,
		), is_ssl() );
		if ( is_wp_error( $utilisateur ) ) {
			ueb_noter_echec_gestion( $identifiant );
			$erreur_connexion = 'Identifiant ou mot de passe incorrect. Vérifie l’identifiant communiqué par l’administration.';
		} elseif ( ! ueb_est_scolarite( $utilisateur->ID ) || ! ueb_etabs_autorises( $utilisateur->ID ) || ueb_agent_suspendu( $utilisateur->ID ) ) {
			wp_logout();
			$erreur_connexion = "Ce compte n'a pas accès à l'espace scolarité.";
		} else {
			ueb_reinitialiser_echecs_gestion( $identifiant );
			/* Réaffirme l'utilisateur et le cookie avant la redirection : cela
			   évite la boucle vers l'écran de connexion avec certains caches ou
			   configurations HTTPS locales. */
			wp_set_current_user( $utilisateur->ID );
			wp_set_auth_cookie( $utilisateur->ID, false, is_ssl() );
			wp_safe_redirect( ueb_url_scolarite() );
			exit;
		}
	}
}

/* Accès par capacité et portée (inc/roles.php) : examiner les quitus ou suivre les paiements. */
$peut_quitus    = ueb_peut( UEB_CAP_GESTION );
$peut_paiements = ueb_peut( 'ueb_voir_paiements' );
$autorise       = ueb_est_scolarite() && ( $peut_quitus || $peut_paiements );
$annee    = ueb_annee_academique();

if ( $autorise ) {
	$etab_agent = ueb_etab_agent();
	$etab       = $etab_agent ? ueb_etablissement( $etab_agent ) : null;
	$vue        = sanitize_key( $_GET['vue'] ?? ( $peut_quitus ? 'bord' : 'paiements' ) );
	/* Chaque vue exige sa permission ; sinon retour à la première vue permise. */
	$permises = array_filter( array(
		'bord'      => $peut_quitus,
		'quitus'    => $peut_quitus,
		'paiements' => $peut_paiements,
		'cellule'   => ueb_peut( 'ueb_creer_agents' ),
		'securite'  => true,
		'comptes'   => true,
	) );
	if ( ! isset( $permises[ $vue ] ) ) {
		$vue = $peut_quitus ? 'bord' : 'paiements';
	}
	$fiche      = $peut_quitus && isset( $_GET['quitus'] ) ? ueb_quitus_par_id( (int) $_GET['quitus'] ) : null;
	if ( $fiche && ! ueb_peut( UEB_CAP_GESTION, $fiche->etablissement ) ) {
		$fiche = null;
	}
	if ( $fiche ) {
		$vue = 'quitus';
	}
	$ici  = static fn( array $args = array() ) => esc_url( add_query_arg( $args, ueb_url_scolarite() ) );
	$prov = $_SESSION['ueb_mdp_provisoire'] ?? null;
	unset( $_SESSION['ueb_mdp_provisoire'] );
	$prov_cellule = $_SESSION['ueb_mdp_cellule'] ?? null;
	unset( $_SESSION['ueb_mdp_cellule'] );
	/* Comptes que ce compte peut créer pour son établissement : rôles dont les
	   droits restent dans les siens (jamais un nom de rôle écrit ici). */
	$roles_creables = ueb_peut( 'ueb_creer_agents' ) ? ueb_roles_attribuables( true ) : array();
	$cellules       = $roles_creables ? array_values( array_filter( ueb_agents(), static fn( $u ) => isset( $roles_creables[ ueb_role_du_compte( $u->ID ) ] ) && ueb_etab_agent( $u->ID ) === $etab_agent ) ) : array();
	if ( 'comptes' === $vue ) {
		ueb_rediriger( ueb_url_cellule() );
	}
}

/* Coque plein écran une fois connecté ; en-tête de site conservé sur l'écran
   de connexion, qui n'a pas encore de barre latérale pour porter la marque. */
ueb_page_debut( array( 'titre' => 'Espace scolarité', 'variante' => $autorise ? 'bo' : 'gestion' ) );
?>
<main id="contenu" class="page-app gestion<?php echo $autorise ? ' page-app--bo' : ''; ?>">

	<?php if ( ! $autorise ) : ?>

		<div class="conteneur">
			<div class="bo-connexion">
				<aside class="bo-connexion__volet">
					<?php ueb_animation( 'embleme', ueb_props_embleme(), 'animation--embleme bo-connexion__embleme', 'Sceau de l’Université d’Ebolowa' ); ?>
					<p class="bo-connexion__marque">Université d’Ebolowa</p>
					<h1 id="titre-connexion">Espace scolarité</h1>
					<p class="bo-connexion__intro">Le poste de travail des scolarités d’établissement pour les inscriptions <?php echo esc_html( $annee['libelle'] ); ?>.</p>
					<ul class="bo-connexion__points">
						<li><?php echo ueb_icone( 'recu', 17 ); ?>Vérifier les reçus de paiement envoyés</li>
						<li><?php echo ueb_icone( 'tampon', 17 ); ?>Rendre les décisions après contrôle des originaux</li>
						<li><?php echo ueb_icone( 'cle', 17 ); ?>Gérer la cellule informatique de l’établissement</li>
					</ul>
				</aside>
				<section class="bo-connexion__formulaire" aria-labelledby="titre-connexion">
					<h2>Connexion</h2>
					<p class="bo-connexion__aide">Utilise l’identifiant communiqué par l’administration de la plateforme.</p>

					<?php if ( is_user_logged_in() ) : ?>
						<?php ueb_alerte( 'erreur', "Ce compte n'a pas accès à l'espace scolarité." ); ?>
					<?php endif; ?>
					<?php if ( $erreur_connexion ) : ?>
						<?php ueb_alerte( 'erreur', $erreur_connexion ); ?>
					<?php endif; ?>

					<form class="formulaire" method="post" action="<?php echo esc_url( get_permalink() ); ?>" data-formulaire novalidate>
						<?php wp_nonce_field( 'ueb_connexion_gestion', 'ueb_connexion_nonce' ); ?>
						<input type="hidden" name="ueb_connexion_gestion" value="1">
						<?php
						ueb_champ( array( 'nom' => 'identifiant', 'libelle' => 'Identifiant', 'icone' => 'utilisateur', 'attrs' => array( 'autocomplete' => 'username', 'autocapitalize' => 'none', 'spellcheck' => 'false', 'autofocus' => true ) ) );
						ueb_champ( array( 'nom' => 'mot_de_passe', 'libelle' => 'Mot de passe', 'type' => 'password', 'icone' => 'cadenas', 'attrs' => array( 'autocomplete' => 'current-password' ) ) );
						?>
						<button class="btn btn--primaire btn--large" type="submit"><?php echo ueb_icone( 'bouclier', 18 ); ?>Se connecter</button>
					</form>
					<p class="bo-connexion__oubli"><?php echo ueb_icone( 'info', 16 ); ?>Mot de passe oublié ? L’administrateur de la plateforme peut t’en donner un nouveau.</p>
				</section>
			</div>
		</div>

	<?php else : ?>

		<div class="bo">
			<?php
			ueb_bo_barre(
				'Espace scolarité',
				array(
					/* Menu construit d'après les permissions du compte. */
					$peut_quitus ? array( 'url' => $ici(), 'libelle' => 'Tableau de bord', 'icone' => 'tampon', 'actif' => 'bord' === $vue ) : null,
					$peut_quitus ? array( 'url' => $ici( array( 'vue' => 'quitus' ) ), 'libelle' => 'Quitus', 'icone' => 'recu', 'actif' => 'quitus' === $vue ) : null,
					$peut_paiements ? array( 'url' => $ici( array( 'vue' => 'paiements' ) ), 'libelle' => 'Paiements', 'icone' => 'banque', 'actif' => 'paiements' === $vue ) : null,
					$roles_creables ? array( 'url' => $ici( array( 'vue' => 'cellule' ) ), 'libelle' => 'Comptes du personnel', 'icone' => 'cle', 'actif' => 'cellule' === $vue ) : null,
					ueb_peut( UEB_CAP_COMPTES ) ? array( 'url' => ueb_url_cellule(), 'libelle' => 'Comptes étudiants', 'icone' => 'utilisateur', 'actif' => false ) : null,
					ueb_peut( UEB_CAP_DIRECTION ) ? array( 'url' => ueb_url_direction(), 'libelle' => 'Direction', 'icone' => 'bouclier', 'actif' => false ) : null,
					array( 'url' => $ici( array( 'vue' => 'securite' ) ), 'libelle' => 'Sécurité', 'icone' => 'cadenas', 'actif' => 'securite' === $vue ),
				),
				array(
					'titre' => $etab ? $etab['sigle'] : 'Tous',
					'note'  => $etab ? $etab['fr'] : 'Tous les établissements',
				)
			);
			?>

			<div class="bo-contenu">
				<?php
				$titres = array(
					'bord'     => array( 'Tableau de bord', sprintf( 'Bonjour %s. Voici où en sont les inscriptions %s.', wp_get_current_user()->display_name ?: wp_get_current_user()->user_login, $etab ? 'de ' . $etab['fr'] : 'de tous les établissements' ) ),
					'quitus'   => array( 'Quitus', 'Retrouve un dossier, examine ses reçus et rends ta décision après la vérification des originaux.' ),
					'paiements' => array( 'Suivi des paiements', 'Droits universitaires attendus et encaissés, filière par filière. Seuls les reçus vérifiés comptent comme encaissés.' ),
					'cellule'  => array( 'Comptes du personnel', 'Les comptes que tu crées pour ton établissement, avec un rôle aux droits inférieurs aux tiens.' ),
					'securite' => array( 'Sécurité', 'Le mot de passe de ton accès à l’espace scolarité.' ),
				);
				list( $titre_vue, $sous_titre_vue ) = $titres[ $vue ] ?? $titres['bord'];
				$stats_entete = ueb_gestion_stats( $annee['code'], $etab_agent );
				$a_verifier   = (int) ( $stats_entete['statuts']['recu_envoye'] ?? 0 );
				?>
				<?php if ( ! $fiche ) : ?>
					<header class="bo-entete">
						<div class="bo-entete__texte">
							<p class="bo-entete__contexte">
								<?php if ( $etab ) : ?><img src="<?php echo esc_url( ueb_logo_url( $etab['sigle'] ) ); ?>" alt="" width="22" height="22"><?php endif; ?>
								<span><?php echo esc_html( $etab ? $etab['sigle'] : 'Tous les établissements' ); ?></span>
								<span class="bo-entete__annee">Année <?php echo esc_html( $annee['libelle'] ); ?></span>
							</p>
							<h1><?php echo esc_html( $titre_vue ); ?></h1>
							<p class="bo-entete__sous-titre"><?php echo esc_html( $sous_titre_vue ); ?></p>
						</div>
						<?php if ( 'bord' === $vue && $a_verifier ) : ?>
							<a class="btn btn--primaire bo-entete__action" href="<?php echo $ici( array( 'vue' => 'quitus', 'statut' => 'recu_envoye' ) ); ?>"><?php echo ueb_icone( 'recu', 18 ); ?>Reçus à vérifier <span class="bo-entete__pastille"><?php echo (int) $a_verifier; ?></span></a>
						<?php endif; ?>
					</header>
				<?php endif; ?>
				<?php ueb_afficher_flash(); ?>

				<?php if ( 'securite' === $vue ) : ?>

					<div class="bo-deux-colonnes">
						<section class="carte bo-panneau" aria-labelledby="titre-securite-personnel">
							<header class="bo-panneau__entete">
								<span class="bo-panneau__icone"><?php echo ueb_icone( 'cadenas', 20 ); ?></span>
								<div><h2 id="titre-securite-personnel">Modifier mon mot de passe</h2><p>Remplace le mot de passe initial communiqué par l’administration.</p></div>
							</header>
							<form class="formulaire bo-formulaire" method="post" action="<?php echo esc_url( ueb_url_scolarite() ); ?>" data-formulaire novalidate>
								<?php ueb_champ_csrf(); ?>
								<input type="hidden" name="ueb_action" value="gestion_changer_mdp_personnel">
								<?php ueb_champ( array( 'nom' => 'mot_de_passe_actuel', 'libelle' => 'Mot de passe actuel', 'type' => 'password', 'icone' => 'cadenas', 'attrs' => array( 'autocomplete' => 'current-password' ) ) ); ?>
								<div class="formulaire__rangee">
									<?php ueb_champ( array( 'nom' => 'mot_de_passe_nouveau', 'libelle' => 'Nouveau mot de passe', 'type' => 'password', 'icone' => 'cle', 'attrs' => array( 'autocomplete' => 'new-password', 'minlength' => 8 ) ) ); ?>
									<?php ueb_champ( array( 'nom' => 'mot_de_passe_confirmation', 'libelle' => 'Confirmation', 'type' => 'password', 'icone' => 'cle', 'attrs' => array( 'autocomplete' => 'new-password', 'minlength' => 8, 'data-confirme' => 'champ-mot_de_passe_nouveau' ) ) ); ?>
								</div>
								<div class="force-mdp" data-force-mdp="champ-mot_de_passe_nouveau" data-niveau="0">
									<div class="force-mdp__jauge" aria-hidden="true"><i></i><i></i><i></i><i></i></div>
									<p class="force-mdp__libelle" aria-live="polite">Solidité : <b data-force-libelle>à saisir</b></p>
								</div>
								<div class="bo-formulaire__actions">
									<button class="btn btn--primaire" type="submit"><?php echo ueb_icone( 'bouclier', 18 ); ?>Changer le mot de passe</button>
								</div>
							</form>
						</section>
						<aside class="carte reflexes" aria-labelledby="titre-reflexes-agent">
							<span class="reflexes__icone" aria-hidden="true"><?php echo ueb_icone( 'bouclier', 22 ); ?></span>
							<h2 id="titre-reflexes-agent">Ton accès engage l’établissement</h2>
							<ul class="reflexes__liste">
								<li><?php echo ueb_icone( 'tampon', 17 ); ?><span><b>Chaque décision est enregistrée à ton nom</b> : paiement vérifié ou reçu renvoyé.</span></li>
								<li><?php echo ueb_icone( 'cadenas', 17 ); ?><span><b>Ne partage pas ton accès</b>, même avec un collègue de la scolarité.</span></li>
								<li><?php echo ueb_icone( 'sortie', 17 ); ?><span><b>Déconnecte-toi</b> en quittant ton poste, surtout sur un ordinateur partagé.</span></li>
							</ul>
							<div class="reflexes__oubli"><p><b>Mot de passe oublié ?</b> L’administrateur de la plateforme peut t’en attribuer un nouveau.</p></div>
						</aside>
					</div>

				<?php elseif ( 'paiements' === $vue ) : ?>

					<?php
					ueb_suivi_paiements_vue( ueb_suivi_paiements( $annee['code'], $etab_agent ), array(
						'perimetre' => $etab ? $etab['sigle'] : 'Université',
						'lignes'    => $etab ? 'filieres' : 'etabs',
					) );
					?>

				<?php elseif ( 'bord' === $vue ) : ?>

					<?php
					$file = ueb_gestion_liste_quitus( array( 'annee' => $annee['code'], 'etab' => $etab_agent, 'statut' => 'recu_envoye' ), 100 )['lignes'];
					usort( $file, static fn( $a, $b ) => strcmp( $a->date_modification, $b->date_modification ) );
					/* Données du tableau de bord, calculées une seule fois. */
					$suivi         = ueb_suivi_paiements( $annee['code'], $etab_agent );
					$c             = ueb_gestion_chiffres( $annee['code'], $etab_agent );
					$activite      = ueb_gestion_activite( $annee['code'], $etab_agent );
					$url_paiements = $peut_paiements ? $ici( array( 'vue' => 'paiements' ) ) : '';
					$maintenant    = current_time( 'timestamp' );
					?>
					<div class="bord">
						<?php
						ueb_bord_recouvrement( $suivi, $url_paiements, $etab ? $etab['sigle'] : 'Université' );
						ueb_bord_parcours( $c, $ici );
						?>

						<div class="bord__rangee bord__rangee--2">
							<section class="carte file-verif" aria-labelledby="titre-file">
								<header class="file-verif__entete">
									<div>
										<h2 id="titre-file">À vérifier</h2>
										<p>Reçus envoyés par les étudiants, du plus ancien au plus récent. Compare-les aux originaux avant de décider.</p>
									</div>
								</header>
								<?php if ( ! $file ) : ?>
									<div class="file-verif__vide"><span><?php echo ueb_icone( 'check', 22 ); ?></span><p><b>Aucun reçu en attente.</b> Tout est à jour pour le moment.</p></div>
								<?php else : ?>
									<ul class="file-verif__liste">
										<?php foreach ( array_slice( $file, 0, 5 ) as $rang => $q ) : ?>
											<li style="--i: <?php echo (int) $rang; ?>">
												<a class="file-verif__ligne" href="<?php echo $ici( array( 'quitus' => $q->id ) ); ?>">
													<span class="bo-avatar" aria-hidden="true"><?php echo esc_html( ueb_initiales( $q->prenom, $q->nom ) ); ?></span>
													<span class="file-verif__qui"><b><?php echo esc_html( trim( $q->nom . ' ' . $q->prenom ) ); ?></b><small><?php echo esc_html( $q->numero ); ?> · <?php echo esc_html( ueb_detail_quitus( $q ) ); ?></small></span>
													<span class="file-verif__meta">
														<span class="file-verif__montant"><?php echo esc_html( ueb_formater_montant( $q->montant ) ); ?> <small>FCFA</small></span>
														<span class="file-verif__attente"><?php echo ueb_icone( 'horloge', 15 ); ?><?php echo esc_html( 'il y a ' . human_time_diff( strtotime( $q->date_modification ), $maintenant ) ); ?></span>
													</span>
													<span class="file-verif__aller" aria-hidden="true"><?php echo ueb_icone( 'fleche', 18 ); ?></span>
												</a>
											</li>
										<?php endforeach; ?>
									</ul>
									<footer class="file-verif__pied">
										<p><?php echo ueb_icone( 'horloge', 16 ); ?>Le plus ancien attend depuis <?php echo esc_html( human_time_diff( strtotime( $file[0]->date_modification ), $maintenant ) ); ?>.</p>
										<a class="bo-lien" href="<?php echo $ici( array( 'vue' => 'quitus', 'statut' => 'recu_envoye' ) ); ?>">Tout voir (<?php echo count( $file ); ?>)<?php echo ueb_icone( 'fleche', 16 ); ?></a>
									</footer>
								<?php endif; ?>
							</section>
							<?php ueb_graphe_courbes( 'Progression de l’année', 'Quitus cumulés, jour après jour', $activite ); ?>
						</div>

						<div class="bord__rangee bord__rangee--3">
							<?php
							ueb_graphe_anneau( 'Répartition par sexe', 'Étudiants ayant au moins un quitus', array(
								'Masculin' => array( 'valeur' => $c['sexe']['M'], 'couleur' => 'var(--viz-id-1)' ),
								'Féminin'  => array( 'valeur' => $c['sexe']['F'], 'couleur' => 'var(--viz-id-2)' ),
							) );
							ueb_graphe_barres( 'Étudiants par tranche réglée', 'Paiements vérifiés par la scolarité', array(
								'Tranche 1' => array( 'valeur' => $c['tranches']['tranche1'], 'couleur' => 'var(--viz-pas-1)' ),
								'Tranche 2' => array( 'valeur' => $c['tranches']['tranche2'], 'couleur' => 'var(--viz-pas-2)' ),
								'Totalité'  => array( 'valeur' => $c['tranches']['totalite'], 'couleur' => 'var(--viz-pas-3)' ),
							) );
							ueb_graphe_filieres( 'Recouvrement par filière', 'Part encaissée des droits attendus', $suivi['filieres'], $url_paiements );
							?>
						</div>
					</div>

				<?php elseif ( 'cellule' === $vue ) : ?>

					<?php if ( $prov_cellule ) : ?>
						<div class="provisoire carte" role="status"><?php echo ueb_icone( 'cle', 26 ); ?><div><p>Mot de passe provisoire pour <b><?php echo esc_html( $prov_cellule['compte'] ); ?></b> :</p><p class="provisoire__mdp"><?php echo esc_html( $prov_cellule['mdp'] ); ?></p><button type="button" class="btn btn--fantome btn--petit provisoire__copier" data-copier-mot-de-passe="<?php echo esc_attr( $prov_cellule['mdp'] ); ?>"><?php echo ueb_icone( 'fichier', 16 ); ?><span>Copier le mot de passe</span></button><p class="champ__aide">Communique-le à la cellule informatique de ton établissement.</p></div></div>
					<?php endif; ?>
					<div class="bo-deux-colonnes bo-deux-colonnes--egal">
						<section class="carte bo-panneau" aria-labelledby="titre-cellule">
							<header class="bo-panneau__entete">
								<span class="bo-panneau__icone"><?php echo ueb_icone( 'plus', 20 ); ?></span>
								<div><h2 id="titre-cellule">Nouveau compte</h2><p>Rattaché à <?php echo esc_html( $etab['fr'] ?? 'ton établissement' ); ?>, avec l’un des rôles que tu peux attribuer.</p></div>
							</header>
							<form class="formulaire bo-formulaire" method="post" action="<?php echo esc_url( ueb_url_scolarite() ); ?>" data-formulaire novalidate>
								<?php ueb_champ_csrf(); ?>
								<input type="hidden" name="ueb_action" value="gestion_creer_cellule">
								<?php ueb_champ( array( 'nom' => 'role', 'libelle' => 'Rôle', 'type' => 'select', 'icone' => 'cle', 'options' => array_map( static fn( $r ) => $r['nom'], $roles_creables ), 'valeur' => ueb_role_par_defaut( UEB_CAP_COMPTES ) ) ); ?>
								<?php ueb_champ( array( 'nom' => 'login', 'libelle' => 'Identifiant de connexion', 'icone' => 'utilisateur', 'aide' => 'Minuscules, sans espace : par exemple cellule.' . strtolower( $etab['sigle'] ?? 'fs' ) . '.', 'attrs' => array( 'placeholder' => 'cellule.' . strtolower( $etab['sigle'] ?? 'fs' ), 'autocapitalize' => 'none', 'spellcheck' => 'false', 'autocomplete' => 'off' ) ) ); ?>
								<?php ueb_champ( array( 'nom' => 'nom', 'libelle' => 'Nom du responsable', 'icone' => 'utilisateur', 'requis' => false ) ); ?>
								<?php ueb_champ( array( 'nom' => 'email', 'libelle' => 'Adresse e-mail', 'type' => 'email', 'icone' => 'courriel', 'requis' => false, 'attrs' => array( 'autocomplete' => 'off' ) ) ); ?>
								<div class="bo-formulaire__actions">
									<button class="btn btn--primaire" type="submit"><?php echo ueb_icone( 'plus', 18 ); ?>Créer le compte</button>
								</div>
							</form>
						</section>
						<section class="carte bo-panneau" aria-labelledby="titre-cellules">
							<header class="bo-panneau__entete">
								<span class="bo-panneau__icone"><?php echo ueb_icone( 'cle', 20 ); ?></span>
								<div><h2 id="titre-cellules">Comptes rattachés <span class="bo-compte-nb"><?php echo count( $cellules ); ?></span></h2><p>Limités à ton établissement. Un mot de passe provisoire s’affiche une seule fois à la création.</p></div>
							</header>
							<?php if ( ! $cellules ) : ?>
								<div class="bo-vide"><span><?php echo ueb_icone( 'utilisateur', 22 ); ?></span><p>Aucun compte pour l’instant. Crée le premier avec le formulaire.</p></div>
							<?php else : ?>
								<ul class="bo-personnes">
									<?php foreach ( $cellules as $cellule ) : $suspendu = ueb_agent_suspendu( $cellule->ID ); ?>
										<li>
											<span class="bo-avatar" aria-hidden="true"><?php echo esc_html( ueb_initiales( $cellule->display_name ?: $cellule->user_login, '' ) ); ?></span>
											<span class="bo-personnes__qui"><b><?php echo esc_html( $cellule->display_name ?: $cellule->user_login ); ?></b><small><?php echo esc_html( $cellule->user_login ); ?><?php echo $cellule->user_email ? ' · ' . esc_html( $cellule->user_email ) : ''; ?></small></span>
											<?php echo $suspendu ? '<span class="badge badge--rejete"><i></i>Suspendu</span>' : '<span class="badge badge--verifie"><i></i>Actif</span>'; ?>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</section>
					</div>

				<?php elseif ( 'comptes' === $vue ) : ?>

					<?php
					$filtres_e = array(
						'q'        => sanitize_text_field( wp_unslash( $_GET['qc'] ?? '' ) ),
						'paiement' => sanitize_key( $_GET['paiement'] ?? '' ),
					);
					$etudiants = ueb_gestion_chercher_etudiants( $filtres_e, $etab_agent );
					?>

					<?php if ( $prov ) : ?>
						<div class="provisoire carte" role="status">
							<?php echo ueb_icone( 'cle', 26 ); ?>
							<div>
								<p>Mot de passe provisoire pour <b><?php echo esc_html( $prov['compte'] ); ?></b> — à communiquer maintenant, il ne sera plus affiché :</p>
								<p class="provisoire__mdp"><?php echo esc_html( $prov['mdp'] ); ?></p>
								<button type="button" class="btn btn--fantome btn--petit provisoire__copier" data-copier-mot-de-passe="<?php echo esc_attr( $prov['mdp'] ); ?>"><?php echo ueb_icone( 'fichier', 16 ); ?><span>Copier le mot de passe</span></button>
								<p class="champ__aide">L'étudiant choisira son propre mot de passe à sa première connexion.</p>
							</div>
						</div>
					<?php endif; ?>

					<form class="filtres carte" method="get" action="<?php echo esc_url( ueb_url_scolarite() ); ?>" role="search">
						<input type="hidden" name="vue" value="comptes">
						<div class="champ">
							<label for="e-q">Rechercher un étudiant</label>
							<input id="e-q" type="search" name="qc" value="<?php echo esc_attr( $filtres_e['q'] ); ?>" placeholder="Nom, prénom, matricule, n° de dossier ou téléphone">
						</div>
						<div class="champ">
							<label for="e-paiement">Paiement</label>
							<div class="champ__select">
								<select id="e-paiement" name="paiement">
									<option value="">Tous</option>
									<option value="paye" <?php selected( $filtres_e['paiement'], 'paye' ); ?>>A payé (reçu vérifié)</option>
									<option value="non_paye" <?php selected( $filtres_e['paiement'], 'non_paye' ); ?>>N'a pas encore payé</option>
								</select><?php echo ueb_icone( 'chevron', 18 ); ?>
							</div>
						</div>
						<button class="btn btn--primaire" type="submit"><?php echo ueb_icone( 'loupe', 18 ); ?>Rechercher</button>
					</form>

					<div class="tableau-conteneur">
						<table class="tableau">
							<thead><tr><th>Étudiant</th><th>Identifiants</th><th>Téléphone</th><th>Quitus</th><th>État</th><th><span class="sr">Actions</span></th></tr></thead>
							<tbody>
							<?php if ( ! $etudiants ) : ?>
								<tr><td colspan="6" class="texte-discret">Aucun étudiant ne correspond à cette recherche.</td></tr>
							<?php endif; ?>
							<?php foreach ( $etudiants as $e ) : ?>
								<tr>
									<td><b><?php echo esc_html( trim( $e->nom . ' ' . $e->prenom ) ?: '—' ); ?></b></td>
									<td><?php echo esc_html( $e->matricule ?: '—' ); ?><br><small class="texte-discret"><?php echo esc_html( $e->numero_dossier ?: '' ); ?></small></td>
									<td class="num"><?php echo esc_html( $e->telephone ? ueb_formater_telephone( $e->telephone ) : '—' ); ?></td>
									<td class="num"><?php echo (int) $e->quitus; ?><br><small class="texte-discret"><?php echo (int) $e->verifies; ?> vérifié(s)</small></td>
									<td>
										<?php echo 'actif' === $e->statut ? '<span class="badge badge--verifie"><i></i>Actif</span>' : '<span class="badge badge--rejete"><i></i>Suspendu</span>'; ?>
										<?php echo $e->doit_changer_mdp ? ' <span class="badge badge--genere"><i></i>Mdp provisoire</span>' : ''; ?>
									</td>
									<td class="actions-ligne">
										<form method="post" action="<?php echo esc_url( ueb_url_scolarite() ); ?>" data-confirmer="Réinitialiser le mot de passe de <?php echo esc_attr( ueb_identifiant_compte( $e ) ); ?> ? As-tu vérifié son identité ?">
											<?php ueb_champ_csrf(); ?>
											<input type="hidden" name="ueb_action" value="gestion_reinit_mdp">
											<input type="hidden" name="compte_id" value="<?php echo (int) $e->id; ?>">
											<input type="hidden" name="q" value="<?php echo esc_attr( $filtres_e['q'] ); ?>">
											<button class="btn btn--fantome btn--petit" type="submit"><?php echo ueb_icone( 'cle', 16 ); ?>Mot de passe</button>
										</form>
										<form method="post" action="<?php echo esc_url( ueb_url_scolarite() ); ?>" data-confirmer="<?php echo 'actif' === $e->statut ? 'Suspendre ce compte ? L’étudiant sera déconnecté.' : 'Réactiver ce compte ?'; ?>">
											<?php ueb_champ_csrf(); ?>
											<input type="hidden" name="ueb_action" value="gestion_bloquer">
											<input type="hidden" name="compte_id" value="<?php echo (int) $e->id; ?>">
											<input type="hidden" name="q" value="<?php echo esc_attr( $filtres_e['q'] ); ?>">
											<button class="btn btn--lien btn--petit" type="submit"><?php echo 'actif' === $e->statut ? 'Suspendre' : 'Réactiver'; ?></button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>

					<section class="carte section-form" aria-labelledby="titre-ajout">
						<header class="section-form__entete">
							<span class="section-form__num"><?php echo ueb_icone( 'plus', 18 ); ?></span>
							<div>
								<h2 id="titre-ajout">Ajouter un étudiant</h2>
								<p>Pour un étudiant qui ne peut pas créer son compte lui-même, faute de téléphone par exemple.</p>
							</div>
						</header>
						<div class="section-form__corps formulaire">
							<form class="formulaire" method="post" action="<?php echo esc_url( ueb_url_scolarite() ); ?>" data-formulaire novalidate>
								<?php ueb_champ_csrf(); ?>
								<input type="hidden" name="ueb_action" value="gestion_creer_etudiant">
								<div class="formulaire__rangee">
									<?php
									ueb_champ( array(
										'nom'     => 'identifiant',
										'libelle' => 'Matricule ou numéro de dossier',
										'icone'   => 'utilisateur',
										'attrs'   => array( 'placeholder' => '24I0017FS ou UEB-2026-000123', 'autocapitalize' => 'characters', 'spellcheck' => 'false', 'autocomplete' => 'off', 'data-identifiant' => true ),
									) );
									ueb_champ( array(
										'nom'     => 'telephone',
										'libelle' => 'Téléphone',
										'type'    => 'tel',
										'icone'   => 'telephone',
										'requis'  => false,
										'aide'    => 'Laisse vide si l’étudiant n’a pas de numéro.',
										'attrs'   => array( 'inputmode' => 'tel', 'maxlength' => 17, 'placeholder' => '6XX XX XX XX', 'data-telephone' => true, 'autocomplete' => 'off' ),
									) );
									?>
								</div>
								<div class="securite-form__actions">
									<button class="btn btn--primaire" type="submit"><?php echo ueb_icone( 'plus', 18 ); ?>Créer le compte</button>
								</div>
							</form>
						</div>
					</section>

				<?php elseif ( $fiche ) : ?>

					<?php
					$etab_fiche = ueb_etablissement( $fiche->etablissement );
					$compte     = ueb_compte_par_id( $fiche->compte_id );
					$recus      = ueb_recus_du_quitus( $fiche->id );
					$decideur   = $fiche->verifie_par ? get_userdata( $fiche->verifie_par ) : null;
					/* Historique du dossier, du plus ancien au plus récent. */
					$historique = array( array( 'date' => $fiche->date_creation, 'icone' => 'fichier', 'texte' => 'Quitus généré par l’étudiant' ) );
					foreach ( $recus as $r ) {
						$historique[] = array( 'date' => $r->date_envoi, 'icone' => 'envoyer', 'texte' => 'Reçu envoyé', 'detail' => ueb_libelle_objet_recu( $r, $fiche->type ) );
					}
					if ( in_array( $fiche->statut, array( 'verifie', 'rejete' ), true ) && $fiche->date_verification ) {
						$historique[] = array(
							'date'   => $fiche->date_verification,
							'icone'  => 'verifie' === $fiche->statut ? 'check' : 'alerte',
							'texte'  => ( 'verifie' === $fiche->statut ? 'Paiement vérifié' : 'Renvoyé à l’étudiant' ) . ( $decideur ? ' par ' . $decideur->display_name : '' ),
							'classe' => $fiche->statut,
						);
					}
					usort( $historique, static fn( $a, $b ) => strcmp( $a['date'], $b['date'] ) );

					/* Suivi du dossier en quatre étapes, comme la composition « statut »
					   (Remotion) jouée une fois dans l'en-tête. Le même suivi en HTML sert
					   de repli (avant le montage du lecteur, sans JavaScript) et prend le
					   relais quand l'en-tête est trop étroit pour lire la composition. */
					$couleur_etab  = $etab_fiche['couleur'] ?? '#13351a';
					$icones_statut = array( 'genere' => 'horloge', 'recu_envoye' => 'envoyer', 'verifie' => 'check', 'rejete' => 'alerte' );
					$type_fiche    = 'medicaux' === ( $fiche->type ?? 'droits' ) ? 'medicaux' : 'droits';
					$rejete        = 'rejete' === $fiche->statut;
					$etape         = array( 'genere' => 1, 'recu_envoye' => 3, 'rejete' => 3, 'verifie' => 4 )[ $fiche->statut ] ?? 1;
					$dernier_recu  = $recus ? end( $recus ) : null;
					$suivi_note    = array(
						'genere'      => 'En attente du paiement de l’étudiant et de son reçu.',
						'recu_envoye' => sprintf( 'Le reçu attend ta vérification depuis %s.', human_time_diff( strtotime( $dernier_recu->date_envoi ?? $fiche->date_modification ), current_time( 'timestamp' ) ) ),
						'rejete'      => 'Reçu renvoyé : l’étudiant doit en envoyer un nouveau.',
						'verifie'     => 'Paiement vérifié : le dossier est complet.',
					)[ $fiche->statut ] ?? '';
					$suivi_etat = array(
						'genere'      => 'quitus généré, en attente du paiement',
						'recu_envoye' => 'reçu envoyé, en attente de vérification',
						'rejete'      => 'reçu à corriger par l’étudiant',
						'verifie'     => 'paiement vérifié',
					)[ $fiche->statut ] ?? '';
					$suivi_html = static function ( $variante ) use ( $etape, $rejete, $couleur_etab ) {
						$html = sprintf( '<ol class="suivi-etapes suivi-etapes--%1$s%2$s" style="--couleur: %3$s; --rempli: %4$s">', esc_attr( $variante ), $rejete ? ' est-rejete' : '', esc_attr( $couleur_etab ), esc_attr( round( ( $etape - 1 ) / 3, 4 ) ) );
						foreach ( array( 'Quitus généré', 'Tamponné et payé', 'Reçu envoyé', 'Vérifié' ) as $i => $libelle ) {
							$n        = $i + 1;
							$atteinte = $n <= $etape;
							$courante = $n === $etape;
							$marque   = $courante && $rejete ? '!' : ( $atteinte && ( ! $courante || 4 === $etape ) ? ueb_icone( 'check', 16 ) : (string) $n );
							$etat     = $courante ? ( $rejete ? 'à corriger' : ( 4 === $etape ? 'terminée' : 'en cours' ) ) : ( $atteinte ? 'franchie' : 'à venir' );
							$html    .= sprintf(
								'<li class="%1$s" style="--n: %2$d"><span class="suivi-etapes__rond" aria-hidden="true">%3$s</span><span class="suivi-etapes__libelle">%4$s<span class="sr"> : étape %5$s</span></span></li>',
								esc_attr( ( $atteinte ? 'est-atteinte' : 'est-a-venir' ) . ( $courante ? ' est-courante' : '' ) . ( $courante && $rejete ? ' est-rejetee' : '' ) ),
								$i,
								$marque,
								esc_html( $courante && $rejete ? 'À corriger' : $libelle ),
								esc_html( $etat )
							);
						}
						return $html . '</ol>';
					};
					?>
					<a class="fil" href="<?php echo $ici( array( 'vue' => 'quitus' ) ); ?>"><?php echo ueb_icone( 'fleche-g', 18 ); ?>Tous les quitus</a>

					<header class="dossier-entete carte" style="--etab: <?php echo esc_attr( $couleur_etab ); ?>">
						<div class="dossier-entete__haut">
							<p class="dossier-entete__etab"><img src="<?php echo esc_url( ueb_logo_url( $fiche->etablissement ) ); ?>" alt="" width="28" height="28"><b><?php echo esc_html( $etab_fiche['sigle'] ?? $fiche->etablissement ); ?></b><span><?php echo esc_html( $etab_fiche['fr'] ?? '' ); ?></span></p>
							<div class="dossier-entete__actions">
								<span class="badge badge--<?php echo esc_attr( $fiche->statut ); ?> dossier-entete__statut"><?php echo ueb_icone( $icones_statut[ $fiche->statut ] ?? 'info', 15 ); ?><?php echo esc_html( UEB_STATUTS_QUITUS[ $fiche->statut ]['libelle'] ?? $fiche->statut ); ?></span>
								<a class="btn btn--fantome btn--petit" href="<?php echo $ici( array( 'quitus' => $fiche->id, 'pdf' => 1 ) ); ?>" target="_blank" rel="noopener"><?php echo ueb_icone( 'telecharger', 17 ); ?>PDF du quitus</a>
							</div>
						</div>
						<div class="dossier-entete__qui">
							<span class="bo-avatar bo-avatar--grand dossier-entete__avatar" aria-hidden="true"><?php echo esc_html( ueb_initiales( $fiche->prenom, $fiche->nom ) ); ?></span>
							<div class="dossier-entete__nom">
								<h1><?php echo esc_html( $fiche->nom . ' ' . $fiche->prenom ); ?></h1>
								<ul class="dossier-entete__ident">
									<li>Quitus <b><?php echo esc_html( $fiche->numero ); ?></b></li>
									<li><?php echo 'matricule' === $fiche->type_identifiant ? 'Matricule' : 'N° de dossier'; ?> <b><?php echo esc_html( $fiche->identifiant ); ?></b></li>
									<?php if ( $compte && $compte->telephone ) : ?><li>Téléphone <b><?php echo esc_html( ueb_formater_telephone( $compte->telephone ) ); ?></b></li><?php endif; ?>
								</ul>
							</div>
						</div>
						<section class="suivi-dossier" aria-labelledby="titre-suivi">
							<div class="suivi-dossier__tete">
								<h2 id="titre-suivi">Suivi du dossier</h2>
								<?php if ( $suivi_note ) : ?><p><?php echo esc_html( $suivi_note ); ?></p><?php endif; ?>
							</div>
							<?php
							ueb_animation( 'statut', array( 'etape' => $etape, 'couleur' => $couleur_etab, 'rejete' => $rejete ), 'animation--statut suivi-dossier__anime', sprintf( 'Suivi du dossier, étape %1$d sur 4 : %2$s', $etape, $suivi_etat ), $suivi_html( 'scene' ) );
							echo $suivi_html( 'compact' ); // phpcs:ignore -- HTML échappé à la construction
							?>
						</section>
						<dl class="dossier-entete__faits">
							<div><dt>Montant</dt><dd class="dossier-entete__montant"><?php echo esc_html( ueb_formater_montant( $fiche->montant ) ); ?> <small>FCFA</small></dd></div>
							<div><dt>Paiement</dt><dd><?php echo esc_html( ueb_libelle_type_quitus( $type_fiche ) ); ?><small class="dossier-entete__suite"><?php echo esc_html( 'medicaux' === $type_fiche ? 'Paiement unique' : ueb_libelle_tranche( $fiche->tranche ) ); ?></small></dd></div>
							<div><dt>Reçus envoyés</dt><dd><?php echo count( $recus ); ?></dd></div>
							<div><dt>Généré le</dt><dd><?php echo esc_html( mysql2date( 'j F Y', $fiche->date_creation ) ); ?></dd></div>
						</dl>
					</header>

					<div class="dossier-grille">
						<div class="dossier-colonne">
							<section class="carte bo-panneau" aria-labelledby="titre-recus-fiche">
								<header class="bo-panneau__entete">
									<span class="bo-panneau__icone"><?php echo ueb_icone( 'recu', 20 ); ?></span>
									<div><h2 id="titre-recus-fiche">Reçus envoyés <span class="bo-compte-nb"><?php echo count( $recus ); ?></span></h2><p>Ouvre chaque reçu et compare-le à l’original présenté par l’étudiant.</p></div>
								</header>
								<?php if ( ! $recus ) : ?>
									<div class="bo-vide"><span><?php echo ueb_icone( 'recu', 22 ); ?></span><p>L’étudiant n’a encore envoyé aucun reçu.</p></div>
								<?php else : ?>
									<ul class="dossier-recus">
										<?php foreach ( $recus as $r ) : $url = ueb_url_recu( $r->id ); $objet = ueb_libelle_objet_recu( $r, $fiche->type ); ?>
											<li class="dossier-recu">
												<?php /* La vignette double le bouton « Ouvrir » : hors de l'ordre de tabulation. */ ?>
												<a class="dossier-recu__vue" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener" tabindex="-1" aria-hidden="true">
													<?php if ( 'application/pdf' === $r->type_mime ) : ?>
														<span class="dossier-recu__pdf"><?php echo ueb_icone( 'fichier', 30 ); ?>PDF</span>
													<?php else : ?>
														<img src="<?php echo esc_url( $url ); ?>" alt="" loading="lazy">
													<?php endif; ?>
												</a>
												<div class="dossier-recu__infos">
													<p class="dossier-recu__objet"><?php echo esc_html( $objet ); ?></p>
													<p class="dossier-recu__date">Envoyé le <time datetime="<?php echo esc_attr( mysql2date( 'c', $r->date_envoi ) ); ?>"><?php echo esc_html( mysql2date( 'j F Y à H:i', $r->date_envoi ) ); ?></time></p>
													<p class="dossier-recu__fichier" title="<?php echo esc_attr( $r->nom_original ); ?>"><?php echo ueb_icone( 'fichier', 14 ); ?><span><?php echo esc_html( $r->nom_original ); ?></span></p>
													<div class="dossier-recu__actions">
														<a class="btn btn--fantome btn--petit" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo ueb_icone( 'oeil', 16 ); ?>Ouvrir<span class="sr"> le reçu « <?php echo esc_html( $objet ); ?> » dans un nouvel onglet</span></a>
														<a class="btn btn--lien btn--petit" href="<?php echo esc_url( ueb_url_recu( $r->id, true ) ); ?>"><?php echo ueb_icone( 'telecharger', 16 ); ?>Télécharger<span class="sr"> le reçu « <?php echo esc_html( $objet ); ?> »</span></a>
													</div>
												</div>
											</li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
							</section>

							<section class="carte bo-panneau" aria-labelledby="titre-infos">
								<header class="bo-panneau__entete">
									<span class="bo-panneau__icone"><?php echo ueb_icone( 'fichier', 20 ); ?></span>
									<div><h2 id="titre-infos">Informations imprimées</h2><p>Telles qu’elles figurent sur le quitus de l’étudiant.</p></div>
								</header>
								<dl class="dossier-infos">
									<div><dt>Nom(s) et prénom(s)</dt><dd><?php echo esc_html( $fiche->nom . ' ' . $fiche->prenom ); ?></dd></div>
									<div><dt>Né(e) le</dt><dd><?php echo esc_html( mysql2date( 'd/m/Y', $fiche->date_naissance ) . ' à ' . $fiche->lieu_naissance ); ?></dd></div>
									<div><dt>Sexe</dt><dd><?php echo 'F' === $fiche->sexe ? 'Féminin' : 'Masculin'; ?></dd></div>
									<div><dt>Nationalité</dt><dd><?php echo esc_html( $fiche->nationalite ); ?></dd></div>
									<div class="dossier-infos__large"><dt>Filière</dt><dd><?php echo esc_html( $fiche->departement ); ?></dd></div>
									<div><dt>Niveau</dt><dd><?php echo esc_html( UEB_NIVEAUX_INSCRIPTION[ $fiche->parcours ] ?? $fiche->parcours ); ?></dd></div>
									<div><dt>Établissement</dt><dd><?php echo esc_html( $etab_fiche['sigle'] ?? $fiche->etablissement ); ?></dd></div>
								</dl>
							</section>
						</div>

						<div class="dossier-colonne">
							<section class="carte bo-panneau decision-carte decision-carte--<?php echo esc_attr( $fiche->statut ); ?>" aria-labelledby="titre-decision">
								<header class="bo-panneau__entete">
									<span class="bo-panneau__icone"><?php echo ueb_icone( 'tampon', 20 ); ?></span>
									<div><h2 id="titre-decision">Décision</h2><p>Après la vérification physique des originaux à la scolarité.</p></div>
								</header>
								<?php if ( 'verifie' === $fiche->statut ) : ?>
									<p class="decision-etat decision-etat--verifie"><?php echo ueb_icone( 'check', 18 ); ?>Paiement vérifié<?php echo $decideur ? ' par ' . esc_html( $decideur->display_name ) : ''; ?><?php echo $fiche->date_verification ? ' le ' . esc_html( mysql2date( 'j F Y', $fiche->date_verification ) ) : ''; ?>.</p>
								<?php elseif ( 'rejete' === $fiche->statut ) : ?>
									<p class="decision-etat decision-etat--rejete"><?php echo ueb_icone( 'alerte', 18 ); ?>Renvoyé à l’étudiant : <?php echo esc_html( $fiche->motif_rejet ); ?></p>
								<?php elseif ( ! $recus ) : ?>
									<p class="decision-etat"><?php echo ueb_icone( 'horloge', 18 ); ?>En attente d’un reçu de l’étudiant.</p>
								<?php endif; ?>
								<?php if ( ! ueb_peut( 'ueb_decider_quitus', $fiche->etablissement ) ) : ?>
									<p class="decision-etat"><?php echo ueb_icone( 'info', 18 ); ?>Ton rôle permet de consulter ce dossier, pas de rendre la décision.</p>
								<?php else : ?>
								<?php if ( $recus && 'verifie' !== $fiche->statut ) : ?>
									<div class="decision-comparer">
										<p class="decision-comparer__titre"><?php echo ueb_icone( 'oeil', 16 ); ?>À comparer à l’original</p>
										<ul>
											<li><span>Montant</span><b><?php echo esc_html( ueb_fcfa( $fiche->montant ) ); ?></b></li>
											<li><span>Nom</span><b><?php echo esc_html( $fiche->nom . ' ' . $fiche->prenom ); ?></b></li>
											<li><span>Date du versement</span></li>
											<li><span>Référence bancaire</span></li>
										</ul>
									</div>
								<?php endif; ?>
								<div class="decision">
									<form method="post" action="<?php echo $ici( array( 'quitus' => $fiche->id ) ); ?>">
										<?php ueb_champ_csrf(); ?>
										<input type="hidden" name="ueb_action" value="gestion_statut">
										<input type="hidden" name="quitus_id" value="<?php echo (int) $fiche->id; ?>">
										<input type="hidden" name="statut" value="verifie">
										<button class="btn btn--primaire btn--large" type="submit" <?php disabled( 'verifie', $fiche->statut ); ?>><?php echo ueb_icone( 'check', 18 ); ?>Paiement vérifié</button>
									</form>
									<p class="decision__ou"><span>ou</span></p>
									<form method="post" action="<?php echo $ici( array( 'quitus' => $fiche->id ) ); ?>" class="decision__rejet">
										<?php ueb_champ_csrf(); ?>
										<input type="hidden" name="ueb_action" value="gestion_statut">
										<input type="hidden" name="quitus_id" value="<?php echo (int) $fiche->id; ?>">
										<input type="hidden" name="statut" value="rejete">
										<div class="champ">
											<label for="motif">Motif du renvoi</label>
											<textarea id="motif" name="motif" rows="2" maxlength="255" placeholder="Ex. Reçu illisible, montant différent du quitus…" aria-describedby="motif-aide"><?php echo esc_textarea( $fiche->motif_rejet ?? '' ); ?></textarea>
											<p class="champ__aide" id="motif-aide">L’étudiant le lira dans son espace.</p>
										</div>
										<button class="btn btn--fantome btn--large decision__renvoyer" type="submit"><?php echo ueb_icone( 'croix', 18 ); ?>Renvoyer à l’étudiant</button>
									</form>
									<?php if ( in_array( $fiche->statut, array( 'verifie', 'rejete' ), true ) && $recus ) : ?>
										<form method="post" action="<?php echo $ici( array( 'quitus' => $fiche->id ) ); ?>" class="decision__annuler">
											<?php ueb_champ_csrf(); ?>
											<input type="hidden" name="ueb_action" value="gestion_statut">
											<input type="hidden" name="quitus_id" value="<?php echo (int) $fiche->id; ?>">
											<input type="hidden" name="statut" value="recu_envoye">
											<button class="btn btn--lien btn--petit" type="submit"><?php echo ueb_icone( 'fleche-g', 16 ); ?>Annuler la décision</button>
										</form>
									<?php endif; ?>
								</div>
								<?php endif; ?>

							</section>

							<section class="carte bo-panneau" aria-labelledby="titre-historique">
								<header class="bo-panneau__entete">
									<span class="bo-panneau__icone"><?php echo ueb_icone( 'horloge', 20 ); ?></span>
									<div><h2 id="titre-historique">Historique</h2></div>
								</header>
								<ol class="historique">
									<?php foreach ( $historique as $h ) : ?>
										<li class="<?php echo esc_attr( 'historique--' . ( $h['classe'] ?? 'neutre' ) ); ?>">
											<span class="historique__puce" aria-hidden="true"><?php echo ueb_icone( $h['icone'], 14 ); ?></span>
											<span class="historique__texte"><?php echo esc_html( $h['texte'] ); ?><?php if ( ! empty( $h['detail'] ) ) : ?> <span class="historique__detail"><?php echo esc_html( $h['detail'] ); ?></span><?php endif; ?></span>
											<time datetime="<?php echo esc_attr( mysql2date( 'c', $h['date'] ) ); ?>"><?php echo esc_html( mysql2date( 'j F Y à H:i', $h['date'] ) ); ?></time>
										</li>
									<?php endforeach; ?>
								</ol>
							</section>
						</div>
					</div>

				<?php else : ?>

					<?php
					$filtres = array(
						'annee'  => $annee['code'],
						'etab'   => $etab_agent,
						'statut' => sanitize_key( $_GET['statut'] ?? '' ),
						'q'      => sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) ),
						'page'   => (int) ( $_GET['p'] ?? 1 ),
					);
					$stats = $stats_entete;
					$liste = ueb_gestion_liste_quitus( $filtres );
					/* Chaque statut a son icône ; le libellé l'accompagne toujours. */
					$icones_statut  = array( 'genere' => 'horloge', 'recu_envoye' => 'envoyer', 'verifie' => 'check', 'rejete' => 'alerte' );
					$libelle_statut = UEB_STATUTS_QUITUS[ $filtres['statut'] ]['libelle'] ?? '';
					/* Bandeau de la file : le montant déclaré et le plus ancien envoi se
					   lisent sur la page affichée quand elle porte toute la file (pas de
					   requête de plus) ; sinon le bandeau s'en tient au nombre. */
					$en_attente = array_values( array_filter( $liste['lignes'], static fn( $q ) => 'recu_envoye' === $q->statut ) );
					usort( $en_attente, static fn( $a, $b ) => strcmp( $a->date_modification, $b->date_modification ) );
					$plus_ancien  = $a_verifier && count( $en_attente ) === $a_verifier ? $en_attente[0] : null;
					$montant_file = $plus_ancien ? array_sum( array_map( static fn( $q ) => (int) $q->montant, $en_attente ) ) : 0;
					$maintenant   = current_time( 'timestamp' );
					?>

					<section class="registre-file<?php echo $a_verifier ? '' : ' registre-file--a-jour'; ?>" aria-labelledby="titre-file-quitus">
						<span class="registre-file__icone" aria-hidden="true"><?php echo ueb_icone( $a_verifier ? 'envoyer' : 'check', 20 ); ?></span>
						<div class="registre-file__texte">
							<?php if ( $a_verifier ) : ?>
								<h2 id="titre-file-quitus"><?php echo esc_html( 1 === $a_verifier ? 'Un reçu attend ta vérification' : $a_verifier . ' reçus attendent ta vérification' ); ?></h2>
								<?php if ( $plus_ancien ) : ?>
									<p><?php echo esc_html( sprintf( 1 === $a_verifier ? '%1$s déclarés. Il attend depuis %2$s.' : '%1$s déclarés au total. Le plus ancien attend depuis %2$s.', ueb_fcfa( $montant_file ), human_time_diff( strtotime( $plus_ancien->date_modification ), $maintenant ) ) ); ?></p>
								<?php else : ?>
									<p>Compare chaque reçu à l’original présenté par l’étudiant avant de rendre ta décision.</p>
								<?php endif; ?>
							<?php else : ?>
								<h2 id="titre-file-quitus">Aucun reçu n’attend ta vérification</h2>
								<p>Les prochains envois des étudiants apparaîtront en tête du registre.</p>
							<?php endif; ?>
						</div>
						<?php if ( $plus_ancien ) : ?>
							<a class="btn btn--primaire registre-file__action" href="<?php echo $ici( array( 'quitus' => $plus_ancien->id ) ); ?>"><?php echo 1 === $a_verifier ? 'Ouvrir le dossier' : 'Ouvrir le plus ancien'; ?><?php echo ueb_icone( 'fleche', 18 ); ?></a>
						<?php elseif ( $a_verifier && 'recu_envoye' !== $filtres['statut'] ) : ?>
							<a class="btn btn--primaire registre-file__action" href="<?php echo $ici( array( 'vue' => 'quitus', 'statut' => 'recu_envoye' ) ); ?>">Afficher les reçus à vérifier<?php echo ueb_icone( 'fleche', 18 ); ?></a>
						<?php endif; ?>
					</section>

					<section class="carte registre registre--quitus" aria-label="Quitus de l’année">
						<div class="registre__barre">
							<nav class="onglets-statut" aria-label="Filtrer par statut">
								<a class="<?php echo '' === $filtres['statut'] ? 'est-actif' : ''; ?>" href="<?php echo $ici( array( 'vue' => 'quitus', 'q' => $filtres['q'] ?: null ) ); ?>" <?php echo '' === $filtres['statut'] ? 'aria-current="page"' : ''; ?>>Tous <span class="onglets-statut__nb"><?php echo (int) $stats['total']; ?></span></a>
								<?php foreach ( array( 'recu_envoye', 'genere', 'rejete', 'verifie' ) as $cle ) : $actif = $cle === $filtres['statut']; ?>
									<a class="onglets-statut__<?php echo esc_attr( $cle ); ?><?php echo $actif ? ' est-actif' : ''; ?>" href="<?php echo $ici( array( 'vue' => 'quitus', 'statut' => $cle, 'q' => $filtres['q'] ?: null ) ); ?>" <?php echo $actif ? 'aria-current="page"' : ''; ?>><?php echo ueb_icone( $icones_statut[ $cle ], 16 ); ?><?php echo esc_html( UEB_STATUTS_QUITUS[ $cle ]['libelle'] ); ?> <span class="onglets-statut__nb"><?php echo (int) $stats['statuts'][ $cle ]; ?></span></a>
								<?php endforeach; ?>
							</nav>
							<form class="registre__recherche" method="get" action="<?php echo esc_url( ueb_url_scolarite() ); ?>" role="search">
								<input type="hidden" name="vue" value="quitus">
								<?php if ( $filtres['statut'] ) : ?><input type="hidden" name="statut" value="<?php echo esc_attr( $filtres['statut'] ); ?>"><?php endif; ?>
								<label class="sr" for="f-q">Rechercher un quitus</label>
								<span class="registre__champ">
									<?php echo ueb_icone( 'loupe', 18 ); ?>
									<input id="f-q" type="search" name="q" value="<?php echo esc_attr( $filtres['q'] ); ?>" placeholder="N° de quitus, matricule, nom…" enterkeyhint="search" autocomplete="off">
									<kbd class="registre__touche" aria-hidden="true">Entrée</kbd>
								</span>
								<button class="btn btn--fantome btn--petit" type="submit">Rechercher</button>
							</form>
						</div>

						<?php if ( ! $liste['lignes'] ) : ?>
							<div class="registre-vide">
								<span class="registre-vide__icone" aria-hidden="true"><?php echo ueb_icone( $filtres['q'] ? 'loupe' : 'recu', 24 ); ?></span>
								<?php if ( $filtres['q'] ) : ?>
									<p class="registre-vide__titre">Aucun quitus ne correspond à « <?php echo esc_html( $filtres['q'] ); ?> »<?php echo $libelle_statut ? esc_html( ' parmi les « ' . $libelle_statut . ' »' ) : ''; ?></p>
									<p>Vérifie l’orthographe, ou cherche par numéro de quitus, matricule ou nom de famille.</p>
									<div class="registre-vide__actions">
										<a class="btn btn--fantome btn--petit" href="<?php echo $ici( array( 'vue' => 'quitus', 'statut' => $libelle_statut ? $filtres['statut'] : null ) ); ?>"><?php echo ueb_icone( 'croix', 16 ); ?>Effacer la recherche</a>
										<?php if ( $libelle_statut ) : ?><a class="btn btn--lien btn--petit" href="<?php echo $ici( array( 'vue' => 'quitus', 'q' => $filtres['q'] ) ); ?>">Chercher dans tous les statuts</a><?php endif; ?>
									</div>
								<?php elseif ( $libelle_statut ) : ?>
									<p class="registre-vide__titre">Aucun quitus « <?php echo esc_html( $libelle_statut ); ?> » pour le moment</p>
									<p><?php echo esc_html( array(
										'recu_envoye' => 'Les reçus envoyés par les étudiants s’afficheront ici, prêts à être vérifiés.',
										'genere'      => 'Les quitus générés et pas encore payés s’afficheront ici.',
										'rejete'      => 'Les dossiers renvoyés à l’étudiant avec un motif s’afficheront ici.',
										'verifie'     => 'Les paiements vérifiés par la scolarité s’afficheront ici.',
									)[ $filtres['statut'] ] ); ?></p>
									<div class="registre-vide__actions"><a class="btn btn--fantome btn--petit" href="<?php echo $ici( array( 'vue' => 'quitus' ) ); ?>">Voir tous les quitus</a></div>
								<?php else : ?>
									<p class="registre-vide__titre">Aucun quitus pour l’année <?php echo esc_html( $annee['libelle'] ); ?></p>
									<p>Les quitus apparaîtront ici dès que les étudiants les auront générés.</p>
								<?php endif; ?>
							</div>
						<?php else : ?>
							<?php if ( $filtres['q'] ) : ?>
								<p class="registre__resultat"><span><b><?php echo (int) $liste['total']; ?></b> <?php echo 1 === (int) $liste['total'] ? 'résultat' : 'résultats'; ?> pour « <b><?php echo esc_html( $filtres['q'] ); ?></b> »</span><a class="bo-lien" href="<?php echo $ici( array( 'vue' => 'quitus', 'statut' => $libelle_statut ? $filtres['statut'] : null ) ); ?>"><?php echo ueb_icone( 'croix', 15 ); ?>Effacer la recherche</a></p>
							<?php endif; ?>
							<div class="tableau-conteneur">
								<table class="tableau registre__tableau">
									<thead><tr><th scope="col">Étudiant</th><th scope="col">Quitus</th><th scope="col">Paiement</th><th scope="col" class="num">Montant</th><th scope="col" class="num">Reçus</th><th scope="col">Statut</th><th scope="col"><span class="sr">Ouvrir</span></th></tr></thead>
									<tbody>
									<?php foreach ( $liste['lignes'] as $q ) :
										$type_q   = 'medicaux' === ( $q->type ?? 'droits' ) ? 'medicaux' : 'droits';
										$modalite = 'medicaux' === $type_q ? 'Paiement unique' : ueb_libelle_tranche( $q->tranche );
										$nb_recus = (int) $q->nb_recus;
										?>
										<tr class="registre__ligne registre__ligne--<?php echo esc_attr( $q->statut ); ?>">
											<td class="registre__c-qui">
												<span class="registre__qui">
													<span class="bo-avatar" aria-hidden="true"><?php echo esc_html( ueb_initiales( $q->prenom, $q->nom ) ); ?></span>
													<span><b><?php echo esc_html( $q->nom . ' ' . $q->prenom ); ?></b><small><?php echo esc_html( $q->identifiant ); ?></small></span>
												</span>
											</td>
											<td class="registre__c-quitus"><span class="registre__numero"><?php echo esc_html( $q->numero ); ?></span><small class="registre__date" title="Dernière mise à jour"><time datetime="<?php echo esc_attr( mysql2date( 'Y-m-d', $q->date_modification ) ); ?>"><?php echo esc_html( mysql2date( 'd/m/Y', $q->date_modification ) ); ?></time></small></td>
											<td class="registre__paiement"><span class="registre__type registre__type--<?php echo esc_attr( $type_q ); ?>"><?php echo esc_html( ueb_libelle_type_quitus( $type_q ) ); ?></span><?php if ( $modalite ) : ?><small><?php echo esc_html( $modalite ); ?></small><?php endif; ?></td>
											<td class="num registre__montant"><?php echo esc_html( ueb_formater_montant( $q->montant ) ); ?> <small>FCFA</small></td>
											<td class="num registre__c-recus"><?php if ( $nb_recus ) : ?><span class="registre__recus"><?php echo ueb_icone( 'recu', 15 ); ?><?php echo $nb_recus; ?><span class="registre__recus-mot"><?php echo 1 === $nb_recus ? ' reçu' : ' reçus'; ?></span></span><?php else : ?><span class="registre__aucun">—<span class="sr">Aucun reçu</span></span><?php endif; ?></td>
											<td class="registre__c-statut"><span class="badge badge--<?php echo esc_attr( $q->statut ); ?> registre__statut"><?php echo ueb_icone( $icones_statut[ $q->statut ] ?? 'info', 14 ); ?><?php echo esc_html( UEB_STATUTS_QUITUS[ $q->statut ]['libelle'] ?? $q->statut ); ?></span></td>
											<td class="registre__action"><a class="registre__ouvrir" href="<?php echo $ici( array( 'quitus' => $q->id ) ); ?>" aria-label="<?php echo esc_attr( 'Ouvrir le quitus ' . $q->numero . ' de ' . trim( $q->nom . ' ' . $q->prenom ) ); ?>"><?php echo ueb_icone( 'fleche', 18 ); ?></a></td>
										</tr>
									<?php endforeach; ?>
									</tbody>
								</table>
							</div>
							<footer class="registre__pied">
								<p><b><?php echo (int) $liste['total']; ?></b> quitus<?php echo $liste['pages'] > 1 ? ', page ' . (int) $liste['page'] . ' sur ' . (int) $liste['pages'] : ''; ?></p>
								<?php if ( $liste['pages'] > 1 ) :
									/* Première et dernière pages, la courante et ses voisines ; « … » entre deux trous. */
									$courante = (int) $liste['page'];
									$derniere = (int) $liste['pages'];
									$url_page = static fn( $n ) => $ici( array( 'vue' => 'quitus', 'p' => $n, 'statut' => $filtres['statut'] ?: null, 'q' => $filtres['q'] ?: null ) );
									$visibles = array_values( array_unique( array_filter( array( 1, $courante - 1, $courante, $courante + 1, $derniere ), static fn( $n ) => $n >= 1 && $n <= $derniere ) ) );
									sort( $visibles );
									$precedente = 0;
									?>
									<nav class="pagination registre__pagination" aria-label="Pages du registre">
										<?php if ( $courante > 1 ) : ?><a class="registre__pas" href="<?php echo $url_page( $courante - 1 ); ?>" rel="prev"><?php echo ueb_icone( 'fleche-g', 16 ); ?><span class="sr">Page précédente</span></a><?php endif; ?>
										<?php foreach ( $visibles as $n ) : ?>
											<?php if ( $precedente && $n > $precedente + 1 ) : ?><span class="registre__ellipse" aria-hidden="true">…</span><?php endif; ?>
											<a href="<?php echo $url_page( $n ); ?>" <?php echo $n === $courante ? 'aria-current="page"' : ''; ?>><span class="sr">Page </span><?php echo (int) $n; ?></a>
											<?php $precedente = $n; ?>
										<?php endforeach; ?>
										<?php if ( $courante < $derniere ) : ?><a class="registre__pas" href="<?php echo $url_page( $courante + 1 ); ?>" rel="next"><span class="sr">Page suivante</span><?php echo ueb_icone( 'fleche', 16 ); ?></a><?php endif; ?>
									</nav>
								<?php endif; ?>
							</footer>
						<?php endif; ?>
					</section>

				<?php endif; ?>
			</div>
		</div>

	<?php endif; ?>

</main>
<?php
ueb_page_fin( 'gestion' );
