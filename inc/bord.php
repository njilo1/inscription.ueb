<?php
/**
 * Tableau de bord de l'espace scolarité : recouvrement (jauge animée),
 * parcours des quitus, progression de l'année, graphiques de répartition.
 *
 * Le héros du recouvrement sert aussi en tête de la vue Paiements
 * (scolarité et administration) : ses styles ne dépendent pas de .bord.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

/* ---------- Jauge : image fixe de repli ----------
   État final de la composition Remotion « jauge » (image JAUGE_DUREE - 1),
   affiché sans JavaScript et tant que le lecteur n'est pas monté. Géométrie
   identique à _source/remotion/src/JaugeRecouvrement.tsx (viewBox 0 0 400 400). */

/**
 * Point à la fraction $t (0–1) de l'arc, au rayon $r : arc de 270° ouvert en
 * bas, de 135° à 405° (0° = 3 h, sens horaire), centre (200, 200).
 */
function ueb_bord_jauge_point( $t, $r = 150 ) {
	$a = deg2rad( 135 + 270 * $t );
	return array( round( 200 + $r * cos( $a ), 2 ), round( 200 + $r * sin( $a ), 2 ) );
}

/** Chemin SVG de l'arc, du départ jusqu'à la fraction $t. */
function ueb_bord_jauge_arc( $t ) {
	list( $x0, $y0 ) = ueb_bord_jauge_point( 0 );
	list( $x1, $y1 ) = ueb_bord_jauge_point( $t );
	return sprintf( 'M %s %s A 150 150 0 %d 1 %s %s', $x0, $y0, 270 * $t > 180 ? 1 : 0, $x1, $y1 );
}

/**
 * SVG de repli : piste, arc blanc jusqu'au taux, point or et son halo,
 * graduations tous les 10 % (0, 50 et 100 % plus longues), pourcentage au
 * centre (signe % en tspan plus petit), libellé, bornes « 0 » et « 100 % ».
 *
 * @return string SVG déjà échappé.
 */
function ueb_bord_jauge_repli( $taux, $libelle ) {
	$taux = max( 0, min( 100, (float) $taux ) );
	$t    = $taux / 100;
	list( $bx, $by ) = ueb_bord_jauge_point( $t );

	$graduations = '';
	for ( $k = 0; $k <= 10; $k++ ) {
		$majeure = 0 === $k % 5;
		list( $x1, $y1 ) = ueb_bord_jauge_point( $k / 10, 170 );
		list( $x2, $y2 ) = ueb_bord_jauge_point( $k / 10, $majeure ? 184 : 178 );
		$graduations    .= sprintf(
			'<line x1="%s" y1="%s" x2="%s" y2="%s" stroke="%s" stroke-width="2"/>',
			$x1, $y1, $x2, $y2, $majeure ? 'rgba(255,255,255,.6)' : 'rgba(255,255,255,.3)'
		);
	}

	/* Comme ueb_pourcent() : une décimale à virgule entre 0 et 10 exclus. */
	$nombre = number_format( $taux, $taux > 0 && $taux < 10 ? 1 : 0, ',', '' );
	$trait  = 'fill="none" stroke-width="22" stroke-linecap="round"';

	return '<svg class="jauge-repli" viewBox="0 0 400 400" width="100%" height="100%" aria-hidden="true" focusable="false" font-family="\'Source Sans 3\', \'Segoe UI\', Arial, sans-serif">'
		. '<g>' . $graduations . '</g>'
		. '<path d="' . ueb_bord_jauge_arc( 1 ) . '" ' . $trait . ' stroke="rgba(255,255,255,.14)"/>'
		. ( $t > 0 ? '<path d="' . ueb_bord_jauge_arc( $t ) . '" ' . $trait . ' stroke="#ffffff"/>' : '' )
		. sprintf( '<circle cx="%s" cy="%s" r="24" fill="rgba(227,168,34,.28)"/>', $bx, $by )
		. sprintf( '<circle cx="%s" cy="%s" r="13" fill="#e3a822"/>', $bx, $by )
		. '<text x="200" y="222" text-anchor="middle" fill="#ffffff" font-size="104" font-weight="700">' . esc_html( $nombre )
		. '<tspan font-size="58" font-weight="600" fill="rgba(255,255,255,.85)">&#8239;%</tspan></text>'
		. '<text x="200" y="266" text-anchor="middle" fill="rgba(255,255,255,.72)" font-size="28" font-weight="400">' . esc_html( $libelle ) . '</text>'
		. '<g fill="rgba(255,255,255,.55)" text-anchor="middle" font-size="22">'
		. '<text x="93.93" y="352">0</text><text x="306.07" y="352">100&#8239;%</text></g>'
		. '</svg>';
}

