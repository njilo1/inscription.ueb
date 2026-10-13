<?php
/**
 * Espace scolarité, vue « IPES » : les IPES sous la tutelle des
 * établissements consultés (inc/ipes-tutelle.php), en lecture seule.
 *   - sans « ipes »       : la file des bordereaux à vérifier, puis le
 *                           registre, filtrable en direct ;
 *   - ?ipes={id}          : la fiche (reversements, bordereaux, étudiants,
 *                           coordonnées) ;
 *   - &etudiant={id}      : un étudiant et son reversement.
 * Tout est limité aux tutelles que le compte regarde (ueb_ipes_tutelles_vues) :
 * la FSJP ne voit ni les étudiants, ni les montants, ni les bordereaux de la FS.
 * La décision sur un bordereau (« ueb_verifier_ipes ») s'ajoute dans le bloc
 * Bordereaux. Attend $ici et $annee (page-scolarite.php), qui n'affiche pas
 * son en-tête générique sur la fiche : elle a le sien.
 */
defined( 'ABSPATH' ) || exit;

$ipes_demande = (int) ( $_GET['ipes'] ?? 0 );
/* Adresses brutes (les composants échappent eux-mêmes). */
$adresse = static fn( array $args = array() ) => add_query_arg( array_merge( array( 'vue' => 'ipes' ), $args ), ueb_url_scolarite() );
?>
<div class="ipes-zone">

<?php if ( ! $ipes_demande ) : ?>

	<?php
	$recherche = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );
	$liste     = ueb_ipes_sous_tutelle( array( 'recherche' => $recherche ) );
	$tous      = '' !== $recherche ? ueb_ipes_sous_tutelle() : $liste;
	$visibles  = array_map( static fn( $i ) => (int) $i->id, $tous );
	/* File : les bordereaux envoyés à une tutelle de la portée, que ce compte peut décider. */
	$envoyes = array_values( array_filter(
		ueb_ipes_bordereaux_envoyes( ueb_etabs_autorises() ),
		static fn( $b ) => in_array( (int) $b->ipes_id, $visibles, true ) && ueb_peut_verifier_bordereau( (object) array_merge( (array) $b, array( 'statut' => 'envoye' ) ) )
	) );
	ueb_ipes_bandeau_a_verifier( $envoyes, static fn( $b ) => $adresse( array( 'ipes' => (int) $b->ipes_id ) ) . '#bordereaux' );
	?>

	<section class="adm-panneau ipes-registre" aria-labelledby="sco-ipes-titre">
		<header class="adm-panneau__tete">
			<div>
				<h2 id="sco-ipes-titre">Registre des IPES</h2>
				<p><?php echo esc_html( $tous ? ueb_ipes_pluriel( count( $tous ), 'IPES', 'IPES' ) . ' sous la tutelle de ton établissement.' : 'Aucun IPES sous la tutelle de ton établissement.' ); ?></p>
			</div>
			<?php if ( count( $tous ) > 1 || '' !== $recherche ) : ?>
				<form class="ipes-outils" method="get" action="<?php echo esc_url( ueb_url_scolarite() ); ?>" role="search" aria-label="Filtrer les IPES" data-filtres-direct="scolarite-ipes-resultats"><?php ueb_champ_espace(); ?>
					<input type="hidden" name="vue" value="ipes">
					<label class="ipes-recherche"><span class="sr">Rechercher un IPES</span><?php echo ueb_icone( 'loupe', 17 ); ?><input type="search" name="q" value="<?php echo esc_attr( $recherche ); ?>" placeholder="Sigle ou nom" enterkeyhint="search" autocomplete="off"></label>
					<button class="adm-bouton" type="submit" data-filtres-bouton><?php echo ueb_icone( 'loupe', 16 ); ?>Rechercher</button>
				</form>
			<?php endif; ?>
		</header>
		<div id="scolarite-ipes-resultats" class="ipes-resultats" aria-live="polite">
			<?php if ( '' !== $recherche ) : ?>
				<p class="ipes-compte"><b><?php echo esc_html( ueb_ipes_pluriel( count( $liste ), 'IPES', 'IPES' ) ); ?></b> pour « <?php echo esc_html( $recherche ); ?> ».</p>
			<?php endif; ?>
			<?php if ( ! $liste ) : ?>
				<div class="bo-vide ipes-vide"><span><?php echo ueb_icone( '' !== $recherche ? 'loupe' : 'ecole', 22 ); ?></span><p><?php echo '' !== $recherche ? '<b>Aucun IPES ne correspond à cette recherche.</b> Cherche par sigle ou par nom.' : '<b>Aucun IPES n’est placé sous la tutelle de ton établissement.</b> L’administration de l’UEb les enregistre avec leur convention.'; ?></p></div>
			<?php else : ?>
				<?php
				ueb_ipes_registre( $liste, array(
					'url'        => static fn( $ipes ) => $adresse( array( 'ipes' => (int) $ipes->id ) ),
					'a_verifier' => static fn( $ipes ) => count( array_filter( ueb_ipes_bordereaux_pour_tutelle( $ipes->id ), static fn( $b ) => 'envoye' === $b->statut ) ),
					'tutelles'   => 'ueb_ipes_tutelles_vues',
				) );
				?>
			<?php endif; ?>
		</div>
	</section>

