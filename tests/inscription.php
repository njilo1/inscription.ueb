<?php
/**
 * Régressions inscription : tables MySQL TEMPORARY, données exclusivement fictives.
 * Usage : /opt/lampp/bin/php tests/inscription.php [répertoire des aperçus]
 * Charge WordPress en SHORTINIT : aucun hook du thème ni migration de la base réelle.
 */
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
define( 'SHORTINIT', true );
require dirname( __DIR__, 4 ) . '/wp-load.php';
require_once ABSPATH . WPINC . '/kses.php';
require_once ABSPATH . WPINC . '/general-template.php';
add_filter( 'kses_allowed_protocols', static fn( $protocoles ) => array_merge( $protocoles, array( 'file' ) ) );
define( 'UEB_INSC_DIR', dirname( __DIR__ ) );
define( 'UEB_INSC_URI', 'file://' . UEB_INSC_DIR );
foreach ( array( 'config', 'db-schema', 'comptes', 'nombres', 'inscription', 'quitus', 'profil', 'etudiants', 'quitus-pdf', 'recus', 'vues' ) as $module ) {
	require UEB_INSC_DIR . '/inc/' . $module . '.php';
}
class TestRedirect extends RuntimeException {}
function ueb_rediriger( $url ) { throw new TestRedirect( $url ); }
function ueb_url( $path = '' ) { return 'http://localhost/inscription-ueb/' . $path; }
function ueb_flash( $type, $message ) { $GLOBALS['flash_test'] = $message; }
function ueb_memoriser_saisie( $v, $e ) { $GLOBALS['erreurs_test'] = $e; }
function verifier( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( 'ÉCHEC : ' . $message ); }
	$GLOBALS['assertions'] = ( $GLOBALS['assertions'] ?? 0 ) + 1;
}
function soumettre( $post, $jeton = null ) {
	$GLOBALS['erreurs_test'] = array();
	$post += array( 'jeton_quitus' => $jeton ?? ueb_jeton_formulaire_quitus() );
	unset( $_SESSION['ueb_telechargement'] );
	$_POST = $post;
	try { ueb_action_enregistrer_quitus(); } catch ( TestRedirect $redirect ) {}
	return $GLOBALS['erreurs_test'];
}
function vider_quitus() {
	global $wpdb;
	// Ces noms sont masqués par les tables temporaires de cette connexion.
	$wpdb->query( 'DELETE FROM ueb_insc_quitus' );
	$wpdb->query( 'DELETE FROM ueb_insc_sequence' );
	$wpdb->query( 'DELETE FROM ueb_insc_profils' );
}

// Masquer toutes les tables métier auxquelles les tests écrivent, avant toute action.
foreach ( ueb_insc_schema() as $table => $sql ) {
	verifier( false !== $wpdb->query( str_replace( 'CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE', $sql ) ), 'création temporaire ' . $table );
}
$wpdb->query( 'ALTER TABLE ueb_insc_quitus DROP INDEX uniq_medical_droits, DROP COLUMN situation, DROP COLUMN filiere_id, DROP COLUMN quitus_droits_id' );
verifier( ueb_insc_migrer(), 'migration depuis le schéma précédent' );
verifier( ! array_diff( array( 'situation', 'filiere_id', 'quitus_droits_id' ), $wpdb->get_col( 'SHOW COLUMNS FROM ueb_insc_quitus' ) ), 'colonnes de migration présentes' );
verifier( ueb_insc_migrer(), 'migration idempotente' );

$annee = ueb_annee_academique();
$wpdb->insert( 'ueb_insc_comptes', array( 'id' => 1, 'matricule' => '24TEST01FS', 'telephone' => '699000000', 'mot_de_passe' => 'fixture-non-utilisable' ) );
$_SESSION = array( 'ueb_compte_id' => 1, 'ueb_version_session' => 1 );
$compte = ueb_compte_courant();
$catalogue = ueb_formations_inscription( true ); // avec les formations professionnelles
$fs = array_values( array_filter( $catalogue, static fn( $f ) => 'FS' === $f->etablissement && 'classique' === $f->type_formation ) );
$pro = array_values( array_filter( $catalogue, static fn( $f ) => 'pro' === $f->type_formation ) )[0];
verifier( count( $fs ) >= 3, 'catalogue classique disponible' );
$post = array(
 'etablissement' => 'FS', 'type' => 'droits', 'nom' => 'ÉTUDIANT TEST', 'prenom' => 'Marie Anne',
 'date_naissance' => '2002-04-12', 'lieu_naissance' => 'Ebolowa', 'sexe' => 'F', 'nationalite' => 'Camerounaise',
 'email' => 'marie.test@example.com', 'adresse' => 'Quartier Nko’ovos, Ebolowa', 'nom_urgence' => 'Jean Test',
 'numero_urgence' => '699111111', 'adresse_urgence' => 'Quartier Angalé, Ebolowa',
 'filiere_id' => $fs[0]->id, 'parcours' => 'M1', 'montant' => '25 000', 'tranche' => 1, 'situation' => 'ancien',
 'moyen_paiement' => 'CCA Bank',
);
$sortie = $argv[1] ?? sys_get_temp_dir() . '/ueb-inscription-review';
if ( ! is_dir( $sortie ) ) { mkdir( $sortie, 0700, true ); }

// Régression : les trois champs d'urgence masqués bloquaient toute génération.
$contact_vide = array( 'nom_urgence' => '', 'numero_urgence' => '', 'adresse_urgence' => '' );
$cms_vide = $contact_vide + array( 'email' => '', 'adresse' => '' );
$c = ueb_contexte_inscription( $compte );
list( $v, $e ) = ueb_valider_quitus( array_replace( $post, $contact_vide ), $c );
verifier( array_keys( $e ) === array_keys( $contact_vide ), 'les trois erreurs proviennent précisément du contact d’urgence' );
verifier( $v['nom'] === $post['nom'] && $v['email'] === $post['email'], 'saisie conservée après erreur CMS' );
list( $v, $e ) = ueb_valider_quitus( array_replace( $post, $cms_vide, array( 'situation' => 'nouveau' ) ), $c );
verifier( ! $e, 'droits seuls : coordonnées CMS vides autorisées' );
foreach ( array( '699111111', '699 11 11 11', '+237 699 11 11 11' ) as $telephone ) {
	list( $v, $e ) = ueb_valider_quitus( array_replace( $post, array( 'numero_urgence' => $telephone ) ), $c );
	verifier( ! $e && $v['numero_urgence'] === '699111111', 'téléphone d’urgence normalisé : ' . $telephone );
}
list( $v, $e ) = ueb_valider_quitus( array_replace( $post, array( 'numero_urgence' => '123' ) ), $c );
verifier( isset( $e['numero_urgence'] ), 'numéro d’urgence invalide toujours refusé' );