/**
 * Héros « Recouvrement des droits » : volet vert portant la jauge animée,
 * volet blanc avec les montants, la barre en quatre parts, sa légende
 * chiffrée et les étudiants soldés, partiels ou sans paiement vérifié.
 *
 * @param array  $suivi         Résultat de ueb_suivi_paiements().
 * @param string $url_paiements Lien vers le suivi complet ; vide = pas de lien.
 * @param string $perimetre     Sigle de l'établissement ou « Université ».
 * @param array  $options       titre (string), reste (bool : « Reste à percevoir »).
 */
function ueb_bord_recouvrement( array $suivi, $url_paiements, $perimetre, array $options = array() ) {
	$options = array_merge( array( 'titre' => 'Recouvrement des droits universitaires', 'reste' => false ), $options );
	$g       = $suivi['global'];
	$taux    = (float) round( ueb_suivi_taux( $g ), 1 );
	$parts   = array(
		'encaisse'     => 'Encaissé',
		'verification' => 'En vérification',
		'declare'      => 'Déclaré',
		'non_declare'  => 'Pas encore déclaré',
	);
	$accord  = static fn( $nombre, $un, $plusieurs ) => (int) $nombre > 1 ? $plusieurs : $un;
	$eleves  = array(
		'etudiants' => $accord( $g['etudiants'], 'étudiant', 'étudiants' ),
		'soldes'    => $accord( $g['soldes'], 'soldé', 'soldés' ),
		'partiels'  => $accord( $g['partiels'], 'partiel', 'partiels' ),
		'aucun'     => 'sans paiement vérifié',
	);
	?>
	<section class="carte bord-recouvrement" aria-labelledby="titre-recouvrement">
		<div class="bord-recouvrement__volet">
			<div class="bord-recouvrement__cadran">
				<?php
				ueb_animation(
					'jauge',
					array( 'taux' => $taux, 'libelle' => 'recouvrés' ),
					'animation--jauge',
					'Taux de recouvrement ' . ( $perimetre ? '(' . $perimetre . ') ' : '' ) . ': ' . ueb_pourcent( $taux ),
					ueb_bord_jauge_repli( $taux, 'recouvrés' )
				);
				?>
			</div>
			<p class="bord-recouvrement__legende-jauge">Part des droits attendus déjà encaissée</p>
		</div>

		<div class="bord-recouvrement__corps">
			<header class="bord-recouvrement__entete">
				<div>
					<h2 id="titre-recouvrement"><?php echo esc_html( $options['titre'] ); ?></h2>
					<p>Seuls les reçus vérifiés par la scolarité comptent comme encaissés.</p>
				</div>
				<?php if ( $url_paiements ) : ?>
					<a class="bo-lien bord-recouvrement__lien" href="<?php echo esc_url( $url_paiements ); ?>">Suivi des paiements<?php echo ueb_icone( 'fleche', 16 ); ?></a>
				<?php endif; ?>
			</header>

			<?php if ( $g['attendu'] <= 0 ) : ?>
				<div class="bord-recouvrement__vide">
					<span aria-hidden="true"><?php echo ueb_icone( 'banque', 22 ); ?></span>
					<p>Aucun quitus de droits cette année pour l’instant.</p>
				</div>
			<?php else : ?>
				<p class="bord-recouvrement__montant">
					<b><?php echo esc_html( ueb_formater_montant( $g['encaisse'] ) ); ?> <small>FCFA</small></b>
					<span>encaissés sur <?php echo esc_html( ueb_fcfa( $g['attendu'] ) ); ?> attendus<?php if ( $options['reste'] ) : ?>. Reste à percevoir : <b><?php echo esc_html( ueb_fcfa( $g['attendu'] - $g['encaisse'] ) ); ?></b><?php endif; ?></span>
				</p>

				<?php ueb_suivi_barre( $g, 'suivi-barre--hero' ); ?>
				<ul class="suivi-legende bord-recouvrement__legende">
					<?php foreach ( $parts as $cle => $libelle ) : ?>
						<li class="suivi-legende__item suivi-legende__item--<?php echo esc_attr( $cle ); ?>">
							<i aria-hidden="true"></i>
							<span class="suivi-legende__nom"><?php echo esc_html( $libelle ); ?></span>
							<b><?php echo esc_html( ueb_fcfa( $g[ $cle ] ) ); ?></b>
							<small><?php echo esc_html( ueb_pourcent( ueb_suivi_taux( $g, $cle ) ) ); ?></small>
						</li>
					<?php endforeach; ?>
				</ul>

				<dl class="bord-recouvrement__eleves">
					<?php foreach ( $eleves as $cle => $libelle ) : ?>
						<div class="bord-recouvrement__eleve bord-recouvrement__eleve--<?php echo esc_attr( $cle ); ?>">
							<dt><?php echo esc_html( $libelle ); ?></dt>
							<dd><?php echo (int) $g[ $cle ]; ?></dd>
						</div>
					<?php endforeach; ?>
				</dl>

				<?php if ( $g['trop_percu'] > 0 ) : ?>
					<p class="bord-recouvrement__alerte"><?php echo ueb_icone( 'alerte', 16 ); ?><span><b><?php echo esc_html( ueb_fcfa( $g['trop_percu'] ) ); ?></b> vérifiés au-delà du montant attendu : à contrôler (le taux n’en tient pas compte).</span></p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	</section>
	<?php
}

