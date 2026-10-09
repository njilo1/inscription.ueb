<?php
/**
 * Tableau de bord du Centre médico-social (espace scolarité, ?type=medicaux) :
 * le même rendu que celui de la scolarité, sur les seuls frais médicaux de la
 * portée du compte. En tête, la carte rouge des reçus en attente de
 * validation ouvre la liste déjà filtrée (inc/attente-recus.php).
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

/**
 * Chiffres des frais médicaux de l'exercice et séries des mini-courbes, du
 * premier quitus au dernier jour de l'historique (ueb_exercice_bornes).
 * Les reçus déposés par jour sont ceux de la carte rouge (ueb_attente_depots()).
 *
 * @return array{chiffres: array, finances: array, historique: array}
 */
function ueb_cms_donnees( $annee_code ) {
	global $wpdb;
	list( $portee, $params ) = ueb_gestion_portee_sql( array( 'annee' => $annee_code, 'type' => 'medicaux', 'stats' => true ) );
	$lignes     = (array) $wpdb->get_results( $wpdb->prepare(
		"SELECT q.compte_id, q.statut, q.montant, q.date_creation, q.date_verification, q.date_modification FROM ueb_insc_quitus q WHERE $portee", // phpcs:ignore -- portée préparée
		$params
	) );

	$c        = array( 'etudiants' => 0, 'quitus' => 0, 'a_payer' => 0, 'recus_envoyes' => 0, 'recus_verifies' => 0, 'recus_rejetes' => 0 );
	$f        = array( 'attendu' => 0, 'encaisse' => 0, 'verification' => 0, 'declare' => 0 );
	$statuts  = array( 'genere' => 'a_payer', 'recu_envoye' => 'recus_envoyes', 'verifie' => 'recus_verifies', 'rejete' => 'recus_rejetes' );
	$premiers = array();
	$evts     = array();
	$premier  = $dernier = '';
	foreach ( $lignes as $l ) {
		$premier = $premier ? min( $premier, $l->date_creation ) : $l->date_creation;
		$dernier = max( $dernier, $l->date_creation, (string) $l->date_verification );
	}
	list( $debut, $fin ) = ueb_exercice_bornes( $annee_code, $premier, $dernier ) ?? array_fill( 0, 2, ueb_exercice_fin_historique( $annee_code ) );
	foreach ( $lignes as $l ) {
		$m        = (int) $l->montant;
		$creation = substr( $l->date_creation, 0, 10 );
		$c['quitus']++;
		$c[ $statuts[ $l->statut ] ?? 'a_payer' ]++;
		$f['attendu'] += $m;
		$f[ array( 'verifie' => 'encaisse', 'recu_envoye' => 'verification' )[ $l->statut ] ?? 'declare' ] += $m;
		$premiers[ $l->compte_id ] = min( $premiers[ $l->compte_id ] ?? $creation, $creation );
		$evts[ min( $fin, max( $debut, $creation ) ) ][] = array( 'quitus', $m );
		if ( 'verifie' === $l->statut ) {
			$evts[ min( $fin, max( $debut, $creation, substr( $l->date_verification ?: $l->date_modification, 0, 10 ) ) ) ][] = array( 'encaisse', $m );
		}
	}
	$c['etudiants'] = count( $premiers );
	foreach ( $premiers as $jour ) {
		$evts[ min( $fin, max( $debut, $jour ) ) ][] = array( 'etudiant', 0 );
	}
	$h = array_fill_keys( array( 'etudiants', 'encaisse', 'quitus', 'taux' ), array() );
	$h['jours'] = array();
	$cumul      = array( 'etudiant' => 0, 'quitus' => 0, 'encaisse' => 0, 'attendu' => 0 );
	for ( $t = strtotime( $debut ); $t <= strtotime( $fin ); $t += DAY_IN_SECONDS ) {
		$jour = gmdate( 'Y-m-d', $t );
		foreach ( $evts[ $jour ] ?? array() as list( $evenement, $montant ) ) {
			if ( 'quitus' === $evenement ) {
				$cumul['quitus']++;
				$cumul['attendu'] += $montant;
			} elseif ( 'encaisse' === $evenement ) {
				$cumul['encaisse'] += $montant;
			} else {
				$cumul['etudiant']++;
			}
		}
		$h['jours'][]     = $jour;
		$h['etudiants'][] = $cumul['etudiant'];
		$h['quitus'][]    = $cumul['quitus'];
		$h['encaisse'][]  = $cumul['encaisse'];
		$h['taux'][]      = $cumul['attendu'] ? round( 100 * $cumul['encaisse'] / $cumul['attendu'], 2 ) : null;
	}
	return array( 'chiffres' => $c, 'finances' => $f, 'historique' => $h );
}

