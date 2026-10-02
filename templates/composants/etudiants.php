<?php
/**
 * Étudiants UEB : registre filtrable des étudiants inscrits, en lecture seule.
 * Partagé par l'administration, l'espace de gestion et l'espace scolarité
 * (ueb_vue_etudiants()). Attend $o : url, params, etabs.
 *
 * Les filtres forment un formulaire GET : sans JavaScript, « Rechercher »
 * recharge la page ; avec, chaque choix ne remplace que #etudiants-resultats
 * (data-filtres-direct, app.js). Onglets d'état, filtres actifs et pages sont
 * des liens data-filtre : ils marchent aussi sans JavaScript.
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

$etabs   = array_values( $o['etabs'] );
$params  = $o['params'] ?? array();
$f       = ueb_etudiants_filtres( $etabs );
$r       = ueb_etudiants( $f, $etabs );
$annees  = ueb_etudiants_annees();
$courant = ueb_annee_academique()['code'];
$libelle_annee = static fn( $code ) => str_replace( '-', ' – ', $code );

/* Adresse d'un lien : filtres courants (année en cours et page 1 omises) modifiés par $changes. */
$actuels = array_merge( $f, array( 'annee' => $f['annee'] === $courant ? '' : $f['annee'], 'p' => '' ) );
$lien    = static fn( array $changes ) => ueb_etudiants_url( $o['url'], array_merge( $params, $actuels, $changes ) );
/* Exports de la liste affichée : mêmes filtres, toutes les pages. */
$formats = array( 'pdf' => array( 'PDF', 'Rapport PDF, prêt à imprimer' ), 'docx' => array( 'Word', 'Rapport Word, modifiable' ), 'xlsx' => array( 'Excel', 'Tableur Excel, données brutes' ) );

