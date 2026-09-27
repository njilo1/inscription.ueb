<?php
/** Calculs du suivi financier : fixtures locales, aucune connexion à la base. */
if ( PHP_SAPI !== 'cli' ) { exit; }
require __DIR__ . '/dashboard-historique.php';
require dirname( __DIR__ ) . '/inc/administration-paiements.php';
define( 'UEB_NIVEAUX_INSCRIPTION', array( 'L1' => 'Licence 1' ) );
function ueb_etab_agent() { return ''; }
verifier( $s['medicaux'] === array(3000,3000,3000,3000,3000), 'Les frais médicaux vérifiés ont leur propre cumul.' );
$medical = ueb_suivi_series_indicateurs( array(
 ligne(1,'FS','medicaux',3000,'2026-08-01','verifie','2026-08-02'),
 ligne(2,'FS','medicaux',3000,'2026-09-01','recu_envoye'),
 ligne(3,'FS','medicaux',3000,'2026-09-01','rejete'),
 ligne(4,'FS','medicaux',3000,'2026-09-02','verifie','2026-09-03'),
 ligne(5,'FS','medicaux',3000,'2026-09-02','verifie','2026-09-10'),
), array(), array(), '2026-09-01', '2026-09-04' );
verifier( $medical['medicaux'] === array(3000,3000,6000,6000), 'Report médical antérieur ; validation tardive ; reçus, rejets et validations futures exclus.' );
verifier( $medical['encaisse'] === array(0,0,0,0), 'Les frais médicaux ne gonflent pas les droits.' );
$mensuel = ueb_adm_paiements_mois( array(
 'jours'=>array('2026-08-31','2026-09-01','2026-09-30','2026-10-01','2027-01-31','2027-02-01','2027-03-01'),
 'encaisse'=>array(10000,20000,25000,50000,75000,80000,90000),
 'medicaux'=>array(3000,6000,9000,9000,12000,15000,18000),
), '2026-09-01' );
verifier( array_keys($mensuel) === array('2026-10','2026-11','2026-12','2027-01','2027-02','2027-03'), 'Six mois calendaires, y compris au passage à la nouvelle année.' );
verifier( $mensuel['2026-10'] === array('droits'=>25000,'medicaux'=>0), 'Le premier mois affiché exclut le solde d’ouverture.' );
verifier( $mensuel['2026-11'] === array('droits'=>0,'medicaux'=>0), 'Un mois sans activité reste à zéro.' );
$debut = ueb_adm_paiements_mois(array('jours'=>array('2026-08-31','2026-09-01'),'encaisse'=>array(10000,15000),'medicaux'=>array(3000,6000)), '2026-09-01');
verifier( $debut === array('2026-09'=>array('droits'=>5000,'medicaux'=>3000)), 'Le premier mois académique n’inclut pas le report antérieur.' );
verifier( ueb_adm_paiements_mois(array(), '2026-09-01') === array(), 'Historique absent sans chiffres inventés.' );
verifier( ueb_adm_paiement_situation(array('attendu'=>0,'encaisse'=>0)) === 'vide', 'Aucun montant attendu ne signifie pas soldé.' );

// Simule uniquement la lecture des quitus et du catalogue effectuée par le suivi.
$wpdb = new class {
 public function prepare($sql, ...$args) { return $sql; }
 public function get_results($sql) {
  if (str_contains($sql, 'JOIN ueb_facultes')) { return array(); }
  $rows = array(
   ligne(1,'FS','droits',60000,'2026-09-01','verifie','2026-09-02'),
   ligne(2,'FS','droits',25000,'2026-09-01','recu_envoye'),
   ligne(1,'FS','medicaux',3000,'2026-09-01','verifie','2026-09-02'),
   ligne(2,'FSEG','medicaux',3000,'2026-09-01','recu_envoye'),
   ligne(3,'FSEG','medicaux',3000,'2026-09-01','rejete'),
  );
  foreach($rows as $i=>$r){$r->id=$i+1;$r->filiere_id=1;$r->departement='Sciences';$r->parcours='L1';$r->filiere_libelle='Sciences';$r->type_formation='classique';}
  return $rows;
 }
};
$suivi = ueb_suivi_paiements('2026-2027');
verifier( $suivi['global']['encaisse'] === 50000 && $suivi['global']['trop_percu'] === 10000, 'Plafonnement et trop-perçu conservés.' );
verifier( $suivi['medicaux'] === array('etudiants'=>3,'attendu'=>9000,'encaisse'=>3000,'verification'=>3000), 'Les totaux médicaux séparent les statuts.' );
verifier( $suivi['medicaux_etabs']['FS']['encaisse'] === 3000 && $suivi['medicaux_etabs']['FSEG']['attendu'] === 6000, 'Ventilation médicale par établissement.' );
verifier( $suivi['quitus_statuts']['droits']['recu_envoye'] === 1 && $suivi['quitus_statuts']['medicaux']['rejete'] === 1, 'Compteurs de contrôle distincts par type.' );
echo "OK — paiements : frais médicaux, plafonds, statuts, ventilation et flux mensuels.\n";
