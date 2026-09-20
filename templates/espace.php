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
$vue = sanitize_key( $_GET['vue'] ?? '' );
$vue = in_array( $vue, array( 'quitus', 'recus' ), true ) ? $vue : '';
$quitus = ueb_quitus_du_compte( $compte->id );
$recus_compte = ueb_recus_du_compte( $compte->id );
$recus_par_annee = array();
foreach ( $recus_compte as $recu ) {
	$recus_par_annee[ $recu->annee_academique ][] = $recu;
}
$annee  = ueb_annee_academique();
$dossiers = ueb_dossiers_quitus( $quitus );
$par_annee = array( $annee['code'] => array() );
foreach ( $dossiers as $dossier ) {
	$par_annee[ $dossier['principal']->annee_academique ][] = $dossier;
}
krsort( $par_annee, SORT_STRING );
$actuels = $par_annee[ $annee['code'] ];
$telechargement = ueb_telechargement_a_demarrer( $compte );
$bienvenue = ueb_bienvenue_a_afficher( $compte );

/* Avancement d'un quitus : nombre d'étapes franchies sur quatre. */
$avancement = static fn( $q ) => array( 'genere' => 1, 'rejete' => 2, 'recu_envoye' => 3, 'verifie' => 4 )[ $q->statut ] ?? 1;

/* Suivre uniquement l'année en cours, en tenant compte des deux paiements. */
$focus = null;
$dossier_focus = null;
foreach ( array( 'rejete', 'genere', 'recu_envoye', 'verifie' ) as $statut ) {
	foreach ( $actuels as $dossier ) {
		if ( $statut === $dossier['statut'] ) {
			$dossier_focus = $dossier;
			$focus = clone $dossier['principal'];
			$focus->statut = $dossier['statut'];
			$focus->montant = $dossier['total'];
			foreach ( $dossier['paiements'] as $paiement ) {
				if ( $paiement->statut === $statut ) {
					$paiement_focus = $paiement;
					$focus->motif_rejet = $paiement->motif_rejet;
					break;
				}
			}
			break 2;
		}
	}
}

/* Chiffres de l'année en cours. */
$de_lannee = array_filter( $quitus, static fn( $q ) => $annee['code'] === $q->annee_academique );
$verifie   = array_sum( array_map( static fn( $q ) => 'verifie' === $q->statut ? (int) $q->montant : 0, $de_lannee ) );
$attente   = array_sum( array_map( static fn( $q ) => in_array( $q->statut, array( 'genere', 'recu_envoye', 'rejete' ), true ) ? (int) $q->montant : 0, $de_lannee ) );

/* Prénom et établissement : ceux du dernier quitus, sinon ceux de la préinscription. */
$preinscription = $compte->numero_dossier ? ueb_preinscription_par_dossier( $compte->numero_dossier ) : null;
$prenom         = trim( (string) ( $quitus[0]->prenom ?? ( $preinscription->prenom ?? '' ) ) );
$prenom         = $prenom ? mb_convert_case( strtok( $prenom, ' ' ), MB_CASE_TITLE ) : '';
$etab_focus     = ueb_etablissement( $focus->etablissement ?? ( $quitus[0]->etablissement ?? ( $preinscription->etablissement ?? '' ) ) );

/* Les quatre étapes ; $en_cours va de 1 à 4, 5 quand tout est fait. */
$etapes   = array(
	array( 'titre' => 'Quitus généré', 'texte' => 'Tes documents sont réunis dans un PDF. Chaque quitus compte 4 coupons ; les fiches CMS accompagnent le quitus médical.' ),
	array( 'titre' => 'Tamponné et payé', 'texte' => 'Fais-le tamponner à la scolarité, puis paie à la ' . UEB_BANQUE['nom'] . '.' ),
	array( 'titre' => 'Reçu envoyé', 'texte' => 'Envoie ici la photo de ton reçu bancaire.' ),
	array( 'titre' => 'Vérifié', 'texte' => 'La scolarité contrôle les originaux et valide ton paiement.' ),
);
$en_cours = $focus ? array( 'genere' => 2, 'rejete' => 3, 'recu_envoye' => 4, 'verifie' => 5 )[ $focus->statut ] : 1;

$url_nouveau = ueb_url( 'mon-espace/quitus' );
$url_recus   = $focus ? ueb_url( 'mon-espace/recus/' . $paiement_focus->numero ) : '';

