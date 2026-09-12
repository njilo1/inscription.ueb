<?php
/**
 * Page d'accueil : données de la landing et boutons d'action.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

/** Tout ce dont la page d'accueil a besoin. */
function ueb_landing_donnees() {
	$etabs  = ueb_etablissements();
	$fiches = array();
	foreach ( $etabs as $sigle => $e ) {
		$fiches[ $sigle ] = array(
			'sigle' => $sigle, 'fr' => $e['fr'], 'en' => $e['en'], 'couleur' => $e['couleur'], 'ville' => $e['ville'],
			'logo' => ueb_logo_url( $sigle ), 'rib' => ueb_rib( $e ), 'tel' => $e['tel'], 'email' => $e['email'], 'bp' => $e['bp'],
		);
	}
	$villes = array_values( array_unique( array_column( $etabs, 'ville' ) ) );

	return array(
		'annee'  => ueb_annee_academique(),
		'compte' => ueb_compte_courant(),
		'etabs'  => $etabs,
		'fiches' => $fiches,
		'villes' => implode( ', ', array_slice( $villes, 0, -1 ) ) . ' et ' . end( $villes ),
		'etapes' => array(
			array( 'titre' => 'Crée ton compte et remplis ton quitus', 'texte' => 'Connecte-toi avec ton matricule, ou avec le numéro de dossier reçu à la préinscription. Choisis ton établissement, vérifie tes informations et indique le montant.' ),
			array( 'titre' => 'Télécharge tes quatre coupons', 'texte' => 'Ton quitus sort en PDF, aux couleurs de ton établissement : une page A4 avec les coupons étudiant, DAF, scolarité et banque.' ),
			array( 'titre' => 'Fais-le tamponner, puis paie', 'texte' => 'La scolarité de ton établissement appose son visa. Tu règles ensuite le montant à la CCA Bank, sur le compte imprimé en bas de chaque coupon.' ),
			array( 'titre' => 'Envoie la photo de ton reçu', 'texte' => 'Depuis ton espace, photographie le reçu de la banque. Présente ensuite les originaux à la scolarité : ton paiement passe à « Vérifié ».' ),
		),
		'faq'    => array(
			array( 'J’ai oublié mon mot de passe. Que faire ?', 'Présente-toi à la scolarité de ton établissement avec ta carte d’identité et le téléphone enregistré sur ton compte. Un agent réinitialise ton mot de passe ; tu en choisis un nouveau à ta connexion suivante.' ),
			array( 'Je me suis inscrit avec mon numéro de dossier. Et quand j’aurai mon matricule ?', 'Dans ton espace, rubrique « Sécurité », enregistre ton matricule. Tu te connecteras ensuite avec lui ; tes quitus restent dans ton compte.' ),
			array( 'Pourquoi quatre coupons sur la même page ?', 'Chaque service garde le sien : l’étudiant, la Direction des affaires financières (DAF), la scolarité et la banque. Imprime la page entière et découpe-la seulement quand on te le demande.' ),
			array( 'Quels fichiers puis-je envoyer pour mon reçu ?', 'Une photo JPG ou PNG, ou un scan PDF, de 5 Mo au plus, jusqu’à trois fichiers par quitus. Le cachet de la banque et le montant doivent être lisibles.' ),
			array( 'Je pense que quelqu’un connaît mon mot de passe.', 'Connecte-toi et change-le tout de suite dans « Sécurité ». Toutes les autres sessions ouvertes sur ton compte sont fermées immédiatement.' ),
		),
	);
}

function ueb_photo_url( $nom ) {
	return UEB_INSC_URI . '/assets/images/photos/' . $nom . '.webp';
}

/** Boutons d'action principaux, adaptés à l'état de connexion. */
function ueb_landing_actions( $compte, $classe_secondaire = 'btn--verre', $classe_primaire = 'btn--or' ) {
	if ( $compte ) {
		printf( '<a class="btn %s" href="%s">Aller à mon espace%s</a>', esc_attr( $classe_primaire ), esc_url( ueb_url( 'mon-espace' ) ), ueb_icone( 'fleche', 18 ) );
		return;
	}
	printf( '<a class="btn %s" href="%s">Créer mon compte%s</a>', esc_attr( $classe_primaire ), esc_url( ueb_url( 'creer-mon-compte' ) ), ueb_icone( 'fleche', 18 ) );
	printf( '<a class="btn %s" href="%s">J’ai déjà un compte</a>', esc_attr( $classe_secondaire ), esc_url( ueb_url( 'connexion' ) ) );
}
