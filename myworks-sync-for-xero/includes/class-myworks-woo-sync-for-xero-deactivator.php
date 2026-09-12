<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fired during plugin deactivation
 *
 * @link       https://myworks.software
 * @since      1.0.0
 *
 * @package    MyWorks_WC_Xero_Sync
 * @subpackage MyWorks_WC_Xero_Sync/includes
 */

/**
 * Fired during plugin deactivation.
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 *
 * @since      1.0.0
 * @package    MyWorks_WC_Xero_Sync
 * @subpackage MyWorks_WC_Xero_Sync/includes
 * @author     MyWorks Software <support@myworks.design>
 */
class MyWorks_WC_Xero_Sync_Deactivator {

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    1.0.0
	 */
	public static function deactivate() {
		# License Related
		delete_option('mw_wc_xero_license');
		delete_option('mw_wc_xero_localkey');

		# Order Xero sync-status reconcile cron. Refs #83
		wp_clear_scheduled_hook('mw_wc_xero_sync_status_reconcile_hook');

		# Pulse Integration - Clear cron
		Self::pulse_deactivate();

		# Mixpanel Integration - Track deactivation and clear cron
		Self::mixpanel_deactivate();
	}

	/**
	 * Pulse integration cleanup
	 *
	 * @since 1.3.2
	 */
	protected static function pulse_deactivate() {
		// Require Pulse integration class
		require_once plugin_dir_path( __FILE__ ) . 'class-myworks-pulse-integration.php';
		MyWorks_Pulse_Integration::deactivate();
	}

	/**
	 * Mixpanel integration cleanup
	 *
	 * @since 1.3.2
	 */
	protected static function mixpanel_deactivate() {
		// Require Mixpanel integration class
		require_once plugin_dir_path( __FILE__ ) . 'class-myworks-mixpanel-integration.php';
		MyWorks_Mixpanel_Integration::deactivate();
	}

}
