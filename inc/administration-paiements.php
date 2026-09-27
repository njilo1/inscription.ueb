<?php
/** Suivi financier de l'administration : droits et frais médicaux distincts. */
defined( 'ABSPATH' ) || exit;

/** Flux mensuels issus des cumuls, sans reporter le solde d'ouverture dans un mois. */
function ueb_adm_paiements_mois( array $historique, $debut_annee ) {
 $jours = $historique['jours'] ?? array();
 if ( ! $jours ) { return array(); }
 $fin = end( $jours );
 $debut = max( $debut_annee, gmdate( 'Y-m-01', strtotime( substr( $fin, 0, 7 ) . '-01 -5 months' ) ) );
 $mois = array();
 for ( $t = strtotime( $debut ); $t <= strtotime( $fin ); $t = strtotime( '+1 month', $t ) ) {
  $mois[ gmdate( 'Y-m', $t ) ] = array( 'droits' => 0, 'medicaux' => 0 );
 }
 $precedent = array( 'encaisse' => 0, 'medicaux' => 0 );
 foreach ( $jours as $i => $jour ) {
  $cle = substr( $jour, 0, 7 );
  foreach ( array( 'encaisse' => 'droits', 'medicaux' => 'medicaux' ) as $serie => $type ) {
   $valeur = (int) ( $historique[ $serie ][ $i ] ?? 0 );
   if ( isset( $mois[ $cle ] ) ) { $mois[ $cle ][ $type ] += $valeur - $precedent[ $serie ]; }
   $precedent[ $serie ] = $valeur;
  }
 }
 return $mois;
}

/** Lignes du registre ; les effectifs médicaux comptent les quitus, pas les étudiants. */
function ueb_adm_paiements_lignes( array $suivi, $focus ) {
 $lignes = array();
 foreach ( $suivi[ $focus ? 'filieres' : 'etabs' ] as $cle => $a ) {
  $sigle = $focus ?: $cle;
  $e = ueb_etablissement( $sigle );
  $lignes[] = array( 'nom' => $focus ? $a['libelle'] : $sigle, 'detail' => $focus ? ( $a['pro'] ? 'Formation professionnelle' : 'Formation classique' ) : $e['fr'], 'sigle' => $sigle, 'type' => 'droits', 'unite' => 'étudiants', 'a' => $a );
 }
 foreach ( $suivi['medicaux_etabs'] ?? array() as $sigle => $a ) {
  $lignes[] = array( 'nom' => $sigle, 'detail' => ueb_etablissement( $sigle )['fr'], 'sigle' => $sigle, 'type' => 'medicaux', 'unite' => 'quitus', 'a' => $a );
 }
 usort( $lignes, static fn( $a, $b ) => $b['a']['attendu'] <=> $a['a']['attendu'] ?: strcmp( $a['nom'], $b['nom'] ) );
 return $lignes;
}

/** Jauge d'une tuile : les parts d'un tout, séparées d'un filet ; le reste reste en piste. */
function ueb_adm_paiements_jauge( array $parts, $total, $libelle ) {
 echo '<div class="pay-jauge" role="img" aria-label="' . esc_attr( $libelle ) . '">';
 foreach ( $parts as $classe => $valeur ) {
  if ( $total > 0 && $valeur > 0 ) {
   printf( '<i class="pay-jauge__%s" style="width:%s%%"></i>', esc_attr( $classe ), esc_attr( round( 100 * $valeur / $total, 2 ) ) );
  }
 }
 echo '</div>';
}

/**
 * Recouvrement des droits par niveau : une jauge verticale par niveau, de L1
 * à M2 (et « Non précisé » s'il y en a), remplie jusqu'au taux encaissé, la
 * part en vérification hachurée au-dessus. Une ligne pointillée marque le taux
 * du périmètre : on voit d'un coup d'œil les niveaux en retard. Les montants
 * sont dans l'infobulle de chaque niveau.
 */
