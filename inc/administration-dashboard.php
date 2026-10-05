<?php
/** Vue analytique de l'administration, alimentée par les agrégats métier. */
defined( 'ABSPATH' ) || exit;

/** Montant compact ; le montant exact reste affiché dans la légende financière. */
function ueb_adm_montant_court( $montant ) {
	if ( $montant >= 1000000000 ) {
		return number_format( $montant / 1000000000, 2, ',', ' ' ) . ' Md';
	}
	if ( $montant >= 1000000 ) {
		return number_format( $montant / 1000000, 2, ',', ' ' ) . ' M';
	}
	return ueb_formater_montant( $montant );
}

/**
 * Cartes, anneau financier, courbes cumulées et comparaison du recouvrement.
 *
 * Repris tel quel par le tableau de bord de la scolarité, limité à son
 * établissement : $options y change les liens et retire ce qui n'appartient
 * qu'à l'administration.
 *
 * @param array $options url (callable array $args => URL de l'espace, non
 *                       échappée ; défaut : l'administration filtrée sur $focus),
 *                       perimetre (bool : choix de l'établissement),
 *                       paiements (URL du suivi des paiements ; '' = sans lien),
 *                       ipes (bool : panneau des IPES sous tutelle),
 *                       attente (arguments de ueb_adm_carte_attente() : la carte
 *                       rouge des quitus à vérifier passe en tête, à la place
 *                       de « Reçus à vérifier »),
 *                       note_quitus (sous-titre de la carte « Quitus générés »).
 */
