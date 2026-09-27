<?php
/**
 * Composants de l'espace Administration (page-administration.php) : barre du
 * haut, bascule clair / sombre, héros du recouvrement avec l'anneau des
 * établissements, parcours des quitus, paiements vérifiés, registre des
 * établissements, niveaux et filières d'un établissement.
 *
 * Même principe que le reste du back-office : rendu serveur, aucune
 * bibliothèque, un seul moment animé par écran (Remotion, joué une fois) et
 * un repli statique identique à sa dernière image, sans JavaScript.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/administration-dashboard.php';
require_once __DIR__ . '/administration-paiements.php';
require_once __DIR__ . '/administration-exports.php';

/* ---------- Barre du haut ---------- */

/**
 * Fil d'Ariane, titre et phrase utile à gauche ; année, thème et actions à droite.
 *
 * @param array $a titre, sous_titre, fil (array( array( url, libellé ) ), le
 *                 dernier élément étant la page courante), actions (HTML déjà échappé),
 *                 visuel (HTML posé à gauche du titre, ex. un logo), apres (HTML
 *                 sous la phrase, ex. des repères) — tous deux déjà échappés.
 */
function ueb_adm_tete( array $a ) {
	$a     = array_merge( array( 'titre' => '', 'sous_titre' => '', 'fil' => array(), 'actions' => '', 'visuel' => '', 'apres' => '' ), $a );
	$annee = ueb_annee_academique();
	$n     = count( $a['fil'] );
	?>
	<header class="adm-tete">
		<div class="adm-tete__texte">
			<?php if ( $n > 1 ) : ?>
				<nav class="adm-fil" aria-label="Fil d’Ariane">
					<ol>
						<?php foreach ( $a['fil'] as $i => $etape ) : ?>
							<li>
								<?php if ( $i === $n - 1 ) : ?>
									<span aria-current="page"><?php echo esc_html( $etape[1] ); ?></span>
								<?php else : ?>
									<a href="<?php echo esc_url( $etape[0] ); ?>"><?php echo esc_html( $etape[1] ); ?></a><?php echo ueb_icone( 'chevron-d', 14 ); ?>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ol>
				</nav>
			<?php endif; ?>
			<?php if ( $a['visuel'] ) : ?><div class="adm-tete__identite"><?php echo $a['visuel']; // phpcs:ignore -- échappé par l'appelant ?><div><?php endif; ?>
			<h1><?php echo esc_html( $a['titre'] ); ?></h1>
			<?php if ( $a['sous_titre'] ) : ?>
				<p class="adm-tete__sous-titre"><?php echo esc_html( $a['sous_titre'] ); ?></p>
			<?php endif; ?>
			<?php echo $a['apres']; // phpcs:ignore -- échappé par l'appelant ?>
			<?php if ( $a['visuel'] ) : ?></div></div><?php endif; ?>
		</div>
		<div class="adm-tete__actions">
			<span class="adm-annee"><?php echo ueb_icone( 'calendrier', 16 ); ?>Année <?php echo esc_html( $annee['libelle'] ); ?></span>
			<?php ueb_adm_bascule_theme(); ?>
			<?php echo $a['actions']; // phpcs:ignore -- construit et échappé par l'appelant ?>
		</div>
	</header>
	<?php
}

/**
 * Bouton de bascule clair / sombre (bouton à deux états : aria-pressed).
 * Le choix est mémorisé par assets/js/administration.js et relu avant
 * l'affichage par ueb_page_debut( array( 'theme' => true ) ).
 */
function ueb_adm_bascule_theme() {
	?>
	<button type="button" class="adm-bouton adm-bouton--icone adm-theme" data-bascule-theme aria-pressed="false" aria-label="Thème sombre" title="Thème sombre">
		<?php echo ueb_icone( 'lune', 18, 'adm-theme__lune' ) . ueb_icone( 'soleil', 18, 'adm-theme__soleil' ); // phpcs:ignore -- SVG interne ?>
	</button>
	<?php
}

/** Bouton-lien de la barre du haut. */
function ueb_adm_action( $url, $libelle, $icone, $primaire = false ) {
	return sprintf(
		'<a class="adm-bouton%s" href="%s">%s%s</a>',
		$primaire ? ' adm-bouton--primaire' : '',
		esc_url( $url ),
		ueb_icone( $icone, 17 ),
		esc_html( $libelle )
	);
}

/* ---------- Anneau des établissements ----------
   Données de la composition Remotion « anneau » et image fixe de repli.
   Géométrie identique à _source/remotion/src/AnneauEtablissements.tsx
   (viewBox 0 0 480 480, image ANNEAU_DUREE - 1). */

