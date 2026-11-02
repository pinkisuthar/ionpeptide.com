<?php

namespace WPForms\Migrations;

use WPForms\Emails\Summaries\Milestones;
use WPForms\Integrations\Stripe\Admin\Connect;
use WPForms\Integrations\Stripe\Helpers as StripeHelpers;
use WPForms\Migrations\Tasks\AntiSpamUpgradeTask;

/**
 * Class upgrade for 2.0.3 release.
 *
 * @since 2.0.3
 *
 * @noinspection PhpUnused
 */
class Upgrade2_0_3 extends UpgradeBase {

	/**
	 * Run upgrade.
	 *
	 * @since 2.0.3
	 *
	 * @return bool|null Upgrade result:
	 *                   true  - the upgrade completed successfully,
	 *                   null  - upgrade started but not yet finished (background task).
	 */
	public function run() {

		// The weekly summary must not celebrate milestones the site reached before this release.
		( new Milestones() )->maybe_baseline();

		$this->encrypt_stripe_connect_tokens();
		$this->record_legacy_form_currency();

		$task = new AntiSpamUpgradeTask();

		if ( $task->is_completed() ) {
			return true;
		}

		// UpgradeBase::run_async() is deliberately not used. It hooks its own
		// instance on init, and the Loader already arms one there so the admin
		// notice can re-run the task after this migration is recorded. Two
		// instances would register the action callback twice and process every
		// queued batch twice.
		$task->maybe_start();

		return null;
	}

	/**
	 * Record the currency the forms saved before this release hold their payment amounts in.
	 *
	 * A stored amount is formatted for the currency current at save time, and from this release a
	 * form records that currency in its settings. The forms already on the site carry no record,
	 * so the site currency at upgrade time is kept for them in an option, and a form without the
	 * setting is read with it rather than with whatever currency the site runs later. The forms
	 * themselves are not touched.
	 *
	 * @since 2.0.3
	 */
	private function record_legacy_form_currency(): void {

		add_option( 'wpforms_legacy_form_currency', wpforms_get_currency() );
	}

	/**
	 * Move the Stripe Connect access token out of the settings table into the encrypted connection.
	 *
	 * Encrypting on write only covers connections made from 2.0.3 on; this rewrites the tokens
	 * already on disk. A stored secret is treated as a Connect token only when the account ID
	 * recorded at handshake is present - without it the value is a manually entered API key,
	 * which belongs to the legacy setting and is left alone.
	 *
	 * Idempotent: set_stripe_key() clears the legacy setting, so a rerun finds nothing to move.
	 *
	 * @since 2.0.3
	 */
	private function encrypt_stripe_connect_tokens(): void {

		$connect = new Connect();

		foreach ( StripeHelpers::CONNECTION_MODES as $mode ) {
			$stored = wpforms_setting( "stripe-{$mode}-secret-key" );

			if ( ! is_string( $stored ) || $stored === '' || ! $connect->get_connected_user_id( $mode ) ) {
				continue;
			}

			StripeHelpers::set_stripe_key( $stored, 'secret', $mode );
		}
	}
}
