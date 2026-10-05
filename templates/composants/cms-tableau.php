<?php
/**
 * Tableau de bord du Centre médico-social : le même rendu que celui de la
 * scolarité (composants de l'administration), avec les frais médicaux de
 * toute la portée du compte. En tête, la carte rouge des quitus en attente de
 * vérification ouvre la liste déjà filtrée.
 *
 * Attend $annee, $etab_agent, $etab, $espace, $a_verifier, $ici et $url
 * (page-cms.php).
 *
 * @package Inscription_UEB
 */
defined( 'ABSPATH' ) || exit;

$periode  = (int) ( $_GET['periode'] ?? 30 ); // phpcs:ignore -- lecture seule
$periode  = in_array( $periode, array( 7, 30, 90 ), true ) ? $periode : 30;
$d        = ueb_cms_donnees( $annee['code'], $etab_agent, $periode );
$c        = $d['chiffres'];
$f        = $d['finances'];
$hist     = $d['historique'];
$activite = ueb_gestion_activite( $annee['code'], $etab_agent, $periode, 'medicaux' );
$taux     = $f['attendu'] ? 100 * $f['encaisse'] / $f['attendu'] : 0;
$reste    = max( 0, $f['attendu'] - $f['encaisse'] );
$file     = ueb_gestion_liste_quitus( array( 'annee' => $annee['code'], 'etab' => $etab_agent, 'type' => 'medicaux', 'statut' => 'recu_envoye' ), 100 )['lignes'];
usort( $file, static fn( $a, $b ) => strcmp( $a->date_modification, $b->date_modification ) );
$maintenant = current_time( 'timestamp' );
$perimetre  = $etab ? $etab['fr'] : 'Toute l’université';
$liste      = static fn( array $args = array() ) => $url( array( 'vue' => 'quitus' ) + $args );

ueb_adm_tete( array(
	'titre'      => 'Tableau de bord',
	'sous_titre' => sprintf( '%s : %s de frais médicaux pour %s cette année.', $perimetre, 1 === (int) $c['quitus'] ? '1 quitus' : (int) $c['quitus'] . ' quitus', ueb_suivi_etudiants( $c['etudiants'] ) ),
	'theme'      => false,
	'actions'    => ueb_adm_action( $liste(), 'Liste des quitus', 'recu' ),
) );
ueb_afficher_flash();

$cartes = array(
	array( 'Étudiants', ueb_formater_montant( $c['etudiants'] ), 'Avec un quitus de frais médicaux', 'groupe', 'foret', 'etudiants' ),
	array( 'Frais encaissés', ueb_adm_montant_court( $f['encaisse'] ), 'FCFA · paiements vérifiés', 'banque', 'foret', 'encaisse' ),
	array( 'Quitus générés', ueb_formater_montant( $c['quitus'] ), 'Frais médicaux de l’année', 'fichier', 'foret', 'quitus' ),
	array( 'Encaissement', $f['attendu'] ? ueb_pourcent( $taux ) : '—', 'Des frais médicaux attendus', 'check', 'vert', 'taux' ),
);
$parts = array(
	'encaisse'     => array( 'Encaissé', 'var(--dash-foret)' ),
	'verification' => array( 'En vérification', 'var(--dash-bleu)' ),
	'declare'      => array( 'Déclaré, sans reçu validé', 'var(--dash-or)' ),
);
?>
<div class="adm-pilotage">
	<div class="adm-pilotage__contexte"><span class="adm-pilotage__repere" aria-hidden="true"></span><b>Vue d’ensemble</b><span>Frais médicaux, année académique en cours</span></div>
</div>
<div class="adm-tendances-tete">
	<p>Évolution sur <b><?php echo (int) $periode; ?> jours</b></p>
	<nav class="adm-periodes" aria-label="Période des graphiques">
		<?php foreach ( array( 7, 30, 90 ) as $jours ) : ?>
			<a href="<?php echo esc_url( $url( array( 'periode' => $jours ) ) ); ?>" <?php echo $jours === $periode ? 'aria-current="true"' : ''; ?>><?php echo (int) $jours; ?> jours</a>
		<?php endforeach; ?>
	</nav>
