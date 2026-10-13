<?php
/**
 *
 * Espace de l'administrateur d'un IPES (établissement privé sous tutelle).
 * Accès : rôle « ueb_admin_ipes », compte non suspendu, IPES actif
 * (inc/ipes-espace.php). Vues par ?vue= :
 *   - bord (défaut) : reversements de l'année (héros et jauge), à faire, derniers bordereaux ;
 *   - etudiants     : les étudiants de l'année, chacun à reverser à la tutelle de sa filière ;
 *   - bordereaux    : les reversements à la tutelle ;
 *   - securite      : son propre mot de passe.
 *
 * L'IPES affiché est toujours celui du compte connecté. Chaque action est
 * revérifiée côté serveur.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

/* ---------- Connexion, avant tout affichage ---------- */
$erreur_connexion = '';
if ( isset( $_POST['ueb_connexion_ipes'] ) ) {
	if ( ! isset( $_POST['ueb_connexion_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['ueb_connexion_nonce'] ) ), 'ueb_connexion_ipes' ) ) {
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
		} elseif ( ! ueb_ipes_du_compte( $utilisateur->ID ) ) {
			wp_logout();
			$erreur_connexion = "Ce compte n'a pas accès à un espace IPES actif.";
		} else {
			ueb_reinitialiser_echecs_gestion( $identifiant );
			wp_set_current_user( $utilisateur->ID );
			wp_set_auth_cookie( $utilisateur->ID, false, is_ssl() );
			wp_safe_redirect( ueb_url_espace_ipes() );
			exit;
		}
	}
}

$ipes     = ueb_ipes_du_compte();
$autorise = (bool) $ipes;

if ( $autorise ) {
	nocache_headers();
	$vue   = sanitize_key( $_GET['vue'] ?? 'bord' );
	$vue   = in_array( $vue, array( 'bord', 'etudiants', 'bordereaux', 'securite' ), true ) ? $vue : 'bord';
	$ici   = static fn( array $args = array() ) => esc_url( add_query_arg( $args, ueb_url_espace_ipes() ) );
	$url   = static fn( array $args = array() ) => add_query_arg( $args, ueb_url_espace_ipes() ); // adresse brute, pour ueb_adm_action()
	$annee = ueb_annee_academique();
}

/* Connecté : coque de l'administration (barre du haut, panneaux, thème clair /
   sombre mémorisé) ; l'écran de connexion garde l'en-tête du site. */
ueb_page_debut( array(
	'titre'    => 'Espace IPES',
	'variante' => $autorise ? 'bo' : 'gestion',
	'classe'   => $autorise ? 'espace-admin espace-ipes' : '',
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
					<h1 id="titre-connexion">Espace IPES</h1>
					<p class="bo-connexion__intro">Pour les établissements privés placés sous la tutelle de l’UEb.</p>
					<ul class="bo-connexion__points">
						<li><?php echo ueb_icone( 'utilisateur', 17 ); ?>Déclarer ses étudiants inscrits</li>
						<li><?php echo ueb_icone( 'recu', 17 ); ?>Préparer les bordereaux de reversement</li>
						<li><?php echo ueb_icone( 'tampon', 17 ); ?>Suivre leur vérification par l’UEb</li>
					</ul>
				</aside>
				<section class="bo-connexion__formulaire" aria-labelledby="titre-connexion">
					<h2>Connexion</h2>
					<p class="bo-connexion__aide">Avec l’identifiant et le mot de passe communiqués par l’administration de l’UEb.</p>
					<?php if ( is_user_logged_in() ) : ?><?php ueb_alerte( 'erreur', "Ce compte n'a pas accès à un espace IPES actif." ); ?><?php endif; ?>
					<?php if ( $erreur_connexion ) : ?><?php ueb_alerte( 'erreur', $erreur_connexion ); ?><?php endif; ?>
					<?php ueb_afficher_flash(); ?>
					<form class="formulaire" method="post" action="<?php echo esc_url( get_permalink() ); ?>" data-formulaire novalidate>
						<?php wp_nonce_field( 'ueb_connexion_ipes', 'ueb_connexion_nonce' ); ?>
						<input type="hidden" name="ueb_connexion_ipes" value="1">
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
				'Espace IPES',
				array(
					array( 'url' => $ici(), 'libelle' => 'Tableau de bord', 'icone' => 'tableau', 'actif' => 'bord' === $vue ),
					array( 'url' => $ici( array( 'vue' => 'etudiants' ) ), 'libelle' => 'Étudiants', 'icone' => 'groupe', 'actif' => 'etudiants' === $vue ),
					array( 'url' => $ici( array( 'vue' => 'bordereaux' ) ), 'libelle' => 'Bordereaux', 'icone' => 'recu', 'actif' => 'bordereaux' === $vue ),
					array( 'url' => $ici( array( 'vue' => 'securite' ) ), 'libelle' => 'Sécurité', 'icone' => 'cadenas', 'actif' => 'securite' === $vue ),
				),
				array(
					'titre' => 1 === count( $ipes->tutelles ) ? 'Tutelle' : 'Tutelles',
					'note'  => implode( ', ', array_map( static fn( $s ) => ueb_etablissement( $s )['fr'] ?? $s, $ipes->tutelles ) ),
				),
				array(
					'nom'  => $ipes->sigle,
					'note' => 'Sous tutelle de l’UEb',
					'url'  => ueb_url_espace_ipes(),
					'logo' => ueb_ipes_logo_url( $ipes ),
				)
			);
			?>

			<div class="bo-contenu adm">

				<?php if ( 'securite' === $vue ) : ?>

					<?php
					ueb_adm_tete( array(
						'titre'      => 'Sécurité',
						'sous_titre' => 'Le mot de passe de ton accès à l’espace de ' . $ipes->sigle . '. Ne le communique à personne.',
					) );
					ueb_afficher_flash();
					?>
					<?php
					ueb_bloc_mot_de_passe( array(
						'action'  => ueb_url_espace_ipes(),
						'titre'   => 'Ton accès engage ' . $ipes->sigle,
						'conseil' => array( 'ecole', 'Ce compte déclare les étudiants et les reversements', ' de ' . $ipes->sigle . '.' ),
						'oubli'   => 'L’administration de l’UEb t’en créera un nouveau, provisoire.',
					) );
					?>

				<?php else : ?>

					<?php
					/* Chaque vue dispose de $ipes (celui du compte), $annee, $ici (échappée) et $url (brute). */
					include UEB_INSC_DIR . '/templates/composants/ipes-espace-' . $vue . '.php';
					?>

				<?php endif; ?>

			</div>
		</div>

	<?php endif; ?>

</main>
<?php
ueb_page_fin( 'gestion' );
