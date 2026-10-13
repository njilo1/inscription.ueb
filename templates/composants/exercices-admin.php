<?php
/**
 * Onglet « Exercice » du Pilotage (page-administration.php, ?vue=exercices),
 * réservé au super-administrateur, en deux colonnes :
 *   - à gauche, les années académiques, du plus récent à 2022-2023 (création
 *     de l'université) : icône de calendrier, statut, quitus. Un
 *     clic consulte un exercice : la sélection y glisse, et tout l'espace
 *     d'administration affiche alors ses données ;
 *   - à droite, le détail de l'exercice consulté, en cartes :
 *       1. la une : titre, statut, dates, et le panneau « Statut actuel »
 *          (interrupteur « Exercice actif » et décision possible : activer,
 *          passer au suivant, clôturer, rouvrir). Un exercice clôturé y porte
 *          le sceau « Exercice clôturé », frappé à la clôture ;
 *       2. l'avancement de l'année : la frise des douze mois (composition
 *          Remotion « frise »), jours écoulés et restants ;
 *       3. ses chiffres, dans une seule carte ;
 *       4. les raccourcis vers ses données, et l'historique de ses décisions.
 * Règles et actions : inc/exercices.php. Styles : assets/css/exercices.css ;
 * mouvement : assets/js/exercices.js.
 */
defined( 'ABSPATH' ) || exit;

$codes      = ueb_exercices_codes();
$en_cours   = ueb_annee_academique();
$consulte   = ueb_exercice_consulte();
$calendrier = ueb_exercice_calendaire();
$reglages   = ueb_exercices_reglages();
$effectifs  = ueb_exercices_effectifs();
$statut     = ueb_exercice_statut( $consulte['code'] );
$chiffres   = ueb_gestion_chiffres( $consulte['code'] );
$encaisse   = ueb_suivi_paiements( $consulte['code'] )['global']['encaisse'];
$av         = ueb_exercice_avancement( $consulte['code'] );
$cloture    = $reglages['clotures'][ $consulte['code'] ] ?? null;
$historique = array_values( array_filter( $reglages['journal'], static fn( $j ) => $consulte['code'] === ( $j['code'] ?? '' ) ) );
$suivant    = ueb_exercice( ( $consulte['debut'] + 1 ) . '-' . ( $consulte['debut'] + 2 ) );
$suivant    = $suivant && in_array( $suivant['code'], $codes, true ) && ! ueb_exercice_cloture( $suivant['code'] ) ? $suivant : null;
$nom_compte = static function ( $id ) {
	$u = get_userdata( (int) $id );
	return $u ? ( $u->display_name ?: $u->user_login ) : 'un compte supprimé';
};
/* Sceau de la clôture : la date de la décision, ou celle du jour pour la frappe. */
$sceau = static fn( $date ) => array( 'etat' => 'cloture', 'sigle' => 'UEB', 'service' => 'ADMINISTRATION', 'date' => $date );

ueb_adm_tete( array(
	'titre'      => 'Exercice',
	'sous_titre' => 'Les années académiques de la plateforme depuis la création de l’université. L’exercice actif reçoit les inscriptions ; tu peux en consulter un autre à tout moment, tout l’espace d’administration le suit.',
) );
ueb_afficher_flash();

/* Le calendrier a dépassé l'exercice en cours : rien ne bascule seul, on le signale. */
if ( $calendrier['debut'] > $en_cours['debut'] && ! ueb_exercice_cloture( $calendrier['code'] ) ) {
	ueb_alerte( 'info', sprintf( 'Le calendrier est passé à %1$s, mais l’exercice actif reste %2$s. Active %1$s quand ses inscriptions commencent.', $calendrier['libelle'], $en_cours['libelle'] ) );
}