function ueb_adm_paiements_niveaux( array $niveaux, array $g, $focus ) {
 $ordre   = array_merge( array_keys( UEB_NIVEAUX_INSCRIPTION ), isset( $niveaux['Non précisé'] ) ? array( 'Non précisé' ) : array() );
 $moyenne = ueb_suivi_taux( $g );
 ?>
 <section class="pay-panneau pay-niveaux" aria-labelledby="pay-niveaux-titre">
  <header class="pay-entete"><div><h2 id="pay-niveaux-titre">Recouvrement des droits par niveau</h2><p>Part des droits attendus déjà encaissée, niveau par niveau</p></div></header>
  <?php if ( ! $niveaux ) : ?>
   <p class="pay-aucune-activite">Aucun étudiant pour l’instant.</p>
  <?php else : ?>
   <ul class="pay-niveaux__graphe" style="--moyenne: <?php echo esc_attr( round( min( 100, $moyenne ) / 100, 4 ) ); ?>">
    <?php foreach ( $ordre as $niv ) :
     $a   = $niveaux[ $niv ] ?? null;
     $enc = $a ? min( 100, ueb_suivi_taux( $a ) ) : 0;
     $ver = $a ? min( 100 - $enc, ueb_suivi_taux( $a, 'verification' ) ) : 0;
     $nom = 'Non précisé' === $niv ? 'Non précisé' : $niv;
     $dit = $a
      ? sprintf( '%s, %s : %s encaissés sur %s attendus (%s), %s en vérification', $nom, ueb_suivi_etudiants( $a['etudiants'] ), ueb_fcfa( $a['encaisse'] ), ueb_fcfa( $a['attendu'] ), ueb_pourcent( $enc ), ueb_fcfa( $a['verification'] ) )
      : $nom . ' : aucun étudiant';
     ?>
     <li class="pay-niveau<?php echo $a ? '' : ' est-vide'; ?>">
      <div class="pay-niveau__corps" tabindex="0" role="img" aria-label="<?php echo esc_attr( $dit ); ?>">
       <b class="pay-niveau__taux"><?php echo esc_html( $a ? ueb_pourcent( $enc ) : '—' ); ?></b>
       <span class="pay-niveau__jauge" style="--enc: <?php echo esc_attr( round( $enc, 2 ) ); ?>%; --ver: <?php echo esc_attr( round( $ver, 2 ) ); ?>%"><?php if ( $enc > 0 ) : ?><i class="pay-niveau__encaisse"></i><?php endif; ?><?php if ( $ver > 0 ) : ?><i class="pay-niveau__verification"></i><?php endif; ?></span>
       <span class="pay-niveau__nom"><?php echo esc_html( $nom ); ?></span>
       <small><?php echo esc_html( $a ? ueb_suivi_etudiants( $a['etudiants'] ) : 'Aucun étudiant' ); ?></small>
       <?php if ( $a ) : ?><span class="pay-niveau__bulle"><b><?php echo esc_html( $nom . ', ' . ueb_suivi_etudiants( $a['etudiants'] ) ); ?></b>Encaissé : <?php echo esc_html( ueb_fcfa( $a['encaisse'] ) ); ?><br>En vérification : <?php echo esc_html( ueb_fcfa( $a['verification'] ) ); ?><br>Attendu : <?php echo esc_html( ueb_fcfa( $a['attendu'] ) ); ?></span><?php endif; ?>
      </div>
     </li>
    <?php endforeach; ?>
   </ul>
   <div class="pay-legende"><span><i class="pay-couleur-droits"></i>Encaissé</span><span><i class="pay-couleur-verification"></i>En vérification</span><span><i class="pay-couleur-moyenne"></i>Taux <?php echo $focus ? 'de ' . esc_html( $focus ) : 'de l’université'; ?> : <?php echo esc_html( ueb_pourcent( $moyenne ) ); ?></span></div>
  <?php endif; ?>
 </section>
 <?php
}

/**
 * Écart de rapprochement : reçus vérifiés au-delà de l'attendu (trop-perçus),
 * ventilés par établissement, ou par filière dans la vue d'un établissement.
 * Rien ne s'affiche tant qu'il n'y a pas d'écart.
 */
