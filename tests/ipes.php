<?php
/**
 * Régressions IPES : tables MySQL TEMPORARY, données exclusivement fictives.
 * Usage : php tests/ipes.php (PHP de XAMPP ; sous Linux /opt/lampp/bin/php)
 * Charge WordPress en SHORTINIT : aucun hook du thème ni migration de la base réelle.
 */
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
define( 'SHORTINIT', true );
require dirname( __DIR__, 4 ) . '/wp-load.php';
define( 'UEB_INSC_DIR', dirname( __DIR__ ) );
foreach ( array( 'config', 'db-schema', 'ipes', 'ipes-filieres', 'ipes-etudiants', 'ipes-bordereaux' ) as $module ) {
	require UEB_INSC_DIR . '/inc/' . $module . '.php';
}
/* SHORTINIT ne charge pas les utilisateurs : personne n'est connecté. */
function get_current_user_id() { return 0; }
function verifier( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( 'ÉCHEC : ' . $message ); }
	$GLOBALS['assertions'] = ( $GLOBALS['assertions'] ?? 0 ) + 1;
}
/** Erreurs champ => message d'un enregistrement refusé ; tableau vide s'il a réussi. */
function erreurs_de( $resultat ) {
	return is_wp_error( $resultat ) ? (array) $resultat->get_error_data() : array();
}
function nombre_ipes() {
	global $wpdb;
	return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ueb_insc_ipes' );
}

global $wpdb;
// Ces noms masquent les vraies tables pour cette connexion seulement.
foreach ( ueb_insc_schema() as $table => $sql ) {
	if ( str_starts_with( $table, 'ueb_insc_ipes' ) ) {
		verifier( false !== $wpdb->query( str_replace( 'CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE', $sql ) ), 'création temporaire ' . $table );
	}
}

$siantou = array(
	'sigle'                => ' is ',
	'nom_fr'               => '  Institut Siantou Supérieur ',
	'nom_en'               => 'Siantou Higher Institute',
	'ville'                => 'Ebolowa',
	'telephone'            => '+237 699 11 22 33',
	'email'                => 'contact@siantou.test',
	'convention_ref'       => 'CONV-FS-2026-01',
	'convention_signee_le' => '2026-09-01',
	'convention_fin_le'    => '',
	'tutelles'             => array( 'fs' ),
);

/* ---------- Création et relecture ---------- */
$id = ueb_ipes_enregistrer( $siantou );
verifier( is_int( $id ) && $id > 0, 'création valide ' . json_encode( erreurs_de( $id ) ) );
$ipes = ueb_ipes( $id );
verifier( 'IS' === $ipes->sigle, 'sigle rogné et mis en majuscules' );
verifier( 'Institut Siantou Supérieur' === $ipes->nom_fr, 'nom rogné' );
verifier( '699112233' === $ipes->telephone, 'téléphone sans indicatif ni espaces' );
verifier( null === $ipes->convention_fin_le, 'fin de convention vide enregistrée à NULL' );
verifier( '1' === (string) $ipes->actif, 'actif par défaut' );
verifier( array( 'FS' ) === $ipes->tutelles, 'tutelle FS relue' );
verifier( null === ueb_ipes( 999999 ), 'IPES inexistant : null' );

