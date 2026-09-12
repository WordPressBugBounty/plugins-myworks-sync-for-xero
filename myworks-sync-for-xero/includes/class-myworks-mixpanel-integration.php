<?php
/**
 * Mixpanel Integration for MyWorks WooCommerce Plugin
 *
 * Tracks user behavior and sync activity analytics using Mixpanel PHP SDK
 *
 * @link       https://myworks.software
 * @since      1.3.2
 * @package    MyWorks_WC_Xero_Sync
 * @subpackage MyWorks_WC_Xero_Sync/includes
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	die;
}

// Load Mixpanel PHP SDK
require_once dirname( __FILE__ ) . '/lib/mixpanel-lib/vendor/autoload.php';

/**
 * Mixpanel Integration Class
 *
 * Handles tracking events and user identification in Mixpanel using PHP SDK
 */
class MyWorks_Mixpanel_Integration {

	/**
	 * Mixpanel project token
	 * Loaded from license API and decrypted
	 */
	private static $mixpanel_token = '';

	/**
	 * Mixpanel instance
	 *
	 * @var Mixpanel
	 */
	private static $mixpanel = null;

	/**
	 * Initialize the Mixpanel integration
	 */
	public static function init() {
		global $MWXS_L;

		// Load and decrypt Mixpanel token from license API
		$encrypted_token = get_option( 'mw_wc_xero_mixpanel_token', '' );
		if ( isset( $MWXS_L ) && is_object( $MWXS_L ) && method_exists( $MWXS_L, 'decrypt_secret' ) ) {
			self::$mixpanel_token = $MWXS_L->decrypt_secret( $encrypted_token );
		} else {
			// Fallback to plaintext if decryption not available
			self::$mixpanel_token = $encrypted_token;
		}

		// Initialize Mixpanel PHP SDK
		if ( ! empty( self::$mixpanel_token ) ) {
			self::$mixpanel = Mixpanel::getInstance( self::$mixpanel_token );
		}

		// Set up daily sync activity cron
		add_filter( 'cron_schedules', array( __CLASS__, 'add_daily_cron_schedule' ) );
		add_action( 'mw_mxp_daily', array( __CLASS__, 'send_daily_sync_activity' ) );

		// Schedule cron if not already scheduled
		if ( ! wp_next_scheduled( 'mw_mxp_daily' ) ) {
			// Schedule for midnight UTC (0:00 UTC)
			$tomorrow_utc = gmdate( 'Y-m-d', strtotime( '+1 day' ) );
			$next_midnight_utc = strtotime( $tomorrow_utc . ' 00:00:00 UTC' );
			wp_schedule_event( $next_midnight_utc, 'daily', 'mw_mxp_daily' );
		}

	}

	/**
	 * Add daily cron schedule
	 *
	 * @param array $schedules Existing schedules
	 * @return array Modified schedules
	 */
	public static function add_daily_cron_schedule( $schedules ) {
		if ( ! isset( $schedules['daily'] ) ) {
			$schedules['daily'] = array(
				'interval' => 86400,
				'display'  => __( 'Once Daily', 'myworks-sync-for-xero' ),
			);
		}
		return $schedules;
	}



	/**
	 * Get user data for Mixpanel identification
	 *
	 * @return array User data including ID, email, name, etc.
	 */
	public static function get_user_data() {
		// License account data from API
		$license_key = get_option( 'mw_wc_xero_license', '' );
		$user_id = get_option( 'mw_wc_xero_user_id', '' );
		$domain = get_option( 'mw_wc_xero_domain', '' );
		$registration_date = get_option( 'mw_wc_xero_registration_date', '' );
		$email = get_option( 'mw_wc_xero_user_email', '' );
		$company_name = get_option( 'mw_wc_xero_company_name', '' );
		$plan = get_option( 'mw_wc_xero_plan', '' );
		$billing_cycle = get_option( 'mw_wc_xero_billing_cycle', '' );

		// Get full name from license API and split into first/last name
		$full_name = get_option( 'mw_wc_xero_user_name', '' );
		$name_parts = explode( ' ', $full_name, 2 );
		$first_name = isset( $name_parts[0] ) ? $name_parts[0] : '';
		$last_name = isset( $name_parts[1] ) ? $name_parts[1] : '';

		// Get currently logged-in WordPress user
		$current_user = wp_get_current_user();
		$wp_user_email = $current_user->user_email;
		$wp_user_first = $current_user->user_firstname;
		$wp_user_last = $current_user->user_lastname;
		$wp_user_display = $current_user->display_name;

		$domain = ! empty( $domain ) ? $domain : get_site_url();
		$domain = preg_replace( '#^https?://#', '', $domain ); // Remove http:// or https://
		$domain = preg_replace( '#^www\.#', '', $domain );      // Remove www.

		return array(
			// License account data (for group tracking)
			'accountId'         => $user_id,
			'accountEmail'      => $email,
			'accountFirstName'  => $first_name,
			'accountLastName'   => $last_name,
			'companyName'       => $company_name,
			'Domain'            => $domain,
			'license_key'       => $license_key,
			'registration_date' => $registration_date,
			'plan'              => $plan,
			'billingcycle'      => $billing_cycle,

			// WordPress user data (for individual tracking)
			'wpUserEmail'       => $wp_user_email,
			'wpUserFirstName'   => $wp_user_first,
			'wpUserLastName'    => $wp_user_last,
			'wpUserDisplayName' => $wp_user_display,
		);
	}

