<?php
/**
 * Espace scolarité, fiche d'un dossier (?quitus={id}) : un poste de
 * vérification. Le dossier réunit les droits universitaires et, pour un
 * premier paiement, les frais médicaux du même étudiant (ueb_gestion_dossier) :
 * chaque paiement est une fiche de vérification d'un seul tenant, le reçu
 * posé sur le sous-main (zoom, rotation) contre sa fiche de contrôle, avec sa
 * propre décision. La colonne de droite porte le dossier : l'historique,
 * toujours en haut, puis les informations du quitus. Valider ou
 * renvoyer fait frapper le tampon de la scolarité (Remotion) sur le reçu
 * concerné avant l'enregistrement ; une décision déjà prise garde son tampon,
 * figé. Sans JavaScript, tout reste lisible et les formulaires s'envoient tels quels.
 * Attend $fiche (contrôlé par page-scolarite.php) et $ici.
 */
defined( 'ABSPATH' ) || exit;

/* Les paiements que ce compte consulte : droits universitaires (scolarité),
   frais médicaux (CMS), ou les deux (administrateur). */
$type_onglet  = ueb_type_recus_courant(); // Reçus (droits) ou Reçus CMS (frais médicaux)
$paiements    = array_values( array_filter( ueb_gestion_dossier( $fiche ), static fn( $q ) => ueb_peut_voir_quitus( $q ) && ueb_type_du_quitus( $q ) === $type_onglet ) ) ?: array( $fiche );
$fiche        = $paiements[0]; /* les droits d'abord, même ouverts depuis le quitus médical */
$double       = count( $paiements ) > 1;
$etab_fiche   = ueb_etablissement( $fiche->etablissement );
$sigle        = $etab_fiche['sigle'] ?? $fiche->etablissement;
$compte       = ueb_compte_par_id( $fiche->compte_id );
$nom_complet  = $fiche->nom . ' ' . $fiche->prenom;
/* « L1 — Licence 1 » : seul le libellé complet est affiché. */
$niveau       = preg_replace( '/^.*—\s*/u', '', UEB_NIVEAUX_INSCRIPTION[ $fiche->parcours ] ?? $fiche->parcours );
$suivant      = ueb_gestion_quitus_suivant( $fiche );
$adresse      = $ici( array( 'quitus' => $fiche->id, 'type' => $type_onglet ) );
$date_heure   = static fn( $date ) => mysql2date( 'j F Y', $date ) . ' à ' . mysql2date( 'H:i', $date );
$aujourdhui   = wp_date( 'd.m.Y' );

/* Statuts dans les mots de l'agent, comme le registre. */
$etats = array(
	'genere'      => array( 'horloge', 'À payer' ),
	'recu_envoye' => array( 'recu', 'À valider' ),
	'verifie'     => array( 'check', 'Validé' ),
	'rejete'      => array( 'alerte', 'Rejeté' ),
);

/* Un volet par paiement : son reçu, les valeurs attendues, sa décision. */
$volets = array();
foreach ( $paiements as $q ) {
	$type  = 'medicaux' === ( $q->type ?? 'droits' ) ? 'medicaux' : 'droits';
	$recus = ueb_recus_du_quitus( $q->id );
	$verif = 'verifie' === $q->statut;
	$rejet = 'rejete' === $q->statut;
	$somme = ueb_fcfa( $q->montant );
	/* Le compte que le reçu doit créditer : celui de l'établissement, ou celui
	   des services centraux pour les frais médicaux. */
	$attendu = 'medicaux' === $type
		? array( 'Services centraux', UEB_COMPTE_MEDICAL['compte'], UEB_COMPTE_MEDICAL['cle'] )
		: array( 'Compte de la ' . $sigle, $etab_fiche['compte'] ?? '', $etab_fiche['cle'] ?? '' );
	$volets[] = (object) array(
		'q'           => $q,
		'id'          => (int) $q->id,
		'type'        => $type,
		'libelle'     => ueb_libelle_type_quitus( $type ),
		'recus'       => $recus,
		'recus_vus'   => array_reverse( $recus ), /* le plus récent d'abord */
		'nb'          => count( $recus ),
		'dernier'     => $recus ? end( $recus ) : null,
		'statut'      => $q->statut,
		'verifie'     => $verif,
		'rejete'      => $rejet,
		'decide'      => $verif || $rejet,
		'decideur'    => $q->verifie_par ? get_userdata( $q->verifie_par ) : null,
		'montant'     => $somme,
		'modalite'    => 'medicaux' === $type ? 'paiement unique' : mb_strtolower( ueb_libelle_tranche( $q->tranche ) ),
		/* Le contrôle se fait sur un reçu reçu et pas encore validé. */
		'a_controler' => $recus && ! $verif,
		'peut_decider' => ueb_peut_decider_quitus( $q ),
		'points'      => array(
			array( 'Montant versé', $somme, 'balance' ),
			array( 'Nom de l’étudiant', $nom_complet, 'utilisateur' ),
			array( $attendu[0], $attendu[1] . ' clé ' . $attendu[2], 'banque' ),
			array( 'Original présenté', 'Reçu en main et tamponné', 'tampon' ),
		),
	);
}

