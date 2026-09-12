<?php
/**
 * Plugin bootstrap: declares the plugin headers, HPOS compatibility, and the
 * activation/deactivation hooks, then starts the plugin.
 *
 * @link              https://myworks.software/
 * @since             1.1
 * @package           MyWorks_WC_Xero_Sync
 *
 * @wordpress-plugin
 * Plugin Name:       MyWorks Sync for WooCommerce & Xero
 * Plugin URI:        https://myworks.software/integrations/woocommerce-xero-sync/
 * Description:       Automatically sync your WooCommerce store with Xero - in real-time! Easily sync customers, orders, payments, products, inventory and more between your WooCommerce store and Xero. Your complete solution to streamline your accounting workflow.
 * Version:           1.4.2
 * Author:            MyWorks
 * Author URI:        https://myworks.software/
 * Developer:         MyWorks
 * Developer URI:     https://myworks.software/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       myworks-sync-for-xero
 * Domain Path:       /languages
 * Requires at least: 5.3
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 4.0
 * WC tested up to: 11.1.0
 *
 * Copyright: © 2011-2026 MyWorks Software.
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	die;
}

// Config.
require plugin_dir_path( __FILE__ ) . 'p-config-s.php';

// Debug logging constant - set to true to enable all debug logging.
if ( ! defined( 'MWXS_DEBUG_LOGGING' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- MWXS is the established plugin prefix (short form of MyWorks Xero Sync)
	define( 'MWXS_DEBUG_LOGGING', false );
}

/*
 * The plugin version has no constant literal here: p-config-s.php derives
 * MW_WC_XERO_SYNC_PLUGIN_VERSION at runtime from the `Version:` header above,
 * via mwxs_get_plugin_headers(). Bump the header, never a define.
 */

// HPOS compatibility declare.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-myworks-woo-sync-for-xero-activator.php
 */
function myworks_woo_sync_for_xero_activate() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-myworks-woo-sync-for-xero-activator.php';
	MyWorks_WC_Xero_Sync_Activator::activate();

	// Track Intercom event.
	if ( class_exists( 'MyWorks_Intercom_Integration' ) ) {
		MyWorks_Intercom_Integration::track_event( 'Plugin: Activated' );
	}
}


/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-myworks-woo-sync-for-xero-deactivator.php
 */
function myworks_woo_sync_for_xero_deactivate() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-myworks-woo-sync-for-xero-deactivator.php';
	MyWorks_WC_Xero_Sync_Deactivator::deactivate();

	// Queue deactivation event for Intercom (sent via JS on next admin visit).
	if ( class_exists( 'MyWorks_Intercom_Integration' ) ) {
		MyWorks_Intercom_Integration::track_deactivation_event();
	}

	// Clean up Intercom cron jobs.
	if ( class_exists( 'MyWorks_Intercom_Integration' ) && method_exists( 'MyWorks_Intercom_Integration', 'deactivate' ) ) {
		MyWorks_Intercom_Integration::deactivate();
	}
}

register_activation_hook( __FILE__, 'myworks_woo_sync_for_xero_activate' );
register_deactivation_hook( __FILE__, 'myworks_woo_sync_for_xero_deactivate' );

/**
 * Force license refresh on plugin update.
 *
 * This ensures Intercom integration data is loaded immediately after update.
 *
 * @since 1.3.2
 *
 * @param WP_Upgrader $upgrader_object The upgrader instance (unused; required by the hook signature).
 * @param array       $options         Upgrade data, including 'action', 'type' and 'plugins'.
 */
function myworks_woo_sync_for_xero_force_license_refresh_on_update( $upgrader_object, $options ) {
	if ( 'update' === $options['action'] && 'plugin' === $options['type'] ) {
		$our_plugin = plugin_basename( __FILE__ );

		if ( isset( $options['plugins'] ) && in_array( $our_plugin, $options['plugins'], true ) ) {
			// Force license refresh to update Intercom data.
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $MWXS_L is the established plugin-wide lib singleton global.
			global $MWXS_L;
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- See above.
			if ( isset( $MWXS_L ) && is_object( $MWXS_L ) && method_exists( $MWXS_L, 'force_license_refresh' ) ) {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- See above.
				$MWXS_L->force_license_refresh();
			}
		}
	}
}
add_action( 'upgrader_process_complete', 'myworks_woo_sync_for_xero_force_license_refresh_on_update', 10, 2 );


/**
 * Add plugin action links on the Plugins screen.
 *
 * @param array $links Existing plugin action links.
 * @return array Action links with the plugin's own entries appended.
 */
function myworks_woo_sync_for_xero_links_add( $links ) {

	$links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=myworks-wc-xero-sync-connection' ) ) . '">'
		. esc_html__( 'Connection', 'myworks-sync-for-xero' ) . '</a>';

	$adminlinks = array(
		'<a target="_blank" rel="noopener noreferrer" href="'
			. esc_url( 'https://support.myworks.software/en_US/10141789949335-WooCommerce-Sync-for-Xero' ) . '">'
			. esc_html__( 'Help Center', 'myworks-sync-for-xero' ) . '</a>',
	);

	return array_merge( $links, $adminlinks );
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'myworks_woo_sync_for_xero_links_add' );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-myworks-woo-sync-for-xero.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function myworks_woo_sync_for_xero_run() {

	$myworks_wc_xero_sync = new MyWorks_WC_Xero_Sync();
	$myworks_wc_xero_sync->run();
}

myworks_woo_sync_for_xero_run();