/**
 * Un secteur par établissement, dans l'ordre de la configuration : la
 * position suit l'établissement, jamais son rang.
 */
function ueb_adm_props_anneau( array $suivi ) {
	$secteurs = array();
	foreach ( array_keys( ueb_etablissements() ) as $sigle ) {
		$a          = $suivi['etabs'][ $sigle ] ?? null;
		$secteurs[] = array(
			'sigle' => $sigle,
			'taux'  => $a ? round( ueb_suivi_taux( $a ), 1 ) : 0,
			'actif' => null !== $a,
		);
	}
	return array(
		'taux'           => round( ueb_suivi_taux( $suivi['global'] ), 1 ),
		'titre'          => 'Université',
		'libelle'        => 'recouvrés',
		'etablissements' => $secteurs,
	);
}

/** Équivalent texte de l'anneau, pour les lecteurs d'écran. */
function ueb_adm_anneau_texte( array $p ) {
	$details = array();
	foreach ( $p['etablissements'] as $s ) {
		$details[] = $s['sigle'] . ' ' . ( $s['actif'] ? ueb_pourcent( $s['taux'] ) : 'sans étudiant' );
	}
	return 'Taux de recouvrement de l’université : ' . ueb_pourcent( $p['taux'] ) . '. Par établissement : ' . implode( ', ', $details ) . '.';
}

/** Nombre à la française comme dans la composition : une décimale entre 0 et 10 exclus. */
function ueb_adm_nombre( $v ) {
	return number_format( (float) $v, $v > 0 && $v < 10 ? 1 : 0, ',', '' );
}

/**
 * SVG de repli : pistes, remplissages, sigles et taux, anneau or de
 * l'université avec son point, taux global au centre.
 *
 * @return string SVG déjà échappé.
 */
function ueb_adm_anneau_repli( array $p ) {
	$n     = max( 1, count( $p['etablissements'] ) );
	$pas   = 360 / $n;
	$ouv   = $pas - 3;
	$point = static function ( $angle, $r ) {
		$a = deg2rad( $angle );
		return array( round( 240 + $r * cos( $a ), 2 ), round( 240 + $r * sin( $a ), 2 ) );
	};
	$arc = static function ( $debut, $o, $r ) use ( $point ) {
		$o               = max( 0.01, min( 359.99, $o ) );
		list( $x0, $y0 ) = $point( $debut, $r );
		list( $x1, $y1 ) = $point( $debut + $o, $r );
		return sprintf( 'M %s %s A %s %s 0 %d 1 %s %s', $x0, $y0, $r, $r, $o > 180 ? 1 : 0, $x1, $y1 );
	};

	$secteurs = '';
	foreach ( $p['etablissements'] as $i => $s ) {
		$debut  = -90 + $i * $pas - $ouv / 2;
		$valeur = max( 0, min( 100, (float) $s['taux'] ) );
		$angle  = $valeur > 0 ? max( 1.5, $ouv * $valeur / 100 ) : 0;
		list( $ex, $ey ) = $point( -90 + $i * $pas, 207 );
		$secteurs .= '<path d="' . $arc( $debut, $ouv, 157 ) . '" fill="none" stroke="rgba(255,255,255,.12)" stroke-width="38"/>';
		if ( $angle > 0 ) {
			$secteurs .= '<path d="' . $arc( $debut, $angle, 157 ) . '" fill="none" stroke="#f4faf5" stroke-width="38"/>';
		}
		$secteurs .= $s['actif']
			? sprintf( '<text x="%s" y="%s" fill="#ffffff" font-size="17" font-weight="700">%s</text><text x="%s" y="%s" fill="rgba(255,255,255,.72)" font-size="15">%s&#8239;%%</text>', $ex, $ey - 3, esc_html( $s['sigle'] ), $ex, $ey + 16, esc_html( ueb_adm_nombre( $valeur ) ) )
			: sprintf( '<text x="%s" y="%s" fill="rgba(255,255,255,.45)" font-size="17" font-weight="700">%s</text>', $ex, $ey + 6, esc_html( $s['sigle'] ) );
	}

	$taux            = max( 0, min( 100, (float) $p['taux'] ) );
	list( $bx, $by ) = $point( -90 + 360 * $taux / 100, 121 );

	return '<svg class="anneau-repli" viewBox="0 0 480 480" width="100%" height="100%" aria-hidden="true" focusable="false" font-family="\'Source Sans 3\', \'Segoe UI\', Arial, sans-serif">'
		. '<g text-anchor="middle">' . $secteurs . '</g>'
		. '<circle cx="240" cy="240" r="121" fill="none" stroke="rgba(255,255,255,.1)" stroke-width="4"/>'
		. ( $taux > 0 ? '<path d="' . $arc( -90, 360 * $taux / 100, 121 ) . '" fill="none" stroke="#e3a822" stroke-width="4" stroke-linecap="round"/>' : '' )
		. sprintf( '<circle cx="%s" cy="%s" r="12" fill="rgba(227,168,34,.28)"/><circle cx="%s" cy="%s" r="7" fill="#e3a822"/>', $bx, $by, $bx, $by )
		. '<text x="240" y="196" text-anchor="middle" fill="rgba(255,255,255,.66)" font-size="19" font-weight="600">' . esc_html( $p['titre'] ) . '</text>'
		. '<text x="240" y="268" text-anchor="middle" fill="#ffffff" font-size="82" font-weight="700" letter-spacing="-0.02em">' . esc_html( ueb_adm_nombre( $taux ) )
		. '<tspan font-size="44" font-weight="600" fill="rgba(255,255,255,.85)">&#8239;%</tspan></text>'
		. '<text x="240" y="302" text-anchor="middle" fill="rgba(255,255,255,.72)" font-size="21">' . esc_html( $p['libelle'] ) . '</text>'
		. '</svg>';
}