/* Statut du dossier pour l'en-tête : ce qui attend une action passe d'abord. */
$statuts_p = array_column( $volets, 'statut' );
$statut    = 'verifie';
foreach ( array( 'recu_envoye', 'rejete', 'genere' ) as $s ) {
	if ( in_array( $s, $statuts_p, true ) ) {
		$statut = $s;
		break;
	}
}

/* Suivi en quatre étapes : payer, envoyer le reçu, le faire tamponner, vérifié.
   Pour deux paiements, le dossier avance au pas du moins avancé. */
$tous_verifies = ! array_diff( $statuts_p, array( 'verifie' ) );
$un_rejete     = in_array( 'rejete', $statuts_p, true );
$tous_envoyes  = ! array_intersect( $statuts_p, array( 'genere', 'rejete' ) );
$nb_envoyes    = count( array_intersect( $statuts_p, array( 'recu_envoye', 'verifie' ) ) );
$nb_verifies   = count( array_keys( $statuts_p, 'verifie', true ) );
$envois        = array();
$verifs        = array();
foreach ( $volets as $v ) {
	foreach ( $v->recus as $r ) {
		$envois[] = $r->date_envoi;
	}
	if ( $v->verifie && $v->q->date_verification ) {
		$verifs[] = $v->q->date_verification;
	}
}
$etape  = $tous_verifies ? 4 : ( $tous_envoyes ? 3 : 2 );
$etapes = array(
	array( 'Quitus généré', mysql2date( 'j F', $fiche->date_creation ) ),
	array(
		$un_rejete ? 'Nouveau reçu attendu' : 'Payé et reçu envoyé',
		$un_rejete ? 'Reçu renvoyé à l’étudiant' : ( $tous_envoyes && $envois ? mysql2date( 'j F', max( $envois ) ) : ( $double && $nb_envoyes ? $nb_envoyes . ' reçu sur 2' : 'En attente de l’étudiant' ) ),
	),
	array( 'Reçu tamponné', $tous_verifies && $verifs ? mysql2date( 'j F', max( $verifs ) ) : 'À la scolarité' ),
	array( 'Vérifié', $tous_verifies ? 'Dossier complet' : ( $double && $nb_verifies ? $nb_verifies . ' paiement sur 2' : 'Décision finale' ) ),
);

/* Historique du dossier, du plus récent au plus ancien. */
$historique = array( array( 'date' => $fiche->date_creation, 'icone' => 'fichier', 'texte' => 'Quitus généré par l’étudiant', 'detail' => $double ? 'Droits universitaires et frais médicaux' : '' ) );
foreach ( $volets as $v ) {
	foreach ( $v->recus as $r ) {
		$historique[] = array( 'date' => $r->date_envoi, 'icone' => 'envoyer', 'texte' => 'Reçu envoyé', 'detail' => ueb_libelle_objet_recu( $r, $v->type ) );
	}
	if ( $v->decide && $v->q->date_verification ) {
		$historique[] = array(
			'date'   => $v->q->date_verification,
			'icone'  => $v->verifie ? 'check' : 'alerte',
			'texte'  => ( $v->verifie ? 'Paiement vérifié' : 'Renvoyé à l’étudiant' ) . ( $v->decideur ? ' par ' . $v->decideur->display_name : '' ),
			'detail' => $double ? $v->libelle : '',
			'classe' => $v->statut,
		);
	}
}
if ( ! empty( $fiche->corrige_le ) ) {
	$historique[] = array( 'date' => $fiche->corrige_le, 'icone' => 'crayon', 'texte' => 'Filière ou niveau corrigé par l’étudiant' );
}
usort( $historique, static fn( $a, $b ) => strcmp( $b['date'], $a['date'] ) );

/* Champs communs aux formulaires de décision d'un paiement. */
$champs_decision = static function ( $q, $nouveau ) {
	ueb_champ_csrf();
	printf( '<input type="hidden" name="ueb_action" value="gestion_statut"><input type="hidden" name="quitus_id" value="%d"><input type="hidden" name="statut" value="%s">', (int) $q->id, esc_attr( $nouveau ) );
};

/* Tampon dessiné en SVG : repli affiché avant le montage du lecteur Remotion
   (ou sans JavaScript), à la géométrie de la dernière image. */
