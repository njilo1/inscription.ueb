<?php
/** Bandeau de l’équipe, registre et création des comptes du personnel de l’établissement courant. */
defined( 'ABSPATH' ) || exit;

$personnel_etab      = $etab['fr'] ?? 'ton établissement';
$personnel_total     = count( $cellules );
$personnel_suspendus = 0;
$personnel_etats     = array();
$personnel_dernier   = 0;
foreach ( $cellules as $cellule ) {
	$personnel_etats[ $cellule->ID ] = ueb_agent_suspendu( $cellule->ID );
	$personnel_suspendus += (int) $personnel_etats[ $cellule->ID ];
	$personnel_dernier    = max( $personnel_dernier, (int) strtotime( $cellule->user_registered . ' UTC' ) );
}
/* La recherche et les filtres n’apparaissent que si la liste ne se lit plus d’un coup d’œil. */
$personnel_outils = $personnel_total >= 4;

/* Accès de chaque rôle, dans l’ordre de la liste blanche des permissions. */
$personnel_acces = array();
foreach ( $roles_creables as $personnel_slug => $personnel_definition ) {
	$personnel_acces[ $personnel_slug ] = array();
	foreach ( ueb_permissions() as $personnel_cap => $personnel_permission ) {
		if ( in_array( $personnel_cap, $personnel_definition['permissions'], true ) ) {
			$personnel_acces[ $personnel_slug ][] = $personnel_permission;
		}
	}
}

/* Saisie rendue après un échec de création (voir ueb_action_gestion_creer_cellule). */
$personnel_saisie = $_SESSION['ueb_cellule_saisie'] ?? array();
unset( $_SESSION['ueb_cellule_saisie'] );
/* L'erreur va sous le champ qu'elle concerne quand on le reconnaît, sinon en tête du formulaire. */
$personnel_erreur = (string) ( $personnel_saisie['erreur'] ?? '' );
$personnel_erreur_champ = '';
if ( false !== stripos( $personnel_erreur, 'identifiant' ) ) {
	$personnel_erreur_champ = 'login';
} elseif ( false !== stripos( $personnel_erreur, 'e-mail' ) ) {
	$personnel_erreur_champ = 'email';
}
$personnel_erreur_de = static fn( $champ ) => $champ === $personnel_erreur_champ ? $personnel_erreur : '';
$personnel_role_choisi = $personnel_saisie['role'] ?? ueb_role_par_defaut( UEB_CAP_COMPTES );
if ( ! isset( $roles_creables[ $personnel_role_choisi ] ) ) {
	$personnel_role_choisi = array_key_first( $roles_creables ) ?? '';
}

