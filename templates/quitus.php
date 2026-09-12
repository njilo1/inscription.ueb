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
	if ( ! ueb_quitus_modifiable( $edite ) ) {
		ueb_flash( 'erreur', "Ce quitus n'est plus modifiable : des reçus ont déjà été envoyés." );
		ueb_rediriger( ueb_url( 'mon-espace' ) );
	}
}

list( $saisie, $erreurs ) = ueb_reprendre_saisie();
$v   = $saisie ?: ( $edite ? (array) $edite : ueb_valeurs_initiales_quitus( $compte ) );
$val = static fn( $cle ) => (string) ( $v[ $cle ] ?? '' );

$etablissements = ueb_etablissements();
$donnees_js     = array();
foreach ( $etablissements as $sigle => $e ) {
	$donnees_js[ $sigle ] = array( 'fr' => $e['fr'], 'logo' => ueb_logo_url( $sigle ) );
}

/* Types de quitus et situations ouvrant droit aux frais médicaux. */
$options_type      = array_map( static fn( $t ) => $t['libelle'], UEB_TYPES_QUITUS );
$options_situation = array();
$montants_medicaux = array();
foreach ( UEB_FRAIS_MEDICAUX as $cle => $f ) {
	$options_situation[ $cle ] = $f['libelle'] . ' — ' . ueb_formater_montant( $f['montant'] ) . ' FCFA';
	$montants_medicaux[ $cle ] = $f['montant'];
}
/* La situation n'est pas stockée : on la retrouve à partir du montant fixe. */
$situation = '';
foreach ( UEB_FRAIS_MEDICAUX as $cle => $f ) {
	if ( (int) $val( 'montant' ) === $f['montant'] ) {
		$situation = $cle;
	}
}
/* Préinscrit cette année : la visite médicale est déjà payée, le type est retiré. */
$preinscrit_cette_annee = ueb_preinscrit_cette_annee( $compte );
if ( $preinscrit_cette_annee ) {
	unset( $options_type['medicaux'] );
}
$type_courant = $val( 'type' ) ?: 'droits';
if ( ! isset( $options_type[ $type_courant ] ) ) {
	$type_courant = 'droits';
}