</div>

<div class="adm-kpis" aria-label="Indicateurs des frais médicaux de l’année">
	<?php
	ueb_adm_carte_attente( array(
		'nombre' => $a_verifier,
		'url'    => $liste( array( 'statut' => 'recu_envoye' ) ),
		'depuis' => $file ? $file[0]->date_modification : '',
	) );
	?>
	<?php foreach ( $cartes as $carte ) : ?>
		<article class="adm-kpi adm-kpi--<?php echo esc_attr( $carte[4] ); ?>">
			<div class="adm-kpi__entete"><h2><?php echo esc_html( $carte[0] ); ?></h2><?php echo ueb_icone( $carte[3], 18 ); ?></div>
			<p class="adm-kpi__valeur"><?php echo esc_html( $carte[1] ); ?></p>
			<p class="adm-kpi__note"><?php echo esc_html( $carte[2] ); ?></p>
			<?php ueb_adm_mini_courbe( $carte[5], $hist['jours'], $hist[ $carte[5] ] ?? array() ); ?>
		</article>
	<?php endforeach; ?>
</div>

<div class="adm-analyse">
	<section class="adm-panneau adm-finances" aria-labelledby="cms-finances-titre">
		<header class="adm-panneau__tete"><div><h2 id="cms-finances-titre">Encaissement des frais médicaux</h2><p>Répartition des montants attendus</p></div><?php echo ueb_icone( 'banque', 19 ); ?></header>
		<div class="adm-donut">
			<svg viewBox="0 0 200 200" aria-hidden="true">
				<circle class="adm-donut__piste" cx="100" cy="100" r="76"/>
				<?php $offset = 0; foreach ( $parts as $cle => $part ) :
					$longueur = $f['attendu'] ? 100 * $f[ $cle ] / $f['attendu'] : 0;
					if ( $longueur > 0 ) : ?>
						<circle cx="100" cy="100" r="76" pathLength="100" stroke="<?php echo esc_attr( $part[1] ); ?>" stroke-dasharray="<?php echo esc_attr( $longueur . ' ' . ( 100 - $longueur ) ); ?>" stroke-dashoffset="<?php echo esc_attr( -$offset ); ?>"/>
					<?php endif; $offset += $longueur; endforeach; ?>
			</svg>
			<p><b><?php echo esc_html( $f['attendu'] ? ueb_pourcent( $taux ) : '—' ); ?></b><span><?php echo $f['attendu'] ? 'encaissés' : 'Aucun frais attendu'; ?></span></p>
		</div>
		<ul class="adm-finances__legende">
			<?php foreach ( $parts as $cle => $part ) : ?>
				<li><i style="background: <?php echo esc_attr( $part[1] ); ?>" aria-hidden="true"></i><span><?php echo esc_html( $part[0] ); ?></span><b><?php echo esc_html( ueb_formater_montant( $f[ $cle ] ) ); ?><small> FCFA</small></b></li>
			<?php endforeach; ?>
		</ul>
		<p class="adm-finances__total"><span>Total attendu</span><b><?php echo esc_html( ueb_formater_montant( $f['attendu'] ) ); ?> <small>FCFA</small></b></p>
	</section>
	<section class="adm-panneau adm-evolution" aria-label="Évolution des quitus de frais médicaux">
		<?php ueb_graphe_courbes( 'Évolution des quitus', 'Frais médicaux, cumuls de l’année · fenêtre de ' . $periode . ' jours', $activite ); ?>
		<div class="adm-evolution__bilan">
			<div><b><?php echo esc_html( ueb_formater_montant( $c['quitus'] ) ); ?></b><span>Quitus générés</span></div>
			<div><b><?php echo esc_html( ueb_formater_montant( $c['recus_verifies'] ) ); ?></b><span>Paiements vérifiés</span></div>
			<div><b><?php echo esc_html( ueb_formater_montant( $c['recus_envoyes'] ) ); ?></b><span>Reçus à vérifier</span></div>
			<div><b><?php echo esc_html( ueb_formater_montant( $c['recus_rejetes'] ) ); ?></b><span>Paiements refusés</span></div>
		</div>
	</section>