/* Texte et actions de la prochaine étape selon l'état du quitus suivi. */
if ( ! $focus ) {
	$prochaine = array( 'titre' => $quitus ? 'Prépare ton inscription ' . $annee['libelle'] : 'Prépare ton premier quitus', 'texte' => 'Choisis ton établissement, vérifie tes informations et choisis la tranche que tu paies. Le total et les documents sont préparés selon ta situation.' );
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

ueb_page_debut( array( 'titre' => $vue ? ( 'quitus' === $vue ? 'Mes quitus' : 'Mes reçus' ) : 'Mon espace', 'variante' => 'espace', 'classe' => $vue ? 'espace--vue-' . $vue : '' ) );
?>
<main id="contenu" class="espace">
	<section class="espace__bandeau">
		<div class="conteneur espace__bandeau-rangee">
			<div>
				<p class="espace__annee">Inscriptions <?php echo esc_html( $annee['libelle'] ); ?></p>
				<h1><?php echo esc_html( $prenom ? 'Bonjour ' . $prenom : 'Bonjour' ); ?></h1>
				<?php if ( $etab_focus ) : ?>
					<p class="espace__etab"><img src="<?php echo esc_url( ueb_logo_url( $etab_focus['sigle'] ) ); ?>" alt="" width="34" height="34"><?php echo esc_html( $etab_focus['sigle'] ); ?></p>
				<?php else : ?>
					<p class="espace__etab">Ton espace pour préparer tes quitus et envoyer tes reçus.</p>
				<?php endif; ?>
			</div>
		</div>

		<?php if ( $de_lannee ) : ?>
			<div class="conteneur">
				<dl class="espace__chiffres">
					<div>
						<dt>Dossiers <?php echo esc_html( $annee['libelle'] ); ?></dt>
						<dd><?php echo count( $actuels ); ?></dd>
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
		<?php if ( $telechargement ) : ?>
			<div class="telechargement-pret" data-telechargement-auto>
				<?php echo ueb_icone( 'telecharger', 22 ); ?>
				<p><strong>Ton PDF est prêt.</strong> <span data-telechargement-message>Le téléchargement va démarrer. Tu peux aussi utiliser le bouton ci-contre.</span></p>
				<a class="btn btn--fantome btn--petit" data-telechargement-lien download="quitus-<?php echo esc_attr( $telechargement->numero ); ?>.pdf" href="<?php echo esc_url( ueb_url( 'mon-espace/quitus/' . $telechargement->numero . '/pdf' ) ); ?>">Télécharger le PDF</a>
			</div>
		<?php endif; ?>

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
					<?php endif; ?>
				</div>
			</div>
		</section>

		<div class="espace-modules">
			<section class="espace-module carte" id="mes-quitus">
			<a class="espace-module__carte espace-module__carte--quitus" href="<?php echo esc_url( add_query_arg( 'vue', 'quitus', ueb_url( 'mon-espace' ) ) ); ?>">
					<span class="espace-module__voile"></span><span class="espace-module__contenu"><b>Documents d’inscription</b><strong>Mes quitus <em><?php echo count( $dossiers ); ?></em></strong><span class="espace-module__ouvrir">Ouvrir mes quitus</span></span>
			</a>
			<?php if ( 'quitus' === $vue ) : ?>
			<div class="espace-module__panneau">
			<section class="mes-quitus" aria-labelledby="mes-quitus-titre">
				<header class="mes-quitus__entete">
					<div><a class="fil" href="<?php echo esc_url( ueb_url( 'mon-espace' ) ); ?>"><?php echo ueb_icone( 'fleche-g', 16 ); ?>Retour à mon espace</a><p class="mes-quitus__repere">Documents d’inscription</p><h2 id="mes-quitus-titre">Mes quitus <span><?php echo count( $dossiers ); ?></span></h2>
				<p>Tous tes documents réunis dans un PDF par dossier, classés par année académique.</p></div>
				<a class="btn btn--fantome btn--petit" href="<?php echo esc_url( $url_nouveau ); ?>"><?php echo ueb_icone( 'plus', 18 ); ?>Nouveau quitus</a>
			</header>
			<?php foreach ( $par_annee as $annee_code => $dossiers_annee ) :
				$en_cours_annee = $annee_code === $annee['code'];
				?>
				<details class="quitus-annee" data-annee="<?php echo esc_attr( $annee_code ); ?>" <?php echo $en_cours_annee ? 'open' : ''; ?>>
					<summary>
						<span class="quitus-annee__date"><?php echo esc_html( str_replace( '-', ' – ', $annee_code ) ); ?></span>
						<span class="quitus-annee__etat"><?php echo $en_cours_annee ? 'Année en cours' : 'Archives'; ?></span>
						<span class="quitus-annee__compte"><?php echo count( $dossiers_annee ); ?> dossier<?php echo count( $dossiers_annee ) > 1 ? 's' : ''; ?></span>
						<?php echo ueb_icone( 'chevron', 18 ); ?>
					</summary>
					<div class="quitus-annee__contenu">
						<?php if ( $dossiers_annee ) : ?>
							<ul class="mes-quitus__liste">
								<?php foreach ( $dossiers_annee as $dossier ) { require UEB_INSC_DIR . '/templates/composants/quitus-dossier.php'; } ?>
							</ul>
						<?php else : ?>
							<div class="mes-quitus__vide">
								<h3><?php echo $quitus ? 'Une nouvelle année commence' : 'Ton premier dossier commence ici'; ?></h3>
								<p>Aucun quitus pour <?php echo esc_html( $annee['libelle'] ); ?>. Choisis ta formation, ton niveau et ta tranche pour préparer tes documents.</p>
								<a class="btn btn--primaire" href="<?php echo esc_url( $url_nouveau ); ?>">M’inscrire pour <?php echo esc_html( $annee['libelle'] ); ?><?php echo ueb_icone( 'fleche', 18 ); ?></a>
							</div>
						<?php endif; ?>
					</div>
				</details>
			<?php endforeach; ?>
			<p class="mes-quitus__note"><?php echo ueb_icone( 'fichier', 16 ); ?>Tes dossiers restent disponibles d’une année à l’autre.</p>
		</section>
			</div>
			<?php endif; ?>
		</section>

			<section class="espace-module carte" id="mes-recus">
			<a class="espace-module__carte espace-module__carte--recus" href="<?php echo esc_url( add_query_arg( 'vue', 'recus', ueb_url( 'mon-espace' ) ) ); ?>">
					<span class="espace-module__voile"></span><span class="espace-module__contenu"><b>Suivi des paiements</b><strong>Mes reçus <em><?php echo count( $recus_compte ); ?></em></strong><span class="espace-module__ouvrir">Ouvrir mes reçus</span></span>
			</a>
			<?php if ( 'recus' === $vue ) : ?>
			<div class="espace-module__panneau espace-module__panneau--recus">
				<a class="fil mes-recus__retour" href="<?php echo esc_url( ueb_url( 'mon-espace' ) ); ?>"><?php echo ueb_icone( 'fleche-g', 16 ); ?>Retour à mon espace</a>
				<?php if ( ! $recus_par_annee ) : ?>
					<div class="mes-recus__vide"><h2>Aucun reçu envoyé</h2><p>Après ton paiement, envoie une photo ou un scan depuis le quitus concerné. Tu retrouveras ici une copie téléchargeable et son statut.</p></div>
				<?php else : foreach ( $recus_par_annee as $recus_annee => $liste_recus ) : ?>
					<section class="mes-recus__annee" aria-labelledby="recus-<?php echo esc_attr( $recus_annee ); ?>"><h2 id="recus-<?php echo esc_attr( $recus_annee ); ?>"><?php echo esc_html( str_replace( '-', ' – ', $recus_annee ) ); ?> <small><?php echo count( $liste_recus ); ?> reçu<?php echo count( $liste_recus ) > 1 ? 's' : ''; ?></small></h2><ul class="mes-recus__liste">
						<?php foreach ( $liste_recus as $recu ) : $statut = $recu->statut_quitus; $statut_libelle = 'verifie' === $statut ? 'Validé par la scolarité' : ( 'rejete' === $statut ? 'À corriger' : 'En cours de vérification' ); ?>
							<li class="mes-recus__item"><a class="mes-recus__telecharger" href="<?php echo esc_url( ueb_url( 'recu/' . $recu->id ) ); ?>" target="_blank" rel="noopener" download><?php echo ueb_icone( 'telecharger', 18 ); ?><span>Télécharger</span></a><div><strong><?php echo esc_html( $recu->nom_original ); ?></strong><small><?php echo esc_html( mysql2date( 'j F Y à H:i', $recu->date_envoi ) ); ?> · <?php echo esc_html( $recu->numero ); ?></small></div><span class="mes-recus__statut mes-recus__statut--<?php echo esc_attr( $statut ); ?>"><?php echo esc_html( $statut_libelle ); ?></span></li>
						<?php endforeach; ?></ul></section>
				<?php endforeach; endif; ?>
			</div>
			<?php endif; ?>
		</section>
		</div>
		<?php if ( $bienvenue ) { ueb_message_bienvenue(); } ?>
	</div>
</main>
<?php
ueb_page_fin( 'espace' );
