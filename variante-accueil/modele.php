<?php
/**
 * Template Name: Accueil — Variante Ebolowa
 * Template Post Type: page
 *
 * Modèle parallèle. Ne devient jamais l’accueil automatiquement.
 * Les données, destinations et la composition Remotion sont celles du thème.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'wp_robots', function ( $robots ) {
	$robots['noindex'] = true;
	$robots['nofollow'] = true;
	return $robots;
} );

add_action( 'wp_enqueue_scripts', function () {
	wp_dequeue_style( 'ueb-landing' );
	wp_dequeue_script( 'ueb-landing' );
	wp_dequeue_script( 'gsap-scrolltrigger' );
	wp_dequeue_script( 'gsap' );
	ueb_style( 'ueb-variante', 'variante-accueil/style.css', array( 'ueb-app' ) );
	ueb_script( 'ueb-variante', 'variante-accueil/animation.js' );
	ueb_script( 'ueb-remotion', 'assets/js/remotion-ueb.js' );
}, 110 );

$d = ueb_landing_donnees();
$villes = array_values( array_unique( array_column( $d['fiches'], 'ville' ) ) );
$debut_url = ueb_url( $d['compte'] ? 'mon-espace' : 'creer-mon-compte' );
$debut_texte = $d['compte'] ? 'Aller à mon espace' : 'Créer mon compte';
$etapes_courtes = array( 'Prépare ton quitus.', 'Imprime tes coupons.', 'Fais viser, puis paie.', 'Envoie ton reçu.' );
$etapes_lieux = array( 'Sur la plateforme', 'En PDF · Format A4', 'Scolarité → CCA Bank', 'Dans ton espace' );

ueb_page_debut( array( 'titre' => 'Ton inscription à Ebolowa · ' . $d['annee']['libelle'], 'variante' => 'auth', 'classe' => 'ueb-v2' ) );
?>
<header class="v2-nav">
	<div class="v2-wrap v2-nav__inner">
		<a class="v2-brand" href="#contenu" aria-label="Université d’Ebolowa — Accueil">
			<img src="<?php echo esc_url( ueb_logo_url( 'UEB' ) ); ?>" alt="" width="48" height="48">
			<span><b>Université d’Ebolowa</b><small>The University of Ebolowa</small></span>
		</a>
		<nav class="v2-links" id="v2-navigation" aria-label="Navigation principale">
			<a href="#parcours">Les étapes</a>
			<a href="#etablissements">Établissements</a>
			<a href="#questions">Besoin d’aide ?</a>
			<a class="v2-links__account" href="<?php echo esc_url( ueb_url( $d['compte'] ? 'mon-espace' : 'connexion' ) ); ?>"><?php echo $d['compte'] ? 'Mon espace' : 'Se connecter'; ?><?php echo ueb_icone( 'fleche', 17 ); ?></a>
		</nav>
		<button class="v2-menu" type="button" aria-controls="v2-navigation" aria-expanded="false" hidden><?php echo ueb_icone( 'menu', 22 ); ?><span class="sr">Ouvrir le menu</span></button>
	</div>
</header>

<main id="contenu">
	<section class="v2-hero" aria-labelledby="v2-title">
		<img class="v2-hero__backdrop" src="<?php echo esc_url( ueb_photo_url( 'campus-ebolowa' ) ); ?>" alt="" width="1000" height="500" fetchpriority="low" aria-hidden="true">
		<div class="v2-wrap v2-hero__grid">
			<div class="v2-hero__copy">
				<p class="v2-eyebrow v2-eyebrow--light" data-enter><span class="v2-dot" aria-hidden="true"></span>Inscriptions <?php echo esc_html( $d['annee']['libelle'] ); ?></p>
				<h1 id="v2-title" data-enter>Ton avenir<br>commence<br>à <em>Ebolowa.</em></h1>
				<p class="v2-hero__lead" data-enter>Ton inscription, étape par étape.</p>
				<p class="v2-hero__description" data-enter>Génère ton quitus, prépare ton paiement et suis la vérification de ton reçu sur la plateforme officielle de l’Université d’Ebolowa.</p>
				<div class="v2-actions" data-enter>
					<a class="v2-button v2-button--gold" href="<?php echo esc_url( $debut_url ); ?>"><?php echo esc_html( $debut_texte ); ?><?php echo ueb_icone( 'fleche', 20 ); ?></a>
					<a class="v2-hero__guide" href="#parcours">Découvrir les étapes<?php echo ueb_icone( 'chevron', 18 ); ?></a>
				</div>
				<p class="v2-hero__hint" data-enter><?php echo ueb_icone( 'bouclier', 16 ); ?>Ton matricule ou ton numéro de dossier suffit pour commencer.</p>
			</div>
			<div class="v2-demo" data-enter>
				<div class="v2-demo__note"><span class="v2-demo__dash" aria-hidden="true"></span>Du premier clic au reçu vérifié.</div>
				<figure class="v2-ticket">
					<figcaption class="v2-ticket__header"><span>LE PARCOURS D’INSCRIPTION</span><span><?php echo ueb_icone( 'horloge', 15 ); ?>13 secondes</span></figcaption>
					<div class="animation v2-player" data-remotion="parcours" data-props="<?php echo esc_attr( wp_json_encode( ueb_props_parcours() ) ); ?>" role="group" aria-label="Le parcours d’inscription en animation" aria-describedby="v2-video-description">
						<div class="animation__scene" data-remotion-scene></div>
						<button type="button" class="animation__pause" data-remotion-pause aria-label="Mettre l’animation en pause" aria-pressed="false"><?php echo ueb_icone( 'pause', 16, 'icone-pause' ); ?><?php echo ueb_icone( 'lecture', 16, 'icone-lecture' ); ?></button>
					</div>
					<div class="v2-ticket__footer"><span><?php echo ueb_icone( 'fichier', 20 ); ?><b>Un quitus. Quatre coupons.</b></span><span>Étudiant · DAF · Scolarité · Banque</span></div>
				</figure>
				<p id="v2-video-description" class="sr">Crée ton compte et ton quitus, télécharge les quatre coupons, fais viser le quitus à la scolarité, paie à la CCA Bank, puis envoie ton reçu pour vérification.</p>
				<noscript><p class="v2-demo__fallback">Le parcours animé nécessite JavaScript. Les quatre étapes sont détaillées juste en dessous.</p></noscript>
				<div class="v2-demo__caption"><?php echo ueb_icone( 'check', 16 ); ?>Les mêmes démarches, quel que soit ton établissement.</div>
			</div>
		</div>
		<div class="v2-wrap v2-hero__bottom"><span>UNE UNIVERSITÉ, <?php echo count( $d['etabs'] ); ?> ÉTABLISSEMENTS</span><span><?php echo esc_html( implode( ' · ', $villes ) ); ?></span><a href="#parcours" aria-label="Voir les étapes d’inscription"><?php echo ueb_icone( 'chevron', 20 ); ?></a></div>
	</section>

	<section class="v2-quick" aria-label="Accès rapides">
		<div class="v2-wrap v2-quick__grid">
			<p>Déjà en cours<br><b>d’inscription ?</b></p>
			<a href="<?php echo esc_url( ueb_url( $d['compte'] ? 'mon-espace/quitus' : 'connexion' ) ); ?>"><?php echo ueb_icone( 'fichier', 23 ); ?><span><b>Générer mon quitus</b><small>Télécharger mes coupons</small></span><?php echo ueb_icone( 'fleche', 18 ); ?></a>
			<a href="<?php echo esc_url( ueb_url( $d['compte'] ? 'mon-espace' : 'connexion' ) ); ?>"><?php echo ueb_icone( 'recu', 23 ); ?><span><b>Envoyer mon reçu</b><small>Suivre sa vérification</small></span><?php echo ueb_icone( 'fleche', 18 ); ?></a>
			<a href="#etablissements"><?php echo ueb_icone( 'banque', 23 ); ?><span><b>Trouver mon établissement</b><small>RIB et contacts utiles</small></span><?php echo ueb_icone( 'fleche', 18 ); ?></a>
		</div>
	</section>

	<section class="v2-section v2-process" id="parcours" aria-labelledby="v2-process-title">
		<div class="v2-wrap">
			<div class="v2-section__heading" data-reveal>
				<div><p class="v2-eyebrow">Le chemin à suivre</p><h2 id="v2-process-title">Quatre étapes.<br>Et te voilà <em>prêt.</em></h2></div>
				<p>De ton compte à la vérification du paiement, suis ces étapes dans l’ordre. On t’explique tout.</p>
			</div>
			<ol class="v2-steps">
				<?php foreach ( $d['etapes'] as $i => $etape ) : ?>
					<li data-reveal>
						<div class="v2-step__top"><span class="v2-step__number" aria-hidden="true"><?php echo esc_html( sprintf( '%02d', $i + 1 ) ); ?></span><span><?php echo esc_html( $etapes_lieux[ $i ] ); ?></span></div>
						<h3><?php echo esc_html( $etapes_courtes[ $i ] ); ?></h3>
						<p><?php echo esc_html( $etape['texte'] ); ?></p>
					</li>
				<?php endforeach; ?>
			</ol>
			<p class="v2-process__reminder" data-reveal><?php echo ueb_icone( 'info', 19 ); ?><span>Garde les originaux de tes reçus : ils seront demandés à la scolarité pour vérifier ton paiement.</span></p>
		</div>
	</section>

	<section class="v2-section v2-directory" id="etablissements" aria-labelledby="v2-directory-title">
		<div class="v2-wrap">
			<div class="v2-section__heading" data-reveal>
				<div><p class="v2-eyebrow">Les établissements de l’UEb</p><h2 id="v2-directory-title">Ton établissement.<br>Tous tes <em>repères.</em></h2></div>
				<p>Retrouve ses coordonnées et son compte CCA Bank. Vérifie toujours le RIB avant de payer.</p>
			</div>
			<div class="v2-directory__tools" data-directory-tools hidden>
				<div class="v2-search"><label for="v2-search">Rechercher un établissement</label><div><?php echo ueb_icone( 'loupe', 20 ); ?><input id="v2-search" type="search" placeholder="Nom, sigle ou ville…" autocomplete="off" aria-controls="v2-establishments"></div></div>
				<div class="v2-filters" role="group" aria-label="Filtrer par ville"><button type="button" data-city="" aria-pressed="true">Tous</button><?php foreach ( $villes as $ville ) : ?><button type="button" data-city="<?php echo esc_attr( $ville ); ?>" aria-pressed="false"><?php echo esc_html( $ville ); ?></button><?php endforeach; ?></div>
			</div>
			<p class="v2-directory__count" role="status" aria-live="polite" aria-atomic="true" data-results><?php echo count( $d['fiches'] ); ?> établissements pour construire ton avenir.</p>
			<ul class="v2-establishments" id="v2-establishments">
				<?php foreach ( $d['fiches'] as $sigle => $f ) : ?>
					<li class="v2-school" data-school data-search="<?php echo esc_attr( $sigle . ' ' . $f['fr'] . ' ' . $f['en'] . ' ' . $f['ville'] ); ?>" data-ville="<?php echo esc_attr( $f['ville'] ); ?>">
						<div class="v2-school__top"><img src="<?php echo esc_url( $f['logo'] ); ?>" alt="" width="56" height="56" loading="lazy"><span><?php echo ueb_icone( 'lieu', 14 ); ?><?php echo esc_html( $f['ville'] ); ?></span></div>
						<h3><?php echo esc_html( $sigle ); ?></h3><p class="v2-school__name"><?php echo esc_html( $f['fr'] ); ?></p>
						<details class="v2-school__details">
							<summary><span>RIB et contacts<span class="sr"> — <?php echo esc_html( $sigle ); ?></span></span><?php echo ueb_icone( 'plus', 19 ); ?></summary>
							<div class="v2-school__information">
								<span class="v2-label">Compte CCA Bank</span><p class="v2-rib" id="rib-<?php echo esc_attr( $sigle ); ?>"><?php echo esc_html( $f['rib'] ); ?></p>
								<button class="v2-copy" type="button" data-copy="<?php echo esc_attr( $f['rib'] ); ?>" data-rib-id="rib-<?php echo esc_attr( $sigle ); ?>" aria-label="Copier le RIB de <?php echo esc_attr( $sigle ); ?>" hidden>Copier le RIB<?php echo ueb_icone( 'fichier', 15 ); ?></button>
								<dl><div><dt>Adresse</dt><dd><?php echo esc_html( $f['bp'] ); ?></dd></div><div><dt>Téléphone</dt><dd><?php echo esc_html( $f['tel'] ?: 'Non communiqué' ); ?></dd></div><div><dt>Email</dt><dd><?php if ( $f['email'] ) : ?><a href="mailto:<?php echo esc_attr( $f['email'] ); ?>"><?php echo esc_html( $f['email'] ); ?></a><?php else : ?>Non communiqué<?php endif; ?></dd></div></dl>
							</div>
						</details>
					</li>
				<?php endforeach; ?>
			</ul>
			<div class="v2-empty" data-empty hidden><p>Aucun établissement ne correspond à ta recherche.</p><button class="v2-button v2-button--green" type="button" data-reset>Réinitialiser la recherche</button></div>
			<p class="sr" role="status" aria-live="polite" data-copy-status></p>
		</div>
	</section>

	<section class="v2-section v2-campus" id="campus" aria-labelledby="v2-campus-title">
		<div class="v2-wrap v2-campus__grid">
			<div class="v2-campus__photos" data-reveal>
				<figure class="v2-campus__main"><img src="<?php echo esc_url( ueb_photo_url( 'campus-ebolowa' ) ); ?>" alt="Le campus d’Ebolowa, au cœur d’un paysage verdoyant" width="1000" height="500" loading="lazy"><figcaption><?php echo ueb_icone( 'lieu', 16 ); ?>Campus d’Ebolowa</figcaption></figure>
				<figure class="v2-campus__inset"><img src="<?php echo esc_url( ueb_photo_url( 'amphi-cours' ) ); ?>" alt="Étudiants en cours dans un amphithéâtre" width="600" height="400" loading="lazy"><figcaption>Apprendre. Se rencontrer. Avancer.</figcaption></figure>
			</div>
			<div class="v2-campus__copy" data-reveal><p class="v2-eyebrow">Bienvenue chez toi</p><h2 id="v2-campus-title">Plus qu’une inscription.<br>Le début d’un <em>parcours.</em></h2><p>À <?php echo esc_html( $d['villes'] ); ?>, les établissements de l’Université d’Ebolowa t’accueillent. Ton inscription commence ici, sur une seule et même plateforme.</p><a class="v2-text-link" href="<?php echo esc_url( $debut_url ); ?>"><?php echo esc_html( $debut_texte ); ?><?php echo ueb_icone( 'fleche', 19 ); ?></a></div>
		</div>
	</section>

	<section class="v2-section v2-faq" id="questions" aria-labelledby="v2-faq-title">
		<div class="v2-wrap v2-faq__grid">
			<div data-reveal><p class="v2-eyebrow">On t’accompagne</p><h2 id="v2-faq-title">Une question<br>avant de <em>commencer ?</em></h2><p>Voici les réponses aux questions les plus fréquentes.</p><a class="v2-text-link" href="#etablissements">Contacter ma scolarité<?php echo ueb_icone( 'fleche', 19 ); ?></a></div>
			<div class="v2-faq__list" data-reveal><?php foreach ( $d['faq'] as $question ) : ?><details><summary><span><?php echo esc_html( $question[0] ); ?></span><?php echo ueb_icone( 'plus', 20 ); ?></summary><p><?php echo esc_html( $question[1] ); ?></p></details><?php endforeach; ?></div>
		</div>
	</section>

	<section class="v2-last" aria-labelledby="v2-last-title"><div class="v2-wrap v2-last__inner" data-reveal><div><p class="v2-eyebrow v2-eyebrow--light">Inscriptions <?php echo esc_html( $d['annee']['libelle'] ); ?></p><h2 id="v2-last-title">La suite commence<br>avec <em>toi.</em></h2></div><div><p>Ton matricule ou ton numéro de dossier.<br>Et un premier pas vers ton année universitaire.</p><a class="v2-button v2-button--gold" href="<?php echo esc_url( $debut_url ); ?>"><?php echo esc_html( $debut_texte ); ?><?php echo ueb_icone( 'fleche', 20 ); ?></a><?php if ( ! $d['compte'] ) : ?><a class="v2-last__login" href="<?php echo esc_url( ueb_url( 'connexion' ) ); ?>">J’ai déjà un compte →</a><?php endif; ?></div></div></section>
</main>

<footer class="v2-footer"><div class="v2-wrap"><div class="v2-footer__main"><div><a class="v2-brand" href="#contenu"><img src="<?php echo esc_url( ueb_logo_url( 'UEB' ) ); ?>" alt="" width="48" height="48" loading="lazy"><span><b>Université d’Ebolowa</b><small>The University of Ebolowa</small></span></a><p>Plateforme officielle d’inscription.<br><?php echo esc_html( UEB_UNIVERSITE['bp'] ); ?>, Cameroun.</p></div><nav aria-label="Liens de pied de page"><b>Ton inscription</b><a href="#parcours">Les étapes</a><a href="#etablissements">Les établissements</a><a href="#questions">Questions fréquentes</a></nav><div><b>Restons en contact</b><a href="mailto:<?php echo esc_attr( UEB_UNIVERSITE['email'] ); ?>"><?php echo esc_html( UEB_UNIVERSITE['email'] ); ?></a><a href="tel:<?php echo esc_attr( preg_replace( '/\s+/', '', UEB_UNIVERSITE['tel'] ) ); ?>"><?php echo esc_html( UEB_UNIVERSITE['tel'] ); ?></a><a href="https://unv-ebolowa.cm" target="_blank" rel="noopener noreferrer">Site de l’université ↗<span class="sr"> (nouvel onglet)</span></a></div></div><div class="v2-footer__bottom"><span>© <?php echo esc_html( $d['annee']['debut'] ); ?> Université d’Ebolowa</span><span>Inscriptions <?php echo esc_html( $d['annee']['libelle'] ); ?></span><a href="<?php echo esc_url( home_url( '/' ) ); ?>">Voir l’accueil actuel<?php echo ueb_icone( 'fleche', 15 ); ?></a></div></div></footer>
<?php ueb_page_fin( 'auth' ); ?>
