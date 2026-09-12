<?php
/**
 * Tableau de bord étudiant : bandeau d'accueil avec les chiffres de l'année,
 * prochaine étape de l'inscription (quatre étapes, celle en cours mise en
 * avant avec son action), liste des quitus avec leur avancement, et un bouton
 * « Réglages » qui déplie le compte et les contacts de la scolarité.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

$compte = ueb_compte_courant();
$quitus = ueb_quitus_du_compte( $compte->id );
$annee  = ueb_annee_academique();

/* Avancement d'un quitus : nombre d'étapes franchies sur quatre. */
$avancement = static fn( $q ) => array( 'genere' => 1, 'rejete' => 2, 'recu_envoye' => 3, 'verifie' => 4 )[ $q->statut ] ?? 1;

/* Quitus à suivre en priorité : à corriger, puis à payer, puis en vérification, sinon le plus récent. */
$focus = null;
foreach ( array( 'rejete', 'genere', 'recu_envoye' ) as $statut ) {
	foreach ( $quitus as $q ) {
		if ( $statut === $q->statut ) {
			$focus = $q;
			break 2;
		}
	}
}
$focus = $focus ?: ( $quitus[0] ?? null );

/* Chiffres de l'année en cours. */
$de_lannee = array_filter( $quitus, static fn( $q ) => $annee['code'] === $q->annee_academique );
$verifie   = array_sum( array_map( static fn( $q ) => 'verifie' === $q->statut ? (int) $q->montant : 0, $de_lannee ) );
$attente   = array_sum( array_map( static fn( $q ) => in_array( $q->statut, array( 'genere', 'recu_envoye', 'rejete' ), true ) ? (int) $q->montant : 0, $de_lannee ) );

/* Prénom et établissement : ceux du dernier quitus, sinon ceux de la préinscription. */
$preinscription = $compte->numero_dossier ? ueb_preinscription_par_dossier( $compte->numero_dossier ) : null;
$prenom         = trim( (string) ( $quitus[0]->prenom ?? ( $preinscription->prenom ?? '' ) ) );
$prenom         = $prenom ? mb_convert_case( strtok( $prenom, ' ' ), MB_CASE_TITLE ) : '';
$etab_focus     = ueb_etablissement( $focus->etablissement ?? ( $preinscription->etablissement ?? '' ) );

/* Les quatre étapes ; $en_cours va de 1 à 4, 5 quand tout est fait. */
$etapes   = array(
	array( 'titre' => 'Quitus généré', 'texte' => 'Télécharge-le et imprime la page : elle compte 4 coupons.' ),
	array( 'titre' => 'Tamponné et payé', 'texte' => 'Fais-le tamponner à la scolarité, puis paie à la ' . UEB_BANQUE['nom'] . '.' ),
	array( 'titre' => 'Reçu envoyé', 'texte' => 'Envoie ici la photo de ton reçu bancaire.' ),
	array( 'titre' => 'Vérifié', 'texte' => 'La scolarité contrôle les originaux et valide ton paiement.' ),
);
$en_cours = $focus ? array( 'genere' => 2, 'rejete' => 3, 'recu_envoye' => 4, 'verifie' => 5 )[ $focus->statut ] : 1;

$url_nouveau = ueb_url( 'mon-espace/quitus' );
$url_pdf     = $focus ? ueb_url( 'mon-espace/quitus/' . $focus->numero . '/pdf' ) : '';
$url_recus   = $focus ? ueb_url( 'mon-espace/recus/' . $focus->numero ) : '';

