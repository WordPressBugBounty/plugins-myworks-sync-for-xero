<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Define the internationalization functionality
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @link       https://myworks.software
 * @since      1.0.0
 *
 * @package    MyWorks_WC_Xero_Sync
 * @subpackage MyWorks_WC_Xero_Sync/includes
 */

/**
 * Define the internationalization functionality.
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @since      1.0.0
 * @package    MyWorks_WC_Xero_Sync
 * @subpackage MyWorks_WC_Xero_Sync/includes
 * @author     MyWorks Software <support@myworks.design>
 */
class MyWorks_WC_Xero_Sync_i18n {


	/**
	 * Load the plugin text domain for translation.
	 *
	 * @since    1.0.0
	 * @deprecated 4.6.0 WordPress automatically loads translations for plugins on WordPress.org
	 */
	public function load_plugin_textdomain() {
		// WordPress automatically loads translations since version 4.6
		// when the plugin is hosted on WordPress.org.
		// Manual loading is no longer necessary.
		// See: https://make.wordpress.org/core/2016/07/06/i18n-improvements-in-4-6/
	}



}
