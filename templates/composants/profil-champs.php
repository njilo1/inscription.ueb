<?php
/**
 * Champs de la fiche de l'étudiant, partagés par le formulaire du quitus et
 * par Mon compte. Une partie à la fois : « etablissement », « identite »
 * (avec le contact d'urgence), « formation » (filière et niveau) ou « niveau » seul.
 *
 * Un champ cité dans « verrou » est affiché avec sa valeur mais ne se
 * modifie pas (lecture seule, cadenas) : le serveur reprend de toute façon
 * la valeur de la fiche.
 *
 * $args : partie, v, erreurs, verrou (clé => valeur), cms_requis, aide_cms,
 *         options_formations, libelle_filiere, aide_filiere, etabs_permis (null = tous).
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

$a = wp_parse_args( $args, array( 'v' => array(), 'erreurs' => array(), 'verrou' => array(), 'cms_requis' => false, 'aide_cms' => '', 'options_formations' => array(), 'libelle_filiere' => 'Filière', 'aide_filiere' => '', 'etabs_permis' => null ) );
$v       = $a['v'];
$erreurs = $a['erreurs'];
$val     = static fn( $cle ) => (string) ( $v[ $cle ] ?? '' );
$fige    = static fn( $cle ) => isset( $a['verrou'][ $cle ] );
/* Champ figé : cadenas à la place de l'icône, lecture seule (texte) ou désactivé (liste). */
$icone   = static fn( $cle, $icone ) => $fige( $cle ) ? 'cadenas' : $icone;
$texte   = static fn( $cle ) => $fige( $cle ) ? array( 'readonly' => true ) : array();
$liste   = static fn( $cle ) => $fige( $cle ) ? array( 'disabled' => true ) : array();

