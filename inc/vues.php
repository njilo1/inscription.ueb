<?php
/**
 * Outils d'affichage partagés par les gabarits : squelette de page,
 * en-têtes, icônes, champs de formulaire, messages et badges.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

/* ---------- Icônes (tracés Lucide, licence ISC) ---------- */

function ueb_icone( $nom, $taille = 20, $classe = '' ) {
	static $traces = array(
		'fleche'      => '<path d="M5 12h14M13 6l6 6-6 6"/>',
		'fleche-g'    => '<path d="M19 12H5M11 18l-6-6 6-6"/>',
		'check'       => '<path d="M20 6 9 17l-5-5"/>',
		'telecharger' => '<path d="M12 3v12M7 10l5 5 5-5M5 21h14"/>',
		'envoyer'     => '<path d="M12 21V9M7 14l5-5 5 5M5 3h14"/>',
		'fichier'     => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h5"/>',
		'bouclier'    => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
		'cadenas'     => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
		'utilisateur' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
		'telephone'   => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.8 19.8 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.18 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.1 9.9a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.9.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>',
		'banque'      => '<path d="M3 21h18M5 21V10M19 21V10M9 21V10M15 21V10M12 3 2 8h20z"/>',
		'tampon'      => '<path d="M5 22h14M19.27 13.73A2.5 2.5 0 0 0 17.5 13h-11A2.5 2.5 0 0 0 4 15.5V17a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-1.5a2.5 2.5 0 0 0-.73-1.77M14 13V8.5C14 7 15 7 15 5a3 3 0 0 0-6 0c0 2 1 2 1 3.5V13"/>',
		'recu'        => '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8M12 17.5v-11"/>',
		'sortie'      => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
		'crayon'      => '<path d="M12 20h9M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
		'oeil'        => '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
		'oeil-barre'  => '<path d="M9.88 9.88a3 3 0 1 0 4.24 4.24M10.73 5.08A10.4 10.4 0 0 1 12 5c7 0 10 7 10 7a13.2 13.2 0 0 1-1.67 2.68M6.61 6.61A13.5 13.5 0 0 0 2 12s3 7 10 7a9.7 9.7 0 0 0 5.39-1.61M2 2l20 20"/>',
		'alerte'      => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4M12 17h.01"/>',
		'info'        => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
		'croix'       => '<path d="M18 6 6 18M6 6l12 12"/>',
		'plus'        => '<path d="M12 5v14M5 12h14"/>',
		'loupe'       => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
		'horloge'     => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
		'chevron'     => '<path d="m6 9 6 6 6-6"/>',
		'menu'        => '<path d="M4 6h16M4 12h16M4 18h16"/>',
		'corbeille'   => '<path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
		'courriel'    => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>',
		'lieu'        => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
		'qr'          => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM20 14v.01M14 20h.01M17 20h4v-3"/>',
		'appareil'    => '<path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3z"/><circle cx="12" cy="13" r="3"/>',
		'cle'         => '<circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6M15.5 7.5l3 3L22 7l-3-3"/>',
		'pause'       => '<rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/>',
		'lecture'     => '<path d="M6 4l14 8-14 8z"/>',
		'reglages'    => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
		'ecole'       => '<path d="M14 22v-4a2 2 0 1 0-4 0v4M18 10l4 2v8a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-8l4-2M18 5v17M4 6l8-4 8 4M6 5v17"/><circle cx="12" cy="9" r="2"/>',
	);
	if ( ! isset( $traces[ $nom ] ) ) {
		return '';
	}
	return sprintf(
		'<svg class="icone %s" width="%d" height="%d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%s</svg>',
		esc_attr( $classe ),
		$taille,
		$taille,
		$traces[ $nom ]
	);
}

/* ---------- Squelette de page ---------- */

/**
 * @param array{titre?:string, variante?:string, classe?:string} $args
 *        variante : landing | auth | espace | gestion | simple
 */
function ueb_page_debut( array $args = array() ) {
	$args = wp_parse_args( $args, array( 'titre' => '', 'variante' => 'simple', 'classe' => '' ) );
	$GLOBALS['ueb_titre_page'] = $args['titre'];
	?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0f2c1f">
<link rel="icon" href="<?php echo esc_url( ueb_logo_url( 'UEB' ) ); ?>">
<?php wp_head(); ?>
</head>
<body <?php body_class( 'variante-' . $args['variante'] . ' ' . $args['classe'] ); ?>>
<?php wp_body_open(); ?>
<a class="lien-evitement" href="#contenu">Aller au contenu</a>
	<?php
	/* La variante « bo » est une coque plein écran : elle porte sa propre
	   marque et son propre compte dans la barre latérale, donc pas d'en-tête
	   de site par-dessus. */
	if ( ! in_array( $args['variante'], array( 'auth', 'bo' ), true ) ) {
		ueb_entete_site( $args['variante'] );
	}
}

/* ---------- Suivi des paiements (administration et scolarité) ---------- */

/** Montant lisible : « 700 000 FCFA ». */
function ueb_fcfa( $montant ) {
	return ueb_formater_montant( (int) $montant ) . ' FCFA';
}

/** Pourcentage à une décimale, à la française : « 14,3 % ». */
function ueb_pourcent( $valeur ) {
	return number_format( (float) $valeur, $valeur > 0 && $valeur < 10 ? 1 : 0, ',', ' ' ) . ' %';
}

/**
 * Barre de progression du paiement : encaissé, en vérification, déclaré,
 * pas encore déclaré. Les quatre parts font 100 % de l'attendu.
 */
function ueb_suivi_barre( array $a, $classe = '' ) {
	$parts = array(
		'encaisse'     => 'Encaissé',
		'verification' => 'En vérification',
		'declare'      => 'Déclaré',
		'non_declare'  => 'Pas encore déclaré',
	);
	$resume = array();
	foreach ( $parts as $cle => $libelle ) {
		$resume[] = $libelle . ' ' . ueb_pourcent( ueb_suivi_taux( $a, $cle ) );
	}
	?>
	<div class="suivi-barre <?php echo esc_attr( $classe ); ?>" role="img" aria-label="<?php echo esc_attr( implode( ', ', $resume ) ); ?>">
		<?php foreach ( $parts as $cle => $libelle ) :
			$part = ueb_suivi_taux( $a, $cle );
			if ( $part <= 0 ) {
				continue;
			}
			?>
			<span class="suivi-barre__part suivi-barre__part--<?php echo esc_attr( $cle ); ?>" style="--part: <?php echo esc_attr( round( $part, 3 ) ); ?>%" data-info="<?php echo esc_attr( $libelle . ' · ' . ueb_fcfa( $a[ $cle ] ) . ' · ' . ueb_pourcent( $part ) ); ?>"></span>
		<?php endforeach; ?>
	</div>
	<?php
}

/**
 * Carte compacte du tableau de bord : taux, barre, lien vers le suivi complet.
 */
function ueb_suivi_carte( array $suivi, $url, $perimetre ) {
	$g = $suivi['global'];
	?>
	<a class="carte suivi-carte" href="<?php echo esc_url( $url ); ?>">
		<span class="suivi-carte__tete">
			<span class="suivi-carte__titre">Recouvrement des droits · <?php echo esc_html( $perimetre ); ?></span>
			<span class="suivi-carte__lien">Suivi des paiements<?php echo ueb_icone( 'fleche', 16 ); ?></span>
		</span>
		<span class="suivi-carte__corps">
			<span class="suivi-carte__taux"><?php echo esc_html( ueb_pourcent( ueb_suivi_taux( $g ) ) ); ?></span>
			<span class="suivi-carte__phrase"><b><?php echo esc_html( ueb_fcfa( $g['encaisse'] ) ); ?></b> encaissés sur <?php echo esc_html( ueb_fcfa( $g['attendu'] ) ); ?> attendus · <?php echo (int) $g['soldes']; ?> étudiant<?php echo $g['soldes'] > 1 ? 's' : ''; ?> soldé<?php echo $g['soldes'] > 1 ? 's' : ''; ?> sur <?php echo (int) $g['etudiants']; ?></span>
		</span>
		<?php ueb_suivi_barre( $g ); ?>
	</a>
	<?php
}

