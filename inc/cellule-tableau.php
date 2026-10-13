<?php
/**
 * Tableau de bord de la cellule informatique (espace « cellule », vue « bord »).
 *
 * La cellule réinitialise les mots de passe des étudiants et suspend leurs
 * comptes : son tableau de bord ne montre que les comptes étudiants de son
 * établissement, jamais un quitus, un paiement ni un montant.
 *   - Recherche d'un étudiant (le point de départ de chaque intervention) ;
 *   - État des comptes : anneau Remotion « donut » (assets/js/cellule.js) ;
 *   - Dernières réinitialisations, avec leur suite (mot de passe choisi,
 *     en attente, expirée) ;
 *   - Comptes suspendus, à réactiver depuis la ligne.
 *
 * Un compte appartient à l'établissement où il a au moins un quitus, comme
 * pour les actions (ueb_compte_dans_etab).
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

/** États d'un compte, dans l'ordre de l'anneau : le rouge ne touche jamais le vert. */
const UEB_CELLULE_ETATS = array(
	'utilise'   => array( 'Utilisés', 'Actifs, mot de passe choisi par l’étudiant', 'var(--cel-utilise)' ),
	'a_choisir' => array( 'Mot de passe à choisir', 'Réinitialisés ou créés avec un mot de passe provisoire', 'var(--cel-choisir)' ),
	'suspendu'  => array( 'Suspendus', 'L’étudiant ne peut plus se connecter', 'var(--cel-suspendu)' ),
	'jamais'    => array( 'Jamais connectés', 'Compte créé, aucune connexion depuis', 'var(--cel-jamais)' ),
);

/**
 * Données du tableau de bord : états des comptes, connexions récentes,
 * journal des réinitialisations et comptes suspendus de l'établissement.
 *
 * @param string $etab Sigle de l'établissement ; vide = tous (administrateur).
 */
function ueb_cellule_donnees( $etab ) {
	global $wpdb;
	$dans   = $etab ? $wpdb->prepare( 'EXISTS ( SELECT 1 FROM ueb_insc_quitus q WHERE q.compte_id = c.id AND q.etablissement = %s )', $etab ) : 'EXISTS ( SELECT 1 FROM ueb_insc_quitus q WHERE q.compte_id = c.id )';
	$nom    = "( SELECT TRIM( CONCAT( MAX( q.nom ), ' ', MAX( q.prenom ) ) ) FROM ueb_insc_quitus q WHERE q.compte_id = c.id )";
	$semaine = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 7 * DAY_IN_SECONDS );
	$mois    = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS );

	$etats = array_fill_keys( array_keys( UEB_CELLULE_ETATS ), 0 );
	$total = $connectes = 0;
	foreach ( (array) $wpdb->get_results( "SELECT c.statut, c.doit_changer_mdp, c.derniere_connexion FROM ueb_insc_comptes c WHERE $dans" ) as $c ) { // phpcs:ignore -- $dans est préparé
		$total++;
		$connectes += $c->derniere_connexion && $c->derniere_connexion >= $semaine ? 1 : 0;
		if ( 'actif' !== $c->statut ) {
			$etats['suspendu']++;
		} elseif ( (int) $c->doit_changer_mdp ) {
			$etats['a_choisir']++;
		} else {
			$etats[ $c->derniere_connexion ? 'utilise' : 'jamais' ]++;
		}
	}

	/* Réinitialisations : les plus récentes, et leur suite (date_choix = mot de passe choisi). */
	$journal = (array) $wpdb->get_results(
		"SELECT r.date_reinit, r.date_choix, r.agent_id, c.id, c.matricule, c.numero_dossier, c.doit_changer_mdp, c.reinit_le, $nom AS nom
		   FROM ueb_insc_reinitialisations r JOIN ueb_insc_comptes c ON c.id = r.compte_id
		  WHERE $dans ORDER BY r.date_reinit DESC, r.id DESC LIMIT 6" // phpcs:ignore -- $dans est préparé
	);
	$reinit_mois = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM ueb_insc_reinitialisations r JOIN ueb_insc_comptes c ON c.id = r.compte_id WHERE $dans AND r.date_reinit >= %s", // phpcs:ignore -- $dans est préparé
		$mois
	) );
	$reinit_moi = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM ueb_insc_reinitialisations r JOIN ueb_insc_comptes c ON c.id = r.compte_id WHERE $dans AND r.date_reinit >= %s AND r.agent_id = %d", // phpcs:ignore -- $dans est préparé
		$mois,
		get_current_user_id()
	) );
	$suspendus = (array) $wpdb->get_results(
		"SELECT c.id, c.matricule, c.numero_dossier, c.statut, c.date_modification, $nom AS nom FROM ueb_insc_comptes c WHERE $dans AND c.statut <> 'actif' ORDER BY c.date_modification DESC LIMIT 6" // phpcs:ignore -- $dans est préparé
	);

	return compact( 'total', 'etats', 'connectes', 'journal', 'reinit_mois', 'reinit_moi', 'suspendus' );
}

