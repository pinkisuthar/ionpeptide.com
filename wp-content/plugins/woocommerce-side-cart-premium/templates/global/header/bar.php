<?php
/**
 * Progress bar
 *
 * This template can be overridden by copying it to yourtheme/templates/side-cart-woocommerce/global/bar.php.
 *
 * HOWEVER, on occasion we will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen.
 * @see     https://docs.xootix.com/side-cart-woocommerce/
 * @version 4.7.5
 */


if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

extract( Xoo_Wsc_Template_Args::progress_bar( $bar_index ) );

if( !isset( $enable ) ) return;


$valueHTML = $endHTML = $titleHTML = $iconHTML = '';

$base = '<span %1$s %2$s>%3$s</span>';

foreach ( $points as $index => $point ){

	$style 		 = $divide === 'prop' ? 'style="width: '.$point['width'].'%"' : '';
	$class 		 = $point['reached'] ? 'class="xoo-wsc-pt-reached"' : '';

	$priceArgs 	 = !is_float( $point['amount'] + 0 ) ? array( 'decimals' => 0 ) : array();

	$pointValue  = $barValType === 'quantity' ? $point['amount'] : wc_price( $point['amount'], $priceArgs );

	$valueHTML  .= sprintf( $base, $style, $class, $pointValue, $priceArgs );
	$endHTML  	.= sprintf( $base, $style, $class, '' );
	$titleHTML 	.= sprintf( $base, $style, $class, $point['title'] );

	if( $point['icon'] ){
		$icon 		= $point['reached'] && $point['iconFilled'] ? $point['iconFilled'] : $point['icon']; 
		$iconHTML 	.= sprintf( $base, $style, $class, '<i class="'.$icon.'"></i>' );
	}
}

$barData = array(
	'points' 	=> $points,
	'filled' 	=> $filled
);

?>

<div id="xoo-wsc-bar-id-<?php echo $bar_index ?>" class="xoo-wsc-bar-cont xoo-wsc-bar-div-<?php echo $divide; ?> <?php echo $iconHTML ? 'xoo-wsc-bar-hasicons' : '' ?> xoo-wsc-bar-type-<?php echo $barValType ?>" data-bardata="<?php echo htmlspecialchars( json_encode($barData) ); ?>">

	
	<?php if( in_array( 'remaining', $show ) ): ?>
		<div class="xoo-wsc-bar-remtext"><?php echo $remainingText ?></div>
	<?php endif; ?>


	<?php if( in_array( 'amount', $show ) ): ?>
		<div class="xoo-wsc-bar-poamt xoo-wsc-bar-lev">
			<?php echo $valueHTML; ?>
		</div>
	<?php endif; ?>


	<div class="xoo-wsc-bar-in">

		<?php if( in_array( 'icon', $show ) ): ?>
			<div class="xoo-wsc-bar-icons xoo-wsc-bar-lev">
				<?php echo $iconHTML ?>
			</div>
		<?php else: ?>
			<div class="xoo-wsc-bar-poends xoo-wsc-bar-lev">
				<?php echo $endHTML ?>
			</div>
		<?php endif; ?>

		<div class="xoo-wsc-bar">
			<span class="xoo-wsc-bar-filled" style="width: <?php echo $filled ?>%"></span>
		</div>


	</div>


	<?php if( in_array( 'title', $show ) ): ?>
		<div class="xoo-wsc-bar-potitle xoo-wsc-bar-lev">
			<?php echo $titleHTML ?>
		</div>
	<?php endif; ?>


	<?php if( !empty( $showcasePoints ) ): ?>
	
		<div class="xoo-wsc-bar-showcase">

			<?php foreach ( $showcasePoints as $point_id => $showcasePoint ): ?>

				<?php

				$showcaseProducts 	= $showcasePoint['gift_ids'];
				$pointReached 		= $showcasePoint['reached'];
				$giftQty 			= $showcasePoint['gift_qty'];

				if( $hideShowcaseWhenReached && $pointReached ) continue;

				?>

				<?php foreach ( $showcaseProducts as $showcaseProductID ): ?>

					<?php

					$product = wc_get_product( $showcaseProductID );

					if( !$product ) continue;

					$product_permalink 	= $product->is_visible() && $showcasePlink ? $product->get_permalink() : '';
					$thumbnail 			= apply_filters( 'xoo_wsc_showcase_product_thumbnail', $product->get_image(), $product );
					$thumbnail 			= $product_permalink && $showcasePlink ? sprintf( '<a href="%s">%s</a>', esc_url( $product_permalink ), $thumbnail ) : $thumbnail;
					$product_name 		= $product_permalink && $showcasePlink ? sprintf( '<a href="%s">%s</a>', $product_permalink, $product->get_name() ) : $product->get_name();
					$product_name 		= $giftQty > 1 ? $product_name .' X '. $giftQty : $product_name; 
					$product_price 		= $pointReached ? wc_format_sale_price( $product->get_price(), 0 ): wc_price( $product->get_price() );
					$product_price 		= apply_filters( 'xoo_wsc_showcase_product_price', $product_price, $product );

				
					?>

					<div class="xoo-wsc-barsc-item" data-reached="<?php echo $pointReached ? 'yes' : 'no'; ?>">

						<div class="xoo-wsc-barsc-leftcol">
							<?php if( $pointReached ): ?>
								<span class="xoo-wsc-icon xoo-wsc-icon-check_circle xoo-wsc-barsc-check"></span>
							<?php endif; ?>
							
							<?php if( $showcasePimage ) echo $thumbnail ?>
						</div>

						<div class="xoo-wsc-barsc-rightcol">
							<?php if( $showcasePname ): ?>
								<span class="xoo-wsc-barsc-title"><?php echo $product_name; ?></span>
							<?php endif; ?>
							<?php if( $showcasePprice ): ?>
								<div class="xoo-wsc-barsc-price"><?php echo $product_price; ?></div>
							<?php endif; ?>
						</div>
					</div>

				<?php endforeach; ?>

			<?php endforeach; ?>

		</div>

		

	<?php endif; ?>



</div>