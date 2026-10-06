<?php
/**
 * Reçus en attente de validation : la carte rouge en tête des tableaux de
 * bord de la scolarité (droits universitaires) et du Centre médico-social
 * (frais médicaux), la file des plus anciens, et leur mise à jour en direct.
 *
 * Tout passe par la portée du compte (ueb_gestion_portee_sql) : établissement
 * de l'agent et types de reçus qu'il a le droit de voir. Le tableau de bord
 * s'actualise seul (assets/js/attente-recus.js) par admin-ajax.php, en
 * lecture seule : un nouveau reçu met la carte à jour et s'annonce.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reçus d'un type qui attendent une décision, dans la portée du compte.
 *
 * Un reçu « arrive » à la date de son dernier envoi (un renvoi après
 * correction compte comme une arrivée) ; l'ancienneté se lit, comme dans le
 * registre, à la dernière modification du quitus.
 *
 * @param string $type « droits » ou « medicaux ».
 * @param string $vu   Arrivée la plus récente déjà affichée (date MySQL) : les
 *                     reçus arrivés après sont comptés dans « nouveaux ».
 * @return array{nombre: int, dossiers: int, depuis: string, dernier: string, nouveaux: int}
 */
function ueb_attente_recus( $type, $vu = '' ) {
	global $wpdb;
	$vide = array( 'nombre' => 0, 'dossiers' => 0, 'depuis' => '', 'dernier' => '', 'nouveaux' => 0 );
	if ( ! in_array( $type, ueb_types_quitus_visibles(), true ) ) {
		return $vide;
	}
	list( $portee, $params ) = ueb_gestion_portee_sql( array( 'annee' => ueb_annee_academique()['code'], 'type' => $type ) );
	$cle = UEB_SQL_CLE_DOSSIER;
	$l   = $wpdb->get_row( $wpdb->prepare(
		"SELECT COUNT(*) AS nombre, COUNT(DISTINCT t.cle) AS dossiers, MIN(t.date_modification) AS depuis, MAX(t.arrivee) AS dernier, SUM(t.arrivee > %s) AS nouveaux
		   FROM ( SELECT $cle AS cle, q.date_modification,
		                 COALESCE( ( SELECT MAX(r.date_envoi) FROM ueb_insc_recus r WHERE r.quitus_id = q.id ), q.date_modification ) AS arrivee
		            FROM ueb_insc_quitus q
		           WHERE $portee AND q.statut = 'recu_envoye' ) t", // phpcs:ignore -- portée préparée, clé constante
		array_merge( array( $vu ?: '9999-12-31 23:59:59' ), $params )
	) );
	return array(
		'nombre'   => (int) ( $l->nombre ?? 0 ),
		'dossiers' => (int) ( $l->dossiers ?? 0 ),
		'depuis'   => (string) ( $l->depuis ?? '' ),
		'dernier'  => (string) ( $l->dernier ?? '' ),
		'nouveaux' => (int) ( $l->nouveaux ?? 0 ),
	);
}

/** Liste des reçus d'un type, filtrée ou non sur ceux qui attendent une décision. */
function ueb_url_recus_attente( $type, $seulement_attente = true ) {
	return ueb_url_espace_admin( 'scolarite', array( 'vue' => 'quitus', 'type' => $type ) + ( $seulement_attente ? array( 'statut' => 'recu_envoye' ) : array() ) );
}

/**
 * Reçus d'un type déposés chaque jour sur la fenêtre affichée, dans la portée
 * du compte : la mini-courbe de la carte rouge.
 *
 * @return array{jours: string[], depots: int[]}
 */