/** Suite d'une réinitialisation du journal : [ classe, libellé ]. */
function ueb_cellule_suite_reinit( $ligne ) {
	if ( $ligne->date_choix ) {
		return array( 'choisi', 'Mot de passe choisi' );
	}
	/* Une réinitialisation plus récente a pris sa place. */
	if ( (string) $ligne->reinit_le !== (string) $ligne->date_reinit ) {
		return array( 'remplace', 'Remplacée' );
	}
	$etat = ueb_reinit_etat( $ligne );
	if ( 'active' === $etat ) {
		return array( 'attente', 'En attente, jusqu’à ' . ueb_reinit_heure( ueb_reinit_fin( $ligne ) ) );
	}
	return 'expiree' === $etat ? array( 'expiree', 'Expirée, à refaire' ) : array( 'choisi', 'Mot de passe choisi' );
}

/** Date courte d'un événement : « aujourd’hui à 11 h 05 », « hier à 9 h 40 » ou « 3 octobre ». */
function ueb_cellule_quand( $mysql ) {
	$t     = strtotime( $mysql );
	$jour  = gmdate( 'Y-m-d', $t );
	$heure = str_replace( ':', ' h ', gmdate( 'G:i', $t ) );
	if ( current_time( 'Y-m-d' ) === $jour ) {
		return 'aujourd’hui à ' . $heure;
	}
	if ( gmdate( 'Y-m-d', current_time( 'timestamp' ) - DAY_IN_SECONDS ) === $jour ) {
		return 'hier à ' . $heure;
	}
	return wp_date( 'j F', $t, new DateTimeZone( 'UTC' ) );
}

/**
 * Tableau de bord de la cellule.
 *
 * @param array  $d              ueb_cellule_donnees().
 * @param array  $etab           Établissement affiché (sigle, fr).
 * @param bool   $peut_suspendre Boutons « Réactiver » sur les comptes suspendus.
 */