// Objet d'un reçu : ce que la scolarité doit rapprocher du relevé bancaire.
$objets_de = static fn( $type, $tranche ) => array_keys( ueb_objets_recu( (object) array( 'type' => $type, 'tranche' => $tranche ) ) );
verifier( $objets_de( 'droits', 1 ) === array( 'tranche1' ), 'reçu d’un quitus tranche 1 : première tranche seulement' );
verifier( $objets_de( 'droits', 2 ) === array( 'tranche2' ), 'reçu d’un quitus tranche 2 : deuxième tranche seulement' );
verifier( $objets_de( 'droits', 3 ) === array( 'totalite' ), 'reçu d’un quitus deux tranches : la totalité, en un seul reçu' );
verifier( $objets_de( 'medicaux', 0 ) === array( 'medicaux' ), 'reçu médical : frais médicaux' );
$quitus_deux = (object) array( 'type' => 'droits', 'tranche' => 3 );
verifier( array( 'totalite' ) === array_keys( ueb_objets_recu_libres( $quitus_deux, array() ) ) && array() === ueb_objets_recu_libres( $quitus_deux, array( (object) array( 'objet' => 'totalite' ) ) ), 'un seul reçu par paiement : fermé dès le premier envoi' );
verifier( 1 === UEB_RECUS_MAX_FICHIERS, 'un reçu au plus par quitus' );
verifier( 'Totalité' === ueb_libelle_objet_recu( (object) array( 'objet' => 'totalite' ) ) && 'Frais médicaux' === ueb_libelle_objet_recu( (object) array( 'objet' => '' ), 'medicaux' ), 'libellé de l’objet, repli sur le type pour les anciens reçus' );

// Formation classique : l'étudiant choisit sa tranche. Première : 25 000 à 45 000 par
// multiples de 5 000 ; deuxième : 50 000 moins la première, imposée ; les deux : 50 000.
vider_quitus();
$c = ueb_contexte_inscription( $compte );
verifier( array_keys( $c['tranches'] ) === array( 1, 3 ), 'sans quitus : première tranche ou les deux' );
verifier( ! array_filter( $c['formations'], static fn( $f ) => 'classique' !== $f->type_formation ), 'filières classiques seulement pour le moment' );
foreach ( array( '20 000', '24 999', '27 500', '50 000', '55 000', '' ) as $montant ) {
	list( $v, $e ) = ueb_valider_quitus( array_replace( $post, array( 'montant' => $montant, 'tranche' => 1 ) ), $c );
	verifier( isset( $e['montant'] ) && ! isset( $e['tranche'] ), 'première tranche refusée : ' . $montant );
}
foreach ( array( '25 000', '35 000', '45 000' ) as $montant ) {
	list( $v, $e ) = ueb_valider_quitus( array_replace( $post, array( 'montant' => $montant, 'tranche' => 1 ) ), $c );
	verifier( ! $e && 1 === $v['tranche'] && (int) preg_replace( '/\D+/', '', $montant ) === $v['montant'], 'première tranche acceptée : ' . $montant );
}
foreach ( array( '25 000', '', '999 999' ) as $montant ) {
	list( $v, $e ) = ueb_valider_quitus( array_replace( $post, array( 'montant' => $montant, 'tranche' => 3 ) ), $c );
	verifier( ! $e && 3 === $v['tranche'] && 50000 === $v['montant'], 'les deux tranches : 50 000 imposés (posté « ' . $montant . ' »)' );
}
list( $v, $e ) = ueb_valider_quitus( array_replace( $post, array( 'tranche' => 2 ) ), $c );
verifier( isset( $e['tranche'] ), 'deuxième tranche impossible avant la première' );
verifier( ! soumettre( array_replace( $post, array( 'montant' => '30 000', 'tranche' => 1 ) ) ), 'première tranche de 30 000' );
$c = ueb_contexte_inscription( $compte );
$regle = ueb_regle_droits_classiques( $c );
verifier( array_keys( $c['tranches'] ) === array( 2 ) && 20000 === $regle['reste'], 'après la première : seule la deuxième, de 20 000' );
verifier( 20000 === ueb_deuxieme_tranche_a_payer( ueb_quitus_du_compte( 1 ) ), 'deuxième tranche à payer affichée : 20 000' );
foreach ( array( '1 500', '20 000', '80 000', '' ) as $montant ) {
	list( $v, $e ) = ueb_valider_quitus( array_replace( $post, array( 'montant' => $montant, 'tranche' => 2, 'situation' => 'nouveau' ) ), $c );
	verifier( ! $e && 2 === $v['tranche'] && 20000 === $v['montant'], 'deuxième tranche : le reste (20 000) imposé, posté « ' . $montant . ' »' );
}
foreach ( array( 1, 3 ) as $tranche ) {
	list( $v, $e ) = ueb_valider_quitus( array_replace( $post, array( 'tranche' => $tranche, 'situation' => 'nouveau' ) ), $c );
	verifier( isset( $e['tranche'] ), 'tranche ' . $tranche . ' refermée après la première' );
}
verifier( ! soumettre( array_replace( $post, array( 'tranche' => 2, 'situation' => 'nouveau' ) ) ), 'deuxième tranche enregistrée' );
verifier( 0 === ueb_deuxieme_tranche_a_payer( ueb_quitus_du_compte( 1 ) ) && ! ueb_contexte_inscription( $compte )['tranches'], 'deuxième tranche préparée : plus rien à proposer' );

foreach ( array( 'ancien' => array( 28000, 53000 ), 'reprise' => array( 30000, 55000 ) ) as $situation => $totaux ) {
	foreach ( array( 1, 3 ) as $i => $tranche ) {
		vider_quitus();
		$p = array_replace( $post, array( 'situation' => $situation, 'tranche' => $tranche, 'montant' => 1 === $tranche ? '25 000' : '50 000', 'numero_urgence' => '+237 699 11 11 11' ) );
		$c = ueb_contexte_inscription( $compte );
		list( $v, $e ) = ueb_valider_quitus( $p, $c );
		verifier( ! $e, 'validation ' . $situation . '/' . $tranche . ' ' . json_encode( $e ) );
		$calcul = ueb_calculer_paiement( $fs[0], $tranche, $v['montant'], $situation, $c );
		verifier( $totaux[$i] === $calcul['total'], 'total attendu ' . $totaux[$i] );
		verifier( $v['montant'] === ( 1 === $tranche ? 25000 : 50000 ) && $v['tranche'] === $tranche, 'montant saisi et tranche déduite' );
		$jeton = ueb_jeton_formulaire_quitus();
		verifier( ! soumettre( $p, $jeton ), 'enregistrement ' . $situation . '/' . $tranche );
		$qs = ueb_quitus_du_compte( 1 );
		verifier( count( $qs ) === 2, 'deux quitus créés' );
		$q = array_values( array_filter( $qs, static fn( $q ) => 'droits' === $q->type ) )[0];
		$m = ueb_medical_du_dossier( $q );
		$dossiers = ueb_dossiers_quitus( $qs );
		verifier( count( $dossiers ) === 1 && $dossiers[0]['principal']->id === $q->id && $dossiers[0]['pages'] === 4 && $dossiers[0]['total'] === $totaux[$i], 'un seul dossier de quatre pages avec le total exact' );
		verifier( count( ueb_dossiers_quitus( array_reverse( $qs ) ) ) === 1, 'regroupement indépendant de l’ordre des paiements' );
		verifier( ueb_dossier_du_quitus( $m )['principal']->id === $q->id, 'les reçus médicaux restent accessibles depuis le dossier' );
		verifier( ueb_telechargement_a_demarrer( $compte )->numero === $q->numero, 'le PDF enregistré est proposé au téléchargement automatique' );
		verifier( null === ueb_telechargement_a_demarrer( $compte ), 'aucun second téléchargement au rafraîchissement' );
		verifier( $m && (int) $m->tranche === 0 && (int) $m->montant === UEB_FRAIS_MEDICAUX[$situation]['montant'], 'paiement médical unique associé' );
		verifier( $m->numero_urgence === '699111111' && $m->nom_urgence === $post['nom_urgence'] && $m->adresse_urgence === $post['adresse_urgence'], 'coordonnées d’urgence enregistrées pour les fiches CMS' );
		$pdf = ueb_generer_pdf_quitus( $q );
		verifier( $pdf->getNumPages() === 4, 'dossier de quatre pages' );
		$pdf->Output( $sortie . '/' . $situation . '-' . $tranche . '.pdf', 'F' );
		$GLOBALS['flash_test'] = '';
		soumettre( $p, $jeton );
		verifier( str_contains( $GLOBALS['flash_test'], 'déjà été envoyé' ), 'double soumission rejetée (jeton déjà utilisé)' );
		verifier( count( ueb_quitus_du_compte( 1 ) ) === 2, 'aucun doublon après double soumission' );
		if ( 1 === $tranche ) {
			$c = ueb_contexte_inscription( $compte );
			verifier( ! $c['medical_inclus'] && array_keys( $c['tranches'] ) === array( 2 ), 'deuxième tranche seule disponible' );
			verifier( ! soumettre( array_replace( $p, $cms_vide, array( 'tranche' => 2, 'situation' => 'nouveau' ) ) ), 'deuxième tranche, situation imposée et aucune coordonnée CMS exigée' );
			$qs = ueb_quitus_du_compte( 1 );
			verifier( count( $qs ) === 3, 'un seul quitus médical annuel' );
			$q2 = array_values( array_filter( $qs, static fn( $q ) => 2 === (int) $q->tranche ) )[0];
			verifier( count( ueb_dossiers_quitus( $qs ) ) === 2, 'deuxième tranche : un nouveau dossier, sans carte médicale supplémentaire' );
			verifier( (int) $q2->montant === 25000 && $q2->situation === $situation, 'deuxième tranche verrouillée' );
			$pdf = ueb_generer_pdf_quitus( $q2 );
			verifier( $pdf->getNumPages() === 1, 'deuxième tranche : une page' );
			$pdf->Output( $sortie . '/deuxieme-tranche.pdf', 'F' );
			verifier( ueb_generer_pdf_quitus( $q )->getNumPages() === 4, 'retéléchargement premier dossier inchangé' );
			$wpdb->update( 'ueb_insc_quitus', array( 'statut' => 'rejete' ), array( 'id' => $m->id ) );
			$c = ueb_contexte_inscription( $compte );
			verifier( ! $c['medical_inclus'], 'rejet de reçu sans nouveaux frais médicaux' );
		}
	}
}