switch ( $a['partie'] ) :
	case 'etablissement':
		?>
		<fieldset id="champ-etablissement" tabindex="-1" class="champ<?php echo ! empty( $erreurs['etablissement'] ) ? ' champ--invalide' : ''; ?><?php echo $fige( 'etablissement' ) ? ' est-verrouille' : ''; ?>">
			<legend class="sr">Établissement</legend>
			<div class="etab-choix">
				<?php foreach ( ueb_etablissements() as $sigle => $e ) : ?>
					<label class="etab-choix__option" style="--etab: <?php echo esc_attr( $e['couleur'] ); ?>">
						<input type="radio" name="etablissement" value="<?php echo esc_attr( $sigle ); ?>" <?php checked( $val( 'etablissement' ), $sigle ); ?> <?php disabled( $fige( 'etablissement' ) || ( null !== $a['etabs_permis'] && ! in_array( $sigle, $a['etabs_permis'], true ) ) ); ?> required>
						<span class="etab-choix__carte">
							<span class="etab-choix__logo"><img src="<?php echo esc_url( ueb_logo_url( $sigle ) ); ?>" alt="" width="40" height="40" loading="lazy"></span>
							<span class="etab-choix__texte">
								<b><?php echo esc_html( $sigle ); ?></b>
								<small><?php echo esc_html( $e['fr'] ); ?></small>
							</span>
							<span class="etab-choix__coche"><?php echo ueb_icone( $fige( 'etablissement' ) ? 'cadenas' : 'check', 16 ); ?></span>
						</span>
					</label>
				<?php endforeach; ?>
			</div>
			<?php if ( ! empty( $erreurs['etablissement'] ) ) : ?>
				<p class="champ__erreur"><?php echo ueb_icone( 'alerte', 16 ); ?><?php echo esc_html( $erreurs['etablissement'] ); ?></p>
			<?php endif; ?>
		</fieldset>
		<?php
		break;

	case 'identite':
		?>
		<div class="formulaire__rangee">
			<?php
			ueb_champ( array( 'nom' => 'nom', 'libelle' => 'Nom(s)', 'icone' => $icone( 'nom', 'utilisateur' ), 'valeur' => $val( 'nom' ), 'erreur' => $erreurs['nom'] ?? '', 'attrs' => array( 'placeholder' => 'Ex. : TCHOUMBA', 'autocomplete' => 'family-name', 'autocapitalize' => 'characters', 'maxlength' => 100 ) + $texte( 'nom' ) ) );
			ueb_champ( array( 'nom' => 'prenom', 'libelle' => 'Prénom(s)', 'icone' => $icone( 'prenom', 'utilisateur' ), 'valeur' => $val( 'prenom' ), 'erreur' => $erreurs['prenom'] ?? '', 'attrs' => array( 'placeholder' => 'Ex. : Vevo ily', 'autocomplete' => 'given-name', 'maxlength' => 150 ) + $texte( 'prenom' ) ) );
			?>
		</div>
		<div class="formulaire__rangee">
			<?php
			ueb_champ( array( 'nom' => 'date_naissance', 'libelle' => 'Date de naissance', 'type' => 'date', 'icone' => $fige( 'date_naissance' ) ? 'cadenas' : '', 'valeur' => $val( 'date_naissance' ), 'erreur' => $erreurs['date_naissance'] ?? '', 'aide' => $fige( 'date_naissance' ) ? '' : 'Ex. : 15/03/2005, pour le 15 mars 2005.', 'attrs' => array( 'autocomplete' => 'bday', 'max' => wp_date( 'Y-m-d', strtotime( '-14 years' ) ) ) + $texte( 'date_naissance' ) ) );
			ueb_champ( array( 'nom' => 'lieu_naissance', 'libelle' => 'Lieu de naissance', 'icone' => $icone( 'lieu_naissance', 'lieu' ), 'valeur' => $val( 'lieu_naissance' ), 'erreur' => $erreurs['lieu_naissance'] ?? '', 'attrs' => array( 'placeholder' => 'Ex. : Ebolowa', 'maxlength' => 150 ) + $texte( 'lieu_naissance' ) ) );
			?>
		</div>
		<div class="formulaire__rangee">
			<?php
			ueb_choix_ronds( 'sexe', 'Sexe', array( 'M' => 'Masculin', 'F' => 'Féminin' ), $val( 'sexe' ), $erreurs['sexe'] ?? '', $fige( 'sexe' ) );
			ueb_champ( array( 'nom' => 'nationalite', 'libelle' => 'Nationalité', 'type' => 'select', 'icone' => $icone( 'nationalite', 'lieu' ), 'valeur' => $val( 'nationalite' ) ?: 'Camerounaise', 'erreur' => $erreurs['nationalite'] ?? '', 'options' => array_combine( ueb_nationalites(), ueb_nationalites() ), 'attrs' => $liste( 'nationalite' ) ) );
			?>
		</div>
		<div class="formulaire__rangee">
			<?php
			ueb_champ( array( 'nom' => 'email', 'libelle' => 'Adresse email', 'type' => 'email', 'icone' => $icone( 'email', 'courriel' ), 'valeur' => $val( 'email' ), 'erreur' => $erreurs['email'] ?? '', 'requis' => $a['cms_requis'], 'aide' => 'Utilisée sur les fiches CMS.', 'attrs' => array( 'placeholder' => 'Ex. : vevo@example.com', 'data-cms-champ' => true, 'autocomplete' => 'email', 'maxlength' => 150 ) + $texte( 'email' ) ) );
			ueb_champ( array( 'nom' => 'adresse', 'libelle' => 'Adresse complète', 'icone' => $icone( 'adresse', 'lieu' ), 'valeur' => $val( 'adresse' ), 'erreur' => $erreurs['adresse'] ?? '', 'requis' => $a['cms_requis'], 'aide' => 'Indique ton quartier et ta ville.', 'attrs' => array( 'placeholder' => 'Ex. : Nko’ovos, Ebolowa', 'data-cms-champ' => true, 'autocomplete' => 'street-address', 'minlength' => 3, 'maxlength' => 255 ) + $texte( 'adresse' ) ) );
			?>
		</div>
		<section class="quitus-cms" aria-labelledby="quitus-contact-titre">
			<h3 id="quitus-contact-titre">Contact en cas d’urgence</h3>
			<?php if ( $a['aide_cms'] ) : ?><p class="champ__aide" data-cms-aide><?php echo esc_html( $a['aide_cms'] ); ?></p><?php endif; ?>
			<div class="quitus-cms__corps formulaire">
				<div class="formulaire__rangee">
					<?php
					ueb_champ( array( 'nom' => 'nom_urgence', 'libelle' => 'Personne à contacter en cas d’urgence', 'icone' => $icone( 'nom_urgence', 'utilisateur' ), 'valeur' => $val( 'nom_urgence' ), 'erreur' => $erreurs['nom_urgence'] ?? '', 'requis' => $a['cms_requis'], 'attrs' => array( 'placeholder' => 'Ex. : TCHOUMBA Jean', 'data-cms-champ' => true, 'autocomplete' => 'section-urgence name', 'minlength' => 2, 'maxlength' => 150 ) + $texte( 'nom_urgence' ) ) );
					ueb_champ( array( 'nom' => 'numero_urgence', 'libelle' => 'Téléphone d’urgence', 'type' => 'tel', 'icone' => $icone( 'numero_urgence', 'telephone' ), 'valeur' => $val( 'numero_urgence' ), 'erreur' => $erreurs['numero_urgence'] ?? '', 'requis' => $a['cms_requis'], 'aide' => 'Numéro camerounais à 9 chiffres, avec ou sans +237.', 'attrs' => array( 'placeholder' => 'Ex. : 699 11 11 11', 'data-cms-champ' => true, 'data-telephone' => true, 'inputmode' => 'tel', 'autocomplete' => 'section-urgence tel', 'maxlength' => 20 ) + $texte( 'numero_urgence' ) ) );
					?>
				</div>
				<div class="formulaire__rangee">
					<?php ueb_champ( array( 'nom' => 'adresse_urgence', 'libelle' => 'Adresse du contact', 'icone' => $icone( 'adresse_urgence', 'lieu' ), 'valeur' => $val( 'adresse_urgence' ), 'erreur' => $erreurs['adresse_urgence'] ?? '', 'requis' => $a['cms_requis'], 'aide' => 'Quartier et ville de la personne à contacter.', 'attrs' => array( 'placeholder' => 'Ex. : Angalé, Ebolowa', 'data-cms-champ' => true, 'autocomplete' => 'section-urgence street-address', 'minlength' => 3, 'maxlength' => 255 ) + $texte( 'adresse_urgence' ) ) ); ?>
				</div>
			</div>
		</section>
		<?php
		break;

	case 'formation':
		?>
		<div class="formulaire__rangee">
			<?php
			/* Le niveau d'abord : il restreint les filières proposées. */
			ueb_champ( array( 'nom' => 'parcours', 'libelle' => 'Niveau', 'type' => 'select', 'icone' => $fige( 'parcours' ) ? 'cadenas' : '', 'valeur' => $val( 'parcours' ), 'erreur' => $erreurs['parcours'] ?? '', 'options' => UEB_NIVEAUX_INSCRIPTION, 'aide' => $fige( 'parcours' ) ? '' : 'Ex. : L1 pour Licence 1, M1 pour Master 1.', 'attrs' => $liste( 'parcours' ) ) );
			ueb_champ( array( 'nom' => 'filiere_id', 'libelle' => $a['libelle_filiere'], 'type' => 'select', 'icone' => $icone( 'filiere_id', 'ecole' ), 'valeur' => $val( 'filiere_id' ), 'erreur' => $erreurs['filiere_id'] ?? '', 'options' => $a['options_formations'], 'aide' => $fige( 'filiere_id' ) ? '' : $a['aide_filiere'], 'attrs' => $liste( 'filiere_id' ) ) );
			?>
		</div>
		<?php
		break;

	case 'niveau':
		?>
		<div class="formulaire__rangee">
			<?php ueb_champ( array( 'nom' => 'parcours', 'libelle' => 'Niveau', 'type' => 'select', 'valeur' => $val( 'parcours' ), 'erreur' => $erreurs['parcours'] ?? '', 'options' => UEB_NIVEAUX_INSCRIPTION, 'aide' => 'À mettre à jour à chaque nouvelle année, avant ton quitus.' ) ); ?>
		</div>
		<?php
		break;
endswitch;
