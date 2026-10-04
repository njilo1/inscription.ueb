<?php
/**
 * Suivi des paiements de l'espace scolarité (?vue=paiements) : le rendu de la
 * page Paiements de l'administration (inc/administration-paiements.php),
 * limité à l'établissement de l'agent et recomposé en rangées égales. En haut
 * les cartes, alignées ; en bas les graphes et la courbe ; puis le registre
 * par filière. Les animations sont celles de l'administration
 * (administration-mouvement.js : comptages, colonnes, anneau Remotion), plus
 * le tracé de la courbe (scolarite-paiements.js).
 */
defined( 'ABSPATH' ) || exit;

/**
 * @param array  $suivi ueb_suivi_paiements( …, 366 ) : avec l'historique quotidien.
 * @param string $etab  Établissement de l'agent ; vide pour une portée sur plusieurs.
 */
function ueb_sco_paiements( array $suivi, $etab ) {
	$g            = $suivi['global'];
	$m            = $suivi['medicaux'];
	$annee        = ueb_annee_academique();
	$verification = $g['verification'] + $m['verification'];
	$attendu      = $g['attendu'] + $m['attendu'];
	$encaisse     = $g['encaisse'] + $m['encaisse'];
	$reste        = max( 0, $attendu - $encaisse );
	$statuts      = $suivi['quitus_statuts'];
	$a_verifier   = $statuts['droits']['recu_envoye'] + $statuts['medicaux']['recu_envoye'];
	$a_corriger   = $statuts['droits']['rejete'] + $statuts['medicaux']['rejete'];
	$taux         = ueb_suivi_taux( $g );
	$suivis       = $g['soldes'] + $g['partiels'] + $g['aucun'];
	/* Une portée sur plusieurs établissements liste les établissements, sans
	   lien vers l'administration. */
	$sans_lien    = $etab ? null : static fn() => '';
	$kpis         = array(
		array( 'Frais médicaux encaissés', $m['encaisse'], 'Sur ' . ueb_fcfa( $m['attendu'] ) . ' déclarés', 'bouclier', 'medical', ueb_formater_montant( $m['etudiants'] ) . ' quitus médicaux' ),
		array( 'Montants à vérifier', $verification, 'Droits et frais médicaux', 'horloge', 'verification', $a_verifier . ' quitus avec reçu à contrôler' ),
		array( 'Reste à encaisser', $reste, 'Inclut les montants à vérifier', 'fichier', 'reste', 'Sur les dossiers de l’année' ),
	);
	?>
	<?php /* Les marques animées partent de leur état initial (html.adm-anime),
	   sauf en mouvement réduit ; filet de sécurité à 3 s, comme à l'administration. */ ?>
	<script>(function(h){if(!matchMedia('(prefers-reduced-motion: reduce)').matches){h.classList.add('adm-anime');setTimeout(function(){if(!h.classList.contains('adm-anime-pret')){h.classList.remove('adm-anime');}},3000);}})(document.documentElement);</script>
	<div class="pay adm-paiements sco-pay" data-pay data-annee="<?php echo esc_attr( $annee['code'] ); ?>">

		<div class="sco-pay__cartes">
			<article class="pay-kpi pay-kpi--fort sco-pay__principal" style="--ordre:0">
				<header><h2>Droits encaissés</h2><span><?php echo ueb_icone( 'banque', 18 ); ?></span></header>
				<p class="pay-kpi__valeur" title="<?php echo esc_attr( ueb_fcfa( $g['encaisse'] ) ); ?>"><?php echo esc_html( ueb_adm_montant_court( $g['encaisse'] ) ); ?><small>FCFA</small></p>
				<p class="pay-kpi__note">Sur <?php echo esc_html( ueb_fcfa( $g['attendu'] ) ); ?> attendus</p>
				<div class="sco-pay__taux" role="img" aria-label="<?php echo esc_attr( sprintf( '%s des droits attendus recouvrés', ueb_pourcent( $taux ) ) ); ?>">
					<span class="sco-pay__piste"><i style="width:<?php echo esc_attr( round( min( 100, $taux ), 2 ) ); ?>%"></i></span>
					<span class="pay-kpi__repere"><?php echo esc_html( $g['attendu'] ? ueb_pourcent( $taux ) . ' recouvrés' : 'Aucun droit attendu' ); ?></span>
				</div>
			</article>
			<?php foreach ( $kpis as $i => $k ) : ?>
				<article class="pay-kpi pay-kpi--<?php echo esc_attr( $k[4] ); ?>" style="--ordre:<?php echo (int) $i + 1; ?>">
					<header><h2><?php echo esc_html( $k[0] ); ?></h2><span><?php echo ueb_icone( $k[3], 18 ); ?></span></header>
					<p class="pay-kpi__valeur" title="<?php echo esc_attr( ueb_fcfa( $k[1] ) ); ?>"><?php echo esc_html( ueb_adm_montant_court( $k[1] ) ); ?><small>FCFA</small></p>
					<p class="pay-kpi__note"><?php echo esc_html( $k[2] ); ?></p>
					<span class="pay-kpi__repere"><?php echo esc_html( $k[5] ); ?></span>
				</article>
			<?php endforeach; ?>
		</div>

		<div class="sco-pay__tuiles">
			<section class="pay-tuile">
				<header><span class="pay-tuile__icone"><?php echo ueb_icone( 'check', 20 ); ?></span><h2>Situation des étudiants</h2></header>
				<p class="pay-tuile__chiffre"><?php echo esc_html( ueb_formater_montant( $suivis ) ); ?><span><?php echo 1 < $suivis ? 'étudiants suivis' : 'étudiant suivi'; ?></span></p>
				<div class="pay-tuile__details">
					<?php ueb_adm_paiements_jauge( array( 'soldes' => $g['soldes'], 'partiels' => $g['partiels'] ), $suivis, sprintf( '%s droits soldés, %s paiements partiels et %s sans paiement vérifié', ueb_formater_montant( $g['soldes'] ), ueb_formater_montant( $g['partiels'] ), ueb_formater_montant( $g['aucun'] ) ) ); ?>
					<p><i class="pay-jauge__soldes"></i><b><?php echo esc_html( ueb_formater_montant( $g['soldes'] ) ); ?></b> droits soldés</p>
					<p><i class="pay-jauge__partiels"></i><b><?php echo esc_html( ueb_formater_montant( $g['partiels'] ) ); ?></b> paiements partiels</p>
					<p><i class="pay-jauge__reste"></i><b><?php echo esc_html( ueb_formater_montant( $g['aucun'] ) ); ?></b> sans paiement vérifié</p>
				</div>
			</section>
			<section class="pay-tuile pay-tuile--attente">
				<header><span class="pay-tuile__icone"><?php echo ueb_icone( 'horloge', 20 ); ?></span><h2>Contrôles en attente</h2></header>
				<p class="pay-tuile__chiffre"><?php echo esc_html( ueb_formater_montant( $a_verifier ) ); ?><span>quitus à vérifier</span></p>
				<div class="pay-tuile__details">
					<?php ueb_adm_paiements_jauge( array( 'droits' => $statuts['droits']['recu_envoye'], 'medicaux' => $statuts['medicaux']['recu_envoye'] ), $a_verifier, sprintf( 'Quitus à vérifier : %d de droits universitaires et %d de frais médicaux', $statuts['droits']['recu_envoye'], $statuts['medicaux']['recu_envoye'] ) ); ?>
					<p><i class="pay-jauge__droits"></i><b><?php echo (int) $statuts['droits']['recu_envoye']; ?></b> droits universitaires</p>
					<p><i class="pay-jauge__medicaux"></i><b><?php echo (int) $statuts['medicaux']['recu_envoye']; ?></b> frais médicaux</p>
					<p class="pay-tuile__corriger"><?php echo ueb_icone( 'alerte', 15 ); ?><b><?php echo (int) $a_corriger; ?></b> quitus à corriger</p>
				</div>
			</section>
			<?php ueb_adm_paiements_repartition( $g['encaisse'], $m['encaisse'] ); ?>
		</div>

		<div class="sco-pay__graphes">
			<?php ueb_adm_paiements_histogramme( ueb_adm_paiements_mois( $suivi['historique'] ?? array(), $annee['debut'] . '-09-01' ) ); ?>
			<?php ueb_sco_paiements_courbe( $suivi['historique'] ?? array(), $annee['debut'] . '-09-01' ); ?>
		</div>

		<div class="pay-duo">
			<?php ueb_adm_paiements_niveaux( $suivi['niveaux'], $g, $etab ); ?>
			<?php ueb_adm_paiements_rapprochement( $suivi, $etab, $sans_lien ); ?>
		</div>

		<?php ueb_adm_paiements_registre( ueb_adm_paiements_lignes( $suivi, $etab ), $etab, $sans_lien ); ?>
	</div>
	<?php
}