// Un ancien paiement d'une autre année ne dispense pas de la nouvelle année.
$wpdb->query( "UPDATE ueb_insc_quitus SET annee_academique = '2020-2021'" );
verifier( ueb_contexte_inscription( $compte )['medical_inclus'], 'renouvellement annuel des frais' );
vider_quitus();
verifier( ! ueb_profil( 1 ), 'aucune fiche avant le premier quitus' );
verifier( ! soumettre( $post ), 'dossier initial pour modification' );
$q = $wpdb->get_row( "SELECT * FROM ueb_insc_quitus WHERE type = 'droits'" );
$fiche = ueb_profil( 1 );
verifier( 'ÉTUDIANT TEST' === ( $fiche['nom'] ?? '' ) && (int) $fiche['filiere_id'] === $fs[0]->id && 'M1' === $fiche['parcours'] && '699111111' === $fiche['numero_urgence'], 'premier quitus : fiche de l’étudiant créée' );
verifier( 'CCA Bank' === $q->moyen_paiement, 'lieu de paiement enregistré sur le quitus' );
verifier( ! soumettre( array_replace( $post, array( 'quitus_id' => $q->id, 'tranche' => 3, 'situation' => 'reprise', 'nom' => 'NOM MODIFIÉ', 'parcours' => 'L1', 'montant' => '50 000', 'moyen_paiement' => 'MTN Mobile Money' ) ) ), 'modification du dossier complet' );
$q = ueb_quitus_par_id( $q->id );
verifier( 'NOM MODIFIÉ' === $q->nom && 'L1' === $q->parcours, 'quitus pas encore envoyé : les champs de la fiche se modifient' );
$fiche = ueb_profil( 1 );
verifier( 'NOM MODIFIÉ' === ( $fiche['nom'] ?? '' ) && 'L1' === ( $fiche['parcours'] ?? '' ), 'modification du quitus : la fiche de l’étudiant suit' );
verifier( 'MTN Mobile Money' === $q->moyen_paiement && 50000 === (int) $q->montant && 3 === (int) $q->tranche, 'lieu de paiement et montant restent modifiables' );
verifier( ueb_profil_enregistrer( 1, array( 'nom' => 'NOM COMPTE' ) + ueb_profil( 1 ) ), 'correction de la fiche (Mon compte)' );
verifier( 'NOM MODIFIÉ' === ueb_quitus_par_id( $q->id )->nom, 'un quitus généré garde ses valeurs' );
verifier( ! soumettre( array_replace( $post, array( 'quitus_id' => $q->id, 'tranche' => 3, 'situation' => 'reprise', 'nom' => 'NOM COMPTE' ) ) ), 'quitus réenregistré après correction' );
$m = ueb_medical_du_dossier( $q );
verifier( (int) $m->montant === 5000 && 'NOM COMPTE' === $m->nom, 'synchronisation des deux quitus' );
$wpdb->update( 'ueb_insc_quitus', array( 'statut' => 'recu_envoye' ), array( 'id' => $m->id ) );
verifier( ! ueb_quitus_modifiable( $q ), 'dossier verrouillé après envoi reçu médical' );

// Matricule : lettres, chiffres, « - », « _ » ou « . », 3 à 30 caractères ; plus aucun numéro de dossier.
foreach ( array( 'ABC123', '17FS0042', '2024-UEB_17.B', 'UEB-2026-000123', str_repeat( 'A', 30 ) ) as $matricule ) {
	verifier( '' === ueb_erreur_matricule( ueb_normaliser_identifiant( $matricule ) ), 'matricule accepté : ' . $matricule );
}
verifier( '17FS0042' === ueb_normaliser_identifiant( ' 17fs 0042 ' ), 'matricule mis en majuscules, espaces retirés' );
foreach ( array( 'AB', 'MAT#01', 'MAT/01', 'MATRICULE+01', 'É12345', str_repeat( 'A', 31 ) ) as $matricule ) {
	verifier( UEB_MESSAGE_MATRICULE === ueb_erreur_matricule( ueb_normaliser_identifiant( $matricule ) ), 'matricule refusé : ' . $matricule );
}
verifier( 'Saisis ton matricule.' === ueb_erreur_matricule( '' ), 'matricule vide refusé' );

