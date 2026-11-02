<?php

/**
 * Class ActionScheduler_wpPostStore_PostTypeRegistrar
 *
 * @codeCoverageIgnore
 */
class ActionScheduler_wpPostStore_PostTypeRegistrar {
	/**
	 * Registrar.
	 */
	public function register() {
		register_post_type( ActionScheduler_wpPostStore::POST_TYPE, $this->post_type_args() );
	}

	/**
	 * Build the args array for the post type definition
	 *
	 * @return array
	 */
	protected function post_type_args() {
		$args = array(
			'label'        => __( 'Scheduled Actions', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
			'description'  => __( 'Scheduled actions are hooks triggered on a certain date and time.', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
			'public'       => false,
			'map_meta_cap' => true,
			'hierarchical' => false,
			'supports'     => array( 'title', 'editor', 'comments' ),
			'rewrite'      => false,
			'query_var'    => false,
			'can_export'   => true,
			'ep_mask'      => EP_NONE,
			'labels'       => array(
				'name'               => __( 'Scheduled Actions', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'singular_name'      => __( 'Scheduled Action', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'menu_name'          => _x( 'Scheduled Actions', 'Admin menu name', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'add_new'            => __( 'Add', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'add_new_item'       => __( 'Add New Scheduled Action', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'edit'               => __( 'Edit', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'edit_item'          => __( 'Edit Scheduled Action', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'new_item'           => __( 'New Scheduled Action', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'view'               => __( 'View Action', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'view_item'          => __( 'View Action', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'search_items'       => __( 'Search Scheduled Actions', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'not_found'          => __( 'No actions found', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'not_found_in_trash' => __( 'No actions found in trash', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
			),
		);

		$args = apply_filters( 'action_scheduler_post_type_args', $args );
		return $args;
	}
}