function ueb_adm_paiements_rapprochement( array $suivi, $focus ) {
 $total = $suivi['global']['trop_percu'];
 if ( $total <= 0 ) {
  return;
 }
 $parts = array();
 foreach ( $suivi[ $focus ? 'filieres' : 'etabs' ] as $cle => $a ) {
  if ( $a['trop_percu'] > 0 ) {
   $parts[] = array( 'nom' => $focus ? $a['libelle'] : $cle, 'sigle' => $focus ?: $cle, 'montant' => $a['trop_percu'] );
  }
 }
 usort( $parts, static fn( $a, $b ) => $b['montant'] <=> $a['montant'] );
 $autres = array_slice( $parts, 5 );
 $nombre = count( $parts );
 $unite  = $focus ? array( 'filière concernée', 'filières concernées' ) : array( 'établissement concerné', 'établissements concernés' );
 ?>
 <section class="pay-panneau pay-rapprochement" aria-labelledby="pay-rapprochement-titre">
  <header class="pay-entete">
   <div class="pay-rapprochement__titre"><span class="pay-rapprochement__icone"><?php echo ueb_icone( 'balance', 20 ); ?></span><div><h2 id="pay-rapprochement-titre">Écart de rapprochement</h2><p>Reçus vérifiés au-delà du montant attendu</p></div></div>
   <span class="pay-etat pay-etat--attente pay-rapprochement__statut"><?php echo ueb_icone( 'alerte', 14 ); ?>À rapprocher</span>
  </header>
  <div class="pay-rapprochement__corps">
   <div class="pay-rapprochement__resume">
    <div class="pay-rapprochement__chiffres">
     <p class="pay-rapprochement__montant"><b><?php echo esc_html( ueb_formater_montant( $total ) ); ?></b><small>FCFA de trop-perçus</small></p>
     <?php if ( $nombre ) : ?><p class="pay-rapprochement__compte"><b><?php echo (int) $nombre; ?></b><small><?php echo esc_html( $unite[ 1 < $nombre ? 1 : 0 ] ); ?></small></p><?php endif; ?>
    </div>
    <p class="pay-rapprochement__note"><?php echo ueb_icone( 'info', 16 ); ?><span>Ces montants ne comptent ni dans les droits encaissés ni dans le taux de recouvrement. Chaque écart est à rapprocher du dossier de l’étudiant concerné.</span></p>
   </div>
   <?php if ( $parts ) : ?>
    <div class="pay-rapprochement__detail">
     <p class="pay-rapprochement__legende"><?php echo $focus ? 'Répartition par filière' : 'Répartition par établissement'; ?></p>
     <ul>
      <?php foreach ( array_slice( $parts, 0, 5 ) as $p ) :
       $part  = 100 * $p['montant'] / $total;
       $corps = sprintf(
        '<span class="pay-ecart__tete">%s<span class="pay-ecart__nom"><b>%s</b>%s</span><strong class="pay-ecart__montant">%s</strong></span><span class="pay-ecart__mesure"><span class="pay-ecart__barre" aria-hidden="true"><i style="width:%s%%"></i></span><small class="pay-ecart__part">%s</small></span>',
        $focus ? '' : '<img src="' . esc_url( ueb_logo_url( $p['sigle'] ) ) . '" alt="" width="30" height="30" loading="lazy">',
        esc_html( $p['nom'] ),
        $focus ? '' : '<small>' . esc_html( ueb_etablissement( $p['sigle'] )['fr'] ) . '</small>',
        esc_html( ueb_formater_montant( $p['montant'] ) ),
        esc_attr( round( $part, 2 ) ),
        esc_html( ueb_pourcent( $part ) . ' du total' )
       );
       ?>
       <li class="pay-ecart">
        <?php if ( $focus ) : ?>
         <div class="pay-ecart__ligne"><?php echo $corps; // phpcs:ignore -- composé et échappé ci-dessus ?></div>
        <?php else : ?>
         <a class="pay-ecart__ligne" href="<?php echo esc_url( add_query_arg( array( 'vue' => 'paiements', 'etab' => $p['sigle'] ), ueb_url_administration() ) ); ?>"><?php echo $corps; // phpcs:ignore -- composé et échappé ci-dessus ?><?php echo ueb_icone( 'chevron-d', 16 ); ?></a>
        <?php endif; ?>
       </li>
      <?php endforeach; ?>
     </ul>
     <?php if ( $autres ) : ?><p class="pay-rapprochement__autres"><?php echo esc_html( sprintf( 'Et %d autres %s, pour %s', count( $autres ), $focus ? 'filières' : 'établissements', ueb_fcfa( array_sum( array_column( $autres, 'montant' ) ) ) ) ); ?></p><?php endif; ?>
    </div>
   <?php endif; ?>
  </div>
 </section>
 <?php
}

