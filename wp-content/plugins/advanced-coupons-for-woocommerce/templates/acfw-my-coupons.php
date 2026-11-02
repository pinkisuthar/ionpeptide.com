<?php
/**
 * Advanced Coupons - My Coupons page (My Accounts > My Coupons).
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/page-my-coupons.php.
 *
 * @version 3.5.6
 */
defined( 'ABSPATH' ) || exit;
?>

<?php if ( $cards || $owned || $usedexpired ) : ?>
    <?php if ( $cards ) : ?>
        <h2 style="padding: 1rem 0; font-size: 1.5rem; font-weight: 200;">
            <?php echo esc_html( $labels['available'] ); ?>
        </h2>
        <?php echo wp_kses_post( $cards ); ?>
    <?php endif; ?>

    <?php if ( $owned ) : ?>
        <h2 style="padding: 1rem 0; font-size: 1.5rem; font-weight: 200;">
            <?php echo esc_html( $labels['owned'] ); ?>
        </h2>
        <?php echo wp_kses_post( $owned ); ?>
    <?php endif; ?>

    <?php if ( $usedexpired ) : ?>
        <h2 style="padding: 1rem 0; font-size: 1.5rem; font-weight: 200;">
            <?php echo esc_html( $labels['usedexpired'] ); ?>
        </h2>
        <?php echo wp_kses_post( $usedexpired ); ?>
    <?php endif; ?>
<?php else : ?>
    <p><?php echo esc_html( $labels['none'] ); ?></p>
<?php endif; ?>
