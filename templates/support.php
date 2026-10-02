<?php
/**
 * Support : le visiteur décrit son problème, puis WhatsApp s'ouvre avec le
 * message prêt à envoyer au numéro de support (lien wa.me, sans API).
 * Aucun traitement serveur : le message est préparé par assets/js/support.js.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

$types = array(
	'Connexion ou mot de passe',
	'Création de compte',
	'Quitus de paiement',
	'Envoi du reçu bancaire',
	'Autre problème',
);
if ( ! function_exists( 'ueb_support_numero_suivant' ) ) {
	/** Renvoie le numéro suivant, à tour de rôle (compteur enregistré en base). */
	function ueb_support_numero_suivant() {
		$numeros = array_values( UEB_SUPPORT_NUMEROS );
		$i       = (int) get_option( 'ueb_support_compteur', 0 );
		update_option( 'ueb_support_compteur', $i + 1, false );
		return preg_replace( '/\D/', '', $numeros[ $i % count( $numeros ) ] );
	}
}
nocache_headers(); // pas de cache : le numéro doit changer à chaque visite
$numero = ueb_support_numero_suivant();

ueb_page_debut( array( 'titre' => 'Support', 'variante' => 'simple', 'classe' => 'page-support' ) );
?>
<main id="contenu" class="page-app support">
	<div class="conteneur">
		<header class="support__entete">
			<p class="support__sur"><?php echo ueb_icone( 'aide', 18 ); ?>Support</p>
			<h1 class="support__titre">Un problème avec la plateforme ?</h1>
			<p class="support__intro">Décris-le ci-dessous. WhatsApp s’ouvre avec ton message déjà écrit : il te reste à l’envoyer, et l’équipe te répond directement dans la conversation.</p>
		</header>

		<div class="support__grille">
			<section class="support__formulaire carte" aria-labelledby="support-form-titre">
				<h2 class="support__sous-titre" id="support-form-titre">Décris ton problème</h2>

				<form class="formulaire" data-support data-whatsapp="<?php echo esc_attr( $numero ); ?>" novalidate>
					<?php
					ueb_champ( array(
						'nom'     => 'nom',
						'libelle' => 'Ton nom',
						'requis'  => false,
						'icone'   => 'utilisateur',
						'attrs'   => array( 'autocomplete' => 'name', 'placeholder' => 'Prénom et nom', 'maxlength' => 80 ),
					) );
					ueb_champ( array(
						'nom'     => 'probleme',
						'libelle' => 'Type de problème',
						'type'    => 'select',
						'icone'   => 'info',
						'options' => array_combine( $types, $types ),
					) );
					?>
					<div class="champ">
						<label for="champ-description">Ton message</label>
						<textarea id="champ-description" name="description" rows="6" maxlength="1000" required placeholder="Explique ce qui se passe : ce que tu as essayé, le message d’erreur affiché…"></textarea>
					</div>

					<button class="btn btn--primaire btn--large" type="submit"><?php echo ueb_icone( 'discussion', 20 ); ?>Envoyer sur WhatsApp</button>
				</form>

				<div class="alerte alerte--succes support__ok" data-support-ok hidden role="status">
					<?php echo ueb_icone( 'check', 20 ); ?>
					<p>WhatsApp s’est ouvert avec ton message. Appuie sur <b>Envoyer</b> pour qu’il nous parvienne. Rien ne s’ouvre ? <a data-support-lien href="#" target="_blank" rel="noopener">Ouvre WhatsApp ici</a>.</p>
				</div>
				<noscript><p class="champ__aide">Active JavaScript pour préparer ton message, ou écris-nous directement sur <a href="https://wa.me/<?php echo esc_attr( $numero ); ?>">WhatsApp</a>.</p></noscript>
			</section>

			<aside class="support__infos" aria-label="Informations utiles">
				<section class="support__carte carte">
					<h2 class="support__sous-titre">Comment ça marche</h2>
					<ol class="support__etapes">
						<li><span>1</span>Tu décris ton problème.</li>
						<li><span>2</span>WhatsApp s’ouvre, message prêt.</li>
						<li><span>3</span>Tu l’envoies : on te répond dans la conversation.</li>
					</ol>
				</section>

				<section class="support__carte support__carte--alerte carte">
					<?php echo ueb_icone( 'cadenas', 20 ); ?>
					<p><b>Ne nous envoie jamais ton mot de passe.</b> L’équipe ne te le demandera jamais.</p>
				</section>

				<section class="support__carte carte">
					<h2 class="support__sous-titre">Questions fréquentes</h2>
					<div class="accordeon">
						<details>
							<summary>Comment générer mon quitus ?<?php echo ueb_icone( 'chevron', 20 ); ?></summary>
							<p>Connecte-toi avec ton matricule, puis ouvre « Nouveau quitus ». Choisis ton établissement, vérifie tes informations et indique le montant : ton quitus sort en PDF, avec les quatre coupons (étudiant, DAF, scolarité et banque) sur une page A4.</p>
						</details>
						<details>
							<summary>Que faire après avoir payé ?<?php echo ueb_icone( 'chevron', 20 ); ?></summary>
							<p>Photographie ton reçu de paiement et envoie-le depuis ton espace. Présente ensuite l’original à la scolarité de ton établissement pour le faire tamponner : ton paiement passe à « Vérifié ».</p>
						</details>
						<details>
							<summary>Mot de passe oublié<?php echo ueb_icone( 'chevron', 20 ); ?></summary>
							<p>Présente-toi à la scolarité de ton établissement avec ta carte d’identité : un agent réinitialise ton compte, sans voir aucun mot de passe. Dans l’heure qui suit, ouvre « Mot de passe oublié ? » sur la page de connexion, saisis ton matricule et choisis ton nouveau mot de passe.</p>
						</details>
					</div>
				</section>
			</aside>
		</div>
	</div>
</main>
<?php
ueb_page_fin( 'simple' );