function ueb_adm_dashboard( array $c, array $suivi, array $activite, $focus, $periode, array $options = array() ) {
	$g       = $suivi['global'];
	$taux    = ueb_suivi_taux( $g );
	$reste   = max( 0, $g['attendu'] - $g['encaisse'] );
	$propre  = isset( $options['url'] );
	$url     = $propre ? $options['url'] : static fn( array $args = array() ) => add_query_arg( $args, ueb_url_administration() );
	$args    = $focus && ! $propre ? array( 'etab' => $focus ) : array();
	$options = array_merge( array( 'perimetre' => true, 'paiements' => $url( $args + array( 'vue' => 'paiements' ) ), 'ipes' => true, 'attente' => null, 'note_quitus' => 'Droits et frais médicaux' ), $options );
	$parts   = array(
		'encaisse'     => array( 'Encaissé', 'var(--dash-foret)' ),
		'verification' => array( 'En vérification', 'var(--dash-bleu)' ),
		'declare'      => array( 'Déclaré, sans reçu', 'var(--dash-or)' ),
		'non_declare'  => array( 'Non déclaré', 'var(--dash-neutre)' ),
	);
	$cartes = array(
		array( 'Étudiants', ueb_formater_montant( $c['etudiants'] ), 'Avec au moins un quitus', 'groupe', 'foret' ),
		array( 'Droits encaissés', ueb_adm_montant_court( $g['encaisse'] ), 'FCFA · paiements vérifiés', 'banque', 'foret' ),
		array( 'Quitus générés', ueb_formater_montant( $c['quitus'] ), $options['note_quitus'], 'fichier', 'foret' ),
		array( 'Recouvrement', $g['attendu'] ? ueb_pourcent( $taux ) : '—', 'Des droits universitaires attendus', 'check', 'vert' ),
		array( 'Reçus à vérifier', ueb_formater_montant( $c['recus_envoyes'] ), 'En attente de la scolarité', 'horloge', 'foret' ),
	);
	$hist = $suivi['historique'] ?? array( 'jours' => array() );
	$cles = array( 'etudiants', 'encaisse', 'quitus', 'taux', 'depots' );
	/* La carte rouge remplace « Reçus à vérifier » et passe en tête. */
	if ( $options['attente'] ) {
		array_pop( $cartes );
		array_pop( $cles );
	}
	?>
	<div class="adm-pilotage">
		<div class="adm-pilotage__contexte"><span class="adm-pilotage__repere" aria-hidden="true"></span><b>Vue d’ensemble</b><span>Année académique en cours</span></div>
		<?php if ( $options['perimetre'] ) : ?>
		<form class="adm-perimetre" method="get" action="<?php echo esc_url( ueb_url_administration() ); ?>">
			<label for="adm-etablissement">Établissement</label>
			<input type="hidden" name="periode" value="<?php echo (int) $periode; ?>">
			<select id="adm-etablissement" name="etab">
				<option value="">Toute l’université</option>
				<?php foreach ( ueb_etablissements() as $sigle => $e ) : ?>
					<option value="<?php echo esc_attr( $sigle ); ?>" <?php selected( $focus, $sigle ); ?>><?php echo esc_html( $sigle . ' — ' . $e['fr'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="adm-bouton">Afficher</button>
		</form>
		<?php endif; ?>
	</div>
	<div class="adm-tendances-tete">
		<p>Évolution sur <b><?php echo (int) $periode; ?> jours</b></p>
		<nav class="adm-periodes" aria-label="Période des graphiques">
			<?php foreach ( array( 7, 30, 90 ) as $jours ) : ?>
				<a href="<?php echo esc_url( $url( $args + array( 'periode' => $jours ) ) ); ?>" <?php echo $jours === $periode ? 'aria-current="true"' : ''; ?>><?php echo (int) $jours; ?> jours</a>
			<?php endforeach; ?>
		</nav>
	</div>
	<div class="adm-kpis" aria-label="Indicateurs de l’année académique">
		<?php if ( $options['attente'] ) { ueb_adm_carte_attente( $options['attente'] ); } ?>
		<?php foreach ( $cartes as $i => $carte ) : ?>
			<article class="adm-kpi adm-kpi--<?php echo esc_attr( $carte[4] ); ?>">
				<div class="adm-kpi__entete"><h2><?php echo esc_html( $carte[0] ); ?></h2><?php echo ueb_icone( $carte[3], 18 ); ?></div>
				<p class="adm-kpi__valeur"><?php echo esc_html( $carte[1] ); ?></p>
				<p class="adm-kpi__note"><?php echo esc_html( $carte[2] ); ?></p>
				<?php ueb_adm_mini_courbe( $cles[ $i ], $hist['jours'], $hist[ $cles[ $i ] ] ?? array() ); ?>
			</article>
		<?php endforeach; ?>
	</div>
	<?php ueb_adm_donnees_courbes( $hist ); ?>
	<div class="adm-analyse">
		<section class="adm-panneau adm-finances" aria-labelledby="adm-finances-titre">
			<header class="adm-panneau__tete"><div><h2 id="adm-finances-titre">Recouvrement des droits</h2><p>Répartition des montants attendus</p></div><?php echo ueb_icone( 'banque', 19 ); ?></header>
			<div class="adm-donut">
				<svg viewBox="0 0 200 200" aria-hidden="true">
					<circle class="adm-donut__piste" cx="100" cy="100" r="76"/>
					<?php $offset = 0; foreach ( $parts as $cle => $part ) :
						$longueur = $g['attendu'] ? 100 * $g[ $cle ] / $g['attendu'] : 0;
						if ( $longueur > 0 ) : ?>
							<circle cx="100" cy="100" r="76" pathLength="100" stroke="<?php echo esc_attr( $part[1] ); ?>" stroke-dasharray="<?php echo esc_attr( $longueur . ' ' . ( 100 - $longueur ) ); ?>" stroke-dashoffset="<?php echo esc_attr( -$offset ); ?>"/>
						<?php endif; $offset += $longueur; endforeach; ?>
				</svg>
				<p><b><?php echo esc_html( $g['attendu'] ? ueb_pourcent( $taux ) : '—' ); ?></b><span><?php echo $g['attendu'] ? 'encaissés' : 'Aucun droit attendu'; ?></span></p>
			</div>
			<ul class="adm-finances__legende">
				<?php foreach ( $parts as $cle => $part ) : ?>
					<li><i style="background: <?php echo esc_attr( $part[1] ); ?>" aria-hidden="true"></i><span><?php echo esc_html( $part[0] ); ?></span><b><?php echo esc_html( ueb_formater_montant( $g[ $cle ] ) ); ?><small> FCFA</small></b></li>
				<?php endforeach; ?>
			</ul>
			<p class="adm-finances__total"><span>Total attendu</span><b><?php echo esc_html( ueb_formater_montant( $g['attendu'] ) ); ?> <small>FCFA</small></b></p>
		</section>
		<section class="adm-panneau adm-evolution" aria-label="Évolution des quitus">
			<?php ueb_graphe_courbes( 'Évolution des quitus', 'Cumuls de l’année · fenêtre de ' . $periode . ' jours', $activite ); ?>
			<div class="adm-evolution__bilan">
				<div><b><?php echo esc_html( ueb_formater_montant( $c['quitus'] ) ); ?></b><span>Quitus générés</span></div>
				<div><b><?php echo esc_html( ueb_formater_montant( $c['recus_verifies'] ) ); ?></b><span>Paiements vérifiés</span></div>
				<div><b><?php echo esc_html( ueb_formater_montant( $g['soldes'] ) ); ?></b><span>Droits soldés</span></div>
				<div><b><?php echo esc_html( ueb_formater_montant( $c['recus_rejetes'] ) ); ?></b><span>Reçus à corriger</span></div>
			</div>
		</section>
	</div>
	<div class="adm-secondaire">
		<?php ueb_adm_verifies( $c ); ?>
		<section class="adm-solde" aria-labelledby="adm-solde-titre">
			<header><h2 id="adm-solde-titre">Reste à encaisser</h2><?php echo ueb_icone( 'banque', 21 ); ?></header>
			<p class="adm-solde__montant"><?php echo esc_html( ueb_adm_montant_court( $reste ) ); ?><small>FCFA</small></p>
			<p class="adm-solde__precision">Sur <?php echo esc_html( ueb_formater_montant( $g['attendu'] ) ); ?> FCFA de droits attendus.</p>
			<dl>
				<div><dt>En vérification</dt><dd><?php echo esc_html( ueb_formater_montant( $g['verification'] ) ); ?> <small>FCFA</small></dd></div>
				<div><dt>Paiement partiel</dt><dd><?php echo esc_html( ueb_suivi_etudiants( $g['partiels'] ) ); ?></dd></div>
				<div><dt>Aucun paiement vérifié</dt><dd><?php echo esc_html( ueb_suivi_etudiants( $g['aucun'] ) ); ?></dd></div>
			</dl>
			<?php if ( $options['paiements'] ) : ?>
				<a href="<?php echo esc_url( $options['paiements'] ); ?>">Consulter les paiements<?php echo ueb_icone( 'fleche', 18 ); ?></a>
			<?php endif; ?>
		</section>
	</div>
	<?php
	if ( ! $options['ipes'] ) {
		return;
	}
	/* IPES : tous, ou ceux de l'établissement filtré (et seulement sa part). */
	ueb_ipes_panneau_synthese(
		ueb_ipes_synthese( ueb_ipes_liste( $focus ? array( 'etablissement' => $focus ) : array() ), $focus ? array( $focus ) : null ),
		array(
			'url'    => $url( array( 'vue' => 'ipes' ) + ( $focus ? array( 'tutelle' => strtolower( $focus ) ) : array() ) ),
			'portee' => $focus ? 'IPES sous la tutelle de ' . $focus . ' : seule sa part des reversements (' . ueb_fcfa( UEB_IPES_REVERSEMENT_PAR_ETUDIANT ) . ' par étudiant).' : '',
		)
	);
}

/* ---------- Carte « Quitus en attente de vérification » ----------
   En tête des tableaux de bord de la scolarité et du Centre médico-social. */

/**
 * Image fixe des reçus en attente, identique à la dernière image de la
 * composition Remotion « attente » (_source/remotion/src/AttenteRecus.tsx) :
 * même viewBox 0 0 240 96, mêmes reçus, même éventail.
 */
function ueb_adm_attente_repli( $nombre ) {
	$k        = max( 1, min( 5, (int) $nombre ) );
	$largeur  = 44;
	$hauteur  = 60;
	$pas      = 40;
	$haut     = 18;
	$inclines = array( -6, 3, -3, 5, -2 );
	$x0       = ( 240 - ( $largeur + ( $k - 1 ) * $pas ) ) / 2;
	$contour  = sprintf( 'M0 4Q0 0 4 0H%1$dQ%2$d 0 %2$d 4V%3$d', $largeur - 4, $largeur, $hauteur - 4 );
	for ( $x = $largeur, $creux = true; $x > 0; $x -= 4, $creux = ! $creux ) {
		$contour .= sprintf( 'L%d %d', $x - 4, $creux ? $hauteur : $hauteur - 4 );
	}
	$contour .= 'Z';
	$svg = '<svg class="adm-attente__repli" viewBox="0 0 240 96" aria-hidden="true" focusable="false"><rect x="10" y="86" width="220" height="4" rx="2" fill="rgba(255,255,255,0.22)"/>';
	/* Du plus récent au plus ancien : le plus ancien, dessiné en dernier, passe au-dessus. */
	for ( $i = $k - 1; $i >= 0; $i-- ) {
		$x    = $x0 + $i * $pas;
		$svg .= sprintf(
			'<g transform="rotate(%1$s %2$s %3$s) translate(%4$s %5$d)"><path d="%6$s" transform="translate(0 2)" fill="#3a0c08" opacity="0.28"/><path d="%6$s" fill="#fffaf3"/><rect x="6" y="7" width="20" height="3" rx="1.5" fill="#b3261e"/><rect x="6" y="16" width="30" height="2.4" rx="1.2" fill="#d6cfc4"/><rect x="6" y="22" width="24" height="2.4" rx="1.2" fill="#d6cfc4"/><rect x="6" y="28" width="28" height="2.4" rx="1.2" fill="#d6cfc4"/><rect x="6" y="39" width="20" height="4" rx="2" fill="#16241c"/>%7$s</g>',
			$inclines[ $i ],
			$x + $largeur / 2,
			$haut + $hauteur / 2,
			$x,
			$haut,
			$contour,
			0 === $i ? sprintf( '<circle cx="%d" cy="6" r="4.4" fill="#f1c45b" stroke="#a3241c" stroke-width="1.4"/>', $largeur - 5 ) : ''
		);
	}
	return $svg . '</svg>';
}

/**
 * Première carte du tableau de bord : les quitus dont le reçu attend la
 * vérification de cet espace. Rouge et cliquable tant qu'il y en a : elle
 * ouvre le registre déjà filtré sur eux. Les reçus s'y posent à l'arrivée
 * (Remotion, une lecture) et un repère bat dans le coin. Sans reçu en
 * attente, elle s'apaise : plus rien n'est urgent.
 *
 * @param array $a nombre (quitus en attente), url (registre filtré, non échappée),
 *                 depuis (date du plus ancien en attente, au format MySQL, ou '').
 */
function ueb_adm_carte_attente( array $a ) {
	$n      = (int) ( $a['nombre'] ?? 0 );
	$depuis = empty( $a['depuis'] ) ? '' : human_time_diff( strtotime( $a['depuis'] ), current_time( 'timestamp' ) );
	if ( ! $n ) :
		?>
		<a class="adm-kpi adm-attente adm-attente--a-jour" href="<?php echo esc_url( $a['url'] ); ?>">
			<div class="adm-kpi__entete"><h2>Quitus en attente de vérification</h2><?php echo ueb_icone( 'check', 18 ); ?></div>
			<p class="adm-kpi__valeur">0</p>
			<p class="adm-kpi__note">Aucun reçu ne t’attend. Les prochains arriveront ici.</p>
			<span class="adm-attente__action">Ouvrir la liste des quitus<?php echo ueb_icone( 'chevron-d', 16 ); ?></span>
		</a>
		<?php
		return;
	endif;
	?>
	<a class="adm-kpi adm-attente" href="<?php echo esc_url( $a['url'] ); ?>">
		<div class="adm-kpi__entete">
			<h2>Quitus en attente de vérification</h2>
			<span class="adm-attente__balise" aria-hidden="true"><i></i></span>
		</div>
		<p class="adm-kpi__valeur"><?php echo (int) $n; ?><span class="sr"> <?php echo 1 === $n ? 'quitus attend' : 'quitus attendent'; ?> ta vérification</span></p>
		<p class="adm-kpi__note"><?php echo esc_html( $depuis ? sprintf( 'Le plus ancien attend depuis %s.', $depuis ) : 'Reçus envoyés, à comparer aux originaux.' ); ?></p>
		<div class="animation adm-attente__scene" data-remotion="attente" data-props="<?php echo esc_attr( wp_json_encode( array( 'nombre' => $n ) ) ); ?>" aria-hidden="true"><div class="animation__scene" data-remotion-scene><?php echo ueb_adm_attente_repli( $n ); // phpcs:ignore -- SVG construit ci-dessus ?></div></div>
		<span class="adm-attente__action">Vérifier maintenant<?php echo ueb_icone( 'chevron-d', 16 ); ?></span>
	</a>
	<?php
}

/** Valeur exacte des courbes ; le taux reste indéfini en l'absence de droits. */
function ueb_adm_valeur_historique( $cle, $valeur ) {
	if ( null === $valeur ) {
		return 'Aucun droit attendu';
	}
	return 'taux' === $cle ? number_format( $valeur, 1, ',', ' ' ) . ' %' : ueb_formater_montant( $valeur ) . ( 'encaisse' === $cle ? ' FCFA' : '' );
}

/** Mini-courbe à base zéro, lisible sans JS, avec repère au survol et au clavier. */
function ueb_adm_mini_courbe( $cle, array $jours, array $valeurs ) {
	$libelles = array( 'etudiants' => 'Étudiants · cumul', 'encaisse' => 'Encaissé · cumul', 'quitus' => 'Quitus · cumul', 'taux' => 'Taux de recouvrement', 'depots' => 'Quitus avec reçu déposé / jour' );
	$n = min( count( $jours ), count( $valeurs ) );
	if ( $n < 2 ) {
		echo '<p class="adm-spark-vide">Historique disponible dès deux jours.</p>';
		return;
	}
	$valeurs = array_slice( $valeurs, 0, $n );
	$haut = 'taux' === $cle ? 100 : max( 1, ...array_map( static fn( $v ) => $v ?? 0, $valeurs ) );
	$points = $troncons = $segment = array();
	foreach ( $valeurs as $i => $v ) {
		$x = round( 4 + 232 * $i / ( $n - 1 ), 2 );
		$y = null === $v ? null : round( 54 - 44 * $v / $haut, 2 );
		$points[] = array( $x, $y );
		if ( null === $y ) {
			if ( $segment ) { $troncons[] = $segment; $segment = array(); }
		} else {
			$segment[] = $x . ',' . $y;
		}
	}
	if ( $segment ) { $troncons[] = $segment; }
	$ligne = $aire = '';
	foreach ( $troncons as $troncon ) {
		$trace = 'M' . implode( ' L', $troncon );
		$ligne .= $trace . ' ';
		$aire .= $trace . ' L' . explode( ',', end( $troncon ) )[0] . ',58 L' . explode( ',', $troncon[0] )[0] . ',58 Z ';
	}
	$delta = null !== $valeurs[0] && null !== $valeurs[ $n - 1 ] ? $valeurs[ $n - 1 ] - $valeurs[0] : null;
	$variation = null === $delta ? 'Début du suivi' : ( abs( $delta ) < .05 ? 'Stable' : ( $delta > 0 ? '+' : '−' ) . ( 'taux' === $cle ? number_format( abs( $delta ), 1, ',', ' ' ) . ' pts' : ueb_adm_montant_court( abs( $delta ) ) . ( 'encaisse' === $cle ? ' FCFA' : '' ) ) );
	if ( 'depots' === $cle ) { $variation = ueb_formater_montant( array_sum( $valeurs ) ) . ' dépôts'; }
	$valeurs_texte = array_map( static fn( $v ) => ueb_adm_valeur_historique( $cle, $v ), $valeurs );
	$dates = array_map( 'ueb_graphe_date_courte', array_slice( $jours, 0, $n ) );
	$donnees = array( 'dates' => $dates, 'valeurs' => $valeurs_texte, 'points' => $points );
	$id = 'adm-spark-' . $cle;
	?>
	<figure class="adm-spark" data-mini-courbe="<?php echo esc_attr( wp_json_encode( $donnees ) ); ?>">
		<figcaption><span><?php echo esc_html( $libelles[ $cle ] ); ?></span><b title="<?php echo esc_attr( 'depots' === $cle ? 'Somme des dépôts quotidiens sur la période' : 'Variation entre le premier et le dernier jour affichés' ); ?>"><?php echo esc_html( $variation ); ?></b></figcaption>
		<div class="adm-spark__zone" tabindex="0" role="group" aria-label="<?php echo esc_attr( $libelles[ $cle ] . ', du ' . $dates[0] . ' au ' . $dates[ $n - 1 ] . '. Flèches gauche et droite pour parcourir les jours.' ); ?>" aria-describedby="<?php echo esc_attr( $id . '-valeur' ); ?>">
			<svg viewBox="0 0 240 62" preserveAspectRatio="none" aria-hidden="true">
				<defs><linearGradient id="<?php echo esc_attr( $id ); ?>" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#b9e4b7" stop-opacity=".26"/><stop offset="100%" stop-color="#b9e4b7" stop-opacity=".015"/></linearGradient></defs>
				<path class="adm-spark__base" d="M4,54 H236"/>
				<path d="<?php echo esc_attr( $aire ); ?>" fill="url(#<?php echo esc_attr( $id ); ?>)"/>
				<path class="adm-spark__ligne" d="<?php echo esc_attr( $ligne ); ?>"/>
				<?php if ( null !== $points[ $n - 1 ][1] ) : ?><circle class="adm-spark__fin" cx="236" cy="<?php echo esc_attr( $points[ $n - 1 ][1] ); ?>" r="2.8"/><?php endif; ?>
				<path class="adm-spark__repere" d="M236,4 V58"/>
				<circle class="adm-spark__point" cx="236" cy="54" r="3"/>
			</svg>
			<output class="adm-spark__lecture" id="<?php echo esc_attr( $id . '-valeur' ); ?>" aria-live="polite"><?php echo esc_html( $dates[ $n - 1 ] . ' · ' . $valeurs_texte[ $n - 1 ] ); ?></output>
		</div>
		<div class="adm-spark__dates" aria-hidden="true"><span><?php echo esc_html( $dates[0] ); ?></span><span><?php echo esc_html( $dates[ $n - 1 ] ); ?></span></div>
	</figure>
	<?php
}

/** Données accessibles et définition explicite de l'historique reconstitué. */
function ueb_adm_donnees_courbes( array $historique ) {
	if ( empty( $historique['jours'] ) ) { return; }
	?>
	<details class="adm-historique">
		<summary>Données des mini-courbes<?php echo ueb_icone( 'chevron', 14 ); ?></summary>
		<p>Les cumuls sont reconstitués à partir des quitus conservés, des dates de validation et des formations actuelles. Les anciennes décisions annulées et les pièces supprimées ne sont pas conservées dans cet historique. Les droits encaissés excluent les frais médicaux et les trop-perçus.</p>
		<p>La courbe « Reçus à vérifier » indique les dépôts quotidiens, pas l’ancienne file d’attente : un quitus compte une fois par jour de dépôt, même avec plusieurs pièces. Les variations comparent le premier et le dernier jour affichés.</p>
		<div class="adm-historique__table" tabindex="0" role="region" aria-label="Historique quotidien des indicateurs">
			<table><caption class="sr">Valeurs des mini-courbes par jour</caption><thead><tr><th scope="col">Date</th><th scope="col">Étudiants</th><th scope="col">Droits encaissés</th><th scope="col">Quitus</th><th scope="col">Recouvrement</th><th scope="col">Dépôts du jour</th></tr></thead><tbody>
				<?php foreach ( $historique['jours'] as $i => $jour ) : ?><tr><th scope="row"><?php echo esc_html( ueb_graphe_date_courte( $jour ) ); ?></th><?php foreach ( array( 'etudiants', 'encaisse', 'quitus', 'taux', 'depots' ) as $cle ) : ?><td><?php echo esc_html( ueb_adm_valeur_historique( $cle, $historique[ $cle ][ $i ] ) ); ?></td><?php endforeach; ?></tr><?php endforeach; ?>
			</tbody></table>
		</div>
	</details>
	<?php
}

/** Situation des quitus : total de l'année et barres par statut. */
function ueb_adm_statistiques( array $c, $sous_titre = 'Droits universitaires et frais médicaux' ) {
	?>
	<section class="adm-panneau adm-statistiques" aria-labelledby="adm-statistiques-titre">
		<header class="adm-panneau__tete"><div><h2 id="adm-statistiques-titre">Situation des quitus</h2><p><?php echo esc_html( $sous_titre ); ?></p></div></header>
		<p class="adm-statistiques__total"><b><?php echo esc_html( ueb_formater_montant( $c['quitus'] ) ); ?></b><span>quitus cette année</span></p>
		<ul class="adm-barres-statut">
			<?php foreach ( array(
				array( 'À payer', $c['a_payer'], 'var(--dash-or)' ),
				array( 'À vérifier', $c['recus_envoyes'], 'var(--dash-bleu)' ),
				array( 'Vérifiés', $c['recus_verifies'], 'var(--dash-foret)' ),
				array( 'À corriger', $c['recus_rejetes'], 'var(--danger)' ),
			) as $statut ) : ?>
				<li><span><?php echo esc_html( $statut[0] ); ?></span><b><?php echo esc_html( ueb_formater_montant( $statut[1] ) ); ?></b><span class="adm-barre" aria-hidden="true"><i style="--largeur: <?php echo esc_attr( $c['quitus'] ? 100 * $statut[1] / $c['quitus'] : 0 ); ?>%; --couleur: <?php echo esc_attr( $statut[2] ); ?>"></i></span></li>
			<?php endforeach; ?>
		</ul>
	</section>
	<?php
}

/**
 * Barres de taux sur une échelle commune 0–100 %, avec valeurs et liens.
 *
 * @param string|null $url_filieres Lien des filières (un établissement) ;
 *                                  null = suivi des paiements de l'administration.
 */
function ueb_adm_comparaison( array $suivi, $focus, $periode, $url_filieres = null ) {
	$lignes = array();
	if ( $focus ) {
		foreach ( array_slice( $suivi['filieres'], 0, 6, true ) as $f ) {
			$lignes[] = array( 'nom' => $f['libelle'], 'titre' => $f['libelle'], 'suivi' => $f, 'url' => $url_filieres ?? add_query_arg( array( 'vue' => 'paiements', 'etab' => $focus ), ueb_url_administration() ) );
		}
	} else {
		foreach ( ueb_etablissements() as $sigle => $etab ) {
			$lignes[] = array( 'nom' => $sigle, 'titre' => $etab['fr'], 'suivi' => $suivi['etabs'][ $sigle ] ?? ueb_suivi_vide(), 'url' => add_query_arg( array( 'etab' => $sigle, 'periode' => $periode ), ueb_url_administration() ) );
		}
	}
	usort( $lignes, static fn( $a, $b ) => ueb_suivi_taux( $b['suivi'] ) <=> ueb_suivi_taux( $a['suivi'] ) ?: strcmp( $a['nom'], $b['nom'] ) );
	?>
	<section class="adm-panneau adm-comparaison<?php echo $focus ? ' adm-comparaison--filieres' : ''; ?>" aria-labelledby="adm-comparaison-titre">
		<header class="adm-panneau__tete"><div><h2 id="adm-comparaison-titre"><?php echo $focus ? 'Recouvrement par filière' : 'Recouvrement par établissement'; ?></h2><p><?php echo $focus ? 'Les six plus gros montants attendus' : 'Part encaissée des droits attendus'; ?></p></div></header>
		<?php if ( ! $lignes ) : ?>
			<p class="graphe__vide">Les filières apparaîtront dès le premier quitus de droits universitaires.</p>
		<?php else : ?>
			<div class="adm-comparaison__axe" aria-hidden="true"><span>0 %</span><span>50 %</span><span>100 %</span></div>
			<ul>
				<?php foreach ( $lignes as $l ) : $taux = ueb_suivi_taux( $l['suivi'] ); ?>
					<li><a href="<?php echo esc_url( $l['url'] ); ?>" title="<?php echo esc_attr( $l['titre'] ); ?>"><span class="adm-comparaison__nom"><?php echo esc_html( $l['nom'] ); ?></span><span class="adm-barre" aria-hidden="true"><i style="--largeur: <?php echo esc_attr( min( 100, max( 0, $taux ) ) ); ?>%"></i></span><b><?php echo esc_html( $l['suivi']['attendu'] ? ueb_pourcent( $taux ) : '—' ); ?></b><span class="sr"><?php echo esc_html( $l['suivi']['attendu'] ? ' encaissés ; ouvrir le détail' : ' Aucun droit attendu ; ouvrir le détail' ); ?></span></a></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</section>
	<?php
}