function ueb_attente_depots( $type, $periode ) {
	global $wpdb;
	$aujourdhui = current_time( 'Y-m-d' );
	$debut      = gmdate( 'Y-m-d', strtotime( $aujourdhui . ' -' . ( max( 2, (int) $periode ) - 1 ) . ' days' ) );
	$par_jour   = array();
	if ( in_array( $type, ueb_types_quitus_visibles(), true ) ) {
		list( $portee, $params ) = ueb_gestion_portee_sql( array( 'annee' => ueb_annee_academique()['code'], 'type' => $type ) );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(r.date_envoi) AS jour, COUNT(DISTINCT r.quitus_id) AS n FROM ueb_insc_recus r JOIN ueb_insc_quitus q ON q.id = r.quitus_id WHERE $portee AND r.date_envoi >= %s GROUP BY jour", // phpcs:ignore -- portée préparée
			array_merge( $params, array( $debut ) )
		) ) as $l ) {
			$par_jour[ $l->jour ] = (int) $l->n;
		}
	}
	$h = array( 'jours' => array(), 'depots' => array() );
	for ( $t = strtotime( $debut ); $t <= strtotime( $aujourdhui ); $t += DAY_IN_SECONDS ) {
		$h['jours'][]  = gmdate( 'Y-m-d', $t );
		$h['depots'][] = $par_jour[ gmdate( 'Y-m-d', $t ) ] ?? 0;
	}
	return $h;
}

/**
 * Première carte du tableau de bord : les reçus qui attendent la validation de
 * cet espace. Même forme que les autres cartes (nombre, phrase, mini-courbe des
 * reçus déposés par jour), entièrement rouge. Toute la carte ouvre la liste
 * filtrée sur les reçus à valider (la liste entière quand il n'y en a pas).
 *
 * @param string     $type    « droits » ou « medicaux ».
 * @param array|null $attente Résultat de ueb_attente_recus() (calculé sinon).
 * @param int        $periode Fenêtre de la mini-courbe (7, 30 ou 90 jours).
 */
function ueb_carte_attente( $type, $attente = null, $periode = 30 ) {
	$a      = $attente ?? ueb_attente_recus( $type );
	$n      = $a['nombre'];
	$h      = ueb_attente_depots( $type, $periode );
	$source = add_query_arg( array( 'action' => 'ueb_attente_recus', 'type' => $type, 'periode' => (int) $periode, '_ajax_nonce' => wp_create_nonce( 'ueb_attente_recus' ) ), admin_url( 'admin-ajax.php' ) );
	$depuis = $a['depuis'] ? human_time_diff( strtotime( $a['depuis'] ), current_time( 'timestamp' ) ) : '';
	?>
	<article class="adm-kpi adm-attente" data-attente="<?php echo esc_attr( $type ); ?>" data-attente-source="<?php echo esc_url( $source ); ?>" data-attente-dernier="<?php echo esc_attr( $a['dernier'] ); ?>" data-attente-nombre="<?php echo (int) $n; ?>">
		<div class="adm-kpi__entete">
			<h2><a class="adm-attente__lien" href="<?php echo esc_url( ueb_url_recus_attente( $type, (bool) $n ) ); ?>">Reçus en attente<span class="sr"> de validation : ouvrir la liste</span></a></h2>
			<?php echo ueb_icone( 'horloge', 18 ); ?>
		</div>
		<p class="adm-kpi__valeur"><?php echo (int) $n; ?><span class="sr"> <?php echo 1 === $n ? 'reçu attend' : 'reçus attendent'; ?> ta validation</span></p>
		<p class="adm-kpi__note"><?php echo esc_html( $n ? sprintf( 'À valider. Le plus ancien attend depuis %s.', $depuis ) : 'Tout est à jour pour le moment.' ); ?></p>
		<?php ueb_adm_mini_courbe( 'depots', $h['jours'], $h['depots'], 'Reçus déposés par jour' ); ?>
	</article>
	<?php
}

/**
 * File des reçus à vérifier, du plus ancien au plus récent (cinq au plus),
 * sous les cartes du tableau de bord. Actualisée avec la carte rouge.
 *
 * @param string $type « droits » ou « medicaux ».
 */