/**
 * Suivi complet des paiements, partagé par la scolarité et l'administration :
 * héros du recouvrement (jauge animée, inc/bord.php), tableau par établissement
 * ou par filière en pleine largeur, puis niveaux et frais médicaux côte à côte,
 * et la méthode de calcul.
 *
 * @param array $args perimetre (texte), lignes ('etabs' ou 'filieres'),
 *                    lien_ligne (callable sigle => url, facultatif).
 */
function ueb_suivi_paiements_vue( array $suivi, array $args ) {
	$args = array_merge( array( 'perimetre' => '', 'lignes' => 'filieres', 'lien_ligne' => null ), $args );
	?>
	<div class="suivi">
		<?php
		ueb_bord_recouvrement( $suivi, '', $args['perimetre'], array( 'reste' => true, 'titre' => 'Taux de recouvrement des droits' ) );
		ueb_suivi_tableau( $suivi, $args );
		?>
		<div class="suivi__rangee">
			<?php
			ueb_suivi_niveaux( $suivi['niveaux'] );
			ueb_suivi_medicaux( $suivi['medicaux'] );
			?>
		</div>
		<?php ueb_suivi_methode(); ?>
	</div>
	<?php
}

/** « 1 étudiant », « 8 étudiants ». */
function ueb_suivi_etudiants( $nombre ) {
	return (int) $nombre . ( (int) $nombre > 1 ? ' étudiants' : ' étudiant' );
}

/**
 * Légende compacte d'une barre : pastille de la couleur de la part et libellé.
 *
 * @param array $cles cle de part (encaisse, verification, declare, non_declare) => libellé.
 */
function ueb_suivi_cles( array $cles, $etiquette ) {
	?>
	<ul class="suivi-cles" aria-label="<?php echo esc_attr( $etiquette ); ?>">
		<?php foreach ( $cles as $cle => $libelle ) : ?>
			<li class="suivi-legende__item--<?php echo esc_attr( $cle ); ?>"><i aria-hidden="true"></i><?php echo esc_html( $libelle ); ?></li>
		<?php endforeach; ?>
	</ul>
	<?php
}

/**
 * Tableau du recouvrement, une ligne par établissement (administration, ligne
 * cliquable vers ses filières) ou par filière, et la ligne Total. Sous 860 px,
 * chaque ligne devient une carte (libellés des colonnes via data-titre).
 */
function ueb_suivi_tableau( array $suivi, array $args ) {
	$g        = $suivi['global'];
	$par_etab = 'etabs' === $args['lignes'];
	$lignes   = $suivi[ $args['lignes'] ] ?? array();
	$lien_de  = $par_etab && is_callable( $args['lien_ligne'] ) ? $args['lien_ligne'] : null;
	$rang     = 0;
	?>
	<section class="suivi-panneau suivi-lignes" aria-labelledby="suivi-lignes-titre">
		<header class="suivi-panneau__entete">
			<div>
				<h2 id="suivi-lignes-titre"><?php echo $par_etab ? 'Par établissement' : 'Par filière'; ?></h2>
				<p><?php echo $lien_de ? 'Du plus gros montant attendu au plus petit. Ouvre un établissement pour voir ses filières. Montants en FCFA.' : 'Du plus gros montant attendu au plus petit. Montants en FCFA.'; ?></p>
			</div>
			<?php if ( $lignes ) {
				ueb_suivi_cles( array( 'encaisse' => 'Encaissé', 'verification' => 'En vérification', 'declare' => 'Déclaré', 'non_declare' => 'Pas encore déclaré' ), 'Lecture de la barre de recouvrement' );
			} ?>
		</header>

		<?php if ( ! $lignes ) : ?>
			<div class="bo-vide"><span><?php echo ueb_icone( 'banque', 22 ); ?></span><p>Aucun quitus de droits universitaires cette année pour l’instant.</p></div>
		<?php else : ?>
			<table class="suivi-table">
				<caption class="sr">Recouvrement des droits universitaires <?php echo $par_etab ? 'par établissement' : 'par filière'; ?>, montants en FCFA</caption>
				<thead>
					<tr>
						<th scope="col" class="suivi-table__col-nom"><?php echo $par_etab ? 'Établissement' : 'Filière'; ?></th>
						<th scope="col" class="num suivi-table__effectif">Étudiants</th>
						<th scope="col" class="num">Attendu</th>
						<th scope="col" class="num">Encaissé</th>
						<th scope="col" class="num">Reste</th>
						<th scope="col" class="suivi-table__col-taux">Recouvrement</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $lignes as $cle => $a ) :
					$sigle = $par_etab ? $cle : $a['etab'];
					$e     = ueb_etablissement( $sigle );
					$lien  = $lien_de ? call_user_func( $lien_de, $sigle ) : '';
					?>
					<tr class="suivi-table__ligne<?php echo $lien ? ' est-cliquable' : ''; ?>" style="--i: <?php echo (int) min( $rang++, 6 ); ?>">
						<th scope="row" class="suivi-table__nom">
							<span class="suivi-table__nom-corps">
								<?php if ( $par_etab ) : ?>
									<span class="suivi-table__logo" style="--etab: <?php echo esc_attr( $e['couleur'] ?? '#13351a' ); ?>"><img src="<?php echo esc_url( ueb_logo_url( $sigle ) ); ?>" alt="" width="26" height="26" loading="lazy"></span>
								<?php endif; ?>
								<span class="suivi-table__texte">
									<?php if ( $par_etab ) : ?>
										<?php if ( $lien ) : ?>
											<a class="suivi-table__intitule suivi-table__lien" href="<?php echo esc_url( $lien ); ?>" title="<?php echo esc_attr( $e['fr'] ?? $sigle ); ?>"><?php echo esc_html( $sigle ); ?><span class="sr"> : voir ses filières</span></a>
										<?php else : ?>
											<span class="suivi-table__intitule"><?php echo esc_html( $sigle ); ?></span>
										<?php endif; ?>
										<small class="suivi-table__complet" title="<?php echo esc_attr( $e['fr'] ?? '' ); ?>"><?php echo esc_html( $e['fr'] ?? '' ); ?></small>
									<?php else : ?>
										<span class="suivi-table__intitule suivi-table__intitule--long" title="<?php echo esc_attr( $a['libelle'] ); ?>"><?php echo esc_html( $a['libelle'] ); ?></span>
										<?php if ( $a['pro'] ) : ?>
											<small class="suivi-table__pro" title="Attendu : total des quitus préparés, le tarif étant fixé par l’établissement">Formation professionnelle</small>
										<?php endif; ?>
									<?php endif; ?>
									<small class="suivi-table__effectif-ligne"><?php echo esc_html( ueb_suivi_etudiants( $a['etudiants'] ) ); ?></small>
									<?php if ( $a['trop_percu'] > 0 ) : ?>
										<small class="suivi-table__trop" title="Vérifié au-delà de l’attendu : à contrôler"><?php echo ueb_icone( 'alerte', 14 ); ?><span>Trop-perçu : <?php echo esc_html( ueb_fcfa( $a['trop_percu'] ) ); ?></span></small>
									<?php endif; ?>
								</span>
							</span>
						</th>
						<?php ueb_suivi_cellules( $a, (bool) $lien_de ); ?>
					</tr>
				<?php endforeach; ?>
				</tbody>
				<?php if ( count( $lignes ) > 1 ) : ?>
					<tfoot>
						<tr class="suivi-table__ligne suivi-table__total" style="--i: <?php echo (int) min( $rang, 6 ); ?>">
							<th scope="row" class="suivi-table__nom">
								<span class="suivi-table__nom-corps">
									<span class="suivi-table__texte">
										<span class="suivi-table__intitule">Total</span>
										<small class="suivi-table__effectif-ligne"><?php echo esc_html( ueb_suivi_etudiants( $g['etudiants'] ) ); ?></small>
									</span>
								</span>
							</th>
							<?php ueb_suivi_cellules( $g, (bool) $lien_de ); ?>
						</tr>
					</tfoot>
				<?php endif; ?>
			</table>
		<?php endif; ?>
	</section>
	<?php
}