/* ---------- Refus ---------- */
$avant = nombre_ipes();
verifier( isset( erreurs_de( ueb_ipes_enregistrer( array( 'sigle' => 'is' ) + $siantou ) )['sigle'] ), 'sigle en double refusé' );
verifier( isset( erreurs_de( ueb_ipes_enregistrer( array( 'sigle' => 'FS' ) + $siantou ) )['sigle'] ), 'sigle d’un établissement UEb refusé' );
verifier( isset( erreurs_de( ueb_ipes_enregistrer( array( 'sigle' => 'UEB' ) + $siantou ) )['sigle'] ), 'sigle UEB refusé' );
verifier( isset( erreurs_de( ueb_ipes_enregistrer( array( 'sigle' => 'A' ) + $siantou ) )['sigle'] ), 'sigle trop court refusé' );
verifier( isset( erreurs_de( ueb_ipes_enregistrer( array( 'sigle' => 'IS/2' ) + $siantou ) )['sigle'] ), 'caractère interdit dans le sigle' );
verifier( isset( erreurs_de( ueb_ipes_enregistrer( array( 'sigle' => 'NEW', 'tutelles' => array() ) + $siantou ) )['tutelles'] ), 'IPES sans tutelle refusé' );
verifier( isset( erreurs_de( ueb_ipes_enregistrer( array( 'sigle' => 'NEW', 'tutelles' => array( 'XYZ' ) ) + $siantou ) )['tutelles'] ), 'tutelle inconnue refusée' );
verifier( isset( erreurs_de( ueb_ipes_enregistrer( array( 'sigle' => 'NEW', 'nom_fr' => '   ' ) + $siantou ) )['nom_fr'] ), 'nom vide refusé' );
verifier( isset( erreurs_de( ueb_ipes_enregistrer( array( 'sigle' => 'NEW', 'nom_fr' => str_repeat( 'é', 151 ) ) + $siantou ) )['nom_fr'] ), 'nom de 151 caractères refusé' );
verifier( array() === ueb_ipes_valider( ueb_ipes_normaliser( array( 'sigle' => 'NEW', 'nom_fr' => str_repeat( 'é', 150 ) ) + $siantou ) ), 'nom de 150 caractères accepté (accents comptés comme un caractère)' );
verifier( isset( erreurs_de( ueb_ipes_enregistrer( array( 'sigle' => 'NEW', 'email' => 'pas-un-mail' ) + $siantou ) )['email'] ), 'e-mail invalide refusé' );
verifier( isset( erreurs_de( ueb_ipes_enregistrer( array( 'sigle' => 'NEW', 'telephone' => '222 33 44 55' ) + $siantou ) )['telephone'] ), 'téléphone fixe refusé' );
verifier( isset( erreurs_de( ueb_ipes_enregistrer( array( 'sigle' => 'NEW', 'convention_signee_le' => '2026-02-30' ) + $siantou ) )['convention_signee_le'] ), 'date inexistante refusée' );
verifier( isset( erreurs_de( ueb_ipes_enregistrer( array( 'sigle' => 'NEW', 'convention_fin_le' => '2026-09-01' ) + $siantou ) )['convention_fin_le'] ), 'fin le jour de la signature refusée' );
verifier( isset( erreurs_de( ueb_ipes_enregistrer( array( 'sigle' => 'NEW', 'convention_fin_le' => '2025-01-01' ) + $siantou ) )['convention_fin_le'] ), 'fin avant la signature refusée' );
verifier( isset( erreurs_de( ueb_ipes_enregistrer( array( 'sigle' => 'NEW', 'montant_annuel_du' => '0' ) + $siantou ) )['montant_annuel_du'] ), 'montant annuel dû nul refusé' );
verifier( isset( erreurs_de( ueb_ipes_enregistrer( array( 'sigle' => 'NEW', 'montant_annuel_du' => 'beaucoup' ) + $siantou ) )['montant_annuel_du'] ), 'montant annuel dû sans chiffre refusé' );
verifier( $avant === nombre_ipes(), 'aucun enregistrement refusé n’a laissé de ligne' );
verifier( null === ueb_ipes( $id )->montant_annuel_du, 'montant annuel dû vide : non renseigné (NULL)' );
verifier( $id === ueb_ipes_enregistrer( array( 'montant_annuel_du' => ' 50 000 FCFA' ) + $siantou, $id ) && 50000 === (int) ueb_ipes( $id )->montant_annuel_du, 'montant annuel dû « 50 000 FCFA » enregistré' );
verifier( $id === ueb_ipes_enregistrer( array( 'montant_annuel_du' => '' ) + $siantou, $id ) && null === ueb_ipes( $id )->montant_annuel_du, 'montant annuel dû vidé : de nouveau NULL' );
verifier( is_wp_error( ueb_ipes_enregistrer( $siantou, 999999 ) ), 'modification d’un IPES inexistant refusée' );

