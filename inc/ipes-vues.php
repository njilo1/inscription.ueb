<?php
/**
 * Composants d'affichage des IPES, partagés par l'onglet IPES de
 * l'administration (templates/composants/ipes-admin.php), l'espace IPES
 * (page-ipes.php) et la vue IPES de la scolarité (scolarite-ipes.php) :
 * héros des reversements, registre des IPES, liste des bordereaux, reçus
 * bancaires, décision de l'UEb, statut, logo et pastilles de tutelle.
 *
 * Même principe que le reste du back-office : rendu serveur, un seul moment
 * animé par écran (la jauge Remotion du héros, jouée une fois, avec un repli
 * SVG identique à sa dernière image). Styles : assets/css/ipes.css, posés sur
 * la couche de l'administration (administration.css).
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

/* Icône de chaque statut de bordereau : la couleur ne dit jamais seule l'état. */
const UEB_IPES_ICONES_BORDEREAU = array(
	'brouillon' => 'crayon',
	'envoye'    => 'horloge',
	'verifie'   => 'check',
	'rejete'    => 'alerte',
);

/* ---------- Petits éléments ---------- */

/** Pastille de statut d'un bordereau : icône et libellé. */
function ueb_ipes_statut( $statut ) {
	return sprintf(
		'<span class="ipes-statut ipes-statut--%s">%s%s</span>',
		esc_attr( $statut ),
		ueb_icone( UEB_IPES_ICONES_BORDEREAU[ $statut ] ?? 'info', 14 ),
		esc_html( UEB_IPES_STATUTS_BORDEREAU[ $statut ] ?? $statut )
	);
}

/** Numéro affiché d'un bordereau : « Brouillon n° 8 » tant qu'il n'est pas envoyé. */
function ueb_ipes_numero( $b ) {
	return str_starts_with( (string) $b->numero, 'BROUILLON-' ) ? 'Brouillon n° ' . (int) $b->id : (string) $b->numero;
}

/** Logo rond d'un IPES, ou l'icône d'école s'il n'en a pas. */
function ueb_ipes_logo_html( $ipes, $classe = '' ) {
	$logo = ueb_ipes_logo_url( $ipes );
	return sprintf(
		'<span class="ipes-logo %s" aria-hidden="true">%s</span>',
		esc_attr( $classe ),
		$logo ? '<img src="' . esc_url( $logo ) . '" alt="" loading="lazy">' : ueb_icone( 'ecole', 20 )
	);
}

/** Pastilles des établissements de tutelle, à leur couleur, avec le nom en infobulle. */
function ueb_ipes_pastilles_html( array $sigles ) {
	$html = '';
	foreach ( $sigles as $sigle ) {
		$e     = ueb_etablissement( $sigle );
		$html .= sprintf(
			'<span class="pastille-etab ipes-tutelle" style="--etab: %s" title="%s">%s</span>',
			esc_attr( $e['couleur'] ?? 'var(--vert)' ),
			esc_attr( $e['fr'] ?? $sigle ),
			esc_html( $sigle )
		);
	}
	return '<span class="ipes-tutelles">' . $html . '</span>';
}

/** Niveau en toutes lettres : « Licence 1 » pour L1. */
function ueb_ipes_niveau( $code ) {
	return trim( preg_replace( '/^.*—\s*/u', '', UEB_NIVEAUX_INSCRIPTION[ $code ] ?? (string) $code ) );
}

/** « 1 étudiant », « 3 étudiants ». */
function ueb_ipes_pluriel( $n, $mot, $pluriel = null ) {
	return (int) $n . ' ' . ( (int) $n > 1 ? ( $pluriel ?? $mot . 's' ) : $mot );
}

/**
 * Reversement d'un étudiant : son bordereau (numéro et statut), ou « À
 * reverser » s'il n'est dans aucun. Côté UEb, un brouillon de l'IPES ne se
 * montre pas : l'étudiant y reste « à reverser ».
 *
 * @param object $e    Étudiant avec bordereau_numero et bordereau_statut (ueb_ipes_etudiants).
 * @param bool   $ipes Vu par l'IPES lui-même.
 */
function ueb_ipes_reversement_etudiant( $e, $ipes = false ) {
	$statut = $e->bordereau_id ? (string) $e->bordereau_statut : '';
	if ( '' === $statut || ( ! $ipes && 'brouillon' === $statut ) ) {
		return '<span class="ipes-statut ipes-statut--libre">' . ueb_icone( 'recu', 14 ) . 'À reverser</span>';
	}
	$numero = ueb_ipes_numero( (object) array( 'id' => $e->bordereau_id, 'numero' => $e->bordereau_numero ) );
	return '<span class="ipes-reversement"><span class="ipes-lien-bordereau">' . esc_html( $numero ) . '</span>' . ueb_ipes_statut( $statut ) . '</span>';
}

/* ---------- Héros des reversements ---------- */

/**
 * Le chiffre fort de l'écran : ce que l'UEb a vérifié des reversements de
 * l'année, avec la jauge Remotion (vérifié / dû). Le dû est connu dès qu'il y
 * a des étudiants : leur nombre × le montant par étudiant. Barre en trois
 * parts du dû : vérifié, en vérification, pas encore reversé.
 *
 * @param object $ipes IPES (ueb_ipes).
 * @param array  $o    titre, intro, lien (array( url, libellé )), pour
 *                     ('ipes' : l'IPES parle de « ta tutelle » ; 'ueb' sinon),
 *                     tutelles (sigles : ne montrer que ce qui les concerne,
 *                     vue d'une scolarité ; null pour l'IPES entier).
 */
