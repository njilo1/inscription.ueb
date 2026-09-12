<?php
/**
 * Début commun des pages de connexion et de création de compte : fond aux
 * formes vertes et carte en deux volets. Le volet du formulaire (retour à
 * l'accueil, sceau animé puis contenu de la page) s'ouvre ici ;
 * partie-auth-fin.php le ferme et ajoute le panneau vert au bord en nuage.
 * $args['page'] vaut « connexion » ou « creer-compte ».
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;

$courante = $args['page'] ?? 'connexion';
?>
<main id="contenu" class="acces">
	<div class="acces__formes" aria-hidden="true"><span></span><span></span><span></span></div>

	<div class="acces__carte acces__carte--<?php echo esc_attr( $courante ); ?>">
		<section class="acces__volet" aria-labelledby="acces-titre">
			<a class="acces__retour" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo ueb_icone( 'fleche-g', 16 ); ?>Accueil</a>
			<?php ueb_animation( 'embleme', ueb_props_embleme(), 'animation--embleme', 'Logo de l’Université d’Ebolowa' ); ?>