/* ---------- Modification et tutelles ---------- */
$wpdb->query( $wpdb->prepare( "UPDATE ueb_insc_ipes_tutelles SET depuis_le = '2020-01-01 00:00:00' WHERE ipes_id = %d", $id ) );
$modifie = ueb_ipes_enregistrer( array( 'tutelles' => array( 'FS', 'FSEG' ), 'convention_fin_le' => '2029-08-31' ) + $siantou, $id );
verifier( $id === $modifie, 'modification sans faux doublon sur son propre sigle ' . json_encode( erreurs_de( $modifie ) ) );
verifier( array( 'FS', 'FSEG' ) === ueb_ipes_tutelles( $id ), 'tutelles FS + FSEG' );
$depuis = $wpdb->get_var( $wpdb->prepare( "SELECT depuis_le FROM ueb_insc_ipes_tutelles WHERE ipes_id = %d AND etablissement = 'FS'", $id ) );
verifier( '2020-01-01 00:00:00' === $depuis, 'la date de la tutelle FS n’a pas bougé' );
verifier( '2029-08-31' === ueb_ipes( $id )->convention_fin_le, 'fin de convention modifiée' );
ueb_ipes_enregistrer( array( 'tutelles' => array( 'FSEG' ) ) + $siantou, $id );
verifier( array( 'FSEG' ) === ueb_ipes_tutelles( $id ), 'tutelle FS retirée' );

/* ---------- Liste et filtres ---------- */
$autre = ueb_ipes_enregistrer( array( 'sigle' => 'IUTE', 'nom_fr' => 'Institut Universitaire Test', 'nom_en' => '', 'telephone' => '', 'email' => '', 'convention_signee_le' => '', 'tutelles' => array( 'FALSH' ) ) + $siantou );
verifier( is_int( $autre ), 'deuxième IPES, champs facultatifs vides ' . json_encode( erreurs_de( $autre ) ) );
$liste = ueb_ipes_liste();
verifier( array( 'IS', 'IUTE' ) === array_map( static fn( $i ) => $i->sigle, $liste ), 'liste triée par sigle' );
verifier( array( 'FSEG' ) === $liste[0]->tutelles && array( 'FALSH' ) === $liste[1]->tutelles, 'tutelles jointes à chaque IPES' );
verifier( array( 'IUTE' ) === array_map( static fn( $i ) => $i->sigle, ueb_ipes_liste( array( 'etablissement' => 'falsh' ) ) ), 'filtre par tutelle' );
verifier( array() === ueb_ipes_liste( array( 'etablissement' => 'FS' ) ), 'aucun IPES sous la FS' );
verifier( array( 'IS' ) === array_map( static fn( $i ) => $i->sigle, ueb_ipes_liste( array( 'recherche' => 'siantou' ) ) ), 'recherche dans le nom' );
verifier( array( 'IUTE' ) === array_map( static fn( $i ) => $i->sigle, ueb_ipes_liste( array( 'recherche' => 'iute' ) ) ), 'recherche dans le sigle' );
verifier( array() === ueb_ipes_liste( array( 'recherche' => '%' ) ), 'le % de la recherche est un caractère, pas un joker' );

/* ---------- Activation ---------- */
verifier( ueb_ipes_changer_etat( $autre, false ), 'désactivation' );
verifier( '0' === (string) ueb_ipes( $autre )->actif, 'IPES désactivé' );
verifier( array( 'IS' ) === array_map( static fn( $i ) => $i->sigle, ueb_ipes_liste( array( 'actif' => 1 ) ) ), 'filtre actifs' );
verifier( array( 'IUTE' ) === array_map( static fn( $i ) => $i->sigle, ueb_ipes_liste( array( 'actif' => 0 ) ) ), 'filtre désactivés' );
verifier( ueb_ipes_changer_etat( $autre, true ) && '1' === (string) ueb_ipes( $autre )->actif, 'réactivation' );
verifier( ! ueb_ipes_changer_etat( 999999, false ), 'IPES inexistant : changement d’état refusé' );

