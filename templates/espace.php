<?php
/**
 * Tableau de bord étudiant : bandeau d'accueil avec les chiffres de l'année,
 * deux accès illustrés (Mes quitus, Mes reçus) et un bouton « Réglages » qui
 * déplie le compte et les contacts de la scolarité.
 *
 * ?vue=quitus : la liste des quitus seule, une ligne par dossier avec ses
 * actions (télécharger, modifier, envoyer le reçu) et le bouton « Nouveau
 * quitus ». Les étapes de l'inscription sont sur le formulaire du quitus.
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

/* Dossier suivi de l'année en cours (le plus urgent), pour l'établissement affiché. */
$parcours = ueb_parcours_inscription( $compte, $quitus );
$focus    = $parcours['focus'];

/* Chiffres de l'année en cours. */
$de_lannee = array_filter( $quitus, static fn( $q ) => $annee['code'] === $q->annee_academique );
$verifie   = array_sum( array_map( static fn( $q ) => 'verifie' === $q->statut ? (int) $q->montant : 0, $de_lannee ) );
$attente   = array_sum( array_map( static fn( $q ) => in_array( $q->statut, array( 'genere', 'recu_envoye', 'rejete' ), true ) ? (int) $q->montant : 0, $de_lannee ) );

/* Reçus de l'année en cours, par statut du paiement concerné. */
$recus_annee = array_filter( $recus_compte, static fn( $r ) => $annee['code'] === $r->annee_academique );
$recus_statuts = array_count_values( array_map( static fn( $r ) => $r->statut_quitus, $recus_annee ) );

/* Prénom et établissement : ceux du dernier quitus, sinon ceux de la préinscription. */
$preinscription = $compte->numero_dossier ? ueb_preinscription_par_dossier( $compte->numero_dossier ) : null;
$prenom         = trim( (string) ( $quitus[0]->prenom ?? ( $preinscription->prenom ?? '' ) ) );
$prenom         = $prenom ? mb_convert_case( strtok( $prenom, ' ' ), MB_CASE_TITLE ) : '';
$etab_focus     = ueb_etablissement( $focus->etablissement ?? ( $quitus[0]->etablissement ?? ( $preinscription->etablissement ?? '' ) ) );

