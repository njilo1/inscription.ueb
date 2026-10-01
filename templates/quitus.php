<?php
/**
 * Formulaire du quitus (création, ou modification avec ?id=).
 * Quatre sections numérotées — établissement, identité, formation, paiement —
 * puis un récapitulatif et le bouton qui génère le PDF, tout en bas.
 * L'année académique est bloquée.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

$compte = ueb_compte_courant();
$annee  = ueb_annee_academique();
$id     = (int) ( $_GET['id'] ?? 0 );
$edite  = null;

if ( $id ) {
	$edite = ueb_quitus_par_id( $id );
	if ( ! $edite || (int) $edite->compte_id !== (int) $compte->id ) {
		ueb_rediriger( ueb_url( 'mon-espace' ) );
	}
	if ( ! empty( $edite->quitus_droits_id ) ) {
		ueb_rediriger( ueb_url( 'mon-espace/quitus' ) . '?id=' . (int) $edite->quitus_droits_id );
	}
	if ( ! ueb_quitus_modifiable( $edite ) ) {
		ueb_flash( 'erreur', "Ce dossier ne peut plus être modifié : année clôturée ou reçus déjà envoyés." );
		ueb_rediriger( ueb_url( 'mon-espace' ) );
	}
}

list( $saisie, $erreurs ) = ueb_reprendre_saisie();
$v = $saisie ?: ( $edite ? (array) $edite : ueb_valeurs_initiales_quitus( $compte ) );
/* Fiche de l'étudiant : chaque information déjà renseignée est reprise et figée ;
   elle se corrige dans Mon compte. Le lieu de paiement et le montant restent libres. */
$profil = ueb_profil( $compte->id );
$v = array_replace( $v, $profil );
$contexte = ueb_contexte_inscription( $compte, $edite );
$preinscrit_cette_annee = $contexte['nouveau'];
$formations = $contexte['formations'];
/* Situation : une liste déroulante dont chaque option s'explique entre parenthèses. */
$options_situation = array(
	'nouveau' => 'Nouveau (préinscrit cette année à l’Université d’Ebolowa)',
	'ancien'  => 'Réinscription sans interruption (inscrit l’année dernière à l’Université d’Ebolowa)',
	'reprise' => 'Réinscription avec interruption (matricule interrompu les années antérieures)',
);
$v['situation'] = $contexte['situation_verrouillee'] ? $contexte['situation'] : ( $v['situation'] ?? $contexte['situation'] );
if ( ! isset( $options_situation[ $v['situation'] ] ) ) {
	$v['situation'] = $contexte['situation'];
}
$v['tranche'] = $v['tranche'] ?? array_key_first( $contexte['tranches'] );
$type_courant = $edite->type ?? 'droits';
$cms_requis = ueb_fiches_cms_requises( $contexte, $v['situation'], $type_courant );
// Compatibilité des quitus créés avant l'ajout de l'identifiant de filière.
if ( empty( $v['filiere_id'] ) ) {
	foreach ( $formations as $formation ) {
		if ( $formation->libelle === ( $v['departement'] ?? '' ) && $formation->etablissement === ( $v['etablissement'] ?? '' ) ) {
			$v['filiere_id'] = $formation->id;
			break;
		}
	}
}
$formation = $formations[ $v['filiere_id'] ?? 0 ] ?? null;
/* Formation classique : montant saisi (second versement pré-rempli avec le reste), tranche déduite. */
$v['montant'] = (int) preg_replace( '/\D+/', '', (string) ( $v['montant'] ?? '' ) );
$regle_droits = ueb_regle_droits_classiques( $contexte );
$classique = $formation && 'classique' === $formation->type_formation;
if ( $classique && 'droits' === $type_courant ) {
	if ( empty( $v['montant'] ) && $regle_droits['second'] && $regle_droits['reste'] ) {
		$v['montant'] = $regle_droits['reste'];
	}
	if ( ! empty( $v['montant'] ) && ! ueb_erreur_montant_classique( $v['montant'], $regle_droits ) ) {
		$v['tranche'] = ueb_tranche_du_montant( $v['montant'], $regle_droits );
	}
}
$medical_document = $contexte['medical_inclus'] && 'nouveau' !== ( $v['situation'] ?? '' );
$paiement = ueb_calculer_paiement( $formation, $v['tranche'], $v['montant'] ?? 0, $v['situation'], $contexte, $type_courant );
$v['montant'] = 'medicaux' === $type_courant ? $paiement['medicaux'] : $paiement['droits'];
$val = static fn( $cle ) => (string) ( $v[ $cle ] ?? '' );
$etablissements = ueb_etablissements();
$donnees_js = array();
foreach ( $etablissements as $sigle => $e ) {
	$donnees_js[ $sigle ] = array( 'fr' => $e['fr'], 'logo' => ueb_logo_url( $sigle ) );
}
$options_formations = array();
foreach ( $formations as $f ) {
	$options_formations[ $f->id ] = ( $f->choix ? 'Choix ' . $f->choix . ' — ' : $f->etablissement . ' — ' ) . $f->libelle;
}
$montants_medicaux = array_map( static fn( $f ) => $f['montant'], UEB_FRAIS_MEDICAUX );
$donnees_paiement = array(
	'formations' => array_values( $formations ),
	'nouveau' => $contexte['nouveau'],
	'medicalInclus' => (bool) $contexte['medical_inclus'],
	'medicalExistant' => $contexte['medical'] ? array( 'numero' => $contexte['medical']->numero, 'statut' => $contexte['medical']->statut ) : null,
	'montantsMedicaux' => $montants_medicaux,
	'droitsClassiques' => UEB_DROITS_CLASSIQUES,
	'regleDroits' => $regle_droits,
);
$aide_classique = $regle_droits['second']
	? ( $regle_droits['reste'] ? sprintf( 'Montant libre. Il te reste %s FCFA pour compléter les 50 000 FCFA de l’année.', ueb_formater_montant( $regle_droits['reste'] ) ) : 'Montant libre.' )
	: '25 000 FCFA au moins, par multiples de 5 000, sans plafond. Moins de 50 000 FCFA : première tranche ; 50 000 FCFA ou plus : les deux tranches.';