/**
 * Cellules chiffrées d'une ligne du tableau : effectif, attendu, encaissé,
 * reste, puis la barre en quatre parts et le taux. $fleche réserve la place
 * de la flèche des lignes cliquables (la ligne Total reste alignée).
 */
function ueb_suivi_cellules( array $a, $fleche = false ) {
	?>
	<td class="num suivi-table__effectif" data-titre="Étudiants"><?php echo (int) $a['etudiants']; ?></td>
	<td class="num" data-titre="Attendu"><?php echo esc_html( ueb_formater_montant( $a['attendu'] ) ); ?></td>
	<td class="num suivi-table__encaisse" data-titre="Encaissé"><?php echo esc_html( ueb_formater_montant( $a['encaisse'] ) ); ?></td>
	<td class="num" data-titre="Reste"><?php echo esc_html( ueb_formater_montant( $a['attendu'] - $a['encaisse'] ) ); ?></td>
	<td class="suivi-table__taux">
		<span class="suivi-table__mesure">
			<?php ueb_suivi_barre( $a, 'suivi-barre--ligne' ); ?>
			<b><?php echo esc_html( ueb_pourcent( ueb_suivi_taux( $a ) ) ); ?></b>
			<?php if ( $fleche ) : ?>
				<span class="suivi-table__aller" aria-hidden="true"><?php echo ueb_icone( 'fleche', 16 ); ?></span>
			<?php endif; ?>
		</span>
	</td>
	<?php
}

/**
 * Recouvrement par niveau (L1 → M2, puis « Non précisé ») : barres
 * horizontales sur une échelle de 0 à 100 % de l'attendu, part encaissée puis
 * part en vérification ; effectif à gauche, taux et montant encaissé à droite.
 */
function ueb_suivi_niveaux( array $niveaux ) {
	$rang = 0;
	?>
	<section class="suivi-panneau suivi-niveaux" aria-labelledby="suivi-niveaux-titre">
		<header class="suivi-panneau__entete">
			<div>
				<h2 id="suivi-niveaux-titre">Par niveau</h2>
				<p>Part des droits attendus déjà encaissée, et celle dont le reçu est en vérification.</p>
			</div>
			<?php if ( $niveaux ) {
				ueb_suivi_cles( array( 'encaisse' => 'Encaissé', 'verification' => 'En vérification' ), 'Lecture des barres par niveau' );
			} ?>
		</header>

		<?php if ( ! $niveaux ) : ?>
			<div class="bo-vide"><span><?php echo ueb_icone( 'utilisateur', 22 ); ?></span><p>Aucun étudiant pour l’instant.</p></div>
		<?php else : ?>
			<div class="suivi-niveaux__graphe">
				<div class="suivi-niveaux__grille" aria-hidden="true">
					<?php foreach ( array( 0, 25, 50, 75, 100 ) as $x ) : ?><span style="--x: <?php echo (int) $x; ?>%"></span><?php endforeach; ?>
				</div>
				<ul class="suivi-niveaux__liste">
					<?php foreach ( $niveaux as $niveau => $a ) :
						$parts  = array(
							'encaisse'     => array( 'Encaissé', ueb_suivi_taux( $a ) ),
							'verification' => array( 'En vérification', ueb_suivi_taux( $a, 'verification' ) ),
						);
						$resume = sprintf(
							'%s, %s : %s encaissés sur %s attendus (%s), %s en vérification (%s)',
							$niveau,
							ueb_suivi_etudiants( $a['etudiants'] ),
							ueb_fcfa( $a['encaisse'] ),
							ueb_fcfa( $a['attendu'] ),
							ueb_pourcent( $parts['encaisse'][1] ),
							ueb_fcfa( $a['verification'] ),
							ueb_pourcent( $parts['verification'][1] )
						);
						?>
						<li class="suivi-niveau" style="--i: <?php echo (int) min( $rang++, 6 ); ?>">
							<span class="suivi-niveau__nom"><b><?php echo esc_html( $niveau ); ?></b><small><?php echo esc_html( ueb_suivi_etudiants( $a['etudiants'] ) ); ?></small></span>
							<span class="suivi-barre suivi-barre--niveau" role="img" aria-label="<?php echo esc_attr( $resume ); ?>">
								<?php foreach ( $parts as $cle => $p ) :
									if ( $p[1] <= 0 ) {
										continue;
									}
									?>
									<span class="suivi-barre__part suivi-barre__part--<?php echo esc_attr( $cle ); ?>" style="--part: <?php echo esc_attr( round( $p[1], 3 ) ); ?>%" data-info="<?php echo esc_attr( $p[0] . ' : ' . ueb_fcfa( $a[ $cle ] ) . ' (' . ueb_pourcent( $p[1] ) . ')' ); ?>"></span>
								<?php endforeach; ?>
							</span>
							<span class="suivi-niveau__valeur"><b><?php echo esc_html( ueb_pourcent( $parts['encaisse'][1] ) ); ?></b><small><?php echo esc_html( ueb_fcfa( $a['encaisse'] ) ); ?></small></span>
						</li>
					<?php endforeach; ?>
				</ul>
				<div class="suivi-niveaux__axe" aria-hidden="true">
					<span style="--x: 0%">0</span><span style="--x: 50%">50 %</span><span style="--x: 100%">100 %</span>
				</div>
			</div>
		<?php endif; ?>
	</section>
	<?php
}

/**
 * Frais médicaux : suivis à part des droits (montant fixe selon la situation
 * de l'étudiant, versé au compte des services centraux).
 */
function ueb_suivi_medicaux( array $m ) {
	$taux = $m['attendu'] > 0 ? 100 * $m['encaisse'] / $m['attendu'] : 0;
	?>
	<section class="suivi-panneau suivi-medicaux" aria-labelledby="suivi-medicaux-titre">
		<header class="suivi-panneau__entete">
			<div>
				<h2 id="suivi-medicaux-titre">Frais médicaux</h2>
				<p>Visite médicale, suivie à part des droits universitaires.</p>
			</div>
		</header>

		<?php if ( $m['attendu'] <= 0 ) : ?>
			<div class="bo-vide"><span><?php echo ueb_icone( 'bouclier', 22 ); ?></span><p>Aucun quitus de frais médicaux cette année pour l’instant.</p></div>
		<?php else : ?>
			<p class="suivi-medicaux__montant">
				<b><?php echo esc_html( ueb_formater_montant( $m['encaisse'] ) ); ?> <small>FCFA</small></b>
				<span>encaissés sur <?php echo esc_html( ueb_fcfa( $m['attendu'] ) ); ?> attendus</span>
			</p>
			<div class="suivi-medicaux__progression">
				<span class="suivi-medicaux__barre" role="img" aria-label="<?php echo esc_attr( 'Frais médicaux encaissés : ' . ueb_pourcent( $taux ) ); ?>"><span style="--part: <?php echo esc_attr( round( $taux, 3 ) ); ?>%"></span></span>
				<b><?php echo esc_html( ueb_pourcent( $taux ) ); ?></b>
			</div>
			<dl class="suivi-medicaux__chiffres">
				<div><dt>Quitus médicaux</dt><dd><?php echo (int) $m['etudiants']; ?></dd></div>
				<div><dt>En vérification</dt><dd><?php echo esc_html( ueb_formater_montant( $m['verification'] ) ); ?> <small>FCFA</small></dd></div>
			</dl>
		<?php endif; ?>
		<p class="suivi-medicaux__compte"><?php echo ueb_icone( 'banque', 18 ); ?><span>Versés au compte des services centraux : ils ne comptent pas dans le taux de recouvrement des droits.</span></p>
	</section>
	<?php
}

