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
?>

<header class="page-app__entete">
	<div>
		<h1>Établissements sous tutelle (IPES)</h1>
		<p class="page-app__sous-titre">Instituts privés liés par convention à un ou plusieurs établissements de l’UEb.</p>
	</div>
</header>
<?php ueb_afficher_flash(); ?>