ueb_page_debut( array( 'titre' => $edite ? 'Modifier le quitus' : 'Nouveau quitus', 'variante' => 'espace' ) );
?>
<main id="contenu" class="page-app">
	<div class="conteneur conteneur--moyen">
		<a class="fil" href="<?php echo esc_url( add_query_arg( 'vue', 'quitus', ueb_url( 'mon-espace' ) ) ); ?>"><?php echo ueb_icone( 'fleche-g', 18 ); ?>Mes quitus</a>
		<header class="page-app__entete">
			<div>
				<h1><?php echo $edite ? 'Modifier le quitus ' . esc_html( $edite->numero ) : 'Nouveau quitus'; ?></h1>
				<p class="page-app__sous-titre">Renseigne chaque champ tel qu’il figure sur tes pièces officielles : ces informations seront reprises sur tes documents.</p>
			</div>
		</header>

		<ul class="quitus-contexte">
			<li><?php echo ueb_icone( 'horloge', 16 ); ?>Année académique <b><?php echo esc_html( $annee['libelle'] ); ?></b></li>
			<li><?php echo ueb_icone( 'utilisateur', 16 ); ?><?php echo $compte->matricule ? 'Matricule' : 'N° de dossier'; ?> <b><?php echo esc_html( ueb_identifiant_compte( $compte ) ); ?></b></li>
		</ul>
		<?php if ( ! $compte->matricule ) : ?>
			<p class="quitus-contexte__note">Tu as reçu ton matricule ? Enregistre-le dans <a href="<?php echo esc_url( ueb_url( 'mon-espace/compte' ) ); ?>">Mon compte</a> avant de générer ton quitus.</p>
		<?php endif; ?>

		<?php $parcours = ueb_parcours_inscription( $compte ); require UEB_INSC_DIR . '/templates/composants/parcours-inscription.php'; ?>

		<?php ueb_afficher_flash(); ?>
		<?php if ( $erreurs ) : ?>
			<div class="alerte alerte--erreur quitus-erreurs" role="alert" tabindex="-1" aria-labelledby="quitus-erreurs-titre" data-resume-erreurs>
				<?php echo ueb_icone( 'alerte', 20 ); ?>
				<div>
					<p id="quitus-erreurs-titre"><strong>Vérifie les informations suivantes avant de générer tes documents.</strong></p>
					<ul>
						<?php foreach ( $erreurs as $champ => $message ) : ?>
							<li><?php if ( 'general' === $champ ) : ?><?php echo esc_html( $message ); ?><?php else : ?>
								<a href="#champ-<?php echo esc_attr( array( 'type' => 'situation', 'departement' => 'filiere_id' )[ $champ ] ?? $champ ); ?>" data-lien-erreur><?php echo esc_html( $message ); ?></a>
							<?php endif; ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			</div>
		<?php endif; ?>

		<?php if ( $profil ) : ?>
			<div class="quitus-fiche" role="note">
				<span class="quitus-fiche__icone" aria-hidden="true"><?php echo ueb_icone( 'cadenas', 20 ); ?></span>
				<p><b>Tes informations sont reprises de ton compte.</b> Pour corriger une erreur, va dans <a href="<?php echo esc_url( ueb_url( 'mon-espace/compte' ) . '#informations' ); ?>">Mon compte</a>. Ici, choisis où tu vas payer et le montant de ce quitus.</p>
			</div>
		<?php endif; ?>

		<form class="quitus-form" method="post" action="<?php echo esc_url( ueb_url( 'mon-espace/quitus' ) . ( $edite ? '?id=' . $edite->id : '' ) ); ?>" data-type="<?php echo esc_attr( $type_courant ); ?>" data-formulaire data-quitus novalidate>
			<?php ueb_champ_csrf(); ?>
			<input type="hidden" name="type" value="<?php echo esc_attr( $type_courant ); ?>">
			<input type="hidden" name="ueb_action" value="enregistrer_quitus">
			<?php if ( $edite ) : ?><input type="hidden" name="quitus_id" value="<?php echo (int) $edite->id; ?>"><?php else : ?><input type="hidden" name="jeton_quitus" value="<?php echo esc_attr( ueb_jeton_formulaire_quitus() ); ?>"><?php endif; ?>

			<!-- 1. Établissement -->
			<section class="carte section-form" aria-labelledby="section-etablissement">
				<header class="section-form__entete">
					<span class="section-form__num">1</span>
					<div>
						<h2 id="section-etablissement">Ton établissement</h2>
						<p>Il détermine le compte bancaire imprimé sur ton quitus.</p>
					</div>
				</header>
				<div class="section-form__corps">
					<?php ueb_champs_profil( array( 'partie' => 'etablissement', 'v' => $v, 'erreurs' => $erreurs, 'verrou' => $profil, 'etabs_permis' => $preinscrit_cette_annee ? array_column( $formations, 'etablissement' ) : null ) ); ?>

					<div class="quitus-choix-type">
						<?php
						ueb_champ( array(
							'nom' => 'situation', 'libelle' => 'Ta situation cette année', 'type' => 'select',
							'valeur' => $val( 'situation' ), 'erreur' => $erreurs['situation'] ?? '',
							'options' => $options_situation,
							'icone' => 'utilisateur',
							'attrs' => $contexte['situation_verrouillee'] ? array( 'disabled' => true ) : array(),
							'aide' => $contexte['situation_verrouillee']
								? 'Ta situation est déjà fixée par ton quitus médical de cette année : elle ne peut plus changer.'
								: sprintf( 'Visite médicale : déjà payée à la préinscription si tu es nouveau, %s FCFA sans interruption, %s FCFA avec interruption (réactivation du matricule).%s', ueb_formater_montant( UEB_FRAIS_MEDICAUX['ancien']['montant'] ), ueb_formater_montant( UEB_FRAIS_MEDICAUX['reprise']['montant'] ), $preinscrit_cette_annee ? ' Ta préinscription de cette année a été retrouvée : « Nouveau » est présélectionné.' : '' ),
						) );
						?>
						<?php if ( $contexte['situation_verrouillee'] ) : ?><input type="hidden" name="situation" value="<?php echo esc_attr( $val( 'situation' ) ); ?>"><?php endif; ?>
					</div>
				</div>
			</section>

			<!-- 2. Identité -->
			<section class="carte section-form" aria-labelledby="section-identite">
				<header class="section-form__entete">
					<span class="section-form__num">2</span>
					<div>
						<h2 id="section-identite">Ton identité</h2>
						<p>Recopie-la exactement comme sur ta pièce d’identité.</p>
					</div>
				</header>
				<div class="section-form__corps formulaire">
					<?php ueb_champs_profil( array(
						'partie'     => 'identite',
						'v'          => $v,
						'erreurs'    => $erreurs,
						'verrou'     => $profil,
						'cms_requis' => $cms_requis,
						'aide_cms'   => $cms_requis ? 'Pour tes fiches CMS, complète ton email, ton adresse et les trois coordonnées de ton contact d’urgence ci-dessous.' : 'Ces coordonnées sont facultatives pour ce paiement : aucune fiche CMS n’est à générer.',
					) ); ?>
				</div>
			</section>

			<!-- 3. Formation -->
			<section class="carte section-form" aria-labelledby="section-formation">
				<header class="section-form__entete">
					<span class="section-form__num">3</span>
					<div>
						<h2 id="section-formation">Ta formation</h2>
						<p>Ton département et ton niveau pour cette année.</p>
					</div>
				</header>
				<div class="section-form__corps formulaire">
					<?php ueb_champs_profil( array(
						'partie'             => 'formation',
						'v'                  => $v,
						'erreurs'            => $erreurs,
						'verrou'             => $profil,
						'options_formations' => $options_formations,
						'libelle_filiere'    => $preinscrit_cette_annee ? 'Un de tes choix de préinscription' : 'Filière',
						'aide_filiere'       => $preinscrit_cette_annee ? 'Choisis parmi les filières enregistrées dans ton dossier de préinscription.' : 'Les filières proposées dépendent de l’établissement sélectionné.',
					) ); ?>
				</div>
			</section>

			<!-- 4. Paiement -->
			<section class="carte section-form" aria-labelledby="section-paiement">
				<header class="section-form__entete">
					<span class="section-form__num">4</span>
					<div>
						<h2 id="section-paiement">Ton paiement</h2>
						<p>Où tu paies et combien tu verses, sur le compte imprimé sur ton quitus.</p>
					</div>
				</header>
				<div class="section-form__corps formulaire">
					<div class="formulaire__rangee">
						<?php ueb_champ( array(
							'nom'     => 'moyen_paiement',
							'libelle' => 'Où vas-tu payer ?',
							'type'    => 'select',
							'icone'   => 'banque',
							'valeur'  => $val( 'moyen_paiement' ),
							'erreur'  => $erreurs['moyen_paiement'] ?? '',
							'options' => array_combine( UEB_MOYENS_PAIEMENT, UEB_MOYENS_PAIEMENT ),
							'aide'    => 'Tu peux en changer à chaque quitus.',
							/* Logos : la liste native devient une liste illustrée (app.js). */
							'attrs'   => array( 'data-liste-logos' => wp_json_encode( array_map( static fn( $l ) => array( 'src' => UEB_INSC_URI . '/assets/images/paiement/' . $l[0], 'fond' => $l[1] ), UEB_LOGOS_PAIEMENT ) ) ),
						) ); ?>
					</div>
					<p class="quitus-montant-fixe champ--medical"><?php echo ueb_icone( 'banque', 18 ); ?><span>Frais de visite médicale : <b data-montant-fixe><?php echo esc_html( ueb_formater_montant( $paiement['medicaux'] ) ); ?> FCFA</b>, à verser sur le compte des services centraux.</span></p>
					<div class="formulaire__rangee champ--droits">
						<?php
						/* Montant des droits : grands chiffres, devise, montant en lettres et
						   raccourcis (formations classiques). */
						$erreur_montant = $erreurs['montant'] ?? '';
						$aide_montant   = $classique ? $aide_classique : ( $formation ? 'Pour une formation professionnelle, indique le montant communiqué par ton établissement.' : 'Choisis une filière pour connaître les modalités de paiement.' );
						$raccourcis     = $regle_droits['second']
							? ( $regle_droits['reste'] ? array( $regle_droits['reste'] => 'Le reste de l’année' ) : array() )
							: array( UEB_DROITS_MINIMUM => 'Première tranche', UEB_DROITS_CLASSIQUES => 'Les deux tranches' );
						?>
						<div class="champ champ-somme<?php echo $erreur_montant ? ' champ--invalide' : ''; ?>">
							<label for="champ-montant">Droits universitaires<span class="sr"> en francs CFA</span></label>
							<div class="champ-somme__boite">
								<input id="champ-montant" name="montant" type="text" inputmode="numeric" autocomplete="off" required data-montant
									value="<?php echo esc_attr( $val( 'montant' ) ? ueb_formater_montant( $val( 'montant' ) ) : '' ); ?>"
									placeholder="<?php echo esc_attr( $regle_droits['second'] ? ( $regle_droits['reste'] ? ueb_formater_montant( $regle_droits['reste'] ) : '' ) : '25 000' ); ?>"
									aria-describedby="champ-montant-lettres champ-montant-aide<?php echo $erreur_montant ? ' champ-montant-erreur' : ''; ?>"<?php echo $erreur_montant ? ' aria-invalid="true"' : ''; ?><?php echo $formation ? '' : ' readonly'; ?>>
								<span class="champ-somme__devise" aria-hidden="true">FCFA</span>
							</div>
							<p class="champ-somme__lettres" id="champ-montant-lettres" data-montant-lettres></p>
							<?php if ( $raccourcis ) : ?>
								<div class="montant-rapide" data-montant-rapide role="group" aria-label="Montants proposés"<?php echo $classique ? '' : ' hidden'; ?>>
									<?php foreach ( $raccourcis as $somme => $libelle ) : ?>
										<button type="button" class="montant-rapide__choix" data-valeur="<?php echo (int) $somme; ?>" aria-pressed="<?php echo (int) $val( 'montant' ) === (int) $somme ? 'true' : 'false'; ?>">
											<b><?php echo esc_html( ueb_formater_montant( $somme ) ); ?></b><span><?php echo esc_html( $libelle ); ?></span>
										</button>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
							<p class="champ__aide" id="champ-montant-aide"><?php echo esc_html( $aide_montant ); ?></p>
							<?php if ( $erreur_montant ) : ?>
								<p class="champ__erreur" id="champ-montant-erreur"><?php echo ueb_icone( 'alerte', 16 ); ?><?php echo esc_html( $erreur_montant ); ?></p>
							<?php endif; ?>
						</div>
						<div class="tranche-auto<?php echo $classique ? ' est-auto' : ''; ?>" data-tranche-auto>
							<?php ueb_choix_segments( 'tranche', 'Tranche payée', $contexte['tranches'], $val( 'tranche' ), $erreurs['tranche'] ?? '' ); ?>
							<p class="tranche-auto__note"><?php echo ueb_icone( 'info', 15 ); ?>Déduite du montant saisi.</p>
						</div>
					</div>
					<?php
					/* Jauge de l'année (formations classiques) : déjà préparé, puis ce versement, sur les 50 000 FCFA. */
					$deja_prepare = $regle_droits['second'] ? max( 0, UEB_DROITS_CLASSIQUES - $regle_droits['reste'] ) : 0;
					$ce_versement = 'droits' === $type_courant ? (int) $v['montant'] : 0;
					$part         = static fn( $n ) => round( min( 1, max( 0, $n / UEB_DROITS_CLASSIQUES ) ), 4 );
					?>
					<div class="jauge-annee champ--droits" data-jauge-annee<?php echo $classique ? '' : ' hidden'; ?>>
						<div class="jauge-annee__piste" aria-hidden="true">
							<span class="jauge-annee__ce" data-jauge-ce style="--part: <?php echo esc_attr( $part( $deja_prepare + $ce_versement ) ); ?>"></span>
							<span class="jauge-annee__deja" style="--part: <?php echo esc_attr( $part( $deja_prepare ) ); ?>"></span>
						</div>
						<p class="jauge-annee__texte" data-jauge-texte>
							<?php
							$total_annee = $deja_prepare + $ce_versement;
							echo esc_html( ( $deja_prepare ? sprintf( 'Déjà préparé : %s FCFA. ', ueb_formater_montant( $deja_prepare ) ) : '' )
								. ( $total_annee >= UEB_DROITS_CLASSIQUES
									? 'Avec ce versement, les 50 000 FCFA de droits de l’année sont couverts.'
									: sprintf( 'Avec ce versement : %s sur 50 000 FCFA de droits pour l’année.', ueb_formater_montant( $total_annee ) ) ) );
							?>
						</p>
					</div>
					<div class="paiement-total" role="status" aria-live="polite" aria-atomic="true">
						<p>Montant total à payer <strong data-total-paiement><?php echo $paiement['total'] ? esc_html( ueb_formater_montant( $paiement['total'] ) . ' FCFA' ) : '—'; ?></strong></p>
						<p class="paiement-total__detail" data-detail-paiement><?php echo esc_html( ueb_formater_montant( $paiement['droits'] ) . ' FCFA de droits universitaires + ' . ueb_formater_montant( $paiement['medicaux'] ) . ' FCFA de frais médicaux.' ); ?></p>
					</div>
					<p class="champ__aide" data-note-medicale><?php echo $preinscrit_cette_annee ? 'Frais médicaux déjà compris dans ta préinscription de cette année.' : ( $contexte['medical_inclus'] ? 'Les frais médicaux sont payables en une seule fois pour l’année en cours, sur le compte des services centraux.' : 'Les frais médicaux figurent déjà sur ton quitus ' . esc_html( $contexte['medical']->numero ) . ' : ils ne sont pas ajoutés à cette tranche.' ); ?></p>
					<noscript><p class="quitus-note">Après avoir choisi ta formation, ta situation ou ta tranche, actualise les montants avant de générer tes documents.</p><button class="btn btn--fantome" type="submit" name="actualiser_paiement" value="1">Actualiser les montants</button></noscript>
					<?php if ( ! $contexte['tranches'] && 'droits' === $type_courant ) : ?><p class="quitus-note">Tes deux tranches ont déjà un quitus pour cette année. Retrouve-les dans <a href="<?php echo esc_url( ueb_url( 'mon-espace' ) ); ?>">ton espace</a>.</p><?php endif; ?>
				</div>
			</section>

			<!-- Récapitulatif et génération -->
			<?php
			$etab_recap = ueb_etablissement( $val( 'etablissement' ) );
			$recap_pret = 'medicaux' === $type_courant || ( $formation && $v['tranche'] && $paiement['droits'] );
			/* Tous les documents possibles ; ceux que ce paiement ne produit pas sont masqués
			   (et réévalués en direct quand la situation change). */
			$documents = array(
				array( 'titre' => 'Quitus universitaire', 'detail' => 'Droits d’inscription', 'pages' => 1, 'cle' => 'droits', 'produit' => 'droits' === $type_courant ),
				array( 'titre' => 'Quitus médical', 'detail' => 'Frais de visite médicale', 'pages' => 1, 'cle' => 'medical', 'produit' => $medical_document ),
				array( 'titre' => 'Fiches CMS', 'detail' => 'Identification et examen médical', 'pages' => 2, 'cle' => 'medical', 'produit' => $medical_document ),
			);
			$documents = array_filter( $documents, static fn( $d ) => 'droits' === $type_courant || 'medical' === $d['cle'] );
			$pages_document = array_sum( array_column( array_filter( $documents, static fn( $d ) => $d['produit'] ), 'pages' ) );
			?>
			<section class="carte quitus-final" aria-labelledby="section-final">
				<header class="quitus-final__entete">
					<p class="quitus-final__repere">Ton dossier d’inscription</p>
					<h2 id="section-final" class="quitus-final__titre">Vérifie, puis génère tes documents</h2>
					<p class="quitus-final__intro">Retrouve tes choix et les documents qui seront préparés pour toi.</p>
				</header>

				<div class="quitus-final__corps">
					<div class="quitus-final__resume">
						<div class="quitus-final__etablissement">
							<img data-recap-logo src="<?php echo esc_url( ueb_logo_url( $etab_recap ? $etab_recap['sigle'] : 'UEB' ) ); ?>" alt="" width="44" height="44" <?php echo $etab_recap ? '' : 'hidden'; ?>>
							<div><span>Établissement</span><strong data-recap-etab><?php echo esc_html( $etab_recap['fr'] ?? 'À choisir' ); ?></strong></div>
						</div>
						<dl class="quitus-recap">
							<div><dt>Filière</dt><dd data-recap-formation><?php echo esc_html( $formation->libelle ?? 'À choisir' ); ?></dd></div>
							<div><dt>Niveau</dt><dd data-recap-niveau><?php echo esc_html( UEB_NIVEAUX_INSCRIPTION[ $val( 'parcours' ) ] ?? 'À choisir' ); ?></dd></div>
							<div><dt>Lieu de paiement</dt><dd data-recap-moyen><?php echo esc_html( $val( 'moyen_paiement' ) ?: 'À choisir' ); ?></dd></div>
							<div><dt>Paiement</dt><dd data-recap-tranche><?php echo esc_html( 'medicaux' === $type_courant ? 'Paiement unique' : ( $contexte['tranches'][ $v['tranche'] ] ?? 'À choisir' ) ); ?></dd></div>
						</dl>
						<dl class="quitus-recap quitus-recap--montants">
							<?php if ( 'droits' === $type_courant ) : ?>
								<div><dt>Droits universitaires</dt><dd data-recap-montant><?php echo $recap_pret ? esc_html( ueb_formater_montant( $paiement['droits'] ) . ' FCFA' ) : '—'; ?></dd></div>
							<?php endif; ?>
							<div><dt>Frais médicaux</dt><dd data-recap-medicaux><?php echo esc_html( ueb_formater_montant( $paiement['medicaux'] ) . ' FCFA' ); ?></dd></div>
							<div class="quitus-recap__total"><dt>Total à payer</dt><dd data-recap-total><?php echo $recap_pret ? esc_html( ueb_formater_montant( $paiement['total'] ) . ' FCFA' ) : '—'; ?></dd></div>
						</dl>
					</div>

					<aside class="quitus-document" aria-labelledby="quitus-document-titre" data-documents-paiement>
						<div class="quitus-document__entete">
							<span class="quitus-document__feuille" aria-hidden="true"><?php echo ueb_icone( 'fichier', 30 ); ?><span>PDF</span></span>
							<div>
								<h3 id="quitus-document-titre">Dans ton PDF</h3>
								<p>Un fichier · <span data-document-pages><?php echo (int) $pages_document; ?></span> page<span data-document-pages-suffix><?php echo $pages_document > 1 ? 's' : ''; ?></span> à imprimer</p>
							</div>
						</div>
						<ul class="quitus-document__liste">
							<?php foreach ( $documents as $document ) : ?>
								<li data-document="<?php echo esc_attr( $document['cle'] ); ?>" data-pages="<?php echo (int) $document['pages']; ?>" <?php echo $document['produit'] ? '' : 'hidden'; ?>>
									<div><strong><?php echo esc_html( $document['titre'] ); ?></strong><span><?php echo esc_html( $document['detail'] ); ?></span></div>
									<span class="quitus-document__pages"><?php echo (int) $document['pages']; ?> p.</span>
								</li>
							<?php endforeach; ?>
						</ul>
						<p class="quitus-document__note">Chaque quitus contient 4 coupons : étudiant, DAF, scolarité et banque.</p>
					</aside>
				</div>

				<footer class="quitus-final__pied">
					<p>Après génération, imprime tes quitus et fais-les tamponner avant de payer.</p>
					<div class="quitus-final__actions">
						<button class="btn btn--primaire quitus-final__generer" type="submit" <?php disabled( ! $contexte['tranches'] && 'droits' === $type_courant ); ?><?php if ( $edite ) : ?> data-confirmer-ton="enregistrer" data-confirmer-titre="Enregistrer les modifications ?" data-confirmer-bouton="Oui, enregistrer" data-confirmer="<?php echo esc_attr( sprintf( 'Le quitus %s sera refait avec ces informations. S’il est déjà imprimé ou tamponné, imprime la nouvelle version.', $edite->numero ) ); ?>"<?php endif; ?>><?php echo $edite ? 'Enregistrer les modifications' : 'Générer mes documents'; ?><?php echo ueb_icone( 'fleche', 18 ); ?></button>
						<a class="quitus-final__annuler" href="<?php echo esc_url( ueb_url( 'mon-espace' ) ); ?>">Annuler</a>
					</div>
				</footer>
			</section>
		</form>
	</div>
	<script type="application/json" id="donnees-etablissements"><?php echo wp_json_encode( $donnees_js ); ?></script>
	<script type="application/json" id="donnees-paiement"><?php echo wp_json_encode( $donnees_paiement, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?></script>
</main>
<?php
ueb_page_fin( 'espace' );
