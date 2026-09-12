<?php
/**
 * Questions fréquentes (accordéon natif <details>).
 *
 * @package Inscription_UEB
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="accordeon">
	<?php foreach ( $args['faq'] as $q ) : ?>
		<details>
			<summary><?php echo esc_html( $q[0] ); ?><?php echo ueb_icone( 'chevron', 20 ); ?></summary>
			<p><?php echo esc_html( $q[1] ); ?></p>
		</details>
	<?php endforeach; ?>
</div>