/* ---------- Héros : recouvrement des droits ---------- */

/**
 * Le seul panneau fort de l'écran : visuel animé à gauche (anneau des
 * établissements pour l'université, jauge pour un établissement), montants,
 * barre en quatre parts et situation des étudiants à droite.
 *
 * @param array $suivi Résultat de ueb_suivi_paiements().
 * @param array $o     anneau (props de l'anneau, pour l'université) ou
 *                     perimetre (sigle, pour la jauge) ; url (suivi complet).
 */
function ueb_adm_recouvrement( array $suivi, array $o ) {
	$o     = array_merge( array( 'anneau' => null, 'perimetre' => '', 'url' => '' ), $o );
	$g     = $suivi['global'];
	$taux  = (float) round( ueb_suivi_taux( $g ), 1 );
	$parts = array(
		'encaisse'     => 'Encaissé',
		'verification' => 'En vérification',
		'declare'      => 'Déclaré',
		'non_declare'  => 'Pas encore déclaré',
	);
	$situations = array(
		'etudiants' => 'Étudiants concernés',
		'soldes'    => 'Soldés',
		'partiels'  => 'Paiements partiels',
		'aucun'     => 'Sans paiement vérifié',
	);
	?>
	<section class="adm-hero<?php echo $o['anneau'] ? ' adm-hero--anneau' : ''; ?>" aria-labelledby="adm-hero-titre">
		<div class="adm-hero__visuel">
			<?php
			if ( $o['anneau'] ) {
				ueb_animation( 'anneau', $o['anneau'], 'animation--anneau', ueb_adm_anneau_texte( $o['anneau'] ), ueb_adm_anneau_repli( $o['anneau'] ) );
			} else {
				ueb_animation(
					'jauge',
					array( 'taux' => $taux, 'libelle' => 'recouvrés' ),
					'animation--jauge',
					'Taux de recouvrement (' . $o['perimetre'] . ') : ' . ueb_pourcent( $taux ),
					ueb_bord_jauge_repli( $taux, 'recouvrés' )
				);
			}
			?>
		</div>

		<div class="adm-hero__corps">
			<header class="adm-hero__tete">
				<div>
					<h2 id="adm-hero-titre">Recouvrement des droits universitaires</h2>
					<p>Seuls les reçus vérifiés par la scolarité comptent comme encaissés.</p>
				</div>
				<?php if ( $o['url'] ) : ?>
					<a class="adm-hero__lien" href="<?php echo esc_url( $o['url'] ); ?>">Suivi des paiements<?php echo ueb_icone( 'fleche', 16 ); ?></a>
				<?php endif; ?>
			</header>

			<?php if ( $g['attendu'] <= 0 ) : ?>
				<p class="adm-hero__vide"><?php echo ueb_icone( 'banque', 20 ); ?>Aucun quitus de droits universitaires cette année pour l’instant. Le recouvrement apparaîtra dès les premiers dossiers.</p>
			<?php else : ?>
				<p class="adm-hero__montant">
					<b><?php echo esc_html( ueb_formater_montant( $g['encaisse'] ) ); ?><small>FCFA</small></b>
					<span>encaissés sur <?php echo esc_html( ueb_fcfa( $g['attendu'] ) ); ?> attendus. Reste à percevoir : <?php echo esc_html( ueb_fcfa( max( 0, $g['attendu'] - $g['encaisse'] ) ) ); ?>.</span>
				</p>

				<?php ueb_suivi_barre( $g, 'suivi-barre--hero adm-hero__barre' ); ?>
				<ul class="adm-hero__legende">
					<?php foreach ( $parts as $cle => $libelle ) : ?>
						<li class="suivi-legende__item--<?php echo esc_attr( $cle ); ?>">
							<i aria-hidden="true"></i>
							<span><?php echo esc_html( $libelle ); ?></span>
							<b><?php echo esc_html( ueb_fcfa( $g[ $cle ] ) ); ?></b>
							<small><?php echo esc_html( ueb_pourcent( ueb_suivi_taux( $g, $cle ) ) ); ?></small>
						</li>
					<?php endforeach; ?>
				</ul>

				<dl class="adm-hero__situations">
					<?php foreach ( $situations as $cle => $libelle ) : ?>
						<div><dt><?php echo esc_html( $libelle ); ?></dt><dd><?php echo esc_html( ueb_formater_montant( $g[ $cle ] ) ); ?></dd></div>
					<?php endforeach; ?>
				</dl>

				<?php if ( $g['trop_percu'] > 0 ) : ?>
					<p class="adm-hero__alerte"><?php echo ueb_icone( 'alerte', 16 ); ?><span><b><?php echo esc_html( ueb_fcfa( $g['trop_percu'] ) ); ?></b> vérifiés au-delà du montant attendu : à contrôler (le taux n’en tient pas compte).</span></p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	</section>
	<?php
}

