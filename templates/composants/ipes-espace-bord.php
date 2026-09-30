<?php
/**
 * Espace IPES, tableau de bord : les reversements de l'année (héros et jauge
 * Remotion), ce qui attend l'IPES (bordereau rejeté à corriger, brouillon à
 * envoyer, étudiants à reverser), puis les derniers bordereaux.
 * Attend $ipes, $annee, $ici et $url (page-ipes.php).
 */
defined( 'ABSPATH' ) || exit;

$jauge      = ueb_ipes_jauge( $ipes->id );
$bordereaux = ueb_ipes_bordereaux( $ipes->id );
$fiche      = static fn( $b ) => $url( array( 'vue' => 'bordereaux', 'bordereau' => (int) $b->id ) );

/* À faire, du plus urgent au moins urgent : chaque ligne dit quoi et mène où le faire. */
$a_faire = array();
foreach ( $bordereaux as $b ) {
	if ( 'rejete' === $b->statut ) {
		$a_faire[] = array( 'rejete', 'alerte', ueb_ipes_numero( $b ) . ' rejeté par l’UEb', 'Motif : « ' . $b->motif_rejet . ' » Corrige-le puis renvoie-le : il garde son numéro.', $fiche( $b ), 'Corriger le bordereau' );
	}
}
foreach ( $bordereaux as $b ) {
	if ( 'brouillon' === $b->statut && (int) $b->nb_etudiants ) {
		$recu      = ueb_ipes_nb_recus( $ipes->id, $b->id );
		$a_faire[] = array( 'brouillon', $recu ? 'envoyer' : 'recu', ueb_ipes_numero( $b ) . ( $recu ? ' prêt à envoyer' : ' : reçu bancaire à joindre' ), ueb_ipes_pluriel( $b->nb_etudiants, 'étudiant' ) . ' pour ' . ueb_fcfa( ueb_ipes_montant_bordereau( $b ) ) . '. ' . ( $recu ? 'Envoie-le à la ' . $b->etablissement . ' quand il est complet.' : 'Joins le reçu du virement à la ' . $b->etablissement . ' pour pouvoir l’envoyer.' ), $fiche( $b ), 'Ouvrir le brouillon' );
	}
}
if ( $jauge['libres'] > 0 ) {
	$a_faire[] = array( 'libre', 'recu', ueb_ipes_pluriel( $jauge['libres'], 'étudiant' ) . ' à reverser', 'Soit ' . ueb_fcfa( $jauge['libres'] * UEB_IPES_REVERSEMENT_PAR_ETUDIANT ) . ' : ces étudiants ne sont encore dans aucun bordereau.', $url( array( 'vue' => 'bordereaux' ) ) . '#nouveau-bordereau', 'Préparer un bordereau' );
}
if ( ! $jauge['etudiants'] ) {
	$a_faire[] = array( 'libre', 'groupe', 'Aucun étudiant cette année', 'Ajoute tes étudiants : chacun est à reverser à la tutelle de sa filière, dans un bordereau.', $url( array( 'vue' => 'etudiants', 'ajout' => 1 ) ) . '#ajout', 'Ajouter un étudiant' );
}

ueb_adm_tete( array(
	'titre'      => 'Tableau de bord',
	'sous_titre' => $ipes->nom_fr,
	'actions'    => ueb_adm_action( $url( array( 'vue' => 'etudiants', 'ajout' => 1 ) ) . '#ajout', 'Ajouter un étudiant', 'plus', true ),
) );
ueb_afficher_flash();
ueb_ipes_hero( $ipes, array( 'pour' => 'ipes', 'lien' => array( $url( array( 'vue' => 'bordereaux' ) ), 'Mes bordereaux' ) ) );
?>

<div class="ipes-bord">
	<section class="adm-panneau" aria-labelledby="ipes-a-faire-titre">
		<header class="adm-panneau__tete">
			<div><h2 id="ipes-a-faire-titre">À faire</h2><p>Ce qui attend ton action, le plus urgent d’abord.</p></div>
		</header>
		<?php if ( ! $a_faire ) : ?>
			<p class="ipes-a-jour"><?php echo ueb_icone( 'check', 18 ); ?>Tout est à jour : rien n’attend ton action.</p>
		<?php else : ?>
			<ul class="ipes-a-faire">
				<?php foreach ( $a_faire as $f ) : ?>
					<li class="ipes-a-faire--<?php echo esc_attr( $f[0] ); ?>">
						<span class="ipes-a-faire__icone" aria-hidden="true"><?php echo ueb_icone( $f[1], 17 ); ?></span>
						<div class="ipes-a-faire__texte">
							<b><?php echo esc_html( $f[2] ); ?></b>
							<p><?php echo esc_html( $f[3] ); ?></p>
							<a class="adm-bouton adm-bouton--petit" href="<?php echo esc_url( $f[4] ); ?>"><?php echo esc_html( $f[5] ); ?></a>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</section>

	<section class="adm-panneau ipes-registre" aria-labelledby="ipes-recents">
		<header class="adm-panneau__tete">
			<div><h2 id="ipes-recents">Derniers bordereaux</h2><p>Chaque bordereau reverse des étudiants à une tutelle, qui le vérifie avec l’UEb.</p></div>
		</header>
		<?php if ( ! $bordereaux ) : ?>
			<div class="bo-vide ipes-vide"><span><?php echo ueb_icone( 'recu', 22 ); ?></span><p><?php echo $jauge['etudiants'] ? '<b>Aucun bordereau cette année.</b> Crée le premier depuis l’onglet Bordereaux.' : '<b>Aucun bordereau pour l’instant.</b> Commence par ajouter tes étudiants.'; ?></p></div>
		<?php else : ?>
			<?php ueb_ipes_bordereaux_liste( array_slice( $bordereaux, 0, 5 ), array( 'url' => $fiche ) ); ?>
			<?php if ( count( $bordereaux ) > 5 ) : ?>
				<footer class="ipes-panneau-pied"><a class="adm-bouton adm-bouton--petit" href="<?php echo esc_url( $url( array( 'vue' => 'bordereaux' ) ) ); ?>">Tous les bordereaux (<?php echo count( $bordereaux ); ?>)</a></footer>
			<?php endif; ?>
		<?php endif; ?>
	</section>
</div>
