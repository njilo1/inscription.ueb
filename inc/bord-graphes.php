<?php
/**
 * Graphiques du tableau de bord de la scolarité : progression de l'année
 * (courbes cumulées) et recouvrement par filière. SVG et HTML rendus côté
 * serveur ; assets/js/bord.js n'ajoute que l'infobulle au survol et au clavier.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

/* ---------- Outils ---------- */

/** « 11 sept. », « 1er oct. » : jamais le format PHP `M`, qui sort en anglais. */
function ueb_graphe_date_courte( $jour ) {
	static $mois = array( 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.' );
	$t = strtotime( $jour );
	$j = (int) gmdate( 'j', $t );
	return ( 1 === $j ? '1er' : $j ) . ' ' . $mois[ (int) gmdate( 'n', $t ) - 1 ];
}

/** « 16 septembre », « 1er octobre ». */
function ueb_graphe_date_longue( $jour ) {
	$t = strtotime( $jour );
	$j = (int) gmdate( 'j', $t );
	return ( 1 === $j ? '1er' : $j ) . ' ' . date_i18n( 'F', $t );
}

/** Singulier ou pluriel selon le nombre. */
function ueb_graphe_accord( $n, array $formes ) {
	return $formes[ $n > 1 ? 1 : 0 ];
}

/**
 * Échelle « ronde » de l'axe vertical : deux ou trois intervalles d'un pas
 * de 1, 2 ou 5 × 10ⁿ, pour trois ou quatre graduations (0 compris).
 *
 * @return array{haut:int, pas:int}
 */
function ueb_graphe_echelle( $maximum ) {
	$maximum = max( 1, (int) $maximum );
	for ( $puissance = 1; ; $puissance *= 10 ) {
		foreach ( array( 1, 2, 5 ) as $m ) {
			$pas = $m * $puissance;
			$n   = (int) ceil( $maximum / $pas );
			if ( $n <= 3 ) {
				return array( 'haut' => max( 2, $n ) * $pas, 'pas' => $pas );
			}
		}
	}
}

/**
 * Écarte verticalement des étiquettes trop proches, sans les sortir des bornes.
 * Les positions sont en % depuis le bas et rangées de la plus haute à la plus
 * basse (l'ordre des séries emboîtées).
 *
 * @param float[] $positions Positions souhaitées.
 * @param float   $ecart     Écart minimal entre deux centres.
 * @param float   $bas       Borne basse.
 * @param float   $haut      Borne haute.
 * @return float[]
 */
function ueb_graphe_ecarter( array $positions, $ecart, $bas, $haut ) {
	$p = array_values( $positions );
	$n = count( $p );
	for ( $tour = 0; $tour < 30; $tour++ ) {
		$bouge = false;
		for ( $i = 0; $i < $n - 1; $i++ ) {
			$manque = $ecart - ( $p[ $i ] - $p[ $i + 1 ] );
			if ( $manque > .01 ) {
				$p[ $i ]     += $manque / 2;
				$p[ $i + 1 ] -= $manque / 2;
				$bouge        = true;
			}
		}
		foreach ( $p as $i => $v ) {
			$p[ $i ] = min( $haut, max( $bas, $v ) );
		}
		if ( ! $bouge ) {
			break;
		}
	}
	return array_map( static fn( $v ) => round( $v, 2 ), $p );
}

/** Tracé SVG d'une série (repère 0–100, origine en haut à gauche). */
function ueb_graphe_trace( array $valeurs, $haut, $aire = false ) {
	$n      = count( $valeurs );
	$points = array();
	foreach ( $valeurs as $i => $v ) {
		$points[] = round( 100 * $i / ( $n - 1 ), 2 ) . ',' . round( 100 - 100 * $v / $haut, 2 );
	}
	return $aire
		? 'M0,100 L' . implode( ' L', $points ) . ' L100,100 Z'
		: 'M' . implode( ' L', $points );
}

/**
 * Pastille qui reprend le style de trait d'une série : la couleur ne porte
 * jamais seule l'identité (trait fin + aire, tirets, trait épais).
 */
function ueb_graphe_cle( $serie ) {
	$traits = array(
		'generes'  => '<rect x="1" y="5" width="18" height="6" rx="1"/><path d="M1 5h18"/>',
		'envoyes'  => '<path d="M1 6h18"/>',
		'verifies' => '<path d="M1 6h18"/>',
	);
	return sprintf(
		'<svg class="courbes__cle courbes__cle--%1$s" width="20" height="12" viewBox="0 0 20 12" aria-hidden="true" focusable="false">%2$s</svg>',
		esc_attr( $serie ),
		$traits[ $serie ]
	);
}

/* ---------- Progression de l'année ---------- */

/**
 * Progression cumulée de l'année : quitus générés, reçus envoyés, quitus
 * vérifiés. Les trois séries sont emboîtées (générés ⊇ reçu envoyé ⊇
 * vérifiés) et se distinguent par la couleur ET le trait : fin sur une aire
 * légère, tirets, épais.
 *
 * La zone de tracé est un SVG étiré (preserveAspectRatio="none", trait non
 * déformé) à hauteur fixe ; tous les textes sont en HTML positionnés en
 * pourcentage par-dessus, ils restent nets à toutes les largeurs. Les points
 * sont reliés en ligne droite d'un jour au suivant : aucun lissage n'invente
 * de valeur. Sans script, la phrase de résumé, les étiquettes de fin de
 * courbe et le tableau pour lecteurs d'écran disent tout.
 *
 * @param string $titre      Titre de la figure.
 * @param string $sous_titre Précision facultative.
 * @param array  $activite   Retour de ueb_gestion_activite() : jours, generes,
 *                           envoyes, verifies (cumuls, même longueur).
 */
function ueb_graphe_courbes( $titre, $sous_titre, array $activite ) {
	$series = array(
		'generes'  => array( 'nom' => 'Quitus générés', 'formes' => array( 'généré', 'générés' ) ),
		'envoyes'  => array( 'nom' => 'Reçu envoyé', 'formes' => array( 'reçu envoyé', 'reçus envoyés' ) ),
		'verifies' => array( 'nom' => 'Vérifiés', 'formes' => array( 'vérifié', 'vérifiés' ) ),
	);
	$jours = array_values( (array) ( $activite['jours'] ?? array() ) );
	$n     = count( $jours );
	foreach ( $series as $cle => $s ) {
		$valeurs = array_map( 'intval', array_values( (array) ( $activite[ $cle ] ?? array() ) ) );
		$n       = min( $n, count( $valeurs ) );
		$series[ $cle ]['valeurs'] = $valeurs;
	}
	$jours   = array_slice( $jours, 0, $n );
	$maximum = 0;
	foreach ( $series as $cle => $s ) {
		$series[ $cle ]['valeurs'] = array_slice( $s['valeurs'], 0, $n );
		$maximum                   = max( $maximum, $n ? max( $series[ $cle ]['valeurs'] ) : 0 );
	}
	?>
	<figure class="graphe graphe--courbes">
		<?php ueb_graphe_entete( $titre, $sous_titre, null ); ?>
		<?php if ( ! $maximum ) : ?>
			<p class="graphe__vide"><?php echo ueb_icone( 'info', 18 ); ?>Aucune donnée pour l’instant.</p>
		<?php elseif ( $n < 2 ) : ?>
			<p class="graphe__vide"><?php echo ueb_icone( 'info', 18 ); ?>La courbe apparaîtra après un deuxième jour d’activité.</p>
		<?php else :
			$echelle = ueb_graphe_echelle( $maximum );
			$haut    = $echelle['haut'];
			$dernier = $n - 1;
			$milieu  = (int) floor( $dernier / 2 );
			$fins    = array();
			foreach ( $series as $cle => $s ) {
				$fins[ $cle ] = 100 * $s['valeurs'][ $dernier ] / $haut;
			}
			/* Étiquettes de fin : deux lignes ≈ 36 px sur 220 px de tracé. Quand
			   le graphique est étroit, elles passent en légende sous le tracé. */
			$positions = array_combine( array_keys( $fins ), ueb_graphe_ecarter( $fins, 17, 0, 92 ) );
			$donnees = array(
				'dates'  => array_map( 'ueb_graphe_date_longue', $jours ),
				'haut'   => $haut,
				'series' => array(),
			);
			foreach ( $series as $cle => $s ) {
				$donnees['series'][] = array( 'cle' => $cle, 'valeurs' => $s['valeurs'], 'formes' => $s['formes'] );
			}
			$resume = array();
			foreach ( $series as $cle => $s ) {
				$v        = $s['valeurs'][ $dernier ];
				$resume[] = '<b>' . esc_html( ueb_formater_montant( $v ) ) . '</b> ' . esc_html( 'generes' === $cle ? 'quitus ' . ueb_graphe_accord( $v, $s['formes'] ) : ueb_graphe_accord( $v, $s['formes'] ) );
			}
			?>
			<p class="courbes__resume"><?php echo $resume[0] . ', ' . $resume[1] . ' et ' . $resume[2] . ' au ' . esc_html( ueb_graphe_date_longue( $jours[ $dernier ] ) ) . '.'; // phpcs:ignore -- échappé ci-dessus ?></p>
			<div class="courbes-cadre"><div class="courbes" data-courbes="<?php echo esc_attr( wp_json_encode( $donnees ) ); ?>">
				<div class="courbes__axe-y" style="--chiffres: <?php echo (int) mb_strlen( ueb_formater_montant( $haut ) ); ?>" aria-hidden="true">
					<?php for ( $g = 0; $g <= $haut; $g += $echelle['pas'] ) : ?>
						<span style="--y: <?php echo esc_attr( round( 100 * $g / $haut, 2 ) ); ?>%"><?php echo esc_html( ueb_formater_montant( $g ) ); ?></span>
					<?php endfor; ?>
				</div>
				<div class="courbes__zone" data-courbes-zone>
					<div class="courbes__grille" aria-hidden="true">
						<?php for ( $g = 0; $g <= $haut; $g += $echelle['pas'] ) : ?>
							<span style="--y: <?php echo esc_attr( round( 100 * $g / $haut, 2 ) ); ?>%"></span>
						<?php endfor; ?>
					</div>
					<svg class="courbes__trace" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true" focusable="false">
						<path class="courbes__aire" d="<?php echo esc_attr( ueb_graphe_trace( $series['generes']['valeurs'], $haut, true ) ); ?>"/>
						<?php foreach ( array( 'verifies', 'envoyes', 'generes' ) as $cle ) : /* le trait épais dessous, les tirets lisibles par-dessus */ ?>
							<path class="courbes__ligne courbes__ligne--<?php echo esc_attr( $cle ); ?>" d="<?php echo esc_attr( ueb_graphe_trace( $series[ $cle ]['valeurs'], $haut ) ); ?>" vector-effect="non-scaling-stroke"/>
						<?php endforeach; ?>
					</svg>
					<?php foreach ( $fins as $cle => $y ) : ?>
						<span class="courbes__point courbes__point--<?php echo esc_attr( $cle ); ?> courbes__point--fin" style="--x: 100%; --y: <?php echo esc_attr( round( $y, 2 ) ); ?>%" aria-hidden="true"></span>
					<?php endforeach; ?>
				</div>
				<ul class="courbes__fins" aria-hidden="true">
					<?php foreach ( $series as $cle => $s ) :
						$v = $s['valeurs'][ $dernier ];
						?>
						<li class="courbes__fin courbes__fin--<?php echo esc_attr( $cle ); ?>" style="--y: <?php echo esc_attr( $positions[ $cle ] ); ?>%">
							<?php echo ueb_graphe_cle( $cle ); // phpcs:ignore -- SVG interne ?>
							<b><?php echo esc_html( ueb_formater_montant( $v ) ); ?></b>
							<span><?php echo esc_html( ueb_graphe_accord( $v, $s['formes'] ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
				<div class="courbes__axe-x" aria-hidden="true">
					<span style="--x: 0%"><?php echo esc_html( ueb_graphe_date_courte( $jours[0] ) ); ?></span>
					<?php if ( $milieu > 0 && $milieu < $dernier ) : ?>
						<span style="--x: <?php echo esc_attr( round( 100 * $milieu / $dernier, 2 ) ); ?>%"><?php echo esc_html( ueb_graphe_date_courte( $jours[ $milieu ] ) ); ?></span>
					<?php endif; ?>
					<span style="--x: 100%"><?php echo esc_html( ueb_graphe_date_courte( $jours[ $dernier ] ) ); ?></span>
				</div>
				<p class="sr" aria-live="polite" data-courbes-annonce></p>
			</div></div>
			<?php /* Une table ignore width: 1px : c'est son enveloppe qui est masquée. */ ?>
			<div class="sr"><table>
				<caption><?php echo esc_html( $titre ); ?>, en cumul jour par jour</caption>
				<thead>
					<tr>
						<th scope="col">Date</th>
						<?php foreach ( $series as $s ) : ?>
							<th scope="col"><?php echo esc_html( $s['nom'] ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $jours as $i => $jour ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( ueb_graphe_date_longue( $jour ) ); ?></th>
							<?php foreach ( $series as $s ) : ?>
								<td><?php echo (int) $s['valeurs'][ $i ]; ?></td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table></div>
		<?php endif; ?>
	</figure>
	<?php
}

/* ---------- Recouvrement par filière ---------- */

/**
 * Recouvrement par filière : une ligne par filière (les plus gros montants
 * attendus d'abord, au plus $limite), avec le taux encaissé et la barre en
 * quatre parts du suivi des paiements. Le nom complet reste lisible au survol
 * quand il est coupé ; la barre porte déjà son équivalent texte.
 *
 * @param string $titre      Titre de la figure.
 * @param string $sous_titre Précision facultative.
 * @param array  $filieres   $suivi['filieres'] de ueb_suivi_paiements().
 * @param string $url_suivi  Suivi complet des paiements (lien du pied), facultatif.
 * @param int    $limite     Nombre de filières affichées.
 */
function ueb_graphe_filieres( $titre, $sous_titre, array $filieres, $url_suivi = '', $limite = 6 ) {
	$filieres = array_values( $filieres );
	$total    = count( $filieres );
	$visibles = array_slice( $filieres, 0, max( 1, (int) $limite ) );
	$legende  = array(
		'encaisse'     => 'Encaissé',
		'verification' => 'En vérification',
		'declare'      => 'Déclaré',
		'non_declare'  => 'Pas encore déclaré',
	);
	?>
	<figure class="graphe graphe--filieres">
		<?php ueb_graphe_entete( $titre, $sous_titre, null ); ?>
		<?php if ( ! $total ) : ?>
			<p class="graphe__vide"><?php echo ueb_icone( 'info', 18 ); ?>Aucune filière pour l’instant.</p>
		<?php else : ?>
			<ul class="filieres">
				<?php foreach ( $visibles as $i => $f ) :
					$etudiants = (int) $f['etudiants'];
					?>
					<li class="filieres__ligne" style="--i: <?php echo (int) min( $i, 6 ); ?>">
						<span class="filieres__nom" title="<?php echo esc_attr( $f['libelle'] ); ?>"><?php echo esc_html( $f['libelle'] ); ?></span>
						<b class="filieres__taux"><?php echo esc_html( ueb_pourcent( ueb_suivi_taux( $f ) ) ); ?></b>
						<span class="filieres__meta">
							<?php echo esc_html( ueb_formater_montant( $etudiants ) . ' ' . ueb_graphe_accord( $etudiants, array( 'étudiant', 'étudiants' ) ) ); ?><?php if ( ! empty( $f['pro'] ) ) : ?>, <span class="filieres__pro">formation professionnelle</span><?php endif; ?>
						</span>
						<?php ueb_suivi_barre( $f, 'suivi-barre--fine' ); ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<div class="filieres__pied">
				<ul class="filieres__legende">
					<?php foreach ( $legende as $cle => $libelle ) : ?>
						<li class="suivi-legende__item--<?php echo esc_attr( $cle ); ?>"><i aria-hidden="true"></i><?php echo esc_html( $libelle ); ?></li>
					<?php endforeach; ?>
				</ul>
				<?php if ( $total > count( $visibles ) && $url_suivi ) : ?>
					<a class="filieres__tout" href="<?php echo esc_url( $url_suivi ); ?>">Voir les <?php echo (int) $total; ?> filières<?php echo ueb_icone( 'fleche', 16 ); ?></a>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</figure>
	<?php
}
