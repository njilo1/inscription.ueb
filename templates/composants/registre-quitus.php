<?php
/**
 * Registre des quitus d'un espace de vérification : une ligne par dossier,
 * compteurs par statut, alerte des validations en attente, filtres, et
 * « Valider » depuis la ligne. Partagé par la scolarité (droits
 * universitaires) et le Centre médico-social (frais médicaux).
 *
 * Attend $espace (ueb_espace_verification()), $annee, $etab_agent et $ici
 * (adresse de l'espace, échappée).
 *
 * @package Inscription_UEB
 */
defined( 'ABSPATH' ) || exit;

$filtres = array(
	'annee'     => $annee['code'],
	'etab'      => $etab_agent,
	'type'      => $espace['type'],
	'statut'    => sanitize_key( $_GET['statut'] ?? '' ),
	'paiements' => sanitize_key( $_GET['paiements'] ?? '' ),
	'q'         => sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) ),
	'filiere'   => sanitize_text_field( wp_unslash( $_GET['filiere'] ?? '' ) ),
	'niveau'    => sanitize_text_field( wp_unslash( $_GET['niveau'] ?? '' ) ),
	'moyen'     => sanitize_text_field( wp_unslash( $_GET['moyen'] ?? '' ) ),
	'page'      => (int) ( $_GET['p'] ?? 1 ),
);
if ( ! isset( UEB_STATUTS_QUITUS[ $filtres['statut'] ] ) ) {
	$filtres['statut'] = '';
}
/* Un seul type de quitus par espace : le filtre « Paiements » (DU / FM) n'a plus d'objet. */
$filtres['paiements'] = '';
/* Une ligne par dossier, limitée au type de quitus de l'espace (inc/gestion.php). */
$dossiers    = ueb_gestion_stats_dossiers( $filtres );
$liste       = ueb_gestion_liste_dossiers( $filtres );
$options     = ueb_gestion_options_dossiers( $filtres );
$maintenant  = current_time( 'timestamp' );
$plus_ancien = $dossiers['plus_ancien'];
$attente     = (int) ( $dossiers['attente']->paiements ?? 0 );
$depuis      = $plus_ancien ? human_time_diff( strtotime( $plus_ancien->date_modification ), $maintenant ) : '';
/* Les compteurs et les pastilles parlent comme l'agent : ce qu'il
   a à valider, ce qui n'est pas encore validé, rejeté ou validé. */
$etats = array(
	'recu_envoye' => array( 'compteur' => 'À valider', 'pastille' => 'À valider', 'icone' => 'envoyer', 'note' => 'Reçus envoyés, à contrôler', 'vide' => 'Les reçus envoyés par les étudiants s’afficheront ici, prêts à être vérifiés.' ),
	'genere'      => array( 'compteur' => 'Non validés', 'pastille' => 'À payer', 'icone' => 'horloge', 'note' => 'Reçu pas encore envoyé', 'vide' => 'Les dossiers dont l’étudiant n’a pas encore envoyé le reçu s’afficheront ici.' ),
	'rejete'      => array( 'compteur' => 'Rejetés', 'pastille' => 'Rejeté', 'icone' => 'alerte', 'note' => 'Renvoyés à l’étudiant', 'vide' => 'Les dossiers renvoyés à l’étudiant avec un motif s’afficheront ici.' ),
	'verifie'     => array( 'compteur' => 'Validés', 'pastille' => 'Validé', 'icone' => 'check', 'note' => 'Tous les paiements vérifiés', 'vide' => 'Les dossiers dont tous les paiements sont vérifiés s’afficheront ici.' ),
);
/* Le Centre médico-social refuse un paiement, la scolarité renvoie un dossier. */
if ( 'cms' === $espace['cle'] ) {
	$etats['rejete'] = array( 'compteur' => 'Refusés', 'pastille' => 'Refusé', 'icone' => 'alerte', 'note' => 'Refusés avec un motif', 'vide' => 'Les paiements refusés avec un motif s’afficheront ici.' );
}
$abreviations   = array( 'droits' => array( 'DU', 'Droits universitaires' ), 'medicaux' => array( 'FM', 'Frais médicaux' ) );
$libelle_statut = $filtres['statut'] ? $etats[ $filtres['statut'] ]['compteur'] : '';
/* Filtres en cours (hors statut) : gardés par les compteurs, l'alerte et les pages. */
$actifs    = array_filter( array_intersect_key( $filtres, array_flip( array( 'paiements', 'q', 'filiere', 'niveau', 'moyen' ) ) ), 'strlen' );
$url_liste = static fn( array $args = array() ) => $ici( array_merge( array( 'vue' => 'quitus', 'statut' => $filtres['statut'] ?: null ), $actifs, $args ) );
$niveau_lu = static function ( $code ) {
	foreach ( UEB_NIVEAUX_INSCRIPTION as $cle_niveau => $libelle ) {
		if ( 0 === strcasecmp( $cle_niveau, $code ) ) {
			return preg_replace( '/^.*—\s*/u', '', $libelle );
		}
	}
	return $code;
};
$selects = array(
	'filiere'   => array( 'Toutes les filières', array_combine( $options['filiere'], $options['filiere'] ) ),
	'niveau'    => array( 'Tous les niveaux', array_combine( $options['niveau'], array_map( $niveau_lu, $options['niveau'] ) ) ),
	'moyen'     => array( 'Tous les moyens de paiement', array_combine( $options['moyen'], $options['moyen'] ) ),
);
$aujourdhui = wp_date( 'd.m.Y' );
/* Anneau « Tous les dossiers » : la composition Remotion « donut »
   (un balayage, la part « à valider » respire), et son repli SVG,
   identique à la dernière image. */