// Nouvel étudiant : il le déclare dans « Ta situation cette année ». Droits seuls, pas de visite médicale à payer.
vider_quitus();
$c = ueb_contexte_inscription( $compte );
verifier( ! isset( $c['nouveau'] ) && count( $c['formations'] ) === count( ueb_formations_inscription() ), 'plus de lien avec la préinscription : toutes les filières ouvertes' );
list( $v, $e ) = ueb_valider_quitus( array_replace( $post, array( 'filiere_id' => $fs[2]->id, 'situation' => 'nouveau' ) ), $c );
verifier( ! $e && 'nouveau' === $v['situation'], 'nouveau déclaré : toute filière ouverte de l’établissement acceptée' );
verifier( ! soumettre( array_replace( $post, $cms_vide, array( 'situation' => 'nouveau' ) ) ), 'enregistrement nouveau sans coordonnées CMS' );
$qs = ueb_quitus_du_compte( 1 );
verifier( count( $qs ) === 1 && (int) $qs[0]->montant === 25000, 'nouveau : droits seuls' );
verifier( ueb_dossiers_quitus( $qs )[0]['pages'] === 1, 'nouveau : une seule page sur la carte' );
$pdf = ueb_generer_pdf_quitus( $qs[0] );
verifier( $pdf->getNumPages() === 1, 'nouveau : PDF une page' );
$pdf->Output( $sortie . '/nouveau.pdf', 'F' );
verifier( 'matricule' === $qs[0]->type_identifiant && '24TEST01FS' === $qs[0]->identifiant, 'quitus identifié par le matricule' );
verifier( ! soumettre( array_replace( $post, array( 'tranche' => 2, 'situation' => 'nouveau' ) ) ), 'nouveau : deuxième tranche sans frais médicaux' );
verifier( ! array_filter( ueb_quitus_du_compte( 1 ), static fn( $q ) => 'medicaux' === $q->type ), 'nouveau : aucun quitus médical créé' );
// Formations professionnelles et appartenance à l'établissement.
vider_quitus();
$c = ueb_contexte_inscription( $compte );
foreach ( array( '', 'Banque inconnue' ) as $moyen ) {
	list( $v, $e ) = ueb_valider_quitus( array_replace( $post, array( 'moyen_paiement' => $moyen ) ), $c );
	verifier( isset( $e['moyen_paiement'] ), 'lieu de paiement refusé : « ' . $moyen . ' »' );
}
foreach ( UEB_MOYENS_PAIEMENT as $moyen ) {
	list( $v, $e ) = ueb_valider_quitus( array_replace( $post, array( 'moyen_paiement' => $moyen ) ), $c );
	verifier( ! $e && $moyen === $v['moyen_paiement'], 'lieu de paiement accepté : ' . $moyen );
}
list( $v, $e ) = ueb_valider_profil( array_replace( $post, $cms_vide ), $c['formations'], false );
verifier( ! $e, 'Mon compte : coordonnées CMS facultatives' );
list( $v, $e ) = ueb_valider_profil( array_replace( $post, array( 'nom' => '', 'filiere_id' => 0 ) ), $c['formations'], false );
verifier( isset( $e['nom'], $e['filiere_id'] ), 'Mon compte : nom et filière obligatoires' );
verifier( ! soumettre( array_replace( $post, $cms_vide, array( 'situation' => 'nouveau' ) ) ), 'premier quitus sans coordonnées CMS' );
verifier( ! isset( ueb_profil( 1 )['email'] ) && isset( ueb_profil( 1 )['nom'] ), 'champ vide de la fiche : laissé libre' );
verifier( ! soumettre( array_replace( $post, array( 'tranche' => 2, 'situation' => 'nouveau', 'nom' => 'AUTRE NOM' ) ) ), 'deuxième quitus : coordonnées complétées' );
verifier( 'marie.test@example.com' === ( ueb_profil( 1 )['email'] ?? '' ) && 'ÉTUDIANT TEST' === ueb_profil( 1 )['nom'], 'champ vide complété, champ renseigné inchangé' );
// Mon compte : l'action enregistre la fiche, ou la refuse sans la toucher.
$_POST = array_replace( $post, array( 'nom' => 'Nouveau Nom', 'parcours' => 'M2', 'etablissement' => 'FSEG', 'filiere_id' => 0 ) );
try { ueb_action_enregistrer_profil(); } catch ( TestRedirect $redirect ) {}
verifier( 'NOUVEAU NOM' === ueb_profil( 1 )['nom'] && 'M2' === ueb_profil( 1 )['parcours'] && str_contains( $GLOBALS['flash_test'], 'prochains quitus' ), 'Mon compte : fiche enregistrée' );
verifier( 'FS' === ueb_profil( 1 )['etablissement'] && (int) ueb_profil( 1 )['filiere_id'] === $fs[0]->id, 'Mon compte : établissement et filière inchangés' );
verifier( str_contains( $GLOBALS['flash_test'], 'Modifier' ), 'Mon compte : rappel pour mettre à jour un quitus non payé' );
$GLOBALS['erreurs_test'] = array();
$_POST = array_replace( $post, array( 'nom' => 'Autre', 'parcours' => 'niveau libre' ) );
try { ueb_action_enregistrer_profil(); } catch ( TestRedirect $redirect ) {}
verifier( isset( $GLOBALS['erreurs_test']['parcours'] ) && 'NOUVEAU NOM' === ueb_profil( 1 )['nom'], 'Mon compte : saisie invalide refusée, fiche inchangée' );
$_POST = array();
vider_quitus();
$c = ueb_contexte_inscription( $compte );
list( $v, $e ) = ueb_valider_quitus( array_replace( $post, array( 'filiere_id' => $pro->id, 'etablissement' => $pro->etablissement, 'montant' => '85 000' ) ), $c );
verifier( isset( $e['filiere_id'] ), 'formation professionnelle refusée tant qu’elle est fermée' );
$c_pro = array( 'formations' => ueb_formations_inscription( true ) ) + $c;
list( $v, $e ) = ueb_valider_quitus( array_replace( $post, array( 'filiere_id' => $pro->id, 'etablissement' => $pro->etablissement, 'montant' => '85 000' ) ), $c_pro );
verifier( ! $e && $v['montant'] === 85000, 'formations professionnelles rouvertes : tarif conservé' );
list( $v, $e ) = ueb_valider_quitus( array_replace( $post, array( 'etablissement' => 'FSEG' ) ), $c );
verifier( isset( $e['filiere_id'] ), 'filière étrangère à l’établissement refusée' );
list( $v, $e ) = ueb_valider_quitus( array_replace( $post, array( 'parcours' => 'niveau libre' ) ), $c );
verifier( isset( $e['parcours'] ), 'niveau hors liste refusé' );
list( $v, $e ) = ueb_valider_quitus( array_replace( $post, array( 'situation' => 'nouveau' ) ), $c );
verifier( ! $e && 'nouveau' === $v['situation'], 'situation nouveau disponible dans le formulaire' );

// Un échec sur le deuxième document annule aussi le premier et sa numérotation.
$panne_medicale = static function ( $sql ) {
	return str_starts_with( $sql, 'INSERT INTO `ueb_insc_quitus`' ) && str_contains( $sql, "'medicaux'" )
		? 'INSERT INTO ueb_insc_quitus (colonne_inexistante_test) VALUES (1)' : $sql;
};
$wpdb->suppress_errors( true );
add_filter( 'query', $panne_medicale );
verifier( isset( soumettre( $post )['general'] ), 'erreur contrôlée si le quitus médical échoue' );
remove_filter( 'query', $panne_medicale );
$wpdb->suppress_errors( false );
verifier( ! ueb_quitus_du_compte( 1 ), 'transaction annulée : aucun dossier partiel' );
verifier( ! $wpdb->get_var( 'SELECT COUNT(*) FROM ueb_insc_sequence' ), 'numérotation annulée avec le dossier' );
verifier( ! soumettre( array_replace( $post, array( 'actualiser_paiement' => '1' ) ) ), 'actualisation sans JavaScript' );
verifier( ! ueb_quitus_du_compte( 1 ), 'actualisation sans création de quitus' );
verifier( empty( $_SESSION['ueb_telechargement'] ), 'actualisation : aucun téléchargement' );

// L'étudiant ne peut pas ouvrir directement une deuxième tranche sans première.
list( $v, $e ) = ueb_valider_quitus( array_replace( $post, array( 'tranche' => 2 ) ), ueb_contexte_inscription( $compte ) );
verifier( isset( $e['tranche'] ), 'première tranche nécessaire : la tranche 2 seule est refusée' );