/* ---------- Filières ---------- */
$libelles = static fn( $filieres ) => array_map( static fn( $f ) => $f->libelle, $filieres );
$genie    = ueb_ipes_filiere_ajouter( $id, '  Génie   logiciel ' );
$compta   = ueb_ipes_filiere_ajouter( $id, 'Comptabilité' );
verifier( is_int( $genie ) && is_int( $compta ), 'deux filières ajoutées' );
verifier( 'Génie logiciel' === ueb_ipes_filiere( $genie )->libelle, 'libellé rogné, espaces multiples réduits' );
verifier( array( 'Comptabilité', 'Génie logiciel' ) === $libelles( ueb_ipes_filieres( $id ) ), 'filières par ordre alphabétique' );
verifier( is_wp_error( ueb_ipes_filiere_ajouter( $id, 'génie LOGICIEL' ) ), 'doublon qui ne diffère que par les majuscules refusé' );
verifier( is_wp_error( ueb_ipes_filiere_ajouter( $id, 'Genie logiciel' ) ), 'doublon qui ne diffère que par les accents refusé' );
verifier( is_int( ueb_ipes_filiere_ajouter( $autre, 'Génie logiciel' ) ), 'même libellé accepté dans un autre IPES' );
verifier( is_wp_error( ueb_ipes_filiere_ajouter( $id, 'IA' ) ), 'libellé de 2 caractères refusé' );
verifier( is_wp_error( ueb_ipes_filiere_ajouter( $id, str_repeat( 'é', 151 ) ) ), 'libellé de 151 caractères refusé' );
verifier( is_wp_error( ueb_ipes_filiere_ajouter( 999999, 'Informatique' ) ), 'filière d’un IPES inexistant refusée' );
verifier( 2 === count( ueb_ipes_filieres( $id ) ), 'aucun refus n’a laissé de ligne' );

verifier( true === ueb_ipes_filiere_renommer( $genie, 'Génie Logiciel' ), 'renommage qui ne change que la casse accepté' );
verifier( is_wp_error( ueb_ipes_filiere_renommer( $genie, 'comptabilite' ) ), 'renommage en doublon refusé' );
verifier( 'Génie Logiciel' === ueb_ipes_filiere( $genie )->libelle, 'libellé inchangé après un renommage refusé' );
verifier( is_wp_error( ueb_ipes_filiere_renommer( 999999, 'Informatique' ) ), 'renommage d’une filière inexistante refusé' );

verifier( ueb_ipes_filiere_changer_etat( $genie, false ), 'filière retirée' );
verifier( array( 'Comptabilité' ) === $libelles( ueb_ipes_filieres( $id, true ) ), 'filière retirée absente des actives' );
verifier( array( 'Comptabilité', 'Génie Logiciel' ) === $libelles( ueb_ipes_filieres( $id ) ), 'filière retirée conservée dans l’historique' );
verifier( is_wp_error( ueb_ipes_filiere_ajouter( $id, 'génie logiciel' ) ), 'une filière retirée bloque toujours son libellé' );
verifier( ueb_ipes_filiere_changer_etat( $genie, true ) && '1' === (string) ueb_ipes_filiere( $genie )->actif, 'filière rétablie' );
verifier( ! ueb_ipes_filiere_changer_etat( 999999, false ), 'filière inexistante : changement d’état refusé' );

