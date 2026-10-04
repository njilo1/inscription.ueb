<?php
/**
 * Espace scolarité, fiche d'un quitus (?quitus={id}) : un poste de
 * vérification. À gauche, le reçu posé sur le sous-main (zoom, rotation) ; à
 * droite, la fiche de contrôle avec les valeurs attendues à cocher et la
 * décision. Valider ou renvoyer fait frapper le tampon de la scolarité
 * (Remotion) sur le reçu avant l'enregistrement ; une décision déjà prise
 * garde son tampon, figé. Sous le reçu, les informations du quitus ; à
 * droite, l’historique au-dessus de la fiche de contrôle.
 * Sans JavaScript, tout reste lisible et les formulaires s'envoient tels quels.
 * Attend $fiche (contrôlé par page-scolarite.php) et $ici.
 */
defined( 'ABSPATH' ) || exit;

$etab_fiche   = ueb_etablissement( $fiche->etablissement );
$sigle        = $etab_fiche['sigle'] ?? $fiche->etablissement;
$compte       = ueb_compte_par_id( $fiche->compte_id );
$recus        = ueb_recus_du_quitus( $fiche->id );
$decideur     = $fiche->verifie_par ? get_userdata( $fiche->verifie_par ) : null;
$type_fiche   = 'medicaux' === ( $fiche->type ?? 'droits' ) ? 'medicaux' : 'droits';
$statut       = $fiche->statut;
$rejete       = 'rejete' === $statut;
$verifie      = 'verifie' === $statut;
$decide       = $rejete || $verifie;
$peut_decider = ueb_peut( 'ueb_decider_quitus', $fiche->etablissement );
$recus_vus    = array_reverse( $recus ); /* le plus récent d'abord */
$nb_recus     = count( $recus );
$dernier_recu = $recus_vus[0] ?? null;
$nom_complet  = $fiche->nom . ' ' . $fiche->prenom;
/* « L1 — Licence 1 » : seul le libellé complet est affiché. */
$niveau       = preg_replace( '/^.*—\s*/u', '', UEB_NIVEAUX_INSCRIPTION[ $fiche->parcours ] ?? $fiche->parcours );
$montant      = ueb_fcfa( $fiche->montant );
$modalite     = 'medicaux' === $type_fiche ? 'paiement unique' : mb_strtolower( ueb_libelle_tranche( $fiche->tranche ) );
$suivant      = ueb_gestion_quitus_suivant( $fiche );
$adresse      = $ici( array( 'quitus' => $fiche->id ) );
$date_heure   = static fn( $date ) => mysql2date( 'j F Y', $date ) . ' à ' . mysql2date( 'H:i', $date );

/* Le compte que le reçu doit créditer : celui de l'établissement, ou celui
   des services centraux pour les frais médicaux. */
$compte_attendu = 'medicaux' === $type_fiche
	? array( 'Services centraux', UEB_COMPTE_MEDICAL['compte'], UEB_COMPTE_MEDICAL['cle'] )
	: array( 'Compte de la ' . $sigle, $etab_fiche['compte'] ?? '', $etab_fiche['cle'] ?? '' );

/* Pastille de statut. */
$statuts = array(
	'genere'      => 'horloge',
	'recu_envoye' => 'recu',
	'verifie'     => 'check',
	'rejete'      => 'alerte',
);
$statut_libelle = UEB_STATUTS_QUITUS[ $statut ]['libelle'] ?? $statut;

/* Suivi en quatre étapes : payer, envoyer le reçu, le faire tamponner, vérifié. */
$etape  = array( 'genere' => 2, 'recu_envoye' => 3, 'rejete' => 2, 'verifie' => 4 )[ $statut ] ?? 1;
$etapes = array(
	array( 'Quitus généré', mysql2date( 'j F', $fiche->date_creation ) ),
	array( $rejete ? 'Nouveau reçu attendu' : 'Payé et reçu envoyé', $dernier_recu && ! $rejete ? mysql2date( 'j F', $dernier_recu->date_envoi ) : ( $rejete ? 'Reçu renvoyé à l’étudiant' : 'En attente de l’étudiant' ) ),
	array( 'Reçu tamponné', $verifie ? mysql2date( 'j F', $fiche->date_verification ) : 'À la scolarité' ),
	array( 'Vérifié', $verifie ? 'Dossier complet' : 'Décision finale' ),
);