$personnel_nouveau = $prov_cellule ? get_user_by( 'login', $prov_cellule['compte'] ) : null;
$personnel_mois    = array( 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.' );
$personnel_date    = static fn( $t ) => wp_date( 'j', $t ) . ' ' . $personnel_mois[ (int) wp_date( 'n', $t ) - 1 ] . ' ' . wp_date( 'Y', $t );
/* Une teinte stable par personne, prise dans la charte, pour reconnaître les avatars. */
$personnel_teinte  = static fn( $login ) => 'personnel-avatar--t' . ( crc32( $login ) % 5 );
?>
<div class="personnel" data-personnel>
	<header class="personnel-bandeau">
		<div class="personnel-bandeau__haut">
			<?php if ( $etab ) : ?><span class="personnel-bandeau__sceau"><img src="<?php echo esc_url( ueb_logo_url( $etab['sigle'] ) ); ?>" alt="" width="46" height="46"></span><?php endif; ?>
			<div class="personnel-bandeau__texte">
				<p class="personnel-bandeau__contexte"><span><?php echo esc_html( $etab['sigle'] ?? 'UEB' ); ?></span><span class="personnel-bandeau__annee">Année <?php echo esc_html( $annee['libelle'] ); ?></span></p>
				<h1>Comptes du personnel</h1>
				<p class="personnel-bandeau__intro">Les accès de ton équipe. Chacun reçoit un rôle aux droits inférieurs aux tiens et reste rattaché à ton établissement.</p>
			</div>
			<?php if ( $roles_creables ) : ?>
				<a class="personnel-bandeau__action" href="#personnel-creation" data-personnel-ouvrir><?php echo ueb_icone( 'ajout-compte', 18 ); ?>Nouveau compte</a>
			<?php endif; ?>
		</div>
		<?php if ( $cellules ) : /* sans membre, le registre invite à créer le premier compte */ ?>
		<div class="personnel-bandeau__equipe">
			<span class="personnel-pile" aria-hidden="true">
				<?php foreach ( array_slice( $cellules, 0, 5 ) as $personnel_i => $cellule ) : ?>
					<span class="personnel-avatar <?php echo esc_attr( $personnel_teinte( $cellule->user_login ) ); ?>" style="--i:<?php echo (int) $personnel_i; ?>"><?php echo esc_html( ueb_initiales( $cellule->display_name ?: $cellule->user_login, '' ) ); ?></span>
				<?php endforeach; ?>
				<?php if ( $personnel_total > 5 ) : ?><span class="personnel-avatar personnel-pile__reste" style="--i:5">+<?php echo (int) ( $personnel_total - 5 ); ?></span><?php endif; ?>
			</span>
			<?php $personnel_actifs = $personnel_total - $personnel_suspendus; ?>
			<p class="personnel-faits">
				<span><b><?php echo (int) $personnel_total; ?></b> membre<?php echo $personnel_total > 1 ? 's' : ''; ?></span>
				<span class="personnel-faits__actifs"><i aria-hidden="true"></i><b><?php echo (int) $personnel_actifs; ?></b> actif<?php echo $personnel_actifs > 1 ? 's' : ''; ?></span>
				<span class="personnel-faits__suspendus"><i aria-hidden="true"></i><b><?php echo (int) $personnel_suspendus; ?></b> suspendu<?php echo $personnel_suspendus > 1 ? 's' : ''; ?></span>
				<?php if ( $personnel_dernier ) : ?><span>Dernier ajout le <b><?php echo esc_html( $personnel_date( $personnel_dernier ) ); ?></b></span><?php endif; ?>
			</p>
		</div>
		<?php endif; ?>
	</header>

	<?php if ( $prov_cellule ) :
		$personnel_nouveau_nom  = $personnel_nouveau ? ( $personnel_nouveau->display_name ?: $personnel_nouveau->user_login ) : $prov_cellule['compte'];
		$personnel_nouveau_role = $personnel_nouveau ? ( $roles_creables[ ueb_role_du_compte( $personnel_nouveau->ID ) ]['nom'] ?? '' ) : '';
		?>
		<section class="personnel-remise" aria-labelledby="personnel-remise-titre" role="status" data-personnel-remise>
			<div class="personnel-remise__compte">
				<span class="personnel-avatar personnel-avatar--remise <?php echo esc_attr( $personnel_teinte( $prov_cellule['compte'] ) ); ?>" aria-hidden="true"><?php echo esc_html( ueb_initiales( $personnel_nouveau_nom, '' ) ); ?><span class="personnel-remise__fait"><?php echo ueb_icone( 'check', 12 ); ?></span></span>
				<div>
					<h2 id="personnel-remise-titre">Le compte de <?php echo esc_html( $personnel_nouveau_nom ); ?> est prêt</h2>
					<dl class="personnel-remise__details">
						<div><dt>Identifiant</dt><dd><?php echo esc_html( $prov_cellule['compte'] ); ?></dd></div>
						<?php if ( $personnel_nouveau_role ) : ?><div><dt>Rôle</dt><dd><?php echo esc_html( $personnel_nouveau_role ); ?></dd></div><?php endif; ?>
					</dl>
				</div>
			</div>
			<div class="personnel-remise__secret">
				<p class="personnel-remise__libelle">Mot de passe provisoire</p>
				<p class="personnel-mdp"><span class="sr"><?php echo esc_html( $prov_cellule['mdp'] ); ?></span><?php
				foreach ( mb_str_split( $prov_cellule['mdp'] ) as $personnel_i => $personnel_car ) {
					printf( '<span class="personnel-mdp__car%s" style="--i:%d" aria-hidden="true">%s</span>', ctype_digit( $personnel_car ) ? ' personnel-mdp__car--chiffre' : '', (int) $personnel_i, esc_html( $personnel_car ) );
				}
				?></p>
				<button type="button" class="personnel-copier" data-copier-mot-de-passe="<?php echo esc_attr( $prov_cellule['mdp'] ); ?>"><?php echo ueb_icone( 'copier', 17, 'personnel-copier__a-faire' ); ?><?php echo ueb_icone( 'check', 17, 'personnel-copier__fait' ); ?><span>Copier le mot de passe</span></button>
			</div>
			<p class="personnel-remise__note"><?php echo ueb_icone( 'cadenas', 16 ); ?><span>Il ne s’affichera plus après cette page. Transmets-le au responsable, qui pourra le changer depuis sa page Sécurité.</span></p>
		</section>
	<?php endif; ?>

	<section class="personnel-registre" aria-labelledby="titre-cellules">
		<header class="personnel-registre__tete">
			<h2 id="titre-cellules">Personnel rattaché <span class="personnel-total"><?php echo (int) $personnel_total; ?></span></h2>
			<?php if ( $personnel_outils ) : ?>
				<div class="personnel-outils" data-personnel-outils hidden>
					<label class="personnel-recherche" for="personnel-recherche"><span class="sr">Rechercher un nom, un identifiant, un e-mail ou un rôle</span><?php echo ueb_icone( 'loupe', 17 ); ?><input type="search" id="personnel-recherche" placeholder="Nom, identifiant, e-mail" autocomplete="off" aria-controls="personnel-liste" data-personnel-recherche></label>
					<div class="personnel-filtres" role="group" aria-label="Filtrer les comptes par statut">
						<button type="button" aria-pressed="true" aria-controls="personnel-liste" data-personnel-statut="tous">Tous <span><?php echo (int) $personnel_total; ?></span></button>
						<button type="button" aria-pressed="false" aria-controls="personnel-liste" data-personnel-statut="actif">Actifs <span><?php echo (int) ( $personnel_total - $personnel_suspendus ); ?></span></button>
						<button type="button" aria-pressed="false" aria-controls="personnel-liste" data-personnel-statut="suspendu">Suspendus <span><?php echo (int) $personnel_suspendus; ?></span></button>
					</div>
				</div>
			<?php endif; ?>
		</header>

		<?php if ( $cellules ) : ?>
			<div class="personnel-table-conteneur">
				<table class="personnel-table" id="personnel-liste">
					<caption class="sr">Comptes du personnel rattachés à <?php echo esc_html( $personnel_etab ); ?></caption>
					<thead><tr><th scope="col">Membre</th><th scope="col">Rôle et accès</th><th scope="col">Créé le</th><th scope="col">Statut</th></tr></thead>
					<tbody>
					<?php foreach ( $cellules as $cellule ) :
						$personnel_nom  = $cellule->display_name ?: $cellule->user_login;
						$personnel_slug = ueb_role_du_compte( $cellule->ID );
						$personnel_role = $roles_creables[ $personnel_slug ]['nom'] ?? '';
						$suspendu       = $personnel_etats[ $cellule->ID ];
						$personnel_neuf = $personnel_nouveau && $personnel_nouveau->ID === $cellule->ID;
						$personnel_cree = strtotime( $cellule->user_registered . ' UTC' );
						?>
						<tr data-personnel-ligne data-statut="<?php echo $suspendu ? 'suspendu' : 'actif'; ?>"<?php echo $personnel_neuf ? ' class="est-nouveau"' : ''; ?>>
							<td>
								<div class="personnel-identite">
									<span class="personnel-avatar <?php echo esc_attr( $personnel_teinte( $cellule->user_login ) ); ?>" aria-hidden="true"><?php echo esc_html( ueb_initiales( $personnel_nom, '' ) ); ?></span>
									<div>
										<b><?php echo esc_html( $personnel_nom ); ?><?php if ( $personnel_neuf ) : ?> <span class="personnel-neuf">Nouveau</span><?php endif; ?></b>
										<span class="personnel-identite__ligne"><?php echo ueb_icone( 'utilisateur', 13 ); ?><span class="sr">Identifiant</span><?php echo esc_html( $cellule->user_login ); ?></span>
										<?php if ( $cellule->user_email ) : ?><span class="personnel-identite__ligne"><?php echo ueb_icone( 'courriel', 13 ); ?><span class="sr">E-mail</span><?php echo esc_html( $cellule->user_email ); ?></span><?php endif; ?>
									</div>
								</div>
							</td>
							<td class="personnel-table__role">
								<b><?php echo esc_html( $personnel_role ); ?></b>
								<?php if ( ! empty( $personnel_acces[ $personnel_slug ] ) ) : ?>
									<span class="personnel-acces">
										<?php foreach ( $personnel_acces[ $personnel_slug ] as $personnel_permission ) : ?>
											<span><?php echo ueb_icone( $personnel_permission['icone'], 13 ); ?><?php echo esc_html( $personnel_permission['libelle'] ); ?></span>
										<?php endforeach; ?>
									</span>
								<?php endif; ?>
							</td>
							<td class="personnel-table__date"><time datetime="<?php echo esc_attr( gmdate( 'Y-m-d', $personnel_cree ) ); ?>"><?php echo esc_html( $personnel_date( $personnel_cree ) ); ?></time></td>
							<td class="personnel-table__statut"><span class="personnel-statut<?php echo $suspendu ? ' personnel-statut--suspendu' : ''; ?>"><i aria-hidden="true"></i><?php echo $suspendu ? 'Suspendu' : 'Actif'; ?></span></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php if ( $personnel_outils ) : ?>
				<div class="personnel-vide personnel-vide--recherche" data-personnel-sans-resultat hidden><h3>Aucun compte ne correspond</h3><p>Essaie un autre nom, ou affiche tous les statuts.</p><button type="button" class="personnel-lien" data-personnel-effacer><?php echo ueb_icone( 'croix', 16 ); ?>Effacer la recherche</button></div>
			<?php endif; ?>
		<?php else : ?>
			<div class="personnel-vide">
				<span class="personnel-vide__illustration" aria-hidden="true"><?php echo ueb_icone( 'groupe', 28 ); ?></span>
				<h3>Ton équipe n’a pas encore d’accès</h3>
				<p>Crée un premier compte. Son rôle décide de ce qu’il pourra faire dans l’espace scolarité.</p>
				<?php if ( $roles_creables ) : ?><a href="#personnel-creation" class="btn btn--primaire personnel-vide__action" data-personnel-ouvrir><?php echo ueb_icone( 'ajout-compte', 18 ); ?>Créer le premier compte</a><?php endif; ?>
			</div>
		<?php endif; ?>

		<footer class="personnel-registre__pied">
			<p role="status" aria-live="polite" aria-atomic="true" data-personnel-compte><?php echo $personnel_total ? (int) $personnel_total . ' compte' . ( 1 === $personnel_total ? '' : 's' ) : 'Aucun compte'; ?></p>
			<p class="personnel-registre__aide"><?php echo ueb_icone( 'info', 15 ); ?><span>Pour suspendre un compte ou réinitialiser son mot de passe, adresse-toi à l’administration de l’UEb.</span></p>
		</footer>
	</section>

	<?php if ( $roles_creables ) : ?>
		<?php /* Sans JavaScript, le volet reste dans la page ; le script le place dans un tiroir. */ ?>
		<section class="personnel-creation" id="personnel-creation" aria-labelledby="titre-cellule" data-personnel-creation<?php echo $personnel_erreur ? ' data-personnel-erreur' : ''; ?>>
			<header class="personnel-creation__tete">
				<div>
					<h2 id="titre-cellule">Nouveau compte</h2>
					<p><?php echo ueb_icone( 'ecole', 16 ); ?><span>Rattachement : <?php echo esc_html( $personnel_etab ); ?></span></p>
				</div>
				<button type="button" class="personnel-fermer" data-personnel-fermer hidden><?php echo ueb_icone( 'croix', 20 ); ?><span class="sr">Fermer</span></button>
			</header>
			<form class="formulaire personnel-formulaire" method="post" action="<?php echo esc_url( ueb_url_scolarite() ); ?>" data-formulaire>
				<?php ueb_champ_csrf(); ?>
				<input type="hidden" name="ueb_action" value="gestion_creer_cellule">
				<?php if ( $personnel_erreur && ! $personnel_erreur_champ ) : ?>
					<p class="personnel-erreur" role="alert" tabindex="-1" data-resume-erreurs><?php echo ueb_icone( 'alerte', 18 ); ?><span><?php echo esc_html( $personnel_erreur ); ?></span></p>
				<?php endif; ?>
				<fieldset class="personnel-roles">
					<legend>Rôle du compte</legend>
					<?php foreach ( $roles_creables as $personnel_slug => $personnel_definition ) : ?>
						<label class="personnel-role-choix">
							<input type="radio" name="role" value="<?php echo esc_attr( $personnel_slug ); ?>" required<?php checked( $personnel_slug, $personnel_role_choisi ); ?>>
							<span class="personnel-role-choix__nom"><?php echo esc_html( $personnel_definition['nom'] ); ?></span>
							<span class="personnel-role-choix__repere" aria-hidden="true"></span>
							<?php if ( $personnel_acces[ $personnel_slug ] ) : ?>
								<span class="personnel-role-choix__acces">
									<?php foreach ( $personnel_acces[ $personnel_slug ] as $personnel_permission ) : ?>
										<span><?php echo ueb_icone( 'check', 14 ); ?><?php echo esc_html( $personnel_permission['libelle'] ); ?></span>
									<?php endforeach; ?>
								</span>
							<?php endif; ?>
						</label>
					<?php endforeach; ?>
				</fieldset>
				<?php ueb_champ( array( 'nom' => 'login', 'libelle' => 'Identifiant de connexion', 'aide' => 'En minuscules, sans espace ni accent.', 'valeur' => $personnel_saisie['login'] ?? '', 'erreur' => $personnel_erreur_de( 'login' ), 'attrs' => array( 'placeholder' => 'cellule.' . strtolower( $etab['sigle'] ?? 'fs' ), 'autocapitalize' => 'none', 'spellcheck' => 'false', 'autocomplete' => 'off', 'data-personnel-login' => true ) ) ); ?>
				<?php ueb_champ( array( 'nom' => 'nom', 'libelle' => 'Nom du responsable', 'requis' => false, 'valeur' => $personnel_saisie['nom'] ?? '', 'attrs' => array( 'autocomplete' => 'off' ) ) ); ?>
				<?php ueb_champ( array( 'nom' => 'email', 'libelle' => 'Adresse e-mail', 'type' => 'email', 'requis' => false, 'valeur' => $personnel_saisie['email'] ?? '', 'erreur' => $personnel_erreur_de( 'email' ), 'attrs' => array( 'autocomplete' => 'off' ) ) ); ?>
				<div class="personnel-creation__pied">
					<p class="personnel-creation__note"><?php echo ueb_icone( 'cle', 17 ); ?><span>Un mot de passe provisoire s’affichera une seule fois, juste après la création.</span></p>
					<button class="btn btn--primaire personnel-creation__envoi" type="submit"><?php echo ueb_icone( 'ajout-compte', 18 ); ?>Créer le compte</button>
				</div>
			</form>
		</section>
	<?php endif; ?>
</div>
