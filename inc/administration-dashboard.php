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
 * Évolution des quitus d'un type, en anneau double. L'anneau intérieur répartit
 * les quitus de l'année par statut ; l'anneau extérieur, plus fin, colore dans
 * chaque statut la part générée ces 7 derniers jours, le reste en piste.
 * Autour de l'anneau : vérifiés, reçu à vérifier, à corriger, à payer, pour que
 * le rouge ne touche jamais le vert (palette validée, libellés toujours écrits).
 * Le SVG est l'image finale ; administration-mouvement.js y pose deux balayages
 * Remotion (composition « donut ») quand $entree est vrai.
 *
 * @param array  $t       $c['types'][ type ] de ueb_gestion_chiffres().
 * @param string $libelle Libellé du type (Droits universitaires…).
 * @param bool   $entree  Anneau animé à l'arrivée (l'onglet affiché d'abord).
 */
function ueb_adm_anneau_quitus( array $t, $libelle, $entree = false ) {
	$statuts = array(
		'verifie'     => array( 'Vérifiés', 'var(--st-verifie)' ),
		'recu_envoye' => array( 'Reçu à vérifier', 'var(--st-verifier)' ),
		'rejete'      => array( 'À corriger', 'var(--st-corriger)' ),
		'genere'      => array( 'À payer', 'var(--st-payer)' ),
	);
	$n       = (int) $t['quitus'];
	$recents = (int) array_sum( $t['recents'] ?? array() );
	$ecart   = 0.5; // espace de surface entre deux parts, en centièmes du tour (environ 2 px)
	/* Parts des deux anneaux (centièmes du tour) : celles du SVG et des balayages Remotion. */
	$interieur = $exterieur = array();
	foreach ( $statuts as $cle => list( $nom, $couleur ) ) {
		$nb = (int) ( $t[ $cle ] ?? 0 );
		if ( ! $n || ! $nb ) {
			continue;
		}
		$part   = 100 * $nb / $n;
		$recent = 100 * min( $nb, (int) ( $t['recents'][ $cle ] ?? 0 ) ) / $n;
		$interieur[] = array( 'cle' => $cle, 'valeur' => round( $part - $ecart, 3 ), 'couleur' => $couleur );
		$interieur[] = array( 'cle' => 'ecart', 'valeur' => $ecart, 'couleur' => 'transparent' );
		if ( $recent > 0 ) {
			$exterieur[] = array( 'cle' => $cle . '-recent', 'valeur' => round( $recent - ( $recent >= $part ? $ecart : 0 ), 3 ), 'couleur' => $couleur );
		}
		if ( $part - $recent > $ecart ) {
			$exterieur[] = array( 'cle' => $cle . '-reste', 'valeur' => round( $part - $recent - $ecart, 3 ), 'couleur' => 'var(--dash-neutre)' );
		}
		$exterieur[] = array( 'cle' => 'ecart', 'valeur' => $ecart, 'couleur' => 'transparent' );
	}
	$dit = sprintf( '%s : %s', $libelle, implode( ', ', array_map( static fn( $cle ) => (int) ( $t[ $cle ] ?? 0 ) . ' ' . mb_strtolower( $statuts[ $cle ][0] ), array_keys( $statuts ) ) ) ) . sprintf( ' ; %d générés ces 7 derniers jours.', $recents );
	$cercles = static function ( array $parts, $rayon, $epaisseur ) {
		$debut = 0;
		foreach ( $parts as $p ) {
			if ( 'ecart' !== $p['cle'] && $p['valeur'] > 0 ) {
				printf(
					'<circle class="donut-part donut-part--%1$s" cx="120" cy="120" r="%2$s" pathLength="100" stroke-width="%3$s" style="stroke:%4$s" stroke-dasharray="%5$s %6$s" stroke-dashoffset="%7$s"/>',
					esc_attr( $p['cle'] ), (int) $rayon, (int) $epaisseur, esc_attr( $p['couleur'] ),
					esc_attr( $p['valeur'] ), esc_attr( 100 - $p['valeur'] ), esc_attr( -$debut )
				);
			}
			$debut += $p['valeur'];
		}
	};
	?>
	<header class="adm-panneau__tete adm-evolution__tete">
		<div>
			<h2>Évolution des quitus</h2>
			<p><?php echo esc_html( $libelle . ( $n ? ', où en sont les ' . ueb_formater_montant( $n ) . ' quitus de l’année' : '' ) ); ?></p>
		</div>
	</header>
	<?php if ( ! $n ) : ?>
		<p class="graphe__vide"><?php echo ueb_icone( 'info', 18 ); ?>Aucun quitus pour l’instant.</p>
	<?php else : ?>
		<div class="adm-evolution__corps">
			<div class="adm-anneau-double<?php echo $entree ? ' adm-anneau-double--entree' : ''; ?>" role="img" aria-label="<?php echo esc_attr( $dit ); ?>"
				data-interieur="<?php echo esc_attr( wp_json_encode( array( 'parts' => $interieur, 'piste' => 'transparent', 'taille' => 240, 'rayon' => 78, 'epaisseur' => 29 ) ) ); ?>"
				data-exterieur="<?php echo esc_attr( wp_json_encode( array( 'parts' => $exterieur, 'piste' => 'transparent', 'taille' => 240, 'rayon' => 106, 'epaisseur' => 10 ) ) ); ?>">
				<svg viewBox="0 0 240 240" aria-hidden="true"><g transform="rotate(-90 120 120)" fill="none"><?php $cercles( $interieur, 78, 29 ); $cercles( $exterieur, 106, 10 ); ?></g></svg>
				<p class="adm-anneau-double__centre"><b><?php echo esc_html( ueb_formater_montant( $n ) ); ?></b><span>quitus</span><?php if ( $recents ) : ?><em>+<?php echo (int) $recents; ?> en 7 jours</em><?php endif; ?></p>
			</div>
			<ul class="adm-anneau-double__legende">
				<?php foreach ( $statuts as $cle => list( $nom, $couleur ) ) :
					$nb = (int) ( $t[ $cle ] ?? 0 );
					$r  = (int) ( $t['recents'][ $cle ] ?? 0 );
					?>
					<li data-cle="<?php echo esc_attr( $cle ); ?>" tabindex="0"><i style="background:<?php echo esc_attr( $couleur ); ?>" aria-hidden="true"></i><span><?php echo esc_html( $nom ); ?><?php if ( $r ) : ?> <small>dont <?php echo (int) $r; ?> cette semaine</small><?php endif; ?></span><b><?php echo esc_html( ueb_formater_montant( $nb ) ); ?></b></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>
	<?php
}