$couleurs_etat = array( 'recu_envoye' => 'var(--ciel-fonce)', 'genere' => 'var(--or)', 'rejete' => 'var(--danger)', 'verifie' => 'var(--vert)' );
$parts_anneau  = array();
foreach ( $couleurs_etat as $cle => $couleur ) {
	if ( $dossiers['total'] && $dossiers['statuts'][ $cle ] ) {
		$parts_anneau[] = array( 'cle' => $cle, 'valeur' => round( 100 * $dossiers['statuts'][ $cle ] / $dossiers['total'], 2 ), 'couleur' => $couleur );
	}
}
$anneau_repli = '<svg viewBox="0 0 200 200" class="compteurs__repli" aria-hidden="true"><circle cx="100" cy="100" r="76" fill="none" stroke="var(--filet)" stroke-width="25"/><g transform="rotate(-90 100 100)">';
$depart       = 0;
foreach ( $parts_anneau as $part ) {
	$anneau_repli .= sprintf( '<circle cx="100" cy="100" r="76" fill="none" pathLength="100" stroke="%s" stroke-width="25" stroke-dasharray="%s %s" stroke-dashoffset="%s"/>', esc_attr( $part['couleur'] ), $part['valeur'], 100 - $part['valeur'], -$depart );
	$depart       += $part['valeur'];
}
$anneau_repli .= '</g></svg>';
?>