/* Texte et actions de la prochaine étape selon l'état du quitus suivi. */
if ( ! $focus ) {
	$prochaine = array( 'titre' => 'Prépare ton premier quitus', 'texte' => 'Choisis ton établissement, vérifie tes informations et indique le montant de la tranche que tu paies. Ton quitus est prêt tout de suite en PDF.' );
} elseif ( 'genere' === $focus->statut ) {
	$prochaine = array( 'titre' => 'Fais tamponner ton quitus, puis paie', 'texte' => 'Imprime ton quitus, fais-le tamponner à la scolarité de ton établissement et paie à la ' . UEB_BANQUE['nom'] . '. Envoie ensuite ici la photo de ton reçu.' );
} elseif ( 'rejete' === $focus->statut ) {
	$prochaine = array( 'titre' => 'Ton reçu est à corriger', 'texte' => 'La scolarité a signalé un problème sur ton reçu. Lis le motif ci-dessous et renvoie une photo lisible.' );
} elseif ( 'recu_envoye' === $focus->statut ) {
	$prochaine = array( 'titre' => 'Ton reçu est en cours de vérification', 'texte' => UEB_STATUTS_QUITUS['recu_envoye']['aide'] );
} else {
	$prochaine = array( 'titre' => 'Paiement vérifié', 'texte' => sprintf( 'La scolarité a validé ton paiement (%s). Garde ton quitus tamponné et ton reçu.', mb_strtolower( ueb_libelle_tranche( $focus->tranche ) ) ) );
}

/* Après une tranche 1 vérifiée, proposer la tranche 2 si elle n'existe pas encore cette année. */
$propose_tranche_2 = $focus && 'verifie' === $focus->statut && 1 === (int) $focus->tranche && ! array_filter(
	$quitus,
	static fn( $q ) => in_array( (int) $q->tranche, array( 2, 3 ), true ) && 'rejete' !== $q->statut && $annee['code'] === $q->annee_academique
);

/* Contacts : la scolarité de l'établissement si elle en a, sinon l'université. */
$contact  = ( $etab_focus && ( $etab_focus['tel'] || $etab_focus['email'] ) ) ? $etab_focus : UEB_UNIVERSITE;
$service  = $contact === UEB_UNIVERSITE ? UEB_UNIVERSITE['fr'] : 'Scolarité : ' . $etab_focus['fr'];
$lien_tel = static function ( $numero ) {
	$chiffres = preg_replace( '/\D+/', '', $numero );
	return 'tel:+' . ( str_starts_with( $chiffres, '237' ) ? $chiffres : '237' . $chiffres );
};