/** Méthode de calcul, repliée par défaut. */
function ueb_suivi_methode() {
	$regles = array(
		'Étudiant compté'      => 'Il a au moins un quitus de droits universitaires cette année. Sa filière et son niveau sont ceux de son quitus le plus récent ; inscrit dans deux établissements, il compte dans chacun.',
		'Attendu'              => ueb_fcfa( UEB_DROITS_CLASSIQUES ) . ' par étudiant en formation classique. En formation professionnelle, le total des quitus préparés, car le tarif est fixé par l’établissement.',
		'Encaissé'             => 'Seuls les quitus <b>vérifiés</b> par la scolarité après contrôle des originaux, plafonnés à l’attendu de l’étudiant.',
		'Taux de recouvrement' => 'Encaissé ÷ attendu. Les quatre parts de la barre (encaissé, en vérification, déclaré, pas encore déclaré) font toujours 100 % de l’attendu.',
		'Soldé ou partiel'     => 'Soldé : tout l’attendu est encaissé. Partiel : une partie seulement est encaissée.',
		'Trop-perçu'           => 'Montant vérifié au-delà de l’attendu d’un étudiant. Il est signalé pour contrôle et n’entre pas dans le taux.',
		'Frais médicaux'       => 'Suivis à part : montant fixe selon la situation de l’étudiant, versé au compte des services centraux.',
	);
	?>
	<details class="suivi-panneau suivi-methode">
		<summary>
			<span class="suivi-methode__icone" aria-hidden="true"><?php echo ueb_icone( 'info', 18 ); ?></span>
			<span class="suivi-methode__titre">Comment ces chiffres sont calculés</span>
			<span class="suivi-methode__chevron" aria-hidden="true"><?php echo ueb_icone( 'chevron', 18 ); ?></span>
		</summary>
		<dl class="suivi-methode__regles">
			<?php foreach ( $regles as $terme => $definition ) : ?>
				<div>
					<dt><?php echo esc_html( $terme ); ?></dt>
					<dd><?php echo wp_kses( $definition, array( 'b' => array() ) ); ?></dd>
				</div>
			<?php endforeach; ?>
		</dl>
	</details>
	<?php
}

/** Deux initiales en capitales pour un avatar : « Neo TCHAMBA » → « NT ». */
function ueb_initiales( $prenom, $nom = '' ) {
	$mots = preg_split( '/[\s.\-_]+/u', trim( $prenom . ' ' . $nom ), -1, PREG_SPLIT_NO_EMPTY );
	if ( ! $mots ) {
		return '?';
	}
	$premiere = mb_substr( $mots[0], 0, 1 );
	$derniere = count( $mots ) > 1 ? mb_substr( end( $mots ), 0, 1 ) : mb_substr( $mots[0], 1, 1 );
	return mb_strtoupper( $premiere . $derniere );
}

/** Bord en nuage au bas des bandeaux verts (espace, listes, envoi des reçus). */
function ueb_nuages() {
	?>
		<svg class="espace__nuages" viewBox="0 0 1200 40" preserveAspectRatio="none" aria-hidden="true" focusable="false">
		<path class="espace__nuages-halo" transform="translate(0 -8)" d="M0 40V30A70 70 0 0 1 90 28A80 80 0 0 1 200 31A65 65 0 0 1 290 27A90 90 0 0 1 410 30A70 70 0 0 1 505 28A85 85 0 0 1 620 31A65 65 0 0 1 710 27A80 80 0 0 1 820 30A70 70 0 0 1 915 28A90 90 0 0 1 1035 31A70 70 0 0 1 1130 28A60 60 0 0 1 1200 30V48H0Z"/>
		<path d="M0 40V30A70 70 0 0 1 90 28A80 80 0 0 1 200 31A65 65 0 0 1 290 27A90 90 0 0 1 410 30A70 70 0 0 1 505 28A85 85 0 0 1 620 31A65 65 0 0 1 710 27A80 80 0 0 1 820 30A70 70 0 0 1 915 28A90 90 0 0 1 1035 31A70 70 0 0 1 1130 28A60 60 0 0 1 1200 30V40Z"/>
		</svg>
	<?php
}

function ueb_page_fin( $variante = 'simple' ) {
	if ( ! in_array( $variante, array( 'auth', 'gestion', 'bo', 'espace' ), true ) ) {
		ueb_pied_site();
	}
	ueb_fenetre_confirmation();
	wp_footer();
	echo "</body>\n</html>\n";
}

/* Titre de l'onglet pour les pages virtuelles. */
add_filter( 'pre_get_document_title', function ( $titre ) {
	if ( ! empty( $GLOBALS['ueb_titre_page'] ) ) {
		return $GLOBALS['ueb_titre_page'] . ' — Inscriptions UEb';
	}
	return is_front_page() ? 'Inscriptions ' . ueb_annee_academique()['libelle'] . ' — ' . UEB_UNIVERSITE['fr'] : $titre;
} );

function ueb_marque() {
	$annee = ueb_annee_academique();
	?>
	<a class="marque" href="<?php echo esc_url( home_url( '/' ) ); ?>">
		<img src="<?php echo esc_url( ueb_logo_url( 'UEB' ) ); ?>" alt="" width="44" height="44">
			<span><b><?php echo esc_html( UEB_UNIVERSITE['fr'] ); ?></b></span>
	</a>
	<?php
}

function ueb_entete_site( $variante ) {
	$compte = ueb_compte_courant();
	$page   = get_query_var( 'ueb_page' );
	?>
	<header class="site-entete site-entete--<?php echo esc_attr( $variante ); ?>" data-entete>
		<?php if ( $compte && 'espace' === $variante ) { ueb_bandeau_confidentialite(); } ?>
		<div class="conteneur site-entete__rangee">
			<?php ueb_marque(); ?>

			<?php /* Sur l'écran de connexion, personne n'est connecté : ni pastille de compte vide, ni bouton « Déconnexion » — la navigation publique suffit. */ ?>
			<?php if ( 'gestion' === $variante && is_user_logged_in() ) : ?>
				<nav class="site-nav" aria-label="Administration">
					<?php if ( ! ueb_est_admin_ueb() ) : ?><a href="<?php echo esc_url( ueb_url_scolarite() ); ?>" <?php echo is_page_template( 'page-scolarite.php' ) ? 'aria-current="page"' : ''; ?>>Espace scolarité</a><?php endif; ?>
					<?php if ( ueb_est_admin_ueb() ) : ?>
						<a href="<?php echo esc_url( ueb_url_administration() ); ?>" <?php echo is_page_template( 'page-administration.php' ) ? 'aria-current="page"' : ''; ?>>Administration</a>
					<?php endif; ?>
				</nav>
				<div class="site-actions">
					<span class="puce-compte"><?php echo ueb_icone( 'bouclier', 16 ); ?><?php echo esc_html( wp_get_current_user()->display_name ); ?></span>
					<a class="btn btn--fantome btn--petit" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">Déconnexion</a>
				</div>
			<?php elseif ( 'gestion' === $variante ) : ?>
				<?php /* Écran de connexion du personnel : la marque suffit. La navigation
				   étudiante (« Se connecter », « Créer mon compte ») y enverrait un agent
				   de scolarité vers l'espace des étudiants. */ ?>
			<?php elseif ( $compte ) : ?>
				<nav class="site-nav" id="site-nav" aria-label="Mon espace">
					<a href="<?php echo esc_url( ueb_url( 'mon-espace' ) ); ?>" <?php echo in_array( $page, array( 'espace', 'recus' ), true ) ? 'aria-current="page"' : ''; ?>>Mon espace</a>
					<a href="<?php echo esc_url( ueb_url( 'mon-espace/quitus' ) ); ?>" <?php echo 'quitus' === $page ? 'aria-current="page"' : ''; ?>>Nouveau quitus</a>
					<a href="<?php echo esc_url( ueb_url( 'mon-espace/securite' ) ); ?>" <?php echo 'securite' === $page ? 'aria-current="page"' : ''; ?>>Sécurité</a>
					<a class="seul-mobile" href="<?php echo esc_url( ueb_url( 'deconnexion' ) ); ?>">Déconnexion</a>
				</nav>
				<div class="site-actions">
					<span class="puce-compte" title="Ton identifiant de connexion"><?php echo ueb_icone( 'utilisateur', 16 ); ?><?php echo esc_html( ueb_identifiant_compte( $compte ) ); ?></span>
					<a class="btn btn--fantome btn--petit" href="<?php echo esc_url( ueb_url( 'deconnexion' ) ); ?>"><?php echo ueb_icone( 'sortie', 16 ); ?><span>Déconnexion</span></a>
				</div>
				<button class="menu-mobile" type="button" aria-expanded="false" aria-controls="site-nav" data-menu-mobile><?php echo ueb_icone( 'menu', 22 ); ?><span class="sr">Menu</span></button>
			<?php else : ?>
				<nav class="site-nav" id="site-nav" aria-label="Navigation principale">
					<a href="<?php echo esc_url( home_url( '/#parcours' ) ); ?>">Comment ça marche</a>
					<a href="<?php echo esc_url( home_url( '/#etablissements' ) ); ?>">Établissements</a>
					<a href="<?php echo esc_url( home_url( '/#questions' ) ); ?>">Questions</a>
					<a class="seul-mobile" href="<?php echo esc_url( ueb_url( 'connexion' ) ); ?>">Se connecter</a>
					<a class="seul-mobile" href="<?php echo esc_url( ueb_url( 'creer-mon-compte' ) ); ?>">Créer mon compte</a>
				</nav>
				<div class="site-actions">
					<a class="btn btn--fantome btn--petit" href="<?php echo esc_url( ueb_url( 'connexion' ) ); ?>">Se connecter</a>
					<a class="btn btn--primaire btn--petit" href="<?php echo esc_url( ueb_url( 'creer-mon-compte' ) ); ?>">Créer mon compte</a>
				</div>
				<button class="menu-mobile" type="button" aria-expanded="false" aria-controls="site-nav" data-menu-mobile><?php echo ueb_icone( 'menu', 22 ); ?><span class="sr">Menu</span></button>
			<?php endif; ?>
		</div>
	</header>
	<?php
}