	/**
	 * Get Mixpanel instance and ensure user is identified
	 *
	 * @return Mixpanel|null Mixpanel instance or null if not initialized
	 */
	private static function get_mixpanel_instance() {
		global $MWXS_L;

		if ( empty( self::$mixpanel ) ) {
			return null;
		}

		// Get user data for identification and grouping
		$user_data = self::get_user_data();

		// Only proceed if we have required data
		if ( empty( $user_data['accountId'] ) || empty( $user_data['license_key'] ) ) {
			return null;
		}

		// Identify the WordPress user
		if ( ! empty( $user_data['wpUserEmail'] ) ) {
			self::remember_identity_email( $user_data['wpUserEmail'], $user_data['accountId'] );
			try {
				self::$mixpanel->identify( $user_data['wpUserEmail'] );

				// Set user profile properties
				$user_props = array(
					'$email' => $user_data['wpUserEmail'],
					'$name'  => $user_data['wpUserDisplayName'],
				);

				if ( ! empty( $user_data['registration_date'] ) ) {
					// Format as ISO 8601 date
					$timestamp = is_numeric( $user_data['registration_date'] ) ? $user_data['registration_date'] : strtotime( $user_data['registration_date'] );
					$user_props['signup_date'] = gmdate( 'c', $timestamp );
				}

				self::$mixpanel->people->set( $user_data['wpUserEmail'], $user_props );
			} catch ( Exception $e ) {
			}
		}

		// Register super properties (sent with every event)
		// Note: Group tracking via account_id and store_id
		try {
			self::$mixpanel->registerAll( array(
				'store_url'            => $user_data['Domain'],
				'userid'               => $user_data['accountId'],
				'account_id'           => $user_data['accountId'],
				'store_id'             => $user_data['license_key'],
				'xero_connection'      => gmdate( 'c' ),
				'accounting_platform'  => 'Xero',
				'ecommerce_platform'   => 'WooCommerce',
				'plan'                 => $user_data['plan'],
				'billingcycle'         => $user_data['billingcycle'],
				# Registered as a super property so every event carries it, which also covers the
				# "as soon after install as possible" case: the first event a new store sends
				# already has it. Single source is the Pulse integration so all three products
				# report the same number.
				'orders_30d_avg'       => class_exists( 'MyWorks_Pulse_Integration' ) ? MyWorks_Pulse_Integration::get_orders_30d_avg() : 0,
			) );
		} catch ( Exception $e ) {
		}

		// Set account-level group profile (requires Mixpanel Group Analytics add-on)
		if ( ! empty( $user_data['accountId'] ) ) {
			try {
				$account_props = array(
					'$name'         => $user_data['companyName'],
					'account_email' => $user_data['accountEmail'],
					// 'Company Name' stays Title-Case: explicit exception in the cross-product
					// conventions doc (Segment sends 'company' as an object - never merge/rename)
					'Company Name'  => $user_data['companyName'],
				);

				if ( ! empty( $user_data['registration_date'] ) ) {
					$timestamp = is_numeric( $user_data['registration_date'] ) ? $user_data['registration_date'] : strtotime( $user_data['registration_date'] );
					$account_props['signup_date'] = gmdate( 'c', $timestamp );
				}

				self::$mixpanel->group->set( 'account_id', $user_data['accountId'], $account_props );
			} catch ( Exception $e ) {
			}
		}

		// Set store-level group profile (requires Mixpanel Group Analytics add-on)
		if ( ! empty( $user_data['license_key'] ) ) {
			try {
				$store_props = array(
					'$name'                => $user_data['Domain'],
					'plan'                 => $user_data['plan'],
					'accounting_platform'  => 'Xero',
					'ecommerce_platform'   => 'WooCommerce',
					'store_url'            => $user_data['Domain'],
				);

				if ( ! empty( $user_data['registration_date'] ) ) {
					$timestamp = is_numeric( $user_data['registration_date'] ) ? $user_data['registration_date'] : strtotime( $user_data['registration_date'] );
					$store_props['signup_date'] = gmdate( 'c', $timestamp );
				}

				self::$mixpanel->group->set( 'store_id', $user_data['license_key'], $store_props );
			} catch ( Exception $e ) {
			}
		}

		return self::$mixpanel;
	}

