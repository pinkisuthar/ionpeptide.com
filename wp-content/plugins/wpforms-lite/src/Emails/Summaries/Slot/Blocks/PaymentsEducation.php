<?php

namespace WPForms\Emails\Summaries\Slot\Blocks;

use WPForms\Emails\Summaries\Slot\AbstractBlock;
use WPForms\Emails\Summaries\Slot\BlockState;
use WPForms\Emails\Summaries\Slot\Context;
use WPForms\Emails\Summaries\Slot\Pipeline;

/**
 * Payments education: a gateway is connected but no payment has come in yet.
 *
 * Retires after five sends or on the first payment, whichever comes first.
 *
 * @since 2.0.3
 */
class PaymentsEducation extends AbstractBlock {

	/**
	 * Block id.
	 *
	 * @since 2.0.3
	 */
	public const ID = 'payments_education';

	/**
	 * Slot.
	 *
	 * @since 2.0.3
	 */
	public const SLOT = Pipeline::SLOT_MIDDLE;

	/**
	 * Priority.
	 *
	 * @since 2.0.3
	 */
	public const PRIORITY = 10;

	/**
	 * Option counting the sends the block went out in.
	 *
	 * Deliberately separate from the Tracking shown counter: retiring the block is product
	 * behavior and must not depend on telemetry data.
	 *
	 * @since 2.0.3
	 */
	private const OPTION = 'wpforms_email_summaries_payments_education_sent';

	/**
	 * Sends after which the block retires.
	 *
	 * @since 2.0.3
	 */
	private const MAX_SENDS = 5;

	/**
	 * Visible while a gateway is connected, nothing has been paid, and the send cap is not reached.
	 *
	 * @since 2.0.3
	 *
	 * @param Context $context Summary context.
	 *
	 * @return BlockState
	 */
	public function get_state( Context $context ): BlockState {

		$visible = $context->get( 'has_gateway', false )
			&& ! $context->get( 'has_payments', false )
			&& $this->get_sent_count() < self::MAX_SENDS;

		return new BlockState( (bool) $visible );
	}

	/**
	 * Education copy.
	 *
	 * @since 2.0.3
	 *
	 * @param Context $context Summary context.
	 * @param string  $variant Unused.
	 *
	 * @return array
	 */
	public function get_args( Context $context, string $variant ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter

		return [
			'title'       => __( 'Payments Are Set Up — It’s Time to Start Collecting!', 'wpforms-lite' ),
			'alt'         => [
				'text' => __( 'Learn More', 'wpforms-lite' ),
				'url'  => self::utm_link( 'https://wpforms.com/docs/viewing-and-managing-payments/', 'Payments Education' ),
			],
			'icon'        => WPFORMS_PLUGIN_URL . 'assets/images/email/icon-credit-card.png',
			'icon_width'  => 16,
			'icon_height' => 12,
		];
	}

	/**
	 * Count the send.
	 *
	 * @since 2.0.3
	 *
	 * @param Context $context Summary context.
	 * @param string  $variant Unused.
	 */
	public function on_sent( Context $context, string $variant ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter

		update_option( self::OPTION, $this->get_sent_count() + 1, false );
	}

	/**
	 * Sends the block has gone out in.
	 *
	 * @since 2.0.3
	 *
	 * @return int
	 */
	private function get_sent_count(): int {

		return (int) get_option( self::OPTION, 0 );
	}
}