/** Rappel privé : une seule lecture accessible, défilement visuel contrôlable. */
function ueb_bandeau_confidentialite() {
	$message = 'Ton espace étudiant est privé. Ne partage ton mot de passe avec personne. En cas de problème, rapproche-toi de la cellule informatique de ton établissement pour le faire réinitialiser.';
	?>
	<div class="confidentialite" data-confidentialite>
		<div class="conteneur confidentialite__ligne">
			<?php echo ueb_icone( 'cadenas', 16 ); ?>
			<p class="sr"><?php echo esc_html( $message ); ?></p>
			<div class="confidentialite__fenetre" aria-hidden="true"><div class="confidentialite__ruban"><span><?php echo esc_html( $message ); ?></span><span class="confidentialite__copie"><?php echo esc_html( $message ); ?></span></div></div>
		</div>
	</div>
	<?php
}

function ueb_message_bienvenue() {
	?>
	<dialog class="bienvenue" data-bienvenue aria-labelledby="bienvenue-titre" aria-describedby="bienvenue-texte">
		<div class="bienvenue__entete"><?php echo ueb_icone( 'bouclier', 28 ); ?><span>Ton compte est créé</span></div>
		<h2 id="bienvenue-titre">Bienvenue dans ton espace privé</h2>
		<p id="bienvenue-texte"><strong>Ne partage ton mot de passe avec personne.</strong> Il protège tes informations personnelles, tes documents et le suivi de ton inscription.</p>
		<p>En cas d’oubli ou de problème, rapproche-toi de la <strong>cellule informatique de ton établissement</strong> pour le faire réinitialiser.</p>
		<p class="bienvenue__conseil">Sur un appareil partagé, pense à te déconnecter après chaque visite.</p>
		<form method="dialog"><button class="btn btn--primaire" autofocus>J’ai compris, accéder à mon espace<?php echo ueb_icone( 'fleche', 18 ); ?></button></form>
	</dialog>
	<noscript><aside class="alerte alerte--info"><p>Bienvenue ! Ton espace est privé : ne partage ton mot de passe avec personne. En cas de problème, contacte la cellule informatique de ton établissement.</p></aside></noscript>
	<?php
}

/** Réseaux sociaux : même table que la préinscription (ueb_reseaux_sociaux). */
function ueb_reseaux_sociaux() {
	global $wpdb;
	$lignes = $wpdb->get_results( 'SELECT plateforme, url FROM ueb_reseaux_sociaux WHERE actif = 1 ORDER BY ordre ASC', ARRAY_A );
	$reseaux = array();
	foreach ( (array) $lignes as $l ) {
		$reseaux[] = array( 'url' => $l['url'], 'libelle' => ucfirst( str_replace( '_', ' ', $l['plateforme'] ) ) );
	}
	if ( ! $reseaux ) {
		$reseaux = array(
			array( 'url' => 'https://www.youtube.com/@universiteebolowa', 'libelle' => 'YouTube' ),
			array( 'url' => 'https://facebook.com/share/18i1CQ2QuY', 'libelle' => 'Facebook' ),
			array( 'url' => 'https://unv-ebolowa.cm', 'libelle' => 'Site web' ),
		);
	}
	return $reseaux;
}

/** Pied de page : repris du site de préinscription (mêmes colonnes, mêmes informations). */
function ueb_pied_site() {
	$annee = ueb_annee_academique();
	?>
	<footer class="site-footer"><div class="conteneur">
		<div class="fgrid">
			<div>
				<div class="fbrand">
					<img src="<?php echo esc_url( ueb_logo_url( 'UEB' ) ); ?>" alt="Logo de l'Université d'Ébolowa" width="247" height="236" loading="lazy">
					<b>Université d'Ébolowa</b>
				</div>
				<p class="fdesc">Plateforme officielle d'inscription en ligne — <?php echo esc_html( $annee['code'] ); ?>.</p>
			</div>
			<div>
				<h4>Liens</h4>
				<ul>
					<li><a href="<?php echo esc_url( home_url( '/#parcours' ) ); ?>">Comment ça marche</a></li>
					<li><a href="<?php echo esc_url( home_url( '/#etablissements' ) ); ?>">Établissements</a></li>
					<li><a href="<?php echo esc_url( home_url( '/#campus' ) ); ?>">Campus</a></li>
					<li><a href="<?php echo esc_url( home_url( '/#questions' ) ); ?>">Questions</a></li>
				</ul>
			</div>
			<div>
				<h4>Contact</h4>
				<ul>
					<li>Ébolowa, Région du Sud</li>
					<li><a href="mailto:info@unv-ebolowa.cm">info@unv-ebolowa.cm</a></li>
					<li>+237 6 76 29 54 88</li>
				</ul>
			</div>
			<div>
				<h4>Suivez-nous</h4>
				<ul>
					<?php foreach ( ueb_reseaux_sociaux() as $r ) : ?>
						<li><a href="<?php echo esc_url( $r['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $r['libelle'] ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>
	</div></footer>
	<?php
}

/* ---------- Graphiques ----------
   Dessinés en SVG côté serveur : pas de bibliothèque, pas de JavaScript, et
   un tableau de valeurs lisible sous chaque figure pour les lecteurs d'écran. */

/** Entête commune d'une figure : titre, sous-titre et total. */
function ueb_graphe_entete( $titre, $sous_titre, $total ) {
	?>
	<figcaption class="graphe__entete">
		<span class="graphe__titre"><?php echo esc_html( $titre ); ?></span>
		<?php if ( $sous_titre ) : ?>
			<span class="graphe__sous-titre"><?php echo esc_html( $sous_titre ); ?></span>
		<?php endif; ?>
		<?php if ( null !== $total ) : ?>
			<span class="graphe__total"><?php echo esc_html( $total ); ?></span>
		<?php endif; ?>
	</figcaption>
	<?php
}

/**
 * Répartition en anneau (part d'un tout), dessiné en SVG côté serveur : aucune
 * bibliothèque, aucun script.
 *
 * Comparer deux angles reste moins précis que comparer deux longueurs : le
 * total est donc écrit au centre, et la légende porte les valeurs et les
 * pourcentages — la lecture ne dépend jamais des seuls secteurs.
 *
 * Les arcs sont raccourcis de 2 unités pour laisser voir le fond entre eux,
 * et l'anneau démarre à midi (rotation de -90°, posée en CSS).
 *
 * @param string $titre      Titre de la figure.
 * @param string $sous_titre Précision facultative.
 * @param array  $parts      libellé => array( 'valeur' => int, 'couleur' => string )
 */
