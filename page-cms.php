<?php
/**
 * Template Name: Centre médico-social
 *
 * Espace du Centre médico-social (CMS) : il reçoit les reçus des frais
 * médicaux de toute l'université, à la place de la scolarité. Même poste de
 * travail que la scolarité, avec trois vues dans la barre latérale :
 *   - bord     : tableau de bord des frais médicaux ;
 *   - quitus   : registre des quitus de frais médicaux, fiche d'un dossier,
 *                validation ou refus avec un motif ;
 *   - securite : mot de passe, sessions ouvertes et dernières connexions.
 *
 * Accès : capacité UEB_CAP_MEDICAUX (inc/cms.php), portée du rôle (tous les
 * établissements par défaut, avec le sélecteur de la barre latérale), compte
 * non suspendu. Chaque action est revérifiée côté serveur.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

/* ---------- Connexion, avant tout affichage ---------- */
$erreur_connexion = '';
if ( isset( $_POST['ueb_connexion_cms'] ) ) {
	if ( ! isset( $_POST['ueb_connexion_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['ueb_connexion_nonce'] ) ), 'ueb_connexion_cms' ) ) {
		$erreur_connexion = 'Ta session a expiré. Recommence.';
	} else {
		$identifiant = sanitize_text_field( wp_unslash( $_POST['identifiant'] ?? '' ) );
		/* L'adresse e-mail du compte est acceptée, comme à la scolarité. */
		if ( is_email( $identifiant ) ) {
			$par_email = get_user_by( 'email', $identifiant );
			if ( $par_email ) {
				$identifiant = $par_email->user_login;
			}
		}
		$identifiant = sanitize_user( $identifiant, true );
		$utilisateur = ueb_connexion_gestion_bloquee( $identifiant ) ? new WP_Error( 'ueb_rate_limited' ) : wp_signon( array(
			'user_login'    => $identifiant,
			'user_password' => (string) wp_unslash( $_POST['mot_de_passe'] ?? '' ),
			'remember'      => false,
		), is_ssl() );
		if ( is_wp_error( $utilisateur ) ) {
			ueb_noter_echec_gestion( $identifiant );
			$erreur_connexion = ueb_message_echec_connexion( $utilisateur, 'Identifiant ou mot de passe incorrect. Vérifie l’identifiant communiqué par l’administration.' );
		} elseif ( ! ueb_est_cms( $utilisateur->ID ) ) {
			wp_logout();
			$erreur_connexion = "Ce compte n'a pas accès à l'espace du Centre médico-social.";
		} else {
			ueb_reinitialiser_echecs_gestion( $identifiant );
			wp_set_current_user( $utilisateur->ID );
			wp_set_auth_cookie( $utilisateur->ID, false, is_ssl() );
			wp_safe_redirect( ueb_url_cms() );
			exit;
		}
	}
}

/* L'administrateur peut aussi ouvrir l'espace, pour le suivre. */
$autorise = ueb_peut( UEB_CAP_MEDICAUX );
$annee    = ueb_annee_academique();

if ( $autorise ) {
	nocache_headers();
	$espace     = ueb_espace_verification( 'cms' );
	$etab_agent = ueb_etab_agent();
	$etab       = $etab_agent && UEB_AUCUN_ETAB !== $etab_agent ? ueb_etablissement( $etab_agent ) : null;
	$vue        = sanitize_key( $_GET['vue'] ?? 'bord' );
	$vue        = in_array( $vue, array( 'bord', 'quitus', 'securite' ), true ) ? $vue : 'bord';
	/* Un lien vers les droits d'un dossier ouvre ses frais médicaux. */
	$fiche      = isset( $_GET['quitus'] ) ? ueb_quitus_de_l_espace( $espace, (int) $_GET['quitus'] ) : null;
	if ( $fiche ) {
		$vue = 'quitus';
	}
	$ici     = static fn( array $args = array() ) => esc_url( add_query_arg( $args, ueb_url_cms() ) );
	$url     = static fn( array $args = array() ) => add_query_arg( $args, ueb_url_cms() ); // adresse brute
	$tableau = 'bord' === $vue;
	$stats   = ueb_gestion_stats( $annee['code'], $etab_agent, 'medicaux' );
	$a_verifier = (int) ( $stats['statuts']['recu_envoye'] ?? 0 );
}

ueb_page_debut( array(
	'titre'    => 'Centre médico-social',
	'variante' => $autorise ? 'bo' : 'gestion',
	'classe'   => $autorise ? 'espace-admin espace-cms' . ( 'securite' === $vue ? ' espace-cms--securite' : '' ) : '',
) );
?>
<main id="contenu" class="page-app gestion<?php echo $autorise ? ' page-app--bo' : ''; ?>">

	<?php if ( ! $autorise ) : ?>

		<div class="conteneur">
			<div class="bo-connexion">
				<aside class="bo-connexion__volet">
					<?php ueb_animation( 'embleme', ueb_props_embleme(), 'animation--embleme bo-connexion__embleme', 'Sceau de l’Université d’Ebolowa' ); ?>
					<p class="bo-connexion__marque">Université d’Ebolowa</p>
					<h1 id="titre-connexion">Centre médico-social</h1>
					<p class="bo-connexion__intro">Le poste de vérification des frais médicaux pour les inscriptions <?php echo esc_html( $annee['libelle'] ); ?>.</p>
					<ul class="bo-connexion__points">
						<li><?php echo ueb_icone( 'recu', 17 ); ?>Recevoir les reçus des frais médicaux</li>
						<li><?php echo ueb_icone( 'tampon', 17 ); ?>Valider les paiements après contrôle des originaux</li>
						<li><?php echo ueb_icone( 'alerte', 17 ); ?>Refuser un reçu en expliquant le motif</li>
					</ul>
				</aside>
				<section class="bo-connexion__formulaire" aria-labelledby="titre-connexion">
					<h2>Connexion</h2>
					<p class="bo-connexion__aide">Utilise l’identifiant communiqué par l’administration de la plateforme.</p>
					<?php if ( is_user_logged_in() ) : ?><?php ueb_alerte( 'erreur', "Ce compte n'a pas accès à l'espace du Centre médico-social." ); ?><?php endif; ?>
					<?php if ( $erreur_connexion ) : ?><?php ueb_alerte( 'erreur', $erreur_connexion ); ?><?php endif; ?>
					<?php ueb_afficher_flash(); ?>
					<form class="formulaire" method="post" action="<?php echo esc_url( get_permalink() ); ?>" data-formulaire novalidate>
						<?php wp_nonce_field( 'ueb_connexion_cms', 'ueb_connexion_nonce' ); ?>
						<input type="hidden" name="ueb_connexion_cms" value="1">
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
				'Centre médico-social',
				array(
					array( 'url' => $ici(), 'libelle' => 'Tableau de bord', 'icone' => 'tableau', 'actif' => 'bord' === $vue ),
					array( 'url' => $ici( array( 'vue' => 'quitus' ) ), 'libelle' => 'Liste des quitus', 'icone' => 'recu', 'actif' => 'quitus' === $vue ),
					array( 'url' => $ici( array( 'vue' => 'securite' ) ), 'libelle' => 'Sécurité', 'icone' => 'cadenas', 'actif' => 'securite' === $vue ),
				),
				array(
					'titre' => $etab ? $etab['sigle'] : 'Université',
					'note'  => $etab ? $etab['fr'] : 'Tous les établissements',
				)
			);
			?>

			<div class="bo-contenu<?php echo $tableau ? ' adm adm-dashboard' : ( 'securite' === $vue ? ' adm' : '' ); ?>">

				<?php if ( $tableau ) : ?>

					<?php require UEB_INSC_DIR . '/templates/composants/cms-tableau.php'; ?>

				<?php elseif ( 'securite' === $vue ) : ?>

					<?php
					ueb_adm_tete( array(
						'titre'      => 'Sécurité',
						'sous_titre' => 'Ton accès au Centre médico-social : ton mot de passe, les appareils connectés et tes dernières connexions.',
						'theme'      => false,
					) );
					ueb_afficher_flash();
					require UEB_INSC_DIR . '/templates/composants/securite-personnel.php';
					?>

				<?php elseif ( $fiche ) : ?>

					<?php ueb_afficher_flash(); ?>
					<?php require UEB_INSC_DIR . '/templates/composants/scolarite-quitus.php'; ?>

				<?php elseif ( 'quitus' === $vue ) : ?>

					<header class="bo-entete">
						<div class="bo-entete__texte">
							<p class="bo-entete__contexte">
								<?php echo ueb_icone( 'stethoscope', 20 ); ?>
								<span><?php echo esc_html( $etab ? $etab['sigle'] : 'Tous les établissements' ); ?></span>
								<span class="bo-entete__annee">Année <?php echo esc_html( $annee['libelle'] ); ?></span>
							</p>
							<h1>Liste des quitus</h1>
							<p class="bo-entete__sous-titre">Les quitus de frais médicaux. Compare chaque reçu à son original, puis valide le paiement ou refuse-le en donnant le motif.</p>
						</div>
					</header>
					<?php ueb_afficher_flash(); ?>
					<?php require UEB_INSC_DIR . '/templates/composants/registre-quitus.php'; ?>

				<?php endif; ?>

			</div>
		</div>

	<?php endif; ?>

</main>
<?php
ueb_page_fin( 'gestion' );