// Compatibilité d'un ancien quitus médical généré séparément.
verifier( ! soumettre( $post ), 'dossier pour compatibilité médicale' );
$wpdb->query( "UPDATE ueb_insc_quitus SET quitus_droits_id = NULL WHERE type = 'medicaux'" );
$wpdb->query( "DELETE FROM ueb_insc_quitus WHERE type = 'droits'" );
verifier( ! soumettre( $post ), 'droits après ancien quitus médical' );
verifier( (int) $wpdb->get_var( "SELECT COUNT(*) FROM ueb_insc_quitus WHERE type = 'medicaux'" ) === 1, 'ancien quitus médical réutilisé sans doublon' );
$medical_seul = $wpdb->get_row( "SELECT * FROM ueb_insc_quitus WHERE type = 'medicaux'" );
verifier( ueb_generer_pdf_quitus( $medical_seul )->getNumPages() === 3, 'téléchargement médical séparé : trois pages' );
verifier( count( ueb_dossiers_quitus( ueb_quitus_du_compte( 1 ) ) ) === 2, 'ancien médical autonome toujours disponible' );

// Statuts mixtes : ne jamais annoncer le dossier validé avec un paiement encore à traiter.
$droit_test = clone ueb_quitus_du_compte( 1 )[0];
$droit_test->id = 9001;
$droit_test->type = 'droits';
$droit_test->statut = 'verifie';
$medical_test = clone $medical_seul;
$medical_test->id = 9002;
$medical_test->quitus_droits_id = 9001;
foreach ( array( 'genere', 'recu_envoye', 'rejete', 'verifie' ) as $statut_test ) {
	$medical_test->statut = $statut_test;
	verifier( ueb_dossiers_quitus( array( $droit_test, $medical_test ) )[0]['statut'] === $statut_test, 'statut médical pris en compte : ' . $statut_test );
}
$medical_test->annee_academique = '2020-2021';
verifier( count( ueb_dossiers_quitus( array( $droit_test, $medical_test ) ) ) === 2, 'années différentes jamais regroupées' );
$medical_test->annee_academique = $droit_test->annee_academique;
$medical_test->compte_id = 9999;
verifier( count( ueb_dossiers_quitus( array( $droit_test, $medical_test ) ) ) === 2, 'comptes différents jamais regroupés' );
$_SESSION['ueb_telechargement'] = array( 'compte_id' => 9999, 'numero' => $medical_seul->numero );
verifier( null === ueb_telechargement_a_demarrer( $compte ), 'téléchargement automatique limité au compte créateur' );
$_SESSION['ueb_telechargement'] = array( 'compte_id' => 1, 'numero' => 'INEXISTANT' );
verifier( null === ueb_telechargement_a_demarrer( $compte ), 'aucun téléchargement pour un numéro absent' );
$_SESSION['ueb_bienvenue'] = 1;
verifier( ueb_bienvenue_a_afficher( $compte ) && ! ueb_bienvenue_a_afficher( $compte ), 'bienvenue affichée une seule fois après création' );
$_SESSION['ueb_bienvenue'] = 9999;
verifier( ! ueb_bienvenue_a_afficher( $compte ), 'bienvenue réservée au compte créé' );