	/**
	 * Track a Mixpanel event using PHP SDK (server-side)
	 *
	 * @param string $event_name The event name
	 * @param array  $properties Additional event properties
	 */
	public static function track_event( $event_name, $properties = array() ) {
		global $MWXS_L;

		// Get Mixpanel instance and ensure user is identified
		$mixpanel = self::get_mixpanel_instance();
		if ( empty( $mixpanel ) ) {
			return;
		}

		// Add default properties
		$default_properties = array(
			'accounting_platform' => 'Xero',
			'ecommerce_platform'  => 'WooCommerce',
		);

		// Add connection_date and tenant ID to all events except 'Daily Sync Activity'
		if ( $event_name !== 'Daily Sync Activity' ) {
			// Set connection_date to current timestamp (ISO 8601 format)
			$default_properties['connection_date'] = gmdate( 'c' );

			// Xero Tenant ID (company ID) from database
			$xero_tenant_id = get_option( 'mw_wc_xero_tenant_id', '' );
			if ( ! empty( $xero_tenant_id ) ) {
				$default_properties['xero_tenant_id'] = $xero_tenant_id;
			}
		}

		$properties = array_merge( $default_properties, $properties );

		// Stamp the settings snapshot on Settings: Saved for change-over-time reporting
		if ( 'Settings: Saved' === $event_name ) {
			$properties = array_merge( $properties, self::get_settings_snapshot() );
		}

		// Track event immediately via PHP SDK
		// Note: Super properties registered via registerAll() are automatically included
		try {
			$mixpanel->track( $event_name, $properties );

		} catch ( Exception $e ) {
		}

		// Refresh the profile settings snapshot whenever settings are saved
		if ( 'Settings: Saved' === $event_name ) {
			self::send_settings_snapshot();
		}
	}