/** Situation d'une ligne : pas de statut « soldé » en l'absence de droits attendus. */
function ueb_adm_paiement_situation( array $a ) {
 return $a['attendu'] <= 0 ? 'vide' : ( $a['encaisse'] >= $a['attendu'] ? 'solde' : ( $a['encaisse'] > 0 ? 'partiel' : 'attente' ) );
}

function ueb_adm_paiements( array $suivi, $focus ) {
 $g = $suivi['global']; $m = $suivi['medicaux'];
 $annee = ueb_annee_academique();
 $verification = $g['verification'] + $m['verification'];
 $attendu = $g['attendu'] + $m['attendu'];
 $encaisse = $g['encaisse'] + $m['encaisse'];
 $reste = max( 0, $attendu - $encaisse );
 $statuts = $suivi['quitus_statuts'];
 $a_verifier = $statuts['droits']['recu_envoye'] + $statuts['medicaux']['recu_envoye'];
 $a_corriger = $statuts['droits']['rejete'] + $statuts['medicaux']['rejete'];
 $lignes = ueb_adm_paiements_lignes( $suivi, $focus );
 ?>
 <div class="pay" data-pay data-annee="<?php echo esc_attr( $annee['code'] ); ?>">
  <div class="pay-contexte">
   <p><?php echo ueb_icone( 'banque', 16 ); ?><span>Périmètre suivi : <b><?php echo esc_html( $focus ? $focus . ', ' . ueb_etablissement( $focus )['fr'] : 'toute l’université' ); ?></b></span></p>
   <form method="get" action="<?php echo esc_url( ueb_url_administration() ); ?>">
    <input type="hidden" name="vue" value="paiements">
    <label for="pay-etab">Établissement</label>
    <select name="etab" id="pay-etab"><option value="">Tous les établissements</option><?php foreach ( ueb_etablissements() as $sigle => $e ) : ?><option value="<?php echo esc_attr( $sigle ); ?>" <?php selected( $focus, $sigle ); ?>><?php echo esc_html( $e['fr'] . ' (' . $sigle . ')' ); ?></option><?php endforeach; ?></select>
    <button class="pay-btn" type="submit">Afficher</button>
   </form>
  </div>
  <div class="pay-apercu">
   <div class="pay-kpis">
    <?php foreach ( array(
     array( 'Droits encaissés', $g['encaisse'], 'Sur ' . ueb_fcfa( $g['attendu'] ) . ' attendus', 'banque', 'fort', $g['attendu'] ? ueb_pourcent( ueb_suivi_taux( $g ) ) . ' recouvrés' : 'Aucun droit attendu' ),
     array( 'Frais médicaux encaissés', $m['encaisse'], 'Sur ' . ueb_fcfa( $m['attendu'] ) . ' déclarés', 'bouclier', 'medical', ueb_formater_montant( $m['etudiants'] ) . ' quitus médicaux' ),
     array( 'Montants à vérifier', $verification, 'Droits et frais médicaux', 'horloge', 'verification', $a_verifier . ' quitus avec reçu à contrôler' ),
     array( 'Reste à encaisser', $reste, 'Inclut les montants à vérifier', 'fichier', 'reste', 'Sur les dossiers de l’année' ),
    ) as $i => $k ) : ?>
     <article class="pay-kpi pay-kpi--<?php echo esc_attr( $k[4] ); ?>" style="--ordre:<?php echo $i; ?>">
      <header><h2><?php echo esc_html( $k[0] ); ?></h2><span><?php echo ueb_icone( $k[3], 18 ); ?></span></header>
      <p class="pay-kpi__valeur" title="<?php echo esc_attr( ueb_fcfa( $k[1] ) ); ?>"><?php echo esc_html( ueb_adm_montant_court( $k[1] ) ); ?><small>FCFA</small></p>
      <p class="pay-kpi__note"><?php echo esc_html( $k[2] ); ?></p>
      <span class="pay-kpi__repere"><?php echo esc_html( $k[5] ); ?></span>
     </article>
    <?php endforeach; ?>
   </div>
   <?php ueb_adm_paiements_histogramme( ueb_adm_paiements_mois( $suivi['historique'] ?? array(), $annee['debut'] . '-09-01' ) ); ?>
  </div>
  <div class="pay-secondaire">
   <?php $suivis = $g['soldes'] + $g['partiels'] + $g['aucun']; ?>
   <section class="pay-tuile">
    <header><span class="pay-tuile__icone"><?php echo ueb_icone( 'check', 20 ); ?></span><h2>Situation des étudiants</h2></header>
    <p class="pay-tuile__chiffre"><?php echo esc_html( ueb_formater_montant( $suivis ) ); ?><span><?php echo 1 < $suivis ? 'étudiants suivis' : 'étudiant suivi'; ?></span></p>
    <div class="pay-tuile__details">
     <?php ueb_adm_paiements_jauge( array( 'soldes' => $g['soldes'], 'partiels' => $g['partiels'] ), $suivis, sprintf( '%s droits soldés, %s paiements partiels et %s sans paiement vérifié', ueb_formater_montant( $g['soldes'] ), ueb_formater_montant( $g['partiels'] ), ueb_formater_montant( $g['aucun'] ) ) ); ?>
     <p><i class="pay-jauge__soldes"></i><b><?php echo esc_html( ueb_formater_montant( $g['soldes'] ) ); ?></b> droits soldés</p>
     <p><i class="pay-jauge__partiels"></i><b><?php echo esc_html( ueb_formater_montant( $g['partiels'] ) ); ?></b> paiements partiels</p>
     <p><i class="pay-jauge__reste"></i><b><?php echo esc_html( ueb_formater_montant( $g['aucun'] ) ); ?></b> sans paiement vérifié</p>
    </div>
   </section>
   <section class="pay-tuile pay-tuile--attente">
    <header><span class="pay-tuile__icone"><?php echo ueb_icone( 'horloge', 20 ); ?></span><h2>Contrôles en attente</h2></header>
    <p class="pay-tuile__chiffre"><?php echo esc_html( ueb_formater_montant( $a_verifier ) ); ?><span>quitus à vérifier</span></p>
    <div class="pay-tuile__details">
     <?php ueb_adm_paiements_jauge( array( 'droits' => $statuts['droits']['recu_envoye'], 'medicaux' => $statuts['medicaux']['recu_envoye'] ), $a_verifier, sprintf( 'Quitus à vérifier : %d de droits universitaires et %d de frais médicaux', $statuts['droits']['recu_envoye'], $statuts['medicaux']['recu_envoye'] ) ); ?>
     <p><i class="pay-jauge__droits"></i><b><?php echo (int) $statuts['droits']['recu_envoye']; ?></b> droits universitaires</p>
     <p><i class="pay-jauge__medicaux"></i><b><?php echo (int) $statuts['medicaux']['recu_envoye']; ?></b> frais médicaux</p>
     <p class="pay-tuile__corriger"><?php echo ueb_icone( 'alerte', 15 ); ?><b><?php echo (int) $a_corriger; ?></b> quitus à corriger</p>
    </div>
   </section>
   <?php ueb_adm_paiements_repartition( $g['encaisse'], $m['encaisse'] ); ?>
  </div>
  <div class="pay-duo">
   <?php ueb_adm_paiements_niveaux( $suivi['niveaux'], $g, $focus ); ?>
   <?php ueb_adm_paiements_rapprochement( $suivi, $focus ); ?>
  </div>
  <?php ueb_adm_paiements_registre( $lignes, $focus ); ?>
 </div>
 <?php
}

