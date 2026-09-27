<?php
/** Régressions des mini-courbes : données fictives, aucune connexion à la base. */
if ( PHP_SAPI !== 'cli' ) { exit; }
date_default_timezone_set( 'UTC' ); // comme WordPress : sinon strtotime() et gmdate() décalent les jours
define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
define( 'UEB_DROITS_CLASSIQUES', 50000 );
function add_action( ...$args ) {}
function add_filter( ...$args ) {}
require dirname( __DIR__ ) . '/inc/gestion.php';
function verifier( $condition, $message ) {
 if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function ligne( $compte, $etab, $type, $montant, $creation, $statut, $validation = null ) {
 return (object) array( 'compte_id' => $compte, 'etablissement' => $etab, 'type' => $type, 'montant' => $montant, 'date_creation' => $creation, 'statut' => $statut, 'date_verification' => $validation, 'date_modification' => $validation ?: $creation );
}
$lignes = array(
 ligne( 1, 'FS', 'droits', 25000, '2026-08-25', 'verifie', '2026-08-28' ),
 ligne( 1, 'FS', 'droits', 35000, '2026-09-02', 'verifie', '2026-09-04' ),
 ligne( 2, 'FS', 'medicaux', 3000, '2026-09-01', 'verifie', '2026-09-01' ),
 ligne( 3, 'FS', 'droits', 100000, '2026-09-01', 'verifie', '2026-09-02' ),
 ligne( 3, 'FS', 'droits', 50000, '2026-09-03', 'genere' ),
 ligne( 1, 'FSEG', 'droits', 50000, '2026-09-03', 'verifie', '2026-09-05' ),
 ligne( 4, 'FS', 'droits', 25000, '2026-09-04', 'recu_envoye' ),
 ligne( 5, 'FS', 'droits', 25000, '2026-09-06', 'rejete' ),
);
$groupes = array( '3|FS' => array( 'pro' => true ) );
$depots = array( (object) array( 'jour' => '2026-09-01', 'nombre' => 2 ), (object) array( 'jour' => '2026-09-03', 'nombre' => 1 ) );
$s = ueb_suivi_series_indicateurs( $lignes, $groupes, $depots, '2026-09-01', '2026-09-05' );
verifier( $s['etudiants'] === array(3,3,3,4,4), 'Un étudiant compte une fois, même avec plusieurs quitus et établissements.' );
verifier( $s['quitus'] === array(3,4,6,7,7), 'Les quitus antérieurs sont reportés, les quitus futurs sont exclus.' );
verifier( $s['encaisse'] === array(25000,125000,125000,150000,200000), 'Dates de validation, médical exclu, trop-perçu plafonné par étudiant et établissement.' );
verifier( $s['taux'] === array(16.67,83.33,50.0,50.0,66.67), 'Le dénominateur suit les nouveaux droits classiques et professionnels.' );
verifier( $s['depots'] === array(2,0,1,0,0), 'Flux de dépôts quotidien, y compris les jours sans dépôt.' );
$court = ueb_suivi_series_indicateurs( $lignes, $groupes, $depots, '2026-09-03', '2026-09-05' );
foreach ( array('etudiants','quitus','encaisse','taux','depots') as $cle ) {
 verifier( $court[$cle] === array_slice( $s[$cle], 2 ), 'Fenêtre courte sans remise à zéro : ' . $cle );
}
$inverse = ueb_suivi_series_indicateurs( array_reverse($lignes), $groupes, $depots, '2026-09-01', '2026-09-05' );
verifier( $inverse === $s, 'Les calculs ne dépendent pas de l’ordre des lignes.' );
$vide = ueb_suivi_series_indicateurs( array(), array(), array(), '2026-09-01', '2026-09-05' );
verifier( $vide['encaisse'] === array(0,0,0,0,0), 'Fenêtre vide : vrais zéros.' );
verifier( $vide['taux'] === array(null,null,null,null,null), 'Aucun droit attendu : taux indéfini, pas un faux zéro.' );
$tardif = ueb_suivi_series_indicateurs( array(ligne(1,'FS','droits',25000,'2026-09-03','verifie','2026-09-01')), array(), array(), '2026-09-01', '2026-09-05' );
verifier( $tardif['encaisse'] === array(0,0,25000,25000,25000), 'Une décision incohérente ne précède pas la création du quitus.' );
echo "OK — historique : déduplication, cumuls, tranches, plafonds, dates, fenêtres et états vides.\n";
