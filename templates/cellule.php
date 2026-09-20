<?php
/** Espace de la cellule informatique : comptes étudiants de l'établissement. */

defined( 'ABSPATH' ) || exit;

$erreur_connexion = '';
if ( isset( $_POST['ueb_connexion_cellule'] ) ) {
	if ( ! isset( $_POST['ueb_connexion_cellule_nonce'] ) || ! wp_verify_nonce( $_POST['ueb_connexion_cellule_nonce'], 'ueb_connexion_cellule' ) ) {
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
		} elseif ( ! user_can( $utilisateur, UEB_CAP_COMPTES ) || ! ueb_est_cellule( $utilisateur->ID ) || ueb_agent_suspendu( $utilisateur->ID ) ) {
			wp_logout();
			$erreur_connexion = "Ce compte n'a pas accès à la cellule informatique.";
		} else {
			ueb_reinitialiser_echecs_gestion( $identifiant );
			wp_safe_redirect( ueb_url_cellule() );
			exit;
		}
	}
}

$autorise = is_user_logged_in() && ueb_est_cellule() && current_user_can( UEB_CAP_COMPTES ) && ! ueb_agent_suspendu();
$annee    = ueb_annee_academique();
$etab     = $autorise ? ueb_etablissement( ueb_etab_agent() ) : null;
$vue      = sanitize_key( $_GET['vue'] ?? 'comptes' );
$filtres  = array( 'q' => sanitize_text_field( wp_unslash( $_GET['qc'] ?? '' ) ), 'paiement' => '' );
$etudiants = $autorise ? ueb_gestion_chercher_etudiants( $filtres, ueb_etab_agent() ) : array();
$prov = $_SESSION['ueb_mdp_provisoire'] ?? null;
unset( $_SESSION['ueb_mdp_provisoire'] );