// Exports HTML : même template et même JS que le site, données fictives.
function ueb_reprendre_saisie() { return array( $GLOBALS['saisie_test'] ?? array(), $GLOBALS['erreurs_apercu'] ?? array() ); }
function ueb_champ_csrf() {}
foreach ( array( 'ancien', 'reprise', 'nouveau', 'deuxieme', 'erreurs-cms' ) as $cas ) {
	vider_quitus();
	if ( $cas === 'deuxieme' ) { soumettre( $post ); }
	$GLOBALS['saisie_test'] = array_replace( $post, array( 'situation' => array( 'reprise' => 'reprise', 'nouveau' => 'nouveau' )[ $cas ] ?? 'ancien', 'tranche' => $cas === 'deuxieme' ? 2 : 1 ) );
	$GLOBALS['erreurs_apercu'] = array();
	if ( 'erreurs-cms' === $cas ) {
		list( $GLOBALS['saisie_test'], $GLOBALS['erreurs_apercu'] ) = ueb_valider_quitus( array_replace( $post, $contact_vide ), ueb_contexte_inscription( $compte ) );
	}
	$_GET = array();
	// Les wrappers du thème dépendent de WordPress complet : extraire uniquement le main.
	$template = file_get_contents( UEB_INSC_DIR . '/templates/quitus.php' );
	$template = preg_replace( '/ueb_page_debut\\(.*?\\);/s', '', $template );
	$template = str_replace( "ueb_page_fin( 'espace' );", '', $template );
	$template = str_replace( 'ueb_afficher_flash();', '', $template );
	ob_start();
	eval( '?>' . $template );
	$html = ob_get_clean();
	$base = UEB_INSC_URI;
	file_put_contents( $sortie . '/' . $cas . '.html', '<!doctype html><html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="' . $base . '/assets/css/polices.css"><link rel="stylesheet" href="' . $base . '/assets/css/app.css"><link rel="stylesheet" href="' . $base . '/assets/css/pages.css"><body>' . $html . '<script src="' . $base . '/assets/js/app.js"></script></body></html>' );
}
// Tableau de bord, historique et navigation entre les reçus, avec les vraies vues.
if ( ! function_exists( 'get_query_var' ) ) {
	function get_query_var( $cle ) { return $GLOBALS['query_test'][ $cle ] ?? ''; }
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $chemin = '' ) { return 'http://localhost/inscription-ueb' . $chemin; }
}
function exporter_vue_espace( $nom, $fichier, $sortie ) {
	$template = file_get_contents( UEB_INSC_DIR . '/templates/' . $fichier . '.php' );
	$template = preg_replace( '/ueb_page_debut\\(.*?\\);/s', '', $template );
	$template = str_replace( array( "ueb_page_fin( 'espace' );", 'ueb_afficher_flash();' ), '', $template );
	ob_start();
	ueb_entete_site( 'espace' );
	eval( '?>' . $template );
	$html = ob_get_clean();
	$base = UEB_INSC_URI;
	$document = '<!doctype html><html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="' . $base . '/assets/css/polices.css"><link rel="stylesheet" href="' . $base . '/assets/css/app.css"><link rel="stylesheet" href="' . $base . '/assets/css/pages.css"><body class="variante-espace">' . $html . '<script src="' . $base . '/assets/js/app.js"></script></body></html>';
	file_put_contents( $sortie . '/' . $nom . '.html', $document );
	return $html;
}
foreach ( array( 'vide', 'ancien', 'nouveau', 'deuxieme', 'mixte', 'rejete', 'verifie', 'archives', 'nouvelle-annee', 'medical-seul', 'bienvenue', 'telechargement' ) as $cas_espace ) {
	vider_quitus();
	if ( ! in_array( $cas_espace, array( 'vide', 'bienvenue' ), true ) ) {
		verifier( ! soumettre( 'nouveau' === $cas_espace ? array_replace( $post, $cms_vide, array( 'situation' => 'nouveau' ) ) : $post ), 'création pour aperçu : ' . $cas_espace );
		if ( 'deuxieme' === $cas_espace ) {
			verifier( ! soumettre( array_replace( $post, array( 'tranche' => 2 ) ) ), 'second dossier pour la deuxième tranche' );
		}
		if ( in_array( $cas_espace, array( 'mixte', 'rejete', 'verifie' ), true ) ) {
			$wpdb->query( "UPDATE ueb_insc_quitus SET statut = 'verifie' WHERE type = 'droits'" );
			$statut_medical = array( 'mixte' => 'recu_envoye', 'rejete' => 'rejete', 'verifie' => 'verifie' )[ $cas_espace ];
			$wpdb->update( 'ueb_insc_quitus', array( 'statut' => $statut_medical, 'motif_rejet' => 'Le montant du reçu médical est illisible.' ), array( 'type' => 'medicaux' ) );
		}
		if ( in_array( $cas_espace, array( 'archives', 'nouvelle-annee' ), true ) ) {
			$wpdb->query( "UPDATE ueb_insc_quitus SET annee_academique = '2020-2021'" );
			verifier( array_keys( ueb_contexte_inscription( $compte )['tranches'] ) === array( 1, 3 ), 'nouvelle année : les tranches sont de nouveau disponibles' );
			verifier( ueb_contexte_inscription( $compte )['medical_inclus'], 'nouvelle année : frais médicaux renouvelés' );
			if ( 'archives' === $cas_espace ) {
				// Nouvelle année : le niveau se met à jour dans Mon compte, le quitus le reprend.
				verifier( ueb_profil_enregistrer( 1, array( 'parcours' => 'M2' ) + ueb_profil( 1 ) ), 'nouveau niveau enregistré dans Mon compte' );
				verifier( ! soumettre( $post ), 'nouvelle inscription sans effacer les archives' );
			}
		}
		if ( 'medical-seul' === $cas_espace ) {
			$wpdb->query( "DELETE FROM ueb_insc_quitus WHERE type = 'droits'" );
			$wpdb->query( "UPDATE ueb_insc_quitus SET quitus_droits_id = NULL" );
		}
	}
	if ( 'telechargement' !== $cas_espace ) { unset( $_SESSION['ueb_telechargement'] ); }
	// Dates explicites pour les aperçus, indépendantes des valeurs par défaut de la base locale.
	$wpdb->query( "UPDATE ueb_insc_quitus SET date_creation = '2026-09-17 09:00:00'" );
	$wpdb->query( "UPDATE ueb_insc_quitus SET date_creation = '2020-10-13 10:00:00' WHERE annee_academique = '2020-2021'" );
	if ( 'bienvenue' === $cas_espace ) { $_SESSION['ueb_bienvenue'] = 1; }
	$GLOBALS['query_test'] = array( 'ueb_page' => 'espace' );
	if ( ! in_array( $cas_espace, array( 'bienvenue', 'telechargement' ), true ) ) { // messages à usage unique
		verifier( ! str_contains( exporter_vue_espace( 'espace-accueil-' . $cas_espace, 'espace', $sortie ), 'data-dossier>' ), 'accueil sans liste de quitus : ' . $cas_espace );
	}
	$_GET['vue'] = 'quitus';
	$html = exporter_vue_espace( 'espace-' . $cas_espace, 'espace', $sortie );
	unset( $_GET['vue'] );
	$cartes = in_array( $cas_espace, array( 'deuxieme', 'archives' ), true ) ? 2 : ( in_array( $cas_espace, array( 'vide', 'bienvenue' ), true ) ? 0 : 1 );
	verifier( substr_count( $html, 'data-dossier>' ) === $cartes, 'une carte par dossier : ' . $cas_espace );
	verifier( substr_count( $html, 'data-dossier-pdf' ) === $cartes, 'un seul bouton PDF par carte : ' . $cas_espace );
	verifier( str_contains( $html, 'class="liste-quitus"' ) && ! str_contains( $html, 'parcours__frise' ), 'Mes quitus : la liste seule, sans les étapes' );
	if ( 'bienvenue' === $cas_espace ) {
		verifier( str_contains( $html, 'data-bienvenue' ), 'popup présent après création' );
		verifier( ! str_contains( exporter_vue_espace( 'espace-retour', 'espace', $sortie ), 'data-bienvenue' ), 'pas de popup au retour' );
	}
	if ( 'nouvelle-annee' === $cas_espace ) {
		verifier( str_contains( $html, 'Aucun quitus pour ' . $annee['libelle'] ), 'invitation à se réinscrire malgré les dossiers archivés' );
		verifier( ! str_contains( $html, 'data-dossier-modifier' ), 'archives non modifiables' );
	}
	if ( 'archives' === $cas_espace ) {
		verifier( substr_count( $html, 'class="quitus-annee"' ) === 2, 'deux années distinctes à l’écran' );
		verifier( str_contains( $html, 'Master 2' ) && str_contains( $html, 'Master 1' ), 'niveaux conservés séparément pour chaque année' );
	}
	if ( 'telechargement' === $cas_espace ) {
		verifier( str_contains( $html, 'data-telechargement-auto' ), 'téléchargement automatique après génération' );
		$_GET['vue'] = 'quitus';
		verifier( ! str_contains( exporter_vue_espace( 'espace-apres-telechargement', 'espace', $sortie ), 'data-telechargement-auto' ), 'rafraîchissement sans nouveau téléchargement' );
		unset( $_GET['vue'] );
	}
	if ( 'ancien' === $cas_espace ) {
		$dossier = ueb_dossiers_quitus( ueb_quitus_du_compte( 1 ) )[0];
		foreach ( $dossier['paiements'] as $paiement ) {
			$GLOBALS['query_test'] = array( 'ueb_page' => 'recus', 'ueb_arg' => $paiement->numero );
			$recus_html = exporter_vue_espace( 'recus-' . $paiement->type, 'recus', $sortie );
			verifier( str_contains( $recus_html, 'Choisir le paiement du dossier' ) && str_contains( $recus_html, 'Frais médicaux' ), 'navigation reçus pour : ' . $paiement->type );
		}
	}
}
// Suivi des paiements : trois étudiants aux montants choisis pour exercer chaque règle.
if ( ! function_exists( 'ueb_etab_agent' ) ) {
	function ueb_etab_agent( $user_id = 0 ) { return ''; } // SHORTINIT : aucun agent connecté
}
require_once UEB_INSC_DIR . '/inc/gestion.php';
vider_quitus();
$compte->numero_dossier = null;
verifier( ! soumettre( $post ), 'quitus de référence pour le suivi' );
$modele = (array) $wpdb->get_row( "SELECT * FROM ueb_insc_quitus WHERE type = 'droits'" );
vider_quitus();
$poser = static function ( $compte_id, $montant, $statut, $jours ) use ( $wpdb, $modele ) {
	static $n = 0;
	$n++;
	$ligne = array_replace( $modele, array( 'numero' => 'TEST-SUIVI-' . $n, 'code_verif' => str_pad( (string) $n, 20, '0' ), 'compte_id' => $compte_id, 'montant' => $montant, 'statut' => $statut, 'date_creation' => gmdate( 'Y-m-d H:i:s', time() - $jours * DAY_IN_SECONDS ) ) );
	unset( $ligne['id'], $ligne['nb_recus'] );
	return false !== $wpdb->insert( 'ueb_insc_quitus', $ligne );
};
verifier( $poser( 1, 25000, 'verifie', 3 ) && $poser( 1, 25000, 'recu_envoye', 1 ), 'étudiant A : une tranche vérifiée, une en vérification' );
verifier( $poser( 2, 50000, 'genere', 2 ), 'étudiant B : les deux tranches déclarées' );
verifier( $poser( 3, 60000, 'verifie', 2 ), 'étudiant C : trop-perçu vérifié' );
$suivi = ueb_suivi_paiements( $annee['code'] );
$g = $suivi['global'];
verifier( 3 === $g['etudiants'] && 150000 === $g['attendu'], 'suivi : 3 étudiants, 150 000 attendus' );
verifier( 75000 === $g['encaisse'] && 25000 === $g['verification'] && 50000 === $g['declare'] && 0 === $g['non_declare'], 'suivi : ventilation exacte ' . json_encode( $g ) );
verifier( $g['encaisse'] + $g['verification'] + $g['declare'] + $g['non_declare'] === $g['attendu'], 'suivi : les quatre parts font l’attendu' );
verifier( 1 === $g['soldes'] && 1 === $g['partiels'] && 1 === $g['aucun'] && 10000 === $g['trop_percu'], 'suivi : soldés, partiels, aucun, trop-perçu' );
verifier( 50.0 === (float) ueb_suivi_taux( $g ), 'suivi : taux de recouvrement 50 %' );
verifier( 1 === count( $suivi['filieres'] ) && 3 === reset( $suivi['filieres'] )['etudiants'], 'suivi : une filière, trois étudiants' );
verifier( '50 %' === ueb_pourcent( 50 ) && '7,2 %' === ueb_pourcent( 7.2 ), 'suivi : pourcentages à la française' );
// Progression de l'année : mêmes quitus, sans reçu en base (repli sur la date de décision).
$activite = ueb_gestion_activite( $annee['code'] );
$croissant = static function ( array $serie ) {
	$trie = $serie;
	sort( $trie );
	return $trie === $serie;
};
verifier( count( $activite['jours'] ) >= 4 && count( $activite['jours'] ) === count( $activite['generes'] ), 'activité : un point par jour depuis le premier quitus' );
verifier( 4 === end( $activite['generes'] ) && 3 === end( $activite['envoyes'] ) && 2 === end( $activite['verifies'] ), 'activité : cumuls finaux 4 / 3 / 2 ' . json_encode( $activite ) );
verifier( $croissant( $activite['generes'] ) && $croissant( $activite['envoyes'] ) && $croissant( $activite['verifies'] ), 'activité : cumuls jamais décroissants' );
verifier( ! array_filter( array_keys( $activite['jours'] ), static fn( $i ) => $activite['envoyes'][ $i ] < $activite['verifies'][ $i ] || $activite['generes'][ $i ] < $activite['envoyes'][ $i ] ), 'activité : générés ⊇ envoyés ⊇ vérifiés chaque jour ' . json_encode( $activite ) );
$fenetre = ueb_gestion_activite( $annee['code'], '', 2 );
verifier( 2 === count( $fenetre['jours'] ) && 4 === end( $fenetre['generes'] ) && $fenetre['generes'][0] >= 3, 'activité : fenêtre de 2 jours, antérieur reporté au départ' );
verifier( array( 0 ) === ueb_gestion_activite( $annee['code'], 'ETAB-INCONNU' )['generes'], 'activité : établissement sans quitus = un jour à zéro' );
vider_quitus();

