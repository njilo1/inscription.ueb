<?php
/**
 * Espace IPES, tableau de bord : pensions encaissées de l'année, ce qui reste
 * à reverser, ce qui a été reversé et vérifié, puis les derniers bordereaux.
 * Attend $ipes, $annee et $ici (page-ipes.php).
 */
defined( 'ABSPATH' ) || exit;

$totaux  = ueb_ipes_totaux( $ipes->id );
$jauge   = ueb_ipes_jauge( $ipes->id );
$recents = array_slice( ueb_ipes_bordereaux( $ipes->id ), 0, 5 );
?>
<header class="bo-entete">
	<div class="bo-entete__texte">
		<p class="bo-entete__contexte"><span><?php echo esc_html( $ipes->sigle ); ?></span><span class="bo-entete__annee"><?php echo esc_html( $annee['libelle'] ); ?></span></p>
		<h1>Tableau de bord</h1>
		<p class="bo-entete__sous-titre"><?php echo esc_html( $ipes->nom_fr ); ?></p>
	</div>
	<a class="btn btn--primaire bo-entete__action" href="<?php echo $ici( array( 'vue' => 'etudiants', 'ajout' => 1 ) ); ?>#ajout"><?php echo ueb_icone( 'plus', 18 ); ?>Ajouter un étudiant</a>
</header>
<?php ueb_afficher_flash(); ?>

<div class="bo-tete">
	<?php ueb_carte_hero( ueb_fcfa( $totaux['encaisse'] ), 'Pensions encaissées', $totaux['etudiants'] . ' étudiant' . ( $totaux['etudiants'] > 1 ? 's' : '' ) . ' · ' . $annee['libelle'] ); ?>
	<div class="bo-chiffres">
		<?php
		ueb_carte_chiffre( ueb_fcfa( $totaux['libre'] ), 'À reverser', 'horloge', 'attente', array( 'note' => 'Versements dans aucun bordereau' ) );
		ueb_carte_chiffre( ueb_fcfa( $jauge['envoye'] ), 'Reversé à la tutelle', 'envoyer', '', array( 'note' => 'Bordereaux envoyés ou vérifiés' ) );
		ueb_carte_chiffre( ueb_fcfa( $jauge['verifie'] ), 'Vérifié par l’UEb', 'check', 'verifie', null === $jauge['du']
			? array( 'note' => 'Montant annuel dû non renseigné' )
			: array( 'part' => $jauge['du'] ? 100 * $jauge['verifie'] / $jauge['du'] : 100, 'note' => 'Sur ' . ueb_fcfa( $jauge['du'] ) . ' dus (indicatif)' ) );
		ueb_carte_chiffre( null === $jauge['reste'] ? '—' : ueb_fcfa( $jauge['reste'] ), 'Reste dû', 'banque', $jauge['reste'] ? 'rejete' : '', array( 'note' => null === $jauge['reste'] ? 'Selon la convention, à préciser' : 'Montant dû moins vérifié' ) );
		?>
	</div>
</div>

<section class="carte bo-panneau" aria-labelledby="ipes-recents">
	<header class="bo-panneau__entete">
		<span class="bo-panneau__icone"><?php echo ueb_icone( 'recu', 20 ); ?></span>
		<div><h2 id="ipes-recents">Derniers bordereaux</h2><p>Chaque bordereau reverse des versements à ta tutelle ; l’UEb le vérifie.</p></div>
	</header>
	<?php if ( ! $recents ) : ?>
		<div class="bo-vide"><span><?php echo ueb_icone( 'recu', 22 ); ?></span><p><?php echo $totaux['etudiants'] ? 'Aucun bordereau cette année. Crée-en un depuis l’onglet Bordereaux.' : 'Commence par ajouter tes étudiants et leurs versements.'; ?></p></div>
	<?php else : ?>
		<?php include UEB_INSC_DIR . '/templates/composants/ipes-espace-tableau-bordereaux.php'; ?>
		<p><a class="btn btn--lien btn--petit" href="<?php echo $ici( array( 'vue' => 'bordereaux' ) ); ?>">Tous les bordereaux<?php echo ueb_icone( 'fleche', 16 ); ?></a></p>
	<?php endif; ?>
</section>