$tampon_repli = static function ( $etat, $date ) use ( $sigle ) {
	$encre = 'verifie' === $etat ? '#1d6b3a' : '#b3261e';
	return sprintf(
		'<svg class="qf-tampon__repli" viewBox="0 0 360 360" aria-hidden="true"><g transform="rotate(-9 180 180)" fill="none" stroke="%1$s" opacity=".9"><circle cx="180" cy="180" r="150" stroke-width="7"/><circle cx="180" cy="180" r="139" stroke-width="2"/><circle cx="180" cy="180" r="96" stroke-width="2.6"/><path d="M94 154H266M94 211H266" stroke-width="2.6"/><g fill="%1$s" stroke="none" font-family="Source Sans 3, Arial, sans-serif" font-weight="700" text-anchor="middle"><text x="180" y="142" font-size="16" letter-spacing="3">%2$s</text><text x="180" y="197" font-size="%3$d" font-weight="800" textLength="176" lengthAdjust="spacingAndGlyphs">%4$s</text><text x="180" y="238" font-size="20" letter-spacing="1.5">%5$s</text><text x="180" y="62" font-size="19" letter-spacing="2.4">SCOLARITÉ %6$s</text></g></g></svg>',
		$encre,
		'verifie' === $etat ? 'PAIEMENT' : 'DOSSIER',
		'verifie' === $etat ? 40 : 31,
		'verifie' === $etat ? 'VÉRIFIÉ' : 'À CORRIGER',
		esc_html( $date ),
		esc_html( $sigle )
	);
};
$tampon_props = static fn( $etat, $date ) => array( 'etat' => $etat, 'sigle' => $sigle, 'date' => $date );