/* ---------- Quitus de l'année : le parcours ---------- */

/**
 * Les trois étapes de la séquence normale (à payer, reçu envoyé, vérifié),
 * reliées par des chevrons, puis la dérivation « à corriger » à part. Une
 * barre par étape (part des quitus), jamais empilée ; chaque statut porte son
 * icône et son libellé, la couleur ne le dit jamais seule.
 */
function ueb_adm_quitus( array $c ) {
	$total  = (int) $c['quitus'];
	$part   = static fn( $n ) => $total > 0 ? 100 * $n / $total : 0;
	$etapes = array(
		'genere'      => array( 'À payer', 'horloge', 'En attente du paiement de l’étudiant', (int) $c['a_payer'] ),
		'recu_envoye' => array( 'Reçu envoyé', 'envoyer', 'À vérifier par la scolarité', (int) $c['recus_envoyes'] ),
		'verifie'     => array( 'Vérifié', 'check', 'Paiement confirmé', (int) $c['recus_verifies'] ),
	);
	$rejetes = (int) $c['recus_rejetes'];
	if ( ! $total ) {
		$phrase = 'Aucun quitus cette année pour l’instant.';
	} else {
		$phrase = sprintf(
			'%s pour %s, selon l’étape où %s se trouve%s.',
			1 === $total ? 'Le seul quitus de l’année' : $total . ' quitus',
			ueb_suivi_etudiants( $c['etudiants'] ),
			1 === $total ? 'il' : 'ils',
			1 === $total ? '' : 'nt'
		);
	}
	?>
	<section class="adm-panneau adm-quitus" aria-labelledby="adm-quitus-titre">
		<header class="adm-panneau__tete">
			<div>
				<h2 id="adm-quitus-titre">Quitus de l’année</h2>
				<p><?php echo esc_html( $phrase ); ?></p>
			</div>
		</header>
		<ol class="adm-etapes">
			<?php foreach ( $etapes as $statut => $e ) : ?>
				<li class="adm-etape adm-etape--<?php echo esc_attr( $statut ); ?>" style="--part: <?php echo esc_attr( round( $part( $e[3] ), 2 ) ); ?>%">
					<span class="adm-etape__nom"><span class="adm-etape__icone" aria-hidden="true"><?php echo ueb_icone( $e[1], 16 ); ?></span><?php echo esc_html( $e[0] ); ?></span>
					<b class="adm-etape__nombre"><?php echo (int) $e[3]; ?></b>
					<span class="adm-etape__part"><?php echo esc_html( ueb_pourcent( $part( $e[3] ) ) ); ?> des quitus</span>
					<span class="adm-etape__barre" aria-hidden="true"><i></i></span>
					<span class="adm-etape__note"><?php echo esc_html( $e[2] ); ?></span>
				</li>
			<?php endforeach; ?>
		</ol>
		<p class="adm-quitus__derive adm-etape--rejete">
			<span class="adm-etape__icone" aria-hidden="true"><?php echo ueb_icone( 'alerte', 16 ); ?></span>
			<span><b><?php echo (int) $rejetes; ?></b> à corriger : <?php echo 1 < $rejetes ? 'reçus renvoyés' : 'reçu renvoyé'; ?> à l’étudiant avec un motif (<?php echo esc_html( ueb_pourcent( $part( $rejetes ) ) ); ?> des quitus).</span>
		</p>
	</section>
	<?php
}