	/**
	 * Send daily sync activity to Mixpanel
	 *
	 * This runs via WP-Cron once daily at midnight
	 */
	public static function send_daily_sync_activity() {
		global $wpdb;
		global $MWXS_L;

		// Daily settings-snapshot refresh must run before the no-syncs early return
		// so the whole active base stays current, not just merchants who synced.
		self::send_settings_snapshot();

		// Check if constant is defined
		if ( ! defined( 'MW_WC_XERO_SYNC_PLUGIN_DB_TABLE_PREFIX' ) ) {
			return;
		}

		$log_table = MW_WC_XERO_SYNC_PLUGIN_DB_TABLE_PREFIX . 'log';

		// Get yesterday's date
		$yesterday = gmdate( 'Y-m-d', strtotime( '-1 day' ) );


		// Count syncs by type for yesterday
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sync_counts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					log_type,
					SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as success_count
				FROM " . esc_sql( $log_table ) . "
				WHERE DATE(added_date) = %s
				GROUP BY log_type",
				$yesterday
			),
			ARRAY_A
		);


		if ( empty( $sync_counts ) ) {
			return;
		}

		// Initialize counters
		$orders_synced = 0;
		$customers_synced = 0;
		$payments_synced = 0;
		$products_synced = 0;
		$inventory_levels_synced = 0;
		$price_synced = 0;
		$cost_synced = 0;
		$total_items_synced = 0;

		// Map log types to counters
		foreach ( $sync_counts as $row ) {
			$count = (int) $row['success_count'];
			$total_items_synced += $count;

			switch ( strtolower( $row['log_type'] ) ) {
				case 'order':
				case 'invoice':
					$orders_synced += $count;
					break;
				case 'customer':
				case 'contact':
					$customers_synced += $count;
					break;
				case 'payment':
					$payments_synced += $count;
					break;
				case 'product':
				case 'item':
					$products_synced += $count;
					break;
				case 'inventory':
					$inventory_levels_synced += $count;
					break;
				case 'price':
					$price_synced += $count;
					break;
				case 'cost':
					$cost_synced += $count;
					break;
			}
		}

		// Track the daily sync activity event
		self::track_event(
			'Daily Sync Activity',
			array(
				'orders_synced'           => $orders_synced,
				'customers_synced'        => $customers_synced,
				'payments_synced'         => $payments_synced,
				'products_synced'         => $products_synced,
				'inventory_levels_synced' => $inventory_levels_synced,
				'price_synced'            => $price_synced,
				'cost_synced'             => $cost_synced,
				'total_items_synced'      => $total_items_synced,
				'sync_date'               => $yesterday,
			)
		);

		// Update user properties for Daily Sync Activity
		$mixpanel = self::get_mixpanel_instance();
		if ( ! empty( $mixpanel ) ) {
			$user_email = self::get_identity_email();

			if ( ! empty( $user_email ) ) {
				try {
					// Set last_sync_date
					$mixpanel->people->set( $user_email, array(
						'last_sync_date' => $yesterday,
					) );

					// Increment lifetime_orders_synced
					if ( $orders_synced > 0 ) {
						$mixpanel->people->increment( $user_email, 'lifetime_orders_synced', $orders_synced );
					}

				} catch ( Exception $e ) {
				}
			}
		}

	}



	/**
	 * Track first successful sync for a given type
	 *
	 * This method should be called after a successful sync operation.
	 * It will only track the event once per sync type, ever.
	 *
	 * @param string $sync_type The sync type: 'customer', 'order', 'product', or 'inventory'
	 * @return bool True if event was tracked (first time), false if already tracked before
	 */
	public static function track_first_sync( $sync_type ) {
		// Validate sync type
		$valid_types = array( 'customer', 'order', 'product', 'inventory' );
		if ( ! in_array( $sync_type, $valid_types, true ) ) {
			return false;
		}

		// Check if this first sync has already been tracked
		$option_name = 'mw_xero_first_sync_' . $sync_type;
		$already_tracked = get_option( $option_name, false );

		if ( $already_tracked ) {
			// Already tracked, don't track again
			return false;
		}

		// Map sync types to event names
		$event_names = array(
			'customer'  => 'First Sync: Customer',
			'order'     => 'First Sync: Order',
			'product'   => 'First Sync: Product',
			'inventory' => 'First Sync: Inventory Level',
		);

		// Track the event
		self::track_event(
			$event_names[ $sync_type ],
			array(
				'first_sync_date' => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		// Mark as tracked so it won't fire again
		update_option( $option_name, true );

		return true;
	}

	/**
	 * Check if a first sync has already been tracked
	 *
	 * @param string $sync_type The sync type: 'customer', 'order', 'product', or 'inventory'
	 * @return bool True if already tracked, false otherwise
	 */
	public static function has_first_sync_been_tracked( $sync_type ) {
		$valid_types = array( 'customer', 'order', 'product', 'inventory' );
		if ( ! in_array( $sync_type, $valid_types, true ) ) {
			return false;
		}

		$option_name = 'mw_xero_first_sync_' . $sync_type;
		return (bool) get_option( $option_name, false );
	}

	/**
	 * Reset first sync tracking (for testing purposes)
	 *
	 * @param string $sync_type Optional. Specific sync type to reset, or empty to reset all
	 */
	public static function reset_first_sync_tracking( $sync_type = '' ) {
		$valid_types = array( 'customer', 'order', 'product', 'inventory' );

		if ( ! empty( $sync_type ) ) {
			// Reset specific type
			if ( in_array( $sync_type, $valid_types, true ) ) {
				delete_option( 'mw_xero_first_sync_' . $sync_type );
			}
		} else {
			// Reset all types
			foreach ( $valid_types as $type ) {
				delete_option( 'mw_xero_first_sync_' . $type );
			}
		}
	}

	/**
	 * Persist the last known WP identity email so anonymous WP-Cron runs
	 * can write to the same Mixpanel profile as interactive requests.
	 * Scoped to the license account so a re-licensed/handed-over site never
	 * routes cron writes to the previous owner's profile.
	 *
	 * @param string $email      Logged-in WP user email
	 * @param string $account_id Current license account id
	 */
	private static function remember_identity_email( $email, $account_id ) {
		if ( empty( $email ) ) {
			return;
		}
		$stored = get_option( 'mw_wc_xero_sync_mxp_last_identity', array() );
		if ( ! is_array( $stored ) || ! isset( $stored['email'] ) || $stored['email'] !== $email || ! isset( $stored['account_id'] ) || $stored['account_id'] !== $account_id ) {
			update_option( 'mw_wc_xero_sync_mxp_last_identity', array( 'account_id' => $account_id, 'email' => $email ), false );
		}
	}

	/**
	 * Identity email for Mixpanel profile writes: the logged-in user's email,
	 * falling back to the last persisted one in user-less contexts (WP-Cron),
	 * so cron writes land on the SAME profile as interactive events.
	 *
	 * @return string Email, or '' when no identity has been seen yet or the
	 *                persisted one belongs to a different license account
	 */
	private static function get_identity_email() {
		$user_data = self::get_user_data();
		if ( ! empty( $user_data['wpUserEmail'] ) ) {
			return $user_data['wpUserEmail'];
		}
		$stored = get_option( 'mw_wc_xero_sync_mxp_last_identity', array() );
		if ( is_array( $stored ) && isset( $stored['email'], $stored['account_id'] ) && $stored['account_id'] === $user_data['accountId'] ) {
			return (string) $stored['email'];
		}
		return '';
	}

	/**
	 * Normalize a stored option value ('true'/'false' strings, '1', 1, etc.) to a real boolean
	 *
	 * @param mixed $value Stored option value
	 * @return bool
	 */
	private static function opt_bool( $value ) {
		return filter_var( (string) $value, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Check whether a comma-separated option list contains an item (case-insensitive)
	 *
	 * @param string $csv  Comma-separated stored value
	 * @param string $item Token to look for
	 * @return bool
	 */
	private static function csv_has( $csv, $item ) {
		$parts = array_map( 'trim', explode( ',', (string) $csv ) );
		foreach ( $parts as $part ) {
			if ( 0 === strcasecmp( $part, $item ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Build the sync-config snapshot (setting_* keys shared across MyWorks products).
	 * Keys must match the cross-product Mixpanel schema exactly - never rename them.
	 * Booleans/enums only - no PII or secrets.
	 *
	 * @return array
	 */
	private static function get_settings_snapshot() {
		// Order sync type - raw stored value (Invoice / Per Role / Per Gateway), Invoice default
		$order_sync_as = get_option( 'mw_wc_xero_sync_order_sync_as', '' );
		if ( empty( $order_sync_as ) ) {
			$order_sync_as = 'Invoice';
		}

		// Realtime push has no master toggle here - the rt_push_items CSV is the enable
		$push_items = get_option( 'mw_wc_xero_sync_rt_push_items', '' );

		$pull_items     = get_option( 'mw_wc_xero_sync_rt_pull_items', '' );
		$pull_inventory = self::csv_has( $pull_items, 'Inventory' );
		$pull_pricing   = self::csv_has( $pull_items, 'Pricing' );
		$pull_cost      = self::csv_has( $pull_items, 'Cost' );

		return array(
			'setting_order_sync_as'           => $order_sync_as,
			'setting_autosync_push_orders'    => self::csv_has( $push_items, 'Order' ),
			'setting_autosync_push_products'  => self::csv_has( $push_items, 'Product' ),
			'setting_autosync_push_customers' => self::csv_has( $push_items, 'Customer' ),
			'setting_autosync_push_interval'  => (string) get_option( 'mw_wc_xero_sync_queue_cron_interval_time', '' ),
			'setting_autosync_pull_inventory' => $pull_inventory,
			'setting_autosync_pull_pricing'   => $pull_pricing,
			'setting_autosync_pull_cost'      => $pull_cost,
			'setting_autosync_pull_interval'  => (string) get_option( 'mw_wc_xero_sync_ivnt_pull_interval_time', '' ),
			'setting_autosync_pull_enabled'   => ( $pull_inventory || $pull_pricing || $pull_cost ),
		);
	}

	/**
	 * Send the settings snapshot to the user's Mixpanel profile.
	 * Runs on Settings: Saved and on the daily cron so the active base stays current.
	 */
	public static function send_settings_snapshot() {
		$mixpanel = self::get_mixpanel_instance();
		if ( empty( $mixpanel ) ) {
			return;
		}

		$user_email = self::get_identity_email();
		if ( empty( $user_email ) ) {
			return;
		}

		try {
			$mixpanel->people->set( $user_email, self::get_settings_snapshot() );
		} catch ( Exception $e ) {
		}
	}

	/**
	 * Clean up on plugin deactivation
	 */
	public static function deactivate() {
		// Track deactivation event before cleaning up
		self::track_event( 'Plugin: Deactivated', array() );

		// Clear scheduled cron
		wp_clear_scheduled_hook( 'mw_mxp_daily' );

		// Drop the persisted cron identity so a reinstalled/handed-over site starts clean
		delete_option( 'mw_wc_xero_sync_mxp_last_identity' );
	}
}