function ueb_graphe_anneau( $titre, $sous_titre, array $parts ) {
	$total = array_sum( array_column( $parts, 'valeur' ) );
	$rayon = 54;
	$tour  = 2 * M_PI * $rayon;
	?>
	<figure class="graphe">
		<?php ueb_graphe_entete( $titre, $sous_titre, null ); ?>
		<?php if ( ! $total ) : ?>
			<p class="graphe__vide"><?php echo ueb_icone( 'info', 18 ); ?>Aucune donnée pour l’instant.</p>
		<?php else : ?>
			<div class="graphe__anneau">
				<svg viewBox="0 0 160 160" role="img" aria-label="<?php echo esc_attr( $titre ); ?>">
					<circle class="graphe__anneau-piste" cx="80" cy="80" r="<?php echo (int) $rayon; ?>"></circle>
					<?php
					$parcouru = 0;
					foreach ( $parts as $libelle => $p ) :
						if ( $p['valeur'] <= 0 ) {
							continue;
						}
						$arc  = $tour * $p['valeur'] / $total;
						$plein = max( 1, $arc - 2 );
						?>
						<circle cx="80" cy="80" r="<?php echo (int) $rayon; ?>"
							stroke="<?php echo esc_attr( $p['couleur'] ); ?>"
							stroke-dasharray="<?php echo esc_attr( round( $plein, 2 ) . ' ' . round( $tour - $plein, 2 ) ); ?>"
							stroke-dashoffset="<?php echo esc_attr( round( -$parcouru, 2 ) ); ?>">
							<title><?php echo esc_html( $libelle . ' : ' . $p['valeur'] ); ?></title>
						</circle>
						<?php
						$parcouru += $arc;
					endforeach;
					?>
				</svg>
				<p class="graphe__anneau-centre"><b><?php echo (int) $total; ?></b><span>au total</span></p>
			</div>
			<ul class="graphe__legende">
				<?php foreach ( $parts as $libelle => $p ) : ?>
					<li>
						<i style="background: <?php echo esc_attr( $p['couleur'] ); ?>" aria-hidden="true"></i>
						<span><?php echo esc_html( $libelle ); ?></span>
						<b><?php echo (int) $p['valeur']; ?></b>
						<small><?php echo esc_html( $total ? round( 100 * $p['valeur'] / $total ) . ' %' : '' ); ?></small>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</figure>
	<?php
}

/**
 * Barres horizontales : comparaison de grandeurs, libellé à gauche et valeur
 * au bout de la barre. Barres fines, extrémité arrondie, piste discrète.
 *
 * @param string $titre      Titre de la figure.
 * @param string $sous_titre Précision facultative.
 * @param array  $parts      libellé => array( 'valeur' => int, 'couleur' => string, 'icone' => string )
 */
function ueb_graphe_barres( $titre, $sous_titre, array $parts ) {
	$valeurs = array_column( $parts, 'valeur' );
	$maximum = max( 1, (int) ( $valeurs ? max( $valeurs ) : 0 ) );
	$total   = array_sum( $valeurs );
	?>
	<figure class="graphe">
		<?php ueb_graphe_entete( $titre, $sous_titre, null ); ?>
		<?php if ( ! $total ) : ?>
			<p class="graphe__vide"><?php echo ueb_icone( 'info', 18 ); ?>Aucune donnée pour l’instant.</p>
		<?php else : ?>
			<ul class="graphe__barres">
				<?php foreach ( $parts as $libelle => $p ) : ?>
					<li>
						<span class="graphe__barre-libelle">
							<?php echo ! empty( $p['icone'] ) ? ueb_icone( $p['icone'], 15 ) : ''; // phpcs:ignore -- SVG interne ?>
							<?php echo esc_html( $libelle ); ?>
						</span>
						<span class="graphe__barre" aria-hidden="true">
							<span style="width: <?php echo esc_attr( max( 2, round( 100 * $p['valeur'] / $maximum, 1 ) ) ); ?>%; background: <?php echo esc_attr( $p['couleur'] ); ?>"></span>
						</span>
						<b><?php echo (int) $p['valeur']; ?></b>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</figure>
	<?php
}

/**
 * Le chiffre que le tableau de bord met en avant : un seul par écran.
 * Police de l'interface et chiffres proportionnels (des chiffres tabulaires
 * espacent trop un grand nombre).
 */
