<?php
/** Fiche scolarité : rendu des états avec des données fictives, sans base.
 * Usage : /opt/lampp/bin/php tests/quitus-fiche.php [répertoire des aperçus]. */
if ( PHP_SAPI !== 'cli' ) { exit; }
error_reporting( E_ALL );
set_error_handler( static function ( $niveau, $message, $fichier, $ligne ) { throw new ErrorException( $message, 0, $niveau, $fichier, $ligne ); } );
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'UEB_INSC_URI', 'file://' . dirname( __DIR__ ) );
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $v ) { return esc_html( $v ); }
function esc_textarea( $v ) { return esc_html( $v ); }
function esc_url( $v ) { return esc_html( $v ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function add_filter( ...$args ) {}
function disabled( $v ) { if ( $v ) { echo 'disabled'; } }
function current_time( $format ) { return strtotime( '2026-10-02 12:00:00' ); }
function human_time_diff( ...$args ) { return '2 heures'; }
function mysql2date( $format, $date ) { return strtr( date( $format, strtotime( $date ) ), array( 'October' => 'octobre', 'Oct' => 'oct.', 'September' => 'septembre', 'Sep' => 'sept.' ) ); }
function get_userdata( $id ) { return (object) array( 'display_name' => 'Agent de scolarité' ); }
function ueb_compte_par_id( $id ) { return (object) array( 'telephone' => '699000000' ); }
function ueb_recus_du_quitus( $id ) { return $GLOBALS['pieces_test']; }
function ueb_peut( ...$args ) { return $GLOBALS['decision_test']; }
function ueb_champ_csrf() { echo '<input type="hidden" name="ueb_csrf" value="fixture">'; }
function ueb_libelle_objet_recu( $r, $type ) { return 'medicaux' === $type ? 'Frais médicaux' : ( 'tranche2' === $r->objet ? 'Deuxième tranche' : 'Première tranche' ); }
function ueb_url_recu( $id, $telecharger = false ) { return 'file://' . $GLOBALS['sortie'] . ( 2 === $id ? '/recu.pdf' : '/recu.svg' ); }
foreach ( array( 'config', 'nombres', 'quitus', 'vues' ) as $module ) { require dirname( __DIR__ ) . '/inc/' . $module . '.php'; }

$sortie = $argv[1] ?? '/tmp/ueb-quitus-fiche-review';
if ( ! is_dir( $sortie ) ) { mkdir( $sortie, 0700, true ); }
$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="570" height="680" viewBox="0 0 570 680"><rect width="570" height="680" fill="white"/><rect x="36" y="35" width="498" height="82" fill="#f0f4f2"/><text x="58" y="72" fill="#1a4633" font-family="Arial" font-size="23" font-weight="bold">CCA BANK</text><text x="58" y="98" fill="#53615a" font-family="Arial" font-size="13">REÇU FICTIF · APERÇU DE TEST</text><path d="M38 148H530M38 505H530" stroke="#cfdad3"/><g fill="#526159" font-family="Arial" font-size="14"><text x="48" y="183">Nom du déposant</text><text x="48" y="260">Montant du versement</text><text x="48" y="344">Référence bancaire</text><text x="48" y="418">Date de l’opération</text></g><g fill="#213c2e" font-family="Arial" font-size="20" font-weight="bold"><text x="48" y="218">MENGUE Marie Claire</text><text x="48" y="304" font-size="32">25 000 FCFA</text><text x="48" y="378">TEST-20261002-001</text><text x="48" y="454">02 octobre 2026</text></g><g transform="translate(375 565) rotate(-13)"><circle r="52" fill="none" stroke="#397657" stroke-width="3"/><circle r="45" fill="none" stroke="#397657"/><text text-anchor="middle" y="-7" fill="#397657" font-size="13" font-family="Arial" font-weight="bold">VERSEMENT</text><text text-anchor="middle" y="14" fill="#397657" font-size="14" font-family="Arial" font-weight="bold">REÇU</text></g><text x="48" y="618" fill="#526159" font-family="Arial" font-size="12">Document fictif pour vérification visuelle.</text></svg>';
file_put_contents( $sortie . '/recu.svg', $svg );
// Petit PDF réel pour vérifier l'iframe et son lien d'ouverture.
$pdf = "%PDF-1.4\n";
$objets = array( '<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>', '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 570 680] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>', '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>' );
$texte_pdf = 'BT /F1 20 Tf 50 590 Td (RECU FICTIF - 25 000 FCFA) Tj ET';
$objets[] = '<< /Length ' . strlen( $texte_pdf ) . ">>\nstream\n" . $texte_pdf . "\nendstream";
$offsets = array( 0 );
foreach ( $objets as $i => $objet ) { $offsets[] = strlen( $pdf ); $pdf .= ( $i + 1 ) . " 0 obj\n" . $objet . "\nendobj\n"; }
$xref = strlen( $pdf );
$pdf .= "xref\n0 6\n0000000000 65535 f \n";
foreach ( array_slice( $offsets, 1 ) as $offset ) { $pdf .= sprintf( "%010d 00000 n \n", $offset ); }
$pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
file_put_contents( $sortie . '/recu.pdf', $pdf );

$base = (object) array( 'id' => 41, 'etablissement' => 'FS', 'compte_id' => 1, 'verifie_par' => null, 'type' => 'droits', 'statut' => 'recu_envoye', 'date_modification' => '2026-10-02 10:00:00', 'date_creation' => '2026-10-01 09:00:00', 'date_verification' => null, 'corrige_le' => null, 'nom' => 'MENGUE', 'prenom' => 'Marie Claire', 'numero' => 'FS-2627-000041', 'identifiant' => '26TEST41FS', 'montant' => 25000, 'tranche' => 1, 'moyen_paiement' => 'CCA Bank', 'date_naissance' => '2003-04-12', 'lieu_naissance' => 'Ebolowa', 'sexe' => 'F', 'nationalite' => 'Camerounaise', 'departement' => 'Informatique', 'parcours' => 'L1', 'motif_rejet' => '', 'annee_academique' => '2026-2027' );
$piece = (object) array( 'id' => 1, 'type_mime' => 'image/jpeg', 'date_envoi' => '2026-10-02 10:00:00', 'nom_original' => 'recu-versement-cca-bank.jpg', 'objet' => 'tranche1' );
$ici = static fn( $args ) => esc_url( '#fiche-' . http_build_query( $args ) );
$assertions = 0;
foreach ( array( 'recu_envoye', 'genere', 'verifie', 'rejete', 'lecture', 'medicaux', 'multiple', 'long', 'pdf' ) as $cas ) {
	$fiche = clone $base;
	$pieces_test = array( clone $piece );
	$decision_test = 'lecture' !== $cas;
	if ( in_array( $cas, array( 'genere', 'verifie', 'rejete' ), true ) ) { $fiche->statut = $cas; }
	if ( 'genere' === $cas ) { $pieces_test = array(); }
	if ( 'verifie' === $cas || 'rejete' === $cas ) { $fiche->verifie_par = 9; $fiche->date_verification = '2026-10-02 11:00:00'; }
	if ( 'rejete' === $cas ) { $fiche->motif_rejet = 'Le reçu est illisible. Envoie une photo plus nette.'; }
	if ( 'medicaux' === $cas ) { $fiche->type = 'medicaux'; $fiche->tranche = 0; $fiche->montant = 5000; }
	if ( 'multiple' === $cas ) { $ancienne = clone $piece; $ancienne->id = 3; $ancienne->date_envoi = '2026-10-01 11:00:00'; $ancienne->objet = 'tranche2'; array_unshift( $pieces_test, $ancienne ); }
	if ( 'long' === $cas ) { $fiche->nom = 'MENGUE-NKOMO ÉTOUNDI'; $fiche->prenom = 'Marie Claire Alexandra'; $fiche->departement = 'Sciences de la terre, de l’environnement et de l’aménagement du territoire'; $pieces_test[0]->nom_original = str_repeat( 'nom-de-fichier-long-', 15 ) . '.jpg'; }
	if ( 'pdf' === $cas ) { $pieces_test[0]->id = 2; $pieces_test[0]->type_mime = 'application/pdf'; $pieces_test[0]->nom_original = 'recu.pdf'; }
	ob_start(); include dirname( __DIR__ ) . '/templates/composants/scolarite-quitus.php'; $contenu = ob_get_clean();
	$doc = new DOMDocument(); @$doc->loadHTML( '<?xml encoding="UTF-8">' . $contenu, LIBXML_NOERROR | LIBXML_NOWARNING ); $xpath = new DOMXPath( $doc );
	$tester = static function ( $ok, $message ) use ( &$assertions, $cas ) { if ( ! $ok ) { throw new RuntimeException( $cas . ' : ' . $message ); } ++$assertions; };
	$tester( 1 === $xpath->query( '//h1' )->length, 'un seul titre principal' );
	$tester( 4 === $xpath->query( '//ol[contains(@class,"qf-etapes")]/li' )->length, 'quatre étapes' );
	$tester( 1 === $xpath->query( '//*[@aria-current="step"]' )->length, 'une étape courante' );
	$tester( count( $pieces_test ) === $xpath->query( '//*[@data-qf-recu]' )->length, 'chaque reçu peut être consulté' );
	$tester( $decision_test || 0 === $xpath->query( '//form' )->length, 'aucune décision en consultation seule' );
	if ( 'genere' === $cas ) { $tester( 1 === $xpath->query( '//button[@disabled]' )->length, 'validation sans reçu désactivée' ); }
	if ( 'verifie' === $cas ) { $tester( 0 === $xpath->query( '//input[@name="statut" and @value="verifie"]' )->length, 'pas de nouvelle validation' ); }
	if ( 'rejete' === $cas ) { $tester( 1 === $xpath->query( '//details[@open]' )->length, 'motif de renvoi ouvert' ); }
	if ( 'multiple' === $cas ) { $tester( 2 === $xpath->query( '//*[@data-qf-choix]' )->length, 'sélection de chaque reçu' ); }
	if ( 'pdf' === $cas ) { $tester( 1 === $xpath->query( '//iframe[@title]' )->length, 'aperçu PDF nommé' ); }
	$styles = '';
	foreach ( array( 'polices', 'app', 'pages', 'bord', 'bord-graphes', 'administration', 'ipes', 'quitus-fiche' ) as $style ) { $styles .= '<link rel="stylesheet" href="' . UEB_INSC_URI . '/assets/css/' . $style . '.css">'; }
	$sidebar = '<aside class="bo-sidebar"><a class="bo-marque" href="#"><img src="' . ueb_logo_url( 'UEB' ) . '" alt=""><span class="bo-marque__texte"><b class="bo-marque__nom">Université d’Ebolowa</b><span class="bo-marque__note">Portail de préinscription</span></span></a><p class="bo-sidebar__titre">ESPACE SCOLARITÉ</p><nav class="bo-sidebar__nav"><a href="#">Tableau de bord</a><a href="#" aria-current="page">Quitus</a><a href="#">Paiements</a><a href="#">Comptes étudiants</a></nav><div class="bo-sidebar__pied"><p class="bo-perimetre">Ton établissement<b>Faculté des Sciences</b></p><span class="bo-sortie">Agent de scolarité</span></div></aside>';
	$html = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Fiche quitus — test ' . $cas . '</title>' . $styles . '</head><body><main class="page-app page-app--bo"><div class="bo">' . $sidebar . '<div class="bo-contenu">' . $contenu . '</div></div></main><script src="' . UEB_INSC_URI . '/assets/js/quitus-fiche.js"></script><script src="' . UEB_INSC_URI . '/assets/js/remotion-ueb.js"></script></body></html>';
	file_put_contents( $sortie . '/' . $cas . '.html', $html );
}
echo $assertions . " vérifications réussies. Aperçus : " . $sortie . "\n";
