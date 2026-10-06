<?php
/**
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
/* Quitus : ceux des droits universitaires (scolarité) ou des frais médicaux (CMS).
   Chacun a son tableau de bord : droits (?type=droits) ou frais médicaux (?type=medicaux). */
$peut_quitus    = (bool) ueb_types_quitus_visibles();
$peut_bord      = $peut_quitus;
$peut_paiements = ueb_peut( 'ueb_voir_paiements' );
$peut_ipes      = ueb_peut( 'ueb_voir_ipes' );
$peut_etudiants = ueb_peut( 'ueb_voir_etudiants' );
$autorise       = ( ueb_est_scolarite() || ueb_est_admin_ueb() ) && ( $peut_quitus || $peut_paiements || $peut_ipes || $peut_etudiants );
/* Première vue permise : tableau de bord, sinon paiements, sinon IPES, sinon étudiants. */
$vue_defaut     = $peut_bord ? 'bord' : ( $peut_quitus ? 'quitus' : ( $peut_paiements ? 'paiements' : ( $peut_ipes ? 'ipes' : 'etudiants' ) ) );
$annee    = ueb_annee_academique();

if ( $autorise ) {
	$etab_agent = ueb_etab_agent();
	$etab       = $etab_agent ? ueb_etablissement( $etab_agent ) : null;
	$vue        = sanitize_key( $_GET['vue'] ?? $vue_defaut );
	/* Chaque vue exige sa permission ; sinon retour à la première vue permise. */
	$permises = array_filter( array(
		'bord'      => $peut_bord,
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
	if ( $fiche && ! ueb_peut_voir_quitus( $fiche ) ) {
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
	/* Tableau de bord : même rendu que celui de l'administration (inc/administration-dashboard.php),
	   sur les droits universitaires, ou celui du CMS sur les frais médicaux (inc/cms-tableau.php). */
	$tableau     = 'bord' === $vue && ! $fiche;
	$tableau_cms = $tableau && 'medicaux' === ueb_type_recus_courant();
	$periode     = (int) ( $_GET['periode'] ?? 30 ); // phpcs:ignore -- lecture seule
	$periode     = in_array( $periode, array( 7, 30, 90 ), true ) ? $periode : 30;
}

/* Coque plein écran une fois connecté ; en-tête de site conservé sur l'écran
   de connexion, qui n'a pas encore de barre latérale pour porter la marque. */
ueb_page_debut( array(
	'titre'    => 'Espace scolarité',
	'variante' => $autorise ? 'bo' : 'gestion',
	'classe'   => $autorise && $tableau ? 'espace-admin' : '',
) );
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

			<div class="bo-contenu<?php echo $tableau ? ' adm adm-dashboard' : ''; ?>">
				<?php
				$titres = array(
					'bord'     => array( 'Tableau de bord', sprintf( 'Bonjour %s. Voici où en sont les inscriptions %s.', wp_get_current_user()->display_name ?: wp_get_current_user()->user_login, $etab ? 'de ' . $etab['fr'] : 'de tous les établissements' ) ),
					'quitus'   => 'medicaux' !== ueb_type_recus_courant() ? array( 'Reçus', 'Les reçus envoyés par les étudiants : retrouve un dossier, compare ses reçus aux originaux et rends ta décision.' ) : array( 'Reçus CMS', 'Les reçus des frais médicaux envoyés par les étudiants : compare-les aux originaux, puis valide-les ou renvoie-les.' ),
					'etudiants' => array( 'Étudiants UEB', 'Les étudiants inscrits de ta portée et l’état de leurs droits de l’année, en lecture seule.' ),
					'paiements' => array( 'Suivi des paiements', 'Droits universitaires attendus et encaissés, filière par filière. Seuls les reçus vérifiés comptent comme encaissés.' ),
					'cellule'  => array( 'Comptes du personnel', 'Les comptes que tu crées pour ton établissement, avec un rôle aux droits inférieurs aux tiens.' ),
					'ipes'     => array( 'IPES sous tutelle', 'Les établissements privés placés sous la tutelle de ton établissement : leurs étudiants et leurs reversements.' ),
					'securite' => array( 'Sécurité', 'Le mot de passe de ton accès à l’espace scolarité.' ),
				);
				list( $titre_vue, $sous_titre_vue ) = $titres[ $vue ] ?? $titres['bord'];
				$stats_entete = ueb_gestion_stats( $annee['code'], $etab_agent, 'droits' ); // tableau de bord : droits universitaires
				$a_verifier   = (int) ( $stats_entete['statuts']['recu_envoye'] ?? 0 );
				?>
				<?php if ( $tableau_cms ) : ?>
					<?php
					$cms = ueb_cms_donnees( $annee['code'], $periode );
					ueb_cms_tete( $cms['chiffres'] );
					?>
				<?php elseif ( $tableau ) : ?>
					<?php
					$c = ueb_gestion_chiffres( $annee['code'], $etab_agent );
					ueb_adm_tete( array(
						'titre'      => 'Tableau de bord',
						'sous_titre' => $etab
							? sprintf( '%s, à %s : %d quitus pour %s cette année.', $etab['fr'], $etab['ville'], $c['quitus'], ueb_suivi_etudiants( $c['etudiants'] ) )
							: sprintf( 'Tous les établissements : %d quitus pour %s cette année.', $c['quitus'], ueb_suivi_etudiants( $c['etudiants'] ) ),
						'theme'      => false,
						/* Les reçus à vérifier : la carte rouge en tête des indicateurs. */
						'actions'    => $peut_paiements ? ueb_adm_action( add_query_arg( 'vue', 'paiements', ueb_url_scolarite() ), $etab ? 'Paiements de ' . $etab['sigle'] : 'Suivi des paiements', 'banque', true ) : '',
					) );
					?>
				<?php elseif ( ! $fiche && ! ( 'ipes' === $vue && isset( $_GET['ipes'] ) ) ) : /* la fiche d'un IPES a son propre en-tête */ ?>
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

					<?php
					/* Qui valide des reçus voit ses décisions signées ; qui consulte, la discrétion attendue. */
					ueb_bloc_mot_de_passe( array(
						'action'  => ueb_url_scolarite(),
						'titre'   => 'Ton accès engage l’université',
						'conseil' => ueb_peut( 'ueb_decider_quitus' ) || ueb_peut( 'ueb_decider_cms' )
							? array( 'tampon', 'Chaque décision est enregistrée à ton nom', ' : paiement vérifié ou reçu renvoyé.' )
							: array( 'oeil', 'Tu consultes les dossiers des étudiants', ' : ces informations ne sortent pas de l’université.' ),
					) );
					?>

				<?php elseif ( 'paiements' === $vue ) : ?>

					<?php
					ueb_suivi_paiements_vue( ueb_suivi_paiements( $annee['code'], $etab_agent ), array(
						'perimetre' => $etab ? $etab['sigle'] : 'Université',
						'lignes'    => $etab ? 'filieres' : 'etabs',
					) );
					?>

				<?php elseif ( 'bord' === $vue && $tableau_cms ) : ?>

					<?php ueb_cms_tableau( $cms, $periode ); ?>

				<?php elseif ( 'bord' === $vue ) : ?>

					<?php
					/* Même tableau de bord que l'administration (inc/administration-dashboard.php),
					   limité à l'établissement de l'agent : la carte rouge des reçus en attente
					   en tête, la file des plus anciens dessous (inc/attente-recus.php). */
					$suivi         = ueb_suivi_paiements( $annee['code'], $etab_agent, $periode );
					$activite      = ueb_gestion_activite( $annee['code'], $etab_agent, $periode );
					$url_espace    = static fn( array $args = array() ) => add_query_arg( $args, ueb_url_scolarite() );
					$url_paiements = $peut_paiements ? $url_espace( array( 'vue' => 'paiements' ) ) : '';

					ueb_adm_dashboard( $c, $suivi, $activite, $etab_agent, $periode, array(
						'url'       => $url_espace,
						'perimetre' => false,
						'paiements' => $url_paiements,
						'ipes'      => false,
						'attente'   => 'droits',
					) );
					ueb_file_attente( 'droits' );
					?>

					<?php if ( $peut_ipes ) : ?>
						<?php
						/* IPES sous tutelle : seulement la part des établissements regardés. */
						ueb_ipes_panneau_synthese( ueb_ipes_synthese( ueb_ipes_sous_tutelle(), 'ueb_ipes_tutelles_vues' ), array(
							'url'    => $url_espace( array( 'vue' => 'ipes' ) ),
							'portee' => 'Ta part des reversements des IPES sous tutelle : ' . ueb_fcfa( UEB_IPES_REVERSEMENT_PAR_ETUDIANT ) . ' par étudiant de tes filières.',
						) );
						?>
					<?php endif; ?>

					<div class="adm-grille adm-grille--graphes adm-complements">
						<?php
						ueb_adm_statistiques( $c );
						ueb_adm_comparaison( $suivi, $etab_agent, $periode, $url_paiements ?: $url_espace( array( 'vue' => 'quitus' ) ) );
						ueb_graphe_anneau( 'Répartition par sexe', 'Étudiants ayant au moins un quitus', array(
							'Masculin' => array( 'valeur' => $c['sexe']['M'], 'couleur' => 'var(--viz-id-1)' ),
							'Féminin'  => array( 'valeur' => $c['sexe']['F'], 'couleur' => 'var(--viz-id-2)' ),
						) );
						?>
					</div>

					<?php if ( $etab_agent ) : ?>
						<?php
						$niveaux  = ueb_gestion_niveaux_par_etab( $annee['code'] );
						$vide_niv = array_fill_keys( array_keys( UEB_NIVEAUX_INSCRIPTION ), 0 );
						?>
						<div class="adm-grille adm-grille--etab">
							<?php
							ueb_adm_niveaux( array_merge( $vide_niv, array_intersect_key( $niveaux[ $etab_agent ] ?? array(), $vide_niv ) ), $c['etudiants'] );
							ueb_adm_filieres( ueb_gestion_par_filiere( $annee['code'], $etab_agent ) );
							?>
						</div>
						<?php ueb_graphe_filieres( 'Recouvrement par filière', 'Part encaissée des droits attendus, les plus gros montants d’abord', $suivi['filieres'], $url_paiements ); ?>
					<?php endif; ?>

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

					<form class="filtres carte" method="get" action="<?php echo esc_url( ueb_url_scolarite() ); ?>" role="search"><?php ueb_champ_espace(); ?>
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
						'annee'     => $annee['code'],
						'etab'      => $etab_agent,
						'statut'    => sanitize_key( $_GET['statut'] ?? '' ),
						'paiements' => '', // un seul type de reçus par onglet : pas de filtre DU / FM
						/* Un seul type de reçus par onglet : Reçus (droits) ou Reçus CMS (frais médicaux). */
						'type'      => ueb_type_recus_courant(),
						'q'         => sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) ),
						'filiere'   => sanitize_text_field( wp_unslash( $_GET['filiere'] ?? '' ) ),
						'niveau'    => sanitize_text_field( wp_unslash( $_GET['niveau'] ?? '' ) ),
						'moyen'     => sanitize_text_field( wp_unslash( $_GET['moyen'] ?? '' ) ),
						'page'      => (int) ( $_GET['p'] ?? 1 ),
					);
					if ( ! isset( UEB_STATUTS_QUITUS[ $filtres['statut'] ] ) ) {
						$filtres['statut'] = '';
					}
					if ( ! isset( UEB_FILTRES_PAIEMENTS[ $filtres['paiements'] ] ) ) {
						$filtres['paiements'] = '';
					}
					/* Une ligne par dossier : les droits universitaires (DU) et les frais
					   médicaux (FM) d'un même étudiant sont réunis (inc/gestion.php). */
					$dossiers    = ueb_gestion_stats_dossiers( $filtres );
					$liste       = ueb_gestion_liste_dossiers( $filtres );
					$options     = ueb_gestion_options_dossiers( $filtres );
					$maintenant  = current_time( 'timestamp' );
					$plus_ancien = $dossiers['plus_ancien'];
					$attente     = (int) ( $dossiers['attente']->paiements ?? 0 );
					$depuis      = $plus_ancien ? human_time_diff( strtotime( $plus_ancien->date_modification ), $maintenant ) : '';
					/* Les compteurs et les pastilles parlent comme l'agent : ce qu'il
					   a à valider, ce qui n'est pas encore validé, rejeté ou validé. */
					$etats = array(
						'recu_envoye' => array( 'compteur' => 'À valider', 'pastille' => 'À valider', 'icone' => 'envoyer', 'note' => 'Reçus envoyés, à contrôler', 'vide' => 'Les reçus envoyés par les étudiants s’afficheront ici, prêts à être vérifiés.' ),
						'genere'      => array( 'compteur' => 'Non validés', 'pastille' => 'À payer', 'icone' => 'horloge', 'note' => 'Reçu pas encore envoyé', 'vide' => 'Les dossiers dont l’étudiant n’a pas encore envoyé le reçu s’afficheront ici.' ),
						'rejete'      => array( 'compteur' => 'Rejetés', 'pastille' => 'Rejeté', 'icone' => 'alerte', 'note' => 'Renvoyés à l’étudiant', 'vide' => 'Les dossiers renvoyés à l’étudiant avec un motif s’afficheront ici.' ),
						'verifie'     => array( 'compteur' => 'Validés', 'pastille' => 'Validé', 'icone' => 'check', 'note' => 'Tous les paiements vérifiés', 'vide' => 'Les dossiers dont tous les paiements sont vérifiés s’afficheront ici.' ),
					);
					$abreviations   = array( 'droits' => array( 'DU', 'Droits universitaires' ), 'medicaux' => array( 'FM', 'Frais médicaux' ) );
					$libelle_statut = $filtres['statut'] ? $etats[ $filtres['statut'] ]['compteur'] : '';
					/* Filtres en cours (hors statut) : gardés par les compteurs, l'alerte et les pages. */
					$actifs    = array_filter( array_intersect_key( $filtres, array_flip( array( 'paiements', 'type', 'q', 'filiere', 'niveau', 'moyen' ) ) ), 'strlen' );
					$url_liste = static fn( array $args = array() ) => $ici( array_merge( array( 'vue' => 'quitus', 'statut' => $filtres['statut'] ?: null ), $actifs, $args ) );
					$niveau_lu = static function ( $code ) {
						foreach ( UEB_NIVEAUX_INSCRIPTION as $cle_niveau => $libelle ) {
							if ( 0 === strcasecmp( $cle_niveau, $code ) ) {
								return preg_replace( '/^.*—\s*/u', '', $libelle );
							}
						}
						return $code;
					};
					$selects = array(
						'filiere'   => array( 'Toutes les filières', array_combine( $options['filiere'], $options['filiere'] ) ),
						'niveau'    => array( 'Tous les niveaux', array_combine( $options['niveau'], array_map( $niveau_lu, $options['niveau'] ) ) ),
						'moyen'     => array( 'Tous les moyens de paiement', array_combine( $options['moyen'], $options['moyen'] ) ),
					);
					$aujourdhui = wp_date( 'd.m.Y' );
					/* Anneau « Tous les dossiers » : la composition Remotion « donut »
					   (un balayage, la part « à valider » respire), et son repli SVG,
					   identique à la dernière image. */
					$couleurs_etat = array( 'recu_envoye' => 'var(--ciel-fonce)', 'genere' => 'var(--or)', 'rejete' => 'var(--danger)', 'verifie' => 'var(--vert)' );
					$parts_anneau  = array();
					foreach ( $couleurs_etat as $cle => $couleur ) {
						if ( $dossiers['total'] && $dossiers['statuts'][ $cle ] ) {
							$parts_anneau[] = array( 'cle' => $cle, 'valeur' => round( 100 * $dossiers['statuts'][ $cle ] / $dossiers['total'], 2 ), 'couleur' => $couleur );
						}
					}
					$anneau_repli = '<svg viewBox="0 0 200 200" class="compteurs__repli" aria-hidden="true"><circle cx="100" cy="100" r="76" fill="none" stroke="var(--filet)" stroke-width="25"/><g transform="rotate(-90 100 100)">';
					$depart       = 0;
					foreach ( $parts_anneau as $part ) {
						$anneau_repli .= sprintf( '<circle cx="100" cy="100" r="76" fill="none" pathLength="100" stroke="%s" stroke-width="25" stroke-dasharray="%s %s" stroke-dashoffset="%s"/>', esc_attr( $part['couleur'] ), $part['valeur'], 100 - $part['valeur'], -$depart );
						$depart       += $part['valeur'];
					}
					$anneau_repli .= '</g></svg>';
					?>

					<div class="registre-quitus" data-registre-quitus>
						<?php if ( $attente && 'recu_envoye' !== $filtres['statut'] ) : /* liste déjà filtrée sur les reçus à valider : pas de bandeau */ ?>
								<div class="attente">
									<span class="attente__icone" aria-hidden="true"><?php echo ueb_icone( 'tampon', 20 ); ?><span class="attente__nombre"><?php echo (int) $attente; ?></span></span>
									<div class="attente__texte">
										<p class="attente__titre"><a class="attente__lien" href="<?php echo $url_liste( array( 'statut' => 'recu_envoye' ) ); ?>"><?php echo esc_html( sprintf( '%d %s en attente', $attente, 1 === $attente ? 'validation' : 'validations' ) ); ?></a></p>
										<p><?php echo esc_html( sprintf( 'Dans %d %s. Le plus ancien reçu attend depuis %s.', (int) $dossiers['attente']->dossiers, 1 === (int) $dossiers['attente']->dossiers ? 'dossier' : 'dossiers', $depuis ) ); ?></p>
									</div>
									<span class="attente__voir" aria-hidden="true">Afficher les reçus à valider<?php echo ueb_icone( 'chevron-d', 18 ); ?></span>
									<?php if ( $plus_ancien ) : ?>
										<a class="attente__second" href="<?php echo $ici( array( 'quitus' => $plus_ancien->id ) ); ?>">Ouvrir le plus ancien</a>
									<?php endif; ?>
								</div>
						<?php endif; ?>

						<nav class="compteurs" aria-label="Dossiers par statut">
							<a class="compteurs__carte compteurs__carte--tous" href="<?php echo $url_liste( array( 'statut' => null ) ); ?>"<?php echo '' === $filtres['statut'] ? ' aria-current="page"' : ''; ?>>
								<span class="compteurs__libelle">Tous les dossiers</span>
								<span class="compteurs__corps">
									<span class="compteurs__nombre"><span data-compte="<?php echo (int) $dossiers['total']; ?>" data-cle="tous"><?php echo (int) $dossiers['total']; ?></span><span class="sr"> dossiers</span></span>
									<?php if ( $parts_anneau ) : ?>
										<span class="compteurs__anneau" aria-hidden="true"><span class="animation" data-remotion-differe="donut" data-props="<?php echo esc_attr( wp_json_encode( array( 'parts' => $parts_anneau, 'piste' => 'var(--filet)' ) ) ); ?>"><span class="animation__scene" data-remotion-scene><?php echo $anneau_repli; // phpcs:ignore -- construit et échappé ci-dessus ?></span></span></span>
									<?php endif; ?>
								</span>
							</a>
							<?php foreach ( $etats as $cle => $etat ) : ?>
								<a class="compteurs__carte compteurs__carte--<?php echo esc_attr( $cle ); ?>" href="<?php echo $url_liste( array( 'statut' => $cle ) ); ?>"<?php echo $cle === $filtres['statut'] ? ' aria-current="page"' : ''; ?>>
									<span class="compteurs__libelle"><span class="compteurs__pastille" aria-hidden="true"><?php echo ueb_icone( $etat['icone'], 14 ); ?></span><?php echo esc_html( $etat['compteur'] ); ?></span>
									<span class="compteurs__nombre"><span data-compte="<?php echo (int) $dossiers['statuts'][ $cle ]; ?>" data-cle="<?php echo esc_attr( $cle ); ?>"><?php echo (int) $dossiers['statuts'][ $cle ]; ?></span><span class="sr"> dossiers</span></span>
									<span class="compteurs__note"><?php echo esc_html( $etat['note'] ); ?></span>
								</a>
							<?php endforeach; ?>
						</nav>
						<script>
						/* Avant le premier affichage : arrivée d'ailleurs (les compteurs partent de
						   zéro, les lignes arrivent en cascade) ou depuis le registre lui-même
						   (filtre, carte, validation : les compteurs partent des anciennes valeurs).
						   Rien ne bouge si l'agent a demandé de réduire les animations. */
						( function () {
							try {
								var racine = document.querySelector( '[data-registre-quitus]' );
								var ref = document.referrer ? new URL( document.referrer ) : null;
								var interne = !! ref && ref.pathname === location.pathname && ref.searchParams.get( 'vue' ) === 'quitus' && ! ref.searchParams.has( 'quitus' );
								racine.dataset.arrivee = interne ? 'interne' : 'entree';
								if ( matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) { return; }
								if ( ! interne ) { racine.classList.add( 'est-entree' ); }
								var anciens = interne ? JSON.parse( sessionStorage.getItem( 'ueb-registre-comptes' ) || '{}' ) : {};
								var nombres = racine.querySelectorAll( '[data-compte]' );
								nombres.forEach( function ( el ) {
									var depart = interne ? anciens[ el.dataset.cle ] : 0;
									if ( depart === undefined || +depart === +el.dataset.compte ) { return; }
									el.dataset.depart = depart;
									el.textContent = depart;
								} );
								/* Filet de sécurité : les vraies valeurs reviennent quoi qu'il arrive. */
								setTimeout( function () { nombres.forEach( function ( el ) { el.textContent = el.dataset.compte; } ); }, 2500 );
							} catch ( e ) {}
						} )();
						</script>

						<section class="carte registre registre--quitus" aria-label="<?php echo esc_attr( $libelle_statut ? 'Dossiers ' . mb_strtolower( $libelle_statut ) : 'Dossiers de l’année' ); ?>">
							<form class="registre__filtres" method="get" action="<?php echo esc_url( ueb_url_scolarite() ); ?>" role="search" data-filtres-registre><?php ueb_champ_espace(); ?>
								<input type="hidden" name="vue" value="quitus">
								<?php if ( $filtres['type'] ) : ?><input type="hidden" name="type" value="<?php echo esc_attr( $filtres['type'] ); ?>"><?php endif; ?>
								<?php if ( $filtres['statut'] ) : ?><input type="hidden" name="statut" value="<?php echo esc_attr( $filtres['statut'] ); ?>"><?php endif; ?>
								<label class="sr" for="f-q">Rechercher un dossier</label>
								<span class="registre__champ">
									<?php echo ueb_icone( 'loupe', 18 ); ?>
									<input id="f-q" type="search" name="q" value="<?php echo esc_attr( $filtres['q'] ); ?>" placeholder="N° de quitus, matricule, nom…" enterkeyhint="search" autocomplete="off">
									<kbd class="registre__touche" aria-hidden="true">Entrée</kbd>
								</span>
								<?php foreach ( $selects as $nom => list( $tous, $choix ) ) : if ( ! $choix ) { continue; } ?>
									<label class="sr" for="f-<?php echo esc_attr( $nom ); ?>"><?php echo esc_html( $tous ); ?></label>
									<span class="registre__select<?php echo '' !== $filtres[ $nom ] ? ' est-actif' : ''; ?>">
										<select id="f-<?php echo esc_attr( $nom ); ?>" name="<?php echo esc_attr( $nom ); ?>">
											<option value=""><?php echo esc_html( $tous ); ?></option>
											<?php foreach ( $choix as $valeur => $libelle ) : ?>
												<option value="<?php echo esc_attr( $valeur ); ?>" <?php selected( 0 === strcasecmp( (string) $valeur, $filtres[ $nom ] ) ); ?>><?php echo esc_html( $libelle ); ?></option>
											<?php endforeach; ?>
										</select><?php echo ueb_icone( 'chevron', 16 ); ?>
									</span>
								<?php endforeach; ?>
								<button class="btn btn--fantome btn--petit registre__filtrer" type="submit">Filtrer</button>
								<?php if ( $actifs ) : ?>
									<a class="registre__effacer" href="<?php echo $ici( array( 'vue' => 'quitus', 'type' => $filtres['type'], 'statut' => $filtres['statut'] ?: null ) ); ?>"><?php echo ueb_icone( 'croix', 15 ); ?>Effacer les filtres</a>
								<?php endif; ?>
							</form>

							<?php if ( ! $liste['lignes'] ) : ?>
								<div class="registre-vide">
									<span class="registre-vide__icone" aria-hidden="true"><?php echo ueb_icone( $actifs ? 'loupe' : 'recu', 24 ); ?></span>
									<?php if ( $actifs ) : ?>
										<p class="registre-vide__titre">Aucun dossier ne correspond à ces filtres<?php echo $libelle_statut ? esc_html( ' parmi les « ' . mb_strtolower( $libelle_statut ) . ' »' ) : ''; ?></p>
										<p>Retire un filtre, ou cherche par numéro de quitus, matricule ou nom de famille.</p>
										<div class="registre-vide__actions">
											<a class="btn btn--fantome btn--petit" href="<?php echo $ici( array( 'vue' => 'quitus', 'statut' => $filtres['statut'] ?: null ) ); ?>"><?php echo ueb_icone( 'croix', 16 ); ?>Effacer les filtres</a>
											<?php if ( $libelle_statut ) : ?><a class="btn btn--lien btn--petit" href="<?php echo $url_liste( array( 'statut' => null ) ); ?>">Chercher dans tous les dossiers</a><?php endif; ?>
										</div>
									<?php elseif ( $libelle_statut ) : ?>
										<p class="registre-vide__titre">Aucun dossier « <?php echo esc_html( mb_strtolower( $libelle_statut ) ); ?> » pour le moment</p>
										<p><?php echo esc_html( $etats[ $filtres['statut'] ]['vide'] ); ?></p>
										<div class="registre-vide__actions"><a class="btn btn--fantome btn--petit" href="<?php echo $ici( array( 'vue' => 'quitus' ) ); ?>">Voir tous les dossiers</a></div>
									<?php else : ?>
										<p class="registre-vide__titre">Aucun quitus pour l’année <?php echo esc_html( $annee['libelle'] ); ?></p>
										<p>Les dossiers apparaîtront ici dès que les étudiants auront généré leurs quitus.</p>
									<?php endif; ?>
								</div>
							<?php else : ?>
								<?php if ( $actifs ) : ?>
									<p class="registre__resultat"><span><b><?php echo (int) $liste['total']; ?></b> <?php echo 1 === (int) $liste['total'] ? 'dossier correspond' : 'dossiers correspondent'; ?> aux filtres<?php echo $filtres['q'] ? ' et à « <b>' . esc_html( $filtres['q'] ) . '</b> »' : ''; ?></span></p>
								<?php endif; ?>
								<div class="tableau-conteneur">
									<table class="tableau registre__tableau">
										<thead><tr><th scope="col">Étudiant</th><th scope="col">Paiements</th><th scope="col" class="num">Montant</th><th scope="col" class="num">Reçus</th><th scope="col" class="registre__c-action"><span class="sr">Décision</span></th><th scope="col">Statut</th></tr></thead>
										<tbody>
										<?php foreach ( $liste['lignes'] as $rang => $d ) :
											$p0        = $d->principal;
											$nom       = trim( $p0->nom . ' ' . $p0->prenom );
											$url       = $ici( array( 'quitus' => $p0->id, 'type' => $filtres['type'] ) );
											$total     = array_sum( array_map( static fn( $q ) => (int) $q->montant, $d->paiements ) );
											$nb_recus  = array_sum( array_map( static fn( $q ) => (int) $q->nb_recus, $d->paiements ) );
											$abr       = static fn( $q ) => $abreviations[ 'medicaux' === $q->type ? 'medicaux' : 'droits' ][0];
											/* Les paiements dont le statut diffère de celui du dossier, en abrégé. */
											$autres    = array_filter( $d->paiements, static fn( $q ) => $q->statut !== $d->statut );
											/* Ce que « Valider » enregistre : les paiements dont le reçu attend. */
											$a_valider = array_values( array_filter( $d->paiements, static fn( $q ) => 'recu_envoye' === $q->statut && (int) $q->nb_recus > 0 ) );
											$a_valider = array_values( array_filter( $a_valider, 'ueb_peut_decider_quitus' ) );
											$valider   = (bool) $a_valider;
											if ( $valider ) {
												$detail = implode( ' et ', array_map( static fn( $q ) => mb_strtolower( ueb_libelle_type_quitus( $q->type ) ) . ' (' . ueb_fcfa( $q->montant ) . ')', $a_valider ) );
												$deux   = count( $a_valider ) > 1;
												$bouton = count( $d->paiements ) > 1 ? 'Valider ' . implode( ' et ', array_map( $abr, $a_valider ) ) : 'Valider';
											}
											?>
											<tr class="registre__dossier registre__dossier--<?php echo esc_attr( $d->statut ); ?>" id="dossier-<?php echo (int) $p0->id; ?>" style="--i: <?php echo (int) min( $rang, 12 ); ?>" data-href="<?php echo $url; ?>">
												<td class="registre__c-qui">
													<span class="registre__qui">
														<span class="bo-avatar" aria-hidden="true"><?php echo esc_html( ueb_initiales( $p0->prenom, $p0->nom ) ); ?></span>
														<span><a class="registre__lien" href="<?php echo $url; ?>" title="<?php echo esc_attr( $nom ); ?>"><?php echo esc_html( $nom ); ?></a><small><?php echo esc_html( $p0->identifiant ); ?></small></span>
													</span>
												</td>
												<td class="registre__c-paiements">
													<ul class="registre__paiements">
														<?php foreach ( $d->paiements as $q ) :
															$type_q   = 'medicaux' === ( $q->type ?? 'droits' ) ? 'medicaux' : 'droits';
															$modalite = 'medicaux' === $type_q ? 'Paiement unique' : ueb_libelle_tranche( $q->tranche ); ?>
															<li><span class="registre__numero"><?php echo esc_html( $q->numero ); ?></span><?php if ( $modalite ) : ?><small><?php echo esc_html( $modalite ); ?></small><?php endif; ?></li>
														<?php endforeach; ?>
													</ul>
												</td>
												<td class="num registre__montant"><?php echo esc_html( ueb_formater_montant( $total ) ); ?> <small>FCFA</small><?php if ( count( $d->paiements ) > 1 ) : ?><span class="registre__detail"><?php echo esc_html( implode( ' + ', array_map( static fn( $q ) => ueb_formater_montant( $q->montant ), $d->paiements ) ) ); ?></span><?php endif; ?></td>
												<td class="num registre__c-recus"><?php if ( $nb_recus ) : ?><span class="registre__recus" title="<?php echo esc_attr( implode( ', ', array_map( static fn( $q ) => $abr( $q ) . ' : ' . (int) $q->nb_recus, $d->paiements ) ) ); ?>"><?php echo ueb_icone( 'recu', 15 ); ?><?php echo (int) $nb_recus; ?><span class="registre__recus-mot"><?php echo 1 === $nb_recus ? ' reçu' : ' reçus'; ?></span></span><?php else : ?><span class="registre__aucun">—<span class="sr">Aucun reçu</span></span><?php endif; ?></td>
												<td class="registre__c-action">
													<?php if ( $valider ) : ?>
														<form method="post" action="<?php echo esc_url( ueb_url_scolarite() ); ?>" class="registre__valider" data-registre-valider
															data-confirmer="<?php echo esc_attr( sprintf( $deux ? 'Les paiements de %1$s seront marqués comme vérifiés : %2$s. Valide seulement après avoir contrôlé les originaux des reçus.' : 'Le paiement de %1$s sera marqué comme vérifié : %2$s. Valide seulement après avoir contrôlé l’original du reçu.', $nom, $detail ) ); ?>"
															data-confirmer-titre="<?php echo $deux ? 'Valider les deux paiements ?' : 'Valider ce paiement ?'; ?>" data-confirmer-bouton="<?php echo $deux ? 'Valider les deux' : 'Valider le paiement'; ?>" data-confirmer-ton="enregistrer">
															<?php ueb_champ_csrf(); ?>
															<input type="hidden" name="ueb_action" value="gestion_valider">
															<input type="hidden" name="quitus_id" value="<?php echo (int) $p0->id; ?>">
															<?php foreach ( array_merge( array( 'statut' => $filtres['statut'], 'p' => $liste['page'] > 1 ? $liste['page'] : '' ), $actifs ) as $champ => $valeur ) : if ( '' === (string) $valeur ) { continue; } ?>
																<input type="hidden" name="retour[<?php echo esc_attr( $champ ); ?>]" value="<?php echo esc_attr( $valeur ); ?>">
															<?php endforeach; ?>
															<button class="registre__bouton-valider" type="submit"><?php echo ueb_icone( 'tampon', 16 ); ?><?php echo esc_html( $bouton ); ?><span class="sr"> <?php echo esc_html( ( $deux ? 'les paiements de ' : 'le paiement de ' ) . $nom ); ?></span></button>
															<div class="registre__tampon" data-registre-tampon hidden>
																<div class="animation" data-remotion-differe="tampon" data-props="<?php echo esc_attr( wp_json_encode( array( 'etat' => 'verifie', 'sigle' => ueb_etablissement( $p0->etablissement )['sigle'] ?? $p0->etablissement, 'date' => $aujourdhui ) ) ); ?>" role="img" aria-label="Tampon : paiement vérifié"><div class="animation__scene" data-remotion-scene></div></div>
															</div>
														</form>
													<?php endif; ?>
												</td>
												<td class="registre__c-statut">
													<span class="badge badge--<?php echo esc_attr( $d->statut ); ?> registre__statut"><?php echo ueb_icone( $etats[ $d->statut ]['icone'] ?? 'info', 13 ); ?><?php echo esc_html( $etats[ $d->statut ]['pastille'] ?? $d->statut ); ?></span>
													<?php if ( $autres ) : ?><span class="registre__detail"><?php echo esc_html( implode( ', ', array_map( static fn( $q ) => $abr( $q ) . ' ' . mb_strtolower( $etats[ $q->statut ]['pastille'] ?? $q->statut ), $autres ) ) ); ?></span><?php endif; ?>
												</td>
											</tr>
										<?php endforeach; ?>
										</tbody>
									</table>
								</div>
								<footer class="registre__pied">
									<p><b><?php echo (int) $liste['total']; ?></b> <?php echo 1 === (int) $liste['total'] ? 'dossier' : 'dossiers'; ?><?php echo $liste['pages'] > 1 ? ', page ' . (int) $liste['page'] . ' sur ' . (int) $liste['pages'] : ''; ?></p>
									<?php if ( $liste['pages'] > 1 ) :
										/* Première et dernière pages, la courante et ses voisines ; « … » entre deux trous. */
										$courante = (int) $liste['page'];
										$derniere = (int) $liste['pages'];
										$url_page = static fn( $n ) => $url_liste( array( 'p' => $n ) );
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
					</div>

				<?php endif; ?>
			</div>
		</div>

	<?php endif; ?>

</main>
<?php
ueb_page_fin( 'gestion' );