/* ---------- Étudiants ---------- */
$filiere_autre = ueb_ipes_filiere_ajouter( $autre, 'Droit' );
$paul_saisie   = array( 'matricule' => ' 24is 001 ', 'nom' => '  Mbarga ', 'prenom' => 'Paul   Arnaud', 'filiere_id' => $genie, 'niveau' => 'l2', 'telephone' => '+237 699 00 11 22' );
$paul          = ueb_ipes_etudiant_enregistrer( $id, $paul_saisie );
verifier( is_int( $paul ), 'étudiant ajouté ' . json_encode( erreurs_de( $paul ) ) );
$e = ueb_ipes_etudiant( $id, $paul );
verifier( '24IS001' === $e->matricule && 'Paul Arnaud' === $e->prenom && 'L2' === $e->niveau && '699001122' === $e->telephone, 'saisie normalisée (matricule, prénom, niveau, téléphone)' );
verifier( ueb_annee_academique()['code'] === $e->annee_academique, 'année académique en cours enregistrée' );
verifier( null === ueb_ipes_etudiant( $autre, $paul ), 'un autre IPES ne voit pas cet étudiant' );
verifier( isset( erreurs_de( ueb_ipes_etudiant_enregistrer( $id, array( 'matricule' => '24is001' ) + $paul_saisie ) )['matricule'] ), 'matricule déjà saisi cette année refusé' );
verifier( is_int( ueb_ipes_etudiant_enregistrer( $autre, array( 'filiere_id' => $filiere_autre ) + $paul_saisie ) ), 'même matricule accepté dans un autre IPES' );
verifier( isset( erreurs_de( ueb_ipes_etudiant_enregistrer( $id, array( 'matricule' => 'X1' ) + $paul_saisie ) )['matricule'] ), 'matricule trop court refusé' );
verifier( isset( erreurs_de( ueb_ipes_etudiant_enregistrer( $id, array( 'matricule' => 'NEW01', 'filiere_id' => $filiere_autre ) + $paul_saisie ) )['filiere_id'] ), 'filière d’un autre IPES refusée' );
verifier( isset( erreurs_de( ueb_ipes_etudiant_enregistrer( $id, array( 'matricule' => 'NEW01', 'niveau' => 'D1' ) + $paul_saisie ) )['niveau'] ), 'niveau inconnu refusé' );
verifier( isset( erreurs_de( ueb_ipes_etudiant_enregistrer( $id, array( 'matricule' => 'NEW01', 'nom' => ' ' ) + $paul_saisie ) )['nom'] ), 'nom vide refusé' );
verifier( isset( erreurs_de( ueb_ipes_etudiant_enregistrer( $id, array( 'matricule' => 'NEW01', 'telephone' => '222 11 22 33' ) + $paul_saisie ) )['telephone'] ), 'téléphone fixe refusé' );
ueb_ipes_filiere_changer_etat( $compta, false );
verifier( isset( erreurs_de( ueb_ipes_etudiant_enregistrer( $id, array( 'matricule' => 'NEW01', 'filiere_id' => $compta ) + $paul_saisie ) )['filiere_id'] ), 'filière retirée refusée pour un nouvel étudiant' );
$jean = ueb_ipes_etudiant_enregistrer( $id, array( 'matricule' => '24IS002', 'nom' => 'Ondoa', 'prenom' => 'Jean', 'filiere_id' => $genie ) + $paul_saisie );
$wpdb->update( 'ueb_insc_ipes_etudiants', array( 'filiere_id' => $compta ), array( 'id' => $jean ) ); // inscrit avant le retrait de sa filière
verifier( $jean === ueb_ipes_etudiant_enregistrer( $id, array( 'matricule' => '24IS002', 'nom' => 'Ondoa', 'prenom' => 'Jean', 'filiere_id' => $compta ) + $paul_saisie, $jean ), 'un étudiant garde sa filière retirée depuis' );
ueb_ipes_filiere_changer_etat( $compta, true );
verifier( is_wp_error( ueb_ipes_etudiant_enregistrer( $autre, $paul_saisie, $paul ) ), 'modification par un autre IPES refusée' );
verifier( array( 'Mbarga', 'Ondoa' ) === array_map( static fn( $x ) => $x->nom, ueb_ipes_etudiants( $id ) ), 'étudiants triés par nom' );
verifier( array( 'Ondoa' ) === array_map( static fn( $x ) => $x->nom, ueb_ipes_etudiants( $id, array( 'recherche' => 'jean' ) ) ), 'recherche dans le prénom' );
verifier( array( 'Mbarga' ) === array_map( static fn( $x ) => $x->nom, ueb_ipes_etudiants( $id, array( 'recherche' => '24is001' ) ) ), 'recherche dans le matricule' );
verifier( array() === ueb_ipes_etudiants( $id, array( 'annee' => '2000-2001' ) ), 'aucun étudiant sur une autre année' );

