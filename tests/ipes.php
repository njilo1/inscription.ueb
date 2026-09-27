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
foreach ( array( 'config', 'db-schema', 'ipes', 'ipes-filieres' ) as $module ) {
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
verifier( $avant === nombre_ipes(), 'aucun enregistrement refusé n’a laissé de ligne' );
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

echo $GLOBALS['assertions'] . " vérifications réussies.\n";
