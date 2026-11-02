<?php
/**
 * Ion Shop Redesign archive template.
 *
 * @package Ion_Shop_Redesign
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'ion-shop-redesign-template' ); ?>>
<?php wp_body_open(); ?>

<?php
if ( function_exists( 'block_template_part' ) ) {
	block_template_part( 'header' );
}
?>

<div id="page" class="site ion-shop-redesign-page">
	<main id="primary" class="site-main ion-shop-redesign-primary">
		<?php echo Ion_Shop_Redesign::instance()->render_shop_page(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</main>
</div>

<?php
if ( function_exists( 'block_template_part' ) ) {
	block_template_part( 'footer' );
}
?>

<?php wp_footer(); ?>
</body>
</html>