ueb_page_debut( array( 'titre' => 'Mon espace', 'variante' => 'espace' ) );
?>
<main id="contenu" class="espace">
	<section class="espace__bandeau">
		<div class="conteneur espace__bandeau-rangee">
			<div>
				<p class="espace__annee">Inscriptions <?php echo esc_html( $annee['libelle'] ); ?></p>
				<h1><?php echo esc_html( $prenom ? 'Bonjour ' . $prenom : 'Bonjour' ); ?></h1>
				<?php if ( $etab_focus ) : ?>
					<p class="espace__etab"><img src="<?php echo esc_url( ueb_logo_url( $etab_focus['sigle'] ) ); ?>" alt="" width="34" height="34"><?php echo esc_html( $etab_focus['fr'] ); ?></p>
				<?php else : ?>
					<p class="espace__etab">Ton espace pour préparer tes quitus et envoyer tes reçus.</p>
				<?php endif; ?>
			</div>
			<a class="btn btn--clair" href="<?php echo esc_url( $url_nouveau ); ?>"><?php echo ueb_icone( 'plus', 18 ); ?>Nouveau quitus</a>
		</div>

		<?php if ( $de_lannee ) : ?>
			<div class="conteneur">
				<dl class="espace__chiffres">
					<div>
						<dt>Quitus <?php echo esc_html( $annee['libelle'] ); ?></dt>
						<dd><?php echo count( $de_lannee ); ?></dd>
					</div>
					<div>
						<dt>Paiement vérifié</dt>
						<dd><?php echo esc_html( ueb_formater_montant( $verifie ) ); ?> <span>FCFA</span></dd>
					</div>
					<div>
						<dt>En attente de vérification</dt>
						<dd><?php echo esc_html( ueb_formater_montant( $attente ) ); ?> <span>FCFA</span></dd>
					</div>
				</dl>
			</div>
		<?php endif; ?>

		<svg class="espace__nuages" viewBox="0 0 1200 40" preserveAspectRatio="none" aria-hidden="true" focusable="false">
			<path class="espace__nuages-halo" transform="translate(0 -8)" d="M0 40V30A70 70 0 0 1 90 28A80 80 0 0 1 200 31A65 65 0 0 1 290 27A90 90 0 0 1 410 30A70 70 0 0 1 505 28A85 85 0 0 1 620 31A65 65 0 0 1 710 27A80 80 0 0 1 820 30A70 70 0 0 1 915 28A90 90 0 0 1 1035 31A70 70 0 0 1 1130 28A60 60 0 0 1 1200 30V48H0Z"/>
			<path d="M0 40V30A70 70 0 0 1 90 28A80 80 0 0 1 200 31A65 65 0 0 1 290 27A90 90 0 0 1 410 30A70 70 0 0 1 505 28A85 85 0 0 1 620 31A65 65 0 0 1 710 27A80 80 0 0 1 820 30A70 70 0 0 1 915 28A90 90 0 0 1 1035 31A70 70 0 0 1 1130 28A60 60 0 0 1 1200 30V40Z"/>
		</svg>
	</section>

	<div class="conteneur espace__grille">
		<?php ueb_afficher_flash(); ?>

		<details class="reglages">
			<summary class="reglages__bouton"><?php echo ueb_icone( 'reglages', 18 ); ?><span>Réglages</span><?php echo ueb_icone( 'chevron', 16, 'reglages__chevron' ); ?></summary>
			<div class="reglages__panneau carte">
				<section class="encart" aria-labelledby="compte-titre">
					<h2 class="encart__titre" id="compte-titre"><?php echo ueb_icone( 'utilisateur', 20 ); ?>Mon compte</h2>
					<dl class="encart__fiche">
						<?php if ( $compte->matricule ) : ?>
							<div><dt>Matricule</dt><dd><?php echo esc_html( $compte->matricule ); ?></dd></div>
						<?php endif; ?>
						<?php if ( $compte->numero_dossier ) : ?>
							<div><dt>N° de dossier</dt><dd><?php echo esc_html( $compte->numero_dossier ); ?></dd></div>
						<?php endif; ?>
						<div><dt>Téléphone</dt><dd><?php echo esc_html( ueb_formater_telephone( $compte->telephone ) ); ?></dd></div>
						<div><dt>Compte créé le</dt><dd><?php echo esc_html( mysql2date( 'j F Y', $compte->date_creation ) ); ?></dd></div>
					</dl>
					<p class="encart__note">Mot de passe découvert ? Change-le : tes autres sessions seront fermées.</p>
					<a class="btn btn--fantome btn--petit" href="<?php echo esc_url( ueb_url( 'mon-espace/securite' ) ); ?>"><?php echo ueb_icone( 'cle', 16 ); ?>Sécurité du compte</a>
				</section>

				<section class="encart" aria-labelledby="aide-titre">
					<h2 class="encart__titre" id="aide-titre"><?php echo ueb_icone( 'info', 20 ); ?>Besoin d’aide ?</h2>
					<p class="encart__service"><?php echo esc_html( $service ); ?></p>
					<ul class="encart__contacts">
						<?php foreach ( array_filter( array_map( 'trim', explode( '/', (string) $contact['tel'] ) ) ) as $numero ) : ?>
							<li><?php echo ueb_icone( 'telephone', 16 ); ?><a href="<?php echo esc_attr( $lien_tel( $numero ) ); ?>"><?php echo esc_html( $numero ); ?></a></li>
						<?php endforeach; ?>
						<?php if ( $contact['email'] ) : ?>
							<li><?php echo ueb_icone( 'courriel', 16 ); ?><a href="mailto:<?php echo esc_attr( $contact['email'] ); ?>"><?php echo esc_html( $contact['email'] ); ?></a></li>
						<?php endif; ?>
						<li><?php echo ueb_icone( 'lieu', 16 ); ?><?php echo esc_html( $contact['bp'] ); ?></li>
					</ul>
				</section>
			</div>
		</details>

		<section class="etape carte" aria-labelledby="etape-titre">
			<p class="etape__compteur"><span class="etape__pastille"></span><?php echo $en_cours > 4 ? 'Parcours terminé' : sprintf( 'Étape %d sur 4', (int) $en_cours ); ?></p>
			<ol class="etapes" aria-label="Avancement de ton inscription">
				<?php foreach ( $etapes as $n => $e ) :
					$numero = $n + 1;
					$classe = $numero < $en_cours ? 'est-faite' : ( $numero === $en_cours ? 'est-en-cours' . ( $focus && 'rejete' === $focus->statut ? ' est-bloquee' : '' ) : '' );
					?>
					<li class="<?php echo esc_attr( $classe ); ?>"<?php echo $numero === $en_cours ? ' aria-current="step"' : ''; ?>>
						<span class="etapes__puce"><?php echo $numero < $en_cours ? ueb_icone( 'check', 18 ) : (int) $numero; // phpcs:ignore -- SVG interne ?></span>
						<b><?php echo esc_html( $e['titre'] ); ?></b>
						<small><?php echo esc_html( $e['texte'] ); ?></small>
					</li>
				<?php endforeach; ?>
			</ol>

			<div class="etape__corps">
				<?php if ( $focus ) : ?>
					<p class="etape__quitus"><img src="<?php echo esc_url( ueb_logo_url( $focus->etablissement ) ); ?>" alt="" width="26" height="26"><span>Quitus n° <b><?php echo esc_html( $focus->numero ); ?></b>, <?php echo esc_html( mb_strtolower( ueb_detail_quitus( $focus ) ) ); ?>, <span class="etape__montant"><?php echo esc_html( ueb_formater_montant( $focus->montant ) ); ?> FCFA</span></span></p>
				<?php endif; ?>
				<h2 id="etape-titre"><?php echo esc_html( $prochaine['titre'] ); ?></h2>
				<p class="etape__texte"><?php echo esc_html( $prochaine['texte'] ); ?></p>
				<?php if ( $focus && 'rejete' === $focus->statut && $focus->motif_rejet ) : ?>
					<div class="alerte alerte--erreur"><?php echo ueb_icone( 'alerte', 20 ); ?><p><b>Motif de la scolarité :</b> <?php echo esc_html( $focus->motif_rejet ); ?></p></div>
				<?php endif; ?>

				<div class="etape__actions">
					<?php if ( ! $focus ) : ?>
						<a class="btn btn--primaire" href="<?php echo esc_url( $url_nouveau ); ?>">Préparer mon quitus<?php echo ueb_icone( 'fleche', 18 ); ?></a>
					<?php else : ?>
						<?php if ( 'genere' === $focus->statut ) : ?>
							<a class="btn btn--primaire" href="<?php echo esc_url( $url_recus ); ?>"><?php echo ueb_icone( 'envoyer', 18 ); ?>Envoyer mon reçu</a>
						<?php elseif ( 'rejete' === $focus->statut ) : ?>
							<a class="btn btn--primaire" href="<?php echo esc_url( $url_recus ); ?>"><?php echo ueb_icone( 'envoyer', 18 ); ?>Renvoyer mon reçu</a>
						<?php elseif ( 'recu_envoye' === $focus->statut ) : ?>
							<a class="btn btn--fantome" href="<?php echo esc_url( $url_recus ); ?>"><?php echo ueb_icone( 'recu', 18 ); ?>Voir mes reçus</a>
						<?php elseif ( $propose_tranche_2 ) : ?>
							<a class="btn btn--primaire" href="<?php echo esc_url( $url_nouveau ); ?>"><?php echo ueb_icone( 'plus', 18 ); ?>Préparer la tranche 2</a>
						<?php endif; ?>
						<a class="btn btn--fantome" href="<?php echo esc_url( $url_pdf ); ?>" target="_blank" rel="noopener"><?php echo ueb_icone( 'telecharger', 18 ); ?>Télécharger le PDF</a>
					<?php endif; ?>
				</div>
			</div>
		</section>

		<?php if ( $quitus ) : ?>
			<section class="mes-quitus" aria-labelledby="mes-quitus-titre">
				<h2 id="mes-quitus-titre">Mes quitus <span><?php echo count( $quitus ); ?></span></h2>
				<ul class="mes-quitus__liste">
					<?php foreach ( $quitus as $q ) :
						$etab  = ueb_etablissement( $q->etablissement );
						$faites = $avancement( $q );
						?>
						<li class="quitus-ligne carte" id="quitus-<?php echo esc_attr( $q->numero ); ?>">
							<img src="<?php echo esc_url( ueb_logo_url( $q->etablissement ) ); ?>" alt="" width="44" height="44">
							<div class="quitus-ligne__infos">
								<h3><?php echo esc_html( $etab['fr'] ?? $q->etablissement ); ?></h3>
								<p>N° <b><?php echo esc_html( $q->numero ); ?></b>, du <?php echo esc_html( mysql2date( 'j F Y', $q->date_creation ) ); ?></p>
							</div>
							<dl class="quitus-ligne__chiffres">
								<?php if ( 'medicaux' === ( $q->type ?? 'droits' ) ) : ?>
									<div><dt>Type</dt><dd>Frais médicaux</dd></div>
								<?php else : ?>
									<div><dt>Tranche</dt><dd><?php echo esc_html( 3 === (int) $q->tranche ? '1 et 2' : (int) $q->tranche ); ?></dd></div>
								<?php endif; ?>
								<div><dt>Montant</dt><dd><?php echo esc_html( ueb_formater_montant( $q->montant ) ); ?> FCFA</dd></div>
							</dl>
							<?php echo ueb_badge_statut( $q->statut ); // phpcs:ignore -- échappé dans la fonction ?>
							<div class="quitus-ligne__jauge" aria-hidden="true">
								<?php for ( $n = 1; $n <= 4; $n++ ) : ?>
									<span class="<?php echo $n <= $faites ? ( 'rejete' === $q->statut && $n === $faites ? 'est-bloquee' : 'est-faite' ) : ''; ?>"></span>
								<?php endfor; ?>
							</div>
							<?php if ( 'rejete' === $q->statut && $q->motif_rejet ) : ?>
								<p class="quitus-ligne__motif alerte alerte--erreur"><?php echo ueb_icone( 'alerte', 18 ); ?><span><b>Motif :</b> <?php echo esc_html( $q->motif_rejet ); ?></span></p>
							<?php endif; ?>
							<div class="quitus-ligne__actions">
								<a class="btn btn--fantome btn--petit" href="<?php echo esc_url( ueb_url( 'mon-espace/quitus/' . $q->numero . '/pdf' ) ); ?>" target="_blank" rel="noopener"><?php echo ueb_icone( 'telecharger', 16 ); ?>PDF</a>
								<?php if ( ueb_quitus_accepte_recus( $q ) || $q->nb_recus ) : ?>
									<a class="btn btn--fantome btn--petit" href="<?php echo esc_url( ueb_url( 'mon-espace/recus/' . $q->numero ) ); ?>"><?php echo ueb_icone( 'recu', 16 ); ?><?php echo $q->nb_recus ? sprintf( 'Mes reçus (%d)', (int) $q->nb_recus ) : 'Envoyer mon reçu'; ?></a>
								<?php endif; ?>
								<?php if ( ueb_quitus_modifiable( $q ) ) : ?>
									<a class="btn btn--lien btn--petit" href="<?php echo esc_url( add_query_arg( 'id', $q->id, $url_nouveau ) ); ?>"><?php echo ueb_icone( 'crayon', 16 ); ?>Modifier</a>
								<?php endif; ?>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>
	</div>
</main>
<?php
ueb_page_fin( 'espace' );