/**
 * Progression des encaissements : le cumul vérifié, jour après jour, des
 * droits universitaires (aire et trait) et des frais médicaux (trait), depuis
 * la semaine qui précède le premier encaissement, jamais avant la rentrée.
 * Le dernier point porte le montant atteint.
 */
function ueb_sco_paiements_courbe( array $historique, $debut_annee ) {
	$jours    = array_values( (array) ( $historique['jours'] ?? array() ) );
	$droits   = array_values( array_map( 'intval', (array) ( $historique['encaisse'] ?? array() ) ) );
	$medicaux = array_values( array_map( 'intval', (array) ( $historique['medicaux'] ?? array() ) ) );
	$premier  = null;
	foreach ( $jours as $i => $jour ) {
		if ( $jour >= $debut_annee && ( ( $droits[ $i ] ?? 0 ) > 0 || ( $medicaux[ $i ] ?? 0 ) > 0 ) ) {
			$premier = $i;
			break;
		}
	}
	$depart = 0;
	foreach ( $jours as $i => $jour ) {
		if ( $jour >= $debut_annee ) {
			$depart = $i;
			break;
		}
	}
	if ( null !== $premier ) {
		$depart   = max( $depart, $premier - 7 );
		$jours    = array_slice( $jours, $depart );
		$droits   = array_slice( $droits, $depart );
		$medicaux = array_slice( $medicaux, $depart );
	}
	$n       = count( $jours );
	$fin_d   = $n ? end( $droits ) : 0;
	$fin_m   = $n ? end( $medicaux ) : 0;
	$maximum = max( 1, $n ? max( max( $droits ), max( $medicaux ) ) : 1 );
	$echelle = ueb_graphe_echelle( $maximum );
	$haut    = $echelle['haut'];
	$noms    = array( 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.' );
	/* Quatre-vingt-dix points au plus : le dernier jour est toujours gardé. */
	$pas     = max( 1, (int) ceil( $n / 90 ) );
	$indices = $n ? range( 0, $n - 1, $pas ) : array();
	if ( $n && end( $indices ) !== $n - 1 ) {
		$indices[] = $n - 1;
	}
	$x     = static fn( $i ) => $n > 1 ? round( 100 * $i / ( $n - 1 ), 3 ) : 0;
	$y     = static fn( $v ) => round( 100 - 100 * $v / $haut, 3 );
	$trace = static function ( array $serie ) use ( $indices, $x, $y ) {
		return 'M' . implode( ' L', array_map( static fn( $i ) => $x( $i ) . ' ' . $y( $serie[ $i ] ), $indices ) );
	};
	$mois = array();
	foreach ( $jours as $i => $jour ) {
		if ( '01' === substr( $jour, 8, 2 ) || 0 === $i ) {
			$mois[] = array( 'x' => $x( $i ), 'nom' => $noms[ (int) substr( $jour, 5, 2 ) - 1 ], 'debut' => 0 === $i );
		}
	}
	/* Le premier jour n'a son étiquette que s'il ne colle pas au mois suivant. */
	if ( count( $mois ) > 1 && $mois[0]['debut'] && $mois[1]['x'] < 12 ) {
		array_shift( $mois );
	}
	?>
	<section class="pay-panneau sco-courbe" aria-labelledby="sco-courbe-titre" data-sco-courbe>
		<header class="pay-entete"><div><h2 id="sco-courbe-titre">Progression des encaissements</h2><p>Cumul vérifié, jour après jour</p></div><span class="pay-pastille">Depuis la rentrée</span></header>
		<p class="sco-courbe__total"><b><?php echo esc_html( ueb_adm_montant_court( $fin_d + $fin_m ) ); ?></b><span>FCFA encaissés au total, dont <?php echo esc_html( ueb_fcfa( $fin_m ) ); ?> de frais médicaux</span></p>
		<?php if ( null === $premier || $n < 2 ) : ?>
			<p class="pay-aucune-activite">La courbe apparaîtra avec les premiers paiements vérifiés.</p>
		<?php else : ?>
			<div class="sco-courbe__graphe" role="img" aria-label="<?php echo esc_attr( sprintf( 'Du %s au %s : droits universitaires de %s à %s, frais médicaux de %s à %s', mysql2date( 'j F', $jours[0] ), mysql2date( 'j F', $jours[ $n - 1 ] ), ueb_fcfa( $droits[0] ), ueb_fcfa( $fin_d ), ueb_fcfa( $medicaux[0] ), ueb_fcfa( $fin_m ) ) ); ?>">
				<div class="pay-histogramme__axe" aria-hidden="true"><?php for ( $v = 0; $v <= $haut; $v += $echelle['pas'] ) : ?><span style="--y:<?php echo esc_attr( 100 * $v / $haut ); ?>%"><?php echo esc_html( ueb_adm_montant_court( $v ) ); ?></span><?php endfor; ?></div>
				<div class="sco-courbe__zone" aria-hidden="true">
					<div class="pay-histogramme__grille"><?php for ( $v = 0; $v <= $haut; $v += $echelle['pas'] ) : ?><i style="--y:<?php echo esc_attr( 100 * $v / $haut ); ?>%"></i><?php endfor; ?></div>
					<svg class="sco-courbe__trace" viewBox="0 0 100 100" preserveAspectRatio="none">
						<path class="sco-courbe__aire" d="<?php echo esc_attr( $trace( $droits ) . ' L100 100 L0 100 Z' ); ?>"/>
						<path class="sco-courbe__droits" d="<?php echo esc_attr( $trace( $droits ) ); ?>"/>
						<?php if ( $fin_m ) : ?><path class="sco-courbe__medicaux" d="<?php echo esc_attr( $trace( $medicaux ) ); ?>"/><?php endif; ?>
					</svg>
					<span class="sco-courbe__point sco-courbe__point--droits" style="--x:100%;--v:<?php echo esc_attr( round( 100 * $fin_d / $haut, 3 ) ); ?>%"><b><?php echo esc_html( ueb_adm_montant_court( $fin_d ) ); ?></b></span>
					<?php if ( $fin_m ) : ?><span class="sco-courbe__point sco-courbe__point--medicaux" style="--x:100%;--v:<?php echo esc_attr( round( 100 * $fin_m / $haut, 3 ) ); ?>%"></span><?php endif; ?>
					<div class="sco-courbe__mois"><?php foreach ( $mois as $mo ) : ?><span style="--x:<?php echo esc_attr( $mo['x'] ); ?>%"><?php echo esc_html( $mo['nom'] ); ?></span><?php endforeach; ?></div>
				</div>
			</div>
		<?php endif; ?>
		<div class="pay-legende"><span><i class="pay-couleur-droits"></i>Droits universitaires</span><span><i class="pay-couleur-medicaux"></i>Frais médicaux</span></div>
	</section>
	<?php
}

/* ==========================================================================
   Exports du suivi détaillé : PDF institutionnel et tableur Excel
   ========================================================================== */

/** Formats proposés à la scolarité : le rapport PDF et les données en Excel. */
const UEB_SCO_EXPORT_FORMATS = array( 'pdf', 'xlsx' );

/** Lien de téléchargement d'un format (jeton lié à la session de l'agent). */
function ueb_sco_export_url( $format ) {
	return wp_nonce_url( add_query_arg( array( 'vue' => 'paiements', 'export' => $format ), ueb_url_scolarite() ), 'ueb_export_paiements_scolarite', 'jeton' );
}

/*
 * Téléchargement : ?vue=paiements&export=pdf|xlsx&jeton=…
 * Mêmes documents que l'administration (inc/administration-exports.php), sur
 * le seul périmètre de l'agent : l'établissement vient de son compte, jamais
 * de l'adresse.
 */
add_action( 'template_redirect', function () {
	if ( ! isset( $_GET['export'] ) || ! is_page_template( 'page-scolarite.php' ) || 'paiements' !== sanitize_key( $_GET['vue'] ?? '' ) ) { // phpcs:ignore -- lecture seule, contrôlée ci-dessous
		return;
	}
	$etab = ueb_etab_agent();
	if ( ! ueb_est_scolarite() || ! ueb_peut( 'ueb_voir_paiements' ) || UEB_AUCUN_ETAB === $etab ) {
		wp_die( 'Cet export est réservé aux comptes autorisés à suivre les paiements.', 'Accès refusé', array( 'response' => 403 ) );
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['jeton'] ?? '' ) ), 'ueb_export_paiements_scolarite' ) ) {
		wp_die( 'Ce lien d’export a expiré. Recharge la page Paiements puis relance l’export.', 'Lien expiré', array( 'response' => 403 ) );
	}
	$format = sanitize_key( $_GET['export'] );
	if ( ! in_array( $format, UEB_SCO_EXPORT_FORMATS, true ) ) {
		wp_die( 'Format d’export inconnu.', 'Export', array( 'response' => 400 ) );
	}
	$annee = ueb_annee_academique();
	$d     = ueb_adm_rapport_donnees( ueb_suivi_paiements( $annee['code'], $etab, 366 ), $etab, $annee );

	nocache_headers();
	if ( 'pdf' === $format ) {
		ueb_adm_export_pdf( $d );
	} else {
		ueb_adm_export_xlsx( $d );
	}
	exit;
}, 20 );