/**
 * Cartes, anneau financier, anneau des quitus et comparaison du recouvrement.
 *
 * Repris tel quel par le tableau de bord de la scolarité, limité à son
 * établissement : $options y change les liens et retire ce qui n'appartient
 * qu'à l'administration.
 *
 * $activite n'est plus lu : la carte « Évolution des quitus » est un anneau
 * double tiré de $c (ueb_adm_anneau_quitus).
 *
 * @param array $options url (callable array $args => URL de l'espace, non
 *                       échappée ; défaut : l'administration filtrée sur $focus),
 *                       perimetre (bool : choix de l'établissement),
 *                       paiements (URL du suivi des paiements ; '' = sans lien),
 *                       ipes (bool : panneau des IPES sous tutelle),
 *                       attente (type de reçus, « droits » : la carte rouge des
 *                       reçus en attente de validation passe en tête, à la place
 *                       de « Reçus à vérifier » ; inc/attente-recus.php).
 */
function ueb_adm_dashboard( array $c, array $suivi, array $activite, $focus, $periode, array $options = array() ) {
	$g       = $suivi['global'];
	$taux    = ueb_suivi_taux( $g );
	$reste   = max( 0, $g['attendu'] - $g['encaisse'] );
	$propre  = isset( $options['url'] );
	$url     = $propre ? $options['url'] : static fn( array $args = array() ) => add_query_arg( $args, ueb_url_administration() );
	$args    = $focus && ! $propre ? array( 'etab' => $focus ) : array();
	$options = array_merge( array( 'perimetre' => true, 'paiements' => $url( $args + array( 'vue' => 'paiements' ) ), 'ipes' => true, 'attente' => '' ), $options );
	$parts   = array(
		'encaisse'     => array( 'Encaissé', 'var(--dash-foret)' ),
		'verification' => array( 'En vérification', 'var(--dash-bleu)' ),
		'declare'      => array( 'Déclaré, sans reçu', 'var(--dash-or)' ),
		'non_declare'  => array( 'Non déclaré', 'var(--dash-neutre)' ),
	);
	/* Quitus et reçus se comptent par type, jamais additionnés : les droits
	   universitaires d'un côté, les frais médicaux de l'autre (ceux que le compte voit). */
	$types     = ueb_types_stats_visibles() ?: array( 'droits' );
	$par_type  = static fn( $cle ) => array_combine( $types, array_map( static fn( $t ) => (int) ( $c['types'][ $t ][ $cle ] ?? 0 ), $types ) );
	$valideurs = array( 'droits' => 'de la scolarité', 'medicaux' => 'du CMS' );
	$cartes    = array(
		array( 'titre' => 'Étudiants', 'valeur' => ueb_formater_montant( $c['etudiants'] ), 'note' => 'Avec au moins un quitus', 'icone' => 'groupe', 'ton' => 'foret', 'courbe' => 'etudiants' ),
		array( 'titre' => 'Droits encaissés', 'valeur' => ueb_adm_montant_court( $g['encaisse'] ), 'note' => 'FCFA · paiements vérifiés', 'icone' => 'banque', 'ton' => 'foret', 'courbe' => 'encaisse' ),
		array( 'titre' => 'Quitus générés', 'types' => $par_type( 'quitus' ), 'note' => '', 'icone' => 'fichier', 'ton' => 'foret', 'courbe' => 'quitus' ),
		array( 'titre' => 'Recouvrement', 'valeur' => $g['attendu'] ? ueb_pourcent( $taux ) : '—', 'note' => 'Des droits universitaires attendus', 'icone' => 'check', 'ton' => 'vert', 'courbe' => 'taux' ),
		array( 'titre' => 'Reçus à vérifier', 'types' => $par_type( 'recu_envoye' ), 'note' => 'En attente ' . implode( ' et ', array_intersect_key( $valideurs, array_flip( $types ) ) ), 'icone' => 'horloge', 'ton' => 'foret', 'courbe' => 'depots' ),
	);
	$hist = $suivi['historique'] ?? array( 'jours' => array() );
	if ( $options['attente'] ) {
		array_pop( $cartes );
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
		<?php if ( $options['attente'] ) { ueb_carte_attente( $options['attente'], null, $periode ); } ?>
		<?php foreach ( $cartes as $carte ) : ?>
			<?php if ( isset( $carte['types'] ) ) : ?>
				<?php ueb_adm_carte_types( $carte, $hist ); ?>
			<?php else : ?>
				<article class="adm-kpi adm-kpi--<?php echo esc_attr( $carte['ton'] ); ?>">
					<div class="adm-kpi__entete"><h2><?php echo esc_html( $carte['titre'] ); ?></h2><?php echo ueb_icone( $carte['icone'], 18 ); ?></div>
					<p class="adm-kpi__valeur"><?php echo esc_html( $carte['valeur'] ); ?></p>
					<p class="adm-kpi__note"><?php echo esc_html( $carte['note'] ); ?></p>
					<?php ueb_adm_mini_courbe( $carte['courbe'], $hist['jours'], $hist[ $carte['courbe'] ] ?? array() ); ?>
				</article>
			<?php endif; ?>
		<?php endforeach; ?>
	</div>
	<?php ueb_adm_donnees_courbes( $hist, $types ); ?>
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
			<?php /* Un anneau double par type de quitus, au choix par onglets. */ ?>
			<?php if ( count( $types ) > 1 ) : ?>
				<div class="adm-onglets" role="tablist" aria-label="Type de quitus" data-onglets>
					<?php $premier = true; foreach ( $types as $t ) : ?>
						<button type="button" role="tab" id="<?php echo esc_attr( 'adm-evolution-onglet-' . $t ); ?>" aria-controls="<?php echo esc_attr( 'adm-evolution-' . $t ); ?>" aria-selected="<?php echo $premier ? 'true' : 'false'; ?>" tabindex="<?php echo $premier ? '0' : '-1'; ?>"><i class="adm-type-cle adm-type-cle--<?php echo esc_attr( $t ); ?>" aria-hidden="true"></i><?php echo esc_html( UEB_TYPES_QUITUS[ $t ]['libelle'] ); ?></button>
						<?php $premier = false; ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<?php $premier = true; foreach ( $types as $t ) : ?>
				<div class="adm-evolution__type" id="<?php echo esc_attr( 'adm-evolution-' . $t ); ?>"<?php echo count( $types ) > 1 ? ' role="tabpanel" aria-labelledby="' . esc_attr( 'adm-evolution-onglet-' . $t ) . '"' : ''; ?><?php echo $premier ? '' : ' hidden'; ?>>
					<?php ueb_adm_anneau_quitus( $c['types'][ $t ], UEB_TYPES_QUITUS[ $t ]['libelle'], $premier ); ?>
				</div>
				<?php $premier = false; ?>
			<?php endforeach; ?>
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

/**
 * Carte d'un compte par type de quitus (quitus générés, reçus à vérifier) :
 * droits universitaires et frais médicaux côte à côte, séparés par un filet,
 * jamais additionnés ; la mini-courbe trace un type par ligne (pleine pour les
 * droits, en tirets pour les frais médicaux). Un seul type visible : une
 * carte ordinaire, à son nom.
 *
 * @param array $carte titre, types (type => nombre), note, icone, ton, courbe
 *                     (clé des séries <courbe>_droits et <courbe>_medicaux).
 */
function ueb_adm_carte_types( array $carte, array $hist ) {
	$types = array_keys( $carte['types'] );
	$cle   = $carte['courbe'];
	$flux  = 'depots' === $cle; // dépôts du jour, pas un cumul
	?>
	<article class="adm-kpi adm-kpi--<?php echo esc_attr( $carte['ton'] ); ?><?php echo 1 < count( $types ) ? ' adm-kpi--types' : ''; ?>">
		<div class="adm-kpi__entete"><h2><?php echo esc_html( $carte['titre'] ); ?></h2><?php echo ueb_icone( $carte['icone'], 18 ); ?></div>
		<?php if ( 1 === count( $types ) ) : $t = $types[0]; ?>
			<p class="adm-kpi__valeur"><?php echo esc_html( ueb_formater_montant( $carte['types'][ $t ] ) ); ?></p>
			<p class="adm-kpi__note"><?php echo esc_html( UEB_TYPES_QUITUS[ $t ]['libelle'] . ( $carte['note'] ? ', ' . lcfirst( $carte['note'] ) : '' ) ); ?></p>
			<?php ueb_adm_mini_courbe( $cle . '_' . $t, $hist['jours'], $hist[ $cle . '_' . $t ] ?? array(), $flux ? 'Reçus déposés par jour' : 'Quitus, cumul' ); ?>
		<?php else : ?>
			<dl class="adm-kpi__types">
				<?php foreach ( $carte['types'] as $t => $n ) : ?>
					<div class="adm-kpi__type"><dt><i class="adm-type-cle adm-type-cle--<?php echo esc_attr( $t ); ?>" aria-hidden="true"></i><span aria-hidden="true"><?php echo esc_html( 'medicaux' === $t ? 'Médicaux' : 'Droits' ); ?></span><span class="sr"><?php echo esc_html( UEB_TYPES_QUITUS[ $t ]['libelle'] ); ?></span></dt><dd><?php echo esc_html( ueb_formater_montant( $n ) ); ?></dd></div>
				<?php endforeach; ?>
			</dl>
			<?php if ( $carte['note'] ) : ?><p class="adm-kpi__note"><?php echo esc_html( $carte['note'] ); ?></p><?php endif; ?>
			<?php
			ueb_adm_mini_courbe( $cle . '_droits', $hist['jours'], $hist[ $cle . '_droits' ] ?? array(), $flux ? 'Dépôts par jour' : 'Cumul par type', array(
				'valeurs' => $hist[ $cle . '_medicaux' ] ?? array(),
				'noms'    => array( 'droits', 'frais médicaux' ),
			) );
			?>
		<?php endif; ?>
	</article>
	<?php
}

/** Valeur exacte des courbes ; le taux reste indéfini en l'absence de droits. */
function ueb_adm_valeur_historique( $cle, $valeur ) {
	if ( null === $valeur ) {
		return 'Aucun droit attendu';
	}
	return 'taux' === $cle ? number_format( $valeur, 1, ',', ' ' ) . ' %' : ueb_formater_montant( $valeur ) . ( 'encaisse' === $cle ? ' FCFA' : '' );
}

/**
 * Mini-courbe à base zéro, lisible sans JS, avec repère au survol et au clavier.
 *
 * @param string $libelle Légende propre (tableau du CMS, cartes par type) ; par défaut celle de la clé.
 * @param array  $seconde Deuxième série sur la même échelle (cartes par type) :
 *                        valeurs, noms (array( nom de la première, nom de la seconde )).
 */
function ueb_adm_mini_courbe( $cle, array $jours, array $valeurs, $libelle = '', array $seconde = array() ) {
	$libelles = array( 'etudiants' => 'Étudiants · cumul', 'encaisse' => 'Encaissé · cumul', 'quitus' => 'Quitus · cumul', 'taux' => 'Taux de recouvrement', 'depots' => 'Quitus avec reçu déposé / jour' );
	$base     = preg_replace( '/_(droits|medicaux)$/', '', $cle ); // nature de la série, quel que soit son type
	$double   = isset( $seconde['valeurs'] );
	$titre    = $libelle ?: $libelles[ $base ];
	$n        = min( count( $jours ), count( $valeurs ), $double ? count( $seconde['valeurs'] ) : PHP_INT_MAX );
	if ( $n < 2 ) {
		echo '<p class="adm-spark-vide">Historique disponible dès deux jours.</p>';
		return;
	}
	$valeurs  = array_slice( $valeurs, 0, $n );
	$valeurs2 = $double ? array_slice( $seconde['valeurs'], 0, $n ) : array();
	$haut     = 'taux' === $base ? 100 : max( 1, ...array_map( static fn( $v ) => $v ?? 0, array_merge( $valeurs, $valeurs2 ) ) );
	/* Points, ligne et aire d'une série, sur l'échelle commune. */
	$tracer = static function ( array $serie ) use ( $n, $haut ) {
		$points = $troncons = $segment = array();
		foreach ( $serie as $i => $v ) {
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
		return array( $points, $ligne, $aire );
	};
	list( $points, $ligne, $aire ) = $tracer( $valeurs );
	list( $points2, $ligne2 )      = $double ? $tracer( $valeurs2 ) : array( array(), '' );
	$ecart = static function ( array $serie ) use ( $n, $base ) {
		$delta = null !== $serie[0] && null !== $serie[ $n - 1 ] ? $serie[ $n - 1 ] - $serie[0] : null;
		return null === $delta ? 'Début du suivi' : ( abs( $delta ) < .05 ? 'Stable' : ( $delta > 0 ? '+' : '−' ) . ( 'taux' === $base ? number_format( abs( $delta ), 1, ',', ' ' ) . ' pts' : ueb_adm_montant_court( abs( $delta ) ) . ( 'encaisse' === $base ? ' FCFA' : '' ) ) );
	};
	if ( 'depots' === $base ) {
		$variation = $double ? ueb_formater_montant( array_sum( $valeurs ) ) . ' et ' . ueb_formater_montant( array_sum( $valeurs2 ) ) . ' dépôts' : ueb_formater_montant( array_sum( $valeurs ) ) . ' dépôts';
	} else {
		$variation = $double ? $ecart( $valeurs ) . ' et ' . $ecart( $valeurs2 ) : $ecart( $valeurs );
	}
	$valeurs_texte = array();
	foreach ( $valeurs as $i => $v ) {
		$valeurs_texte[] = $double
			? ueb_adm_valeur_historique( $base, $v ) . ' ' . $seconde['noms'][0] . ', ' . ueb_adm_valeur_historique( $base, $valeurs2[ $i ] ) . ' ' . $seconde['noms'][1]
			: ueb_adm_valeur_historique( $base, $v );
	}
	$dates = array_map( 'ueb_graphe_date_courte', array_slice( $jours, 0, $n ) );
	$donnees = array( 'dates' => $dates, 'valeurs' => $valeurs_texte, 'points' => $points ) + ( $double ? array( 'points2' => $points2 ) : array() );
	$id = 'adm-spark-' . $cle;
	?>
	<figure class="adm-spark<?php echo $double ? ' adm-spark--double' : ''; ?>" data-mini-courbe="<?php echo esc_attr( wp_json_encode( $donnees ) ); ?>">
		<figcaption><span><?php echo esc_html( $titre ); ?></span><b title="<?php echo esc_attr( 'depots' === $base ? 'Somme des dépôts quotidiens sur la période' : ( $double ? 'Variation de chaque type entre le premier et le dernier jour affichés' : 'Variation entre le premier et le dernier jour affichés' ) ); ?>"><?php echo esc_html( $variation ); ?></b></figcaption>
		<div class="adm-spark__zone" tabindex="0" role="group" aria-label="<?php echo esc_attr( $titre . ( $double ? ' (' . implode( ', puis ', $seconde['noms'] ) . ')' : '' ) . ', du ' . $dates[0] . ' au ' . $dates[ $n - 1 ] . '. Flèches gauche et droite pour parcourir les jours.' ); ?>" aria-describedby="<?php echo esc_attr( $id . '-valeur' ); ?>">
			<svg viewBox="0 0 240 62" preserveAspectRatio="none" aria-hidden="true">
				<defs><linearGradient id="<?php echo esc_attr( $id ); ?>" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#b9e4b7" stop-opacity=".26"/><stop offset="100%" stop-color="#b9e4b7" stop-opacity=".015"/></linearGradient></defs>
				<path class="adm-spark__base" d="M4,54 H236"/>
				<?php if ( ! $double ) : ?><path d="<?php echo esc_attr( $aire ); ?>" fill="url(#<?php echo esc_attr( $id ); ?>)"/><?php endif; ?>
				<path class="adm-spark__ligne" d="<?php echo esc_attr( $ligne ); ?>"/>
				<?php if ( $double ) : ?><path class="adm-spark__ligne adm-spark__ligne--seconde" d="<?php echo esc_attr( $ligne2 ); ?>"/><?php endif; ?>
				<?php if ( null !== $points[ $n - 1 ][1] ) : ?><circle class="adm-spark__fin" cx="236" cy="<?php echo esc_attr( $points[ $n - 1 ][1] ); ?>" r="2.8"/><?php endif; ?>
				<?php if ( $double && null !== $points2[ $n - 1 ][1] ) : ?><circle class="adm-spark__fin adm-spark__fin--seconde" cx="236" cy="<?php echo esc_attr( $points2[ $n - 1 ][1] ); ?>" r="2.8"/><?php endif; ?>
				<path class="adm-spark__repere" d="M236,4 V58"/>
				<circle class="adm-spark__point" cx="236" cy="54" r="3"/>
				<?php if ( $double ) : ?><circle class="adm-spark__point adm-spark__point--seconde" cx="236" cy="54" r="3"/><?php endif; ?>
			</svg>
			<output class="adm-spark__lecture" id="<?php echo esc_attr( $id . '-valeur' ); ?>" aria-live="polite"><?php echo esc_html( $dates[ $n - 1 ] . ' · ' . $valeurs_texte[ $n - 1 ] ); ?></output>
		</div>
		<div class="adm-spark__dates" aria-hidden="true"><span><?php echo esc_html( $dates[0] ); ?></span><span><?php echo esc_html( $dates[ $n - 1 ] ); ?></span></div>
	</figure>
	<?php
}

/**
 * Données accessibles et définition explicite de l'historique reconstitué.
 *
 * @param array $types Types de quitus que le compte voit : quitus et dépôts y ont une colonne chacun.
 */
function ueb_adm_donnees_courbes( array $historique, array $types = array( 'droits' ) ) {
	if ( empty( $historique['jours'] ) ) { return; }
	$colonnes = array( 'etudiants' => 'Étudiants', 'encaisse' => 'Droits encaissés' );
	foreach ( $types as $t ) {
		$colonnes[ 'quitus_' . $t ] = 'Quitus, ' . mb_strtolower( UEB_TYPES_QUITUS[ $t ]['libelle'] );
	}
	$colonnes['taux'] = 'Recouvrement';
	foreach ( $types as $t ) {
		$colonnes[ 'depots_' . $t ] = 'Dépôts du jour, ' . mb_strtolower( UEB_TYPES_QUITUS[ $t ]['libelle'] );
	}
	?>
	<details class="adm-historique">
		<summary>Données des mini-courbes<?php echo ueb_icone( 'chevron', 14 ); ?></summary>
		<p>Les cumuls sont reconstitués à partir des quitus conservés, des dates de validation et des formations actuelles. Les anciennes décisions annulées et les pièces supprimées ne sont pas conservées dans cet historique. Les droits encaissés excluent les frais médicaux et les trop-perçus. Quitus et dépôts se comptent séparément pour les droits universitaires et pour les frais médicaux.</p>
		<p>La courbe « Reçus à vérifier » indique les dépôts quotidiens, pas l’ancienne file d’attente : un quitus compte une fois par jour de dépôt, même avec plusieurs pièces. Les variations comparent le premier et le dernier jour affichés.</p>
		<div class="adm-historique__table" tabindex="0" role="region" aria-label="Historique quotidien des indicateurs">
			<table><caption class="sr">Valeurs des mini-courbes par jour</caption><thead><tr><th scope="col">Date</th><?php foreach ( $colonnes as $libelle ) : ?><th scope="col"><?php echo esc_html( $libelle ); ?></th><?php endforeach; ?></tr></thead><tbody>
				<?php foreach ( $historique['jours'] as $i => $jour ) : ?><tr><th scope="row"><?php echo esc_html( ueb_graphe_date_courte( $jour ) ); ?></th><?php foreach ( array_keys( $colonnes ) as $cle ) : ?><td><?php echo esc_html( ueb_adm_valeur_historique( $cle, $historique[ $cle ][ $i ] ?? 0 ) ); ?></td><?php endforeach; ?></tr><?php endforeach; ?>
			</tbody></table>
		</div>
	</details>
	<?php
}

/**
 * Situation des quitus : total de l'année et barres par statut, pour chaque
 * type de quitus que le compte voit (jamais additionnés). Sans détail par
 * type (tableau du CMS), un seul groupe, celui de $c.
 */
function ueb_adm_statistiques( array $c, $sous_titre = '' ) {
	$groupes = array();
	if ( isset( $c['types'] ) ) {
		foreach ( ueb_types_stats_visibles() ?: array( 'droits' ) as $t ) {
			$x = $c['types'][ $t ];
			$groupes[ $t ] = array( 'quitus' => $x['quitus'], 'a_payer' => $x['genere'], 'recus_envoyes' => $x['recu_envoye'], 'recus_verifies' => $x['verifie'], 'recus_rejetes' => $x['rejete'] );
		}
	} else {
		$groupes[''] = $c;
	}
	$plusieurs  = 1 < count( $groupes );
	$sous_titre = $sous_titre ?: ( $plusieurs ? 'Chaque type de quitus séparément' : UEB_TYPES_QUITUS[ array_key_first( $groupes ) ]['libelle'] );
	?>
	<section class="adm-panneau adm-statistiques<?php echo $plusieurs ? ' adm-statistiques--types' : ''; ?>" aria-labelledby="adm-statistiques-titre">
		<header class="adm-panneau__tete"><div><h2 id="adm-statistiques-titre">Situation des quitus</h2><p><?php echo esc_html( $sous_titre ); ?></p></div></header>
		<?php foreach ( $groupes as $t => $g ) : ?>
			<div class="adm-statistiques__groupe">
				<?php if ( $plusieurs ) : ?>
					<h3 class="adm-statistiques__type"><i class="adm-type-cle adm-type-cle--<?php echo esc_attr( $t ); ?>" aria-hidden="true"></i><?php echo esc_html( UEB_TYPES_QUITUS[ $t ]['libelle'] ); ?></h3>
				<?php endif; ?>
				<p class="adm-statistiques__total"><b><?php echo esc_html( ueb_formater_montant( $g['quitus'] ) ); ?></b><span><?php echo $plusieurs ? 'quitus' : 'quitus cette année'; ?></span></p>
				<ul class="adm-barres-statut">
					<?php foreach ( array(
						array( 'À payer', $g['a_payer'], 'var(--dash-or)' ),
						array( 'À vérifier', $g['recus_envoyes'], 'var(--dash-bleu)' ),
						array( 'Vérifiés', $g['recus_verifies'], 'var(--dash-foret)' ),
						array( 'À corriger', $g['recus_rejetes'], 'var(--danger)' ),
					) as $statut ) : ?>
						<li><span><?php echo esc_html( $statut[0] ); ?></span><b><?php echo esc_html( ueb_formater_montant( $statut[1] ) ); ?></b><span class="adm-barre" aria-hidden="true"><i style="--largeur: <?php echo esc_attr( $g['quitus'] ? 100 * $statut[1] / $g['quitus'] : 0 ); ?>%; --couleur: <?php echo esc_attr( $statut[2] ); ?>"></i></span></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endforeach; ?>
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