function ueb_carte_hero( $valeur, $libelle, $note = '' ) {
	?>
	<div class="bo-hero">
		<span class="bo-hero__libelle"><?php echo esc_html( $libelle ); ?></span>
		<b class="bo-hero__valeur"><?php echo esc_html( $valeur ); ?></b>
		<?php if ( $note ) : ?>
			<span class="bo-hero__note"><?php echo esc_html( $note ); ?></span>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Tuile de chiffre : libellé, valeur, et une note ou une jauge facultative.
 *
 * @param array $options note (string), part (float 0-100), variante (string)
 */
function ueb_carte_chiffre( $valeur, $libelle, $icone = '', $variante = '', array $options = array() ) {
	?>
	<div class="bo-chiffre <?php echo esc_attr( $variante ? 'bo-chiffre--' . $variante : '' ); ?>">
		<span class="bo-chiffre__haut">
			<?php echo $icone ? ueb_icone( $icone, 18 ) : ''; // phpcs:ignore -- SVG interne ?>
			<span><?php echo esc_html( $libelle ); ?></span>
		</span>
		<b><?php echo esc_html( $valeur ); ?></b>
		<?php if ( isset( $options['part'] ) ) : ?>
			<span class="bo-chiffre__jauge" aria-hidden="true"><span style="width: <?php echo esc_attr( max( 0, min( 100, round( $options['part'] ) ) ) ); ?>%"></span></span>
		<?php endif; ?>
		<?php if ( ! empty( $options['note'] ) ) : ?>
			<span class="bo-chiffre__note"><?php echo esc_html( $options['note'] ); ?></span>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Barre latérale de la coque du back-office : marque, navigation, périmètre
 * consulté et compte connecté.
 *
 * Les entrées sont de vrais liens, et non des onglets JavaScript : chaque vue
 * garde son adresse, le bouton Retour fonctionne, et la page reste utilisable
 * si le script ne se charge pas.
 *
 * @param string $espace Nom de l'espace, affiché au-dessus de la navigation.
 * @param array  $liens  array( array( 'url', 'libelle', 'icone', 'actif' ) )
 * @param array  $pied   array( 'titre' => string, 'note' => string )
 */
function ueb_bo_barre( $espace, array $liens, array $pied = array() ) {
	$utilisateur = wp_get_current_user();
	$nom         = $utilisateur->display_name ? $utilisateur->display_name : $utilisateur->user_login;
	$role        = ueb_nom_role_du_compte(); // nom saisi par la Direction, jamais écrit dans le code
	$liens       = array_values( array_filter( $liens ) ); // entrées retirées faute de permission
	$role_classe = sanitize_html_class( strtolower( str_replace( ' ', '-', remove_accents( $role ) ) ) );
	?>
	<aside class="bo-sidebar bo-sidebar--<?php echo esc_attr( $role_classe ); ?>">
		<a class="bo-marque" href="<?php echo esc_url( home_url( '/' ) ); ?>">
			<img src="<?php echo esc_url( ueb_logo_url( 'UEB' ) ); ?>" alt="" width="38" height="38">
			<span class="bo-marque__texte">
				<span class="bo-marque__nom">Inscriptions</span>
				<span class="bo-marque__note"><?php echo esc_html( UEB_UNIVERSITE['fr'] ); ?></span>
			</span>
		</a>

		<p class="bo-sidebar__titre"><?php echo esc_html( $espace ); ?></p>
		<nav class="bo-sidebar__nav" aria-label="<?php echo esc_attr( $espace ); ?>">
			<?php foreach ( $liens as $lien ) : ?>
				<a href="<?php echo esc_url( $lien['url'] ); ?>" <?php echo empty( $lien['actif'] ) ? '' : 'aria-current="page"'; ?>><?php echo ueb_icone( $lien['icone'], 18 ); ?><?php echo esc_html( $lien['libelle'] ); ?></a>
			<?php endforeach; ?>
		</nav>

		<div class="bo-sidebar__pied">
			<?php
			/* Portée de plusieurs établissements (ou tous) : sélecteur, revalidé côté serveur. */
			$autorises = ueb_est_admin_ueb() ? array() : ueb_etabs_autorises();
			if ( count( $autorises ) > 1 ) :
				$courant = ueb_etab_agent();
				?>
				<form class="bo-perimetre bo-perimetre--choix" method="get" action="">
					<label for="bo-etab"><b>Établissement consulté</b></label>
					<div class="champ__select">
						<select id="bo-etab" name="ueb_etab" onchange="this.form.submit()">
							<?php if ( ueb_portee_totale() ) : ?><option value="" <?php selected( $courant, '' ); ?>>Tous les établissements</option><?php endif; ?>
							<?php foreach ( $autorises as $sigle ) : ?>
								<option value="<?php echo esc_attr( $sigle ); ?>" <?php selected( $courant, $sigle ); ?>><?php echo esc_html( $sigle . ' — ' . ueb_etablissement( $sigle )['fr'] ); ?></option>
							<?php endforeach; ?>
						</select><?php echo ueb_icone( 'chevron', 16 ); ?>
					</div>
					<noscript><button class="btn btn--petit btn--clair" type="submit">Afficher</button></noscript>
				</form>
			<?php elseif ( ! empty( $pied['titre'] ) ) : ?>
				<p class="bo-perimetre"><b><?php echo esc_html( $pied['titre'] ); ?></b><?php echo esc_html( $pied['note'] ?? '' ); ?></p>
			<?php endif; ?>
			<div class="bo-compte">
				<span class="bo-compte__avatar" aria-hidden="true"><?php echo esc_html( mb_substr( $nom, 0, 1 ) ); ?></span>
				<span class="bo-compte__meta">
					<span class="bo-compte__nom"><?php echo esc_html( $nom ); ?></span>
					<span class="bo-compte__role"><?php echo esc_html( $role ); ?></span>
				</span>
			</div>
			<a class="bo-sortie" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"><?php echo ueb_icone( 'sortie', 16 ); ?>Déconnexion</a>
		</div>
	</aside>
	<?php
}

/**
 * Cartes et graphiques d'un palier : toute l'université, un établissement, ou
 * l'établissement d'un agent de scolarité. Partagée par les deux espaces.
 *
 * Un seul chiffre mis en avant par écran, puis les tuiles, puis les figures.
 * Couleurs : identité (vert / bleu) pour le sexe, rampe d'un seul vert pour
 * les tranches, jeu de statuts réservé pour l'avancement — chaque statut
 * portant une icône et un libellé, jamais la couleur seule.
 */
function ueb_bo_palier( array $c, $perimetre = '' ) {
	$part = static fn( $valeur, $sur ) => $sur > 0 ? 100 * $valeur / $sur : 0;
	?>
	<div class="bo-tete">
		<?php
		ueb_carte_hero(
			$c['etudiants'],
			'Étudiants inscrits',
			$perimetre ? $perimetre . ' · ' . $c['quitus'] . ' quitus générés' : $c['quitus'] . ' quitus générés'
		);
		?>
		<div class="bo-chiffres bo-anim">
			<?php
			ueb_carte_chiffre( $c['recus_envoyes'], 'Reçus à vérifier', 'horloge', 'attente', array(
				'part' => $part( $c['recus_envoyes'], $c['quitus'] ),
				'note' => $c['quitus'] ? round( $part( $c['recus_envoyes'], $c['quitus'] ) ) . ' % des quitus' : 'Aucun quitus',
			) );
			ueb_carte_chiffre( $c['recus_verifies'], 'Reçus vérifiés', 'check', 'verifie', array(
				'part' => $part( $c['recus_verifies'], $c['quitus'] ),
				'note' => $c['quitus'] ? round( $part( $c['recus_verifies'], $c['quitus'] ) ) . ' % des quitus' : 'Aucun quitus',
			) );
			ueb_carte_chiffre( $c['recus_rejetes'], 'Reçus rejetés', 'alerte', 'rejete', array(
				'part' => $part( $c['recus_rejetes'], $c['quitus'] ),
				'note' => 'À corriger par l’étudiant',
			) );
			ueb_carte_chiffre( $c['a_payer'], 'Quitus à payer', 'banque', '', array(
				'part' => $part( $c['a_payer'], $c['quitus'] ),
				'note' => 'En attente de paiement',
			) );
			?>
		</div>
	</div>

	<div class="bo-chiffres">
		<?php
		ueb_carte_chiffre( $c['tranches']['tranche1'], 'Ont payé la tranche 1', 'banque', '', array(
			'part' => $part( $c['tranches']['tranche1'], $c['etudiants'] ),
			'note' => $c['etudiants'] ? 'sur ' . $c['etudiants'] . ' étudiants' : '',
		) );
		ueb_carte_chiffre( $c['tranches']['tranche2'], 'Ont payé la tranche 2', 'banque', '', array(
			'part' => $part( $c['tranches']['tranche2'], $c['etudiants'] ),
			'note' => $c['etudiants'] ? 'sur ' . $c['etudiants'] . ' étudiants' : '',
		) );
		ueb_carte_chiffre( $c['tranches']['totalite'], 'Ont payé la totalité', 'bouclier', '', array(
			'part' => $part( $c['tranches']['totalite'], $c['etudiants'] ),
			'note' => $c['etudiants'] ? 'sur ' . $c['etudiants'] . ' étudiants' : '',
		) );
		ueb_carte_chiffre( ueb_formater_montant( $c['montant_verifie'] ), 'Encaissé et vérifié', 'tampon', '', array(
			'note' => 'FCFA confirmés par la scolarité',
		) );
		?>
	</div>

	<div class="bo-graphes bo-anim">
		<?php
		ueb_graphe_anneau( 'Répartition par sexe', 'Étudiants ayant au moins un quitus', array(
			'Masculin' => array( 'valeur' => $c['sexe']['M'], 'couleur' => 'var(--viz-id-1)' ),
			'Féminin'  => array( 'valeur' => $c['sexe']['F'], 'couleur' => 'var(--viz-id-2)' ),
		) );
		ueb_graphe_barres( 'Avancement des quitus', 'Où en sont les ' . $c['quitus'] . ' quitus de l’année', array(
			'À payer'     => array( 'valeur' => $c['a_payer'], 'couleur' => 'var(--viz-attente)', 'icone' => 'horloge' ),
			'Reçu envoyé' => array( 'valeur' => $c['recus_envoyes'], 'couleur' => 'var(--viz-id-2)', 'icone' => 'envoyer' ),
			'Vérifié'     => array( 'valeur' => $c['recus_verifies'], 'couleur' => 'var(--viz-bien)', 'icone' => 'check' ),
			'À corriger'  => array( 'valeur' => $c['recus_rejetes'], 'couleur' => 'var(--viz-critique)', 'icone' => 'alerte' ),
		) );
		ueb_graphe_barres( 'Étudiants par tranche réglée', 'Paiements vérifiés par la scolarité', array(
			'Tranche 1' => array( 'valeur' => $c['tranches']['tranche1'], 'couleur' => 'var(--viz-pas-1)' ),
			'Tranche 2' => array( 'valeur' => $c['tranches']['tranche2'], 'couleur' => 'var(--viz-pas-2)' ),
			'Totalité'  => array( 'valeur' => $c['tranches']['totalite'], 'couleur' => 'var(--viz-pas-3)' ),
		) );
		?>
	</div>
	<?php
}

/* ---------- Messages ---------- */

function ueb_afficher_flash() {
	$icones = array( 'succes' => 'check', 'erreur' => 'alerte', 'alerte' => 'alerte', 'info' => 'info' );
	foreach ( ueb_lire_flash() as $m ) {
		printf(
			'<div class="alerte alerte--%1$s" role="%2$s">%3$s<p>%4$s</p></div>',
			esc_attr( $m['type'] ),
			'erreur' === $m['type'] ? 'alert' : 'status',
			ueb_icone( $icones[ $m['type'] ] ?? 'info', 20 ),
			esc_html( $m['message'] )
		);
	}
}

function ueb_alerte( $type, $message ) {
	printf( '<div class="alerte alerte--%1$s" role="alert">%2$s<p>%3$s</p></div>', esc_attr( $type ), ueb_icone( 'erreur' === $type ? 'alerte' : 'info', 20 ), esc_html( $message ) );
}

function ueb_badge_statut( $statut ) {
	$libelle = UEB_STATUTS_QUITUS[ $statut ]['libelle'] ?? $statut;
	return sprintf( '<span class="badge badge--%s"><i aria-hidden="true"></i>%s</span>', esc_attr( $statut ), esc_html( $libelle ) );
}

/* ---------- Champs de formulaire ---------- */

/**
 * Champ avec libellé visible, aide et erreur reliées par aria-describedby.
 *
 * @param array $a nom, libelle, type, valeur, erreur, aide, attrs (tableau), options (select), requis,
 *                 icone (nom d'une icône ueb_icone() affichée dans le champ)
 */
function ueb_champ( array $a ) {
	$a      = wp_parse_args( $a, array( 'type' => 'text', 'valeur' => '', 'erreur' => '', 'aide' => '', 'attrs' => array(), 'options' => array(), 'requis' => true, 'classe' => '', 'icone' => '' ) );
	$icone  = $a['icone'] ? '<span class="champ__icone">' . ueb_icone( $a['icone'], 18 ) . '</span>' : '';
	$id     = 'champ-' . $a['nom'];
	$decrit = array();
	if ( $a['aide'] ) {
		$decrit[] = $id . '-aide';
	}
	if ( $a['erreur'] ) {
		$decrit[] = $id . '-erreur';
	}
	$attrs = '';
	foreach ( $a['attrs'] as $cle => $val ) {
		$attrs .= true === $val ? ' ' . esc_attr( $cle ) : sprintf( ' %s="%s"', esc_attr( $cle ), esc_attr( $val ) );
	}
	if ( $a['requis'] ) {
		$attrs .= ' required';
	}
	if ( $decrit ) {
		$attrs .= ' aria-describedby="' . esc_attr( implode( ' ', $decrit ) ) . '"';
	}
	if ( $a['erreur'] ) {
		$attrs .= ' aria-invalid="true"';
	}
	?>
	<div class="champ <?php echo esc_attr( $a['classe'] ); ?><?php echo $a['erreur'] ? ' champ--invalide' : ''; ?>">
		<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $a['libelle'] ); ?><?php echo $a['requis'] ? '' : ' <span class="facultatif">(facultatif)</span>'; ?></label>
		<?php if ( 'select' === $a['type'] ) : ?>
			<div class="champ__select<?php echo $icone ? ' champ__boite' : ''; ?>">
				<?php echo $icone; // phpcs:ignore -- SVG interne ?>
				<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $a['nom'] ); ?>"<?php echo $attrs; // phpcs:ignore -- échappé ci-dessus ?>>
					<option value="">Choisir…</option>
					<?php foreach ( $a['options'] as $val => $lib ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>" <?php selected( (string) $a['valeur'], (string) $val ); ?>><?php echo esc_html( $lib ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php echo ueb_icone( 'chevron', 18 ); ?>
			</div>
		<?php elseif ( 'password' === $a['type'] ) : ?>
			<div class="champ__mdp<?php echo $icone ? ' champ__boite' : ''; ?>">
				<?php echo $icone; // phpcs:ignore -- SVG interne ?>
				<input id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $a['nom'] ); ?>" type="password"<?php echo $attrs; // phpcs:ignore ?>>
				<button type="button" class="champ__voir" data-voir-mdp aria-label="Afficher le mot de passe" aria-pressed="false"><?php echo ueb_icone( 'oeil', 20 ); ?></button>
			</div>
		<?php elseif ( $icone ) : ?>
			<div class="champ__boite">
				<?php echo $icone; // phpcs:ignore -- SVG interne ?>
				<input id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $a['nom'] ); ?>" type="<?php echo esc_attr( $a['type'] ); ?>" value="<?php echo esc_attr( $a['valeur'] ); ?>"<?php echo $attrs; // phpcs:ignore ?>>
			</div>
		<?php else : ?>
			<input id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $a['nom'] ); ?>" type="<?php echo esc_attr( $a['type'] ); ?>" value="<?php echo esc_attr( $a['valeur'] ); ?>"<?php echo $attrs; // phpcs:ignore ?>>
		<?php endif; ?>
		<?php if ( $a['aide'] ) : ?>
			<p class="champ__aide" id="<?php echo esc_attr( $id ); ?>-aide"><?php echo esc_html( $a['aide'] ); ?></p>
		<?php endif; ?>
		<?php if ( $a['erreur'] ) : ?>
			<p class="champ__erreur" id="<?php echo esc_attr( $id ); ?>-erreur"><?php echo ueb_icone( 'alerte', 16 ); ?><?php echo esc_html( $a['erreur'] ); ?></p>
		<?php endif; ?>
	</div>
	<?php
}