$motifs = array(
	'Montant différent' => 'Le montant du reçu ne correspond pas à celui du quitus.',
	'Reçu illisible'    => 'Le reçu est illisible : envoie une photo nette et complète.',
	'Autre compte'      => 'Le versement n’a pas été fait sur le compte indiqué sur le quitus.',
	'Autre nom'         => 'Le nom sur le reçu ne correspond pas à celui du quitus.',
);
?>
<div class="qf<?php echo $double ? ' qf--double' : ''; ?>" data-quitus-fiche>

	<nav class="qf-barre" aria-label="Navigation entre les dossiers">
		<a class="qf-lien" href="<?php echo $ici( array( 'vue' => 'quitus', 'type' => $type_onglet ) ); ?>"><?php echo ueb_icone( 'fleche-g', 18 ); ?><?php echo 'medicaux' === $type_onglet ? 'Tous les reçus CMS' : 'Tous les reçus'; ?></a>
		<?php if ( $suivant ) : ?>
			<a class="qf-lien qf-lien--suivant" href="<?php echo $ici( array( 'quitus' => $suivant['id'], 'type' => $type_onglet ) ); ?>">
				Dossier suivant à valider
				<span class="qf-lien__nombre"><?php echo (int) $suivant['reste']; ?><span class="sr"> en attente</span></span>
				<?php echo ueb_icone( 'chevron-d', 18 ); ?>
			</a>
		<?php endif; ?>
	</nav>

	<header class="qf-tete">
		<div class="qf-tete__identite">
			<span class="qf-avatar" aria-hidden="true"><?php echo esc_html( ueb_initiales( $fiche->prenom, $fiche->nom ) ); ?></span>
			<div class="qf-tete__texte">
				<h1><?php echo esc_html( $nom_complet ); ?></h1>
				<ul class="qf-reperes">
					<?php foreach ( $volets as $v ) : ?>
						<li class="qf-reperes__numero" title="<?php echo esc_attr( $v->libelle ); ?>"><?php echo ueb_icone( 'fichier', 16 ); ?><span class="sr"><?php echo esc_html( 'Quitus des ' . mb_strtolower( $v->libelle ) . ' n° ' ); ?></span><?php echo esc_html( $v->q->numero ); ?></li>
					<?php endforeach; ?>
					<li title="<?php echo esc_attr( $etab_fiche['fr'] ?? '' ); ?>"><img src="<?php echo esc_url( ueb_logo_url( $fiche->etablissement ) ); ?>" alt="" width="20" height="20"><?php echo esc_html( $sigle ); ?></li>
					<li><?php echo esc_html( $fiche->departement . ', ' . $niveau ); ?></li>
				</ul>
			</div>
		</div>
		<div class="qf-tete__actions">
			<span class="qf-statut qf-statut--<?php echo esc_attr( $statut ); ?>"><?php echo ueb_icone( $etats[ $statut ][0] ?? 'info', 16 ); ?><?php echo esc_html( $etats[ $statut ][1] ?? $statut ); ?></span>
			<a class="qf-bouton qf-bouton--clair" href="<?php echo $ici( array( 'quitus' => $fiche->id, 'pdf' => 1 ) ); ?>" target="_blank" rel="noopener"><?php echo ueb_icone( 'telecharger', 18 ); ?>Quitus en PDF<span class="sr"><?php echo $double ? ', droits universitaires et frais médicaux' : ''; ?> (nouvel onglet)</span></a>
		</div>
	</header>

	<ol class="qf-rail<?php echo $un_rejete ? ' qf-rail--rejete' : ''; ?>" aria-label="Suivi du dossier">
		<?php foreach ( $etapes as $i => list( $libelle, $detail ) ) :
			$n        = $i + 1;
			$faite    = $n < $etape || 4 === $etape;
			$courante = $n === $etape && ! $faite;
			$classe   = $faite ? 'est-faite' : ( $courante ? ( $un_rejete ? 'est-courante est-bloquee' : 'est-courante' ) : '' ); ?>
			<li class="<?php echo esc_attr( $classe ); ?>"<?php echo $courante ? ' aria-current="step"' : ''; ?>>
				<span class="qf-rail__noeud" aria-hidden="true"><?php echo $faite ? ueb_icone( 'check', 14 ) : ( $courante && $un_rejete ? ueb_icone( 'alerte', 14 ) : (int) $n ); ?></span>
				<span class="qf-rail__libelle"><?php echo esc_html( $libelle ); ?><span class="sr"><?php echo $faite ? ' : franchie' : ( $courante ? ' : en cours' : ' : à venir' ); ?></span></span>
				<span class="qf-rail__detail"><?php echo esc_html( $detail ); ?></span>
			</li>
		<?php endforeach; ?>
	</ol>

	<div class="qf-poste">
		<div class="qf-paiements">
			<?php foreach ( $volets as $v ) :
				$id    = (int) $v->id;
				$titre = $double
					? ( $v->nb > 1 ? 'Reçus des ' : 'Reçu des ' ) . mb_strtolower( $v->libelle )
					: ( $v->nb > 1 ? 'Reçus de paiement' : 'Reçu de paiement' );
				$quoi  = $double ? 'Le paiement des ' . mb_strtolower( $v->libelle ) . ' (' . $v->montant . ')' : 'Le paiement de ' . $v->montant; ?>
				<article class="qf-paiement qf-paiement--<?php echo esc_attr( $v->statut ); ?>" id="qf-paiement-<?php echo $id; ?>" aria-labelledby="qf-controle-titre-<?php echo $id; ?>">
					<section class="qf-platine" id="qf-recus-<?php echo (int) $v->id; ?>" aria-labelledby="qf-platine-titre-<?php echo (int) $v->id; ?>" data-qf-platine data-qf-paiement="<?php echo (int) $v->id; ?>">
						<header class="qf-platine__tete">
							<div class="qf-platine__titre">
								<h2 id="qf-platine-titre-<?php echo (int) $v->id; ?>"><?php echo esc_html( $titre ); ?></h2>
								<?php if ( $v->dernier ) : ?>
									<p data-qf-legende><?php echo esc_html( ueb_libelle_objet_recu( $v->dernier, $v->type ) ); ?>, envoyé le <?php echo esc_html( $date_heure( $v->dernier->date_envoi ) ); ?></p>
								<?php else : ?>
									<p>Rien n’a encore été envoyé.</p>
								<?php endif; ?>
							</div>
							<?php if ( $v->recus ) : ?>
								<div class="qf-outils" role="toolbar" aria-label="<?php echo esc_attr( 'Affichage : ' . mb_strtolower( $titre ) ); ?>" data-qf-outils hidden>
									<button type="button" class="qf-outil" data-qf-zoom="-1" aria-label="Réduire" title="Réduire"><?php echo ueb_icone( 'moins', 18 ); ?></button>
									<button type="button" class="qf-outil qf-outil--niveau" data-qf-ajuster aria-label="Ajuster à la zone" title="Ajuster à la zone"><span data-qf-niveau>100 %</span></button>
									<button type="button" class="qf-outil" data-qf-zoom="1" aria-label="Agrandir" title="Agrandir"><?php echo ueb_icone( 'plus', 18 ); ?></button>
									<span class="qf-outils__separateur" aria-hidden="true"></span>
									<button type="button" class="qf-outil" data-qf-pivoter aria-label="Pivoter d’un quart de tour" title="Pivoter d’un quart de tour"><?php echo ueb_icone( 'pivoter', 18 ); ?></button>
								</div>
							<?php endif; ?>
						</header>

						<?php if ( $v->nb > 1 ) : ?>
							<nav class="qf-choix" aria-label="Choisir un reçu">
								<?php foreach ( $v->recus_vus as $i => $r ) : ?>
									<a href="#qf-recu-<?php echo (int) $r->id; ?>" data-qf-choix<?php echo 0 === $i ? ' aria-current="true"' : ''; ?>><?php echo esc_html( ueb_libelle_objet_recu( $r, $v->type ) ); ?><small><?php echo esc_html( mysql2date( 'd/m/Y', $r->date_envoi ) ); ?></small></a>
								<?php endforeach; ?>
							</nav>
						<?php endif; ?>
						<p class="sr" role="status" data-qf-annonce></p>

						<?php if ( ! $v->recus ) : ?>
							<div class="qf-scene qf-scene--vide">
								<div class="qf-vide">
									<?php ueb_animation( 'tampon', array( 'etat' => 'attente', 'sigle' => $sigle, 'date' => '', 'titre' => 'Aucun reçu', 'legende' => "pour l’instant" ), 'qf-vide__tampon', 'Emplacement du tampon : aucun reçu envoyé', '' ); ?>
									<p><?php echo $double
										? esc_html( 'Le reçu des ' . mb_strtolower( $v->libelle ) . ' s’affichera ici dès que l’étudiant l’aura envoyé depuis son espace.' )
										: 'Le reçu s’affichera ici dès que l’étudiant l’aura envoyé depuis son espace. Tu pourras alors le comparer au quitus.'; ?></p>
								</div>
							</div>
						<?php else : ?>
							<?php foreach ( $v->recus_vus as $i => $r ) :
								$url   = ueb_url_recu( $r->id );
								$objet = ueb_libelle_objet_recu( $r, $v->type );
								$pdf   = 'application/pdf' === $r->type_mime; ?>
								<figure class="qf-scene<?php echo $pdf ? ' qf-scene--pdf' : ''; ?>" id="qf-recu-<?php echo (int) $r->id; ?>" data-qf-recu data-legende="<?php echo esc_attr( $objet . ', envoyé le ' . $date_heure( $r->date_envoi ) ); ?>" data-fichier="<?php echo esc_attr( $r->nom_original ); ?>" data-ouvrir="<?php echo esc_url( $url ); ?>" data-telecharger="<?php echo esc_url( ueb_url_recu( $r->id, true ) ); ?>"<?php echo $pdf ? ' data-pdf' : ''; ?><?php echo $pdf ? '' : ' tabindex="0" aria-label="' . esc_attr( 'Reçu ' . $objet . ' : glisse pour déplacer, double-clic pour agrandir' ) . '"'; ?>>
									<div class="qf-feuille" data-qf-feuille>
										<?php if ( $pdf ) : ?>
											<iframe src="<?php echo esc_url( $url ); ?>" title="<?php echo esc_attr( 'Reçu de paiement : ' . $objet ); ?>" loading="lazy"></iframe>
										<?php else : ?>
											<img src="<?php echo esc_url( $url ); ?>" alt="<?php echo esc_attr( 'Reçu de paiement : ' . $objet ); ?>" draggable="false" <?php echo 0 === $i ? 'fetchpriority="high"' : 'loading="lazy"'; ?>>
										<?php endif; ?>
										<?php if ( 0 === $i ) : ?>
											<?php if ( $v->decide ) : $date_tampon = mysql2date( 'd.m.Y', $v->q->date_verification ); ?>
												<div class="qf-tampon" data-qf-tampon-pose>
													<?php ueb_animation( 'tampon', $tampon_props( $v->statut, $date_tampon ), 'qf-tampon__animation animation--fige', ( $v->verifie ? 'Tampon de la scolarité : paiement vérifié le ' : 'Tampon de la scolarité : dossier à corriger depuis le ' ) . mysql2date( 'd/m/Y', $v->q->date_verification ), $tampon_repli( $v->statut, $date_tampon ) ); ?>
												</div>
											<?php endif; ?>
											<?php if ( $v->peut_decider && $v->a_controler ) : /* tampons prêts à frapper, montés à la décision */ ?>
												<?php foreach ( array( 'verifie', 'rejete' ) as $etat ) : ?>
													<div class="qf-tampon" data-qf-tampon="<?php echo esc_attr( $etat ); ?>" hidden>
														<div class="animation qf-tampon__animation" data-remotion-differe="tampon" data-props="<?php echo esc_attr( wp_json_encode( $tampon_props( $etat, $aujourdhui ) ) ); ?>" role="img" aria-label="<?php echo esc_attr( 'verifie' === $etat ? 'Tampon : paiement vérifié' : 'Tampon : dossier à corriger' ); ?>"><div class="animation__scene" data-remotion-scene></div></div>
													</div>
												<?php endforeach; ?>
											<?php endif; ?>
										<?php endif; ?>
									</div>
								</figure>
							<?php endforeach; ?>

							<footer class="qf-platine__pied">
								<p class="qf-fichier" data-qf-fichier title="<?php echo esc_attr( $v->dernier->nom_original ); ?>"><?php echo ueb_icone( 'image', 16 ); ?><span><?php echo esc_html( $v->dernier->nom_original ); ?></span></p>
								<div class="qf-platine__actions">
									<a class="qf-bouton qf-bouton--verre" href="<?php echo esc_url( ueb_url_recu( $v->dernier->id ) ); ?>" target="_blank" rel="noopener" data-qf-ouvrir><?php echo ueb_icone( 'oeil', 18 ); ?>Ouvrir<span class="sr"> le reçu dans un nouvel onglet</span></a>
									<a class="qf-bouton qf-bouton--verre" href="<?php echo esc_url( ueb_url_recu( $v->dernier->id, true ) ); ?>" data-qf-telecharger><?php echo ueb_icone( 'telecharger', 18 ); ?>Télécharger<span class="sr"> le reçu</span></a>
								</div>
							</footer>
						<?php endif; ?>
					</section>
					<section class="qf-controle qf-controle--<?php echo esc_attr( $v->statut ); ?>" id="qf-decision-<?php echo $id; ?>" aria-labelledby="qf-controle-titre-<?php echo $id; ?>" data-qf-controle data-qf-paiement="<?php echo $id; ?>">
						<div class="qf-controle__resume">
							<header class="qf-controle__tete">
								<div>
									<h2 id="qf-controle-titre-<?php echo $id; ?>"><?php echo esc_html( $v->libelle ); ?></h2>
									<p class="qf-controle__numero"><span class="sr">Quitus n° </span><?php echo esc_html( $v->q->numero ); ?></p>
								</div>
								<?php if ( $double ) : ?>
									<span class="qf-statut qf-statut--petit qf-statut--<?php echo esc_attr( $v->statut ); ?>"><?php echo ueb_icone( $etats[ $v->statut ][0] ?? 'info', 14 ); ?><?php echo esc_html( $etats[ $v->statut ][1] ?? $v->statut ); ?></span>
								<?php endif; ?>
							</header>

						<div class="qf-attendu">
							<p class="qf-attendu__libelle">Montant attendu</p>
							<p class="qf-attendu__montant"><?php echo esc_html( ueb_formater_montant( $v->q->montant ) ); ?> <span>FCFA</span></p>
							<?php if ( $v->modalite ) : ?><p class="qf-attendu__nature"><?php echo esc_html( $v->modalite ); ?></p><?php endif; ?>
							<?php if ( $v->q->moyen_paiement ) : ?>
								<p class="qf-attendu__lieu"><?php echo ueb_icone( 'lieu', 15 ); ?>Payé via <?php echo esc_html( $v->q->moyen_paiement ); ?></p>
							<?php endif; ?>
						</div>

						<?php if ( $v->verifie ) : ?>
							<div class="qf-verdict qf-verdict--verifie">
								<span class="qf-verdict__icone" aria-hidden="true"><?php echo ueb_icone( 'check', 20 ); ?></span>
								<div><p class="qf-verdict__titre">Paiement vérifié</p><p><?php echo $v->decideur ? 'Par ' . esc_html( $v->decideur->display_name ) . ', l' : 'L'; ?>e <?php echo esc_html( $date_heure( $v->q->date_verification ) ); ?>.</p></div>
							</div>
						<?php elseif ( $v->rejete ) : ?>
							<div class="qf-verdict qf-verdict--rejete">
								<span class="qf-verdict__icone" aria-hidden="true"><?php echo ueb_icone( 'alerte', 20 ); ?></span>
								<div><p class="qf-verdict__titre">Renvoyé à l’étudiant</p><p><?php echo $v->decideur ? 'Par ' . esc_html( $v->decideur->display_name ) . ', l' : 'L'; ?>e <?php echo esc_html( $date_heure( $v->q->date_verification ) ); ?>.</p><blockquote><?php echo esc_html( $v->q->motif_rejet ); ?></blockquote></div>
							</div>
						<?php elseif ( ! $v->recus ) : ?>
							<div class="qf-verdict">
								<span class="qf-verdict__icone" aria-hidden="true"><?php echo ueb_icone( 'horloge', 20 ); ?></span>
								<div><p class="qf-verdict__titre">En attente du paiement</p><p>La vérification s’ouvrira quand l’étudiant aura envoyé son reçu.</p></div>
							</div>
						<?php endif; ?>
						</div>

						<div class="qf-controle__examen">
						<?php if ( $v->peut_decider && $v->a_controler ) : ?>
							<fieldset class="qf-points" data-qf-points>
								<legend>Compare le reçu et l’original</legend>
								<?php foreach ( $v->points as list( $libelle, $valeur ) ) : ?>
									<label class="qf-point">
										<input type="checkbox" data-qf-point>
										<span class="qf-point__case" aria-hidden="true"><?php echo ueb_icone( 'check', 14 ); ?></span>
										<span class="qf-point__texte"><span class="qf-point__libelle"><?php echo esc_html( $libelle ); ?></span><span class="qf-point__valeur"><?php echo esc_html( $valeur ); ?></span></span>
									</label>
								<?php endforeach; ?>
							</fieldset>
							<div class="qf-progression" data-qf-progression hidden>
								<span class="qf-progression__piste" aria-hidden="true"><i data-qf-jauge></i></span>
								<p aria-live="polite" data-qf-compte>Aucun point contrôlé sur <?php echo count( $v->points ); ?></p>
							</div>
						<?php else : ?>
							<dl class="qf-releve">
								<?php foreach ( array_slice( $v->points, 0, 3 ) as list( $libelle, $valeur, $icone ) ) : ?>
									<div><dt><?php echo ueb_icone( $icone, 16 ); ?><?php echo esc_html( $libelle ); ?></dt><dd><?php echo esc_html( $valeur ); ?></dd></div>
								<?php endforeach; ?>
							</dl>
						<?php endif; ?>

						<?php if ( ! $v->peut_decider ) : ?>
							<p class="qf-note"><?php echo ueb_icone( 'cadenas', 16 ); ?>Consultation seule : ton rôle ne permet pas de rendre une décision.</p>
						<?php else : ?>
							<div class="qf-decision">
								<?php if ( $v->a_controler ) : ?>
									<form method="post" action="<?php echo $adresse; ?>" data-qf-tamponner="verifie"
										data-confirmer="<?php echo esc_attr( sprintf( '%s de %s sera marqué comme vérifié. L’étudiant le verra dans son espace.', $quoi, $nom_complet ) ); ?>"
										data-confirmer-titre="Valider ce paiement ?" data-confirmer-bouton="Valider le paiement" data-confirmer-ton="enregistrer">
										<?php $champs_decision( $v->q, 'verifie' ); ?>
										<button class="qf-bouton qf-bouton--valider" type="submit"><?php echo ueb_icone( 'tampon', 20 ); ?>Valider le paiement<?php echo $double ? '<span class="sr"> des ' . esc_html( mb_strtolower( $v->libelle ) ) . '</span>' : ''; ?></button>
									</form>
								<?php elseif ( ! $v->verifie ) : ?>
									<button class="qf-bouton qf-bouton--valider" type="button" disabled aria-describedby="qf-sans-recu-<?php echo $id; ?>"><?php echo ueb_icone( 'tampon', 20 ); ?>Valider le paiement</button>
									<p class="qf-aide" id="qf-sans-recu-<?php echo $id; ?>">Disponible dès l’envoi du reçu.</p>
								<?php endif; ?>

								<?php if ( ! $v->verifie ) : ?>
									<details class="qf-renvoi">
										<summary><?php echo ueb_icone( $v->rejete ? 'crayon' : 'alerte', 17 ); ?><?php echo $v->rejete ? 'Modifier le motif du renvoi' : 'Signaler un problème'; ?><?php echo ueb_icone( 'chevron', 16, 'qf-renvoi__chevron' ); ?></summary>
										<form method="post" action="<?php echo $adresse; ?>" class="qf-renvoi__formulaire"<?php if ( ! $v->rejete ) : ?><?php echo $v->recus ? ' data-qf-tamponner="rejete"' : ''; ?> data-confirmer="L’étudiant verra ton motif dans son espace et devra envoyer un nouveau reçu." data-confirmer-titre="<?php echo $double ? esc_attr( 'Renvoyer le reçu des ' . mb_strtolower( $v->libelle ) . ' ?' ) : 'Renvoyer le dossier à l’étudiant ?'; ?>" data-confirmer-bouton="Renvoyer à l’étudiant"<?php endif; ?>>
											<?php $champs_decision( $v->q, 'rejete' ); ?>
											<div class="qf-motifs" data-qf-motifs hidden>
												<?php foreach ( $motifs as $court => $phrase ) : ?>
													<button type="button" class="qf-motif" data-qf-motif="<?php echo esc_attr( $phrase ); ?>"><?php echo esc_html( $court ); ?></button>
												<?php endforeach; ?>
											</div>
											<label class="qf-champ">
												<span class="qf-champ__libelle">Motif du renvoi</span>
												<textarea name="motif" rows="3" required minlength="5" maxlength="255" placeholder="Explique ce que l’étudiant doit corriger." aria-describedby="qf-motif-aide-<?php echo $id; ?>"><?php echo esc_textarea( $v->q->motif_rejet ?? '' ); ?></textarea>
											</label>
											<p class="qf-aide" id="qf-motif-aide-<?php echo $id; ?>">L’étudiant lira ce message dans son espace (5 à 255 caractères).</p>
											<button class="qf-bouton qf-bouton--danger" type="submit"><?php echo ueb_icone( 'envoyer', 18 ); ?><?php echo $v->rejete ? 'Mettre à jour le motif' : 'Renvoyer à l’étudiant'; ?></button>
										</form>
									</details>
								<?php endif; ?>

								<?php if ( $v->decide && $v->recus ) : ?>
									<form method="post" action="<?php echo $adresse; ?>" class="qf-annuler"
										data-confirmer="<?php echo $double ? esc_attr( 'Le paiement des ' . mb_strtolower( $v->libelle ) . ' reviendra parmi les reçus à vérifier et la décision actuelle sera effacée.' ) : 'Le dossier reviendra parmi les reçus à vérifier et la décision actuelle sera effacée.'; ?>"
										data-confirmer-titre="Annuler la décision ?" data-confirmer-bouton="Annuler la décision" data-confirmer-annuler="Garder la décision">
										<?php $champs_decision( $v->q, 'recu_envoye' ); ?>
										<button class="qf-bouton qf-bouton--discret" type="submit"><?php echo ueb_icone( 'fleche-g', 17 ); ?>Annuler la décision</button>
									</form>
								<?php endif; ?>
							</div>
							<p class="qf-note"><?php echo ueb_icone( 'bouclier', 16 ); ?>Chaque décision est enregistrée à ton nom.</p>
						<?php endif; ?>
						</div>
					</section>
				</article>
			<?php endforeach; ?>
		</div>

			<section class="qf-panneau qf-journal" aria-labelledby="qf-journal-titre">
				<header class="qf-panneau__tete">
					<h2 id="qf-journal-titre">Historique</h2>
					<p>Tout ce qui est arrivé au dossier.</p>
				</header>
				<ol class="qf-journal__liste">
					<?php foreach ( $historique as $h ) : ?>
						<li class="qf-journal--<?php echo esc_attr( $h['classe'] ?? 'neutre' ); ?>">
							<span class="qf-journal__puce" aria-hidden="true"><?php echo ueb_icone( $h['icone'], 14 ); ?></span>
							<div>
								<p><?php echo esc_html( $h['texte'] ); ?></p>
								<?php if ( ! empty( $h['detail'] ) ) : ?><p class="qf-journal__detail"><?php echo esc_html( $h['detail'] ); ?></p><?php endif; ?>
								<time datetime="<?php echo esc_attr( mysql2date( 'c', $h['date'] ) ); ?>"><?php echo esc_html( $date_heure( $h['date'] ) ); ?></time>
							</div>
						</li>
					<?php endforeach; ?>
				</ol>
			</section>

			<section class="qf-panneau qf-infos" aria-labelledby="qf-infos-titre">
				<header class="qf-panneau__tete">
					<h2 id="qf-infos-titre">Informations du quitus</h2>
					<p>Les données imprimées sur <?php echo $double ? 'les documents' : 'le document'; ?> de l’étudiant.</p>
				</header>
				<dl class="qf-infos__liste">
					<div class="qf-infos__large"><dt>Date et lieu de naissance</dt><dd><?php echo esc_html( mysql2date( 'd/m/Y', $fiche->date_naissance ) . ' à ' . $fiche->lieu_naissance ); ?></dd></div>
					<div><dt>Sexe</dt><dd><?php echo 'F' === $fiche->sexe ? 'Féminin' : 'Masculin'; ?></dd></div>
					<div><dt>Nationalité</dt><dd><?php echo esc_html( $fiche->nationalite ); ?></dd></div>
					<div class="qf-infos__large"><dt>Établissement</dt><dd><?php echo esc_html( $etab_fiche['fr'] ?? $fiche->etablissement ); ?></dd></div>
					<div class="qf-infos__large"><dt>Filière</dt><dd><?php echo esc_html( $fiche->departement ); ?></dd></div>
					<div><dt>Niveau</dt><dd><?php echo esc_html( $niveau ); ?></dd></div>
					<div><dt>Identifiant étudiant</dt><dd class="qf-chiffres"><?php echo esc_html( $fiche->identifiant ); ?></dd></div>
					<div><dt>Téléphone</dt><dd><?php if ( $compte && $compte->telephone ) : ?><a href="tel:+237<?php echo esc_attr( $compte->telephone ); ?>"><?php echo esc_html( ueb_formater_telephone( $compte->telephone ) ); ?></a><?php else : ?>Non renseigné<?php endif; ?></dd></div>
					<div><dt>Année académique</dt><dd><?php echo esc_html( $fiche->annee_academique ); ?></dd></div>
				</dl>
				<?php if ( ! empty( $fiche->corrige_le ) ) : ?>
					<p class="qf-correction"><?php echo ueb_icone( 'crayon', 16 ); ?><span>Corrigé par l’étudiant le <?php echo esc_html( $date_heure( $fiche->corrige_le ) ); ?>. <?php echo esc_html( $fiche->correction ); ?></span></p>
				<?php endif; ?>
			</section>
	</div>
</div>