function ueb_cellule_tableau( array $d, array $etab, $peut_suspendre ) {
	$url_comptes = add_query_arg( 'vue', 'comptes', ueb_url_cellule() );
	$parts       = array();
	$debut       = 0;
	foreach ( UEB_CELLULE_ETATS as $cle => $e ) {
		if ( $d['total'] && $d['etats'][ $cle ] ) {
			$valeur  = round( 100 * $d['etats'][ $cle ] / $d['total'], 3 );
			$parts[] = array( 'cle' => $cle, 'valeur' => $valeur, 'couleur' => $e[2], 'debut' => $debut );
			$debut  += $valeur;
		}
	}
	$dit = sprintf( '%d comptes étudiants : ', $d['total'] ) . implode( ', ', array_map( static fn( $cle ) => $d['etats'][ $cle ] . ' ' . mb_strtolower( UEB_CELLULE_ETATS[ $cle ][0] ), array_keys( UEB_CELLULE_ETATS ) ) );
	ueb_adm_tete( array(
		'titre'      => 'Tableau de bord',
		'sous_titre' => ( 'Tous' === $etab['sigle'] ? 'Tous les établissements' : $etab['fr'] ) . ' : retrouver un étudiant, réinitialiser son mot de passe, suspendre son compte.',
		'actions'    => ueb_adm_action( $url_comptes, 'Comptes étudiants', 'utilisateur', true ),
	) );
	ueb_afficher_flash();
	?>
	<form class="cel-recherche" method="get" action="<?php echo esc_url( ueb_url_cellule() ); ?>" role="search">
		<?php ueb_champ_espace(); ?>
		<input type="hidden" name="vue" value="comptes">
		<label for="cel-q">Retrouver un étudiant</label>
		<div class="cel-recherche__champ">
			<?php echo ueb_icone( 'loupe', 20 ); ?>
			<input id="cel-q" type="search" name="qc" placeholder="Nom, matricule ou téléphone" autocomplete="off">
			<button class="btn btn--primaire" type="submit">Rechercher</button>
		</div>
		<p>Vérifie toujours la carte d’identité de l’étudiant avant de toucher à son compte.</p>
	</form>

	<div class="cel-grille">
		<section class="adm-panneau cel-etats" aria-labelledby="cel-etats-titre">
			<header class="adm-panneau__tete"><div><h2 id="cel-etats-titre">État des comptes</h2><p><?php echo esc_html( 'Tous' === $etab['sigle'] ? 'Tous les comptes étudiants de l’université' : $etab['sigle'] . ', tous les comptes étudiants' ); ?></p></div></header>
			<?php if ( ! $d['total'] ) : ?>
				<p class="cel-vide"><?php echo ueb_icone( 'utilisateur', 20 ); ?>Aucun compte étudiant pour l’instant. Ils apparaîtront dès le premier quitus d’un étudiant de l’établissement.</p>
			<?php else : ?>
				<div class="cel-etats__corps">
					<div class="cel-anneau" role="img" aria-label="<?php echo esc_attr( $dit ); ?>"
						data-props="<?php echo esc_attr( wp_json_encode( array( 'parts' => array_map( static fn( $p ) => array_intersect_key( $p, array_flip( array( 'cle', 'valeur', 'couleur' ) ) ), $parts ), 'piste' => 'var(--cel-piste)', 'taille' => 200, 'rayon' => 78, 'epaisseur' => 24 ) ) ); ?>">
						<svg viewBox="0 0 200 200" aria-hidden="true"><g transform="rotate(-90 100 100)" fill="none">
							<circle class="cel-anneau__piste" cx="100" cy="100" r="78" stroke-width="24"/>
							<?php foreach ( $parts as $p ) : ?>
								<circle class="donut-part donut-part--<?php echo esc_attr( $p['cle'] ); ?>" cx="100" cy="100" r="78" stroke-width="24" pathLength="100" style="stroke:<?php echo esc_attr( $p['couleur'] ); ?>" stroke-dasharray="<?php echo esc_attr( $p['valeur'] . ' ' . ( 100 - $p['valeur'] ) ); ?>" stroke-dashoffset="<?php echo esc_attr( -$p['debut'] ); ?>"/>
							<?php endforeach; ?>
						</g></svg>
						<p class="cel-anneau__centre"><b><?php echo esc_html( ueb_formater_montant( $d['total'] ) ); ?></b><span><?php echo 1 < $d['total'] ? 'comptes' : 'compte'; ?></span></p>
					</div>
					<ul class="cel-etats__legende">
						<?php foreach ( UEB_CELLULE_ETATS as $cle => $e ) : $n = $d['etats'][ $cle ]; ?>
							<li data-cle="<?php echo esc_attr( $cle ); ?>" tabindex="0">
								<i style="background:<?php echo esc_attr( $e[2] ); ?>" aria-hidden="true"></i>
								<span><?php echo esc_html( $e[0] ); ?><small><?php echo esc_html( $e[1] ); ?></small></span>
								<b><?php echo esc_html( ueb_formater_montant( $n ) ); ?></b>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
				<p class="cel-etats__pied"><?php echo ueb_icone( 'horloge', 16 ); ?><span><b><?php echo esc_html( ueb_formater_montant( $d['connectes'] ) ); ?></b> <?php echo 1 < $d['connectes'] ? 'étudiants se sont connectés' : 'étudiant s’est connecté'; ?> ces 7 derniers jours.</span></p>
			<?php endif; ?>
		</section>

		<section class="adm-panneau cel-journal" aria-labelledby="cel-journal-titre">
			<header class="adm-panneau__tete">
				<div><h2 id="cel-journal-titre">Dernières réinitialisations</h2><p>Chaque réinitialisation est enregistrée au nom de l’agent qui l’a faite.</p></div>
				<?php if ( $d['reinit_mois'] ) : ?><p class="cel-journal__compte"><b><?php echo (int) $d['reinit_mois']; ?></b> en 30 jours<?php echo $d['reinit_moi'] ? ', dont ' . (int) $d['reinit_moi'] . ' par toi' : ''; ?></p><?php endif; ?>
			</header>
			<?php if ( ! $d['journal'] ) : ?>
				<p class="cel-vide"><?php echo ueb_icone( 'cle', 20 ); ?>Aucune réinitialisation pour l’instant. Chacune s’affichera ici avec sa suite : mot de passe choisi, en attente ou expirée.</p>
			<?php else : ?>
				<ol class="cel-journal__liste">
					<?php foreach ( $d['journal'] as $l ) :
						list( $classe, $suite ) = ueb_cellule_suite_reinit( $l );
						$agent = (int) $l->agent_id === get_current_user_id() ? 'toi' : ( $l->agent_id && get_userdata( (int) $l->agent_id ) ? get_userdata( (int) $l->agent_id )->display_name : 'un agent' );
						?>
						<li>
							<span class="cel-journal__qui"><b><?php echo esc_html( $l->nom ?: ueb_identifiant_compte( $l ) ); ?></b><small><?php echo esc_html( ueb_identifiant_compte( $l ) ); ?></small></span>
							<span class="cel-journal__quand"><?php echo esc_html( ucfirst( ueb_cellule_quand( $l->date_reinit ) ) ); ?><small>par <?php echo esc_html( $agent ); ?></small></span>
							<span class="cel-suite cel-suite--<?php echo esc_attr( $classe ); ?>"><i aria-hidden="true"></i><?php echo esc_html( $suite ); ?></span>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
		</section>
	</div>

	<section class="adm-panneau cel-suspendus" aria-labelledby="cel-suspendus-titre">
		<header class="adm-panneau__tete"><div><h2 id="cel-suspendus-titre">Comptes suspendus</h2><p>Ces étudiants ne peuvent plus se connecter tant que leur compte n’est pas réactivé.</p></div></header>
		<?php if ( ! $d['suspendus'] ) : ?>
			<p class="cel-vide"><?php echo ueb_icone( 'check', 20 ); ?>Aucun compte suspendu.</p>
		<?php else : ?>
			<ul class="cel-suspendus__liste">
				<?php foreach ( $d['suspendus'] as $s ) : $id_compte = ueb_identifiant_compte( $s ); ?>
					<li>
						<span class="cel-journal__qui"><b><?php echo esc_html( $s->nom ?: $id_compte ); ?></b><small><?php echo esc_html( $id_compte ); ?></small></span>
						<?php if ( $peut_suspendre ) : ?>
							<form method="post" action="<?php echo esc_url( ueb_url_cellule() ); ?>" data-confirmer="<?php echo esc_attr( 'Réactiver le compte ' . $id_compte . ' ? L’étudiant pourra de nouveau se connecter.' ); ?>" data-confirmer-titre="Réactiver ce compte ?" data-confirmer-bouton="Réactiver" data-confirmer-ton="enregistrer">
								<?php ueb_champ_csrf(); ?>
								<input type="hidden" name="ueb_action" value="gestion_bloquer">
								<input type="hidden" name="depuis" value="bord">
								<input type="hidden" name="compte_id" value="<?php echo (int) $s->id; ?>">
								<button class="adm-bouton" type="submit"><?php echo ueb_icone( 'cadenas', 16 ); ?>Réactiver<span class="sr"> <?php echo esc_html( $id_compte ); ?></span></button>
							</form>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</section>
	<?php
}