</div>

<div class="adm-secondaire">
	<section class="adm-panneau file-verif" aria-labelledby="titre-file">
		<header class="adm-panneau__tete">
			<div>
				<h2 id="titre-file">Reçus à vérifier</h2>
				<p><?php echo $a_verifier ? esc_html( sprintf( '%d %s ta vérification : compare-les aux originaux, en commençant par les plus anciens.', $a_verifier, $a_verifier > 1 ? 'reçus attendent' : 'reçu attend' ) ) : 'Les reçus des frais médicaux envoyés par les étudiants arrivent ici.'; ?></p>
			</div>
			<?php echo ueb_icone( 'horloge', 19 ); ?>
		</header>
		<?php if ( ! $file ) : ?>
			<div class="file-verif__vide"><span><?php echo ueb_icone( 'check', 22 ); ?></span><p><b>Aucun reçu en attente.</b> Tout est à jour pour le moment.</p></div>
		<?php else : ?>
			<ul class="file-verif__liste">
				<?php foreach ( array_slice( $file, 0, 5 ) as $rang => $q ) : ?>
					<li style="--i: <?php echo (int) $rang; ?>">
						<a class="file-verif__ligne" href="<?php echo $ici( array( 'quitus' => $q->id ) ); ?>">
							<span class="bo-avatar" aria-hidden="true"><?php echo esc_html( ueb_initiales( $q->prenom, $q->nom ) ); ?></span>
							<span class="file-verif__qui"><b><?php echo esc_html( trim( $q->nom . ' ' . $q->prenom ) ); ?></b><small><?php echo esc_html( $q->numero ); ?>, <?php echo esc_html( ueb_etablissement( $q->etablissement )['sigle'] ?? $q->etablissement ); ?></small></span>
							<span class="file-verif__meta">
								<span class="file-verif__montant"><?php echo esc_html( ueb_formater_montant( $q->montant ) ); ?> <small>FCFA</small></span>
								<span class="file-verif__attente"><?php echo ueb_icone( 'horloge', 15 ); ?><?php echo esc_html( 'il y a ' . human_time_diff( strtotime( $q->date_modification ), $maintenant ) ); ?></span>
							</span>
							<span class="file-verif__aller" aria-hidden="true"><?php echo ueb_icone( 'fleche', 18 ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
			<footer class="file-verif__pied">
				<p><?php echo ueb_icone( 'horloge', 16 ); ?>Le premier reçu affiché attend depuis <?php echo esc_html( human_time_diff( strtotime( $file[0]->date_modification ), $maintenant ) ); ?>.</p>
				<a class="bo-lien" href="<?php echo esc_url( $liste( array( 'statut' => 'recu_envoye' ) ) ); ?>">Tout voir (<?php echo (int) $a_verifier; ?>)<?php echo ueb_icone( 'fleche', 16 ); ?></a>
			</footer>
		<?php endif; ?>
	</section>
	<section class="adm-solde" aria-labelledby="cms-solde-titre">
		<header><h2 id="cms-solde-titre">Reste à encaisser</h2><?php echo ueb_icone( 'banque', 21 ); ?></header>
		<p class="adm-solde__montant"><?php echo esc_html( ueb_adm_montant_court( $reste ) ); ?><small>FCFA</small></p>
		<p class="adm-solde__precision">Sur <?php echo esc_html( ueb_formater_montant( $f['attendu'] ) ); ?> FCFA de frais médicaux attendus.</p>
		<dl>
			<div><dt>En vérification</dt><dd><?php echo esc_html( ueb_formater_montant( $f['verification'] ) ); ?> <small>FCFA</small></dd></div>
			<div><dt>Reçu pas encore envoyé</dt><dd><?php echo esc_html( sprintf( '%d quitus', (int) $c['a_payer'] ) ); ?></dd></div>
			<div><dt>Refusés, à renvoyer</dt><dd><?php echo esc_html( sprintf( '%d quitus', (int) $c['recus_rejetes'] ) ); ?></dd></div>
		</dl>
		<a href="<?php echo esc_url( $liste( array( 'statut' => 'genere' ) ) ); ?>">Voir les quitus non payés<?php echo ueb_icone( 'fleche', 18 ); ?></a>
	</section>
</div>

<div class="adm-grille adm-grille--graphes adm-complements">
	<?php ueb_adm_statistiques( $c, 'Frais médicaux de l’année' ); ?>
	<?php
	/* Établissements : part des quitus médicaux déjà vérifiés, le lien ouvre leur liste. */
	$lignes = array();
	foreach ( $d['etabs'] as $sigle => $e ) {
		$lignes[] = array( 'sigle' => $sigle, 'nom' => ueb_etablissement( $sigle )['fr'] ?? $sigle, 'taux' => $e['quitus'] ? 100 * $e['verifies'] / $e['quitus'] : 0, 'e' => $e );
	}
	usort( $lignes, static fn( $a, $b ) => $b['taux'] <=> $a['taux'] ?: strcmp( $a['sigle'], $b['sigle'] ) );
	?>
	<section class="adm-panneau adm-comparaison" aria-labelledby="cms-etabs-titre">
		<header class="adm-panneau__tete"><div><h2 id="cms-etabs-titre">Vérification par établissement</h2><p>Part des quitus de frais médicaux vérifiés</p></div></header>
		<?php if ( ! $lignes ) : ?>
			<p class="graphe__vide">Les établissements apparaîtront dès le premier quitus de frais médicaux.</p>
		<?php else : ?>
			<div class="adm-comparaison__axe" aria-hidden="true"><span>0 %</span><span>50 %</span><span>100 %</span></div>
			<ul>
				<?php foreach ( $lignes as $l ) : ?>
					<li><a href="<?php echo esc_url( add_query_arg( array( 'ueb_etab' => $etab_agent === $l['sigle'] || ueb_est_admin_ueb() ? null : $l['sigle'], 'vue' => 'quitus' ), ueb_url_cms() ) ); ?>" title="<?php echo esc_attr( sprintf( '%s : %d vérifiés sur %d, %d en attente', $l['nom'], $l['e']['verifies'], $l['e']['quitus'], $l['e']['attente'] ) ); ?>"><span class="adm-comparaison__nom"><?php echo esc_html( $l['sigle'] ); ?></span><span class="adm-barre" aria-hidden="true"><i style="--largeur: <?php echo esc_attr( round( $l['taux'], 2 ) ); ?>%"></i></span><b><?php echo esc_html( ueb_pourcent( $l['taux'] ) ); ?></b><span class="sr"><?php echo esc_html( sprintf( ' vérifiés : %d sur %d quitus ; ouvrir la liste', $l['e']['verifies'], $l['e']['quitus'] ) ); ?></span></a></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</section>
	<?php
	ueb_graphe_anneau( 'Répartition par sexe', 'Étudiants ayant un quitus de frais médicaux', array(
		'Masculin' => array( 'valeur' => $c['sexe']['M'], 'couleur' => 'var(--viz-id-1)' ),
		'Féminin'  => array( 'valeur' => $c['sexe']['F'], 'couleur' => 'var(--viz-id-2)' ),
	) );
	?>
</div>

<div class="adm-grille adm-grille--etab">
	<?php
	ueb_adm_niveaux( $d['niveaux'], $c['etudiants'] );
	$couleurs = array( 'ancien' => 'var(--dash-foret)', 'reprise' => 'var(--dash-or)' );
	$situations = array();
	foreach ( UEB_FRAIS_MEDICAUX as $cle => $frais ) {
		$situations[ $frais['libelle'] . ' (' . ueb_fcfa( $frais['montant'] ) . ')' ] = array( 'valeur' => $d['situations'][ $cle ] ?? 0, 'couleur' => $couleurs[ $cle ] ?? 'var(--dash-bleu)' );
	}
	ueb_graphe_anneau( 'Situation des étudiants', 'Le montant des frais médicaux suit la situation déclarée', $situations );
	?>
</div>
