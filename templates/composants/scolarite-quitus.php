<?php
/**
 * Espace scolarité, fiche d'un quitus (?quitus={id}) : en-tête de l'étudiant,
 * suivi du dossier en quatre étapes, reçus envoyés, informations imprimées,
 * décision et historique. Mêmes composants que la fiche d'un IPES
 * (administration.css, ipes.css) plus assets/css/quitus-fiche.css.
 * Attend $fiche (contrôlé par page-scolarite.php) et $ici.
 */
defined( 'ABSPATH' ) || exit;

$etab_fiche = ueb_etablissement( $fiche->etablissement );
$compte     = ueb_compte_par_id( $fiche->compte_id );
$recus      = ueb_recus_du_quitus( $fiche->id );
$decideur   = $fiche->verifie_par ? get_userdata( $fiche->verifie_par ) : null;
$type_fiche = 'medicaux' === ( $fiche->type ?? 'droits' ) ? 'medicaux' : 'droits';
$rejete     = 'rejete' === $fiche->statut;
$etape      = array( 'genere' => 2, 'recu_envoye' => 3, 'rejete' => 2, 'verifie' => 4 )[ $fiche->statut ] ?? 1;
$peut_decider = ueb_peut( 'ueb_decider_quitus', $fiche->etablissement );
$dernier_recu = $recus ? end( $recus ) : null;

/* Pastille de statut : mêmes couleurs que les bordereaux des IPES. */
$statuts = array(
	'genere'      => array( 'libre', 'horloge' ),
	'recu_envoye' => array( 'envoye', 'envoyer' ),
	'verifie'     => array( 'verifie', 'check' ),
	'rejete'      => array( 'rejete', 'alerte' ),
);
list( $statut_classe, $statut_icone ) = $statuts[ $fiche->statut ] ?? array( 'brouillon', 'info' );

$suivi_note = array(
	'genere'      => 'En attente du paiement de l’étudiant et de son reçu.',
	'recu_envoye' => sprintf( 'Le reçu attend ta vérification depuis %s.', human_time_diff( strtotime( $dernier_recu->date_envoi ?? $fiche->date_modification ), current_time( 'timestamp' ) ) ),
	'rejete'      => 'Reçu renvoyé : l’étudiant doit en envoyer un nouveau.',
	'verifie'     => 'Paiement vérifié : le dossier est complet.',
)[ $fiche->statut ] ?? '';

/* Historique du dossier, du plus ancien au plus récent. */
$historique = array( array( 'date' => $fiche->date_creation, 'icone' => 'fichier', 'texte' => 'Quitus généré par l’étudiant' ) );
foreach ( $recus as $r ) {
	$historique[] = array( 'date' => $r->date_envoi, 'icone' => 'envoyer', 'texte' => 'Reçu envoyé', 'detail' => ueb_libelle_objet_recu( $r, $fiche->type ) );
}
if ( ! empty( $fiche->corrige_le ) ) {
	$historique[] = array( 'date' => $fiche->corrige_le, 'icone' => 'crayon', 'texte' => 'Filière ou niveau corrigé par l’étudiant' );
}
if ( in_array( $fiche->statut, array( 'verifie', 'rejete' ), true ) && $fiche->date_verification ) {
	$historique[] = array(
		'date'   => $fiche->date_verification,
		'icone'  => 'verifie' === $fiche->statut ? 'check' : 'alerte',
		'texte'  => ( 'verifie' === $fiche->statut ? 'Paiement vérifié' : 'Renvoyé à l’étudiant' ) . ( $decideur ? ' par ' . $decideur->display_name : '' ),
		'classe' => $fiche->statut,
	);
}
usort( $historique, static fn( $a, $b ) => strcmp( $a['date'], $b['date'] ) );