/* ---------- Paiements vérifiés : le parcours de paiement ---------- */

/**
 * Montant vérifié par les scolarités, puis le parcours de paiement en
 * colonnes : tous les étudiants (la référence), ceux dont la tranche 1, la
 * tranche 2 ou la totalité est vérifiée. Chaque colonne porte son nombre et
 * sa part ; la phrase d'en-tête dit combien n'ont encore rien de vérifié.
 */
function ueb_adm_verifies( array $c ) {
	$n       = (int) $c['etudiants'];
	$t1      = (int) $c['tranches']['tranche1'];
	$t2      = (int) $c['tranches']['tranche2'];
	$tout    = (int) $c['tranches']['totalite'];
	$rien    = max( 0, $n - ( $t1 + $t2 - $tout ) );
	$etapes  = array(
		array( 'Tous les étudiants', $n, 'reference', 'avec un quitus' ),
		array( 'Tranche 1 payée', $t1, 'partiel', null ),
		array( 'Tranche 2 payée', $t2, 'partiel', null ),
		array( 'Tout payé', $tout, 'plein', null ),
	);
	?>
	<section class="adm-panneau adm-verifies" aria-labelledby="adm-verifies-titre">
		<header class="adm-panneau__tete">
			<div>
				<h2 id="adm-verifies-titre">Paiements vérifiés</h2>
				<p>Quitus confirmés par les scolarités après contrôle des originaux.</p>
			</div>
		</header>
		<div class="adm-verifies__haut">
			<p class="adm-verifies__montant"><b><?php echo esc_html( ueb_formater_montant( $c['montant_verifie'] ) ); ?><small>FCFA</small></b><span>encaissés et vérifiés</span></p>
			<?php if ( $n ) : ?>
				<p class="adm-verifies__constat"><?php echo ueb_icone( 'horloge', 18 ); ?><span><b><?php echo (int) $rien; ?></b> <?php echo 1 < $rien ? 'étudiants n’ont' : 'étudiant n’a'; ?> encore aucun paiement vérifié, sur <?php echo (int) $n; ?>.</span></p>
			<?php endif; ?>
		</div>
		<?php if ( $n ) : ?>
			<ol class="adm-parcours">
				<?php foreach ( $etapes as $e ) : ?>
					<li class="adm-parcours__etape adm-parcours__etape--<?php echo esc_attr( $e[2] ); ?>">
						<span class="adm-parcours__colonne"><b class="adm-parcours__valeur"><?php echo (int) $e[1]; ?></b><i aria-hidden="true" style="--part: <?php echo esc_attr( $e[1] ? max( .015, round( $e[1] / $n, 4 ) ) : 0 ); ?>"></i></span>
						<span class="adm-parcours__nom"><?php echo esc_html( $e[0] ); ?></span>
						<small><?php echo esc_html( $e[3] ?? ueb_pourcent( 100 * $e[1] / $n ) . ' des étudiants' ); ?></small>
					</li>
				<?php endforeach; ?>
			</ol>
		<?php else : ?>
			<p class="graphe__vide"><?php echo ueb_icone( 'info', 18 ); ?>Aucun étudiant pour l’instant.</p>
		<?php endif; ?>
	</section>
	<?php
}

/* ---------- Registre des établissements ---------- */

/**
 * Une ligne par établissement qui a de l'activité, réduite à l'essentiel :
 * identité, effectif, quitus (le total et seulement ce qui attend
 * l'administration : reçus à vérifier, quitus à corriger) et recouvrement.
 * La ligne s'ouvre sur un tiroir : étudiants par niveau, quitus par statut,
 * montants de la barre de recouvrement et accès au tableau de bord de
 * l'établissement. Les établissements sans activité sont regroupés en pied.
 * Sous 980 px, chaque ligne devient une carte.
 *
 * @param array $lignes array( sigle, etab, etudiants, niveaux, statuts, quitus, suivi|null, url )
 */