function ueb_adm_paiements_histogramme( array $mois ) {
 $total = 0; $maximum = 1;
 foreach ( $mois as $a ) { $total += array_sum( $a ); $maximum = max( $maximum, array_sum( $a ) ); }
 $reels = count( $mois );
 /* Six colonnes au moins : les mois à venir de l'année gardent leur place,
    sans barre, pour situer le mois en cours dans l'année académique. */
 if ( $mois && $reels < 6 ) {
  $suivant = strtotime( array_key_last( $mois ) . '-01 +1 month' );
  for ( $k = $reels; $k < 6; $k++, $suivant = strtotime( '+1 month', $suivant ) ) {
   $mois[ gmdate( 'Y-m', $suivant ) ] = null;
  }
 }
 $echelle = ueb_graphe_echelle( $maximum ); $haut = $echelle['haut'];
 $noms = array( 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.' );
 ?>
 <section class="pay-panneau pay-histogramme" aria-labelledby="pay-evolution">
  <header class="pay-entete"><div><h2 id="pay-evolution">Encaissements par mois</h2><p>Droits universitaires et frais médicaux</p></div><span class="pay-pastille">Année en cours</span></header>
  <p class="pay-histogramme__total"><b><?php echo esc_html( ueb_adm_montant_court( $total ) ); ?></b><span>FCFA encaissés <?php echo $reels > 1 ? 'sur les ' . $reels . ' derniers mois' : 'ce mois-ci'; ?></span></p>
  <?php if ( ! $total ) : ?><p class="pay-aucune-activite">Aucun encaissement vérifié sur cette période.</p><?php endif; ?>
  <div class="pay-histogramme__graphe">
   <div class="pay-histogramme__axe" aria-hidden="true"><?php for ( $v = 0; $v <= $haut; $v += $echelle['pas'] ) : ?><span style="--y:<?php echo esc_attr( 100 * $v / $haut ); ?>%"><?php echo esc_html( ueb_adm_montant_court( $v ) ); ?></span><?php endfor; ?></div>
   <div class="pay-histogramme__zone">
    <div class="pay-histogramme__grille" aria-hidden="true"><?php for ( $v = 0; $v <= $haut; $v += $echelle['pas'] ) : ?><i style="--y:<?php echo esc_attr( 100 * $v / $haut ); ?>%"></i><?php endfor; ?></div>
    <div class="pay-histogramme__colonnes">
     <?php $i = 0; foreach ( $mois as $cle => $a ) : $label = $noms[ (int) substr( $cle, 5, 2 ) - 1 ] . ' ' . substr( $cle, 0, 4 ); ?>
      <?php if ( null === $a ) : ?>
      <div class="pay-mois pay-mois--avenir" aria-label="<?php echo esc_attr( $label . ' : mois à venir' ); ?>" role="img">
       <div class="pay-mois__barres" aria-hidden="true"></div>
       <span class="pay-mois__nom" aria-hidden="true"><?php echo esc_html( $noms[ (int) substr( $cle, 5, 2 ) - 1 ] ); ?><small><?php echo esc_html( substr( $cle, 0, 4 ) ); ?></small></span>
      </div>
      <?php continue; endif; ?>
      <div class="pay-mois" tabindex="0" aria-label="<?php echo esc_attr( $label . ' : droits ' . ueb_fcfa( $a['droits'] ) . ', frais médicaux ' . ueb_fcfa( $a['medicaux'] ) ); ?>">
       <div class="pay-mois__barres" aria-hidden="true" style="--ordre:<?php echo $i++; ?>"><i class="pay-mois__droits" style="height:<?php echo esc_attr( 100 * $a['droits'] / $haut ); ?>%"></i><i class="pay-mois__medicaux" style="height:<?php echo esc_attr( 100 * $a['medicaux'] / $haut ); ?>%"></i></div>
       <span class="pay-mois__nom" aria-hidden="true"><?php echo esc_html( $noms[ (int) substr( $cle, 5, 2 ) - 1 ] ); ?><small><?php echo esc_html( substr( $cle, 0, 4 ) ); ?></small></span>
       <span class="pay-mois__bulle" aria-hidden="true"><b><?php echo esc_html( $label ); ?></b>Droits : <?php echo esc_html( ueb_fcfa( $a['droits'] ) ); ?><br>Médicaux : <?php echo esc_html( ueb_fcfa( $a['medicaux'] ) ); ?></span>
      </div>
     <?php endforeach; ?>
    </div>
   </div>
  </div>
  <div class="pay-legende"><span><i class="pay-couleur-droits"></i>Droits universitaires</span><span><i class="pay-couleur-medicaux"></i>Frais médicaux</span><?php if ( $reels < count( $mois ) ) : ?><span><i class="pay-couleur-avenir"></i>Mois à venir</span><?php endif; ?></div>
 </section>
 <?php
}

function ueb_adm_paiements_repartition( $droits, $medicaux ) {
 $total = $droits + $medicaux; $part = $total ? 100 * $droits / $total : 0;
 ?>
 <section class="pay-panneau pay-repartition" aria-labelledby="pay-repartition-titre">
  <header class="pay-entete"><div><h2 id="pay-repartition-titre">Répartition des encaissements</h2><p>Sur toute l’année académique</p></div></header>
  <div class="pay-repartition__corps">
   <div class="pay-anneau"><svg viewBox="0 0 140 140" aria-hidden="true"><circle class="pay-anneau__piste" cx="70" cy="70" r="52"/><?php if ( $total ) : ?><circle class="pay-anneau__droits" cx="70" cy="70" r="52" pathLength="100" stroke-dasharray="<?php echo esc_attr( $part . ' ' . ( 100 - $part ) ); ?>"/><circle class="pay-anneau__medicaux" cx="70" cy="70" r="52" pathLength="100" stroke-dasharray="<?php echo esc_attr( ( 100 - $part ) . ' ' . $part ); ?>" stroke-dashoffset="<?php echo esc_attr( -$part ); ?>"/><?php endif; ?></svg><span><b><?php echo esc_html( $total ? ueb_adm_montant_court( $total ) : '—' ); ?></b>FCFA<br>encaissés</span></div>
   <ul><li><i class="pay-couleur-droits"></i><div><span>Droits universitaires</span><b><?php echo esc_html( ueb_fcfa( $droits ) ); ?></b></div><small><?php echo esc_html( $total ? ueb_pourcent( $part ) : '—' ); ?></small></li><li><i class="pay-couleur-medicaux"></i><div><span>Frais médicaux</span><b><?php echo esc_html( ueb_fcfa( $medicaux ) ); ?></b></div><small><?php echo esc_html( $total ? ueb_pourcent( 100 - $part ) : '—' ); ?></small></li></ul>
  </div>
 </section>
 <?php
}

function ueb_adm_paiements_registre( array $lignes, $focus ) {
 $libelles = array( 'solde' => 'Soldé', 'partiel' => 'Partiel', 'attente' => 'À encaisser', 'vide' => 'Aucun montant' );
 ?>
 <section class="pay-panneau pay-registre" id="pay-registre" data-pay-registre aria-labelledby="pay-registre-titre">
  <header class="pay-entete"><div><h2 id="pay-registre-titre"><?php echo $focus ? 'Suivi détaillé de ' . esc_html( $focus ) : 'Suivi par établissement'; ?></h2><p>Droits et frais médicaux séparés, montants en FCFA</p></div><button class="pay-btn" type="button" data-pay-export hidden><?php echo ueb_icone( 'telecharger', 17 ); ?>Lignes affichées en CSV</button></header>
  <div class="pay-outils" data-pay-outils hidden>
   <div class="pay-types" role="group" aria-label="Type de paiement"><button type="button" data-pay-type="tous" aria-pressed="true">Tous les paiements</button><button type="button" data-pay-type="droits" aria-pressed="false">Droits universitaires</button><button type="button" data-pay-type="medicaux" aria-pressed="false">Frais médicaux</button></div>
   <div class="pay-filtres">
    <label class="pay-recherche"><span>Recherche</span><span><?php echo ueb_icone( 'loupe', 17 ); ?><input type="search" placeholder="<?php echo $focus ? 'Rechercher une filière' : 'Rechercher un établissement'; ?>" data-pay-recherche aria-controls="pay-table"></span></label>
    <label><span>Situation</span><select data-pay-situation aria-controls="pay-table"><option value="tous">Toutes les situations</option><option value="verification">En vérification</option><option value="attente">À encaisser</option><option value="partiel">Paiement partiel</option><option value="solde">Soldé</option><option value="reste">Avec un reste</option></select></label>
    <label><span>Trier par</span><select data-pay-tri aria-controls="pay-table"><option value="attendu">Montant attendu</option><option value="reste">Reste à encaisser</option><option value="taux">Taux de recouvrement</option><option value="nom">Nom</option></select></label>
    <button type="button" class="pay-btn pay-btn--discret" data-pay-reset>Réinitialiser</button>
   </div>
  </div>
  <p class="pay-resultats" data-pay-resultats role="status" aria-live="polite"><?php echo count( $lignes ); ?> lignes. Le bilan en haut porte sur tout le périmètre.</p>
  <div class="pay-table-cadre" tabindex="0" role="region" aria-label="Registre financier">
   <table id="pay-table"><caption class="sr">Suivi des droits universitaires et des frais médicaux en FCFA</caption><thead><tr><th scope="col"><?php echo $focus ? 'Filière / établissement' : 'Établissement'; ?></th><th scope="col">Type</th><th scope="col">Effectif</th><th scope="col">Attendu</th><th scope="col">Encaissé</th><th scope="col">À vérifier</th><th scope="col">Reste</th><th scope="col">Recouvrement</th></tr></thead><tbody>
   <?php foreach ( $lignes as $ligne ) : $a = $ligne['a']; $reste = max( 0, $a['attendu'] - $a['encaisse'] ); $taux = ueb_suivi_taux( $a ); $etat = ueb_adm_paiement_situation( $a );
    $data = array( 'nom' => $ligne['nom'], 'detail' => $ligne['detail'], 'type' => $ligne['type'], 'unite' => $ligne['unite'], 'effectif' => $a['etudiants'], 'attendu' => $a['attendu'], 'encaisse' => $a['encaisse'], 'verification' => $a['verification'], 'reste' => $reste, 'taux' => $taux, 'situation' => $etat ); ?>
    <tr data-pay-ligne="<?php echo esc_attr( wp_json_encode( $data ) ); ?>">
     <th scope="row"><div class="pay-identite"><?php if ( ! $focus ) : ?><img src="<?php echo esc_url( ueb_logo_url( $ligne['sigle'] ) ); ?>" alt="" width="30" height="30" loading="lazy"><?php endif; ?><div><?php if ( ! $focus ) : ?><a href="<?php echo esc_url( add_query_arg( array( 'vue' => 'paiements', 'etab' => $ligne['sigle'] ), ueb_url_administration() ) ); ?>"><?php echo esc_html( $ligne['nom'] ); ?><?php echo ueb_icone( 'chevron-d', 13 ); ?></a><?php else : ?><b><?php echo esc_html( $ligne['nom'] ); ?></b><?php endif; ?><small><?php echo esc_html( $ligne['detail'] ); ?></small></div></div></th>
     <td data-titre="Type"><span class="pay-type pay-type--<?php echo esc_attr( $ligne['type'] ); ?>"><?php echo 'droits' === $ligne['type'] ? 'Droits' : 'Médicaux'; ?></span></td>
     <td data-titre="Effectif"><?php echo esc_html( ueb_formater_montant( $a['etudiants'] ) ); ?><small class="pay-unite"><?php echo esc_html( $ligne['unite'] ); ?></small></td>
     <?php foreach ( array( 'attendu' => 'Attendu', 'encaisse' => 'Encaissé', 'verification' => 'À vérifier' ) as $cle => $titre ) : ?><td class="pay-montant pay-montant--<?php echo esc_attr( $cle ); ?>" data-titre="<?php echo esc_attr( $titre ); ?>"><?php echo esc_html( ueb_formater_montant( $a[ $cle ] ) ); ?></td><?php endforeach; ?>
     <td class="pay-montant pay-montant--reste" data-titre="Reste"><?php echo esc_html( ueb_formater_montant( $reste ) ); ?></td>
     <td class="pay-cellule-taux" data-titre="Recouvrement"><div class="pay-taux"><b><?php echo esc_html( $a['attendu'] ? ueb_pourcent( $taux ) : '—' ); ?></b><span class="pay-etat pay-etat--<?php echo esc_attr( $etat ); ?>"><?php echo esc_html( $libelles[ $etat ] ); ?></span><i aria-hidden="true"><span style="width:<?php echo esc_attr( min( 100, $taux ) ); ?>%"></span></i></div></td>
    </tr>
   <?php endforeach; ?>
   </tbody></table>
  </div>
  <div class="pay-vide" data-pay-vide <?php echo $lignes ? 'hidden' : ''; ?>><?php echo ueb_icone( 'fichier', 24 ); ?><b><?php echo $lignes ? 'Aucune ligne ne correspond aux filtres.' : 'Aucun paiement à suivre pour le moment.'; ?></b><p><?php echo $lignes ? 'Modifiez la recherche ou réinitialisez les filtres.' : 'Les montants apparaîtront dès la génération des premiers quitus.'; ?></p></div>
  <footer class="pay-registre__pied"><p data-pay-total></p><nav aria-label="Pages du registre" data-pay-pagination hidden><button type="button" class="pay-btn" data-pay-precedent aria-label="Page précédente"><?php echo ueb_icone( 'fleche-g', 16 ); ?></button><span data-pay-page></span><button type="button" class="pay-btn" data-pay-suivant aria-label="Page suivante"><?php echo ueb_icone( 'chevron-d', 16 ); ?></button></nav></footer>
 </section>
 <?php
}