/** En-tête du tableau de bord du CMS : périmètre, quitus et étudiants de l'année. */
function ueb_cms_tete( array $c ) {
	$etab = ueb_etab_agent() ? ueb_etablissement( ueb_etab_agent() ) : null;
	ueb_adm_tete( array(
		'titre'      => 'Tableau de bord',
		'sous_titre' => sprintf( 'Frais médicaux, %s : %d quitus pour %s cette année.', $etab ? $etab['fr'] : 'tous les établissements', $c['quitus'], ueb_suivi_etudiants( $c['etudiants'] ) ),
		/* Statistiques seules (Chef CMS…) : pas de lien vers des reçus qu'il ne peut pas ouvrir. */
		'actions'    => ueb_bouton_imprimer() . ( in_array( 'medicaux', ueb_types_quitus_visibles(), true ) ? ueb_adm_action( ueb_url_recus_attente( 'medicaux', false ), 'Tous les reçus', 'recu', true ) : '' ),
	) );
}

/**
 * Corps du tableau de bord du CMS : la carte rouge et quatre indicateurs,
 * l'encaissement et la file des reçus, puis la situation des quitus et le
 * reste à encaisser.
 *
 * @param array $d Résultat de ueb_cms_donnees().
 */
function ueb_cms_tableau( array $d ) {
	$c     = $d['chiffres'];
	$f     = $d['finances'];
	$hist  = $d['historique'];
	$taux  = $f['attendu'] ? 100 * $f['encaisse'] / $f['attendu'] : 0;
	$reste = max( 0, $f['attendu'] - $f['encaisse'] );
	$url   = static fn( array $args = array() ) => ueb_url_espace_admin( 'scolarite', array( 'type' => 'medicaux' ) + $args );
	$cartes = array(
		array( 'Étudiants', ueb_formater_montant( $c['etudiants'] ), 'Avec un quitus de frais médicaux', 'groupe', 'foret', 'etudiants', 'Étudiants, cumul' ),
		array( 'Frais encaissés', ueb_adm_montant_court( $f['encaisse'] ), 'FCFA de reçus validés', 'banque', 'foret', 'encaisse', 'Encaissé, cumul' ),
		array( 'Quitus générés', ueb_formater_montant( $c['quitus'] ), 'Frais médicaux de l’année', 'fichier', 'foret', 'quitus', 'Quitus, cumul' ),
		array( 'Encaissement', $f['attendu'] ? ueb_pourcent( $taux ) : '0 %', 'Des frais médicaux attendus', 'check', 'vert', 'taux', 'Taux d’encaissement' ),
	);
	$parts = array(
		'encaisse'     => array( 'Encaissé', 'var(--dash-foret)' ),
		'verification' => array( 'En vérification', 'var(--dash-bleu)' ),
		'declare'      => array( 'Déclaré, sans reçu validé', 'var(--dash-or)' ),
	);
	?>
	<div class="adm-pilotage">
		<div class="adm-pilotage__contexte"><span class="adm-pilotage__repere" aria-hidden="true"></span><b>Frais médicaux</b><span><?php echo esc_html( ueb_adm_contexte_exercice( $hist['jours'] ) ); ?></span></div>
	</div>
	<div class="adm-kpis" aria-label="Indicateurs des frais médicaux de l’exercice">
		<?php ueb_carte_attente( 'medicaux' ); ?>
		<?php foreach ( $cartes as $carte ) : ?>
			<article class="adm-kpi adm-kpi--<?php echo esc_attr( $carte[4] ); ?>">
				<div class="adm-kpi__entete"><h2><?php echo esc_html( $carte[0] ); ?></h2><?php echo ueb_icone( $carte[3], 18 ); ?></div>
				<p class="adm-kpi__valeur"><?php echo esc_html( $carte[1] ); ?></p>
				<p class="adm-kpi__note"><?php echo esc_html( $carte[2] ); ?></p>
				<?php ueb_adm_mini_courbe( $carte[5], $hist['jours'], $hist[ $carte[5] ], $carte[6] ); ?>
			</article>
		<?php endforeach; ?>
	</div>

	<div class="adm-analyse">
		<section class="adm-panneau adm-finances" aria-labelledby="cms-finances-titre">
			<header class="adm-panneau__tete"><div><h2 id="cms-finances-titre">Encaissement des frais médicaux</h2><p>Répartition des montants attendus</p></div><?php echo ueb_icone( 'banque', 19 ); ?></header>
			<div class="adm-donut">
				<svg viewBox="0 0 200 200" aria-hidden="true">
					<circle class="adm-donut__piste" cx="100" cy="100" r="76"/>
					<?php
					$offset = 0;
					foreach ( $parts as $cle => $part ) :
						$longueur = $f['attendu'] ? 100 * $f[ $cle ] / $f['attendu'] : 0;
						if ( $longueur > 0 ) :
							?>
							<circle cx="100" cy="100" r="76" pathLength="100" stroke="<?php echo esc_attr( $part[1] ); ?>" stroke-dasharray="<?php echo esc_attr( $longueur . ' ' . ( 100 - $longueur ) ); ?>" stroke-dashoffset="<?php echo esc_attr( -$offset ); ?>"/>
							<?php
						endif;
						$offset += $longueur;
					endforeach;
					?>
				</svg>
				<p><b><?php echo esc_html( $f['attendu'] ? ueb_pourcent( $taux ) : '0 %' ); ?></b><span><?php echo $f['attendu'] ? 'encaissés' : 'Aucun frais attendu'; ?></span></p>
			</div>
			<ul class="adm-finances__legende">
				<?php foreach ( $parts as $cle => $part ) : ?>
					<li><i style="background: <?php echo esc_attr( $part[1] ); ?>" aria-hidden="true"></i><span><?php echo esc_html( $part[0] ); ?></span><b><?php echo esc_html( ueb_formater_montant( $f[ $cle ] ) ); ?><small> FCFA</small></b></li>
				<?php endforeach; ?>
			</ul>
			<p class="adm-finances__total"><span>Total attendu</span><b><?php echo esc_html( ueb_formater_montant( $f['attendu'] ) ); ?> <small>FCFA</small></b></p>
		</section>
		<?php ueb_file_attente( 'medicaux' ); ?>
	</div>

	<div class="adm-secondaire">
		<?php ueb_adm_statistiques( $c, 'Frais médicaux de l’année' ); ?>
		<section class="adm-solde" aria-labelledby="cms-solde-titre">
			<header><h2 id="cms-solde-titre">Reste à encaisser</h2><?php echo ueb_icone( 'banque', 21 ); ?></header>
			<p class="adm-solde__montant"><?php echo esc_html( ueb_adm_montant_court( $reste ) ); ?><small>FCFA</small></p>
			<p class="adm-solde__precision">Sur <?php echo esc_html( ueb_formater_montant( $f['attendu'] ) ); ?> FCFA de frais médicaux attendus.</p>
			<dl>
				<div><dt>En vérification</dt><dd><?php echo esc_html( ueb_formater_montant( $f['verification'] ) ); ?> <small>FCFA</small></dd></div>
				<div><dt>Reçu pas encore envoyé</dt><dd><?php echo esc_html( ueb_formater_montant( $c['a_payer'] ) . ' quitus' ); ?></dd></div>
				<div><dt>Reçus renvoyés à l’étudiant</dt><dd><?php echo esc_html( ueb_formater_montant( $c['recus_rejetes'] ) . ' quitus' ); ?></dd></div>
			</dl>
			<?php if ( in_array( 'medicaux', ueb_types_quitus_visibles(), true ) ) : ?><a href="<?php echo esc_url( ueb_url_recus_attente( 'medicaux', false ) ); ?>">Ouvrir les reçus<?php echo ueb_icone( 'fleche', 18 ); ?></a><?php endif; ?>
		</section>
	</div>
	<?php
}