function ueb_adm_etablissements( array $lignes ) {
	$statuts = array(
		'genere'      => array( 'À payer', 'horloge' ),
		'recu_envoye' => array( 'Reçu à vérifier', 'envoyer' ),
		'verifie'     => array( 'Vérifiés', 'check' ),
		'rejete'      => array( 'À corriger', 'alerte' ),
	);
	$parts  = array( 'encaisse' => 'Encaissé', 'verification' => 'En vérification', 'declare' => 'Déclaré, sans reçu', 'non_declare' => 'Pas encore déclaré' );
	$titre  = 9 === count( $lignes ) ? 'Les neuf établissements' : sprintf( 'Les %d établissements', count( $lignes ) );
	$actifs = array_filter( $lignes, static fn( $l ) => $l['etudiants'] || $l['quitus'] || $l['suivi'] );
	$calmes = array_diff_key( $lignes, $actifs );
	?>
	<section class="adm-panneau adm-registre" aria-labelledby="adm-registre-titre">
		<header class="adm-panneau__tete">
			<div>
				<h2 id="adm-registre-titre"><?php echo esc_html( $titre ); ?></h2>
				<p>Du plus grand effectif au plus petit. Ouvre une ligne pour ses niveaux, ses quitus et ses montants.</p>
			</div>
			<?php ueb_suivi_cles( $parts, 'Lecture de la barre de recouvrement' ); ?>
		</header>
		<?php if ( $actifs ) : ?>
			<table class="adm-registre__table">
				<caption class="sr">Étudiants, quitus et recouvrement des droits de chaque établissement ; chaque ligne s’ouvre sur son détail</caption>
				<thead>
					<tr>
						<th scope="col" class="adm-registre__col-nom">Établissement</th>
						<th scope="col" class="num">Étudiants</th>
						<th scope="col" class="adm-registre__col-quitus">Quitus de l’année</th>
						<th scope="col" class="adm-registre__col-taux">Recouvrement</th>
						<th scope="col" class="adm-registre__col-ouvrir"><span class="sr">Détail</span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $actifs as $l ) :
						$id      = 'adm-detail-' . sanitize_html_class( strtolower( $l['sigle'] ) );
						$a_voir  = (int) ( $l['statuts']['recu_envoye'] ?? 0 );
						$a_corr  = (int) ( $l['statuts']['rejete'] ?? 0 );
						$max     = max( 1, (int) ( $l['niveaux'] ? max( $l['niveaux'] ) : 0 ) );
						$resume  = array();
						foreach ( $l['niveaux'] as $niv => $v ) {
							$resume[] = $niv . ' : ' . $v;
						}
						?>
						<tr class="adm-registre__ligne" style="--etab: <?php echo esc_attr( $l['etab']['couleur'] ); ?>" data-detail="<?php echo esc_attr( $id ); ?>">
							<th scope="row" class="adm-registre__nom">
								<span class="adm-registre__logo"><img src="<?php echo esc_url( ueb_logo_url( $l['sigle'] ) ); ?>" alt="" width="28" height="28" loading="lazy"></span>
								<span class="adm-registre__texte">
									<a class="adm-registre__lien" href="<?php echo esc_url( $l['url'] ); ?>"><?php echo esc_html( $l['sigle'] ); ?><span class="sr"> : ouvrir son tableau de bord</span></a>
									<small title="<?php echo esc_attr( $l['etab']['fr'] ); ?>"><?php echo esc_html( $l['etab']['fr'] ); ?></small>
								</span>
							</th>
							<td class="num adm-registre__effectif" data-titre="Étudiants"><b><?php echo esc_html( ueb_formater_montant( $l['etudiants'] ) ); ?></b></td>
							<td class="adm-registre__quitus" data-titre="Quitus de l’année">
								<span class="adm-registre__total"><b><?php echo (int) $l['quitus']; ?></b> quitus</span>
								<?php if ( $a_voir ) : ?><span class="adm-a-traiter adm-a-traiter--recu"><?php echo ueb_icone( 'envoyer', 13 ); ?><?php echo esc_html( $a_voir . ' à vérifier' ); ?></span><?php endif; ?>
								<?php if ( $a_corr ) : ?><span class="adm-a-traiter adm-a-traiter--rejete"><?php echo ueb_icone( 'alerte', 13 ); ?><?php echo esc_html( $a_corr . ' à corriger' ); ?></span><?php endif; ?>
								<?php if ( ! $a_voir && ! $a_corr && $l['quitus'] ) : ?><span class="adm-registre__calme">rien à vérifier</span><?php endif; ?>
							</td>
							<td class="adm-registre__taux" data-titre="Recouvrement">
								<span class="adm-registre__mesure">
									<?php if ( $l['suivi'] ) : ?>
										<?php ueb_suivi_barre( $l['suivi'], 'suivi-barre--ligne' ); ?>
										<b><?php echo esc_html( ueb_pourcent( ueb_suivi_taux( $l['suivi'] ) ) ); ?></b>
									<?php else : ?>
										<span class="adm-registre__rien">Aucun droit déclaré</span>
									<?php endif; ?>
								</span>
							</td>
							<td class="adm-registre__ouvrir">
								<button type="button" class="adm-registre__bouton" aria-expanded="false" aria-controls="<?php echo esc_attr( $id ); ?>"><span class="sr">Détail de <?php echo esc_html( $l['sigle'] ); ?></span><?php echo ueb_icone( 'chevron', 18 ); ?></button>
							</td>
						</tr>
						<tr class="adm-registre__detail" id="<?php echo esc_attr( $id ); ?>" hidden>
							<td colspan="5">
								<div class="adm-registre__tiroir"><div class="adm-registre__tiroir-corps"><div class="adm-registre__tiroir-contenu">
									<section class="adm-detail" aria-label="<?php echo esc_attr( 'Étudiants de ' . $l['sigle'] . ' par niveau' ); ?>">
										<h3 class="adm-detail__titre">Étudiants par niveau</h3>
										<?php if ( ! array_sum( $l['niveaux'] ) ) : ?>
											<p class="adm-registre__rien">Niveau non précisé sur les quitus.</p>
										<?php else : ?>
											<span class="adm-niveaux" role="img" aria-label="<?php echo esc_attr( 'Étudiants par niveau : ' . implode( ', ', $resume ) ); ?>">
												<?php $rang = 0; foreach ( $l['niveaux'] as $niv => $v ) : ?>
													<span class="adm-niveaux__col<?php echo $v ? '' : ' est-nul'; ?>" style="--h: <?php echo esc_attr( round( 100 * $v / $max, 1 ) ); ?>%; --rang: <?php echo (int) $rang++; ?>"><b><?php echo (int) $v; ?></b><i></i><small><?php echo esc_html( $niv ); ?></small></span>
												<?php endforeach; ?>
											</span>
										<?php endif; ?>
									</section>
									<section class="adm-detail" aria-label="<?php echo esc_attr( 'Quitus de ' . $l['sigle'] ); ?>">
										<h3 class="adm-detail__titre">Quitus de l’année</h3>
										<ul class="adm-detail__liste">
											<?php foreach ( $statuts as $cle => $st ) :
												$v = (int) ( $l['statuts'][ $cle ] ?? 0 );
												?>
												<li class="adm-detail__statut adm-detail__statut--<?php echo esc_attr( $cle ); ?><?php echo $v ? '' : ' est-nul'; ?>"><?php echo ueb_icone( $st[1], 15 ); ?><span><?php echo esc_html( $st[0] ); ?></span><b><?php echo (int) $v; ?></b></li>
											<?php endforeach; ?>
										</ul>
									</section>
									<section class="adm-detail" aria-label="<?php echo esc_attr( 'Droits universitaires de ' . $l['sigle'] ); ?>">
										<h3 class="adm-detail__titre">Droits universitaires</h3>
										<?php if ( $l['suivi'] ) : ?>
											<ul class="adm-detail__liste">
												<?php foreach ( $parts as $cle => $libelle ) : ?>
													<li class="suivi-legende__item--<?php echo esc_attr( $cle ); ?>"><i aria-hidden="true"></i><span><?php echo esc_html( $libelle ); ?></span><b><?php echo esc_html( ueb_formater_montant( $l['suivi'][ $cle ] ) ); ?></b></li>
												<?php endforeach; ?>
											</ul>
											<p class="adm-detail__attendu">Sur <b><?php echo esc_html( ueb_fcfa( $l['suivi']['attendu'] ) ); ?></b> attendus</p>
										<?php else : ?>
											<p class="adm-registre__rien">Aucun droit déclaré.</p>
										<?php endif; ?>
									</section>
									<a class="adm-bouton adm-detail__aller" href="<?php echo esc_url( $l['url'] ); ?>">Tableau de bord de <?php echo esc_html( $l['sigle'] ); ?><?php echo ueb_icone( 'chevron-d', 16 ); ?></a>
								</div></div></div>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php if ( $calmes ) : ?>
			<div class="adm-registre__calmes">
				<p><b><?php echo count( $calmes ); ?></b> <?php echo 1 < count( $calmes ) ? 'établissements sans étudiant' : 'établissement sans étudiant'; ?> cette année</p>
				<ul>
					<?php foreach ( $calmes as $l ) : ?>
						<li><a href="<?php echo esc_url( $l['url'] ); ?>" title="<?php echo esc_attr( $l['etab']['fr'] ); ?>"><img src="<?php echo esc_url( ueb_logo_url( $l['sigle'] ) ); ?>" alt="" width="22" height="22" loading="lazy"><?php echo esc_html( $l['sigle'] ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>
	</section>
	<?php
}