// Étudiants UEB : dernier quitus de droits de l'année par compte, portée imposée, états du paiement.
$ins = static function ( $compte_id, $etab, $nom, $prenom, $tranche, $montant, $statut, $extra = array() ) use ( $wpdb, $annee, $fs ) {
	static $n = 0;
	$n++;
	$wpdb->insert( 'ueb_insc_quitus', $extra + array(
		'numero' => 'ETU-' . $n, 'code_verif' => substr( md5( 'etu' . $n ), 0, 20 ), 'compte_id' => $compte_id, 'etablissement' => $etab,
		'annee_academique' => $annee['code'], 'type' => 'droits', 'situation' => 'ancien', 'filiere_id' => $fs[0]->id,
		'identifiant' => '24ETU' . $compte_id, 'type_identifiant' => 'matricule', 'nom' => $nom, 'prenom' => $prenom,
		'date_naissance' => '2003-01-01', 'lieu_naissance' => 'Ebolowa', 'sexe' => 'F', 'nationalite' => 'Camerounaise',
		'departement' => $fs[0]->libelle, 'parcours' => 'L2', 'montant' => $montant, 'tranche' => $tranche, 'statut' => $statut,
	) );
};
$ins( 201, 'FS', 'ABENA', 'Claire', 1, 25000, 'verifie' );
$ins( 201, 'FS', 'ABENA', 'Claire', 2, 25000, 'verifie' );                 // soldé
$ins( 202, 'FS', 'BELINGA', 'Paul', 1, 30000, 'verifie', array( 'sexe' => 'M', 'parcours' => 'L1' ) ); // partiel
$ins( 203, 'FS', 'ETOA', 'Marie', 1, 25000, 'recu_envoye' );               // aucun, reçu à vérifier
$ins( 204, 'FSEG', 'MVONDO', 'Jean', 3, 50000, 'verifie', array( 'sexe' => 'M' ) ); // autre établissement
$ins( 205, 'FS', 'NDZANA', 'Ange', 1, 25000, 'genere', array( 'annee_academique' => '2020-2021' ) ); // autre année
$wpdb->insert( 'ueb_insc_quitus', array( 'numero' => 'ETU-MED', 'code_verif' => 'etu-medical-00000000', 'compte_id' => 206, 'etablissement' => 'FS', 'annee_academique' => $annee['code'], 'type' => 'medicaux', 'situation' => 'ancien', 'identifiant' => '24ETU206', 'type_identifiant' => 'matricule', 'nom' => 'MEDICAL', 'prenom' => 'Seul', 'date_naissance' => '2003-01-01', 'lieu_naissance' => 'Ebolowa', 'sexe' => 'F', 'nationalite' => 'Camerounaise', 'departement' => 'x', 'parcours' => 'L1', 'montant' => 3000, 'tranche' => 0, 'statut' => 'verifie' ) );
$f = static fn( $extra = array() ) => array_merge( array( 'annee' => $annee['code'], 'etab' => '', 'filiere' => 0, 'niveau' => '', 'sexe' => '', 'situation' => '', 'statut' => '', 'q' => '', 'p' => 1 ), $extra );
$tout = ueb_etudiants( $f(), array( 'FS', 'FSEG' ) );
verifier( 4 === $tout['total'] && array( 'ABENA', 'BELINGA', 'ETOA', 'MVONDO' ) === array_map( static fn( $l ) => $l->nom, $tout['lignes'] ), 'étudiants : un par compte, droits de l’année seulement, triés par nom' );
verifier( 50000 === (int) $tout['lignes'][0]->verifie && 'solde' === $tout['lignes'][0]->etat && 'partiel' === $tout['lignes'][1]->etat && 'aucun' === $tout['lignes'][2]->etat, 'étudiants : soldé, partiel, aucun paiement vérifié' );
verifier( array( 'tous' => 4, 'solde' => 2, 'partiel' => 1, 'aucun' => 1, 'a_verifier' => 1 ) === $tout['compteurs'], 'étudiants : compteurs des onglets ' . json_encode( $tout['compteurs'] ) );
$fs_seul = ueb_etudiants( $f(), array( 'FS' ) );
verifier( 3 === $fs_seul['total'] && ! array_filter( $fs_seul['lignes'], static fn( $l ) => 'FS' !== $l->etablissement ), 'étudiants : portée « un établissement », jamais les autres' );
verifier( 0 === ueb_etudiants( $f( array( 'etab' => 'FSEG' ) ), array( 'FS' ) )['total'], 'étudiants : établissement hors portée, aucun résultat' );
verifier( 0 === ueb_etudiants( $f(), array() )['total'], 'étudiants : portée vide, liste vide' );
verifier( 1 === ueb_etudiants( $f( array( 'statut' => 'partiel' ) ), array( 'FS', 'FSEG' ) )['total'], 'étudiants : filtre « paiement partiel »' );
verifier( 'ETOA' === ueb_etudiants( $f( array( 'statut' => 'a_verifier' ) ), array( 'FS', 'FSEG' ) )['lignes'][0]->nom, 'étudiants : filtre « reçu à vérifier »' );
verifier( 2 === ueb_etudiants( $f( array( 'sexe' => 'M' ) ), array( 'FS', 'FSEG' ) )['total'] && 1 === ueb_etudiants( $f( array( 'niveau' => 'L1' ) ), array( 'FS', 'FSEG' ) )['total'], 'étudiants : filtres sexe et niveau' );
foreach ( array( 'belin', 'Paul BELINGA', '24ETU202', '%' ) as $recherche ) {
	$trouve = ueb_etudiants( $f( array( 'q' => $recherche ) ), array( 'FS', 'FSEG' ) );
	verifier( '%' === $recherche ? 0 === $trouve['total'] : ( 1 === $trouve['total'] && 'BELINGA' === $trouve['lignes'][0]->nom ), 'étudiants : recherche « ' . $recherche . ' »' );
}
verifier( 1 === ueb_etudiants( $f( array( 'annee' => '2020-2021' ) ), array( 'FS', 'FSEG' ) )['total'], 'étudiants : autre année' );
verifier( isset( $tout['filieres']['FS'][ $fs[0]->id ], $tout['filieres']['FSEG'] ) && 3 === $tout['filieres']['FS'][ $fs[0]->id ][1], 'étudiants : filières proposées par établissement, avec leur effectif' );
verifier( 1 === $tout['pages'] && 1 === ueb_etudiants( $f( array( 'p' => 9 ) ), array( 'FS', 'FSEG' ) )['page'], 'étudiants : page hors limite ramenée à la dernière' );
verifier( 'http://x/?vue=etudiants&etab=FS' === ueb_etudiants_url( 'http://x/', array( 'vue' => 'etudiants', 'etab' => 'FS', 'q' => '', 'p' => 1 ) ), 'étudiants : adresse sans filtres vides ni page 1' );
$wpdb->query( "DELETE FROM ueb_insc_quitus WHERE numero LIKE 'ETU-%'" );