/* Champ caché commun aux trois formulaires de décision. */
$champs_decision = static function ( $statut ) use ( $fiche ) {
	ueb_champ_csrf();
	printf( '<input type="hidden" name="ueb_action" value="gestion_statut"><input type="hidden" name="quitus_id" value="%d"><input type="hidden" name="statut" value="%s">', (int) $fiche->id, esc_attr( $statut ) );
};
$adresse_fiche = $ici( array( 'quitus' => $fiche->id ) );
?>
<div class="ipes-zone qf">

	<a class="fil" href="<?php echo $ici( array( 'vue' => 'quitus' ) ); ?>"><?php echo ueb_icone( 'fleche-g', 18 ); ?>Tous les quitus</a>

	<header class="bo-entete ipes-sco-entete qf-entete">
		<div class="bo-entete__texte">
			<div class="adm-tete__identite">
				<span class="bo-avatar ipes-avatar" aria-hidden="true"><?php echo esc_html( ueb_initiales( $fiche->prenom, $fiche->nom ) ); ?></span>
				<div>
					<h1><?php echo esc_html( $fiche->nom . ' ' . $fiche->prenom ); ?></h1>
					<ul class="ipes-reperes">
						<li><img src="<?php echo esc_url( ueb_logo_url( $fiche->etablissement ) ); ?>" alt="" width="18" height="18"><?php echo esc_html( $etab_fiche['sigle'] ?? $fiche->etablissement ); ?></li>
						<li><?php echo ueb_icone( 'fichier', 14 ); ?>Quitus <?php echo esc_html( $fiche->numero ); ?></li>
						<li><?php echo ueb_icone( 'qr', 14 ); ?><?php echo esc_html( $fiche->identifiant ); ?></li>
						<?php if ( $compte && $compte->telephone ) : ?>
							<li><?php echo ueb_icone( 'telephone', 14 ); ?><a href="tel:+237<?php echo esc_attr( $compte->telephone ); ?>"><?php echo esc_html( ueb_formater_telephone( $compte->telephone ) ); ?></a></li>
						<?php endif; ?>
					</ul>
				</div>
			</div>
		</div>
		<div class="qf-entete__actions">
			<span class="ipes-statut ipes-statut--<?php echo esc_attr( $statut_classe ); ?>"><?php echo ueb_icone( $statut_icone, 14 ); ?><?php echo esc_html( UEB_STATUTS_QUITUS[ $fiche->statut ]['libelle'] ?? $fiche->statut ); ?></span>
			<a class="adm-bouton" href="<?php echo $ici( array( 'quitus' => $fiche->id, 'pdf' => 1 ) ); ?>" target="_blank" rel="noopener"><?php echo ueb_icone( 'telecharger', 16 ); ?>PDF du quitus</a>
		</div>
	</header>

	<section class="adm-panneau qf-suivi" aria-labelledby="qf-suivi-titre">
		<header class="adm-panneau__tete">
			<div><h2 id="qf-suivi-titre">Suivi du dossier</h2><?php if ( $suivi_note ) : ?><p><?php echo esc_html( $suivi_note ); ?></p><?php endif; ?></div>
		</header>
		<ol class="qf-etapes<?php echo $rejete ? ' qf-etapes--rejete' : ''; ?>" style="--rempli: <?php echo esc_attr( round( ( $etape - 1 ) / 3, 4 ) ); ?>">
			<?php
			foreach ( array( 'Quitus généré', 'Payé et reçu envoyé', 'Reçu tamponné', 'Vérifié' ) as $i => $libelle ) :
				$n        = $i + 1;
				$courante = $n === $etape;
				$faite    = $n < $etape || 4 === $etape;
				$etat     = $courante && $rejete ? 'à corriger' : ( $faite ? 'franchie' : ( $courante ? 'en cours' : 'à venir' ) );
				$classe   = $courante && $rejete ? 'est-rejetee' : ( $faite ? 'est-faite' : ( $courante ? 'est-courante' : '' ) );
				?>
				<li class="<?php echo esc_attr( $classe ); ?>"<?php echo $courante ? ' aria-current="step"' : ''; ?>>
					<span class="qf-etapes__rond" aria-hidden="true"><?php echo $courante && $rejete ? ueb_icone( 'alerte', 15 ) : ( $faite ? ueb_icone( 'check', 15 ) : (int) $n ); // phpcs:ignore -- icône ou entier ?></span>
					<span class="qf-etapes__libelle"><?php echo esc_html( $courante && $rejete ? 'À corriger' : $libelle ); ?><span class="sr"> : étape <?php echo esc_html( $etat ); ?></span></span>
				</li>
			<?php endforeach; ?>
		</ol>
		<dl class="ipes-faits qf-faits">
			<div><dt>Montant</dt><dd class="qf-montant"><?php echo esc_html( ueb_formater_montant( $fiche->montant ) ); ?> <small>FCFA</small></dd></div>
			<div><dt>Paiement</dt><dd><?php echo esc_html( ueb_libelle_type_quitus( $type_fiche ) ); ?></dd></div>
			<div><dt><?php echo 'medicaux' === $type_fiche ? 'Modalité' : 'Tranche'; ?></dt><dd><?php echo esc_html( 'medicaux' === $type_fiche ? 'Paiement unique' : ueb_libelle_tranche( $fiche->tranche ) ); ?></dd></div>
			<?php if ( ! empty( $fiche->moyen_paiement ) ) : ?><div><dt>Lieu de paiement</dt><dd><?php echo esc_html( $fiche->moyen_paiement ); ?></dd></div><?php endif; ?>
			<div><dt>Généré le</dt><dd><?php echo esc_html( mysql2date( 'j F Y', $fiche->date_creation ) ); ?></dd></div>
		</dl>
	</section>

	<div class="ipes-grille">
		<div class="ipes-pile">

			<section class="adm-panneau" aria-labelledby="qf-recus-titre">
				<header class="adm-panneau__tete">
					<div><h2 id="qf-recus-titre">Reçus envoyés</h2><p>Ouvre chaque reçu et compare-le à l’original présenté par l’étudiant.</p></div>
					<?php if ( $recus ) : ?><span class="qf-compte"><?php echo esc_html( count( $recus ) . ' reçu' . ( count( $recus ) > 1 ? 's' : '' ) ); ?></span><?php endif; ?>
				</header>
				<?php if ( ! $recus ) : ?>
					<div class="bo-vide ipes-vide"><span><?php echo ueb_icone( 'recu', 22 ); ?></span><p><b>Aucun reçu pour l’instant.</b> Il apparaîtra ici dès que l’étudiant l’enverra.</p></div>
				<?php else : ?>
					<ul class="qf-recus">
						<?php foreach ( $recus as $r ) : $url = ueb_url_recu( $r->id ); $objet = ueb_libelle_objet_recu( $r, $fiche->type ); ?>
							<li class="qf-recu">
								<?php /* La vignette double le bouton « Ouvrir » : hors de l'ordre de tabulation. */ ?>
								<a class="qf-recu__vue" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener" tabindex="-1" aria-hidden="true">
									<?php if ( 'application/pdf' === $r->type_mime ) : ?>
										<span class="qf-recu__pdf"><?php echo ueb_icone( 'fichier', 28 ); ?>PDF</span>
									<?php else : ?>
										<img src="<?php echo esc_url( $url ); ?>" alt="" loading="lazy">
									<?php endif; ?>
								</a>
								<div class="qf-recu__infos">
									<p class="qf-recu__objet"><?php echo esc_html( $objet ); ?></p>
									<p class="qf-recu__date">Envoyé le <time datetime="<?php echo esc_attr( mysql2date( 'c', $r->date_envoi ) ); ?>"><?php echo esc_html( mysql2date( 'j F Y à H:i', $r->date_envoi ) ); ?></time></p>
									<p class="qf-recu__fichier" title="<?php echo esc_attr( $r->nom_original ); ?>"><?php echo ueb_icone( 'fichier', 14 ); ?><span><?php echo esc_html( $r->nom_original ); ?></span></p>
									<div class="qf-recu__actions">
										<a class="adm-bouton adm-bouton--petit" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo ueb_icone( 'oeil', 15 ); ?>Ouvrir<span class="sr"> le reçu « <?php echo esc_html( $objet ); ?> » dans un nouvel onglet</span></a>
										<a class="adm-bouton adm-bouton--petit" href="<?php echo esc_url( ueb_url_recu( $r->id, true ) ); ?>"><?php echo ueb_icone( 'telecharger', 15 ); ?>Télécharger<span class="sr"> le reçu « <?php echo esc_html( $objet ); ?> »</span></a>
									</div>
								</div>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</section>

			<section class="adm-panneau" aria-labelledby="qf-infos-titre">
				<header class="adm-panneau__tete">
					<div><h2 id="qf-infos-titre">Informations imprimées</h2><p>Telles qu’elles figurent sur le quitus de l’étudiant.</p></div>
				</header>
				<dl class="ipes-faits qf-infos">
					<div class="qf-infos__large"><dt>Nom(s) et prénom(s)</dt><dd><?php echo esc_html( $fiche->nom . ' ' . $fiche->prenom ); ?></dd></div>
					<div><dt>Né(e) le</dt><dd><?php echo esc_html( mysql2date( 'd/m/Y', $fiche->date_naissance ) . ' à ' . $fiche->lieu_naissance ); ?></dd></div>
					<div><dt>Sexe</dt><dd><?php echo 'F' === $fiche->sexe ? 'Féminin' : 'Masculin'; ?></dd></div>
					<div><dt>Nationalité</dt><dd><?php echo esc_html( $fiche->nationalite ); ?></dd></div>
					<div><dt>Établissement</dt><dd><?php echo esc_html( $etab_fiche['sigle'] ?? $fiche->etablissement ); ?></dd></div>
					<div><dt>Filière</dt><dd><?php echo esc_html( $fiche->departement ); ?></dd></div>
					<div><dt>Niveau</dt><dd><?php echo esc_html( UEB_NIVEAUX_INSCRIPTION[ $fiche->parcours ] ?? $fiche->parcours ); ?></dd></div>
				</dl>
				<?php if ( ! empty( $fiche->corrige_le ) ) : ?>
					<p class="qf-note"><?php echo ueb_icone( 'crayon', 16 ); ?><span><b>Corrigé par l’étudiant le <?php echo esc_html( mysql2date( 'j F Y à H:i', $fiche->corrige_le ) ); ?>.</b> <?php echo esc_html( $fiche->correction ); ?></span></p>
				<?php endif; ?>
			</section>

		</div>
		<div class="ipes-pile">

			<section class="adm-panneau qf-decision qf-decision--<?php echo esc_attr( $fiche->statut ); ?>" aria-labelledby="qf-decision-titre">
				<header class="adm-panneau__tete">
					<div><h2 id="qf-decision-titre">Décision</h2><p>Après la vérification physique des originaux à la scolarité.</p></div>
				</header>

				<?php if ( 'verifie' === $fiche->statut ) : ?>
					<p class="qf-etat qf-etat--verifie"><?php echo ueb_icone( 'check', 18 ); ?><span>Paiement vérifié<?php echo $decideur ? ' par ' . esc_html( $decideur->display_name ) : ''; ?><?php echo $fiche->date_verification ? ' le ' . esc_html( mysql2date( 'j F Y', $fiche->date_verification ) ) : ''; ?>.</span></p>
				<?php elseif ( $rejete ) : ?>
					<p class="qf-etat qf-etat--rejete"><?php echo ueb_icone( 'alerte', 18 ); ?><span><b>Renvoyé à l’étudiant.</b> <?php echo esc_html( $fiche->motif_rejet ); ?></span></p>
				<?php elseif ( ! $recus ) : ?>
					<p class="qf-etat"><?php echo ueb_icone( 'horloge', 18 ); ?><span>En attente d’un reçu de l’étudiant.</span></p>
				<?php endif; ?>

				<?php if ( ! $peut_decider ) : ?>
					<p class="qf-etat"><?php echo ueb_icone( 'info', 18 ); ?><span>Ton rôle permet de consulter ce dossier, pas de rendre la décision.</span></p>
				<?php else : ?>
					<?php if ( $recus && 'verifie' !== $fiche->statut ) : ?>
						<div class="qf-comparer">
							<p class="qf-comparer__titre"><?php echo ueb_icone( 'oeil', 16 ); ?>À comparer à l’original</p>
							<dl>
								<div><dt>Montant</dt><dd><?php echo esc_html( ueb_fcfa( $fiche->montant ) ); ?></dd></div>
								<div><dt>Nom</dt><dd><?php echo esc_html( $fiche->nom . ' ' . $fiche->prenom ); ?></dd></div>
								<div><dt>Date du versement</dt><dd>Sur le reçu</dd></div>
								<div><dt>Référence bancaire</dt><dd>Sur le reçu</dd></div>
							</dl>
						</div>
					<?php endif; ?>

					<div class="qf-actions">
						<?php if ( 'verifie' !== $fiche->statut ) : ?>
							<form method="post" action="<?php echo $adresse_fiche; ?>">
								<?php $champs_decision( 'verifie' ); ?>
								<button class="adm-bouton adm-bouton--primaire qf-bouton" type="submit" <?php disabled( ! $recus ); ?><?php echo $recus ? '' : ' aria-describedby="qf-sans-recu"'; ?>><?php echo ueb_icone( 'check', 18 ); ?>Paiement vérifié</button>
								<?php if ( ! $recus ) : ?><p class="qf-aide" id="qf-sans-recu">Possible dès que l’étudiant aura envoyé son reçu.</p><?php endif; ?>
							</form>
							<p class="qf-ou"><span>ou</span></p>
							<form method="post" action="<?php echo $adresse_fiche; ?>" class="qf-rejet">
								<?php $champs_decision( 'rejete' ); ?>
								<div class="champ">
									<label for="motif">Motif du renvoi</label>
									<textarea id="motif" name="motif" rows="2" maxlength="255" placeholder="Ex. Reçu illisible, montant différent du quitus…" aria-describedby="motif-aide"><?php echo esc_textarea( $fiche->motif_rejet ?? '' ); ?></textarea>
									<p class="champ__aide" id="motif-aide">L’étudiant le lira dans son espace.</p>
								</div>
								<button class="adm-bouton adm-bouton--danger qf-bouton" type="submit"><?php echo ueb_icone( 'croix', 18 ); ?><?php echo $rejete ? 'Mettre à jour le motif' : 'Renvoyer à l’étudiant'; ?></button>
							</form>
						<?php endif; ?>
						<?php if ( in_array( $fiche->statut, array( 'verifie', 'rejete' ), true ) && $recus ) : ?>
							<form method="post" action="<?php echo $adresse_fiche; ?>" class="qf-annuler">
								<?php $champs_decision( 'recu_envoye' ); ?>
								<button class="adm-bouton adm-bouton--petit" type="submit"><?php echo ueb_icone( 'fleche-g', 15 ); ?>Annuler la décision</button>
							</form>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</section>

			<section class="adm-panneau" aria-labelledby="qf-historique-titre">
				<header class="adm-panneau__tete"><div><h2 id="qf-historique-titre">Historique</h2></div></header>
				<ol class="historique qf-historique">
					<?php foreach ( $historique as $h ) : ?>
						<li class="<?php echo esc_attr( 'historique--' . ( $h['classe'] ?? 'neutre' ) ); ?>">
							<span class="historique__puce" aria-hidden="true"><?php echo ueb_icone( $h['icone'], 14 ); ?></span>
							<span class="historique__texte"><?php echo esc_html( $h['texte'] ); ?><?php if ( ! empty( $h['detail'] ) ) : ?> <span class="historique__detail"><?php echo esc_html( $h['detail'] ); ?></span><?php endif; ?></span>
							<time datetime="<?php echo esc_attr( mysql2date( 'c', $h['date'] ) ); ?>"><?php echo esc_html( mysql2date( 'j F Y à H:i', $h['date'] ) ); ?></time>
						</li>
					<?php endforeach; ?>
				</ol>
			</section>

		</div>
	</div>

</div>