<?php else : ?>

	<?php $ipes = ueb_ipes_sous_tutelle_par_id( $ipes_demande ); ?>

	<?php if ( ! $ipes ) : ?>

		<a class="fil" href="<?php echo esc_url( $adresse() ); ?>"><?php echo ueb_icone( 'fleche-g', 18 ); ?>Tous les IPES</a>
		<?php ueb_afficher_flash(); ?>
		<div class="bo-vide bo-vide--large"><span><?php echo ueb_icone( 'ecole', 24 ); ?></span><p><b>Cet IPES n’est pas sous la tutelle de ton établissement.</b> Retrouve les tiens dans le registre.</p></div>

	<?php elseif ( isset( $_GET['etudiant'] ) ) : ?>

		<?php
		$etudiant  = ueb_ipes_etudiant( $ipes->id, (int) $_GET['etudiant'] );
		/* Un étudiant d'une filière d'une autre tutelle n'existe pas pour cette scolarité. */
		if ( $etudiant && ! in_array( $etudiant->tutelle, ueb_ipes_tutelles_vues( $ipes ), true ) ) {
			$etudiant = null;
		}
		$bordereau = $etudiant && $etudiant->bordereau_id ? ueb_ipes_bordereau( $ipes->id, $etudiant->bordereau_id ) : null;
		?>
		<a class="fil" href="<?php echo esc_url( $adresse( array( 'ipes' => (int) $ipes->id ) ) . '#etudiants' ); ?>"><?php echo ueb_icone( 'fleche-g', 18 ); ?><?php echo esc_html( 'Étudiants de ' . $ipes->sigle ); ?></a>
		<?php if ( ! $etudiant ) : ?>
			<div class="bo-vide bo-vide--large"><span><?php echo ueb_icone( 'utilisateur', 24 ); ?></span><p><b>Cet étudiant n’existe pas dans cet IPES.</b></p></div>
		<?php else : ?>
			<header class="bo-entete ipes-sco-entete">
				<div class="bo-entete__texte">
					<div class="adm-tete__identite">
						<span class="bo-avatar ipes-avatar" aria-hidden="true"><?php echo esc_html( ueb_initiales( $etudiant->prenom, $etudiant->nom ) ); ?></span>
						<div>
							<h1><?php echo esc_html( trim( $etudiant->nom . ' ' . $etudiant->prenom ) ); ?></h1>
							<ul class="ipes-reperes">
								<li><?php echo ueb_icone( 'ecole', 14 ); ?><?php echo esc_html( $ipes->sigle ); ?></li>
								<li><?php echo ueb_icone( 'qr', 14 ); ?><?php echo esc_html( $etudiant->matricule ); ?></li>
								<li><?php echo ueb_icone( 'fichier', 14 ); ?><?php echo esc_html( ueb_ipes_filiere( $etudiant->filiere_id )->libelle ?? 'Filière inconnue' ); ?></li>
								<?php if ( $etudiant->tutelle ) : ?><li><?php echo ueb_icone( 'bouclier', 14 ); ?><?php echo esc_html( 'Tutelle : ' . $etudiant->tutelle ); ?></li><?php endif; ?>
								<li><?php echo ueb_icone( 'calendrier', 14 ); ?><?php echo esc_html( ueb_ipes_niveau( $etudiant->niveau ) . ', ' . str_replace( '-', ' – ', $etudiant->annee_academique ) ); ?></li>
							</ul>
						</div>
					</div>
				</div>
			</header>
			<section class="adm-panneau ipes-registre" aria-labelledby="sco-reversement-titre">
				<header class="adm-panneau__tete">
					<div><h2 id="sco-reversement-titre">Reversement</h2><p><?php echo esc_html( 'L’IPES reverse ' . ueb_fcfa( UEB_IPES_REVERSEMENT_PAR_ETUDIANT ) . ' par étudiant à la tutelle de sa filière. Seul un bordereau vérifié compte comme reversé.' ); ?></p></div>
				</header>
				<div class="ipes-corps">
					<p class="ipes-reversement-etat">
						<?php if ( $bordereau && ueb_ipes_bordereau_a_pdf( $bordereau ) && 'brouillon' !== $bordereau->statut ) : ?>
							<span class="ipes-lien-bordereau"><?php echo esc_html( $bordereau->numero ); ?></span><?php echo ueb_ipes_statut( $bordereau->statut ); // phpcs:ignore -- échappé ?>
							<span><?php echo esc_html( 'Bordereau adressé à la ' . $bordereau->etablissement . '.' ); ?></span>
						<?php else : ?>
							<span class="ipes-statut ipes-statut--libre"><?php echo ueb_icone( 'recu', 14 ); ?>Pas encore reversé</span>
							<span>L’IPES ne l’a encore placé dans aucun bordereau envoyé.</span>
						<?php endif; ?>
					</p>
				</div>
			</section>
		<?php endif; ?>

	<?php else : ?>

		<?php
		$bordereaux = ueb_ipes_bordereaux_pour_tutelle( $ipes->id );
		$recherche  = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );
		$vues       = ueb_ipes_tutelles_vues( $ipes );
		$etudiants  = ueb_ipes_etudiants( $ipes->id, array( 'recherche' => $recherche, 'tutelle' => $vues ) );
		$en_attente = count( array_filter( $bordereaux, static fn( $b ) => 'envoye' === $b->statut ) );
		?>
		<a class="fil" href="<?php echo esc_url( $adresse() ); ?>"><?php echo ueb_icone( 'fleche-g', 18 ); ?>Tous les IPES</a>
		<header class="bo-entete ipes-sco-entete">
			<div class="bo-entete__texte">
				<div class="adm-tete__identite">
					<?php echo ueb_ipes_logo_html( $ipes, 'ipes-logo--grand' ); // phpcs:ignore -- échappé ?>
					<div>
						<h1><?php echo esc_html( $ipes->sigle ); ?></h1>
						<p class="bo-entete__sous-titre"><?php echo esc_html( $ipes->nom_fr ); ?></p>
						<ul class="ipes-reperes">
							<li><?php echo (int) $ipes->actif ? ueb_icone( 'check', 14 ) . 'Actif' : ueb_icone( 'pause', 14 ) . 'Désactivé'; ?></li>
							<li><?php echo ueb_icone( 'bouclier', 14 ); ?><?php echo esc_html( 'Tutelle : ' . implode( ', ', $ipes->tutelles ) ); ?></li>
							<?php if ( $ipes->ville ) : ?><li><?php echo ueb_icone( 'lieu', 14 ); ?><?php echo esc_html( $ipes->ville ); ?></li><?php endif; ?>
						</ul>
					</div>
				</div>
			</div>
		</header>
		<?php ueb_afficher_flash(); ?>

		<?php ueb_ipes_hero( $ipes, array( 'pour' => 'ueb', 'tutelles' => $vues ) ); ?>

		<section id="bordereaux" class="adm-panneau ipes-registre" aria-labelledby="sco-bordereaux-titre" tabindex="-1">
			<header class="adm-panneau__tete">
				<div><h2 id="sco-bordereaux-titre">Bordereaux adressés à ton établissement</h2><p>Vérifie chaque bordereau avec son PDF et le relevé reçu, ou rejette-le avec un motif. Les brouillons de l’IPES n’apparaissent pas.</p></div>
				<?php if ( $en_attente ) : ?><span class="adm-a-traiter adm-a-traiter--recu"><?php echo ueb_icone( 'horloge', 14 ); ?><?php echo esc_html( $en_attente . ' à vérifier' ); ?></span><?php endif; ?>
			</header>
			<?php if ( ! $bordereaux ) : ?>
				<div class="bo-vide ipes-vide"><span><?php echo ueb_icone( 'recu', 22 ); ?></span><p><b>Aucun bordereau reçu de cet IPES.</b> Ses bordereaux apparaîtront ici dès qu’il les enverra.</p></div>
			<?php else : ?>
				<?php
				ueb_ipes_bordereaux_liste( $bordereaux, array(
					'annee'   => true,
					'actions' => static function ( $b ) use ( $ipes, $adresse ) {
						ueb_ipes_decision( $b, array(
							'action'       => 'ipes_bordereau_decider_tutelle',
							'url'          => ueb_url_scolarite(),
							'pdf'          => $adresse( array( 'ipes' => (int) $ipes->id, 'bordereau' => (int) $b->id, 'pdf' => 1 ) ),
							'peut_decider' => ueb_peut_verifier_bordereau( $b ),
							'champs'       => array( 'ipes_id' => (int) $ipes->id ),
						) );
					},
				) );
				?>
			<?php endif; ?>
		</section>

		<div class="ipes-grille">
			<section id="etudiants" class="adm-panneau ipes-registre" aria-labelledby="sco-etudiants-titre">
				<header class="adm-panneau__tete">
					<div><h2 id="sco-etudiants-titre">Étudiants <?php echo esc_html( $annee['libelle'] ); ?></h2><p><?php echo esc_html( 'Ceux des filières rattachées à la ' . implode( ' et à la ', $vues ) . ', avec leur reversement.' ); ?></p></div>
					<form class="ipes-outils" method="get" action="<?php echo esc_url( ueb_url_scolarite() ); ?>" role="search" aria-label="Rechercher un étudiant" data-filtres-direct="sco-ipes-etudiants"><?php ueb_champ_espace(); ?>
						<input type="hidden" name="vue" value="ipes">
						<input type="hidden" name="ipes" value="<?php echo (int) $ipes->id; ?>">
						<label class="ipes-recherche"><span class="sr">Rechercher un étudiant</span><?php echo ueb_icone( 'loupe', 17 ); ?><input type="search" name="q" value="<?php echo esc_attr( $recherche ); ?>" placeholder="Matricule, nom ou prénom" enterkeyhint="search" autocomplete="off"></label>
						<button class="adm-bouton" type="submit" data-filtres-bouton><?php echo ueb_icone( 'loupe', 16 ); ?>Rechercher</button>
					</form>
				</header>
				<div id="sco-ipes-etudiants" class="ipes-resultats" aria-live="polite">
					<?php if ( '' !== $recherche ) : ?>
						<p class="ipes-compte"><b><?php echo esc_html( ueb_ipes_pluriel( count( $etudiants ), 'étudiant' ) ); ?></b> pour « <?php echo esc_html( $recherche ); ?> ».</p>
					<?php endif; ?>
					<?php if ( ! $etudiants ) : ?>
						<div class="bo-vide ipes-vide"><span><?php echo ueb_icone( '' !== $recherche ? 'loupe' : 'groupe', 22 ); ?></span><p><?php echo '' !== $recherche ? '<b>Aucun étudiant ne correspond.</b> Cherche par matricule ou par nom.' : '<b>L’IPES n’a déclaré aucun étudiant cette année.</b>'; ?></p></div>
					<?php else : ?>
						<?php ueb_ipes_etudiants_liste( $etudiants, static fn( $e ) => $adresse( array( 'ipes' => (int) $ipes->id, 'etudiant' => (int) $e->id ) ), 'Ouvrir la fiche de' ); ?>
					<?php endif; ?>
				</div>
			</section>

			<aside class="adm-panneau" aria-labelledby="sco-ipes-coordonnees">
				<header class="adm-panneau__tete"><div><h2 id="sco-ipes-coordonnees">Coordonnées et convention</h2><p>Pour joindre l’IPES au sujet de ses reversements.</p></div></header>
				<dl class="ipes-faits ipes-faits--pile">
					<div><dt>Nom complet</dt><dd><?php echo esc_html( $ipes->nom_fr ); ?></dd></div>
					<div><dt>Téléphone</dt><dd><?php echo $ipes->telephone ? '<a href="tel:+237' . esc_attr( $ipes->telephone ) . '">' . esc_html( ueb_formater_telephone( $ipes->telephone ) ) . '</a>' : 'Non renseigné'; ?></dd></div>
					<div><dt>Adresse e-mail</dt><dd><?php echo $ipes->email ? '<a href="mailto:' . esc_attr( $ipes->email ) . '">' . esc_html( $ipes->email ) . '</a>' : 'Non renseignée'; ?></dd></div>
					<div><dt>Convention</dt><dd><?php echo esc_html( $ipes->convention_ref ?: 'Non renseignée' ); ?></dd></div>
					<?php if ( $ipes->convention_signee_le || $ipes->convention_fin_le ) : ?>
						<div><dt>Validité</dt><dd><?php echo esc_html( trim( ( $ipes->convention_signee_le ? 'Signée le ' . mysql2date( 'd/m/Y', $ipes->convention_signee_le ) : '' ) . ( $ipes->convention_fin_le ? ( $ipes->convention_signee_le ? ', jusqu’au ' : 'Jusqu’au ' ) . mysql2date( 'd/m/Y', $ipes->convention_fin_le ) : '' ) ) ); ?></dd></div>
					<?php endif; ?>
				</dl>
			</aside>
		</div>

	<?php endif; ?>

<?php endif; ?>

</div>