// Mot de passe oublié : la réinitialisation par la scolarité vaut UEB_REINIT_DUREE (1 h).
$il_y_a = static fn( $secondes ) => gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $secondes );
verifier( HOUR_IN_SECONDS === UEB_REINIT_DUREE, 'réinitialisation valable 1 heure' );
verifier( '' === ueb_reinit_etat( (object) array( 'doit_changer_mdp' => 0, 'reinit_le' => null ) ), 'aucune réinitialisation : état vide' );
verifier( 'active' === ueb_reinit_etat( (object) array( 'doit_changer_mdp' => 1, 'reinit_le' => $il_y_a( 10 * MINUTE_IN_SECONDS ) ) ), 'réinitialisée il y a 10 min : active' );
verifier( 'active' === ueb_reinit_etat( (object) array( 'doit_changer_mdp' => 1, 'reinit_le' => $il_y_a( 59 * MINUTE_IN_SECONDS ) ) ), 'réinitialisée il y a 59 min : encore active' );
verifier( 'expiree' === ueb_reinit_etat( (object) array( 'doit_changer_mdp' => 1, 'reinit_le' => $il_y_a( 61 * MINUTE_IN_SECONDS ) ) ), 'réinitialisée il y a 61 min : expirée' );
verifier( '' === ueb_reinit_etat( (object) array( 'doit_changer_mdp' => 1, 'reinit_le' => null ) ), 'mot de passe provisoire de la cellule (sans heure) : pas une réinitialisation' );
verifier( '' === ueb_reinit_etat( null ), 'compte inconnu : aucune réinitialisation' );
verifier( str_contains( ueb_badge_mdp( (object) array( 'doit_changer_mdp' => 1, 'reinit_le' => $il_y_a( 60 ) ) ), 'jusqu’à' ), 'badge : réinitialisé, jusqu’à l’heure de fin' );
verifier( str_contains( ueb_badge_mdp( (object) array( 'doit_changer_mdp' => 1, 'reinit_le' => $il_y_a( 2 * HOUR_IN_SECONDS ) ) ), 'expirée' ), 'badge : réinitialisation expirée' );
verifier( '' === ueb_badge_mdp( (object) array( 'doit_changer_mdp' => 0, 'reinit_le' => null ) ), 'badge : rien pour un compte normal' );
verifier( ! str_contains( ueb_reinit_heure( current_time( 'timestamp' ) ), ' à ' ), 'heure de fin du jour même : sans date' );

// Filières par niveau : table vide, chaque filière est ouverte à tous les niveaux ; remplie, elle seule compte.
verifier( ueb_filiere_ouverte_au_niveau( ueb_formations_inscription()[ $fs[0]->id ], 'M2' ), 'sans niveaux importés : filière ouverte à tous les niveaux' );
$wpdb->insert( 'ueb_filieres_niveaux', array( 'filiere_id' => $fs[0]->id, 'niveau' => 'L1' ) );
$wpdb->insert( 'ueb_filieres_niveaux', array( 'filiere_id' => $fs[1]->id, 'niveau' => 'M1' ) );
$par_niveau = ueb_formations_inscription();
verifier( array( 'L1' ) === $par_niveau[ $fs[0]->id ]->niveaux && ueb_filiere_ouverte_au_niveau( $par_niveau[ $fs[0]->id ], 'L1' ) && ! ueb_filiere_ouverte_au_niveau( $par_niveau[ $fs[0]->id ], 'M1' ), 'filière ouverte à ses seuls niveaux' );
verifier( array() === $par_niveau[ $fs[2]->id ]->niveaux, 'filière sans niveau : proposée nulle part' );
list( , $e ) = ueb_valider_profil( array_replace( $post, array( 'filiere_id' => $fs[0]->id, 'parcours' => 'M1' ) ), $par_niveau, false );
verifier( isset( $e['filiere_id'] ) && str_contains( $e['filiere_id'], 'pas ouverte en M1' ), 'filière refusée hors de ses niveaux' );
list( , $e ) = ueb_valider_profil( array_replace( $post, array( 'filiere_id' => $fs[1]->id, 'parcours' => 'M1' ) ), $par_niveau, false );
verifier( ! isset( $e['filiere_id'] ), 'filière acceptée à son niveau' );
ueb_profil_enregistrer( 1, array( 'etablissement' => 'FS', 'filiere_id' => $fs[0]->id, 'parcours' => 'M1' ) + ueb_profil( 1 ) );
verifier( ! isset( ueb_profil_fige( 1, $par_niveau )['filiere_id'] ) && 'FS' === ueb_profil_fige( 1, $par_niveau )['etablissement'], 'nouveau niveau : la filière de la fiche n’est plus figée, l’établissement reste figé' );
ueb_profil_enregistrer( 1, array( 'parcours' => 'L1' ) + ueb_profil( 1 ) );
verifier( (int) ueb_profil_fige( 1, $par_niveau )['filiere_id'] === $fs[0]->id, 'filière ouverte au niveau de la fiche : figée' );
$wpdb->query( 'DELETE FROM ueb_filieres_niveaux' );

echo $GLOBALS['assertions'] . " vérifications réussies. Aperçus : $sortie\n";
