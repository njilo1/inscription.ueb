<?php
/**
 * Page Sécurité d'un compte du personnel (espace du Centre médico-social) :
 *   - « Ton accès » : qui tu es, ta portée, et trois repères d'état (mot de
 *     passe initial ou personnel, sessions ouvertes, connexion précédente) ;
 *   - changement du mot de passe, avec jauge de solidité et règles vérifiées
 *     pendant la saisie (les mêmes que le serveur, ueb_erreur_mot_de_passe()) ;
 *   - sessions ouvertes (appareil, adresse, date), avec « Fermer les autres
 *     sessions » ;
 *   - dernières connexions du compte (journal tenu par inc/cms.php).
 *
 * Les formulaires postent les actions existantes (gestion_changer_mdp_personnel,
 * personnel_fermer_sessions) ; la page d'origine sert de retour.
 *
 * @package Inscription_UEB
 */
defined( 'ABSPATH' ) || exit;

$moi        = wp_get_current_user();
$nom        = $moi->display_name ?: $moi->user_login;
$agent      = ueb_est_agent( $moi->ID );
$date_heure = static fn( $date ) => wp_date( 'j F Y à H:i', is_numeric( $date ) ? (int) $date : strtotime( get_gmt_from_date( $date ) ) );
$mdp_le     = (string) get_user_meta( $moi->ID, 'ueb_mdp_modifie_le', true );
$journal    = array_values( array_filter( (array) get_user_meta( $moi->ID, 'ueb_journal_connexions', true ) ) );
$precedente = $journal[1] ?? null;
$autorises  = ueb_etabs_autorises();
$portee     = ueb_portee_totale() ? 'Tous les établissements' : implode( ', ', $autorises );

/* Sessions ouvertes : la plus récente d'abord, la session courante repérée par
   son empreinte (le stockage de WordPress range chaque session sous le
   sha256 de son jeton). */
$brutes   = get_user_meta( $moi->ID, 'session_tokens', true );
$brutes   = is_array( $brutes ) ? $brutes : array();
$courante = wp_get_session_token() ? hash( 'sha256', wp_get_session_token() ) : '';
$sessions = array();
foreach ( $brutes as $empreinte => $s ) {
	if ( ( $s['expiration'] ?? 0 ) < time() ) {
		continue;
	}
	$sessions[] = array( 'courante' => hash_equals( (string) $empreinte, $courante ), 'login' => (int) ( $s['login'] ?? 0 ), 'ip' => (string) ( $s['ip'] ?? '' ), 'ua' => (string) ( $s['ua'] ?? '' ), 'expiration' => (int) $s['expiration'] );
}
usort( $sessions, static fn( $a, $b ) => array( $b['courante'], $b['login'] ) <=> array( $a['courante'], $a['login'] ) );
$autres = count( $sessions ) - 1;