/* ---------- Versements ---------- */
$v1 = ueb_ipes_paiement_enregistrer( $id, $paul, array( 'montant' => '25 000 FCFA', 'date_paiement' => '2026-09-10' ) );
$v2 = ueb_ipes_paiement_enregistrer( $id, $paul, array( 'montant' => '10000', 'date_paiement' => current_time( 'Y-m-d' ) ) );
verifier( is_int( $v1 ) && is_int( $v2 ), 'deux versements enregistrés (« 25 000 FCFA » et date du jour)' );
verifier( 25000 === (int) ueb_ipes_paiement( $id, $v1 )->montant, 'montant saisi avec espaces et FCFA' );
verifier( isset( erreurs_de( ueb_ipes_paiement_enregistrer( $id, $paul, array( 'montant' => '0', 'date_paiement' => '2026-09-10' ) ) )['montant'] ), 'montant nul refusé' );
verifier( isset( erreurs_de( ueb_ipes_paiement_enregistrer( $id, $paul, array( 'montant' => '250000000', 'date_paiement' => '2026-09-10' ) ) )['montant'] ), 'montant démesuré refusé' );
verifier( isset( erreurs_de( ueb_ipes_paiement_enregistrer( $id, $paul, array( 'montant' => '5000', 'date_paiement' => '2099-01-01' ) ) )['date_paiement'] ), 'date future refusée' );
verifier( is_wp_error( ueb_ipes_paiement_enregistrer( $autre, $paul, array( 'montant' => '5000', 'date_paiement' => '2026-09-10' ) ) ), 'versement pour l’étudiant d’un autre IPES refusé' );
verifier( null === ueb_ipes_paiement( $autre, $v1 ), 'un autre IPES ne voit pas ce versement' );
$etudiants = ueb_ipes_etudiants( $id );
verifier( 35000 === (int) $etudiants[0]->total_paye && 2 === (int) $etudiants[0]->nb_versements, 'total et nombre de versements de l’étudiant' );
verifier( array( 'etudiants' => 2, 'encaisse' => 35000, 'libre' => 35000 ) === ueb_ipes_totaux( $id ), 'totaux de l’IPES ' . json_encode( ueb_ipes_totaux( $id ) ) );
verifier( $v1 === ueb_ipes_paiement_enregistrer( $id, $paul, array( 'montant' => '30000', 'date_paiement' => '2026-09-11' ), $v1 ) && 30000 === (int) ueb_ipes_paiement( $id, $v1 )->montant, 'versement modifié' );

$wpdb->update( 'ueb_insc_ipes_paiements', array( 'bordereau_id' => 999 ), array( 'id' => $v1 ) ); // placé dans un bordereau
verifier( is_wp_error( ueb_ipes_paiement_enregistrer( $id, $paul, array( 'montant' => '1', 'date_paiement' => '2026-09-11' ), $v1 ) ), 'versement dans un bordereau : modification refusée' );
verifier( is_wp_error( ueb_ipes_paiement_supprimer( $id, $v1 ) ) && ueb_ipes_paiement( $id, $v1 ), 'versement dans un bordereau : suppression refusée' );
verifier( 10000 === ueb_ipes_totaux( $id )['libre'], 'la part libre exclut les versements en bordereau' );
verifier( is_wp_error( ueb_ipes_etudiant_supprimer( $id, $paul ) ), 'étudiant avec versements : suppression refusée' );
verifier( is_wp_error( ueb_ipes_paiement_supprimer( $autre, $v2 ) ) && ueb_ipes_paiement( $id, $v2 ), 'suppression par un autre IPES refusée' );
verifier( true === ueb_ipes_paiement_supprimer( $id, $v2 ) && null === ueb_ipes_paiement( $id, $v2 ), 'versement libre supprimé' );
verifier( true === ueb_ipes_etudiant_supprimer( $id, $jean ) && null === ueb_ipes_etudiant( $id, $jean ), 'étudiant sans versement supprimé' );

/* ---------- Bordereaux ---------- */
// À ce stade : IPES « IS » sous la seule tutelle FSEG, Paul et son versement de 30 000 ($v1).
$wpdb->update( 'ueb_insc_ipes_paiements', array( 'bordereau_id' => null ), array( 'id' => $v1 ) );
$wpdb->update( 'ueb_insc_ipes', array( 'montant_annuel_du' => 50000 ), array( 'id' => $id ) );
$v3     = ueb_ipes_paiement_enregistrer( $id, $paul, array( 'montant' => '15000', 'date_paiement' => '2026-09-12' ) );
$marie  = ueb_ipes_etudiant_enregistrer( $id, array( 'matricule' => '24IS003', 'nom' => 'Ateba', 'prenom' => 'Marie' ) + $paul_saisie );
$v4     = ueb_ipes_paiement_enregistrer( $id, $marie, array( 'montant' => '10000', 'date_paiement' => '2026-09-12' ) );
$ailleurs = ueb_ipes_etudiants( $autre )[0]->id;
$vx     = ueb_ipes_paiement_enregistrer( $autre, $ailleurs, array( 'montant' => '5000', 'date_paiement' => '2026-09-12' ) );
$annee  = ueb_annee_academique();
$courte = substr( $annee['code'], 2, 2 ) . substr( $annee['code'], 7, 2 );
$statut = static fn( $b ) => ueb_ipes_bordereau( $id, $b )->statut;

