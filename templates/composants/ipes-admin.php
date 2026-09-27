<?php
/**
 * Onglet « IPES » de l'administration (page-administration.php, ?vue=ipes) :
 *   - sans « ipes » : la liste des IPES, avec ses filtres ;
 *   - ?ipes=nouveau : la fiche de création ;
 *   - ?ipes={id}    : la fiche d'un IPES, ses filières et ses comptes.
 *
 * Les formulaires postent sur les actions de inc/ipes.php et
 * inc/ipes-filieres.php, qui revérifient tout côté serveur.
 */
defined( 'ABSPATH' ) || exit;

$ipes_demande = sanitize_key( wp_unslash( $_GET['ipes'] ?? '' ) );

/** Date AAAA-MM-JJ affichée JJ/MM/AAAA, ou tiret. */
$ipes_date = static fn( $date ) => $date ? mysql2date( 'd/m/Y', $date ) : '—';
?>

<?php if ( '' === $ipes_demande ) : ?>

	<?php
	$filtres_ipes = array(
		'recherche'     => sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) ),
		'etablissement' => strtoupper( sanitize_key( wp_unslash( $_GET['tutelle'] ?? '' ) ) ),
		'etat'          => sanitize_key( wp_unslash( $_GET['etat'] ?? '' ) ),
	);
	$liste_ipes = ueb_ipes_liste( array(
		'recherche'     => $filtres_ipes['recherche'],
		'etablissement' => $filtres_ipes['etablissement'],
		'actif'         => array( 'actif' => 1, 'inactif' => 0 )[ $filtres_ipes['etat'] ] ?? '',
	) );
	$filtre_actif = '' !== $filtres_ipes['recherche'] || '' !== $filtres_ipes['etablissement'] || '' !== $filtres_ipes['etat'];
	?>

	<header class="page-app__entete">
		<div>
			<h1>Établissements sous tutelle (IPES)</h1>
			<p class="page-app__sous-titre">Instituts privés liés par convention à un ou plusieurs établissements de l’UEb.</p>
		</div>
		<a class="btn btn--primaire" href="<?php echo esc_url( ueb_url_ipes( 'nouveau' ) ); ?>"><?php echo ueb_icone( 'plus', 18 ); ?>Créer un IPES</a>
	</header>
	<?php ueb_afficher_flash(); ?>

	<form class="filtres carte" method="get" action="<?php echo esc_url( ueb_url_administration() ); ?>" role="search">
		<input type="hidden" name="vue" value="ipes">
		<div class="champ">
			<label for="ipes-q">Rechercher</label>
			<input id="ipes-q" type="search" name="q" value="<?php echo esc_attr( $filtres_ipes['recherche'] ); ?>" placeholder="Sigle ou nom de l’IPES" enterkeyhint="search" autocomplete="off">
		</div>
		<div class="champ">
			<label for="ipes-tutelle">Tutelle</label>
			<div class="champ__select">
				<select id="ipes-tutelle" name="tutelle">
					<option value="">Tous les établissements</option>
					<?php foreach ( ueb_etablissements() as $sigle => $e ) : ?>
						<option value="<?php echo esc_attr( $sigle ); ?>" <?php selected( $filtres_ipes['etablissement'], $sigle ); ?>><?php echo esc_html( $sigle . ' — ' . $e['fr'] ); ?></option>
					<?php endforeach; ?>
				</select><?php echo ueb_icone( 'chevron', 18 ); ?>
			</div>
		</div>
		<div class="champ">
			<label for="ipes-etat">État</label>
			<div class="champ__select">
				<select id="ipes-etat" name="etat">
					<option value="">Tous</option>
					<option value="actif" <?php selected( $filtres_ipes['etat'], 'actif' ); ?>>Actifs</option>
					<option value="inactif" <?php selected( $filtres_ipes['etat'], 'inactif' ); ?>>Désactivés</option>
				</select><?php echo ueb_icone( 'chevron', 18 ); ?>
			</div>
		</div>
		<button class="btn btn--primaire" type="submit"><?php echo ueb_icone( 'loupe', 18 ); ?>Rechercher</button>
	</form>

	<div class="tableau-conteneur">
		<table class="tableau ipes-tableau">
			<thead><tr><th>IPES</th><th>Tutelle</th><th>Ville</th><th>Convention</th><th>État</th><th><span class="sr">Actions</span></th></tr></thead>
			<tbody>
			<?php if ( ! $liste_ipes ) : ?>
				<tr><td colspan="6" class="texte-discret"><?php echo $filtre_actif ? 'Aucun IPES ne correspond à ces filtres.' : 'Aucun IPES enregistré pour l’instant.'; ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $liste_ipes as $ipes ) : $logo = ueb_ipes_logo_url( $ipes ); ?>
				<tr>
					<td>
						<span class="ipes-nom">
							<span class="ipes-logo" aria-hidden="true"><?php if ( $logo ) : ?><img src="<?php echo esc_url( $logo ); ?>" alt="" width="26" height="26" loading="lazy"><?php else : ?><?php echo ueb_icone( 'ecole', 18 ); ?><?php endif; ?></span>
							<span><b><?php echo esc_html( $ipes->sigle ); ?></b><br><small class="texte-discret"><?php echo esc_html( $ipes->nom_fr ); ?></small></span>
						</span>
					</td>
					<td>
						<?php foreach ( $ipes->tutelles as $sigle ) : $e = ueb_etablissement( $sigle ); ?>
							<span class="pastille-etab" style="--etab: <?php echo esc_attr( $e['couleur'] ?? 'var(--vert)' ); ?>" title="<?php echo esc_attr( $e['fr'] ?? $sigle ); ?>"><?php echo esc_html( $sigle ); ?></span>
						<?php endforeach; ?>
					</td>
					<td><?php echo esc_html( $ipes->ville ?: '—' ); ?></td>
					<td><?php echo esc_html( $ipes->convention_ref ?: '—' ); ?><?php if ( $ipes->convention_signee_le ) : ?><br><small class="texte-discret">signée le <?php echo esc_html( $ipes_date( $ipes->convention_signee_le ) ); ?></small><?php endif; ?></td>
					<td><?php echo (int) $ipes->actif ? '<span class="badge badge--verifie"><i></i>Actif</span>' : '<span class="badge badge--rejete"><i></i>Désactivé</span>'; ?></td>
					<td class="actions-ligne"><a class="btn btn--lien btn--petit" href="<?php echo esc_url( ueb_url_ipes( (int) $ipes->id ) ); ?>">Ouvrir<?php echo ueb_icone( 'fleche', 16 ); ?></a></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>

<?php endif; ?>