$reperes = array(
	$mdp_le
		? array( 'ok', 'cadenas', 'Mot de passe personnel', 'Changé le ' . $date_heure( $mdp_le ) . '.' )
		: array( 'alerte', 'cle', 'Mot de passe initial', 'Celui donné à la création du compte : remplace-le ci-dessous.', '#sec-mdp' ),
	$autres > 0
		? array( 'alerte', 'ecran', sprintf( '%d sessions ouvertes', count( $sessions ) ), 'Ferme celles que tu ne reconnais pas.', '#sec-sessions' )
		: array( 'ok', 'ecran', 'Une seule session ouverte', 'Celle de cet appareil.' ),
	$precedente
		? array( 'info', 'historique', 'Connexion précédente', $date_heure( $precedente['date'] ) . ', ' . ueb_appareil_lisible( $precedente['ua'] ?? '' )[0] . '.', '#sec-journal' )
		: array( 'info', 'historique', 'Première connexion suivie', 'Les prochaines s’afficheront dans ton journal.' ),
);
?>
<div class="sec">

	<section class="sec-acces" aria-labelledby="sec-acces-titre">
		<div class="sec-acces__qui">
			<span class="sec-acces__avatar" aria-hidden="true"><?php echo esc_html( ueb_initiales( $nom ) ); ?></span>
			<div>
				<h2 id="sec-acces-titre"><?php echo esc_html( $nom ); ?></h2>
				<p class="sec-acces__login"><?php echo ueb_icone( 'utilisateur', 15 ); ?><?php echo esc_html( $moi->user_login ); ?></p>
				<ul class="sec-acces__droits" aria-label="Rôle et portée">
					<li><?php echo ueb_icone( 'stethoscope', 15 ); ?><?php echo esc_html( ueb_nom_role_du_compte() ); ?></li>
					<li><?php echo ueb_icone( 'ecole', 15 ); ?><?php echo esc_html( $portee ); ?></li>
					<?php if ( $moi->user_registered ) : ?><li><?php echo ueb_icone( 'calendrier', 15 ); ?>Compte créé le <?php echo esc_html( mysql2date( 'j F Y', $moi->user_registered ) ); ?></li><?php endif; ?>
				</ul>
			</div>
		</div>
		<ul class="sec-reperes" aria-label="État de ton accès">
			<?php foreach ( $reperes as $r ) : ?>
				<li class="sec-repere sec-repere--<?php echo esc_attr( $r[0] ); ?>">
					<span class="sec-repere__icone" aria-hidden="true"><?php echo ueb_icone( $r[1], 18 ); ?></span>
					<span class="sec-repere__texte">
						<b><?php echo esc_html( $r[2] ); ?><?php echo 'alerte' === $r[0] ? '<span class="sr"> : à faire</span>' : ''; ?></b>
						<span><?php echo esc_html( $r[3] ); ?></span>
					</span>
					<?php if ( ! empty( $r[4] ) ) : ?><a class="sec-repere__lien" href="<?php echo esc_attr( $r[4] ); ?>"><?php echo ueb_icone( 'chevron-d', 16 ); ?><span class="sr">Aller à : <?php echo esc_html( $r[2] ); ?></span></a><?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>

	<div class="sec-grille">
		<section class="adm-panneau sec-mdp" id="sec-mdp" aria-labelledby="sec-mdp-titre">
			<header class="adm-panneau__tete">
				<div><h2 id="sec-mdp-titre">Changer mon mot de passe</h2><p>Tu seras déconnecté ensuite : reconnecte-toi avec le nouveau.</p></div>
				<?php echo ueb_icone( 'cadenas', 19 ); ?>
			</header>
			<?php if ( ! $agent ) : ?>
				<p class="sec-note"><?php echo ueb_icone( 'info', 16 ); ?>Le mot de passe d’un administrateur se change dans son profil WordPress.</p>
			<?php else : ?>
				<form class="formulaire bo-formulaire sec-mdp__form" method="post" action="<?php echo esc_url( add_query_arg( 'vue', 'securite' ) ); ?>" data-formulaire novalidate>
					<?php ueb_champ_csrf(); ?>
					<input type="hidden" name="ueb_action" value="gestion_changer_mdp_personnel">
					<?php ueb_champ( array( 'nom' => 'mot_de_passe_actuel', 'libelle' => 'Mot de passe actuel', 'type' => 'password', 'icone' => 'cadenas', 'attrs' => array( 'autocomplete' => 'current-password' ) ) ); ?>
					<?php ueb_champ( array( 'nom' => 'mot_de_passe_nouveau', 'libelle' => 'Nouveau mot de passe', 'type' => 'password', 'icone' => 'cle', 'attrs' => array( 'autocomplete' => 'new-password', 'minlength' => 8, 'aria-describedby' => 'sec-regles' ) ) ); ?>
					<div class="force-mdp" data-force-mdp="champ-mot_de_passe_nouveau" data-niveau="0">
						<div class="force-mdp__jauge" aria-hidden="true"><i></i><i></i><i></i><i></i></div>
						<p class="force-mdp__libelle" aria-live="polite">Solidité : <b data-force-libelle>à saisir</b></p>
					</div>
					<ul class="sec-regles" id="sec-regles" data-regles-mdp="champ-mot_de_passe_nouveau" data-identifiant-compte="<?php echo esc_attr( $moi->user_login ); ?>" aria-label="Règles du mot de passe">
						<li data-regle="longueur"><span class="sec-regles__case" aria-hidden="true"><?php echo ueb_icone( 'check', 12 ); ?></span>Au moins 8 caractères<span class="sr" data-regle-etat></span></li>
						<li data-regle="lettre"><span class="sec-regles__case" aria-hidden="true"><?php echo ueb_icone( 'check', 12 ); ?></span>Une lettre<span class="sr" data-regle-etat></span></li>
						<li data-regle="chiffre"><span class="sec-regles__case" aria-hidden="true"><?php echo ueb_icone( 'check', 12 ); ?></span>Un chiffre<span class="sr" data-regle-etat></span></li>
						<li data-regle="identifiant"><span class="sec-regles__case" aria-hidden="true"><?php echo ueb_icone( 'check', 12 ); ?></span>Sans ton identifiant<span class="sr" data-regle-etat></span></li>
					</ul>
					<?php ueb_champ( array( 'nom' => 'mot_de_passe_confirmation', 'libelle' => 'Confirmer le nouveau mot de passe', 'type' => 'password', 'icone' => 'cle', 'attrs' => array( 'autocomplete' => 'new-password', 'minlength' => 8, 'data-confirme' => 'champ-mot_de_passe_nouveau' ) ) ); ?>
					<div class="sec-mdp__actions">
						<button class="adm-bouton adm-bouton--primaire" type="submit"><?php echo ueb_icone( 'bouclier', 16 ); ?>Changer le mot de passe</button>
					</div>
				</form>
			<?php endif; ?>
		</section>

		<div class="sec-colonne">
			<section class="adm-panneau sec-sessions" id="sec-sessions" aria-labelledby="sec-sessions-titre">
				<header class="adm-panneau__tete">
					<div><h2 id="sec-sessions-titre">Sessions ouvertes</h2><p>Les appareils où ton compte est connecté en ce moment.</p></div>
					<?php echo ueb_icone( 'ecran', 19 ); ?>
				</header>
				<ul class="sec-sessions__liste">
					<?php foreach ( $sessions as $s ) : list( $appareil, $icone ) = ueb_appareil_lisible( $s['ua'] ); ?>
						<li class="sec-session<?php echo $s['courante'] ? ' sec-session--courante' : ''; ?>">
							<span class="sec-session__icone" aria-hidden="true"><?php echo ueb_icone( $icone, 18 ); ?></span>
							<span class="sec-session__texte">
								<b><?php echo esc_html( $appareil ); ?></b>
								<span><?php echo esc_html( sprintf( 'Connecté le %s%s', $date_heure( $s['login'] ), $s['ip'] ? ', adresse ' . $s['ip'] : '' ) ); ?></span>
							</span>
							<?php if ( $s['courante'] ) : ?><span class="sec-session__etiquette">Cet appareil</span><?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php if ( $autres > 0 ) : ?>
					<form method="post" action="<?php echo esc_url( add_query_arg( 'vue', 'securite' ) ); ?>" class="sec-sessions__fermer"
						data-confirmer="<?php echo esc_attr( sprintf( 'Les %d autres appareils seront déconnectés. Celui-ci reste connecté.', $autres ) ); ?>"
						data-confirmer-titre="Fermer les autres sessions ?" data-confirmer-bouton="Fermer les sessions">
						<?php ueb_champ_csrf(); ?>
						<input type="hidden" name="ueb_action" value="personnel_fermer_sessions">
						<button class="adm-bouton" type="submit"><?php echo ueb_icone( 'sortie', 16 ); ?>Fermer les autres sessions</button>
					</form>
				<?php else : ?>
					<p class="sec-note"><?php echo ueb_icone( 'check', 16 ); ?>Aucun autre appareil n’est connecté à ton compte.</p>
				<?php endif; ?>
			</section>

			<section class="adm-panneau sec-journal" id="sec-journal" aria-labelledby="sec-journal-titre">
				<header class="adm-panneau__tete">
					<div><h2 id="sec-journal-titre">Dernières connexions</h2><p>Une connexion que tu ne reconnais pas ? Change ton mot de passe, puis préviens l’administration.</p></div>
					<?php echo ueb_icone( 'historique', 19 ); ?>
				</header>
				<?php if ( ! $journal ) : ?>
					<p class="sec-note"><?php echo ueb_icone( 'info', 16 ); ?>Le journal démarre à ta prochaine connexion.</p>
				<?php else : ?>
					<ol class="sec-journal__liste">
						<?php foreach ( $journal as $rang => $j ) : ?>
							<li>
								<time datetime="<?php echo esc_attr( mysql2date( 'c', $j['date'] ) ); ?>"><?php echo esc_html( $date_heure( $j['date'] ) ); ?></time>
								<span><?php echo esc_html( ueb_appareil_lisible( $j['ua'] ?? '' )[0] . ( empty( $j['ip'] ) ? '' : ', ' . $j['ip'] ) ); ?><?php echo 0 === $rang ? ' <em class="sec-journal__actuelle">connexion actuelle</em>' : ''; ?></span>
							</li>
						<?php endforeach; ?>
					</ol>
				<?php endif; ?>
			</section>
		</div>
	</div>

	<section class="adm-panneau sec-reflexes" aria-labelledby="sec-reflexes-titre">
		<h2 id="sec-reflexes-titre">Les bons réflexes</h2>
		<ul>
			<li><?php echo ueb_icone( 'tampon', 18 ); ?><span><b>Chaque décision est enregistrée à ton nom.</b> Valider ou refuser un paiement t’engage : ne laisse personne le faire à ta place.</span></li>
			<li><?php echo ueb_icone( 'cadenas', 18 ); ?><span><b>Un mot de passe à toi seul.</b> Personne ne te le demandera, pas même l’administration.</span></li>
			<li><?php echo ueb_icone( 'sortie', 18 ); ?><span><b>Déconnecte-toi en partant.</b> Surtout sur un ordinateur partagé du centre.</span></li>
		</ul>
	</section>
</div>