verifier( is_wp_error( ueb_ipes_bordereau_creer( $id, 'FS' ) ), 'tutelle qui n’est pas celle de l’IPES refusée' );
$b1 = ueb_ipes_bordereau_creer( $id );
verifier( is_int( $b1 ) && 'FSEG' === ueb_ipes_bordereau( $id, $b1 )->etablissement, 'seule tutelle choisie d’office' );
verifier( 'BROUILLON-' . $b1 === ueb_ipes_bordereau( $id, $b1 )->numero && 'brouillon' === $statut( $b1 ), 'brouillon au numéro provisoire' );
verifier( null === ueb_ipes_bordereau( $autre, $b1 ), 'un autre IPES ne voit pas ce bordereau' );
verifier( is_wp_error( ueb_ipes_bordereau_envoyer( $id, $b1 ) ), 'bordereau vide : envoi refusé' );
verifier( true === ueb_ipes_bordereau_definir_paiements( $id, $b1, array( $v1, $v3 ) ), 'deux versements cochés' );
verifier( array( $v4 ) === array_map( static fn( $p ) => (int) $p->id, ueb_ipes_paiements_libres( $id ) ), 'versements libres : seulement celui de Marie' );

$b2 = ueb_ipes_bordereau_creer( $id );
verifier( is_wp_error( ueb_ipes_bordereau_definir_paiements( $id, $b2, array( $v1 ) ) ), 'versement déjà dans un bordereau refusé' );
verifier( is_wp_error( ueb_ipes_bordereau_definir_paiements( $id, $b2, array( $v4, $vx ) ) ), 'versement d’un autre IPES refusé' );
verifier( array() === ueb_ipes_bordereau_paiements( $id, $b2 ) && (int) ueb_ipes_paiement( $id, $v4 )->bordereau_id === 0, 'refus : rien n’a bougé (tout ou rien)' );
verifier( 2 === count( ueb_ipes_bordereau_paiements( $id, $b1 ) ), 'le premier bordereau est intact' );
$ancien = ueb_ipes_etudiant_enregistrer( $id, array( 'matricule' => '99OLD01' ) + $paul_saisie );
$wpdb->update( 'ueb_insc_ipes_etudiants', array( 'annee_academique' => '2000-2001' ), array( 'id' => $ancien ) );
$v_ancien = ueb_ipes_paiement_enregistrer( $id, $ancien, array( 'montant' => '7000', 'date_paiement' => '2026-09-12' ) );
verifier( is_wp_error( ueb_ipes_bordereau_definir_paiements( $id, $b2, array( $v_ancien ) ) ), 'versement d’une autre année refusé' );
verifier( is_wp_error( ueb_ipes_bordereau_envoyer( $autre, $b1 ) ), 'envoi par un autre IPES refusé' );

verifier( true === ueb_ipes_bordereau_envoyer( $id, $b1 ), 'bordereau envoyé' );
$envoye = ueb_ipes_bordereau( $id, $b1 );
verifier( 'BRD-IS-' . $courte . '-0001' === $envoye->numero, 'numéro officiel au premier envoi : ' . $envoye->numero );
verifier( 45000 === (int) $envoye->total && 'envoye' === $envoye->statut && $envoye->date_envoi, 'total figé à 45 000' );
verifier( is_wp_error( ueb_ipes_bordereau_envoyer( $id, $b1 ) ), 'second envoi refusé' );
verifier( is_wp_error( ueb_ipes_bordereau_definir_paiements( $id, $b1, array( $v1 ) ) ), 'bordereau envoyé : versements figés' );
verifier( is_wp_error( ueb_ipes_bordereau_supprimer( $id, $b1 ) ), 'bordereau envoyé : suppression refusée' );
verifier( array( 'du' => 50000, 'envoye' => 45000, 'verifie' => 0, 'reste' => 50000 ) === ueb_ipes_jauge( $id ), 'jauge après envoi ' . json_encode( ueb_ipes_jauge( $id ) ) );