function ueb_file_attente( $type ) {
	$a          = ueb_attente_recus( $type );
	$n          = $a['nombre'];
	$file       = ueb_gestion_liste_quitus( array( 'annee' => ueb_annee_academique()['code'], 'etab' => ueb_etab_agent(), 'statut' => 'recu_envoye', 'type' => $type ), 100 )['lignes'];
	$maintenant = current_time( 'timestamp' );
	usort( $file, static fn( $x, $y ) => strcmp( $x->date_modification, $y->date_modification ) );
	$fiche = static fn( $id ) => ueb_url_espace_admin( 'scolarite', array( 'quitus' => (int) $id, 'type' => $type ) );
	?>
	<section class="adm-panneau file-verif" aria-labelledby="titre-file-<?php echo esc_attr( $type ); ?>" data-attente-file="<?php echo esc_attr( $type ); ?>">
		<header class="adm-panneau__tete">
			<div>
				<h2 id="titre-file-<?php echo esc_attr( $type ); ?>">Reçus à vérifier</h2>
				<p><?php echo $n ? esc_html( sprintf( '%d %s ta vérification : compare-les aux originaux, en commençant par les plus anciens.', $n, $n > 1 ? 'reçus attendent' : 'reçu attend' ) ) : esc_html( 'medicaux' === $type ? 'Les reçus des frais médicaux envoyés par les étudiants arrivent ici.' : 'Les reçus envoyés par les étudiants arrivent ici.' ); ?></p>
			</div>
			<?php echo ueb_icone( 'horloge', 19 ); ?>
		</header>
		<?php if ( ! $file ) : ?>
			<div class="file-verif__vide"><span><?php echo ueb_icone( 'check', 22 ); ?></span><p><b>Aucun reçu en attente.</b> Tout est à jour pour le moment.</p></div>
		<?php else : ?>
			<ul class="file-verif__liste">
				<?php foreach ( array_slice( $file, 0, 5 ) as $rang => $q ) : ?>
					<li style="--i: <?php echo (int) $rang; ?>">
						<a class="file-verif__ligne" href="<?php echo esc_url( $fiche( $q->id ) ); ?>">
							<span class="bo-avatar" aria-hidden="true"><?php echo esc_html( ueb_initiales( $q->prenom, $q->nom ) ); ?></span>
							<span class="file-verif__qui"><b><?php echo esc_html( trim( $q->nom . ' ' . $q->prenom ) ); ?></b><small><?php echo esc_html( $q->numero . ', ' . ueb_detail_quitus( $q ) ); ?></small></span>
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
				<a class="bo-lien" href="<?php echo esc_url( ueb_url_recus_attente( $type ) ); ?>">Tout voir (<?php echo (int) $n; ?>)<?php echo ueb_icone( 'fleche', 16 ); ?></a>
			</footer>
		<?php endif; ?>
	</section>
	<?php
}

/**
 * Point d'accès de l'actualisation (comptes connectés seulement) : la carte
 * et la file à jour, et le nombre de reçus arrivés depuis le dernier affiché.
 * Lecture seule ; jeton WordPress ; type limité à ceux que le compte voit.
 */
add_action( 'wp_ajax_ueb_attente_recus', function () {
	check_ajax_referer( 'ueb_attente_recus' );
	$type = sanitize_key( wp_unslash( $_GET['type'] ?? '' ) );
	if ( ! in_array( $type, ueb_types_quitus_visibles(), true ) ) {
		wp_send_json_error( null, 403 );
	}
	$vu      = sanitize_text_field( wp_unslash( $_GET['vu'] ?? '' ) );
	$vu      = preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $vu ) ? $vu : '';
	$periode = (int) ( $_GET['periode'] ?? 30 );
	$a       = ueb_attente_recus( $type, $vu );
	ob_start();
	ueb_carte_attente( $type, $a, in_array( $periode, array( 7, 30, 90 ), true ) ? $periode : 30 );
	$carte = ob_get_clean();
	ob_start();
	ueb_file_attente( $type );
	$file = ob_get_clean();
	wp_send_json_success( array(
		'nombre'   => $a['nombre'],
		'dernier'  => $a['dernier'],
		'nouveaux' => $vu ? $a['nouveaux'] : 0,
		'carte'    => $carte,
		'file'     => $file,
	) );
} );
