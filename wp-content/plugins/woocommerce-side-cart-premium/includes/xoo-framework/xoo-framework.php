<?php

if( !function_exists( 'xoo_framework_includes' ) ){

	if( !defined( 'XOO_FW_DIR' ) ){
		define( 'XOO_FW_DIR' , __DIR__ );
	}

	function xoo_framework_includes(){
		require_once __DIR__.'/class-xoo-helper.php';
		require_once __DIR__.'/class-xoo-exception.php';
	}

	xoo_framework_includes();

}

if (!function_exists('array_is_list')) {
    function array_is_list(array $arr)
    {
        if ($arr === []) {
            return true;
        }
        return array_keys($arr) === range(0, count($arr) - 1);
    }
}

if ( ! function_exists( 'xoo_recursive_parse_args' ) ) {
	function xoo_recursive_parse_args( $args, $defaults ) {
		$new_args = (array) $defaults;

		foreach ( $args as $key => $value ) {
			if ( is_array( $value ) && isset( $new_args[ $key ] ) && !array_is_list( $value ) ) {
				$new_args[ $key ] = xoo_recursive_parse_args( $value, $new_args[ $key ] );
			}
			else {
				$new_args[ $key ] = $value;
			}
		}

		return $new_args;
    }
}

if( !function_exists( 'xoo_clean' ) ){

	function xoo_clean( $var, $func = 'sanitize_text_field' ) {

		if ( is_array( $var ) ) {
			return array_map( 'xoo_clean', $var );
		} else {
			return is_scalar( $var ) ? call_user_func($func, $var ) : $var;
		}

	}

}

if( !function_exists( 'xoo_wp_kses_email' ) ){
	function xoo_wp_kses_email( $content ) {

	    // Start with WordPress's built-in 'post' context.
	    $allowed_html = wp_kses_allowed_html( 'post' );

	    // Extend with email-specific attributes and tags.
	    $extra_tags = array(
	        'table' => array(
	            'width'       => true,
	            'border'      => true,
	            'cellpadding' => true,
	            'cellspacing' => true,
	            'bgcolor'     => true,
	            'align'       => true,
	            'style'       => true,
	        ),
	        'tr' => array(
	            'align'   => true,
	            'valign'  => true,
	            'bgcolor' => true,
	            'style'   => true,
	        ),
	        'td' => array(
	            'align'   => true,
	            'valign'  => true,
	            'bgcolor' => true,
	            'width'   => true,
	            'height'  => true,
	            'style'   => true,
	        ),
	        'th' => array(
	            'align'   => true,
	            'valign'  => true,
	            'bgcolor' => true,
	            'style'   => true,
	        ),
	        'tbody' => array(),
	        'thead' => array(),
	        'tfoot' => array(),
	    );

	    // Merge the defaults with the extra tags.
	    $allowed_html = array_replace_recursive( $allowed_html, $extra_tags );

	    // Now sanitize using wp_kses().
	    return wp_kses( $content, $allowed_html );
	}
}