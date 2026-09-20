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
		} elseif ( ! user_can( $utilisateur, UEB_CAP_GESTION ) || ! ueb_est_scolarite( $utilisateur->ID ) || ueb_agent_suspendu( $utilisateur->ID ) ) {
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

$autorise = is_user_logged_in() && ueb_est_scolarite() && current_user_can( UEB_CAP_GESTION ) && ! ueb_agent_suspendu();
$annee    = ueb_annee_academique();

if ( $autorise ) {
	$etab_agent = ueb_etab_agent();
	$etab       = $etab_agent ? ueb_etablissement( $etab_agent ) : null;
	$vue        = sanitize_key( $_GET['vue'] ?? 'bord' );
	$fiche      = isset( $_GET['quitus'] ) ? ueb_quitus_par_id( (int) $_GET['quitus'] ) : null;
	if ( $fiche && ! ueb_peut_gerer_etab( $fiche->etablissement ) ) {
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
	$cellules = array_values( array_filter( ueb_agents_cellule(), static fn( $cellule ) => ueb_etab_agent( $cellule->ID ) === $etab_agent ) );
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
		<div class="espace-connexion">
			<section class="carte carte__corps" aria-labelledby="titre-connexion">
				<h1 id="titre-connexion">Espace scolarité</h1>
				<p class="page-app__sous-titre">Réservé à la scolarité de l’établissement.</p>

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
				<p class="champ__aide">Mot de passe oublié ? L'administrateur de la plateforme peut t'en donner un nouveau.</p>
			</section>
		</div>
		</div>

	<?php else : ?>

		<div class="bo">
			<?php
			ueb_bo_barre(
				'Espace scolarité',
				array(
					array( 'url' => $ici(), 'libelle' => 'Tableau de bord', 'icone' => 'tampon', 'actif' => 'bord' === $vue ),
					array( 'url' => $ici( array( 'vue' => 'quitus' ) ), 'libelle' => 'Quitus', 'icone' => 'recu', 'actif' => 'quitus' === $vue ),
					array( 'url' => $ici( array( 'vue' => 'cellule' ) ), 'libelle' => 'Cellule informatique', 'icone' => 'cle', 'actif' => 'cellule' === $vue ),
					array( 'url' => $ici( array( 'vue' => 'securite' ) ), 'libelle' => 'Sécurité', 'icone' => 'bouclier', 'actif' => 'securite' === $vue ),
				),
				array(
					'titre' => $etab ? $etab['sigle'] : 'Tous',
					'note'  => $etab ? $etab['fr'] : 'Tous les établissements',
				)
			);
			?>

			<div class="bo-contenu">
				<header class="page-app__entete">
					<div>
						<h1><?php echo esc_html( 'cellule' === $vue ? 'Cellule informatique' : ( 'securite' === $vue ? 'Sécurité' : ( 'quitus' === $vue ? 'Quitus' : 'Tableau de bord' ) ) ); ?></h1>
						<p class="page-app__sous-titre"><?php echo esc_html( $etab ? $etab['fr'] : 'Tous les établissements' ); ?> · année <?php echo esc_html( $annee['libelle'] ); ?></p>
					</div>
				</header>
				<?php ueb_afficher_flash(); ?>

				<?php if ( 'securite' === $vue ) : ?>
					<section class="carte section-form bo-securite" aria-labelledby="titre-securite-personnel">
						<header class="section-form__entete"><span class="section-form__num"><?php echo ueb_icone( 'bouclier', 18 ); ?></span><div><h2 id="titre-securite-personnel">Modifier mon mot de passe</h2><p>Remplace le mot de passe initial communiqué par l’administration.</p></div></header>
						<div class="section-form__corps"><form class="formulaire bo-securite-form" method="post" action="<?php echo esc_url( ueb_url_scolarite() ); ?>" data-formulaire novalidate><?php ueb_champ_csrf(); ?><input type="hidden" name="ueb_action" value="gestion_changer_mdp_personnel"><?php ueb_champ( array( 'nom' => 'mot_de_passe_actuel', 'libelle' => 'Mot de passe actuel', 'type' => 'password', 'icone' => 'cadenas', 'attrs' => array( 'autocomplete' => 'current-password' ) ) ); ?><?php ueb_champ( array( 'nom' => 'mot_de_passe_nouveau', 'libelle' => 'Nouveau mot de passe', 'type' => 'password', 'icone' => 'cle', 'attrs' => array( 'autocomplete' => 'new-password', 'minlength' => 8 ) ) ); ?><?php ueb_champ( array( 'nom' => 'mot_de_passe_confirmation', 'libelle' => 'Confirmation', 'type' => 'password', 'icone' => 'cle', 'attrs' => array( 'autocomplete' => 'new-password', 'minlength' => 8 ) ) ); ?><div class="securite-form__actions"><button class="btn btn--primaire" type="submit"><?php echo ueb_icone( 'bouclier', 18 ); ?>Changer le mot de passe</button></div></form></div>
					</section>

				<?php elseif ( 'bord' === $vue ) : ?>

					<?php
					$c = ueb_gestion_chiffres( $annee['code'], $etab_agent );
					ueb_bo_palier( $c, $etab ? $etab['fr'] : 'Tous les établissements' );
					?>

				<?php elseif ( 'cellule' === $vue ) : ?>

					<?php if ( $prov_cellule ) : ?>
						<div class="provisoire carte" role="status"><?php echo ueb_icone( 'cle', 26 ); ?><div><p>Mot de passe provisoire pour <b><?php echo esc_html( $prov_cellule['compte'] ); ?></b> :</p><p class="provisoire__mdp"><?php echo esc_html( $prov_cellule['mdp'] ); ?></p><button type="button" class="btn btn--fantome btn--petit provisoire__copier" data-copier-mot-de-passe="<?php echo esc_attr( $prov_cellule['mdp'] ); ?>"><?php echo ueb_icone( 'fichier', 16 ); ?><span>Copier le mot de passe</span></button><p class="champ__aide">Communique-le à la cellule informatique de ton établissement.</p></div></div>
					<?php endif; ?>
					<section class="carte section-form bo-cellule-creation" aria-labelledby="titre-cellule">
						<header class="section-form__entete"><span class="section-form__num"><?php echo ueb_icone( 'cle', 18 ); ?></span><div><h2 id="titre-cellule">Créer un compte cellule informatique</h2><p>Ce compte pourra créer et réinitialiser les comptes étudiants de <?php echo esc_html( $etab['fr'] ); ?>.</p></div></header>
						<div class="section-form__corps formulaire"><form class="formulaire" method="post" action="<?php echo esc_url( ueb_url_scolarite() ); ?>" data-formulaire novalidate><?php ueb_champ_csrf(); ?><input type="hidden" name="ueb_action" value="gestion_creer_cellule"><div class="formulaire__rangee"><?php ueb_champ( array( 'nom' => 'login', 'libelle' => 'Identifiant de connexion', 'icone' => 'utilisateur', 'attrs' => array( 'placeholder' => 'cellule.fs', 'autocapitalize' => 'none', 'spellcheck' => 'false', 'autocomplete' => 'off' ) ) ); ?><?php ueb_champ( array( 'nom' => 'nom', 'libelle' => 'Nom du responsable', 'icone' => 'utilisateur', 'requis' => false ) ); ?></div><?php ueb_champ( array( 'nom' => 'email', 'libelle' => 'Adresse e-mail', 'type' => 'email', 'icone' => 'courriel', 'requis' => false, 'attrs' => array( 'autocomplete' => 'off' ) ) ); ?><div class="securite-form__actions"><button class="btn btn--primaire" type="submit"><?php echo ueb_icone( 'plus', 18 ); ?>Créer le compte</button></div></form></div>
					</section>
					<section class="carte section-form" aria-labelledby="titre-cellules"><header class="section-form__entete"><span class="section-form__num"><?php echo ueb_icone( 'utilisateur', 18 ); ?></span><div><h2 id="titre-cellules">Cellules rattachées</h2><p>Ces comptes sont limités à ton établissement.</p></div></header><div class="section-form__corps"><div class="tableau-conteneur"><table class="tableau"><thead><tr><th>Compte</th><th>Identifiant</th><th>État</th></tr></thead><tbody><?php if ( ! $cellules ) : ?><tr><td colspan="3" class="texte-discret">Aucun compte de cellule pour l’instant.</td></tr><?php endif; ?><?php foreach ( $cellules as $cellule ) : ?><tr><td><b><?php echo esc_html( $cellule->display_name ); ?></b></td><td><?php echo esc_html( $cellule->user_login ); ?></td><td><?php echo ueb_agent_suspendu( $cellule->ID ) ? '<span class="badge badge--rejete"><i></i>Suspendu</span>' : '<span class="badge badge--verifie"><i></i>Actif</span>'; ?></td></tr><?php endforeach; ?></tbody></table></div></div></section>

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
					?>
					<a class="fil" href="<?php echo $ici( array( 'vue' => 'quitus' ) ); ?>"><?php echo ueb_icone( 'fleche-g', 18 ); ?>Tous les quitus</a>
					<header class="page-app__entete">
						<div>
							<h2>Quitus <?php echo esc_html( $fiche->numero ); ?></h2>
							<p class="page-app__sous-titre"><?php echo esc_html( $etab_fiche['fr'] ); ?> · généré le <?php echo esc_html( mysql2date( 'j F Y à H:i', $fiche->date_creation ) ); ?></p>
						</div>
						<div class="page-app__actions">
							<?php echo ueb_badge_statut( $fiche->statut ); // phpcs:ignore ?>
							<a class="btn btn--fantome btn--petit" href="<?php echo $ici( array( 'quitus' => $fiche->id, 'pdf' => 1 ) ); ?>" target="_blank" rel="noopener"><?php echo ueb_icone( 'telecharger', 18 ); ?>PDF</a>
						</div>
					</header>

					<div class="gestion-fiche">
						<section class="carte carte__corps">
							<h3 class="section-form__titre">Informations imprimées</h3>
							<dl class="fiche">
								<div><dt><?php echo 'matricule' === $fiche->type_identifiant ? 'Matricule' : 'N° de dossier'; ?></dt><dd><?php echo esc_html( $fiche->identifiant ); ?></dd></div>
								<div><dt>Nom(s) et prénom(s)</dt><dd><?php echo esc_html( $fiche->nom . ' ' . $fiche->prenom ); ?></dd></div>
								<div><dt>Né(e) le</dt><dd><?php echo esc_html( mysql2date( 'd/m/Y', $fiche->date_naissance ) . ' à ' . $fiche->lieu_naissance ); ?></dd></div>
								<div><dt>Sexe · Nationalité</dt><dd><?php echo esc_html( $fiche->sexe . ' · ' . $fiche->nationalite ); ?></dd></div>
								<div><dt>Département</dt><dd><?php echo esc_html( $fiche->departement ); ?></dd></div>
								<div><dt>Cycle / niveau / parcours</dt><dd><?php echo esc_html( $fiche->parcours ); ?></dd></div>
								<div><dt>Montant</dt><dd><?php echo esc_html( ueb_formater_montant( $fiche->montant ) ); ?> FCFA · <?php echo esc_html( ueb_detail_quitus( $fiche ) ); ?></dd></div>
								<div><dt>Téléphone du compte</dt><dd><?php echo esc_html( $compte && $compte->telephone ? ueb_formater_telephone( $compte->telephone ) : '—' ); ?></dd></div>
							</dl>
							<?php if ( $decideur ) : ?>
								<p class="champ__aide">Dernière décision par <?php echo esc_html( $decideur->display_name ); ?> le <?php echo esc_html( mysql2date( 'j F Y à H:i', $fiche->date_verification ) ); ?>.</p>
							<?php endif; ?>
						</section>

						<section class="carte carte__corps">
							<h3 class="section-form__titre">Reçus envoyés (<?php echo count( $recus ); ?>)</h3>
							<?php if ( ! $recus ) : ?>
								<p class="texte-discret">L'étudiant n'a encore envoyé aucun reçu.</p>
							<?php else : ?>
								<div class="gestion-recus">
									<?php foreach ( $recus as $r ) : $url = ueb_url( 'recu/' . $r->id ); ?>
										<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener" class="gestion-recus__item">
											<?php if ( 'application/pdf' === $r->type_mime ) : ?>
												<span class="recus-liste__pdf"><?php echo ueb_icone( 'fichier', 28 ); ?>PDF</span>
											<?php else : ?>
												<img src="<?php echo esc_url( $url ); ?>" alt="Reçu <?php echo (int) $r->id; ?>" loading="lazy">
											<?php endif; ?>
											<span><?php echo esc_html( mysql2date( 'd/m/Y H:i', $r->date_envoi ) ); ?></span>
										</a>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>

							<h4 class="formulaire__titre">Décision après vérification physique</h4>
							<div class="decision">
								<form method="post" action="<?php echo $ici( array( 'quitus' => $fiche->id ) ); ?>">
									<?php ueb_champ_csrf(); ?>
									<input type="hidden" name="ueb_action" value="gestion_statut">
									<input type="hidden" name="quitus_id" value="<?php echo (int) $fiche->id; ?>">
									<input type="hidden" name="statut" value="verifie">
									<button class="btn btn--primaire" type="submit" <?php disabled( 'verifie', $fiche->statut ); ?>><?php echo ueb_icone( 'check', 18 ); ?>Paiement vérifié</button>
								</form>
								<form method="post" action="<?php echo $ici( array( 'quitus' => $fiche->id ) ); ?>" class="decision__rejet">
									<?php ueb_champ_csrf(); ?>
									<input type="hidden" name="ueb_action" value="gestion_statut">
									<input type="hidden" name="quitus_id" value="<?php echo (int) $fiche->id; ?>">
									<input type="hidden" name="statut" value="rejete">
									<div class="champ">
										<label for="motif">Motif si le reçu pose problème</label>
										<input id="motif" name="motif" type="text" maxlength="255" placeholder="Ex. Reçu illisible, montant différent du quitus…" value="<?php echo esc_attr( $fiche->motif_rejet ?? '' ); ?>">
									</div>
									<button class="btn btn--danger" type="submit"><?php echo ueb_icone( 'croix', 18 ); ?>Renvoyer à l'étudiant</button>
								</form>
								<?php if ( in_array( $fiche->statut, array( 'verifie', 'rejete' ), true ) && $recus ) : ?>
									<form method="post" action="<?php echo $ici( array( 'quitus' => $fiche->id ) ); ?>">
										<?php ueb_champ_csrf(); ?>
										<input type="hidden" name="ueb_action" value="gestion_statut">
										<input type="hidden" name="quitus_id" value="<?php echo (int) $fiche->id; ?>">
										<input type="hidden" name="statut" value="recu_envoye">
										<button class="btn btn--lien btn--petit" type="submit">Annuler la décision</button>
									</form>
								<?php endif; ?>
							</div>
						</section>
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
					$stats = ueb_gestion_stats( $annee['code'], $etab_agent );
					$liste = ueb_gestion_liste_quitus( $filtres );
					?>

					<nav class="compteurs" aria-label="Filtrer par statut">
						<a class="compteur<?php echo '' === $filtres['statut'] ? ' est-actif' : ''; ?>" href="<?php echo $ici( array( 'vue' => 'quitus', 'q' => $filtres['q'] ?: null ) ); ?>"><b><?php echo (int) $stats['total']; ?></b><span>Tous</span></a>
						<?php foreach ( UEB_STATUTS_QUITUS as $cle => $s ) : ?>
							<a class="compteur compteur--<?php echo esc_attr( $cle ); ?><?php echo $cle === $filtres['statut'] ? ' est-actif' : ''; ?>" href="<?php echo $ici( array( 'vue' => 'quitus', 'statut' => $cle, 'q' => $filtres['q'] ?: null ) ); ?>"><b><?php echo (int) $stats['statuts'][ $cle ]; ?></b><span><?php echo esc_html( $s['libelle'] ); ?></span></a>
						<?php endforeach; ?>
					</nav>

					<form class="filtres filtres--quitus carte" method="get" action="<?php echo esc_url( ueb_url_scolarite() ); ?>" role="search">
						<input type="hidden" name="vue" value="quitus">
						<div class="champ">
							<label for="f-q">Rechercher</label>
							<input id="f-q" type="search" name="q" value="<?php echo esc_attr( $filtres['q'] ); ?>" placeholder="N° de quitus, matricule, nom…">
						</div>
						<?php if ( $filtres['statut'] ) : ?><input type="hidden" name="statut" value="<?php echo esc_attr( $filtres['statut'] ); ?>"><?php endif; ?>
						<button class="btn btn--primaire" type="submit"><?php echo ueb_icone( 'loupe', 18 ); ?>Filtrer</button>
					</form>

					<div class="tableau-conteneur">
						<table class="tableau">
							<thead><tr><th>Quitus</th><th>Étudiant</th><th>Type</th><th>Montant</th><th>Reçus</th><th>Statut</th><th><span class="sr">Action</span></th></tr></thead>
							<tbody>
							<?php if ( ! $liste['lignes'] ) : ?>
								<tr><td colspan="7" class="texte-discret">Aucun quitus ne correspond à ces critères.</td></tr>
							<?php endif; ?>
							<?php foreach ( $liste['lignes'] as $q ) : ?>
								<tr>
									<td class="num"><b><?php echo esc_html( $q->numero ); ?></b><br><small class="texte-discret"><?php echo esc_html( mysql2date( 'd/m/Y H:i', $q->date_modification ) ); ?></small></td>
									<td><?php echo esc_html( $q->nom . ' ' . $q->prenom ); ?><br><small class="texte-discret"><?php echo esc_html( $q->identifiant ); ?></small></td>
									<td><small class="texte-discret"><?php echo esc_html( ueb_detail_quitus( $q ) ); ?></small></td>
									<td class="num"><?php echo esc_html( ueb_formater_montant( $q->montant ) ); ?> F</td>
									<td class="num"><?php echo (int) $q->nb_recus; ?></td>
									<td><?php echo ueb_badge_statut( $q->statut ); // phpcs:ignore ?></td>
									<td><a class="btn btn--fantome btn--petit" href="<?php echo $ici( array( 'quitus' => $q->id ) ); ?>">Ouvrir</a></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>

					<?php if ( $liste['pages'] > 1 ) : ?>
						<nav class="pagination" aria-label="Pages">
							<?php for ( $p = 1; $p <= $liste['pages']; $p++ ) : ?>
								<a href="<?php echo $ici( array( 'vue' => 'quitus', 'p' => $p, 'statut' => $filtres['statut'] ?: null, 'q' => $filtres['q'] ?: null ) ); ?>" <?php echo $p === $liste['page'] ? 'aria-current="page"' : ''; ?>><?php echo (int) $p; ?></a>
							<?php endfor; ?>
						</nav>
					<?php endif; ?>

				<?php endif; ?>
			</div>
		</div>

	<?php endif; ?>

</main>
<?php
ueb_page_fin( 'gestion' );
