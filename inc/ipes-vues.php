<?php
/**
 * Composants d'affichage des IPES, partagés par l'onglet IPES de
 * l'administration (templates/composants/ipes-admin.php), l'espace IPES
 * (page-ipes.php) et la vue IPES de la scolarité (scolarite-ipes.php) :
 * héros des reversements, registre des IPES, liste des bordereaux, décision
 * de l'UEb, statut, logo et pastilles de tutelle.
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

/** « 1 versement », « 3 versements ». */
function ueb_ipes_pluriel( $n, $mot, $pluriel = null ) {
	return (int) $n . ' ' . ( (int) $n > 1 ? ( $pluriel ?? $mot . 's' ) : $mot );
}

/* ---------- Héros des reversements ---------- */

/**
 * Le chiffre fort de l'écran : ce que l'UEb a vérifié des reversements de
 * l'année, avec la jauge Remotion (vérifié / montant annuel dû) quand ce
 * montant est connu. Barre en quatre parts de la même base : vérifié, en
 * vérification, encaissé pas encore reversé, reste.
 *
 * @param object $ipes IPES (ueb_ipes).
 * @param array  $o    titre, intro, lien (array( url, libellé )), pour
 *                     ('ipes' : l'IPES parle de « ta tutelle » ; 'ueb' sinon).
 */