ueb_page_debut( array( 'titre' => $edite ? 'Modifier le quitus' : 'Nouveau quitus', 'variante' => 'espace' ) );
?>
<main id="contenu" class="page-app">
	<div class="conteneur conteneur--moyen">
		<a class="fil" href="<?php echo esc_url( ueb_url( 'mon-espace' ) ); ?>"><?php echo ueb_icone( 'fleche-g', 18 ); ?>Mes quitus</a>
		<header class="page-app__entete">
			<div>
				<h1><?php echo $edite ? 'Modifier le quitus ' . esc_html( $edite->numero ) : 'Nouveau quitus'; ?></h1>
				<p class="page-app__sous-titre">Renseigne chaque champ tel qu’il figure sur tes pièces officielles : ces informations sont imprimées sur les quatre coupons.</p>
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
			<div class="alerte alerte--erreur" role="alert" tabindex="-1" data-resume-erreurs><?php echo ueb_icone( 'alerte', 20 ); ?><p><?php echo esc_html( $erreurs['general'] ?? sprintf( '%d champ(s) à corriger avant de générer le quitus.', count( $erreurs ) ) ); ?></p></div>
		<?php endif; ?>

		<form class="quitus-form" method="post" action="<?php echo esc_url( ueb_url( 'mon-espace/quitus' ) . ( $edite ? '?id=' . $edite->id : '' ) ); ?>" data-type="<?php echo esc_attr( $type_courant ); ?>" data-formulaire data-quitus novalidate>
			<?php ueb_champ_csrf(); ?>
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
					<fieldset class="champ<?php echo ! empty( $erreurs['etablissement'] ) ? ' champ--invalide' : ''; ?>">
						<legend class="sr">Établissement</legend>
						<div class="etab-choix">
							<?php foreach ( $etablissements as $sigle => $e ) : ?>
								<label class="etab-choix__option" style="--etab: <?php echo esc_attr( $e['couleur'] ); ?>">
									<input type="radio" name="etablissement" value="<?php echo esc_attr( $sigle ); ?>" <?php checked( $val( 'etablissement' ), $sigle ); ?> required>
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

					<div class="formulaire__rangee quitus-choix-type">
						<?php
						ueb_champ( array(
							'nom'     => 'type',
							'libelle' => 'Type de quitus',
							'type'    => 'select',
							'icone'   => 'fichier',
							'valeur'  => $type_courant,
							'erreur'  => $erreurs['type'] ?? '',
							'options' => $options_type,
							'attrs'   => array( 'data-type-quitus' => true ),
						) );
						ueb_champ( array(
							'nom'     => 'situation',
							'libelle' => 'Ta situation',
							'type'    => 'select',
							'icone'   => 'utilisateur',
							'valeur'  => $situation,
							'erreur'  => $erreurs['situation'] ?? '',
							'options' => $options_situation,
							'classe'  => 'champ--medical',
							'aide'    => 'Elle fixe le montant de la visite médicale.',
						) );
						?>
					</div>
					<?php if ( $preinscrit_cette_annee ) : ?>
						<p class="quitus-note"><?php echo ueb_icone( 'info', 18 ); ?><span>Tu t’es préinscrit cette année : ta visite médicale est déjà comprise dans tes frais de préinscription. Tu n’as donc pas de quitus médical à générer.</span></p>
					<?php else : ?>
						<p class="quitus-note champ--medical"><?php echo ueb_icone( 'info', 18 ); ?><span>Les étudiants préinscrits cette année n’ont pas de quitus médical à générer : leur visite est comprise dans les frais de préinscription.</span></p>
					<?php endif; ?>
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
						ueb_champ( array( 'nom' => 'nom', 'libelle' => 'Nom(s)', 'icone' => 'utilisateur', 'valeur' => $val( 'nom' ), 'erreur' => $erreurs['nom'] ?? '', 'attrs' => array( 'autocomplete' => 'family-name', 'autocapitalize' => 'characters', 'maxlength' => 100 ) ) );
						ueb_champ( array( 'nom' => 'prenom', 'libelle' => 'Prénom(s)', 'icone' => 'utilisateur', 'valeur' => $val( 'prenom' ), 'erreur' => $erreurs['prenom'] ?? '', 'attrs' => array( 'autocomplete' => 'given-name', 'maxlength' => 150 ) ) );
						?>
					</div>
					<div class="formulaire__rangee">
						<?php
						ueb_champ( array( 'nom' => 'date_naissance', 'libelle' => 'Date de naissance', 'type' => 'date','valeur' => $val( 'date_naissance' ), 'erreur' => $erreurs['date_naissance'] ?? '', 'attrs' => array( 'autocomplete' => 'bday', 'max' => wp_date( 'Y-m-d', strtotime( '-14 years' ) ) ) ) );
						ueb_champ( array( 'nom' => 'lieu_naissance', 'libelle' => 'Lieu de naissance', 'icone' => 'lieu', 'valeur' => $val( 'lieu_naissance' ), 'erreur' => $erreurs['lieu_naissance'] ?? '', 'attrs' => array( 'maxlength' => 150 ) ) );
						?>
					</div>
					<div class="formulaire__rangee">
						<?php
						ueb_choix_segments( 'sexe', 'Sexe', array( 'M' => 'Masculin', 'F' => 'Féminin' ), $val( 'sexe' ), $erreurs['sexe'] ?? '' );
						ueb_champ( array( 'nom' => 'nationalite', 'libelle' => 'Nationalité', 'type' => 'select', 'icone' => 'lieu', 'valeur' => $val( 'nationalite' ) ?: 'Camerounaise', 'erreur' => $erreurs['nationalite'] ?? '', 'options' => array_combine( ueb_nationalites(), ueb_nationalites() ) ) );
						?>
					</div>
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
						ueb_champ( array( 'nom' => 'departement', 'libelle' => 'Département ou filière', 'icone' => 'ecole', 'valeur' => $val( 'departement' ), 'erreur' => $erreurs['departement'] ?? '', 'aide' => 'Exemple : Informatique (TIC)', 'attrs' => array( 'maxlength' => 150 ) ) );
						ueb_champ( array( 'nom' => 'parcours', 'libelle' => 'Cycle, niveau et parcours', 'icone' => 'fichier', 'valeur' => $val( 'parcours' ), 'erreur' => $erreurs['parcours'] ?? '', 'aide' => 'Exemple : Licence 2 — TIC', 'attrs' => array( 'maxlength' => 150 ) ) );
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
					<p class="quitus-montant-fixe champ--medical"><?php echo ueb_icone( 'banque', 18 ); ?><span>Frais de visite médicale : <b data-montant-fixe>—</b>, à verser sur le compte des services centraux.</span></p>
					<div class="formulaire__rangee champ--droits">
						<div class="champ-montant">
							<?php
							ueb_champ( array(
								'nom'     => 'montant',
								'libelle' => 'Montant à payer (FCFA)',
								'icone'   => 'banque',
								'valeur'  => $val( 'montant' ) ? ueb_formater_montant( $val( 'montant' ) ) : '',
								'erreur'  => $erreurs['montant'] ?? '',
								'attrs'   => array( 'inputmode' => 'numeric', 'placeholder' => '25 000', 'data-montant' => true, 'autocomplete' => 'off' ),
							) );
							?>
							<p class="apercu" data-montant-lettres aria-live="polite"></p>
						</div>
						<?php ueb_choix_segments( 'tranche', 'Tranche payée', array( 1 => 'Tranche 1', 2 => 'Tranche 2', 3 => 'Les deux tranches' ), $val( 'tranche' ), $erreurs['tranche'] ?? '' ); ?>
					</div>
				</div>
			</section>

			<!-- Récapitulatif et génération -->
			<section class="carte quitus-final" aria-labelledby="section-final">
				<h2 id="section-final" class="quitus-final__titre">Vérifie, puis génère ton quitus</h2>
				<dl class="quitus-recap">
					<div>
						<dt>Type</dt>
						<dd data-recap-type><?php echo esc_html( $options_type[ $type_courant ] ?? '' ); ?></dd>
					</div>
					<div>
						<dt>Établissement</dt>
						<dd><img data-recap-logo src="<?php echo esc_url( ueb_logo_url( 'UEB' ) ); ?>" alt="" width="26" height="26" hidden><span data-recap-etab>À choisir</span></dd>
					</div>
					<div>
						<dt>Montant</dt>
						<dd data-recap-montant>—</dd>
					</div>
					<div>
						<dt>Tranche</dt>
						<dd data-recap-tranche>—</dd>
					</div>
				</dl>
				<p class="quitus-final__note"><?php echo ueb_icone( 'fichier', 18 ); ?>Le PDF contient 4 coupons identiques : étudiant, DAF, scolarité et banque.</p>
				<div class="quitus-final__actions">
					<button class="btn btn--primaire btn--large" type="submit"><?php echo $edite ? 'Enregistrer les modifications' : 'Générer mon quitus'; ?><?php echo ueb_icone( 'fleche', 18 ); ?></button>
					<a class="btn btn--fantome" href="<?php echo esc_url( ueb_url( 'mon-espace' ) ); ?>">Annuler</a>
				</div>
			</section>
		</form>
	</div>
	<script type="application/json" id="donnees-etablissements"><?php echo wp_json_encode( $donnees_js ); ?></script>
	<script type="application/json" id="frais-medicaux"><?php echo wp_json_encode( $montants_medicaux ); ?></script>
</main>
<?php
ueb_page_fin( 'espace' );