/* ---------- Un établissement : niveaux et filières ---------- */

/**
 * Étudiants par niveau (L1 → M2) : colonnes chiffrées sur une échelle
 * commune, d'un seul vert (grandeur, pas identité).
 */
function ueb_adm_niveaux( array $niveaux, $etudiants ) {
	$max   = max( 1, (int) ( $niveaux ? max( $niveaux ) : 0 ) );
	$somme = array_sum( $niveaux );
	?>
	<section class="adm-panneau adm-par-niveau" aria-labelledby="adm-niveaux-titre">
		<header class="adm-panneau__tete">
			<div>
				<h2 id="adm-niveaux-titre">Étudiants par niveau</h2>
				<p>D’après le niveau saisi sur le quitus<?php echo $somme < $etudiants ? ' ; ' . ( $etudiants - $somme ) . ' sans niveau précisé' : ''; ?>.</p>
			</div>
		</header>
		<?php if ( ! $somme ) : ?>
			<p class="graphe__vide"><?php echo ueb_icone( 'info', 18 ); ?>Aucun niveau renseigné pour l’instant.</p>
		<?php else : ?>
			<ul class="adm-colonnes">
				<?php foreach ( $niveaux as $niv => $v ) : ?>
					<li style="--h: <?php echo esc_attr( round( 100 * $v / $max, 1 ) ); ?>%"<?php echo $v ? '' : ' class="est-nul"'; ?>>
						<b><?php echo (int) $v; ?></b>
						<span class="adm-colonnes__barre" aria-hidden="true"><i></i></span>
						<span class="adm-colonnes__nom"><?php echo esc_html( $niv ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</section>
	<?php
}

/**
 * Effectifs par filière, d'après le département saisi sur le quitus : barres
 * classées, les huit premières visibles, les suivantes dans un dépliant.
 *
 * @param array $filieres Retour de ueb_gestion_par_filiere().
 */
function ueb_adm_filieres( array $filieres ) {
	$max      = $filieres ? max( 1, (int) $filieres[0]->n ) : 1;
	$visibles = array_slice( $filieres, 0, 8 );
	$autres   = array_slice( $filieres, 8 );
	$ligne    = static function ( $f ) use ( $max ) {
		printf(
			'<li style="--part: %s%%"><span class="adm-filieres__nom" title="%s">%s</span><span class="adm-filieres__barre" aria-hidden="true"><i></i></span><b>%d</b></li>',
			esc_attr( round( 100 * $f->n / $max, 2 ) ),
			esc_attr( $f->filiere ),
			esc_html( $f->filiere ),
			(int) $f->n
		);
	};
	?>
	<section class="adm-panneau adm-filieres" aria-labelledby="adm-filieres-titre">
		<header class="adm-panneau__tete">
			<div>
				<h2 id="adm-filieres-titre">Effectifs par filière</h2>
				<p>D’après le département saisi par l’étudiant sur son quitus.</p>
			</div>
		</header>
		<?php if ( ! $filieres ) : ?>
			<p class="graphe__vide"><?php echo ueb_icone( 'info', 18 ); ?>Aucun quitus dans cet établissement pour l’instant.</p>
		<?php else : ?>
			<ul class="adm-filieres__liste"><?php array_map( $ligne, $visibles ); ?></ul>
			<?php if ( $autres ) : ?>
				<details class="adm-filieres__suite">
					<summary><?php echo ueb_icone( 'chevron', 16 ); ?><?php echo esc_html( sprintf( 'Voir les %d autres filières', count( $autres ) ) ); ?></summary>
					<ul class="adm-filieres__liste"><?php array_map( $ligne, $autres ); ?></ul>
				</details>
			<?php endif; ?>
		<?php endif; ?>
	</section>
	<?php
}