$url_nouveau = ueb_url( 'mon-espace/quitus' );
$url_espace  = ueb_url( 'mon-espace' );

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
			<?php if ( 'quitus' === $vue ) : ?>
				<div>
					<a class="fil fil--clair" href="<?php echo esc_url( $url_espace ); ?>"><?php echo ueb_icone( 'fleche-g', 16 ); ?>Mon espace</a>
					<h1>Mes quitus<?php if ( $dossiers ) : ?> <span class="espace__compte-titre"><?php echo count( $dossiers ); ?></span><?php endif; ?></h1>
					<p class="espace__etab espace__etab--texte"><?php echo $dossiers ? 'Télécharge, modifie ou envoie le reçu de chaque dossier.' : 'Tes quitus apparaîtront ici dès que tu en auras créé un.'; ?></p>
				</div>
				<?php if ( $dossiers ) : ?>
					<a class="btn btn--clair" href="<?php echo esc_url( $url_nouveau ); ?>"><?php echo ueb_icone( 'plus', 18 ); ?>Nouveau quitus</a>
				<?php endif; ?>
			<?php elseif ( 'recus' === $vue ) : ?>
				<div>
					<a class="fil fil--clair" href="<?php echo esc_url( $url_espace ); ?>"><?php echo ueb_icone( 'fleche-g', 16 ); ?>Mon espace</a>
					<h1>Mes reçus<?php if ( $recus_compte ) : ?> <span class="espace__compte-titre"><?php echo count( $recus_compte ); ?></span><?php endif; ?></h1>
					<p class="espace__etab espace__etab--texte"><?php echo $recus_compte ? 'Retrouve chaque reçu envoyé, son statut et une copie à télécharger.' : 'Tes reçus bancaires apparaîtront ici dès le premier envoi.'; ?></p>
				</div>
				<?php if ( $parcours['action'] && $parcours['action']['principal'] ) : ?>
					<a class="btn btn--clair" href="<?php echo esc_url( $parcours['action']['url'] ); ?>"><?php echo ueb_icone( $parcours['action']['icone'], 18 ); ?><?php echo esc_html( $parcours['action']['libelle'] ); ?></a>
				<?php endif; ?>
			<?php else : ?>
				<div>
					<p class="espace__annee">Inscriptions <?php echo esc_html( $annee['libelle'] ); ?></p>
					<h1><?php echo esc_html( $prenom ? 'Bonjour ' . $prenom : 'Bonjour' ); ?></h1>
					<?php if ( $etab_focus ) : ?>
						<p class="espace__etab"><img src="<?php echo esc_url( ueb_logo_url( $etab_focus['sigle'] ) ); ?>" alt="" width="34" height="34"><?php echo esc_html( $etab_focus['sigle'] ); ?></p>
					<?php else : ?>
						<p class="espace__etab">Ton espace pour préparer tes quitus et envoyer tes reçus.</p>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>

		<?php if ( 'recus' === $vue ) : ?>
			<?php if ( $recus_annee ) : ?>
				<div class="conteneur">
					<dl class="espace__chiffres">
						<div>
							<dt>Reçus <?php echo esc_html( $annee['libelle'] ); ?></dt>
							<dd><?php echo count( $recus_annee ); ?></dd>
						</div>
						<div>
							<dt>Validés par la scolarité</dt>
							<dd><?php echo (int) ( $recus_statuts['verifie'] ?? 0 ); ?></dd>
						</div>
						<div>
							<dt>En cours de vérification</dt>
							<dd><?php echo (int) ( $recus_statuts['recu_envoye'] ?? 0 ); ?></dd>
						</div>
						<?php if ( ! empty( $recus_statuts['rejete'] ) ) : ?>
							<div>
								<dt>À corriger</dt>
								<dd><?php echo (int) $recus_statuts['rejete']; ?></dd>
							</div>
						<?php endif; ?>
					</dl>
				</div>
			<?php endif; ?>
		<?php elseif ( $de_lannee ) : ?>
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

		<?php ueb_nuages(); ?>
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

		<?php if ( 'quitus' === $vue ) : ?>
			<section class="liste-quitus" aria-label="Mes quitus par année académique">
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
						<?php if ( $dossiers_annee ) : ?>
							<ul class="liste-quitus__lignes">
								<?php foreach ( $dossiers_annee as $rang => $dossier ) { require UEB_INSC_DIR . '/templates/composants/quitus-dossier.php'; } ?>
							</ul>
						<?php else : ?>
							<div class="liste-quitus__vide">
								<span class="liste-quitus__vide-icone"><?php echo ueb_icone( 'fichier', 26 ); ?></span>
								<h2><?php echo $quitus ? 'Aucun quitus pour ' . esc_html( $annee['libelle'] ) : 'Ton premier quitus commence ici'; ?></h2>
								<p>Choisis ton établissement, ta formation et ta tranche : le PDF est prêt en quelques minutes.</p>
								<a class="btn btn--primaire" href="<?php echo esc_url( $url_nouveau ); ?>"><?php echo ueb_icone( 'plus', 18 ); ?>Nouveau quitus</a>
							</div>
						<?php endif; ?>
					</details>
				<?php endforeach; ?>
				<p class="liste-quitus__note"><?php echo ueb_icone( 'fichier', 16 ); ?>Tes dossiers restent disponibles d’une année à l’autre.</p>
			</section>
		<?php elseif ( 'recus' === $vue ) : ?>
			<section class="liste-quitus liste-recus" aria-label="Mes reçus par année académique">
				<?php if ( ! $recus_par_annee ) : ?>
					<div class="liste-quitus__vide">
						<span class="liste-quitus__vide-icone"><?php echo ueb_icone( 'recu', 26 ); ?></span>
						<h2>Aucun reçu envoyé</h2>
						<p>Après ton paiement à la <?php echo esc_html( UEB_BANQUE['nom'] ); ?>, envoie une photo ou un scan de ton reçu depuis le quitus concerné.</p>
						<a class="btn btn--primaire" href="<?php echo esc_url( add_query_arg( 'vue', 'quitus', $url_espace ) ); ?>"><?php echo ueb_icone( 'fichier', 18 ); ?>Voir mes quitus</a>
					</div>
				<?php else : foreach ( $recus_par_annee as $recus_code => $liste_recus ) :
					$en_cours_annee = $recus_code === $annee['code'];
					?>
					<details class="quitus-annee" <?php echo $en_cours_annee || 1 === count( $recus_par_annee ) ? 'open' : ''; ?>>
						<summary>
							<span class="quitus-annee__date"><?php echo esc_html( str_replace( '-', ' – ', $recus_code ) ); ?></span>
							<span class="quitus-annee__etat"><?php echo $en_cours_annee ? 'Année en cours' : 'Archives'; ?></span>
							<span class="quitus-annee__compte"><?php echo count( $liste_recus ); ?> reçu<?php echo count( $liste_recus ) > 1 ? 's' : ''; ?></span>
							<?php echo ueb_icone( 'chevron', 18 ); ?>
						</summary>
						<ul class="liste-quitus__lignes">
							<?php foreach ( $liste_recus as $rang => $recu ) { require UEB_INSC_DIR . '/templates/composants/ligne-recu.php'; } ?>
						</ul>
					</details>
				<?php endforeach; endif; ?>
				<p class="liste-quitus__note"><?php echo ueb_icone( 'info', 16 ); ?>Garde toujours l’original de ton reçu : la scolarité le contrôle lors de la vérification.</p>
			</section>
		<?php else : ?>
		<div class="espace-modules">
			<section class="espace-module carte" id="mes-quitus">
				<a class="espace-module__carte espace-module__carte--quitus" href="<?php echo esc_url( add_query_arg( 'vue', 'quitus', $url_espace ) ); ?>">
					<span class="espace-module__voile"></span><span class="espace-module__contenu"><b>Documents d’inscription</b><strong>Mes quitus <em><?php echo count( $dossiers ); ?></em></strong><span class="espace-module__ouvrir">Ouvrir mes quitus</span></span>
				</a>
			</section>

			<section class="espace-module carte" id="mes-recus">
				<a class="espace-module__carte espace-module__carte--recus" href="<?php echo esc_url( add_query_arg( 'vue', 'recus', $url_espace ) ); ?>">
					<span class="espace-module__voile"></span><span class="espace-module__contenu"><b>Suivi des paiements</b><strong>Mes reçus <em><?php echo count( $recus_compte ); ?></em></strong><span class="espace-module__ouvrir">Ouvrir mes reçus</span></span>
				</a>
			</section>
		</div>
		<?php endif; ?>
		<?php if ( $bienvenue ) { ueb_message_bienvenue(); } ?>
	</div>
</main>
<?php
ueb_page_fin( 'espace' );
