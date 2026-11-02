<?php
/**
 * Omnisend Sync Class
 *
 * @package OmnisendPlugin
 */

defined( 'ABSPATH' ) || exit;

class Omnisend_Sync {

	/**
	 * Meta key written by earlier plugin versions that synced data themselves. Kept for cleanup on uninstall.
	 */
	const FIELD_NAME = 'omnisend_last_sync';
}
