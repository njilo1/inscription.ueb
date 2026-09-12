<?php
/**
 * Vérification publique d'un quitus (adresse encodée dans le QR code).
 * N'affiche que le nécessaire pour authentifier le document.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

$q    = ueb_quitus_par_code( preg_replace( '/[^a-f0-9]/', '', strtolower( (string) get_query_var( 'ueb_arg' ) ) ) );
$etab = $q ? ueb_etablissement( $q->etablissement ) : null;

/* Prénoms réduits à leurs initiales : « NGONO ESSOMBA C. L. » */
$initiales = static function ( $prenoms ) {
	return implode( ' ', array_map( static fn( $p ) => mb_strtoupper( mb_substr( $p, 0, 1 ) ) . '.', preg_split( '/[\s-]+/u', trim( $prenoms ) ) ) );
};

ueb_page_debut( array( 'titre' => 'Vérification de quitus', 'variante' => 'simple' ) );
?>
<main id="contenu" class="page-app">
	<div class="conteneur conteneur--etroit">
		<?php if ( $q ) : ?>
			<section class="verif carte" style="--etab: <?php echo esc_attr( $etab['couleur'] ); ?>">
				<div class="verif__sceau"><?php echo ueb_icone( 'bouclier', 34 ); ?></div>
				<h1 class="verif__titre">Quitus authentique</h1>
				<p class="verif__texte">Ce quitus a bien été généré par la plateforme d'inscription de l'<?php echo esc_html( UEB_UNIVERSITE['fr'] ); ?>.</p>
				<dl class="fiche fiche--verif">
					<div><dt>Numéro</dt><dd><?php echo esc_html( $q->numero ); ?></dd></div>
					<div><dt>Établissement</dt><dd><?php echo esc_html( $etab['fr'] ); ?></dd></div>
					<div><dt>Étudiant</dt><dd><?php echo esc_html( $q->nom . ' ' . $initiales( $q->prenom ) ); ?></dd></div>
					<div><dt><?php echo 'matricule' === $q->type_identifiant ? 'Matricule' : 'N° de dossier'; ?></dt><dd><?php echo esc_html( $q->identifiant ); ?></dd></div>
					<div><dt>Année académique</dt><dd><?php echo esc_html( str_replace( '-', ' – ', $q->annee_academique ) ); ?></dd></div>
					<div><dt>Montant</dt><dd><?php echo esc_html( ueb_formater_montant( $q->montant ) ); ?> FCFA · <?php echo esc_html( ueb_detail_quitus( $q ) ); ?></dd></div>
					<div><dt>Statut du paiement</dt><dd><?php echo ueb_badge_statut( $q->statut ); // phpcs:ignore ?></dd></div>
				</dl>
				<p class="verif__note">Comparez ces informations avec celles imprimées sur le coupon. Toute différence signale un document modifié.</p>
			</section>
		<?php else : ?>
			<section class="verif verif--echec carte">
				<div class="verif__sceau"><?php echo ueb_icone( 'alerte', 34 ); ?></div>
				<h1 class="verif__titre">Quitus introuvable</h1>
				<p class="verif__texte">Aucun quitus ne correspond à ce QR code. Le document n'a pas été généré par la plateforme ou il a été modifié : ne l'acceptez pas et signalez-le à la scolarité.</p>
			</section>
		<?php endif; ?>
	</div>
</main>
<?php
ueb_page_fin( 'simple' );