function ueb_ipes_hero( $ipes, array $o = array() ) {
	$annee  = ueb_annee_academique();
	$o      = array_merge( array( 'titre' => 'Reversements ' . $annee['libelle'], 'intro' => '', 'lien' => null, 'pour' => 'ueb' ), $o );
	$totaux = ueb_ipes_totaux( $ipes->id );
	$jauge  = ueb_ipes_jauge( $ipes->id );
	$du     = $jauge['du'];

	/* Parts successives, bornées à la base : jamais plus de 100 % au total. */
	$base    = null !== $du ? $du : max( 1, (int) $totaux['encaisse'] );
	$reste_b = $base;
	$parts   = array();
	foreach ( array(
		'encaisse'     => array( 'Vérifié par l’UEb', $jauge['verifie'] ),
		'verification' => array( 'En vérification', max( 0, $jauge['envoye'] - $jauge['verifie'] ) ),
		'declare'      => array( 'Pas encore reversé', max( 0, (int) $totaux['encaisse'] - $jauge['envoye'] ) ),
	) as $cle => $p ) {
		$v              = min( $reste_b, max( 0, (int) $p[1] ) );
		$reste_b       -= $v;
		$parts[ $cle ] = array( $p[0], $v );
	}
	if ( null !== $du ) {
		$parts['non_declare'] = array( 'Pas encore encaissé', $reste_b );
	}
	$taux = null !== $du && $du > 0 ? min( 100, round( 100 * $jauge['verifie'] / $du, 1 ) ) : null;
	$a_verifier = count( array_filter( ueb_ipes_bordereaux( $ipes->id ), static fn( $b ) => 'envoye' === $b->statut ) );
	$tutelle    = 'ipes' === $o['pour'] ? 'ta tutelle' : ( 1 === count( $ipes->tutelles ) ? 'sa tutelle' : 'ses tutelles' );
	?>
	<section class="adm-hero ipes-hero<?php echo null === $taux ? ' ipes-hero--sans-jauge' : ''; ?>" aria-labelledby="ipes-hero-titre">
		<?php if ( null !== $taux ) : ?>
			<div class="adm-hero__visuel">
				<?php
				ueb_animation(
					'jauge',
					array( 'taux' => $taux, 'libelle' => 'vérifiés' ),
					'animation--jauge',
					'Reversements vérifiés : ' . ueb_pourcent( $taux ) . ' du montant annuel dû',
					ueb_bord_jauge_repli( $taux, 'vérifiés' )
				);
				?>
			</div>
		<?php endif; ?>

		<div class="adm-hero__corps">
			<header class="adm-hero__tete">
				<div>
					<h2 id="ipes-hero-titre"><?php echo esc_html( $o['titre'] ); ?></h2>
					<p><?php echo esc_html( $o['intro'] ?: 'Seuls les bordereaux vérifiés par l’UEb comptent comme reversés à ' . $tutelle . '.' ); ?></p>
				</div>
				<?php if ( $o['lien'] ) : ?>
					<a class="adm-hero__lien" href="<?php echo esc_url( $o['lien'][0] ); ?>"><?php echo esc_html( $o['lien'][1] ); ?><?php echo ueb_icone( 'fleche', 16 ); ?></a>
				<?php endif; ?>
			</header>

			<?php if ( ! $totaux['encaisse'] && ! $jauge['envoye'] ) : ?>
				<p class="adm-hero__vide"><?php echo ueb_icone( 'banque', 20 ); ?><?php echo 'ipes' === $o['pour'] ? 'Aucun versement cette année. Ajoute tes étudiants et leurs versements : le suivi des reversements apparaîtra ici.' : 'Aucun versement déclaré par l’IPES cette année pour l’instant.'; ?></p>
			<?php else : ?>
				<p class="adm-hero__montant">
					<b><?php echo esc_html( ueb_formater_montant( $jauge['verifie'] ) ); ?><small>FCFA</small></b>
					<span>
						<?php
						echo esc_html( null !== $du
							? 'vérifiés sur ' . ueb_fcfa( $du ) . ' dus pour l’année (montant indicatif). Reste à percevoir : ' . ueb_fcfa( $jauge['reste'] ) . '.'
							: 'vérifiés sur ' . ueb_fcfa( $totaux['encaisse'] ) . ' de pensions encaissées. Le montant annuel dû n’est pas encore renseigné.' );
						?>
					</span>
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
					<div><dt>Étudiants de l’année</dt><dd><?php echo esc_html( ueb_formater_montant( $totaux['etudiants'] ) ); ?></dd></div>
					<div><dt>Pensions encaissées</dt><dd><?php echo esc_html( ueb_adm_montant_court( $totaux['encaisse'] ) ); ?><small>FCFA</small></dd></div>
					<div><dt>Reversé à <?php echo esc_html( $tutelle ); ?></dt><dd><?php echo esc_html( ueb_adm_montant_court( $jauge['envoye'] ) ); ?><small>FCFA</small></dd></div>
					<div><dt><?php echo 'ipes' === $o['pour'] ? 'Bordereaux en vérification' : 'Bordereaux à vérifier'; ?></dt><dd><?php echo (int) $a_verifier; ?></dd></div>
				</dl>
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
 * (barre sur le montant dû), ce qui attend une décision. Toute la ligne
 * mène à la fiche (lien étiré), même après un filtrage en direct.
 *
 * @param array $liste IPES (ueb_ipes_liste ou ueb_ipes_sous_tutelle).
 * @param array $o     url (callable $ipes => adresse de la fiche),
 *                     a_verifier (callable $ipes => nombre de bordereaux à vérifier),
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
			$totaux = ueb_ipes_totaux( $ipes->id );
			$jauge  = ueb_ipes_jauge( $ipes->id );
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
				<td class="num ipes-ligne__nombre" data-titre="Étudiants"><b><?php echo (int) $totaux['etudiants']; ?></b></td>
				<td class="ipes-ligne__mesure" data-titre="Reversements vérifiés">
					<?php if ( null !== $taux ) : ?>
						<span class="ipes-mesure">
							<span class="ipes-mesure__barre" aria-hidden="true"><i style="--part: <?php echo esc_attr( round( $taux, 2 ) ); ?>%"></i></span>
							<b><?php echo esc_html( ueb_pourcent( $taux ) ); ?></b>
						</span>
						<small><?php echo esc_html( ueb_fcfa( $jauge['verifie'] ) . ' sur ' . ueb_fcfa( $jauge['du'] ) ); ?></small>
					<?php elseif ( $jauge['verifie'] ) : ?>
						<b class="ipes-ligne__montant"><?php echo esc_html( ueb_fcfa( $jauge['verifie'] ) ); ?></b>
						<small>Montant dû non renseigné</small>
					<?php else : ?>
						<span class="ipes-ligne__rien">Aucun reversement vérifié</span>
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
 * Étudiants d'un IPES : identité (initiales, nom, matricule), filière et
 * niveau, versements, total payé. Toute la ligne mène au détail.
 *
 * @param array    $liste Étudiants (ueb_ipes_etudiants).
 * @param callable $url   $e => adresse du détail.
 * @param string   $aller Libellé accessible du lien (« Ouvrir », « Versements de »).
 */
function ueb_ipes_etudiants_liste( array $liste, callable $url, $aller = 'Ouvrir la fiche de' ) {
	?>
	<table class="adm-registre__table ipes-table ipes-table--etudiants">
		<thead><tr>
			<th scope="col">Étudiant</th>
			<th scope="col">Filière</th>
			<th scope="col" class="num">Versements</th>
			<th scope="col" class="num">Total payé</th>
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
				<td class="num ipes-ligne__nombre" data-titre="Versements"><b><?php echo (int) $e->nb_versements; ?></b></td>
				<td class="num ipes-ligne__montant" data-titre="Total payé"><?php if ( (int) $e->total_paye ) : ?><?php echo esc_html( ueb_formater_montant( (int) $e->total_paye ) ); ?> <small>FCFA</small><?php else : ?><span class="ipes-ligne__rien">Aucun versement</span><?php endif; ?></td>
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
			/* Envoyé : total figé ; brouillon ou rejeté : somme des versements cochés. */
			$montant = isset( $b->montant_coche ) && in_array( $b->statut, UEB_IPES_BORDEREAU_MODIFIABLE, true ) ? (int) $b->montant_coche : (int) $b->total;
			$details = array( ueb_ipes_pluriel( $b->nb_versements, 'versement' ) );
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
 * PDF et décision de l'UEb sur un bordereau : « Vérifié » (confirmé), ou
 * « Rejeter » avec un motif dans une fenêtre. La décision n'est proposée que
 * pour un bordereau envoyé et si $peut_decider.
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
	if ( 'envoye' === $b->statut && $o['peut_decider'] ) : ?>
		<button class="adm-bouton adm-bouton--petit" type="button" data-ouvrir-agent-mdp="rejet-<?php echo $id; ?>">Rejeter</button>
		<form method="post" action="<?php echo esc_url( $o['url'] ); ?>" data-confirmer="<?php echo esc_attr( 'Marquer le bordereau ' . $b->numero . ' (' . ueb_fcfa( $b->total ) . ') comme vérifié ? La décision est définitive.' ); ?>">
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
				<label><span>Motif du rejet</span><textarea name="motif" rows="3" minlength="5" maxlength="255" required placeholder="Par exemple : le versement de Paul Mbarga ne figure pas sur le relevé bancaire."></textarea></label>
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