<div class="registre-quitus" data-registre-quitus>
	<?php if ( $attente ) : ?>
		<?php if ( 'recu_envoye' === $filtres['statut'] ) : ?>
			<div class="attente attente--filtre" role="status">
				<span class="attente__icone" aria-hidden="true"><?php echo ueb_icone( 'tampon', 20 ); ?></span>
				<div class="attente__texte">
					<p class="attente__titre">Seuls les reçus à valider sont affichés</p>
					<p><?php echo esc_html( sprintf( '%d %s en attente dans %d %s.', $attente, 1 === $attente ? 'validation' : 'validations', (int) $dossiers['attente']->dossiers, 1 === (int) $dossiers['attente']->dossiers ? 'dossier' : 'dossiers' ) ); ?></p>
				</div>
				<a class="attente__action" href="<?php echo $url_liste( array( 'statut' => null ) ); ?>"><?php echo ueb_icone( 'croix', 16 ); ?>Tout afficher</a>
			</div>
		<?php else : ?>
			<div class="attente">
				<span class="attente__icone" aria-hidden="true"><?php echo ueb_icone( 'tampon', 20 ); ?><span class="attente__nombre"><?php echo (int) $attente; ?></span></span>
				<div class="attente__texte">
					<p class="attente__titre"><a class="attente__lien" href="<?php echo $url_liste( array( 'statut' => 'recu_envoye' ) ); ?>"><?php echo esc_html( sprintf( '%d %s en attente', $attente, 1 === $attente ? 'validation' : 'validations' ) ); ?></a></p>
					<p><?php echo esc_html( sprintf( 'Dans %d %s. Le plus ancien reçu attend depuis %s.', (int) $dossiers['attente']->dossiers, 1 === (int) $dossiers['attente']->dossiers ? 'dossier' : 'dossiers', $depuis ) ); ?></p>
				</div>
				<span class="attente__voir" aria-hidden="true">Afficher les reçus à valider<?php echo ueb_icone( 'chevron-d', 18 ); ?></span>
				<?php if ( $plus_ancien ) : ?>
					<a class="attente__second" href="<?php echo $ici( array( 'quitus' => $plus_ancien->id ) ); ?>">Ouvrir le plus ancien</a>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	<?php endif; ?>

	<nav class="compteurs" aria-label="Dossiers par statut">
		<a class="compteurs__carte compteurs__carte--tous" href="<?php echo $url_liste( array( 'statut' => null ) ); ?>"<?php echo '' === $filtres['statut'] ? ' aria-current="page"' : ''; ?>>
			<span class="compteurs__libelle">Tous les dossiers</span>
			<span class="compteurs__corps">
				<span class="compteurs__nombre"><span data-compte="<?php echo (int) $dossiers['total']; ?>" data-cle="tous"><?php echo (int) $dossiers['total']; ?></span><span class="sr"> dossiers</span></span>
				<?php if ( $parts_anneau ) : ?>
					<span class="compteurs__anneau" aria-hidden="true"><span class="animation" data-remotion-differe="donut" data-props="<?php echo esc_attr( wp_json_encode( array( 'parts' => $parts_anneau, 'piste' => 'var(--filet)' ) ) ); ?>"><span class="animation__scene" data-remotion-scene><?php echo $anneau_repli; // phpcs:ignore -- construit et échappé ci-dessus ?></span></span></span>
				<?php endif; ?>
			</span>
		</a>
		<?php foreach ( $etats as $cle => $etat ) : ?>
			<a class="compteurs__carte compteurs__carte--<?php echo esc_attr( $cle ); ?>" href="<?php echo $url_liste( array( 'statut' => $cle ) ); ?>"<?php echo $cle === $filtres['statut'] ? ' aria-current="page"' : ''; ?>>
				<span class="compteurs__libelle"><span class="compteurs__pastille" aria-hidden="true"><?php echo ueb_icone( $etat['icone'], 14 ); ?></span><?php echo esc_html( $etat['compteur'] ); ?></span>
				<span class="compteurs__nombre"><span data-compte="<?php echo (int) $dossiers['statuts'][ $cle ]; ?>" data-cle="<?php echo esc_attr( $cle ); ?>"><?php echo (int) $dossiers['statuts'][ $cle ]; ?></span><span class="sr"> dossiers</span></span>
				<span class="compteurs__note"><?php echo esc_html( $etat['note'] ); ?></span>
			</a>
		<?php endforeach; ?>
	</nav>
	<script>
	/* Avant le premier affichage : arrivée d'ailleurs (les compteurs partent de
	   zéro, les lignes arrivent en cascade) ou depuis le registre lui-même
	   (filtre, carte, validation : les compteurs partent des anciennes valeurs).
	   Rien ne bouge si l'agent a demandé de réduire les animations. */
	( function () {
		try {
			var racine = document.querySelector( '[data-registre-quitus]' );
			var ref = document.referrer ? new URL( document.referrer ) : null;
			var interne = !! ref && ref.pathname === location.pathname && ref.searchParams.get( 'vue' ) === 'quitus' && ! ref.searchParams.has( 'quitus' );
			racine.dataset.arrivee = interne ? 'interne' : 'entree';
			if ( matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) { return; }
			if ( ! interne ) { racine.classList.add( 'est-entree' ); }
			var anciens = interne ? JSON.parse( sessionStorage.getItem( 'ueb-registre-comptes' ) || '{}' ) : {};
			var nombres = racine.querySelectorAll( '[data-compte]' );
			nombres.forEach( function ( el ) {
				var depart = interne ? anciens[ el.dataset.cle ] : 0;
				if ( depart === undefined || +depart === +el.dataset.compte ) { return; }
				el.dataset.depart = depart;
				el.textContent = depart;
			} );
			/* Filet de sécurité : les vraies valeurs reviennent quoi qu'il arrive. */
			setTimeout( function () { nombres.forEach( function ( el ) { el.textContent = el.dataset.compte; } ); }, 2500 );
		} catch ( e ) {}
	} )();
	</script>

	<section class="carte registre registre--quitus" aria-label="<?php echo esc_attr( $libelle_statut ? 'Dossiers ' . mb_strtolower( $libelle_statut ) : 'Dossiers de l’année' ); ?>">
		<form class="registre__filtres" method="get" action="<?php echo esc_url( $espace['url'] ); ?>" role="search" data-filtres-registre>
			<input type="hidden" name="vue" value="quitus">
			<?php if ( $filtres['statut'] ) : ?><input type="hidden" name="statut" value="<?php echo esc_attr( $filtres['statut'] ); ?>"><?php endif; ?>
			<label class="sr" for="f-q">Rechercher un dossier</label>
			<span class="registre__champ">
				<?php echo ueb_icone( 'loupe', 18 ); ?>
				<input id="f-q" type="search" name="q" value="<?php echo esc_attr( $filtres['q'] ); ?>" placeholder="N° de quitus, matricule, nom…" enterkeyhint="search" autocomplete="off">
				<kbd class="registre__touche" aria-hidden="true">Entrée</kbd>
			</span>
			<?php foreach ( $selects as $nom => list( $tous, $choix ) ) : if ( ! $choix ) { continue; } ?>
				<label class="sr" for="f-<?php echo esc_attr( $nom ); ?>"><?php echo esc_html( $tous ); ?></label>
				<span class="registre__select<?php echo '' !== $filtres[ $nom ] ? ' est-actif' : ''; ?>">
					<select id="f-<?php echo esc_attr( $nom ); ?>" name="<?php echo esc_attr( $nom ); ?>">
						<option value=""><?php echo esc_html( $tous ); ?></option>
						<?php foreach ( $choix as $valeur => $libelle ) : ?>
							<option value="<?php echo esc_attr( $valeur ); ?>" <?php selected( 0 === strcasecmp( (string) $valeur, $filtres[ $nom ] ) ); ?>><?php echo esc_html( $libelle ); ?></option>
						<?php endforeach; ?>
					</select><?php echo ueb_icone( 'chevron', 16 ); ?>
				</span>
			<?php endforeach; ?>
			<button class="btn btn--fantome btn--petit registre__filtrer" type="submit">Filtrer</button>
			<?php if ( $actifs ) : ?>
				<a class="registre__effacer" href="<?php echo $ici( array( 'vue' => 'quitus', 'statut' => $filtres['statut'] ?: null ) ); ?>"><?php echo ueb_icone( 'croix', 15 ); ?>Effacer les filtres</a>
			<?php endif; ?>
		</form>

		<?php if ( ! $liste['lignes'] ) : ?>
			<div class="registre-vide">
				<span class="registre-vide__icone" aria-hidden="true"><?php echo ueb_icone( $actifs ? 'loupe' : 'recu', 24 ); ?></span>
				<?php if ( $actifs ) : ?>
					<p class="registre-vide__titre">Aucun dossier ne correspond à ces filtres<?php echo $libelle_statut ? esc_html( ' parmi les « ' . mb_strtolower( $libelle_statut ) . ' »' ) : ''; ?></p>
					<p>Retire un filtre, ou cherche par numéro de quitus, matricule ou nom de famille.</p>
					<div class="registre-vide__actions">
						<a class="btn btn--fantome btn--petit" href="<?php echo $ici( array( 'vue' => 'quitus', 'statut' => $filtres['statut'] ?: null ) ); ?>"><?php echo ueb_icone( 'croix', 16 ); ?>Effacer les filtres</a>
						<?php if ( $libelle_statut ) : ?><a class="btn btn--lien btn--petit" href="<?php echo $url_liste( array( 'statut' => null ) ); ?>">Chercher dans tous les dossiers</a><?php endif; ?>
					</div>
				<?php elseif ( $libelle_statut ) : ?>
					<p class="registre-vide__titre">Aucun dossier « <?php echo esc_html( mb_strtolower( $libelle_statut ) ); ?> » pour le moment</p>
					<p><?php echo esc_html( $etats[ $filtres['statut'] ]['vide'] ); ?></p>
					<div class="registre-vide__actions"><a class="btn btn--fantome btn--petit" href="<?php echo $ici( array( 'vue' => 'quitus' ) ); ?>">Voir tous les dossiers</a></div>
				<?php else : ?>
					<p class="registre-vide__titre">Aucun quitus pour l’année <?php echo esc_html( $annee['libelle'] ); ?></p>
					<p>Les dossiers apparaîtront ici dès que les étudiants auront généré leurs quitus.</p>
				<?php endif; ?>
			</div>
		<?php else : ?>
			<?php if ( $actifs ) : ?>
				<p class="registre__resultat"><span><b><?php echo (int) $liste['total']; ?></b> <?php echo 1 === (int) $liste['total'] ? 'dossier correspond' : 'dossiers correspondent'; ?> aux filtres<?php echo $filtres['q'] ? ' et à « <b>' . esc_html( $filtres['q'] ) . '</b> »' : ''; ?></span></p>
			<?php endif; ?>
			<div class="tableau-conteneur">
				<table class="tableau registre__tableau">
					<thead><tr><th scope="col">Étudiant</th><th scope="col">Paiements</th><th scope="col" class="num">Montant</th><th scope="col" class="num">Reçus</th><th scope="col" class="registre__c-action"><span class="sr">Décision</span></th><th scope="col">Statut</th></tr></thead>
					<tbody>
					<?php foreach ( $liste['lignes'] as $rang => $d ) :
						$p0        = $d->principal;
						$nom       = trim( $p0->nom . ' ' . $p0->prenom );
						$url       = $ici( array( 'quitus' => $p0->id ) );
						$total     = array_sum( array_map( static fn( $q ) => (int) $q->montant, $d->paiements ) );
						$nb_recus  = array_sum( array_map( static fn( $q ) => (int) $q->nb_recus, $d->paiements ) );
						$abr       = static fn( $q ) => $abreviations[ 'medicaux' === $q->type ? 'medicaux' : 'droits' ][0];
						/* Les paiements dont le statut diffère de celui du dossier, en abrégé. */
						$autres    = array_filter( $d->paiements, static fn( $q ) => $q->statut !== $d->statut );
						/* Ce que « Valider » enregistre : les paiements dont le reçu attend. */
						$a_valider = array_values( array_filter( $d->paiements, static fn( $q ) => 'recu_envoye' === $q->statut && (int) $q->nb_recus > 0 ) );
						$valider   = $a_valider && ueb_peut( $espace['decider'], $p0->etablissement );
						if ( $valider ) {
							$detail = implode( ' et ', array_map( static fn( $q ) => mb_strtolower( ueb_libelle_type_quitus( $q->type ) ) . ' (' . ueb_fcfa( $q->montant ) . ')', $a_valider ) );
							$deux   = count( $a_valider ) > 1;
							$bouton = count( $d->paiements ) > 1 ? 'Valider ' . implode( ' et ', array_map( $abr, $a_valider ) ) : 'Valider';
						}
						?>
						<tr class="registre__dossier registre__dossier--<?php echo esc_attr( $d->statut ); ?>" id="dossier-<?php echo (int) $p0->id; ?>" style="--i: <?php echo (int) min( $rang, 12 ); ?>" data-href="<?php echo $url; ?>">
							<td class="registre__c-qui">
								<span class="registre__qui">
									<span class="bo-avatar" aria-hidden="true"><?php echo esc_html( ueb_initiales( $p0->prenom, $p0->nom ) ); ?></span>
									<span><a class="registre__lien" href="<?php echo $url; ?>" title="<?php echo esc_attr( $nom ); ?>"><?php echo esc_html( $nom ); ?></a><small><?php echo esc_html( $p0->identifiant ); ?><?php echo $etab_agent ? '' : esc_html( ', ' . ( ueb_etablissement( $p0->etablissement )['sigle'] ?? $p0->etablissement ) ); /* plusieurs établissements affichés : celui de l'étudiant */ ?></small></span>
								</span>
							</td>
							<td class="registre__c-paiements">
								<ul class="registre__paiements">
									<?php foreach ( $d->paiements as $q ) :
										$type_q   = 'medicaux' === ( $q->type ?? 'droits' ) ? 'medicaux' : 'droits';
										$modalite = 'medicaux' === $type_q ? 'Paiement unique' : ueb_libelle_tranche( $q->tranche ); ?>
										<li><abbr class="registre__abr registre__abr--<?php echo esc_attr( $type_q ); ?>" title="<?php echo esc_attr( $abreviations[ $type_q ][1] ); ?>"><?php echo esc_html( $abreviations[ $type_q ][0] ); ?></abbr><span class="registre__numero"><?php echo esc_html( $q->numero ); ?></span><?php if ( $modalite ) : ?><small><?php echo esc_html( $modalite ); ?></small><?php endif; ?></li>
									<?php endforeach; ?>
								</ul>
							</td>
							<td class="num registre__montant"><?php echo esc_html( ueb_formater_montant( $total ) ); ?> <small>FCFA</small><?php if ( count( $d->paiements ) > 1 ) : ?><span class="registre__detail"><?php echo esc_html( implode( ' + ', array_map( static fn( $q ) => ueb_formater_montant( $q->montant ), $d->paiements ) ) ); ?></span><?php endif; ?></td>
							<td class="num registre__c-recus"><?php if ( $nb_recus ) : ?><span class="registre__recus" title="<?php echo esc_attr( implode( ', ', array_map( static fn( $q ) => $abr( $q ) . ' : ' . (int) $q->nb_recus, $d->paiements ) ) ); ?>"><?php echo ueb_icone( 'recu', 15 ); ?><?php echo (int) $nb_recus; ?><span class="registre__recus-mot"><?php echo 1 === $nb_recus ? ' reçu' : ' reçus'; ?></span></span><?php else : ?><span class="registre__aucun">—<span class="sr">Aucun reçu</span></span><?php endif; ?></td>
							<td class="registre__c-action">
								<?php if ( $valider ) : ?>
									<form method="post" action="<?php echo esc_url( $espace['url'] ); ?>" class="registre__valider" data-registre-valider
										data-confirmer="<?php echo esc_attr( sprintf( $deux ? 'Les paiements de %1$s seront marqués comme vérifiés : %2$s. Valide seulement après avoir contrôlé les originaux des reçus.' : 'Le paiement de %1$s sera marqué comme vérifié : %2$s. Valide seulement après avoir contrôlé l’original du reçu.', $nom, $detail ) ); ?>"
										data-confirmer-titre="<?php echo $deux ? 'Valider les deux paiements ?' : 'Valider ce paiement ?'; ?>" data-confirmer-bouton="<?php echo $deux ? 'Valider les deux' : 'Valider le paiement'; ?>" data-confirmer-ton="enregistrer">
										<?php ueb_champ_csrf(); ?>
										<input type="hidden" name="ueb_action" value="<?php echo esc_attr( $espace['valider'] ); ?>">
										<input type="hidden" name="quitus_id" value="<?php echo (int) $p0->id; ?>">
										<?php foreach ( array_merge( array( 'statut' => $filtres['statut'], 'p' => $liste['page'] > 1 ? $liste['page'] : '' ), $actifs ) as $champ => $valeur ) : if ( '' === (string) $valeur ) { continue; } ?>
											<input type="hidden" name="retour[<?php echo esc_attr( $champ ); ?>]" value="<?php echo esc_attr( $valeur ); ?>">
										<?php endforeach; ?>
										<button class="registre__bouton-valider" type="submit"><?php echo ueb_icone( 'tampon', 16 ); ?><?php echo esc_html( $bouton ); ?><span class="sr"> <?php echo esc_html( ( $deux ? 'les paiements de ' : 'le paiement de ' ) . $nom ); ?></span></button>
										<div class="registre__tampon" data-registre-tampon hidden>
											<div class="animation" data-remotion-differe="tampon" data-props="<?php echo esc_attr( wp_json_encode( array_filter( array( 'etat' => 'verifie', 'sigle' => ueb_etablissement( $p0->etablissement )['sigle'] ?? $p0->etablissement, 'date' => $aujourdhui, 'service' => $espace['tampon'] ) ) ) ); ?>" role="img" aria-label="Tampon : paiement vérifié"><div class="animation__scene" data-remotion-scene></div></div>
										</div>
									</form>
								<?php endif; ?>
							</td>
							<td class="registre__c-statut">
								<span class="badge badge--<?php echo esc_attr( $d->statut ); ?> registre__statut"><?php echo ueb_icone( $etats[ $d->statut ]['icone'] ?? 'info', 13 ); ?><?php echo esc_html( $etats[ $d->statut ]['pastille'] ?? $d->statut ); ?></span>
								<?php if ( $autres ) : ?><span class="registre__detail"><?php echo esc_html( implode( ', ', array_map( static fn( $q ) => $abr( $q ) . ' ' . mb_strtolower( $etats[ $q->statut ]['pastille'] ?? $q->statut ), $autres ) ) ); ?></span><?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<footer class="registre__pied">
				<p><b><?php echo (int) $liste['total']; ?></b> <?php echo 1 === (int) $liste['total'] ? 'dossier' : 'dossiers'; ?><?php echo $liste['pages'] > 1 ? ', page ' . (int) $liste['page'] . ' sur ' . (int) $liste['pages'] : ''; ?></p>
				<?php if ( $liste['pages'] > 1 ) :
					/* Première et dernière pages, la courante et ses voisines ; « … » entre deux trous. */
					$courante = (int) $liste['page'];
					$derniere = (int) $liste['pages'];
					$url_page = static fn( $n ) => $url_liste( array( 'p' => $n ) );
					$visibles = array_values( array_unique( array_filter( array( 1, $courante - 1, $courante, $courante + 1, $derniere ), static fn( $n ) => $n >= 1 && $n <= $derniere ) ) );
					sort( $visibles );
					$precedente = 0;
					?>
					<nav class="pagination registre__pagination" aria-label="Pages du registre">
						<?php if ( $courante > 1 ) : ?><a class="registre__pas" href="<?php echo $url_page( $courante - 1 ); ?>" rel="prev"><?php echo ueb_icone( 'fleche-g', 16 ); ?><span class="sr">Page précédente</span></a><?php endif; ?>
						<?php foreach ( $visibles as $n ) : ?>
							<?php if ( $precedente && $n > $precedente + 1 ) : ?><span class="registre__ellipse" aria-hidden="true">…</span><?php endif; ?>
							<a href="<?php echo $url_page( $n ); ?>" <?php echo $n === $courante ? 'aria-current="page"' : ''; ?>><span class="sr">Page </span><?php echo (int) $n; ?></a>
							<?php $precedente = $n; ?>
						<?php endforeach; ?>
						<?php if ( $courante < $derniere ) : ?><a class="registre__pas" href="<?php echo $url_page( $courante + 1 ); ?>" rel="next"><span class="sr">Page suivante</span><?php echo ueb_icone( 'fleche', 16 ); ?></a><?php endif; ?>
					</nav>
				<?php endif; ?>
			</footer>
		<?php endif; ?>
	</section>
</div>