/* Libellé d'une filière retenue (pour son filtre actif). */
$nom_filiere = '';
foreach ( $r['filieres'] as $liste ) {
	if ( isset( $liste[ $f['filiere'] ] ) ) {
		$nom_filiere = $liste[ $f['filiere'] ][0];
	}
}
$actifs = array_filter( array(
	'etab'      => $f['etab'] ? ( ueb_etablissement( $f['etab'] )['sigle'] ?? $f['etab'] ) : '',
	'filiere'   => $f['filiere'] ? ( $nom_filiere ?: 'Filière choisie' ) : '',
	'niveau'    => $f['niveau'] ? UEB_NIVEAUX_INSCRIPTION[ $f['niveau'] ] : '',
	'sexe'      => array( 'F' => 'Femmes', 'M' => 'Hommes' )[ $f['sexe'] ] ?? '',
	'situation' => UEB_ETUDIANTS_SITUATIONS[ $f['situation'] ] ?? '',
	'q'         => '' !== $f['q'] ? 'Recherche « ' . $f['q'] . ' »' : '',
	'annee'     => $f['annee'] !== $courant ? 'Année ' . $libelle_annee( $f['annee'] ) : '',
) );
$effacer  = 'q etab filiere niveau sexe situation statut annee p';
$onglets  = array( '' => array( 'Tous', $r['compteurs']['tous'] ) );
foreach ( UEB_ETUDIANTS_STATUTS as $cle => $libelle ) {
	$onglets[ $cle ] = array( $libelle, $r['compteurs'][ $cle ] );
}
$fmt = static fn( $n ) => ueb_formater_montant( (int) $n );
?>
<section class="adm-panneau etu" aria-labelledby="etu-titre">
	<header class="etu-tete">
		<div>
			<h2 id="etu-titre">Registre des inscrits</h2>
			<p>Étudiants qui ont préparé au moins un quitus de droits pour <?php echo esc_html( $libelle_annee( $f['annee'] ) ); ?><?php echo 1 === count( $etabs ) ? ', ' . esc_html( ueb_etablissement( $etabs[0] )['fr'] ?? $etabs[0] ) : ''; ?>. Lecture seule.</p>
		</div>
	</header>

	<form id="etu-form" class="etu-filtres" method="get" action="<?php echo esc_url( $o['url'] ); ?>" role="search" aria-label="Filtrer les étudiants" data-filtres-direct="etudiants-resultats" data-etudiants-filtres>
		<?php foreach ( $params as $cle => $valeur ) : ?>
			<input type="hidden" name="<?php echo esc_attr( $cle ); ?>" value="<?php echo esc_attr( $valeur ); ?>">
		<?php endforeach; ?>
		<input type="hidden" name="statut" value="<?php echo esc_attr( $f['statut'] ); ?>">
		<input type="hidden" name="p" value="">
		<label class="ipes-recherche etu-recherche"><span class="sr">Rechercher un étudiant</span><?php echo ueb_icone( 'loupe', 17 ); ?><input type="search" name="q" value="<?php echo esc_attr( $f['q'] ); ?>" placeholder="Nom, prénom ou matricule" enterkeyhint="search" autocomplete="off"></label>
		<?php if ( count( $annees ) > 1 ) : ?>
			<label class="ipes-selecteur"><span class="sr">Année académique</span>
				<select name="annee">
					<?php foreach ( $annees as $code ) : ?>
						<option value="<?php echo esc_attr( $code === $courant ? '' : $code ); ?>" <?php selected( $f['annee'], $code ); ?>><?php echo esc_html( $libelle_annee( $code ) ); ?></option>
					<?php endforeach; ?>
				</select><?php echo ueb_icone( 'chevron', 16 ); ?>
			</label>
		<?php endif; ?>
		<?php if ( count( $etabs ) > 1 ) : ?>
			<label class="ipes-selecteur"><span class="sr">Établissement</span>
				<select name="etab" data-etu-etab>
					<option value="">Tous les établissements</option>
					<?php foreach ( $etabs as $sigle ) : ?>
						<option value="<?php echo esc_attr( strtolower( $sigle ) ); ?>" <?php selected( $f['etab'], $sigle ); ?>><?php echo esc_html( $sigle ); ?></option>
					<?php endforeach; ?>
				</select><?php echo ueb_icone( 'chevron', 16 ); ?>
			</label>
		<?php endif; ?>
		<label class="ipes-selecteur etu-selecteur--large"><span class="sr">Filière</span>
			<select name="filiere" data-etu-filiere>
				<option value="">Toutes les filières</option>
				<?php foreach ( $r['filieres'] as $sigle => $liste ) : ?>
					<optgroup label="<?php echo esc_attr( $sigle ); ?>" data-etab="<?php echo esc_attr( strtolower( $sigle ) ); ?>">
						<?php foreach ( $liste as $id => list( $libelle, $n ) ) : ?>
							<option value="<?php echo (int) $id; ?>" <?php selected( $f['filiere'], $id ); ?>><?php echo esc_html( $libelle . ' (' . $n . ')' ); ?></option>
						<?php endforeach; ?>
					</optgroup>
				<?php endforeach; ?>
			</select><?php echo ueb_icone( 'chevron', 16 ); ?>
		</label>
		<label class="ipes-selecteur"><span class="sr">Niveau</span>
			<select name="niveau">
				<option value="">Tous les niveaux</option>
				<?php foreach ( UEB_NIVEAUX_INSCRIPTION as $code => $libelle ) : ?>
					<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $f['niveau'], $code ); ?>><?php echo esc_html( $code ); ?></option>
				<?php endforeach; ?>
			</select><?php echo ueb_icone( 'chevron', 16 ); ?>
		</label>
		<label class="ipes-selecteur"><span class="sr">Sexe</span>
			<select name="sexe">
				<option value="">Femmes et hommes</option>
				<option value="F" <?php selected( $f['sexe'], 'F' ); ?>>Femmes</option>
				<option value="M" <?php selected( $f['sexe'], 'M' ); ?>>Hommes</option>
			</select><?php echo ueb_icone( 'chevron', 16 ); ?>
		</label>
		<label class="ipes-selecteur"><span class="sr">Situation</span>
			<select name="situation">
				<option value="">Toutes les situations</option>
				<?php foreach ( UEB_ETUDIANTS_SITUATIONS as $cle => $libelle ) : ?>
					<option value="<?php echo esc_attr( $cle ); ?>" <?php selected( $f['situation'], $cle ); ?>><?php echo esc_html( $libelle ); ?></option>
				<?php endforeach; ?>
			</select><?php echo ueb_icone( 'chevron', 16 ); ?>
		</label>
		<button class="adm-bouton" type="submit" data-filtres-bouton><?php echo ueb_icone( 'loupe', 16 ); ?>Rechercher</button>
	</form>

	<div id="etudiants-resultats" class="etu-resultats" aria-live="polite">
		<nav class="etu-onglets" aria-label="État des droits de l’année">
			<?php foreach ( $onglets as $cle => list( $libelle, $n ) ) : ?>
				<a href="<?php echo esc_url( $lien( array( 'statut' => $cle ) ) ); ?>" data-filtre="statut" data-valeur="<?php echo esc_attr( $cle ); ?>"<?php echo $f['statut'] === $cle ? ' aria-current="true"' : ''; ?> class="etu-onglet<?php echo $cle ? ' etu-onglet--' . esc_attr( $cle ) : ''; ?>"><?php echo esc_html( $libelle ); ?><span><?php echo (int) $n; ?></span></a>
			<?php endforeach; ?>
		</nav>

		<?php if ( $actifs ) : ?>
			<div class="etu-actifs" aria-label="Filtres actifs">
				<?php foreach ( $actifs as $cle => $libelle ) : ?>
					<a class="etu-actif" href="<?php echo esc_url( $lien( array( $cle => '' ) ) ); ?>" data-filtre="<?php echo esc_attr( $cle ); ?>" data-valeur=""><?php echo esc_html( $libelle ); ?><?php echo ueb_icone( 'croix', 14 ); ?><span class="sr"> : retirer ce filtre</span></a>
				<?php endforeach; ?>
				<a class="etu-effacer" href="<?php echo esc_url( add_query_arg( $params, $o['url'] ) ); ?>" data-filtre-effacer="<?php echo esc_attr( $effacer ); ?>">Tout effacer</a>
			</div>
		<?php endif; ?>

		<div class="etu-outils">
			<p class="etu-compte"><b><?php echo esc_html( ueb_ipes_pluriel( $r['total'], 'étudiant' ) ); ?></b><?php echo $r['pages'] > 1 ? esc_html( sprintf( ', page %d sur %d', $r['page'], $r['pages'] ) ) : ''; ?></p>
			<?php if ( $r['total'] ) : ?>
				<div class="etu-exports" role="group" aria-label="Exporter la liste affichée, toutes les pages">
					<span class="etu-exports__libelle"><?php echo ueb_icone( 'telecharger', 16 ); ?>Exporter</span>
					<?php foreach ( $formats as $format => list( $nom, $titre ) ) : ?>
						<a class="etu-export etu-export--<?php echo esc_attr( $format ); ?>" href="<?php echo esc_url( ueb_etudiants_export_url( $o['url'], array_merge( $params, $actuels ), $format ) ); ?>" title="<?php echo esc_attr( $titre ); ?>"><span class="sr">Exporter la liste en </span><?php echo esc_html( $nom ); ?></a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>

		<?php if ( ! $r['lignes'] ) : ?>
			<div class="bo-vide etu-vide">
				<span><?php echo ueb_icone( 'diplome', 22 ); ?></span>
				<p><?php echo $r['compteurs']['tous'] || $actifs || $f['statut'] ? '<b>Aucun étudiant ne correspond à ces filtres.</b> Retire un filtre pour élargir la liste.' : '<b>Aucun étudiant inscrit pour l’instant.</b> La liste se remplit dès les premiers quitus de droits de l’année.'; ?></p>
			</div>
		<?php else : ?>
			<table class="etu-table">
				<caption class="sr">Étudiants inscrits, triés par nom</caption>
				<thead>
					<tr><th scope="col">Étudiant</th><th scope="col">Établissement</th><th scope="col">Filière</th><th scope="col">Niveau</th><th scope="col">Droits de l’année</th><th scope="col">État</th></tr>
				</thead>
				<tbody>
					<?php foreach ( $r['lignes'] as $e ) :
						$verifie = (int) $e->verifie;
						$attente = (int) $e->en_verification;
						$part    = static fn( $n ) => round( min( 1, $n / UEB_DROITS_CLASSIQUES ), 4 );
						$etat    = array( 'solde' => 'Droits soldés', 'partiel' => 'Partiel', 'aucun' => 'Aucun vérifié' )[ $e->etat ];
						?>
						<tr>
							<th scope="row" class="etu-nom">
								<b><?php echo esc_html( $e->nom . ' ' . $e->prenom ); ?></b>
								<small><?php echo esc_html( 'Matricule ' . $e->identifiant ); ?></small>
							</th>
							<td data-libelle="Établissement" class="etu-etab"><img src="<?php echo esc_url( ueb_logo_url( $e->etablissement ) ); ?>" alt="" width="22" height="22" loading="lazy"><?php echo esc_html( $e->etablissement ); ?></td>
							<td data-libelle="Filière"><?php echo esc_html( $e->departement ); ?></td>
							<td data-libelle="Niveau" class="etu-niveau"><?php echo esc_html( $e->parcours ); ?></td>
							<td data-libelle="Droits de l’année" class="etu-droits">
								<span class="etu-barre" aria-hidden="true">
									<i class="etu-barre__attente" style="--part: <?php echo esc_attr( $part( $verifie + $attente ) ); ?>"></i>
									<i class="etu-barre__verifie" style="--part: <?php echo esc_attr( $part( $verifie ) ); ?>"></i>
								</span>
								<span class="etu-droits__texte"><b><?php echo esc_html( $fmt( $verifie ) ); ?></b> sur 50 000 FCFA vérifiés<?php echo $attente ? '<small>' . esc_html( $fmt( $attente ) ) . ' FCFA en vérification</small>' : ''; ?></span>
							</td>
							<td data-libelle="État" class="etu-etat">
								<span class="etu-badge etu-badge--<?php echo esc_attr( $e->etat ); ?>"><?php echo esc_html( $etat ); ?></span>
								<?php if ( (int) $e->a_verifier ) : ?><span class="etu-note"><?php echo ueb_icone( 'horloge', 14 ); ?>Reçu à vérifier</span><?php endif; ?>
								<?php if ( (int) $e->a_corriger ) : ?><span class="etu-note etu-note--corriger"><?php echo ueb_icone( 'alerte', 14 ); ?>Reçu à corriger</span><?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $r['pages'] > 1 ) : ?>
				<nav class="etu-pages" aria-label="Pages de la liste">
					<?php if ( $r['page'] > 1 ) : ?>
						<a class="adm-bouton" href="<?php echo esc_url( $lien( array( 'p' => $r['page'] - 1 ) ) ); ?>" data-filtre="p" data-valeur="<?php echo (int) $r['page'] - 1; ?>"><?php echo ueb_icone( 'fleche-g', 16 ); ?>Précédente</a>
					<?php endif; ?>
					<span>Page <?php echo (int) $r['page']; ?> sur <?php echo (int) $r['pages']; ?></span>
					<?php if ( $r['page'] < $r['pages'] ) : ?>
						<a class="adm-bouton" href="<?php echo esc_url( $lien( array( 'p' => $r['page'] + 1 ) ) ); ?>" data-filtre="p" data-valeur="<?php echo (int) $r['page'] + 1; ?>">Suivante<?php echo ueb_icone( 'fleche', 16 ); ?></a>
					<?php endif; ?>
				</nav>
			<?php endif; ?>
		<?php endif; ?>
	</div>
</section>