verifier( is_wp_error( ueb_ipes_bordereau_decider( $b1, false, 'non' ) ), 'rejet sans vrai motif refusé' );
verifier( true === ueb_ipes_bordereau_decider( $b1, false, '  Le reçu de   Paul manque. ' ) && 'rejete' === $statut( $b1 ), 'bordereau rejeté' );
verifier( 'Le reçu de Paul manque.' === ueb_ipes_bordereau( $id, $b1 )->motif_rejet, 'motif du rejet conservé' );
verifier( is_wp_error( ueb_ipes_bordereau_decider( $b1, true ) ), 'décision sur un bordereau non envoyé refusée' );
verifier( true === ueb_ipes_bordereau_definir_paiements( $id, $b1, array( $v1 ) ), 'bordereau rejeté : de nouveau modifiable' );
verifier( null === ueb_ipes_paiement( $id, $v3 )->bordereau_id, 'versement retiré : de nouveau libre' );
verifier( true === ueb_ipes_bordereau_envoyer( $id, $b1 ), 'bordereau renvoyé' );
$renvoye = ueb_ipes_bordereau( $id, $b1 );
verifier( 'BRD-IS-' . $courte . '-0001' === $renvoye->numero && 30000 === (int) $renvoye->total && null === $renvoye->motif_rejet, 'renvoi : même numéro, nouveau total, motif effacé' );
verifier( true === ueb_ipes_bordereau_decider( $b1, true ) && 'verifie' === $statut( $b1 ), 'bordereau vérifié' );
verifier( is_wp_error( ueb_ipes_bordereau_decider( $b1, false, 'Trop tard pour changer' ) ), 'seconde décision refusée' );
verifier( array( 'du' => 50000, 'envoye' => 30000, 'verifie' => 30000, 'reste' => 20000 ) === ueb_ipes_jauge( $id ), 'jauge après vérification ' . json_encode( ueb_ipes_jauge( $id ) ) );

verifier( true === ueb_ipes_bordereau_definir_paiements( $id, $b2, array( $v3 ) ) && true === ueb_ipes_bordereau_envoyer( $id, $b2 ), 'deuxième bordereau envoyé' );
verifier( 'BRD-IS-' . $courte . '-0002' === ueb_ipes_bordereau( $id, $b2 )->numero, 'numérotation continue : 0002' );
$b3 = ueb_ipes_bordereau_creer( $id );
ueb_ipes_bordereau_definir_paiements( $id, $b3, array( $v4 ) );
verifier( is_wp_error( ueb_ipes_bordereau_supprimer( $autre, $b3 ) ), 'suppression par un autre IPES refusée' );
verifier( true === ueb_ipes_bordereau_supprimer( $id, $b3 ) && null === ueb_ipes_bordereau( $id, $b3 ), 'brouillon supprimé' );
verifier( null === ueb_ipes_paiement( $id, $v4 )->bordereau_id, 'ses versements redeviennent libres' );
verifier( array( $b2, $b1 ) === array_map( static fn( $b ) => (int) $b->id, ueb_ipes_bordereaux( $id ) ), 'bordereaux du plus récent au plus ancien' );
verifier( array( $b1 ) === array_map( static fn( $b ) => (int) $b->id, ueb_ipes_bordereaux( $id, array( 'statut' => 'verifie' ) ) ), 'filtre par statut' );
$wpdb->update( 'ueb_insc_ipes', array( 'montant_annuel_du' => null ), array( 'id' => $id ) );
verifier( null === ueb_ipes_jauge( $id )['du'] && null === ueb_ipes_jauge( $id )['reste'], 'jauge sans montant dû : pas de reste calculé' );
ueb_ipes_changer_etat( $id, false );
verifier( is_wp_error( ueb_ipes_bordereau_creer( $id ) ), 'IPES désactivé : pas de nouveau bordereau' );
ueb_ipes_changer_etat( $id, true );

echo $GLOBALS['assertions'] . " vérifications réussies.\n";