/* Historique du dossier, du plus récent au plus ancien. */
$historique = array( array( 'date' => $fiche->date_creation, 'icone' => 'fichier', 'texte' => 'Quitus généré par l’étudiant' ) );
foreach ( $recus as $r ) {
	$historique[] = array( 'date' => $r->date_envoi, 'icone' => 'envoyer', 'texte' => 'Reçu envoyé', 'detail' => ueb_libelle_objet_recu( $r, $fiche->type ) );
}
if ( ! empty( $fiche->corrige_le ) ) {
	$historique[] = array( 'date' => $fiche->corrige_le, 'icone' => 'crayon', 'texte' => 'Filière ou niveau corrigé par l’étudiant' );
}
if ( $decide && $fiche->date_verification ) {
	$historique[] = array(
		'date'   => $fiche->date_verification,
		'icone'  => $verifie ? 'check' : 'alerte',
		'texte'  => ( $verifie ? 'Paiement vérifié' : 'Renvoyé à l’étudiant' ) . ( $decideur ? ' par ' . $decideur->display_name : '' ),
		'classe' => $statut,
	);
}
usort( $historique, static fn( $a, $b ) => strcmp( $b['date'], $a['date'] ) );

/* Champs communs aux formulaires de décision. */
$champs_decision = static function ( $nouveau ) use ( $fiche ) {
	ueb_champ_csrf();
	printf( '<input type="hidden" name="ueb_action" value="gestion_statut"><input type="hidden" name="quitus_id" value="%d"><input type="hidden" name="statut" value="%s">', (int) $fiche->id, esc_attr( $nouveau ) );
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
$aujourdhui   = wp_date( 'd.m.Y' );

/* Le contrôle se fait sur un reçu reçu et pas encore validé. */
$a_controler = $recus && ! $verifie;
$points      = array(
	array( 'Montant versé', $montant, 'balance' ),
	array( 'Nom de l’étudiant', $nom_complet, 'utilisateur' ),
	array( $compte_attendu[0], $compte_attendu[1] . ' clé ' . $compte_attendu[2], 'banque' ),
	array( 'Original présenté', 'Reçu en main et tamponné', 'tampon' ),
);
$motifs = array(
	'Montant différent' => 'Le montant du reçu ne correspond pas à celui du quitus.',
	'Reçu illisible'    => 'Le reçu est illisible : envoie une photo nette et complète.',
	'Autre compte'      => 'Le versement n’a pas été fait sur le compte indiqué sur le quitus.',
	'Autre nom'         => 'Le nom sur le reçu ne correspond pas à celui du quitus.',
);
?>
<div class="qf" data-quitus-fiche>

	<nav class="qf-barre" aria-label="Navigation entre les dossiers">
		<a class="qf-lien" href="<?php echo $ici( array( 'vue' => 'quitus' ) ); ?>"><?php echo ueb_icone( 'fleche-g', 18 ); ?>Tous les quitus</a>
		<?php if ( $suivant ) : ?>
			<a class="qf-lien qf-lien--suivant" href="<?php echo $ici( array( 'quitus' => $suivant['id'] ) ); ?>">
				Reçu suivant à vérifier
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
					<li class="qf-reperes__numero"><?php echo ueb_icone( 'fichier', 16 ); ?><span class="sr">Quitus n° </span><?php echo esc_html( $fiche->numero ); ?></li>
					<li title="<?php echo esc_attr( $etab_fiche['fr'] ?? '' ); ?>"><img src="<?php echo esc_url( ueb_logo_url( $fiche->etablissement ) ); ?>" alt="" width="20" height="20"><?php echo esc_html( $sigle ); ?></li>
					<li><?php echo esc_html( $fiche->departement . ', ' . $niveau ); ?></li>
				</ul>
			</div>
		</div>
		<div class="qf-tete__actions">
			<span class="qf-statut qf-statut--<?php echo esc_attr( $statut ); ?>"><?php echo ueb_icone( $statuts[ $statut ] ?? 'info', 16 ); ?><?php echo esc_html( $statut_libelle ); ?></span>
			<a class="qf-bouton qf-bouton--clair" href="<?php echo $ici( array( 'quitus' => $fiche->id, 'pdf' => 1 ) ); ?>" target="_blank" rel="noopener"><?php echo ueb_icone( 'telecharger', 18 ); ?>Quitus en PDF<span class="sr"> (nouvel onglet)</span></a>
		</div>
	</header>

	<ol class="qf-rail<?php echo $rejete ? ' qf-rail--rejete' : ''; ?>" aria-label="Suivi du dossier">
		<?php foreach ( $etapes as $i => list( $libelle, $detail ) ) :
			$n        = $i + 1;
			$faite    = $n < $etape || 4 === $etape;
			$courante = $n === $etape && ! $faite;
			$classe   = $faite ? 'est-faite' : ( $courante ? ( $rejete ? 'est-courante est-bloquee' : 'est-courante' ) : '' ); ?>
			<li class="<?php echo esc_attr( $classe ); ?>"<?php echo $courante ? ' aria-current="step"' : ''; ?>>
				<span class="qf-rail__noeud" aria-hidden="true"><?php echo $faite ? ueb_icone( 'check', 14 ) : ( $courante && $rejete ? ueb_icone( 'alerte', 14 ) : (int) $n ); ?></span>
				<span class="qf-rail__libelle"><?php echo esc_html( $libelle ); ?><span class="sr"><?php echo $faite ? ' : franchie' : ( $courante ? ' : en cours' : ' : à venir' ); ?></span></span>
				<span class="qf-rail__detail"><?php echo esc_html( $detail ); ?></span>
			</li>
		<?php endforeach; ?>
	</ol>

	<div class="qf-poste">

		<div class="qf-poste__principal">
			<section class="qf-platine" aria-labelledby="qf-platine-titre" data-qf-platine>
				<header class="qf-platine__tete">
					<div class="qf-platine__titre">
						<h2 id="qf-platine-titre"><?php echo $nb_recus > 1 ? 'Reçus de paiement' : 'Reçu de paiement'; ?></h2>
						<?php if ( $dernier_recu ) : ?>
							<p data-qf-legende><?php echo esc_html( ueb_libelle_objet_recu( $dernier_recu, $fiche->type ) ); ?>, envoyé le <?php echo esc_html( $date_heure( $dernier_recu->date_envoi ) ); ?></p>
						<?php else : ?>
							<p>Rien n’a encore été envoyé.</p>
						<?php endif; ?>
					</div>
					<?php if ( $recus ) : ?>
						<div class="qf-outils" role="toolbar" aria-label="Affichage du reçu" data-qf-outils hidden>
							<button type="button" class="qf-outil" data-qf-zoom="-1" aria-label="Réduire" title="Réduire"><?php echo ueb_icone( 'moins', 18 ); ?></button>
							<button type="button" class="qf-outil qf-outil--niveau" data-qf-ajuster aria-label="Ajuster à la zone" title="Ajuster à la zone"><span data-qf-niveau>100 %</span></button>
							<button type="button" class="qf-outil" data-qf-zoom="1" aria-label="Agrandir" title="Agrandir"><?php echo ueb_icone( 'plus', 18 ); ?></button>
							<span class="qf-outils__separateur" aria-hidden="true"></span>
							<button type="button" class="qf-outil" data-qf-pivoter aria-label="Pivoter d’un quart de tour" title="Pivoter d’un quart de tour"><?php echo ueb_icone( 'pivoter', 18 ); ?></button>
						</div>
					<?php endif; ?>
				</header>

				<?php if ( $nb_recus > 1 ) : ?>
					<nav class="qf-choix" aria-label="Choisir un reçu">
						<?php foreach ( $recus_vus as $i => $r ) : ?>
							<a href="#qf-recu-<?php echo (int) $r->id; ?>" data-qf-choix<?php echo 0 === $i ? ' aria-current="true"' : ''; ?>><?php echo esc_html( ueb_libelle_objet_recu( $r, $fiche->type ) ); ?><small><?php echo esc_html( mysql2date( 'd/m/Y', $r->date_envoi ) ); ?></small></a>
						<?php endforeach; ?>
					</nav>
				<?php endif; ?>
				<p class="sr" role="status" data-qf-annonce></p>

				<?php if ( ! $recus ) : ?>
					<div class="qf-scene qf-scene--vide">
						<div class="qf-vide">
							<?php ueb_animation( 'tampon', array( 'etat' => 'attente', 'sigle' => $sigle, 'date' => '', 'titre' => 'Aucun reçu', 'legende' => "pour l’instant" ), 'qf-vide__tampon', 'Emplacement du tampon : aucun reçu envoyé', '' ); ?>
							<p>Le reçu s’affichera ici dès que l’étudiant l’aura envoyé depuis son espace. Tu pourras alors le comparer au quitus.</p>
						</div>
					</div>
				<?php else : ?>
					<?php foreach ( $recus_vus as $i => $r ) :
						$url   = ueb_url_recu( $r->id );
						$objet = ueb_libelle_objet_recu( $r, $fiche->type );
						$pdf   = 'application/pdf' === $r->type_mime; ?>
						<figure class="qf-scene<?php echo $pdf ? ' qf-scene--pdf' : ''; ?>" id="qf-recu-<?php echo (int) $r->id; ?>" data-qf-recu data-legende="<?php echo esc_attr( $objet . ', envoyé le ' . $date_heure( $r->date_envoi ) ); ?>" data-fichier="<?php echo esc_attr( $r->nom_original ); ?>" data-ouvrir="<?php echo esc_url( $url ); ?>" data-telecharger="<?php echo esc_url( ueb_url_recu( $r->id, true ) ); ?>"<?php echo $pdf ? ' data-pdf' : ''; ?><?php echo $pdf ? '' : ' tabindex="0" aria-label="' . esc_attr( 'Reçu ' . $objet . ' : glisse pour déplacer, double-clic pour agrandir' ) . '"'; ?>>
							<div class="qf-feuille" data-qf-feuille>
								<?php if ( $pdf ) : ?>
									<iframe src="<?php echo esc_url( $url ); ?>" title="<?php echo esc_attr( 'Reçu de paiement : ' . $objet ); ?>" loading="lazy"></iframe>
								<?php else : ?>
									<img src="<?php echo esc_url( $url ); ?>" alt="<?php echo esc_attr( 'Reçu de paiement : ' . $objet ); ?>" draggable="false" <?php echo 0 === $i ? 'fetchpriority="high"' : 'loading="lazy"'; ?>>
								<?php endif; ?>
								<?php if ( 0 === $i ) : ?>
									<?php if ( $decide ) : $date_tampon = mysql2date( 'd.m.Y', $fiche->date_verification ); ?>
										<div class="qf-tampon" data-qf-tampon-pose>
											<?php ueb_animation( 'tampon', $tampon_props( $statut, $date_tampon ), 'qf-tampon__animation animation--fige', ( $verifie ? 'Tampon de la scolarité : paiement vérifié le ' : 'Tampon de la scolarité : dossier à corriger depuis le ' ) . mysql2date( 'd/m/Y', $fiche->date_verification ), $tampon_repli( $statut, $date_tampon ) ); ?>
										</div>
									<?php endif; ?>
									<?php if ( $peut_decider && $a_controler ) : /* tampons prêts à frapper, montés à la décision */ ?>
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
						<p class="qf-fichier" data-qf-fichier title="<?php echo esc_attr( $dernier_recu->nom_original ); ?>"><?php echo ueb_icone( 'image', 16 ); ?><span><?php echo esc_html( $dernier_recu->nom_original ); ?></span></p>
						<div class="qf-platine__actions">
							<a class="qf-bouton qf-bouton--verre" href="<?php echo esc_url( ueb_url_recu( $dernier_recu->id ) ); ?>" target="_blank" rel="noopener" data-qf-ouvrir><?php echo ueb_icone( 'oeil', 18 ); ?>Ouvrir<span class="sr"> le reçu dans un nouvel onglet</span></a>
							<a class="qf-bouton qf-bouton--verre" href="<?php echo esc_url( ueb_url_recu( $dernier_recu->id, true ) ); ?>" data-qf-telecharger><?php echo ueb_icone( 'telecharger', 18 ); ?>Télécharger<span class="sr"> le reçu</span></a>
						</div>
					</footer>
				<?php endif; ?>
			</section>

			<section class="qf-panneau qf-infos" aria-labelledby="qf-infos-titre">
				<header class="qf-panneau__tete">
					<h2 id="qf-infos-titre">Informations du quitus</h2>
					<p>Les données imprimées sur le document de l’étudiant.</p>
				</header>
				<dl class="qf-infos__liste">
					<div><dt>Date et lieu de naissance</dt><dd><?php echo esc_html( mysql2date( 'd/m/Y', $fiche->date_naissance ) . ' à ' . $fiche->lieu_naissance ); ?></dd></div>
					<div><dt>Sexe</dt><dd><?php echo 'F' === $fiche->sexe ? 'Féminin' : 'Masculin'; ?></dd></div>
					<div><dt>Nationalité</dt><dd><?php echo esc_html( $fiche->nationalite ); ?></dd></div>
					<div><dt>Établissement</dt><dd><?php echo esc_html( $etab_fiche['fr'] ?? $fiche->etablissement ); ?></dd></div>
					<div><dt>Filière</dt><dd><?php echo esc_html( $fiche->departement ); ?></dd></div>
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

		<div class="qf-poste__cote">
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

			<aside class="qf-controle qf-controle--<?php echo esc_attr( $statut ); ?>" id="qf-decision" aria-labelledby="qf-controle-titre" data-qf-controle>
				<h2 id="qf-controle-titre" class="sr">Contrôle du paiement</h2>

				<div class="qf-attendu">
					<p class="qf-attendu__libelle">Montant attendu</p>
					<p class="qf-attendu__montant"><?php echo esc_html( ueb_formater_montant( $fiche->montant ) ); ?> <span>FCFA</span></p>
					<p class="qf-attendu__nature"><?php echo esc_html( ueb_libelle_type_quitus( $type_fiche ) . ( $modalite ? ', ' . $modalite : '' ) ); ?></p>
					<?php if ( $fiche->moyen_paiement ) : ?>
						<p class="qf-attendu__lieu"><?php echo ueb_icone( 'lieu', 15 ); ?>Payé via <?php echo esc_html( $fiche->moyen_paiement ); ?></p>
					<?php endif; ?>
				</div>

				<?php if ( $verifie ) : ?>
					<div class="qf-verdict qf-verdict--verifie">
						<span class="qf-verdict__icone" aria-hidden="true"><?php echo ueb_icone( 'check', 20 ); ?></span>
						<div><p class="qf-verdict__titre">Paiement vérifié</p><p><?php echo $decideur ? 'Par ' . esc_html( $decideur->display_name ) . ', l' : 'L'; ?>e <?php echo esc_html( $date_heure( $fiche->date_verification ) ); ?>.</p></div>
					</div>
				<?php elseif ( $rejete ) : ?>
					<div class="qf-verdict qf-verdict--rejete">
						<span class="qf-verdict__icone" aria-hidden="true"><?php echo ueb_icone( 'alerte', 20 ); ?></span>
						<div><p class="qf-verdict__titre">Renvoyé à l’étudiant</p><p><?php echo $decideur ? 'Par ' . esc_html( $decideur->display_name ) . ', l' : 'L'; ?>e <?php echo esc_html( $date_heure( $fiche->date_verification ) ); ?>.</p><blockquote><?php echo esc_html( $fiche->motif_rejet ); ?></blockquote></div>
					</div>
				<?php elseif ( ! $recus ) : ?>
					<div class="qf-verdict">
						<span class="qf-verdict__icone" aria-hidden="true"><?php echo ueb_icone( 'horloge', 20 ); ?></span>
						<div><p class="qf-verdict__titre">En attente du paiement</p><p>La vérification s’ouvrira quand l’étudiant aura envoyé son reçu.</p></div>
					</div>
				<?php endif; ?>

				<?php if ( $peut_decider && $a_controler ) : ?>
					<fieldset class="qf-points" data-qf-points>
						<legend>Compare le reçu et l’original</legend>
						<?php foreach ( $points as $p => list( $libelle, $valeur, $icone ) ) : ?>
							<label class="qf-point">
								<input type="checkbox" data-qf-point>
								<span class="qf-point__case" aria-hidden="true"><?php echo ueb_icone( 'check', 14 ); ?></span>
								<span class="qf-point__texte"><span class="qf-point__libelle"><?php echo esc_html( $libelle ); ?></span><span class="qf-point__valeur"><?php echo esc_html( $valeur ); ?></span></span>
							</label>
						<?php endforeach; ?>
					</fieldset>
					<div class="qf-progression" data-qf-progression hidden>
						<span class="qf-progression__piste" aria-hidden="true"><i data-qf-jauge></i></span>
						<p aria-live="polite" data-qf-compte>Aucun point contrôlé sur <?php echo count( $points ); ?></p>
					</div>
				<?php else : ?>
					<dl class="qf-releve">
						<?php foreach ( array_slice( $points, 0, 3 ) as list( $libelle, $valeur, $icone ) ) : ?>
							<div><dt><?php echo ueb_icone( $icone, 16 ); ?><?php echo esc_html( $libelle ); ?></dt><dd><?php echo esc_html( $valeur ); ?></dd></div>
						<?php endforeach; ?>
					</dl>
				<?php endif; ?>

				<?php if ( ! $peut_decider ) : ?>
					<p class="qf-note"><?php echo ueb_icone( 'cadenas', 16 ); ?>Consultation seule : ton rôle ne permet pas de rendre une décision.</p>
				<?php else : ?>
					<div class="qf-decision">
						<?php if ( $a_controler ) : ?>
							<form method="post" action="<?php echo $adresse; ?>" data-qf-tamponner="verifie"
								data-confirmer="<?php echo esc_attr( sprintf( 'Le paiement de %s de %s sera marqué comme vérifié. L’étudiant le verra dans son espace.', $montant, $nom_complet ) ); ?>"
								data-confirmer-titre="Valider ce paiement ?" data-confirmer-bouton="Valider le paiement" data-confirmer-ton="enregistrer">
								<?php $champs_decision( 'verifie' ); ?>
								<button class="qf-bouton qf-bouton--valider" type="submit"><?php echo ueb_icone( 'tampon', 20 ); ?>Valider le paiement</button>
							</form>
						<?php elseif ( ! $verifie ) : ?>
							<button class="qf-bouton qf-bouton--valider" type="button" disabled aria-describedby="qf-sans-recu"><?php echo ueb_icone( 'tampon', 20 ); ?>Valider le paiement</button>
							<p class="qf-aide" id="qf-sans-recu">Disponible dès l’envoi du reçu.</p>
						<?php endif; ?>

						<?php if ( ! $verifie ) : ?>
							<details class="qf-renvoi">
								<summary><?php echo ueb_icone( $rejete ? 'crayon' : 'alerte', 17 ); ?><?php echo $rejete ? 'Modifier le motif du renvoi' : 'Signaler un problème'; ?><?php echo ueb_icone( 'chevron', 16, 'qf-renvoi__chevron' ); ?></summary>
								<form method="post" action="<?php echo $adresse; ?>" class="qf-renvoi__formulaire"<?php if ( ! $rejete ) : ?><?php echo $recus ? ' data-qf-tamponner="rejete"' : ''; ?> data-confirmer="L’étudiant verra ton motif dans son espace et devra envoyer un nouveau reçu." data-confirmer-titre="Renvoyer le dossier à l’étudiant ?" data-confirmer-bouton="Renvoyer à l’étudiant"<?php endif; ?>>
									<?php $champs_decision( 'rejete' ); ?>
									<div class="qf-motifs" data-qf-motifs hidden>
										<?php foreach ( $motifs as $court => $phrase ) : ?>
											<button type="button" class="qf-motif" data-qf-motif="<?php echo esc_attr( $phrase ); ?>"><?php echo esc_html( $court ); ?></button>
										<?php endforeach; ?>
									</div>
									<label class="qf-champ">
										<span class="qf-champ__libelle">Motif du renvoi</span>
										<textarea name="motif" rows="3" required minlength="5" maxlength="255" placeholder="Explique ce que l’étudiant doit corriger." aria-describedby="qf-motif-aide"><?php echo esc_textarea( $fiche->motif_rejet ?? '' ); ?></textarea>
									</label>
									<p class="qf-aide" id="qf-motif-aide">L’étudiant lira ce message dans son espace (5 à 255 caractères).</p>
									<button class="qf-bouton qf-bouton--danger" type="submit"><?php echo ueb_icone( 'envoyer', 18 ); ?><?php echo $rejete ? 'Mettre à jour le motif' : 'Renvoyer à l’étudiant'; ?></button>
								</form>
							</details>
						<?php endif; ?>

						<?php if ( $decide && $recus ) : ?>
							<form method="post" action="<?php echo $adresse; ?>" class="qf-annuler"
								data-confirmer="Le dossier reviendra parmi les reçus à vérifier et la décision actuelle sera effacée."
								data-confirmer-titre="Annuler la décision ?" data-confirmer-bouton="Annuler la décision" data-confirmer-annuler="Garder la décision">
								<?php $champs_decision( 'recu_envoye' ); ?>
								<button class="qf-bouton qf-bouton--discret" type="submit"><?php echo ueb_icone( 'fleche-g', 17 ); ?>Annuler la décision</button>
							</form>
						<?php endif; ?>
					</div>
					<p class="qf-note"><?php echo ueb_icone( 'bouclier', 16 ); ?>Chaque décision est enregistrée à ton nom.</p>
				<?php endif; ?>
			</aside>
		</div>
	</div>
</div>
