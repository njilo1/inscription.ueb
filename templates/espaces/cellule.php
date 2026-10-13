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
			$erreur_connexion = ueb_message_echec_connexion( $utilisateur, 'Identifiant ou mot de passe incorrect.' );
		} elseif ( ! ueb_est_cellule( $utilisateur->ID ) || ! ueb_etabs_autorises( $utilisateur->ID ) || ueb_agent_suspendu( $utilisateur->ID ) ) {
			wp_logout();
			$erreur_connexion = "Ce compte n'a pas accès à la cellule informatique.";
		} else {
			ueb_reinitialiser_echecs_gestion( $identifiant );
			wp_safe_redirect( ueb_url_cellule() );
			exit;
		}
	}
}

/* Accès par capacité et portée (inc/roles.php), jamais par nom de rôle. */
$autorise = ( ueb_est_cellule() || ueb_est_admin_ueb() ) && ( ueb_peut( UEB_CAP_COMPTES ) || ueb_peut( 'ueb_reinit_mdp' ) );
/* Réinitialisation seule (cellule informatique) : ni création ni suspension. */
$gere_comptes = ueb_peut( UEB_CAP_COMPTES );
$annee    = ueb_annee_academique();
$etab     = $autorise ? ueb_etablissement( ueb_etab_agent() ) : null;
$etab     = $etab ?: array( 'sigle' => 'Tous', 'fr' => 'Tous les établissements' );
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
			<?php ueb_bo_barre( 'Comptes étudiants', array( array( 'url' => ueb_url_cellule(), 'libelle' => 'Comptes étudiants', 'icone' => 'utilisateur', 'actif' => 'comptes' === $vue ), ueb_est_scolarite() && ( ueb_peut( UEB_CAP_GESTION ) || ueb_peut( 'ueb_voir_paiements' ) ) ? array( 'url' => ueb_url_scolarite(), 'libelle' => 'Espace scolarité', 'icone' => 'recu', 'actif' => false ) : null, ueb_peut( UEB_CAP_DIRECTION ) ? array( 'url' => ueb_url_direction(), 'libelle' => 'Direction', 'icone' => 'bouclier', 'actif' => false ) : null, array( 'url' => add_query_arg( 'vue', 'securite', ueb_url_cellule() ), 'libelle' => 'Sécurité', 'icone' => 'cadenas', 'actif' => 'securite' === $vue ) ), array( 'titre' => $etab['sigle'], 'note' => $etab['fr'] ) ); ?>
			<div class="bo-contenu">
				<header class="page-app__entete"><div><h1><?php echo 'securite' === $vue ? 'Sécurité' : 'Comptes étudiants'; ?></h1><p class="page-app__sous-titre"><?php echo esc_html( $etab['fr'] ); ?> · année <?php echo esc_html( $annee['libelle'] ); ?></p></div></header>
				<?php ueb_afficher_flash(); ?>
				<?php if ( 'securite' === $vue ) : ?>
					<?php ueb_bloc_mot_de_passe( array( 'action' => ueb_url_cellule(), 'titre' => 'Ton accès touche aux comptes des étudiants', 'conseil' => array( 'utilisateur', 'Chaque réinitialisation est enregistrée à ton nom', ', avec sa date.' ) ) ); ?>
				<?php else : ?>
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
				<?php if ( $prov ) : ?><div class="provisoire carte" role="status"><?php echo ueb_icone( 'cle', 26 ); ?><div><p>Mot de passe provisoire pour <b><?php echo esc_html( $prov['compte'] ); ?></b> :</p><p class="provisoire__mdp"><?php echo esc_html( $prov['mdp'] ); ?></p><button type="button" class="btn btn--fantome btn--petit provisoire__copier" data-copier-mot-de-passe="<?php echo esc_attr( $prov['mdp'] ); ?>"><?php echo ueb_icone( 'fichier', 16 ); ?><span>Copier le mot de passe</span></button></div></div><?php endif; ?>
				<section class="carte comptes-etu" aria-labelledby="comptes-etu-titre">
					<header class="comptes-etu__tete">
						<div>
							<h2 id="comptes-etu-titre">Registre des comptes</h2>
							<p><?php echo esc_html( $filtres['q'] ? sprintf( '%d résultat%s pour « %s »', count( $etudiants ), 1 < count( $etudiants ) ? 's' : '', $filtres['q'] ) : sprintf( '%d compte%s, les plus récents d’abord', count( $etudiants ), 1 < count( $etudiants ) ? 's' : '' ) ); ?></p>
						</div>
						<form class="comptes-etu__recherche" method="get" action="<?php echo esc_url( ueb_url_cellule() ); ?>" role="search"><?php ueb_champ_espace(); ?>
							<label class="sr" for="cellule-q">Rechercher un étudiant</label>
							<span class="comptes-etu__champ"><?php echo ueb_icone( 'loupe', 18 ); ?><input id="cellule-q" type="search" name="qc" value="<?php echo esc_attr( $filtres['q'] ); ?>" placeholder="Nom, prénom, matricule ou téléphone" autocomplete="off"<?php echo ueb_attr_suggestions( 'comptes' ); // phpcs:ignore -- échappé ?>></span>
							<button class="btn btn--primaire" type="submit">Rechercher</button>
						</form>
					</header>
					<div class="tableau-conteneur comptes-etu__registre">
						<table class="tableau">
							<thead>
								<tr>
									<th scope="col">Étudiant</th>
									<th scope="col">Matricule</th>
									<th scope="col">Téléphone</th>
									<th scope="col">État</th>
									<th scope="col" class="comptes-etu__col-action">Mot de passe</th>
									<?php if ( $gere_comptes ) : ?><th scope="col" class="comptes-etu__col-action">Accès</th><?php endif; ?>
								</tr>
							</thead>
							<tbody>
								<?php if ( ! $etudiants ) : ?>
									<tr><td colspan="<?php echo $gere_comptes ? 6 : 5; ?>" class="texte-discret">Aucun étudiant ne correspond à cette recherche.</td></tr>
								<?php endif; ?>
								<?php foreach ( $etudiants as $e ) : $actif = 'actif' === $e->statut; ?>
									<tr>
										<th scope="row"><b><?php echo esc_html( trim( $e->nom . ' ' . $e->prenom ) ?: '—' ); ?></b></th>
										<td class="comptes-etu__matricule"><?php echo esc_html( $e->matricule ?: '—' ); ?></td>
										<td class="num"><?php echo esc_html( $e->telephone ? ueb_formater_telephone( $e->telephone ) : '—' ); ?></td>
										<td><span class="comptes-etu__etats"><?php echo $actif ? '<span class="badge badge--verifie"><i></i>Actif</span>' : '<span class="badge badge--rejete"><i></i>Suspendu</span>'; ?><?php echo ueb_badge_mdp( $e ); // phpcs:ignore -- échappé ?></span></td>
										<td class="comptes-etu__col-action">
											<form method="post" action="<?php echo esc_url( ueb_url_cellule() ); ?>" data-confirmer="Réinitialiser le mot de passe de <?php echo esc_attr( ueb_identifiant_compte( $e ) ); ?> ? As-tu vérifié sa carte d’identité ? Il aura 1 heure pour choisir son nouveau mot de passe." data-confirmer-titre="Réinitialiser le mot de passe ?" data-confirmer-bouton="Réinitialiser" data-confirmer-ton="enregistrer">
												<?php ueb_champ_csrf(); ?>
												<input type="hidden" name="ueb_action" value="gestion_reinit_mdp">
												<input type="hidden" name="compte_id" value="<?php echo (int) $e->id; ?>">
												<input type="hidden" name="q" value="<?php echo esc_attr( $filtres['q'] ); ?>">
												<button class="comptes-etu__action" type="submit"><?php echo ueb_icone( 'cle', 15 ); ?>Réinitialiser</button>
											</form>
										</td>
										<?php if ( $gere_comptes ) : ?>
											<td class="comptes-etu__col-action">
												<form method="post" action="<?php echo esc_url( ueb_url_cellule() ); ?>" data-confirmer="<?php echo $actif ? 'L’étudiant sera déconnecté et ne pourra plus se connecter jusqu’à la réactivation de son compte.' : 'L’étudiant pourra de nouveau se connecter.'; ?>" data-confirmer-titre="<?php echo esc_attr( ( $actif ? 'Suspendre le compte de ' : 'Réactiver le compte de ' ) . ueb_identifiant_compte( $e ) . ' ?' ); ?>" data-confirmer-bouton="<?php echo $actif ? 'Suspendre' : 'Réactiver'; ?>"<?php echo $actif ? '' : ' data-confirmer-ton="enregistrer"'; ?>>
													<?php ueb_champ_csrf(); ?>
													<input type="hidden" name="ueb_action" value="gestion_bloquer">
													<input type="hidden" name="compte_id" value="<?php echo (int) $e->id; ?>">
													<input type="hidden" name="q" value="<?php echo esc_attr( $filtres['q'] ); ?>">
													<button class="comptes-etu__action comptes-etu__action--<?php echo $actif ? 'suspendre' : 'reactiver'; ?>" type="submit"><?php echo ueb_icone( $actif ? 'pause' : 'lecture', 15 ); ?><?php echo $actif ? 'Suspendre' : 'Réactiver'; ?></button>
												</form>
											</td>
										<?php endif; ?>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</section>
				<?php if ( $gere_comptes ) : ?>
					<section class="carte comptes-etu comptes-etu--ajout" aria-labelledby="titre-ajout-cellule">
						<header class="comptes-etu__tete">
							<div>
								<h2 id="titre-ajout-cellule">Ajouter un étudiant</h2>
								<p>Crée un compte lorsqu’un étudiant ne peut pas le faire lui-même. Un mot de passe provisoire s’affichera une seule fois.</p>
							</div>
						</header>
						<form class="formulaire comptes-etu__ajout" method="post" action="<?php echo esc_url( ueb_url_cellule() ); ?>" data-formulaire novalidate>
							<?php ueb_champ_csrf(); ?>
							<input type="hidden" name="ueb_action" value="gestion_creer_etudiant">
							<?php
							ueb_champ( array( 'nom' => 'identifiant', 'libelle' => 'Matricule', 'icone' => 'utilisateur', 'attrs' => array( 'placeholder' => '24I0017FS', 'autocapitalize' => 'characters', 'spellcheck' => 'false', 'autocomplete' => 'off', 'data-identifiant' => true ) ) );
							ueb_champ( array( 'nom' => 'telephone', 'libelle' => 'Téléphone', 'type' => 'tel', 'icone' => 'telephone', 'requis' => false, 'attrs' => array( 'inputmode' => 'tel', 'maxlength' => 17, 'placeholder' => '6XX XX XX XX', 'data-telephone' => true, 'autocomplete' => 'off' ) ) );
							?>
							<button class="btn btn--primaire comptes-etu__creer" type="submit"><?php echo ueb_icone( 'plus', 18 ); ?>Créer le compte</button>
						</form>
					</section>
				<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
	<?php endif; ?>
</main>
<?php ueb_page_fin( $autorise ? 'bo' : 'gestion' );
