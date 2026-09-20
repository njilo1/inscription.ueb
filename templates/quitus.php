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
$contexte = ueb_contexte_inscription( $compte, $edite );
$preinscrit_cette_annee = $contexte['nouveau'];
$formations = $contexte['formations'];
$options_situation = array( 'nouveau' => 'Nouveau — frais médicaux déjà réglés à la préinscription' ) + array_map( static fn( $f ) => $f['libelle'], UEB_FRAIS_MEDICAUX );
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
);

ueb_page_debut( array( 'titre' => $edite ? 'Modifier le quitus' : 'Nouveau quitus', 'variante' => 'espace' ) );
?>
<main id="contenu" class="page-app">
	<div class="conteneur conteneur--moyen">
		<a class="fil" href="<?php echo esc_url( ueb_url( 'mon-espace' ) ); ?>"><?php echo ueb_icone( 'fleche-g', 18 ); ?>Mes quitus</a>
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
			<p class="quitus-contexte__note">Tu as reçu ton matricule ? Enregistre-le dans <a href="<?php echo esc_url( ueb_url( 'mon-espace/securite' ) ); ?>">Sécurité</a> avant de générer ton quitus.</p>
		<?php endif; ?>

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

		<form class="quitus-form" method="post" action="<?php echo esc_url( ueb_url( 'mon-espace/quitus' ) . ( $edite ? '?id=' . $edite->id : '' ) ); ?>" data-type="<?php echo esc_attr( $type_courant ); ?>" data-formulaire data-quitus novalidate>
			<?php ueb_champ_csrf(); ?>
			<input type="hidden" name="type" value="<?php echo esc_attr( $type_courant ); ?>">
			<input type="hidden" name="ueb_action" value="enregistrer_quitus">
			<?php if ( $edite ) : ?><input type="hidden" name="quitus_id" value="<?php echo (int) $edite->id; ?>"><?php endif; ?>

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
					<fieldset id="champ-etablissement" tabindex="-1" class="champ<?php echo ! empty( $erreurs['etablissement'] ) ? ' champ--invalide' : ''; ?>">
						<legend class="sr">Établissement</legend>
						<div class="etab-choix">
							<?php foreach ( $etablissements as $sigle => $e ) : ?>
								<label class="etab-choix__option" style="--etab: <?php echo esc_attr( $e['couleur'] ); ?>">
									<input type="radio" name="etablissement" value="<?php echo esc_attr( $sigle ); ?>" <?php checked( $val( 'etablissement' ), $sigle ); ?> <?php disabled( $preinscrit_cette_annee && ! in_array( $sigle, array_column( $formations, 'etablissement' ), true ) ); ?> required>
									<span class="etab-choix__carte">
										<span class="etab-choix__logo"><img src="<?php echo esc_url( ueb_logo_url( $sigle ) ); ?>" alt="" width="40" height="40" loading="lazy"></span>
										<span class="etab-choix__texte">
											<b><?php echo esc_html( $sigle ); ?></b>
											<small><?php echo esc_html( $e['fr'] ); ?></small>
										</span>
										<span class="etab-choix__coche"><?php echo ueb_icone( 'check', 16 ); ?></span>
									</span>
								</label>
							<?php endforeach; ?>
						</div>
						<?php if ( ! empty( $erreurs['etablissement'] ) ) : ?>
							<p class="champ__erreur"><?php echo ueb_icone( 'alerte', 16 ); ?><?php echo esc_html( $erreurs['etablissement'] ); ?></p>
						<?php endif; ?>
					</fieldset>

					<div class="quitus-choix-type">
						<?php
						ueb_champ( array(
							'nom' => 'situation', 'libelle' => 'Ta situation cette année', 'type' => 'select',
							'valeur' => $val( 'situation' ), 'erreur' => $erreurs['situation'] ?? '',
							'options' => $options_situation,
							'attrs' => $contexte['situation_verrouillee'] ? array( 'disabled' => true ) : array(),
							'aide' => $preinscrit_cette_annee ? 'Ton dossier est prérempli. Tu peux conserver Nouveau ou choisir une autre situation.' : 'Nouveau ne génère pas de frais médicaux. Les autres situations sont payables en une seule fois : 3 000 ou 5 000 FCFA.',
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
					<div class="formulaire__rangee">
						<?php
						ueb_champ( array( 'nom' => 'nom', 'libelle' => 'Nom(s)', 'icone' => 'utilisateur', 'valeur' => $val( 'nom' ), 'erreur' => $erreurs['nom'] ?? '', 'attrs' => array( 'placeholder' => 'Ex. : TCHOUMBA', 'autocomplete' => 'family-name', 'autocapitalize' => 'characters', 'maxlength' => 100 ) ) );
						ueb_champ( array( 'nom' => 'prenom', 'libelle' => 'Prénom(s)', 'icone' => 'utilisateur', 'valeur' => $val( 'prenom' ), 'erreur' => $erreurs['prenom'] ?? '', 'attrs' => array( 'placeholder' => 'Ex. : Vevo ily', 'autocomplete' => 'given-name', 'maxlength' => 150 ) ) );
						?>
					</div>
					<div class="formulaire__rangee">
						<?php
						ueb_champ( array( 'nom' => 'date_naissance', 'libelle' => 'Date de naissance', 'type' => 'date','valeur' => $val( 'date_naissance' ), 'erreur' => $erreurs['date_naissance'] ?? '', 'aide' => 'Ex. : 15/03/2005, pour le 15 mars 2005.', 'attrs' => array( 'autocomplete' => 'bday', 'max' => wp_date( 'Y-m-d', strtotime( '-14 years' ) ) ) ) );
						ueb_champ( array( 'nom' => 'lieu_naissance', 'libelle' => 'Lieu de naissance', 'icone' => 'lieu', 'valeur' => $val( 'lieu_naissance' ), 'erreur' => $erreurs['lieu_naissance'] ?? '', 'attrs' => array( 'placeholder' => 'Ex. : Ebolowa', 'maxlength' => 150 ) ) );
						?>
					</div>
					<div class="formulaire__rangee">
						<?php
						ueb_choix_segments( 'sexe', 'Sexe', array( 'M' => 'Masculin', 'F' => 'Féminin' ), $val( 'sexe' ), $erreurs['sexe'] ?? '' );
						ueb_champ( array( 'nom' => 'nationalite', 'libelle' => 'Nationalité', 'type' => 'select', 'icone' => 'lieu', 'valeur' => $val( 'nationalite' ) ?: 'Camerounaise', 'erreur' => $erreurs['nationalite'] ?? '', 'options' => array_combine( ueb_nationalites(), ueb_nationalites() ) ) );
						?>
					</div>
					<div class="formulaire__rangee">
						<?php
						ueb_champ( array( 'nom' => 'email', 'libelle' => 'Adresse email', 'type' => 'email', 'icone' => 'courriel', 'valeur' => $val( 'email' ), 'erreur' => $erreurs['email'] ?? '', 'requis' => $cms_requis, 'aide' => 'Utilisée sur les fiches CMS.', 'attrs' => array( 'placeholder' => 'Ex. : vevo@example.com', 'data-cms-champ' => true, 'autocomplete' => 'email', 'maxlength' => 150 ) ) );
						ueb_champ( array( 'nom' => 'adresse', 'libelle' => 'Adresse complète', 'icone' => 'lieu', 'valeur' => $val( 'adresse' ), 'erreur' => $erreurs['adresse'] ?? '', 'requis' => $cms_requis, 'aide' => 'Indique ton quartier et ta ville.', 'attrs' => array( 'placeholder' => 'Ex. : Nko’ovos, Ebolowa', 'data-cms-champ' => true, 'autocomplete' => 'street-address', 'minlength' => 3, 'maxlength' => 255 ) ) );
						?>
					</div>
					<section class="quitus-cms" aria-labelledby="quitus-contact-titre">
						<h3 id="quitus-contact-titre">Contact en cas d’urgence</h3>
						<p class="champ__aide" data-cms-aide><?php echo $cms_requis ? 'Pour tes fiches CMS, complète ton email, ton adresse et les trois coordonnées de ton contact d’urgence ci-dessous.' : 'Ces coordonnées sont facultatives pour ce paiement : aucune fiche CMS n’est à générer.'; ?></p>
						<div class="quitus-cms__corps formulaire">
							<div class="formulaire__rangee">
								<?php
								ueb_champ( array( 'nom' => 'nom_urgence', 'libelle' => 'Personne à contacter en cas d’urgence', 'icone' => 'utilisateur', 'valeur' => $val( 'nom_urgence' ), 'erreur' => $erreurs['nom_urgence'] ?? '', 'requis' => $cms_requis, 'attrs' => array( 'placeholder' => 'Ex. : TCHOUMBA Jean', 'data-cms-champ' => true, 'autocomplete' => 'section-urgence name', 'minlength' => 2, 'maxlength' => 150 ) ) );
								ueb_champ( array( 'nom' => 'numero_urgence', 'libelle' => 'Téléphone d’urgence', 'type' => 'tel', 'icone' => 'telephone', 'valeur' => $val( 'numero_urgence' ), 'erreur' => $erreurs['numero_urgence'] ?? '', 'requis' => $cms_requis, 'aide' => 'Numéro camerounais à 9 chiffres, avec ou sans +237.', 'attrs' => array( 'placeholder' => 'Ex. : 699 11 11 11', 'data-cms-champ' => true, 'data-telephone' => true, 'inputmode' => 'tel', 'autocomplete' => 'section-urgence tel', 'maxlength' => 20 ) ) );
								?>
							</div>
							<div class="formulaire__rangee">
								<?php ueb_champ( array( 'nom' => 'adresse_urgence', 'libelle' => 'Adresse du contact', 'icone' => 'lieu', 'valeur' => $val( 'adresse_urgence' ), 'erreur' => $erreurs['adresse_urgence'] ?? '', 'requis' => $cms_requis, 'aide' => 'Quartier et ville de la personne à contacter.', 'attrs' => array( 'placeholder' => 'Ex. : Angalé, Ebolowa', 'data-cms-champ' => true, 'autocomplete' => 'section-urgence street-address', 'minlength' => 3, 'maxlength' => 255 ) ) ); ?>
							</div>
						</div>
					</section>
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
					<div class="formulaire__rangee">
						<?php
						ueb_champ( array( 'nom' => 'filiere_id', 'libelle' => $preinscrit_cette_annee ? 'Un de tes choix de préinscription' : 'Filière', 'type' => 'select', 'icone' => 'ecole', 'valeur' => $val( 'filiere_id' ), 'erreur' => $erreurs['filiere_id'] ?? '', 'options' => $options_formations, 'aide' => $preinscrit_cette_annee ? 'Choisis parmi les filières enregistrées dans ton dossier de préinscription.' : 'Les filières proposées dépendent de l’établissement sélectionné.' ) );
						ueb_champ( array( 'nom' => 'parcours', 'libelle' => 'Niveau', 'type' => 'select', 'valeur' => $val( 'parcours' ), 'erreur' => $erreurs['parcours'] ?? '', 'options' => UEB_NIVEAUX_INSCRIPTION, 'aide' => 'Ex. : L1 pour Licence 1, M1 pour Master 1.' ) );
						?>
					</div>
				</div>
			</section>

			<!-- 4. Paiement -->
			<section class="carte section-form" aria-labelledby="section-paiement">
				<header class="section-form__entete">
					<span class="section-form__num">4</span>
					<div>
						<h2 id="section-paiement">Ton paiement</h2>
						<p>Ce que tu vas verser à la <?php echo esc_html( UEB_BANQUE['nom'] ); ?>, sur le compte imprimé sur ton quitus.</p>
					</div>
				</header>
				<div class="section-form__corps formulaire">
					<p class="quitus-montant-fixe champ--medical"><?php echo ueb_icone( 'banque', 18 ); ?><span>Frais de visite médicale : <b data-montant-fixe><?php echo esc_html( ueb_formater_montant( $paiement['medicaux'] ) ); ?> FCFA</b>, à verser sur le compte des services centraux.</span></p>
					<div class="formulaire__rangee champ--droits">
						<div class="champ-montant">
							<?php
							ueb_champ( array(
								'nom'     => 'montant',
								'libelle' => 'Droits universitaires (FCFA)',
								'icone'   => 'banque',
								'valeur'  => $val( 'montant' ) ? ueb_formater_montant( $val( 'montant' ) ) : '',
								'erreur'  => $erreurs['montant'] ?? '',
								'aide' => $formation && 'classique' === $formation->type_formation ? 'Formation classique : 50 000 FCFA par an, en deux tranches de 25 000 FCFA. Montant fixé automatiquement.' : 'Pour une formation professionnelle, indique le montant communiqué par ton établissement.',
								'attrs' => array( 'inputmode' => 'numeric', 'placeholder' => '25 000', 'data-montant' => true, 'autocomplete' => 'off' ) + ( ! $formation || 'classique' === $formation->type_formation ? array( 'readonly' => true ) : array() ),
							) );
							?>
							<p class="apercu" data-montant-lettres></p>
						</div>
						<?php ueb_choix_segments( 'tranche', 'Tranche payée', $contexte['tranches'], $val( 'tranche' ), $erreurs['tranche'] ?? '' ); ?>
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
			$documents = array();
			if ( 'droits' === $type_courant ) {
				$documents[] = array( 'titre' => 'Quitus universitaire', 'detail' => 'Droits d’inscription', 'pages' => 1 );
			}
			if ( $medical_document ) {
				$documents[] = array( 'titre' => 'Quitus médical', 'detail' => 'Frais de visite médicale', 'pages' => 1 );
				$documents[] = array( 'titre' => 'Fiches CMS', 'detail' => 'Identification et examen médical', 'pages' => 2 );
			}
			$pages_document = array_sum( array_column( $documents, 'pages' ) );
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
								<li <?php echo ( 'Quitus universitaire' !== $document['titre'] && $medical_document ) ? 'data-document-medical' : ''; ?>>
									<div><strong><?php echo esc_html( $document['titre'] ); ?></strong><span><?php echo esc_html( $document['detail'] ); ?></span></div>
									<span class="quitus-document__pages"><?php echo (int) $document['pages']; ?> p.</span>
								</li>
							<?php endforeach; ?>
						</ul>
						<p class="quitus-document__note">Chaque quitus contient 4 coupons : étudiant, DAF, scolarité et banque.</p>
					</aside>
				</div>

				<footer class="quitus-final__pied">
					<p>Après génération, imprime tes quitus et fais-les tamponner avant de payer à la banque.</p>
					<div class="quitus-final__actions">
						<button class="btn btn--primaire quitus-final__generer" type="submit" <?php disabled( ! $contexte['tranches'] && 'droits' === $type_courant ); ?>><?php echo $edite ? 'Enregistrer les modifications' : 'Générer mes documents'; ?><?php echo ueb_icone( 'fleche', 18 ); ?></button>
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