/** Groupe de boutons radio présenté en segments (sexe, tranche). */
function ueb_choix_segments( $nom, $legende, array $options, $valeur, $erreur = '' ) {
	?>
	<fieldset id="champ-<?php echo esc_attr( $nom ); ?>" tabindex="-1" class="champ segments<?php echo $erreur ? ' champ--invalide' : ''; ?>">
		<legend><?php echo esc_html( $legende ); ?></legend>
		<div class="segments__liste">
			<?php foreach ( $options as $val => $lib ) : ?>
				<label class="segments__option">
					<input type="radio" name="<?php echo esc_attr( $nom ); ?>" value="<?php echo esc_attr( $val ); ?>" <?php checked( (string) $valeur, (string) $val ); ?> required>
					<span><?php echo esc_html( $lib ); ?></span>
				</label>
			<?php endforeach; ?>
		</div>
		<?php if ( $erreur ) : ?>
			<p class="champ__erreur"><?php echo ueb_icone( 'alerte', 16 ); ?><?php echo esc_html( $erreur ); ?></p>
		<?php endif; ?>
	</fieldset>
	<?php
}

/* Fenêtre de confirmation accessible (remplace window.confirm). */
function ueb_fenetre_confirmation() {
	?>
	<dialog class="fenetre" id="fenetre-confirmation" aria-labelledby="fenetre-titre">
		<form method="dialog" class="fenetre__contenu">
			<h2 id="fenetre-titre" class="fenetre__titre">Confirmer</h2>
			<p class="fenetre__texte" data-fenetre-texte></p>
			<div class="fenetre__actions">
				<button class="btn btn--fantome" value="non">Annuler</button>
				<button class="btn btn--danger" value="oui" data-fenetre-valider>Confirmer</button>
			</div>
		</form>
	</dialog>
	<?php
}

/**
 * Balise du lecteur d'animation Remotion (monté par remotion-ueb.js).
 *
 * $repli : HTML déjà échappé, affiché dans la scène tant que le lecteur n'est
 * pas monté (sans JavaScript, il reste l'image définitive) ; React le remplace.
 */
function ueb_animation( $composition, array $props = array(), $classe = '', $etiquette = '', $repli = '' ) {
	printf(
		'<div class="animation %s" data-remotion="%s" data-props="%s" role="img" aria-label="%s"><div class="animation__scene" data-remotion-scene>%s</div><button type="button" class="animation__pause" data-remotion-pause aria-label="Mettre l’animation en pause" aria-pressed="false">%s%s</button></div>',
		esc_attr( $classe ),
		esc_attr( $composition ),
		esc_attr( wp_json_encode( $props ) ),
		esc_attr( $etiquette ),
		$repli, // phpcs:ignore -- HTML construit et échappé par l'appelant
		ueb_icone( 'pause', 16, 'icone-pause' ),
		ueb_icone( 'lecture', 16, 'icone-lecture' )
	);
}