/* Ce qu'est l'exercice consulté, selon son statut. */
$textes = array(
	'en_cours' => 'C’est l’exercice des inscriptions : les étudiants y préparent leurs quitus, la scolarité et le CMS y valident les reçus.',
	'ouvert'   => 'Exercice passé, encore ouvert : ses reçus peuvent toujours être validés ou renvoyés. Clôture-le quand ses comptes sont arrêtés.',
	'cloture'  => $cloture ? sprintf( 'Clôturé le %s par %s. Ses chiffres restent consultables ; plus aucun reçu n’y est validé ni renvoyé.', date_i18n( 'j F Y', strtotime( $cloture['date'] ) ), $nom_compte( $cloture['par'] ) ) : 'Ses chiffres restent consultables ; plus aucun reçu n’y est validé ni renvoyé.',
	'a_venir'  => 'Pas encore ouvert aux inscriptions. Une fois activé, les nouveaux quitus des étudiants y seront rattachés.',
);
$confirmer_activer = static fn( $e ) => array(
	'titre'  => 'Activer l’exercice ' . $e['libelle'] . ' ?',
	'texte'  => 'Il devient l’exercice des inscriptions pour tous : les étudiants y prépareront leurs nouveaux quitus, la scolarité et le CMS y travailleront. ' . $en_cours['libelle'] . ' restera ouvert jusqu’à sa clôture.',
	'bouton' => 'Oui, activer',
	'ton'    => 'enregistrer',
);

/* Panneau « Statut actuel » : l'interrupteur dit si l'exercice est actif ; il
   s'allume pour activer l'exercice, jamais pour l'éteindre (on active le
   suivant). Verrouillé quand rien ne peut changer depuis cet exercice. */
$etat = array(
	'en_cours' => array(
		'titre' => 'Exercice actif',
		'note'  => 'Un seul exercice est actif à la fois. Activer l’année suivante met fin à celui-ci : il reste ouvert à ses reçus jusqu’à sa clôture.',
	),
	'a_venir'  => array(
		'titre' => 'Pas encore actif',
		'note'  => sprintf( 'Activé, il remplace %s, qui reste ouvert à ses reçus jusqu’à sa clôture.', $en_cours['libelle'] ),
	),
	'ouvert'   => array(
		'titre' => 'Inactif, encore ouvert',
		'note'  => 'Clôturé, il reste consultable mais plus aucun reçu n’y est validé ni renvoyé. Tu pourras le rouvrir.',
	),
	'cloture'  => array(
		'titre' => 'Clôturé',
		'note'  => 'Rouvre-le pour rendre de nouveau des décisions sur ses reçus, ou pour l’activer.',
	),
)[ $statut ];

/* Avancement : deux repères selon que l'année a commencé, se déroule ou s'est achevée. */
$jours = static fn( $n ) => sprintf( '<span data-compter="%1$d">%1$d</span>', (int) $n );
if ( ! $av['ecoules'] ) {
	$reperes = array(
		array( 'icone' => 'calendrier', 'libelle' => 'Début de l’année', 'valeur' => '1<sup>er</sup> sept.', 'unite' => (string) $av['debut'] ),
		$av['avant']
			? array( 'icone' => 'horloge', 'libelle' => 'Avant son ouverture', 'valeur' => $jours( $av['avant'] ), 'unite' => $av['avant'] > 1 ? 'jours' : 'jour' )
			: array( 'icone' => 'horloge', 'libelle' => 'Au calendrier', 'valeur' => 'Commencée', 'unite' => 'pas encore activée' ),
	);
} elseif ( $av['restants'] ) {
	$reperes = array(
		array( 'icone' => 'calendrier', 'libelle' => 'Jours écoulés', 'valeur' => $jours( $av['ecoules'] ), 'unite' => $av['ecoules'] > 1 ? 'jours' : 'jour' ),
		array( 'icone' => 'horloge', 'libelle' => 'Jours restants', 'valeur' => $jours( $av['restants'] ), 'unite' => $av['restants'] > 1 ? 'jours' : 'jour' ),
	);
} else {
	$reperes = array(
		array( 'icone' => 'calendrier', 'libelle' => 'Jours écoulés', 'valeur' => $jours( $av['ecoules'] ), 'unite' => 'jours' ),
		array( 'icone' => 'check', 'libelle' => 'Année achevée le', 'valeur' => '31 août', 'unite' => (string) ( $av['debut'] + 1 ) ),
	);
}
$part      = (int) round( $av['progression'] * 100 );
$aujourdui = 'en_cours' === $statut && $av['ecoules'] && $av['restants'];