/**
 * Une étape du parcours : lien vers le registre filtré sur ce statut, avec
 * icône, libellé, nombre, part des quitus et barre de sa couleur.
 *
 * @param array $e statut, libelle, icone, note, nombre, numero (0 = hors séquence), i (rang d'entrée).
 */
function ueb_bord_etape( array $e, $total, callable $ici ) {
	$part    = $total > 0 ? 100 * $e['nombre'] / $total : 0;
	$classes = 'bord-etape bord-etape--' . $e['statut'] . ( 'recu_envoye' === $e['statut'] && $e['nombre'] > 0 ? ' est-a-traiter' : '' );
	?>
	<a class="<?php echo esc_attr( $classes ); ?>" href="<?php echo esc_url( $ici( array( 'vue' => 'quitus', 'statut' => $e['statut'] ) ) ); ?>" style="--part: <?php echo esc_attr( round( $part, 2 ) ); ?>%; --i: <?php echo (int) $e['i']; ?>">
		<span class="bord-etape__tete">
			<?php if ( $e['numero'] ) : ?><span class="bord-etape__numero" aria-hidden="true"><?php echo (int) $e['numero']; ?></span><?php endif; ?>
			<span class="bord-etape__nom"><?php echo esc_html( $e['libelle'] ); ?></span>
			<span class="bord-etape__icone" aria-hidden="true"><?php echo ueb_icone( $e['icone'], 17 ); ?></span>
		</span>
		<b class="bord-etape__nombre"><?php echo (int) $e['nombre']; ?></b>
		<span class="bord-etape__part"><?php echo esc_html( ueb_pourcent( $part ) ); ?> des quitus</span>
		<span class="bord-etape__barre" aria-hidden="true"><span></span></span>
		<span class="bord-etape__note"><?php echo esc_html( $e['note'] ); ?></span>
	</a>
	<?php
}

/**
 * Parcours des quitus : les trois étapes de la séquence normale (à payer,
 * reçu envoyé, vérifié) reliées par des chevrons, et la dérivation « à
 * corriger » présentée à part. Une barre par étape, jamais empilée.
 *
 * @param array    $c   Résultat de ueb_gestion_chiffres().
 * @param callable $ici array $args => URL (déjà échappée) de l'espace scolarité.
 */
function ueb_bord_parcours( array $c, callable $ici ) {
	$total = (int) $c['quitus'];
	if ( ! $total ) {
		$phrase = 'Aucun quitus cette année pour l’instant.';
	} elseif ( 1 === $total ) {
		$phrase = 'Le seul quitus de l’année, selon l’étape où il se trouve.';
	} else {
		$phrase = sprintf( 'Les %d quitus de l’année, selon l’étape où ils se trouvent.', $total );
	}
	$etapes = array(
		array( 'statut' => 'genere', 'libelle' => 'À payer', 'icone' => 'horloge', 'note' => 'En attente du paiement de l’étudiant', 'nombre' => (int) $c['a_payer'], 'numero' => 1, 'i' => 2 ),
		array( 'statut' => 'recu_envoye', 'libelle' => 'Reçu envoyé', 'icone' => 'envoyer', 'note' => 'À vérifier par la scolarité', 'nombre' => (int) $c['recus_envoyes'], 'numero' => 2, 'i' => 3 ),
		array( 'statut' => 'verifie', 'libelle' => 'Vérifié', 'icone' => 'check', 'note' => 'Paiement confirmé', 'nombre' => (int) $c['recus_verifies'], 'numero' => 3, 'i' => 4 ),
	);
	$derive = array( 'statut' => 'rejete', 'libelle' => 'À corriger', 'icone' => 'alerte', 'note' => 'Renvoyé à l’étudiant avec un motif', 'nombre' => (int) $c['recus_rejetes'], 'numero' => 0, 'i' => 5 );
	?>
	<section class="carte bord-parcours" aria-labelledby="titre-parcours">
		<header class="bord-parcours__entete">
			<h2 id="titre-parcours">Parcours des quitus</h2>
			<p><?php echo esc_html( $phrase ); ?></p>
		</header>
		<div class="bord-parcours__corps">
			<ol class="bord-parcours__etapes">
				<?php foreach ( $etapes as $rang => $e ) : ?>
					<li>
						<?php ueb_bord_etape( $e, $total, $ici ); ?>
						<?php if ( $rang < count( $etapes ) - 1 ) : ?>
							<span class="bord-parcours__suite" aria-hidden="true"><?php echo ueb_icone( 'chevron', 18 ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ol>
			<span class="bord-parcours__filet" aria-hidden="true"></span>
			<div class="bord-parcours__derive">
				<?php ueb_bord_etape( $derive, $total, $ici ); ?>
			</div>
		</div>
	</section>
	<?php
}
