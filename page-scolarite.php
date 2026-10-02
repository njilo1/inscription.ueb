<?php
/**
 * Template Name: Espace scolarité
 *
 * Espace de la scolarité d'un établissement, avec barre latérale :
 *   - Tableau de bord : bilan des droits et quatre graphiques ;
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
			$erreur_connexion = ueb_message_echec_connexion( $utilisateur, 'Identifiant ou mot de passe incorrect. Vérifie l’identifiant communiqué par l’administration.' );
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

/* Accès par capacité et portée (inc/roles.php) : examiner les quitus, suivre les
   paiements ou suivre les IPES sous tutelle (inc/ipes-tutelle.php). */
$peut_quitus    = ueb_peut( UEB_CAP_GESTION );
$peut_paiements = ueb_peut( 'ueb_voir_paiements' );
$peut_ipes      = ueb_peut( 'ueb_voir_ipes' );
$peut_etudiants = ueb_peut( 'ueb_voir_etudiants' );
$autorise       = ueb_est_scolarite() && ( $peut_quitus || $peut_paiements || $peut_ipes || $peut_etudiants );
/* Première vue permise : tableau de bord, sinon paiements, sinon IPES, sinon étudiants. */
$vue_defaut     = $peut_quitus ? 'bord' : ( $peut_paiements ? 'paiements' : ( $peut_ipes ? 'ipes' : 'etudiants' ) );
$annee    = ueb_annee_academique();