/* Chiffres de l'exercice : ceux sous le million comptent jusqu'à leur valeur à l'arrivée. */
$chiffre = static function ( $valeur, $texte ) {
	return $valeur < 1000000 ? sprintf( '<span data-compter="%d">%s</span>', (int) $valeur, esc_html( $texte ) ) : esc_html( $texte );
};
$a_verifier = (int) $chiffres['recus_envoyes'];
?>

<div class="exo-maitre">
	<nav class="exo-liste" aria-labelledby="exo-liste-titre" data-exo-liste>
		<header class="exo-liste__tete">
			<h2 id="exo-liste-titre">Années académiques</h2>
			<p><?php echo esc_html( sprintf( '%d depuis la création de l’université', count( $codes ) ) ); ?></p>
		</header>
		<div class="exo-liste__corps">
			<span class="exo-liste__selection" aria-hidden="true" data-exo-selection></span>
			<ol class="exo-liste__annees">
				<?php foreach ( array_reverse( $codes ) as $code ) :
					$e     = ueb_exercice( $code );
					$st    = ueb_exercice_statut( $code );
					$n     = $effectifs[ $code ]['quitus'] ?? 0;
					$actif = $code === $consulte['code'];
					?>
					<li class="exo-ligne exo-ligne--<?php echo esc_attr( $st ); ?><?php echo $actif ? ' est-consulte' : ''; ?>">
						<form method="post" action="<?php echo esc_url( ueb_url_exercices() ); ?>">
							<?php ueb_champ_csrf(); ?>
							<input type="hidden" name="ueb_action" value="exercice_consulter">
							<input type="hidden" name="exercice" value="<?php echo esc_attr( $code ); ?>">
							<button class="exo-ligne__bouton" type="submit"<?php echo $actif ? ' aria-current="true"' : ''; ?>>
								<span class="exo-ligne__icone" aria-hidden="true"><?php echo ueb_icone( 'calendrier', 19 ); ?></span>
								<span class="exo-ligne__texte">
									<b><?php echo esc_html( $e['libelle'] ); ?></b>
									<span class="exo-ligne__statut"><?php echo 'cloture' === $st ? ueb_icone( 'cadenas', 12 ) : '<i aria-hidden="true"></i>'; // phpcs:ignore -- SVG interne ?><?php echo esc_html( UEB_EXERCICE_STATUTS[ $st ]['libelle'] ); ?></span>
								</span>
								<span class="exo-ligne__quitus<?php echo $n ? '' : ' est-vide'; ?>"><b><?php echo esc_html( ueb_formater_montant( $n ) ); ?></b><small>quitus</small></span>
								<?php echo ueb_icone( 'chevron-d', 16, 'exo-ligne__chevron' ); ?>
								<span class="sr"><?php echo $actif ? 'Exercice consulté' : 'Consulter cet exercice'; ?></span>
							</button>
						</form>
					</li>
				<?php endforeach; ?>
			</ol>
		</div>
	</nav>

	<div class="exo-detail exo-detail--<?php echo esc_attr( $statut ); ?>" data-exo-detail>

		<section class="exo-une" aria-labelledby="exo-detail-titre">
			<div class="exo-une__texte">
				<header class="exo-une__tete">
					<h2 id="exo-detail-titre">Exercice <?php echo esc_html( $consulte['libelle'] ); ?></h2>
					<span class="exo-statut exo-statut--<?php echo esc_attr( $statut ); ?>"><?php echo 'cloture' === $statut ? ueb_icone( 'cadenas', 13 ) : '<i aria-hidden="true"></i>'; // phpcs:ignore -- SVG interne ?><?php echo esc_html( UEB_EXERCICE_STATUTS[ $statut ]['libelle'] ); ?></span>
				</header>
				<p class="exo-une__description"><?php echo esc_html( $textes[ $statut ] ); ?></p>
				<p class="exo-une__dates"><?php echo ueb_icone( 'calendrier', 15 ); ?><span>Du 1<sup>er</sup> septembre <?php echo (int) $consulte['debut']; ?> au 31 août <?php echo (int) $consulte['fin']; ?></span></p>
			</div>

			<aside class="exo-etat exo-etat--<?php echo esc_attr( $statut ); ?>" aria-labelledby="exo-etat-titre">
				<div class="exo-etat__tete">
					<p><span>Statut actuel</span><b id="exo-etat-titre"><?php echo esc_html( $etat['titre'] ); ?></b></p>
					<?php if ( in_array( $statut, array( 'a_venir', 'ouvert' ), true ) ) : ?>
						<?php ueb_exercice_formulaire( 'activer', $consulte['code'], array( 'libelle' => 'Exercice actif', 'interrupteur' => true ), $confirmer_activer( $consulte ), '', array( 'exo-allumer' => '1' ) ); ?>
					<?php elseif ( 'en_cours' === $statut ) : /* allumé ; il ne s'éteint pas d'ici : on active le suivant */ ?>
						<span class="exo-interrupteur est-verrouille est-allume" aria-hidden="true" title="Active l’exercice suivant pour changer d’exercice"><span class="exo-interrupteur__curseur"><?php echo ueb_icone( 'cadenas', 11 ); ?></span></span>
					<?php endif; ?>
				</div>
				<?php if ( 'cloture' === $statut && $cloture ) : ?>
					<div class="exo-etat__sceau">
						<?php ueb_animation( 'tampon', $sceau( mysql2date( 'd.m.Y', $cloture['date'] ) ), 'animation--fige exo-sceau', 'Sceau : exercice clôturé le ' . date_i18n( 'j F Y', strtotime( $cloture['date'] ) ) ); ?>
					</div>
				<?php elseif ( 'ouvert' === $statut ) : /* frappé à la clôture (assets/js/exercices.js) */ ?>
					<div class="exo-etat__sceau" data-exo-sceau hidden>
						<div class="animation exo-sceau" data-remotion-differe="tampon" data-props="<?php echo esc_attr( wp_json_encode( $sceau( current_time( 'd.m.Y' ) ) ) ); ?>" role="img" aria-label="Sceau : exercice clôturé"><div class="animation__scene" data-remotion-scene></div></div>
					</div>
				<?php endif; ?>

				<div class="exo-etat__action">
					<?php if ( 'en_cours' === $statut && $suivant ) : ?>
						<?php ueb_exercice_formulaire( 'activer', $suivant['code'], array( 'libelle' => 'Activer ' . $suivant['libelle'], 'icone' => 'lecture' ), $confirmer_activer( $suivant ) ); ?>
					<?php elseif ( 'a_venir' === $statut ) : ?>
						<?php ueb_exercice_formulaire( 'activer', $consulte['code'], array( 'libelle' => 'Activer l’exercice', 'icone' => 'lecture', 'classe' => 'adm-bouton--primaire' ), $confirmer_activer( $consulte ) ); ?>
					<?php elseif ( 'ouvert' === $statut ) : ?>
						<?php ueb_exercice_formulaire( 'cloturer', $consulte['code'], array( 'libelle' => 'Clôturer l’exercice', 'icone' => 'cadenas', 'classe' => 'adm-bouton--primaire' ), array(
							'titre'  => 'Clôturer l’exercice ' . $consulte['libelle'] . ' ?',
							'texte'  => 'Ses chiffres resteront consultables, mais plus aucun reçu ne pourra y être validé ni renvoyé. Tu pourras le rouvrir si besoin.',
							'bouton' => 'Clôturer l’exercice',
						), '', array( 'exo-sceller' => '1' ) ); ?>
					<?php elseif ( 'cloture' === $statut ) : ?>
						<?php ueb_exercice_formulaire( 'rouvrir', $consulte['code'], array( 'libelle' => 'Rouvrir l’exercice', 'icone' => 'cadenas-ouvert' ), array(
							'titre'  => 'Rouvrir l’exercice ' . $consulte['libelle'] . ' ?',
							'texte'  => 'Ses reçus pourront de nouveau être validés ou renvoyés.',
							'bouton' => 'Oui, rouvrir',
							'ton'    => 'enregistrer',
						) ); ?>
					<?php endif; ?>
				</div>

				<p class="exo-etat__note"><?php echo ueb_icone( 'info', 16 ); ?><span><?php echo esc_html( $etat['note'] ); ?></span></p>
			</aside>
		</section>

		<section class="exo-carte exo-avancement" aria-labelledby="exo-avancement-titre">
			<header class="exo-carte__tete">
				<h3 id="exo-avancement-titre"><?php echo ueb_icone( 'calendrier', 18 ); ?>Avancement de l’année académique</h3>
				<p class="exo-avancement__part"><b><span data-compter="<?php echo (int) $part; ?>"><?php echo (int) $part; ?></span>&nbsp;%</b> de l’année écoulée</p>
			</header>

			<div class="exo-frise">
				<?php if ( $aujourdui ) : ?>
					<p class="exo-frise__jour<?php echo $av['progression'] > .6 ? ' est-a-gauche' : ''; ?>" style="--x: <?php echo esc_attr( ueb_frise_position( $av['progression'] ) ); ?>%">Aujourd’hui, <?php echo esc_html( date_i18n( 'j F' ) ); ?></p>
				<?php endif; ?>
				<?php
				ueb_animation(
					'frise',
					array( 'debut' => $av['debut'], 'statut' => $statut, 'progression' => $av['progression'] ),
					'animation--frise',
					sprintf( 'Frise de l’exercice %s, de septembre à août : %d jours écoulés sur %d.', $consulte['libelle'], $av['ecoules'], $av['total'] ),
					ueb_exercice_frise_repli( $av )
				);
				?>
				<ol class="exo-frise__mois" aria-hidden="true">
					<?php foreach ( $av['mois'] as $m ) : ?>
						<li class="est-<?php echo esc_attr( $m['etat'] ); ?>" style="--x: <?php echo esc_attr( $m['centre'] ); ?>%"><span class="exo-frise__long"><?php echo esc_html( $m['court'] ); ?></span><span class="exo-frise__court"><?php echo esc_html( mb_strtoupper( mb_substr( $m['nom'], 0, 1 ) ) ); ?></span></li>
					<?php endforeach; ?>
				</ol>
			</div>

			<ul class="exo-reperes">
				<?php foreach ( $reperes as $r ) : ?>
					<li>
						<span class="exo-reperes__icone" aria-hidden="true"><?php echo ueb_icone( $r['icone'], 18 ); ?></span>
						<p><?php echo esc_html( $r['libelle'] ); ?></p>
						<p class="exo-reperes__valeur"><b><?php echo wp_kses( $r['valeur'], array( 'sup' => array(), 'span' => array( 'data-compter' => true ) ) ); ?></b> <small><?php echo esc_html( $r['unite'] ); ?></small></p>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>

		<section class="exo-carte exo-chiffres" aria-labelledby="exo-chiffres-titre">
			<h3 id="exo-chiffres-titre" class="sr">Chiffres de l’exercice</h3>
			<ul>
				<?php
				$cases = array(
					array( 'groupe', $chiffre( $chiffres['etudiants'], ueb_formater_montant( $chiffres['etudiants'] ) ), 'Étudiants', 'ayant un quitus', '' ),
					array( 'fichier', $chiffre( $chiffres['quitus'], ueb_formater_montant( $chiffres['quitus'] ) ), 'Quitus', 'générés sur l’exercice', '' ),
					array( 'banque', $chiffre( $encaisse, ueb_adm_montant_court( $encaisse ) ), 'Droits encaissés', 'en FCFA, reçus vérifiés', '' ),
					array( $a_verifier ? 'alerte' : 'check', $chiffre( $a_verifier, ueb_formater_montant( $a_verifier ) ), 'Reçus à vérifier', $a_verifier ? 'en attente de décision' : 'aucun en attente', $a_verifier ? ' exo-chiffres__case--attente' : '' ),
				);
				foreach ( $cases as $c ) :
					?>
					<li class="exo-chiffres__case<?php echo esc_attr( $c[4] ); ?>">
						<span class="exo-chiffres__icone" aria-hidden="true"><?php echo ueb_icone( $c[0], 19 ); ?></span>
						<p class="exo-chiffres__valeur"><?php echo $c[1]; // phpcs:ignore -- échappé par $chiffre ?></p>
						<p class="exo-chiffres__libelle"><b><?php echo esc_html( $c[2] ); ?></b> <?php echo esc_html( $c[3] ); ?></p>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>

		<div class="exo-bas">
			<section class="exo-carte exo-raccourcis" aria-labelledby="exo-raccourcis-titre">
				<h3 id="exo-raccourcis-titre">Ses données</h3>
				<p>Tableaux, paiements et étudiants affichent <?php echo esc_html( $consulte['libelle'] ); ?> dans tout l’espace d’administration.</p>
				<ul>
					<?php
					foreach ( array(
						array( ueb_url_administration(), 'tableau', 'Tableau de bord', 'Recouvrement, quitus, établissements' ),
						array( add_query_arg( 'vue', 'paiements', ueb_url_administration() ), 'banque', 'Paiements', 'Encaissements et rapprochement' ),
						array( add_query_arg( 'vue', 'etudiants', ueb_url_administration() ), 'diplome', 'Étudiants UEB', 'Le registre des inscrits' ),
					) as $lien ) :
						?>
						<li>
							<a href="<?php echo esc_url( $lien[0] ); ?>">
								<span class="exo-raccourcis__icone" aria-hidden="true"><?php echo ueb_icone( $lien[1], 18 ); ?></span>
								<span><b><?php echo esc_html( $lien[2] ); ?></b><small><?php echo esc_html( $lien[3] ); ?></small></span>
								<?php echo ueb_icone( 'chevron-d', 16, 'exo-raccourcis__chevron' ); ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>

			<section class="exo-carte exo-historique" aria-labelledby="exo-historique-titre">
				<h3 id="exo-historique-titre"><?php echo ueb_icone( 'horloge', 18 ); ?>Historique de l’exercice</h3>
				<?php if ( ! $historique ) : ?>
					<div class="exo-historique__vide">
						<span class="exo-historique__vide-icone" aria-hidden="true"><?php echo ueb_icone( 'fichier', 20 ); ?></span>
						<p><b><?php echo esc_html( 'en_cours' === $statut && ! $reglages['journal'] ? 'Retenu d’après le calendrier, sans décision pour l’instant.' : 'Aucune décision sur cet exercice pour l’instant.' ); ?></b>Les activations, clôtures et réouvertures s’afficheront ici, avec leur auteur et leur date.</p>
					</div>
				<?php else : ?>
					<ol class="exo-historique__liste">
						<?php
						$verbes = array( 'activer' => 'Devenu l’exercice actif', 'cloturer' => 'Clôturé', 'rouvrir' => 'Rouvert' );
						$icones = array( 'activer' => 'lecture', 'cloturer' => 'cadenas', 'rouvrir' => 'cadenas-ouvert' );
						foreach ( $historique as $j ) :
							if ( ! isset( $verbes[ $j['action'] ] ) ) {
								continue;
							}
							?>
							<li class="exo-historique__ligne exo-historique__ligne--<?php echo esc_attr( $j['action'] ); ?>">
								<span class="exo-historique__icone" aria-hidden="true"><?php echo ueb_icone( $icones[ $j['action'] ], 14 ); ?></span>
								<p><b><?php echo esc_html( $verbes[ $j['action'] ] ); ?></b> par <?php echo esc_html( $nom_compte( $j['par'] ) ); ?></p>
								<time datetime="<?php echo esc_attr( mysql2date( 'c', $j['date'] ) ); ?>"><?php echo esc_html( date_i18n( 'j F Y \à H \h i', strtotime( $j['date'] ) ) ); ?></time>
							</li>
						<?php endforeach; ?>
					</ol>
				<?php endif; ?>
			</section>
		</div>
	</div>
</div>