function ueb_ipes_hero( $ipes, array $o = array() ) {
	$annee = ueb_annee_academique();
	$o     = array_merge( array( 'titre' => 'Reversements ' . $annee['libelle'], 'intro' => '', 'lien' => null, 'pour' => 'ueb', 'tutelles' => null ), $o );
	$jauge = ueb_ipes_jauge( $ipes->id, null, $o['tutelles'] );
	$du    = $jauge['du'];

	/* Parts successives, bornées au dû : jamais plus de 100 % au total. */
	$base    = max( 1, $du );
	$reste_b = $du;
	$parts   = array();
	foreach ( array(
		'encaisse'     => array( 'Vérifié par l’UEb', $jauge['verifie'] ),
		'verification' => array( 'En vérification', max( 0, $jauge['envoye'] - $jauge['verifie'] ) ),
	) as $cle => $p ) {
		$v              = min( $reste_b, max( 0, (int) $p[1] ) );
		$reste_b       -= $v;
		$parts[ $cle ] = array( $p[0], $v );
	}
	$parts['declare'] = array( 'Pas encore reversé', $reste_b );
	$taux       = $du > 0 ? min( 100, round( 100 * $jauge['verifie'] / $du, 1 ) ) : null;
	$a_verifier = count( array_filter( ueb_ipes_bordereaux( $ipes->id ), static fn( $b ) => 'envoye' === $b->statut && ( null === $o['tutelles'] || in_array( $b->etablissement, $o['tutelles'], true ) ) ) );
	if ( null !== $o['tutelles'] ) {
		$tutelle = 'la ' . implode( ' et la ', $o['tutelles'] );
	} else {
		$tutelle = 'ipes' === $o['pour'] ? ( 1 === count( $ipes->tutelles ) ? 'ta tutelle' : 'tes tutelles' ) : ( 1 === count( $ipes->tutelles ) ? 'sa tutelle' : 'ses tutelles' );
	}
	$unitaire   = ueb_fcfa( UEB_IPES_REVERSEMENT_PAR_ETUDIANT );
	?>
	<section class="adm-hero ipes-hero<?php echo null === $taux ? ' ipes-hero--sans-jauge' : ''; ?>" aria-labelledby="ipes-hero-titre">
		<?php if ( null !== $taux ) : ?>
			<div class="adm-hero__visuel">
				<?php
				ueb_animation(
					'jauge',
					array( 'taux' => $taux, 'libelle' => 'vérifiés' ),
					'animation--jauge',
					'Reversements vérifiés : ' . ueb_pourcent( $taux ) . ' du montant dû',
					ueb_bord_jauge_repli( $taux, 'vérifiés' )
				);
				?>
			</div>
		<?php endif; ?>

		<div class="adm-hero__corps">
			<header class="adm-hero__tete">
				<div>
					<h2 id="ipes-hero-titre"><?php echo esc_html( $o['titre'] ); ?></h2>
					<p><?php echo esc_html( $o['intro'] ?: 'Chaque étudiant inscrit vaut ' . $unitaire . ' à reverser à ' . $tutelle . '. Seuls les bordereaux vérifiés comptent comme reversés.' ); ?></p>
				</div>
				<?php if ( $o['lien'] ) : ?>
					<a class="adm-hero__lien" href="<?php echo esc_url( $o['lien'][0] ); ?>"><?php echo esc_html( $o['lien'][1] ); ?><?php echo ueb_icone( 'fleche', 16 ); ?></a>
				<?php endif; ?>
			</header>

			<?php if ( ! $jauge['etudiants'] && ! $jauge['envoye'] ) : ?>
				<p class="adm-hero__vide"><?php echo ueb_icone( 'groupe', 20 ); ?><?php echo 'ipes' === $o['pour'] ? 'Aucun étudiant cette année. Ajoute tes étudiants : le montant à reverser et le suivi apparaîtront ici.' : 'Aucun étudiant déclaré par l’IPES cette année pour l’instant.'; ?></p>
			<?php else : ?>
				<p class="adm-hero__montant">
					<b><?php echo esc_html( ueb_formater_montant( $jauge['verifie'] ) ); ?><small>FCFA</small></b>
					<span><?php echo esc_html( 'vérifiés sur ' . ueb_fcfa( $du ) . ' dus pour ' . ueb_ipes_pluriel( $jauge['etudiants'], 'étudiant' ) . ' (' . $unitaire . ' chacun). Reste à percevoir : ' . ueb_fcfa( $jauge['reste'] ) . '.' ); ?></span>
				</p>

				<div class="suivi-barre suivi-barre--hero adm-hero__barre" role="img" aria-label="<?php echo esc_attr( implode( ', ', array_map( static fn( $p ) => $p[0] . ' ' . ueb_fcfa( $p[1] ), $parts ) ) ); ?>">
					<?php foreach ( $parts as $cle => $p ) :
						if ( $p[1] <= 0 ) {
							continue;
						}
						$part = 100 * $p[1] / $base;
						?>
						<span class="suivi-barre__part suivi-barre__part--<?php echo esc_attr( $cle ); ?>" style="--part: <?php echo esc_attr( round( $part, 3 ) ); ?>%" data-info="<?php echo esc_attr( $p[0] . ' : ' . ueb_fcfa( $p[1] ) . ' (' . ueb_pourcent( $part ) . ')' ); ?>"></span>
					<?php endforeach; ?>
				</div>
				<ul class="adm-hero__legende ipes-hero__legende">
					<?php foreach ( $parts as $cle => $p ) : ?>
						<li class="suivi-legende__item--<?php echo esc_attr( $cle ); ?>">
							<i aria-hidden="true"></i>
							<span><?php echo esc_html( $p[0] ); ?></span>
							<b><?php echo esc_html( ueb_fcfa( $p[1] ) ); ?></b>
							<small><?php echo esc_html( ueb_pourcent( 100 * $p[1] / $base ) ); ?></small>
						</li>
					<?php endforeach; ?>
				</ul>

				<dl class="adm-hero__situations">
					<div><dt>Étudiants de l’année</dt><dd><?php echo esc_html( ueb_formater_montant( $jauge['etudiants'] ) ); ?></dd></div>
					<div><dt>Pas encore dans un bordereau</dt><dd><?php echo esc_html( ueb_formater_montant( $jauge['libres'] ) ); ?></dd></div>
					<div><dt>Reversé à <?php echo esc_html( $tutelle ); ?></dt><dd><?php echo esc_html( ueb_adm_montant_court( $jauge['envoye'] ) ); ?><small>FCFA</small></dd></div>
					<div><dt><?php echo 'ipes' === $o['pour'] ? 'Bordereaux en vérification' : 'Bordereaux à vérifier'; ?></dt><dd><?php echo (int) $a_verifier; ?></dd></div>
				</dl>

				<?php if ( count( $jauge['par_tutelle'] ) > 1 ) : ?>
					<ul class="ipes-hero__tutelles">
						<?php foreach ( $jauge['par_tutelle'] as $sigle => $t ) : ?>
							<li>
								<?php echo ueb_ipes_pastilles_html( array( $sigle ) ); // phpcs:ignore -- échappé ?>
								<span><?php echo esc_html( ueb_ipes_pluriel( $t['etudiants'], 'étudiant' ) . ' · ' . ueb_fcfa( $t['verifie'] ) . ' vérifiés sur ' . ueb_fcfa( $t['du'] ) ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	</section>
	<?php
}

/**
 * Bordereaux envoyés en attente d'une décision, tous IPES confondus (bandeau
 * de la liste de l'administration), ou limités à des tutelles.
 *
 * @param array|null $tutelles Sigles d'établissement, ou null pour tous.
 */
function ueb_ipes_bordereaux_envoyes( $tutelles = null ) {
	global $wpdb;
	$lignes = $wpdb->get_results( "SELECT id, ipes_id, numero, etablissement, total, date_envoi FROM ueb_insc_ipes_bordereaux WHERE statut = 'envoye' ORDER BY date_envoi ASC, id ASC" );
	return null === $tutelles ? $lignes : array_values( array_filter( $lignes, static fn( $b ) => in_array( $b->etablissement, $tutelles, true ) ) );
}

/* ---------- Registre des IPES ---------- */

/**
 * Une ligne par IPES : identité, tutelles, étudiants, reversements vérifiés
 * (barre sur le montant dû : étudiants × montant par étudiant), ce qui attend une décision. Toute la ligne
 * mène à la fiche (lien étiré), même après un filtrage en direct.
 *
 * @param array $liste IPES (ueb_ipes_liste ou ueb_ipes_sous_tutelle).
 * @param array $o     url (callable $ipes => adresse de la fiche),
 *                     a_verifier (callable $ipes => nombre de bordereaux à vérifier),
 *                     tutelles (callable $ipes => sigles vus, pour une scolarité ;
 *                     absent pour l'IPES entier),
 *                     vide (phrase quand la liste est vide).
 */
function ueb_ipes_registre( array $liste, array $o ) {
	?>
	<table class="adm-registre__table ipes-table ipes-table--registre">
		<thead><tr>
			<th scope="col">IPES</th>
			<th scope="col">Tutelle</th>
			<th scope="col" class="num">Étudiants</th>
			<th scope="col">Reversements vérifiés</th>
			<th scope="col">À traiter</th>
			<th scope="col"><span class="sr">Ouvrir</span></th>
		</tr></thead>
		<tbody>
		<?php foreach ( $liste as $ipes ) :
			$jauge  = ueb_ipes_jauge( $ipes->id, null, isset( $o['tutelles'] ) ? ( $o['tutelles'] )( $ipes ) : null );
			$n      = (int) ( $o['a_verifier'] )( $ipes );
			$actif  = (int) $ipes->actif;
			$taux   = $jauge['du'] ? min( 100, 100 * $jauge['verifie'] / $jauge['du'] ) : null;
			$lieu   = array_filter( array( $ipes->ville, $ipes->convention_ref ? 'convention ' . $ipes->convention_ref : '' ) );
			?>
			<tr class="ipes-ligne<?php echo $actif ? '' : ' est-inactif'; ?>">
				<td class="ipes-c-qui"><span class="ipes-ligne__qui">
					<?php echo ueb_ipes_logo_html( $ipes ); // phpcs:ignore -- échappé par la fonction ?>
					<span class="ipes-ligne__texte">
						<b><?php echo esc_html( $ipes->sigle ); ?></b>
						<small title="<?php echo esc_attr( $ipes->nom_fr ); ?>"><?php echo esc_html( $ipes->nom_fr ); ?></small>
						<?php if ( $lieu ) : ?><small><?php echo esc_html( ucfirst( implode( ', ', $lieu ) ) ); ?></small><?php endif; ?>
					</span>
				</span></td>
				<td data-titre="Tutelle"><?php echo ueb_ipes_pastilles_html( $ipes->tutelles ); // phpcs:ignore -- échappé ?></td>
				<td class="num ipes-ligne__nombre" data-titre="Étudiants"><b><?php echo (int) $jauge['etudiants']; ?></b></td>
				<td class="ipes-ligne__mesure" data-titre="Reversements vérifiés">
					<?php if ( null !== $taux ) : ?>
						<span class="ipes-mesure">
							<span class="ipes-mesure__barre" aria-hidden="true"><i style="--part: <?php echo esc_attr( round( $taux, 2 ) ); ?>%"></i></span>
							<b><?php echo esc_html( ueb_pourcent( $taux ) ); ?></b>
						</span>
						<small><?php echo esc_html( ueb_fcfa( $jauge['verifie'] ) . ' sur ' . ueb_fcfa( $jauge['du'] ) ); ?></small>
					<?php else : ?>
						<span class="ipes-ligne__rien">Aucun étudiant cette année</span>
					<?php endif; ?>
				</td>
				<td data-titre="À traiter">
					<?php if ( ! $actif ) : ?>
						<span class="adm-etat adm-etat--suspendu"><?php echo ueb_icone( 'pause', 14 ); ?>Désactivé</span>
					<?php elseif ( $n ) : ?>
						<span class="adm-a-traiter adm-a-traiter--recu"><?php echo ueb_icone( 'horloge', 14 ); ?><?php echo esc_html( ueb_ipes_pluriel( $n, 'bordereau', 'bordereaux' ) . ' à vérifier' ); ?></span>
					<?php else : ?>
						<span class="ipes-ligne__rien">Rien à vérifier</span>
					<?php endif; ?>
				</td>
				<td class="ipes-ligne__aller">
					<a class="ipes-ouvrir" href="<?php echo esc_url( ( $o['url'] )( $ipes ) ); ?>" aria-label="<?php echo esc_attr( 'Ouvrir la fiche de ' . $ipes->sigle ); ?>"><?php echo ueb_icone( 'fleche', 18 ); ?></a>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

/* ---------- Étudiants ---------- */

/**
 * Étudiants d'un IPES : identité (initiales, nom, matricule), filière,
 * niveau et tutelle, reversement (bordereau et statut, ou « À reverser »).
 * Toute la ligne mène au détail.
 *
 * @param array    $liste Étudiants (ueb_ipes_etudiants).
 * @param callable $url   $e => adresse du détail.
 * @param string   $aller Libellé accessible du lien.
 * @param bool     $pour_ipes Vue de l'IPES lui-même (ses brouillons s'affichent).
 */
function ueb_ipes_etudiants_liste( array $liste, callable $url, $aller = 'Ouvrir la fiche de', $pour_ipes = false ) {
	?>
	<table class="adm-registre__table ipes-table ipes-table--etudiants">
		<thead><tr>
			<th scope="col">Étudiant</th>
			<th scope="col">Filière</th>
			<th scope="col">Tutelle</th>
			<th scope="col">Reversement</th>
			<th scope="col"><span class="sr">Ouvrir</span></th>
		</tr></thead>
		<tbody>
		<?php foreach ( $liste as $e ) : $nom = trim( $e->nom . ' ' . $e->prenom ); ?>
			<tr class="ipes-ligne">
				<td class="ipes-c-qui"><span class="ipes-ligne__qui">
					<span class="bo-avatar" aria-hidden="true"><?php echo esc_html( ueb_initiales( $e->prenom, $e->nom ) ); ?></span>
					<span class="ipes-ligne__texte"><b><?php echo esc_html( $nom ); ?></b><small><?php echo esc_html( $e->matricule ); ?></small></span>
				</span></td>
				<td data-titre="Filière"><span class="ipes-ligne__texte"><span><?php echo esc_html( $e->filiere ?: '—' ); ?></span><small><?php echo esc_html( ueb_ipes_niveau( $e->niveau ) ); ?></small></span></td>
				<td data-titre="Tutelle"><?php echo $e->tutelle ? ueb_ipes_pastilles_html( array( $e->tutelle ) ) : '—'; // phpcs:ignore -- échappé ?></td>
				<td data-titre="Reversement"><?php echo ueb_ipes_reversement_etudiant( $e, $pour_ipes ); // phpcs:ignore -- échappé ?></td>
				<td class="ipes-ligne__aller"><a class="ipes-ouvrir" href="<?php echo esc_url( $url( $e ) ); ?>" aria-label="<?php echo esc_attr( $aller . ' ' . $nom ); ?>"><?php echo ueb_icone( 'fleche', 18 ); ?></a></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

/**
 * Résumé d'erreurs accessible, avec un lien vers chaque champ (même rendu que
 * le quitus). Le JavaScript de l'application y porte le focus.
 */
function ueb_ipes_resume_erreurs( array $erreurs, $titre ) {
	if ( ! $erreurs ) {
		return;
	}
	?>
	<div class="alerte alerte--erreur ipes-erreurs" role="alert" tabindex="-1" data-resume-erreurs>
		<?php echo ueb_icone( 'alerte', 20 ); ?>
		<div>
			<p><strong><?php echo esc_html( $titre ); ?></strong></p>
			<ul>
				<?php foreach ( $erreurs as $champ => $message ) : ?>
					<li><?php if ( 'general' === $champ ) : ?><?php echo esc_html( $message ); ?><?php else : ?><a href="#champ-<?php echo esc_attr( $champ ); ?>" data-lien-erreur><?php echo esc_html( $message ); ?></a><?php endif; ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	</div>
	<?php
}

/* ---------- Bordereaux ---------- */

/**
 * Liste de bordereaux. Côté IPES, chaque ligne s'ouvre sur sa fiche (lien
 * étiré) ; côté UEb, la ligne porte le PDF et la décision.
 *
 * @param array $bordereaux Bordereaux (objets de ueb_ipes_bordereaux*).
 * @param array $o          url (callable $b => adresse de la fiche, côté IPES),
 *                          actions (callable $b => affiche les boutons, côté UEb),
 *                          annee (bool : afficher l'année du bordereau).
 */
function ueb_ipes_bordereaux_liste( array $bordereaux, array $o = array() ) {
	$o = array_merge( array( 'url' => null, 'actions' => null, 'annee' => false ), $o );
	?>
	<table class="adm-registre__table ipes-table ipes-table--bordereaux<?php echo $o['actions'] ? ' ipes-table--decision' : ''; ?>">
		<thead><tr>
			<th scope="col">Bordereau</th>
			<th scope="col">Tutelle</th>
			<th scope="col" class="num">Montant</th>
			<th scope="col">Statut</th>
			<th scope="col"><span class="sr"><?php echo $o['actions'] ? 'Actions' : 'Ouvrir'; ?></span></th>
		</tr></thead>
		<tbody>
		<?php foreach ( $bordereaux as $b ) :
			$montant = ueb_ipes_montant_bordereau( $b );
			$details = array( ueb_ipes_pluriel( $b->nb_etudiants, 'étudiant' ) );
			if ( $b->date_envoi ) {
				$details[] = 'envoyé le ' . mysql2date( 'd/m/Y', $b->date_envoi );
			}
			if ( $o['annee'] ) {
				$details[] = str_replace( '-', ' – ', $b->annee_academique );
			}
			?>
			<tr class="ipes-ligne ipes-ligne--<?php echo esc_attr( $b->statut ); ?>">
				<td class="ipes-c-qui"><span class="ipes-ligne__qui">
					<span class="ipes-doc" aria-hidden="true"><?php echo ueb_icone( 'recu', 18 ); ?></span>
					<span class="ipes-ligne__texte">
						<b><?php echo esc_html( ueb_ipes_numero( $b ) ); ?></b>
						<small><?php echo esc_html( ucfirst( implode( ', ', $details ) ) ); ?></small>
						<?php if ( 'rejete' === $b->statut && $b->motif_rejet ) : ?>
							<small class="ipes-ligne__motif"><?php echo esc_html( 'Motif : ' . $b->motif_rejet ); ?></small>
						<?php endif; ?>
					</span>
				</span></td>
				<td data-titre="Tutelle"><?php echo ueb_ipes_pastilles_html( array( $b->etablissement ) ); // phpcs:ignore -- échappé ?></td>
				<td class="num ipes-ligne__montant" data-titre="Montant"><?php echo esc_html( ueb_formater_montant( $montant ) ); ?> <small>FCFA</small></td>
				<td data-titre="Statut"><?php echo ueb_ipes_statut( $b->statut ); // phpcs:ignore -- échappé ?></td>
				<?php if ( $o['actions'] ) : ?>
					<td class="ipes-ligne__actions"><div class="ipes-actions"><?php ( $o['actions'] )( $b ); ?></div></td>
				<?php else : ?>
					<td class="ipes-ligne__aller"><a class="ipes-ouvrir" href="<?php echo esc_url( ( $o['url'] )( $b ) ); ?>" aria-label="<?php echo esc_attr( 'Ouvrir ' . ueb_ipes_numero( $b ) ); ?>"><?php echo ueb_icone( 'fleche', 18 ); ?></a></td>
				<?php endif; ?>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

/**
 * PDF, reçus bancaires et décision de l'UEb sur un bordereau : « Vérifié »
 * (confirmé), ou « Rejeter » avec un motif dans une fenêtre. Les reçus
 * s'ouvrent dans une fenêtre, pour être comparés au bordereau. La décision
 * n'est proposée que pour un bordereau envoyé et si $peut_decider.
 *
 * @param array $o action (ueb_action), url (cible du formulaire), pdf (adresse),
 *                 peut_decider (bool), champs (champs cachés en plus : nom => valeur).
 */
function ueb_ipes_decision( $b, array $o ) {
	$o = array_merge( array( 'action' => '', 'url' => '', 'pdf' => '', 'peut_decider' => false, 'champs' => array() ), $o );
	$caches = static function () use ( $b, $o ) {
		ueb_champ_csrf();
		printf( '<input type="hidden" name="ueb_action" value="%s">', esc_attr( $o['action'] ) );
		printf( '<input type="hidden" name="bordereau_id" value="%d">', (int) $b->id );
		foreach ( $o['champs'] as $nom => $valeur ) {
			printf( '<input type="hidden" name="%s" value="%s">', esc_attr( $nom ), esc_attr( $valeur ) );
		}
	};
	$id = (int) $b->id;
	if ( $o['pdf'] ) : ?>
		<a class="adm-bouton adm-bouton--petit" href="<?php echo esc_url( $o['pdf'] ); ?>"><?php echo ueb_icone( 'telecharger', 15 ); ?>PDF</a>
	<?php endif;
	$recus = 'brouillon' === $b->statut ? array() : ueb_ipes_recus( (int) $b->ipes_id, $id );
	if ( $recus ) : ?>
		<button class="adm-bouton adm-bouton--petit" type="button" data-ouvrir-agent-mdp="recus-<?php echo $id; ?>"><?php echo ueb_icone( 'recu', 15 ); ?><?php echo esc_html( 'Reçus (' . count( $recus ) . ')' ); ?></button>
		<dialog class="bo-agent-mdp ipes-dialogue ipes-dialogue--recus" id="recus-<?php echo $id; ?>" aria-labelledby="recus-titre-<?php echo $id; ?>">
			<h2 id="recus-titre-<?php echo $id; ?>"><?php echo esc_html( 'Reçus bancaires · ' . $b->numero ); ?></h2>
			<p><?php echo esc_html( 'À comparer au bordereau : ' . ueb_fcfa( $b->total ) . ' pour ' . ueb_ipes_pluriel( (int) ( $b->nb_etudiants ?? 0 ), 'étudiant' ) . '. Chaque reçu s’ouvre en grand dans un nouvel onglet.' ); ?></p>
			<?php ueb_ipes_recus_liste( $b, $recus ); ?>
			<div class="bo-agent-mdp__actions"><button class="btn btn--lien btn--petit" type="button" data-fermer-agent-mdp>Fermer</button></div>
		</dialog>
	<?php elseif ( 'brouillon' !== $b->statut ) : ?>
		<span class="ipes-sans-recu" title="Bordereau envoyé avant l’obligation des reçus"><?php echo ueb_icone( 'alerte', 14 ); ?>Sans reçu</span>
	<?php endif;
	if ( 'envoye' === $b->statut && $o['peut_decider'] ) : ?>
		<button class="adm-bouton adm-bouton--petit" type="button" data-ouvrir-agent-mdp="rejet-<?php echo $id; ?>">Rejeter</button>
		<form method="post" action="<?php echo esc_url( $o['url'] ); ?>" data-confirmer="<?php echo esc_attr( 'Marquer le bordereau ' . $b->numero . ' (' . ueb_fcfa( $b->total ) . ') comme vérifié ? As-tu contrôlé le reçu bancaire ? La décision est définitive.' ); ?>">
			<?php $caches(); ?>
			<input type="hidden" name="decision" value="verifie">
			<button class="adm-bouton adm-bouton--petit adm-bouton--primaire" type="submit"><?php echo ueb_icone( 'check', 15 ); ?>Vérifié</button>
		</form>
		<dialog class="bo-agent-mdp ipes-dialogue" id="rejet-<?php echo $id; ?>" aria-labelledby="rejet-titre-<?php echo $id; ?>">
			<h2 id="rejet-titre-<?php echo $id; ?>">Rejeter <?php echo esc_html( $b->numero ); ?></h2>
			<p>L’IPES verra ce motif, corrigera son bordereau et le renverra avec le même numéro.</p>
			<form method="post" action="<?php echo esc_url( $o['url'] ); ?>">
				<?php $caches(); ?>
				<input type="hidden" name="decision" value="rejete">
				<label><span>Motif du rejet</span><textarea name="motif" rows="3" minlength="5" maxlength="255" required placeholder="Par exemple : le montant du reçu bancaire ne correspond pas au total du bordereau."></textarea></label>
				<div class="bo-agent-mdp__actions"><button class="btn btn--lien btn--petit" type="button" data-fermer-agent-mdp>Annuler</button><button class="btn btn--danger btn--petit" type="submit">Rejeter le bordereau</button></div>
			</form>
		</dialog>
	<?php endif;
}

/**
 * Bandeau de la file : les bordereaux envoyés qui attendent une décision, en
 * une phrase, et l'accès au plus ancien. Rien s'il n'y en a aucun.
 *
 * @param array    $envoyes Bordereaux au statut « envoye ».
 * @param callable $url     $b => adresse où le décider.
 */
function ueb_ipes_bandeau_a_verifier( array $envoyes, callable $url ) {
	if ( ! $envoyes ) {
		return;
	}
	usort( $envoyes, static fn( $a, $b ) => strcmp( (string) $a->date_envoi, (string) $b->date_envoi ) );
	$ancien  = $envoyes[0];
	$n       = count( $envoyes );
	$total   = array_sum( array_map( static fn( $b ) => (int) $b->total, $envoyes ) );
	$attente = $ancien->date_envoi ? human_time_diff( strtotime( $ancien->date_envoi ), current_time( 'timestamp' ) ) : '';
	?>
	<section class="registre-file ipes-file" aria-labelledby="ipes-file-titre">
		<span class="registre-file__icone" aria-hidden="true"><?php echo ueb_icone( 'recu', 22 ); ?></span>
		<div class="registre-file__texte">
			<h2 id="ipes-file-titre"><?php echo esc_html( 1 === $n ? '1 bordereau attend ta vérification' : $n . ' bordereaux attendent ta vérification' ); ?></h2>
			<p><?php echo esc_html( ueb_fcfa( $total ) . ' reversés au total.' . ( $attente ? ' Le plus ancien attend depuis ' . $attente . '.' : '' ) ); ?></p>
		</div>
		<a class="adm-bouton adm-bouton--primaire registre-file__action" href="<?php echo esc_url( $url( $ancien ) ); ?>"><?php echo esc_html( 1 === $n ? 'Ouvrir le bordereau' : 'Ouvrir le plus ancien' ); ?><?php echo ueb_icone( 'fleche', 17 ); ?></a>
	</section>
	<?php
}

/* ---------- Reçus bancaires d'un bordereau ---------- */

/**
 * Liste des reçus d'un bordereau : vignette (ouvre le reçu), nom, date et
 * poids, téléchargement ; retrait si $o['modifiable'] (espace de l'IPES).
 *
 * @param object $b     Bordereau.
 * @param array  $recus Reçus (ueb_ipes_recus).
 * @param array  $o     modifiable, action (adresse du formulaire de retrait),
 *                      garder (attribut data-garder-selection déjà échappé).
 */
function ueb_ipes_recus_liste( $b, array $recus, array $o = array() ) {
	$o      = array_merge( array( 'modifiable' => false, 'action' => '', 'garder' => '' ), $o );
	$garder = $o['garder'];
	?>
		<ul class="recus-liste">
			<?php foreach ( $recus as $rang => $r ) : $adresse = ueb_url_recu_ipes( $r->id ); ?>
				<li class="recus-liste__item" style="--i: <?php echo (int) $rang; ?>">
					<a class="recus-liste__vignette" href="<?php echo esc_url( $adresse ); ?>" target="_blank" rel="noopener" aria-label="<?php echo esc_attr( 'Ouvrir ' . $r->nom_original ); ?>">
						<?php if ( 'application/pdf' === $r->type_mime ) : ?>
							<span class="recus-liste__pdf"><?php echo ueb_icone( 'fichier', 26 ); ?>PDF</span>
						<?php else : ?>
							<img src="<?php echo esc_url( $adresse ); ?>" alt="" loading="lazy">
						<?php endif; ?>
					</a>
					<div class="recus-liste__infos">
						<b title="<?php echo esc_attr( $r->nom_original ); ?>"><?php echo esc_html( $r->nom_original ); ?></b>
						<span>Joint le <?php echo esc_html( mysql2date( 'j F Y à H:i', $r->date_envoi ) ); ?> · <?php echo esc_html( size_format( $r->taille, 1 ) ); ?></span>
					</div>
					<a class="recus-liste__telecharger" href="<?php echo esc_url( ueb_url_recu_ipes( $r->id, true ) ); ?>" aria-label="<?php echo esc_attr( 'Télécharger ' . $r->nom_original ); ?>" title="Télécharger"><?php echo ueb_icone( 'telecharger', 18 ); ?></a>
					<?php if ( $o['modifiable'] ) : ?>
						<form method="post" action="<?php echo esc_url( $o['action'] ); ?>" data-confirmer="Retirer ce reçu du bordereau ?"<?php echo $garder; // phpcs:ignore -- échappé ?>>
							<?php ueb_champ_csrf(); ?>
							<input type="hidden" name="ueb_action" value="ipes_recu_supprimer">
							<input type="hidden" name="bordereau_id" value="<?php echo (int) $b->id; ?>">
							<input type="hidden" name="recu_id" value="<?php echo (int) $r->id; ?>">
							<button class="recus-liste__supprimer" type="submit" aria-label="<?php echo esc_attr( 'Retirer ' . $r->nom_original ); ?>" title="Retirer"><?php echo ueb_icone( 'corbeille', 18 ); ?></button>
						</form>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php
}

/**
 * Panneau des reçus d'un bordereau : la liste (vignette, ouverture,
 * téléchargement) et, si $o['modifiable'], le retrait de chaque reçu et le
 * dépôt de nouveaux (même composant que les reçus des étudiants : glisser,
 * aperçus, photos compressées dans le navigateur).
 *
 * @param object $b Bordereau.
 * @param array  $o modifiable (bool : l'IPES peut ajouter et retirer),
 *                  action (adresse des formulaires),
 *                  selection (sélecteur du formulaire d'étudiants dont les cases
 *                  cochées non enregistrées partent avec chaque envoi).
 */
function ueb_ipes_recus_panneau( $b, array $o = array() ) {
	$o        = array_merge( array( 'modifiable' => false, 'action' => '', 'selection' => '' ), $o );
	$garder   = $o['selection'] ? ' data-garder-selection="' . esc_attr( $o['selection'] ) . '"' : '';
	$recus    = ueb_ipes_recus( (int) $b->ipes_id, (int) $b->id );
	$restants = max( 0, UEB_IPES_RECUS_MAX - count( $recus ) );
	?>
	<section id="recus" class="adm-panneau ipes-recus" aria-labelledby="ipes-recus-titre" tabindex="-1">
		<header class="adm-panneau__tete">
			<div>
				<h2 id="ipes-recus-titre">Reçus bancaires</h2>
				<p><?php echo esc_html( $o['modifiable'] ? 'La preuve du virement à la ' . $b->etablissement . ' : au moins un reçu pour envoyer le bordereau, ' . UEB_IPES_RECUS_MAX . ' au plus.' : 'La preuve du virement jointe par l’IPES, figée avec le bordereau.' ); ?></p>
			</div>
			<span class="envoi-carte__compteur"><?php echo count( $recus ) . ' sur ' . (int) UEB_IPES_RECUS_MAX; ?></span>
		</header>
		<div class="ipes-corps ipes-recus__corps">
			<?php if ( ! $recus ) : ?>
				<div class="bo-vide ipes-vide"><span><?php echo ueb_icone( 'recu', 22 ); ?></span><p><?php echo $o['modifiable'] ? '<b>Aucun reçu pour l’instant.</b> Joins la photo ou le scan du reçu bancaire du virement.' : '<b>Aucun reçu joint.</b>'; ?></p></div>
			<?php else : ?>
				<?php ueb_ipes_recus_liste( $b, $recus, array( 'modifiable' => $o['modifiable'], 'action' => $o['action'], 'garder' => $garder ) ); ?>
			<?php endif; ?>

			<?php if ( $o['modifiable'] && $restants > 0 ) : ?>
				<form class="ipes-recus__depot" method="post" action="<?php echo esc_url( $o['action'] ); ?>" enctype="multipart/form-data" data-formulaire data-envoi-recus data-libelle-un="Joindre ce reçu" data-libelle-plusieurs="Joindre ces {n} reçus"<?php echo $garder; // phpcs:ignore -- échappé ?>>
					<?php ueb_champ_csrf(); ?>
					<input type="hidden" name="ueb_action" value="ipes_recus_envoyer">
					<input type="hidden" name="bordereau_id" value="<?php echo (int) $b->id; ?>">
					<label class="depot" data-depot>
						<input type="file" name="recus[]" accept="image/jpeg,image/png,application/pdf" multiple data-max="<?php echo (int) $restants; ?>" data-max-octets="<?php echo (int) UEB_RECUS_MAX_OCTETS; ?>" aria-describedby="ipes-depot-aide">
						<span class="depot__icone"><?php echo ueb_icone( 'fichier', 28 ); ?></span>
						<span class="depot__titre" data-depot-titre>Choisis la photo ou le scan du reçu bancaire</span>
						<span class="depot__aide" id="ipes-depot-aide">Glisse-le ici ou clique pour le choisir · JPG, PNG ou PDF, 5 Mo au plus. Le reçu entier, cachet de la banque et montant lisibles.</span>
						<span class="depot__places"><?php echo esc_html( $restants . ' fichier' . ( $restants > 1 ? 's' : '' ) . ' encore possible' . ( $restants > 1 ? 's' : '' ) ); ?></span>
					</label>
					<div class="depot-camera">
						<span>ou</span>
						<label class="btn btn--fantome btn--petit depot-camera__natif" data-camera-natif>
							<input type="file" name="recus[]" accept="image/*" capture="environment" data-capture>
							<?php echo ueb_icone( 'appareil', 18 ); ?>Prendre une photo
						</label>
					</div>
					<ul class="depot__apercus" data-apercus aria-live="polite"></ul>
					<p class="champ__erreur" data-depot-erreur hidden></p>
					<button class="adm-bouton adm-bouton--primaire" type="submit" disabled data-depot-envoyer><?php echo ueb_icone( 'envoyer', 16 ); ?><span data-depot-libelle>Joindre ce reçu</span></button>
				</form>
			<?php elseif ( $o['modifiable'] ) : ?>
				<div class="depot-plein"><?php echo ueb_icone( 'info', 20 ); ?><p><?php echo esc_html( 'Limite de ' . UEB_IPES_RECUS_MAX . ' reçus atteinte. Retire un reçu pour en joindre un autre.' ); ?></p></div>
			<?php endif; ?>
		</div>
	</section>
	<?php
}

/* ---------- Synthèse des IPES pour les tableaux de bord ---------- */

/**
 * Chiffres cumulés des IPES d'une liste, pour l'année en cours : IPES
 * (actifs), étudiants déclarés, montant dû (étudiants × montant par
 * étudiant), envoyé, vérifié, reste à percevoir, bordereaux à vérifier.
 *
 * @param array               $liste    IPES (ueb_ipes_liste, ueb_ipes_sous_tutelle).
 * @param array|callable|null $tutelles Part regardée : null pour l'IPES entier,
 *                                      une liste de sigles (filtre d'établissement
 *                                      de l'admin), ou $ipes => sigles (scolarité).
 */
function ueb_ipes_synthese( array $liste, $tutelles = null ) {
	$s   = array( 'ipes' => count( $liste ), 'actifs' => 0, 'etudiants' => 0, 'du' => 0, 'envoye' => 0, 'verifie' => 0, 'reste' => 0, 'a_verifier' => 0 );
	$vus = array();
	foreach ( $liste as $ipes ) {
		$vues = is_callable( $tutelles ) ? $tutelles( $ipes ) : $tutelles;
		$vus[ (int) $ipes->id ] = $vues;
		$j = ueb_ipes_jauge( $ipes->id, null, $vues );
		$s['actifs'] += (int) $ipes->actif;
		foreach ( array( 'etudiants', 'du', 'envoye', 'verifie', 'reste' ) as $cle ) {
			$s[ $cle ] += (int) $j[ $cle ];
		}
	}
	foreach ( ueb_ipes_bordereaux_envoyes() as $b ) {
		$id = (int) $b->ipes_id;
		if ( array_key_exists( $id, $vus ) && ( null === $vus[ $id ] || in_array( $b->etablissement, (array) $vus[ $id ], true ) ) ) {
			$s['a_verifier']++;
		}
	}
	return $s;
}

/**
 * Panneau « IPES » des tableaux de bord (administration et scolarité) :
 * chiffres clés, barre vérifié / en vérification / pas encore reversé sur le
 * montant dû, bordereaux à vérifier, lien vers l'onglet IPES.
 *
 * @param array $s Synthèse (ueb_ipes_synthese).
 * @param array $o url (onglet IPES), classe (classe du panneau : adm-panneau
 *                 ou carte), portee (phrase sous le titre).
 */
function ueb_ipes_panneau_synthese( array $s, array $o ) {
	$o     = array_merge( array( 'url' => '', 'classe' => 'adm-panneau', 'portee' => '' ), $o );
	$du    = max( 0, (int) $s['du'] );
	$base  = max( 1, $du );
	$verif = min( $du, (int) $s['verifie'] );
	$cours = min( $du - $verif, max( 0, (int) $s['envoye'] - (int) $s['verifie'] ) );
	$parts = array(
		'encaisse'     => array( 'Vérifié', $verif ),
		'verification' => array( 'En vérification', $cours ),
		'declare'      => array( 'Pas encore reversé', max( 0, $du - $verif - $cours ) ),
	);
	$taux = $du ? min( 100, 100 * $verif / $du ) : null;
	?>
	<section class="<?php echo esc_attr( $o['classe'] ); ?> ipes-synthese" aria-labelledby="ipes-synthese-titre">
		<header class="ipes-synthese__tete">
			<div>
				<h2 id="ipes-synthese-titre">IPES sous tutelle</h2>
				<p><?php echo esc_html( $o['portee'] ?: 'Reversements de l’année : ' . ueb_fcfa( UEB_IPES_REVERSEMENT_PAR_ETUDIANT ) . ' par étudiant inscrit.' ); ?></p>
			</div>
			<?php if ( $o['url'] ) : ?><a class="ipes-synthese__lien" href="<?php echo esc_url( $o['url'] ); ?>">Voir les IPES<?php echo ueb_icone( 'fleche', 16 ); ?></a><?php endif; ?>
		</header>
		<?php if ( ! $s['ipes'] ) : ?>
			<p class="ipes-synthese__vide"><?php echo ueb_icone( 'ecole', 18 ); ?>Aucun IPES dans ce périmètre.</p>
		<?php else : ?>
			<dl class="ipes-synthese__chiffres">
				<div><dt>IPES</dt><dd><?php echo (int) $s['ipes']; ?><?php if ( $s['actifs'] < $s['ipes'] ) : ?><small><?php echo esc_html( $s['actifs'] . ' actif' . ( $s['actifs'] > 1 ? 's' : '' ) ); ?></small><?php endif; ?></dd></div>
				<div><dt>Étudiants déclarés</dt><dd><?php echo esc_html( ueb_formater_montant( $s['etudiants'] ) ); ?></dd></div>
				<div><dt>Montant dû</dt><dd><?php echo esc_html( ueb_formater_montant( $du ) ); ?><small>FCFA</small></dd></div>
				<div><dt>Vérifié</dt><dd><?php echo esc_html( null === $taux ? '—' : ueb_pourcent( $taux ) ); ?><small><?php echo esc_html( ueb_fcfa( $verif ) ); ?></small></dd></div>
			</dl>
			<?php if ( $du ) : ?>
				<div class="suivi-barre ipes-synthese__barre" role="img" aria-label="<?php echo esc_attr( implode( ', ', array_map( static fn( $p ) => $p[0] . ' ' . ueb_fcfa( $p[1] ), $parts ) ) ); ?>">
					<?php foreach ( $parts as $cle => $p ) : if ( $p[1] <= 0 ) { continue; } ?>
						<span class="suivi-barre__part suivi-barre__part--<?php echo esc_attr( $cle ); ?>" style="--part: <?php echo esc_attr( round( 100 * $p[1] / $base, 3 ) ); ?>%" data-info="<?php echo esc_attr( $p[0] . ' : ' . ueb_fcfa( $p[1] ) ); ?>"></span>
					<?php endforeach; ?>
				</div>
				<ul class="ipes-synthese__legende">
					<?php foreach ( $parts as $cle => $p ) : ?>
						<li class="suivi-legende__item--<?php echo esc_attr( $cle ); ?>"><i aria-hidden="true"></i><?php echo esc_html( $p[0] ); ?> <b><?php echo esc_html( ueb_fcfa( $p[1] ) ); ?></b></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<p class="ipes-synthese__pied">
				<?php if ( $s['a_verifier'] ) : ?>
					<a class="ipes-synthese__a-verifier" href="<?php echo esc_url( $o['url'] ); ?>"><?php echo ueb_icone( 'horloge', 15 ); ?><?php echo esc_html( $s['a_verifier'] . ' bordereau' . ( $s['a_verifier'] > 1 ? 'x' : '' ) . ' à vérifier' ); ?></a>
				<?php else : ?>
					<span><?php echo ueb_icone( 'check', 15 ); ?>Aucun bordereau en attente de vérification.</span>
				<?php endif; ?>
			</p>
		<?php endif; ?>
	</section>
	<?php
}