if ( $autorise ) {
	$etab_agent = ueb_etab_agent();
	$etab       = $etab_agent ? ueb_etablissement( $etab_agent ) : null;
	$vue        = sanitize_key( $_GET['vue'] ?? $vue_defaut );
	/* Chaque vue exige sa permission ; sinon retour à la première vue permise. */
	$permises = array_filter( array(
		'bord'      => $peut_quitus,
		'quitus'    => $peut_quitus,
		'paiements' => $peut_paiements,
		'etudiants' => $peut_etudiants,
		'ipes'      => $peut_ipes,
		'cellule'   => ueb_peut( 'ueb_creer_agents' ),
		'securite'  => true,
		'comptes'   => true,
	) );
	if ( ! isset( $permises[ $vue ] ) ) {
		$vue = $vue_defaut;
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
					$peut_etudiants ? array( 'url' => $ici( array( 'vue' => 'etudiants' ) ), 'libelle' => 'Étudiants UEB', 'icone' => 'diplome', 'actif' => 'etudiants' === $vue ) : null,
					$peut_ipes ? array( 'url' => $ici( array( 'vue' => 'ipes' ) ), 'libelle' => 'IPES', 'icone' => 'ecole', 'actif' => 'ipes' === $vue ) : null,
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
					'etudiants' => array( 'Étudiants UEB', 'Les étudiants inscrits de ta portée et l’état de leurs droits de l’année, en lecture seule.' ),
					'paiements' => array( 'Suivi des paiements', 'Droits universitaires attendus et encaissés, filière par filière. Seuls les reçus vérifiés comptent comme encaissés.' ),
					'cellule'  => array( 'Comptes du personnel', 'Les comptes que tu crées pour ton établissement, avec un rôle aux droits inférieurs aux tiens.' ),
					'ipes'     => array( 'IPES sous tutelle', 'Les établissements privés placés sous la tutelle de ton établissement : leurs étudiants et leurs reversements.' ),
					'securite' => array( 'Sécurité', 'Le mot de passe de ton accès à l’espace scolarité.' ),
				);
				list( $titre_vue, $sous_titre_vue ) = $titres[ $vue ] ?? $titres['bord'];
				$stats_entete = ueb_gestion_stats( $annee['code'], $etab_agent );
				$a_verifier   = (int) ( $stats_entete['statuts']['recu_envoye'] ?? 0 );
				?>
				<?php if ( ! $fiche && ! ( 'ipes' === $vue && isset( $_GET['ipes'] ) ) ) : /* la fiche d'un IPES a son propre en-tête */ ?>
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
					<div class="bord bord--scolarite">
						<?php
						ueb_bord_synthese( $suivi, $url_paiements );
						ueb_bord_parcours( $c, $ici );
						?>

						<div class="bord__rangee bord__rangee--2">
							<?php ueb_graphe_courbes( 'Progression de l’année', 'Quitus cumulés, jour après jour', $activite ); ?>
							<section class="carte file-verif" aria-labelledby="titre-file">
								<header class="file-verif__entete">
									<div>
										<h2 id="titre-file">Reçus à vérifier <span class="scolarite-compteur"><?php echo (int) $a_verifier; ?></span></h2>
										<p>Compare les reçus aux originaux, en commençant par les plus anciens de cette sélection.</p>
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
										<p><?php echo ueb_icone( 'horloge', 16 ); ?>Le premier reçu affiché attend depuis <?php echo esc_html( human_time_diff( strtotime( $file[0]->date_modification ), $maintenant ) ); ?>.</p>
										<a class="bo-lien" href="<?php echo $ici( array( 'vue' => 'quitus', 'statut' => 'recu_envoye' ) ); ?>">Tout voir (<?php echo (int) $a_verifier; ?>)<?php echo ueb_icone( 'fleche', 16 ); ?></a>
									</footer>
								<?php endif; ?>
							</section>
						</div>

						<?php if ( $peut_ipes ) : ?>
							<?php
							/* IPES sous tutelle : seulement la part des établissements regardés. */
							$sous_tutelle = ueb_ipes_sous_tutelle();
							ueb_ipes_panneau_synthese( ueb_ipes_synthese( $sous_tutelle, 'ueb_ipes_tutelles_vues' ), array(
								'url'    => $ici( array( 'vue' => 'ipes' ) ),
								'classe' => 'carte',
								'portee' => 'Ta part des reversements des IPES sous tutelle : ' . ueb_fcfa( UEB_IPES_REVERSEMENT_PAR_ETUDIANT ) . ' par étudiant de tes filières.',
							) );
							?>
						<?php endif; ?>

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

					<?php $reinit = $_SESSION['ueb_reinit_effectuee'] ?? null; unset( $_SESSION['ueb_reinit_effectuee'] ); ?>
					<?php if ( $reinit ) : ?>
						<div class="provisoire carte" role="status">
							<?php echo ueb_icone( 'cle', 26 ); ?>
							<div>
								<p>Compte <b><?php echo esc_html( $reinit['compte'] ); ?></b> réinitialisé, <b>jusqu’à <?php echo esc_html( $reinit['jusqua'] ); ?></b>.</p>
								<p class="champ__aide">Conseille à l’étudiant de choisir son mot de passe maintenant, sur son téléphone : page de connexion → « Mot de passe oublié ? », puis son matricule. Personne d’autre que lui ne connaîtra ce mot de passe. Passé ce délai, il faudra réinitialiser de nouveau.</p>
							</div>
						</div>
					<?php endif; ?>
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
							<input id="e-q" type="search" name="qc" value="<?php echo esc_attr( $filtres_e['q'] ); ?>" placeholder="Nom, prénom, matricule ou téléphone">
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
									<td><?php echo esc_html( $e->matricule ?: '—' ); ?></td>
									<td class="num"><?php echo esc_html( $e->telephone ? ueb_formater_telephone( $e->telephone ) : '—' ); ?></td>
									<td class="num"><?php echo (int) $e->quitus; ?><br><small class="texte-discret"><?php echo (int) $e->verifies; ?> vérifié(s)</small></td>
									<td>
										<?php echo 'actif' === $e->statut ? '<span class="badge badge--verifie"><i></i>Actif</span>' : '<span class="badge badge--rejete"><i></i>Suspendu</span>'; ?>
										<?php echo ueb_badge_mdp( $e ); // phpcs:ignore -- échappé ?>
									</td>
									<td class="actions-ligne">
										<form method="post" action="<?php echo esc_url( ueb_url_scolarite() ); ?>" data-confirmer="Réinitialiser le mot de passe de <?php echo esc_attr( ueb_identifiant_compte( $e ) ); ?> ? As-tu vérifié sa carte d’identité ? Il aura 1 heure pour choisir son nouveau mot de passe.">
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
										'libelle' => 'Matricule',
										'icone'   => 'utilisateur',
										'attrs'   => array( 'placeholder' => 'Exemple : 24I0017FS', 'autocapitalize' => 'characters', 'spellcheck' => 'false', 'autocomplete' => 'off', 'data-identifiant' => true ),
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

					<?php require UEB_INSC_DIR . '/templates/composants/scolarite-quitus.php'; ?>

				<?php elseif ( 'etudiants' === $vue ) : ?>

					<?php ueb_vue_etudiants( array( 'url' => ueb_url_scolarite(), 'params' => array( 'vue' => 'etudiants' ), 'etabs' => ueb_etabs_autorises() ) ); ?>

				<?php elseif ( 'ipes' === $vue ) : ?>

					<?php include UEB_INSC_DIR . '/templates/composants/scolarite-ipes.php'; ?>

				<?php elseif ( 'quitus' === $vue ) : /* jamais un « else » : une vue sans branche n'affiche rien, surtout pas les quitus */ ?>

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
								<span class="onglets-statut__titre" aria-hidden="true">Filtrer par statut</span>
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