ueb_page_debut( array( 'titre' => 'Cellule informatique', 'variante' => $autorise ? 'bo' : 'gestion' ) );
?>
<main id="contenu" class="page-app gestion cellule-page<?php echo $autorise ? ' page-app--bo' : ''; ?>">
	<?php if ( ! $autorise ) : ?>
		<div class="conteneur">
			<div class="espace-connexion">
				<section class="carte carte__corps" aria-labelledby="titre-connexion-cellule">
					<h1 id="titre-connexion-cellule">Cellule informatique</h1>
					<p class="page-app__sous-titre">Gestion des comptes étudiants de ton établissement.</p>
					<?php if ( is_user_logged_in() ) : ?><?php ueb_alerte( 'erreur', "Ce compte n'a pas accès à la cellule informatique." ); ?><?php endif; ?>
					<?php if ( $erreur_connexion ) : ?><?php ueb_alerte( 'erreur', $erreur_connexion ); ?><?php endif; ?>
					<form class="formulaire cellule-connexion" method="post" action="<?php echo esc_url( ueb_url_cellule() ); ?>" data-formulaire novalidate>
						<?php wp_nonce_field( 'ueb_connexion_cellule', 'ueb_connexion_cellule_nonce' ); ?>
						<input type="hidden" name="ueb_connexion_cellule" value="1">
						<?php ueb_champ( array( 'nom' => 'identifiant', 'libelle' => 'Identifiant', 'icone' => 'utilisateur', 'attrs' => array( 'autocomplete' => 'username', 'autocapitalize' => 'none', 'spellcheck' => 'false', 'autofocus' => true ) ) ); ?>
						<?php ueb_champ( array( 'nom' => 'mot_de_passe', 'libelle' => 'Mot de passe', 'type' => 'password', 'icone' => 'cadenas', 'attrs' => array( 'autocomplete' => 'current-password' ) ) ); ?>
						<button class="btn btn--primaire btn--large" type="submit"><?php echo ueb_icone( 'bouclier', 18 ); ?>Se connecter</button>
					</form>
				</section>
			</div>
		</div>
	<?php else : ?>
		<div class="bo">
			<?php ueb_bo_barre( 'Cellule informatique', array( array( 'url' => ueb_url_cellule(), 'libelle' => 'Comptes étudiants', 'icone' => 'utilisateur', 'actif' => 'comptes' === $vue ), array( 'url' => add_query_arg( 'vue', 'securite', ueb_url_cellule() ), 'libelle' => 'Sécurité', 'icone' => 'bouclier', 'actif' => 'securite' === $vue ) ), array( 'titre' => $etab['sigle'], 'note' => $etab['fr'] ) ); ?>
			<div class="bo-contenu">
				<header class="page-app__entete"><div><h1><?php echo 'securite' === $vue ? 'Sécurité' : 'Comptes étudiants'; ?></h1><p class="page-app__sous-titre"><?php echo esc_html( $etab['fr'] ); ?> · année <?php echo esc_html( $annee['libelle'] ); ?></p></div></header>
				<?php ueb_afficher_flash(); ?>
				<?php if ( 'securite' === $vue ) : ?>
					<section class="carte section-form" aria-labelledby="titre-securite-cellule"><header class="section-form__entete"><span class="section-form__num"><?php echo ueb_icone( 'bouclier', 18 ); ?></span><div><h2 id="titre-securite-cellule">Modifier mon mot de passe</h2><p>Remplace le mot de passe communiqué par la scolarité.</p></div></header><div class="section-form__corps"><form class="formulaire securite-form" method="post" action="<?php echo esc_url( ueb_url_cellule() ); ?>" data-formulaire novalidate><?php ueb_champ_csrf(); ?><input type="hidden" name="ueb_action" value="gestion_changer_mdp_personnel"><?php ueb_champ( array( 'nom' => 'mot_de_passe_actuel', 'libelle' => 'Mot de passe actuel', 'type' => 'password', 'icone' => 'cadenas', 'attrs' => array( 'autocomplete' => 'current-password' ) ) ); ?><?php ueb_champ( array( 'nom' => 'mot_de_passe_nouveau', 'libelle' => 'Nouveau mot de passe', 'type' => 'password', 'icone' => 'cle', 'attrs' => array( 'autocomplete' => 'new-password', 'minlength' => 8 ) ) ); ?><?php ueb_champ( array( 'nom' => 'mot_de_passe_confirmation', 'libelle' => 'Confirmation', 'type' => 'password', 'icone' => 'cle', 'attrs' => array( 'autocomplete' => 'new-password', 'minlength' => 8 ) ) ); ?><div class="securite-form__actions"><button class="btn btn--primaire" type="submit"><?php echo ueb_icone( 'bouclier', 18 ); ?>Changer le mot de passe</button></div></form></div></section>
				<?php else : ?>
				<?php if ( $prov ) : ?><div class="provisoire carte" role="status"><?php echo ueb_icone( 'cle', 26 ); ?><div><p>Mot de passe provisoire pour <b><?php echo esc_html( $prov['compte'] ); ?></b> :</p><p class="provisoire__mdp"><?php echo esc_html( $prov['mdp'] ); ?></p><button type="button" class="btn btn--fantome btn--petit provisoire__copier" data-copier-mot-de-passe="<?php echo esc_attr( $prov['mdp'] ); ?>"><?php echo ueb_icone( 'fichier', 16 ); ?><span>Copier le mot de passe</span></button></div></div><?php endif; ?>
				<form class="filtres carte" method="get" action="<?php echo esc_url( ueb_url_cellule() ); ?>" role="search">
					<div class="champ"><label for="cellule-q">Rechercher un étudiant</label><input id="cellule-q" type="search" name="qc" value="<?php echo esc_attr( $filtres['q'] ); ?>" placeholder="Nom, matricule, dossier ou téléphone"></div>
					<button class="btn btn--primaire" type="submit"><?php echo ueb_icone( 'loupe', 18 ); ?>Rechercher</button>
				</form>
				<div class="tableau-conteneur"><table class="tableau"><thead><tr><th>Étudiant</th><th>Identifiants</th><th>Téléphone</th><th>État</th><th><span class="sr">Actions</span></th></tr></thead><tbody>
				<?php if ( ! $etudiants ) : ?><tr><td colspan="5" class="texte-discret">Aucun étudiant ne correspond à cette recherche.</td></tr><?php endif; ?>
				<?php foreach ( $etudiants as $e ) : ?><tr><td><b><?php echo esc_html( trim( $e->nom . ' ' . $e->prenom ) ?: '—' ); ?></b></td><td><?php echo esc_html( $e->matricule ?: '—' ); ?><br><small class="texte-discret"><?php echo esc_html( $e->numero_dossier ?: '' ); ?></small></td><td class="num"><?php echo esc_html( $e->telephone ? ueb_formater_telephone( $e->telephone ) : '—' ); ?></td><td><?php echo 'actif' === $e->statut ? '<span class="badge badge--verifie"><i></i>Actif</span>' : '<span class="badge badge--rejete"><i></i>Suspendu</span>'; ?></td><td class="actions-ligne"><form method="post" action="<?php echo esc_url( ueb_url_cellule() ); ?>" data-confirmer="Réinitialiser le mot de passe de <?php echo esc_attr( ueb_identifiant_compte( $e ) ); ?> ?"><?php ueb_champ_csrf(); ?><input type="hidden" name="ueb_action" value="gestion_reinit_mdp"><input type="hidden" name="compte_id" value="<?php echo (int) $e->id; ?>"><input type="hidden" name="q" value="<?php echo esc_attr( $filtres['q'] ); ?>"><button class="btn btn--fantome btn--petit" type="submit"><?php echo ueb_icone( 'cle', 16 ); ?>Mot de passe</button></form><form method="post" action="<?php echo esc_url( ueb_url_cellule() ); ?>" data-confirmer="<?php echo 'actif' === $e->statut ? 'Suspendre ce compte ?' : 'Réactiver ce compte ?'; ?>"><?php ueb_champ_csrf(); ?><input type="hidden" name="ueb_action" value="gestion_bloquer"><input type="hidden" name="compte_id" value="<?php echo (int) $e->id; ?>"><input type="hidden" name="q" value="<?php echo esc_attr( $filtres['q'] ); ?>"><button class="btn btn--lien btn--petit" type="submit"><?php echo 'actif' === $e->statut ? 'Suspendre' : 'Réactiver'; ?></button></form></td></tr><?php endforeach; ?>
				</tbody></table></div>
				<section class="carte section-form" aria-labelledby="titre-ajout-cellule"><header class="section-form__entete"><span class="section-form__num"><?php echo ueb_icone( 'plus', 18 ); ?></span><div><h2 id="titre-ajout-cellule">Ajouter un étudiant</h2><p>Crée un compte lorsqu’un étudiant ne peut pas le faire lui-même.</p></div></header><div class="section-form__corps formulaire"><form class="formulaire" method="post" action="<?php echo esc_url( ueb_url_cellule() ); ?>" data-formulaire novalidate><?php ueb_champ_csrf(); ?><input type="hidden" name="ueb_action" value="gestion_creer_etudiant"><div class="formulaire__rangee"><?php ueb_champ( array( 'nom' => 'identifiant', 'libelle' => 'Matricule ou numéro de dossier', 'icone' => 'utilisateur', 'attrs' => array( 'placeholder' => '24I0017FS ou UEB-2026-000123', 'autocapitalize' => 'characters', 'spellcheck' => 'false', 'autocomplete' => 'off', 'data-identifiant' => true ) ) ); ?><?php ueb_champ( array( 'nom' => 'telephone', 'libelle' => 'Téléphone (facultatif)', 'type' => 'tel', 'icone' => 'telephone', 'requis' => false, 'attrs' => array( 'inputmode' => 'tel', 'maxlength' => 17, 'placeholder' => '6XX XX XX XX', 'data-telephone' => true, 'autocomplete' => 'off' ) ) ); ?></div><div class="securite-form__actions"><button class="btn btn--primaire" type="submit"><?php echo ueb_icone( 'plus', 18 ); ?>Créer le compte</button></div></form></div></section>
				<?php endif; ?>
			</div>
		</div>
	<?php endif; ?>
</main>
<?php ueb_page_fin( $autorise ? 'bo' : 'gestion' );
