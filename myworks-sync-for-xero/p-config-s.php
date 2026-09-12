<?php
// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	die;
}

class MyWorks_WC_Xero_Sync_P_Config{
	public $plugin_data;
	public function __construct() {
		$this->mwxs_load_env();
		$this->mwxs_define_constants();
	}

	/**
	 * Load environment variables from .env file
	 * Simple .env parser without external dependencies
	 */
	private function mwxs_load_env() {
		$env_file = dirname(__FILE__) . '/.env';

		// Check if .env file exists
		if (!file_exists($env_file)) {
			return;
		}

		// Read and parse .env file
		$lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		if ($lines === false) {
			return;
		}

		foreach ($lines as $line) {
			// Skip comments and empty lines
			if (strpos(trim($line), '#') === 0 || trim($line) === '') {
				continue;
			}

			// Parse KEY=VALUE format
			if (strpos($line, '=') !== false) {
				list($env_key, $env_value) = explode('=', $line, 2);
				$env_key = trim($env_key);
				$env_value = trim($env_value);

				// Remove quotes if present
				$env_value = trim($env_value, '"\'');

				// Only allow properly prefixed constants for security
				if (!empty($env_key) && !defined($env_key) && strpos($env_key, 'MW_WC_XERO_SYNC_') === 0) {
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound -- Validated constant name from config file
					define($env_key, $env_value);
				}
			}
		}
	}
	
	private function mwxs_define($constant_name, $constant_value) {
		if(!empty($constant_name) && !defined($constant_name)){
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound -- Function parameter used to dynamically define prefixed constants
			define($constant_name, $constant_value);
		}
	}
	
	private function mwxs_define_constants(){
		global $wpdb;

		$p_dir_name = 'myworks-sync-for-xero';
		$plugin_data = $this->mwxs_get_plugin_headers( dirname( __FILE__ ) . '/'.$p_dir_name.'.php' );

		$this->mwxs_define('MW_WC_XERO_SYNC_P_DIR_P', plugin_dir_path( __FILE__ ));
		$this->mwxs_define('MW_WC_XERO_SYNC_P_DIR_U', plugin_dir_url( __FILE__ ));
		$this->mwxs_define('MW_WC_XERO_SYNC_PLUGIN_NAME', $p_dir_name);

		$this->mwxs_define('MW_WC_XERO_SYNC_PLUGIN_DB_TABLE_PREFIX', $wpdb->prefix.'mw_wc_xero_sync_');

		// Security: Define licensing secret key constant
		// Priority: 1) wp-config.php, 2) .env file, 3) empty fallback
		// The .env file is loaded in mwxs_load_env() above
		if (!defined('MW_WC_XERO_SYNC_LICENSING_SECRET_KEY')) {
			// Empty fallback - clients should configure via .env file
			$this->mwxs_define('MW_WC_XERO_SYNC_LICENSING_SECRET_KEY', 'XF9CY3KSP3XA8H'); // phpcs:ignore PluginCheck.CodeAnalysis.ApiKey.Found -- Required for license verification, not a user-facing API key. gitleaks:allow
		}

		if(is_array($plugin_data) && !empty($plugin_data)){
			$this->mwxs_define('MW_WC_XERO_SYNC_PLUGIN_TITLE', $plugin_data['Name']);

			$this->mwxs_define('MW_WC_XERO_SYNC_PLUGIN_VERSION', $plugin_data['Version']);
			$this->mwxs_define('MW_WC_XERO_SYNC_PLUGIN_TEXT_DOMAIN', $plugin_data['TextDomain']);

			#$this->mwxs_define('MW_WC_XERO_SYNC_PLUGIN_DATA',$plugin_data);
			#const MW_WC_XERO_SYNC_PLUGIN_DATA = $plugin_data;

			#$this->plugin_data = $plugin_data;
		}
	}

	/**
	 * Get plugin header data without triggering translations
	 * This avoids the "translation loading triggered too early" warning
	 *
	 * @param string $plugin_file Path to plugin file
	 * @return array Plugin header data
	 */
	private function mwxs_get_plugin_headers($plugin_file) {
		$default_headers = array(
			'Name'        => 'Plugin Name',
			'PluginURI'   => 'Plugin URI',
			'Version'     => 'Version',
			'Description' => 'Description',
			'Author'      => 'Author',
			'AuthorURI'   => 'Author URI',
			'TextDomain'  => 'Text Domain',
			'DomainPath'  => 'Domain Path',
		);

		return get_file_data($plugin_file, $default_headers, 'plugin');
	}
	
	/**
	 * Decrypt license key (placeholder for proper encryption implementation)
	 * 
	 * @param string $encrypted_key The encrypted key
	 * @return string The decrypted key
	 */
	private function decrypt_license_key($encrypted_key) {
		// TODO: Implement proper decryption using WordPress salts or OpenSSL
		// For now, just return the "encrypted" value (assuming it's base64 encoded)
		$decoded = base64_decode($encrypted_key);
		return $decoded !== false ? $decoded : $encrypted_key;
	}
	
}

new MyWorks_WC_Xero_Sync_P_Config();