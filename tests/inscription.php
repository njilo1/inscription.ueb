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
foreach ( array( 'config', 'db-schema', 'comptes', 'nombres', 'inscription', 'quitus', 'quitus-pdf', 'recus', 'vues' ) as $module ) {
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
function soumettre( $post ) {
	$GLOBALS['erreurs_test'] = array();
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
}

// Masquer toutes les tables métier auxquelles les tests écrivent, avant toute action.
foreach ( ueb_insc_schema() as $table => $sql ) {
	verifier( false !== $wpdb->query( str_replace( 'CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE', $sql ) ), 'création temporaire ' . $table );
}
verifier( false !== $wpdb->query( "CREATE TEMPORARY TABLE ueb_preinscriptions (
 id INT PRIMARY KEY, numero_dossier VARCHAR(30), statut VARCHAR(20), nom VARCHAR(100), prenom VARCHAR(150), email VARCHAR(150), adresse VARCHAR(255), nom_urgence VARCHAR(150), numero_urgence VARCHAR(20), adresse_urgence VARCHAR(255),
 date_naissance DATE, lieu_naissance VARCHAR(150), sexe CHAR(1), nationalite_id INT, faculte_id INT,
 filiere_1_id INT, filiere_2_id INT, filiere_3_id INT, niveau_lmd_id INT
)" ), 'préinscriptions fictives isolées' );
$wpdb->query( 'ALTER TABLE ueb_insc_quitus DROP INDEX uniq_medical_droits, DROP COLUMN situation, DROP COLUMN filiere_id, DROP COLUMN quitus_droits_id' );
verifier( ueb_insc_migrer(), 'migration depuis le schéma précédent' );
verifier( ! array_diff( array( 'situation', 'filiere_id', 'quitus_droits_id' ), $wpdb->get_col( 'SHOW COLUMNS FROM ueb_insc_quitus' ) ), 'colonnes de migration présentes' );
verifier( ueb_insc_migrer(), 'migration idempotente' );

$annee = ueb_annee_academique();
$wpdb->insert( 'ueb_insc_comptes', array( 'id' => 1, 'matricule' => '24TEST01FS', 'telephone' => '699000000', 'mot_de_passe' => 'fixture-non-utilisable' ) );
$_SESSION = array( 'ueb_compte_id' => 1, 'ueb_version_session' => 1 );
$compte = ueb_compte_courant();
$catalogue = ueb_formations_inscription( $compte, false );
$fs = array_values( array_filter( $catalogue, static fn( $f ) => 'FS' === $f->etablissement && 'classique' === $f->type_formation ) );
$pro = array_values( array_filter( $catalogue, static fn( $f ) => 'pro' === $f->type_formation ) )[0];
verifier( count( $fs ) >= 3, 'catalogue classique disponible' );
$post = array(
 'etablissement' => 'FS', 'type' => 'droits', 'nom' => 'ÉTUDIANT TEST', 'prenom' => 'Marie Anne',
 'date_naissance' => '2002-04-12', 'lieu_naissance' => 'Ebolowa', 'sexe' => 'F', 'nationalite' => 'Camerounaise',
 'email' => 'marie.test@example.com', 'adresse' => 'Quartier Nko’ovos, Ebolowa', 'nom_urgence' => 'Jean Test',
 'numero_urgence' => '699111111', 'adresse_urgence' => 'Quartier Angalé, Ebolowa',
 'filiere_id' => $fs[0]->id, 'parcours' => 'M1', 'montant' => '1', 'tranche' => 1, 'situation' => 'ancien',
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

foreach ( array( 'ancien' => array( 28000, 53000 ), 'reprise' => array( 30000, 55000 ) ) as $situation => $totaux ) {
	foreach ( array( 1, 3 ) as $i => $tranche ) {
		vider_quitus();
		$p = array_replace( $post, array( 'situation' => $situation, 'tranche' => $tranche, 'numero_urgence' => '+237 699 11 11 11' ) );
		$c = ueb_contexte_inscription( $compte );
		list( $v, $e ) = ueb_valider_quitus( $p, $c );
		verifier( ! $e, 'validation ' . $situation . '/' . $tranche . ' ' . json_encode( $e ) );
		$calcul = ueb_calculer_paiement( $fs[0], $tranche, 1, $situation, $c );
		verifier( $totaux[$i] === $calcul['total'], 'total attendu ' . $totaux[$i] );
		verifier( $v['montant'] === ( 1 === $tranche ? 25000 : 50000 ), 'montant falsifié remplacé' );
		verifier( ! soumettre( $p ), 'enregistrement ' . $situation . '/' . $tranche );
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
		verifier( (bool) soumettre( $p ), 'double soumission rejetée' );
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
verifier( ! soumettre( $post ), 'dossier initial pour modification' );
$q = $wpdb->get_row( "SELECT * FROM ueb_insc_quitus WHERE type = 'droits'" );
verifier( ! soumettre( array_replace( $post, array( 'quitus_id' => $q->id, 'tranche' => 3, 'situation' => 'reprise', 'nom' => 'NOM MODIFIÉ' ) ) ), 'modification du dossier complet' );
$m = ueb_medical_du_dossier( $q );
verifier( (int) $m->montant === 5000 && 'NOM MODIFIÉ' === $m->nom, 'synchronisation des deux quitus' );
$wpdb->update( 'ueb_insc_quitus', array( 'statut' => 'recu_envoye' ), array( 'id' => $m->id ) );
verifier( ! ueb_quitus_modifiable( $q ), 'dossier verrouillé après envoi reçu médical' );

// Préinscrit : ses trois vœux seulement ; tarif médical impossible à réintroduire.
vider_quitus();
$compte->numero_dossier = 'UEB-' . $annee['debut'] . '-999999';
$wpdb->insert( 'ueb_preinscriptions', array(
 'id' => 1, 'numero_dossier' => $compte->numero_dossier, 'statut' => 'soumis',
 'nom' => 'ÉTUDIANT TEST', 'prenom' => 'Marie Anne', 'date_naissance' => '2002-04-12', 'lieu_naissance' => 'Ebolowa', 'sexe' => 'F',
 'nationalite_id' => $wpdb->get_var( "SELECT id FROM ueb_nationalites WHERE nom = 'Camerounaise'" ),
 'faculte_id' => $wpdb->get_var( "SELECT id FROM ueb_facultes WHERE code = 'FS'" ),
 'filiere_1_id' => $fs[0]->id, 'filiere_2_id' => $fs[1]->id, 'filiere_3_id' => $fs[2]->id,
 'niveau_lmd_id' => $wpdb->get_var( "SELECT id FROM ueb_niveaux_lmd WHERE code = 'L1'" ),
) );
$c = ueb_contexte_inscription( $compte );
verifier( $c['nouveau'] && $c['situation'] === 'nouveau' && count( $c['formations'] ) === 3, 'nouveau reconnu avec trois choix' );
foreach ( $fs as $i => $f ) {
	if ( $i > 2 ) { break; }
	list( $v, $e ) = ueb_valider_quitus( array_replace( $post, array( 'filiere_id' => $f->id, 'situation' => 'nouveau' ) ), $c );
	verifier( ! $e && $v['situation'] === 'nouveau', 'vœu ' . ( $i + 1 ) . ' autorisé et situation imposée' );
}
list( $v, $e ) = ueb_valider_quitus( array_replace( $post, array( 'filiere_id' => $pro->id ) ), $c );
verifier( isset( $e['filiere_id'] ), 'filière hors vœux interdite' );
verifier( ! soumettre( array_replace( $post, $cms_vide ) ), 'enregistrement nouveau sans coordonnées CMS' );
$qs = ueb_quitus_du_compte( 1 );
verifier( count( $qs ) === 1 && (int) $qs[0]->montant === 25000, 'nouveau : droits seuls' );
verifier( ueb_dossiers_quitus( $qs )[0]['pages'] === 1, 'nouveau : une seule page sur la carte' );
$pdf = ueb_generer_pdf_quitus( $qs[0] );
verifier( $pdf->getNumPages() === 1, 'nouveau : PDF une page' );
$pdf->Output( $sortie . '/nouveau.pdf', 'F' );
verifier( ! soumettre( array_replace( $post, array( 'tranche' => 2 ) ) ), 'nouveau : deuxième tranche sans frais médicaux' );

// Formations professionnelles et appartenance à l'établissement.
vider_quitus();
$compte->numero_dossier = null;
$c = ueb_contexte_inscription( $compte );
list( $v, $e ) = ueb_valider_quitus( array_replace( $post, array( 'filiere_id' => $pro->id, 'etablissement' => $pro->etablissement, 'montant' => '85 000' ) ), $c );
verifier( ! $e && $v['montant'] === 85000, 'tarif professionnel conservé' );
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
verifier( isset( soumettre( array_replace( $post, array( 'tranche' => 2 ) ) )['tranche'] ), 'première tranche nécessaire' );

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
	$compte->numero_dossier = $cas === 'nouveau' ? 'UEB-' . $annee['debut'] . '-999999' : null;
	if ( $cas === 'deuxieme' ) { soumettre( $post ); }
	$GLOBALS['saisie_test'] = $cas === 'nouveau' ? array() : array_replace( $post, array( 'situation' => $cas === 'reprise' ? 'reprise' : 'ancien', 'tranche' => $cas === 'deuxieme' ? 2 : 1 ) );
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
	$compte->numero_dossier = 'nouveau' === $cas_espace ? 'UEB-' . $annee['debut'] . '-999999' : null;
	if ( ! in_array( $cas_espace, array( 'vide', 'bienvenue' ), true ) ) {
		verifier( ! soumettre( $post ), 'création pour aperçu : ' . $cas_espace );
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
				verifier( ! soumettre( array_replace( $post, array( 'parcours' => 'M2' ) ) ), 'nouvelle inscription sans effacer les archives' );
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
	$html = exporter_vue_espace( 'espace-' . $cas_espace, 'espace', $sortie );
	$cartes = in_array( $cas_espace, array( 'deuxieme', 'archives' ), true ) ? 2 : ( in_array( $cas_espace, array( 'vide', 'bienvenue' ), true ) ? 0 : 1 );
	verifier( substr_count( $html, 'data-dossier>' ) === $cartes, 'une carte par dossier : ' . $cas_espace );
	verifier( substr_count( $html, 'data-dossier-pdf' ) === $cartes, 'un seul bouton PDF par carte : ' . $cas_espace );
	verifier( str_contains( $html, 'id="mes-quitus"' ), 'module Mes quitus toujours présent' );
	if ( 'bienvenue' === $cas_espace ) {
		verifier( str_contains( $html, 'data-bienvenue' ), 'popup présent après création' );
		verifier( ! str_contains( exporter_vue_espace( 'espace-retour', 'espace', $sortie ), 'data-bienvenue' ), 'pas de popup au retour' );
	}
	if ( 'nouvelle-annee' === $cas_espace ) {
		verifier( str_contains( $html, 'Une nouvelle année commence' ), 'invitation à se réinscrire malgré les dossiers archivés' );
		verifier( ! str_contains( $html, 'data-dossier-modifier' ), 'archives non modifiables' );
	}
	if ( 'archives' === $cas_espace ) {
		verifier( substr_count( $html, 'class="quitus-annee"' ) === 2, 'deux années distinctes à l’écran' );
		verifier( str_contains( $html, 'Master 2' ) && str_contains( $html, 'Master 1' ), 'niveaux conservés séparément pour chaque année' );
	}
	if ( 'telechargement' === $cas_espace ) {
		verifier( str_contains( $html, 'data-telechargement-auto' ), 'téléchargement automatique après génération' );
		verifier( ! str_contains( exporter_vue_espace( 'espace-apres-telechargement', 'espace', $sortie ), 'data-telechargement-auto' ), 'rafraîchissement sans nouveau téléchargement' );
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
echo $GLOBALS['assertions'] . " vérifications réussies. Aperçus : $sortie\n";
