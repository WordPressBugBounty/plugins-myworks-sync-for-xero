<?php
/**
 * Pulse Integration for MyWorks WooCommerce Plugin
 *
 * Centralized sync activity logging system
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

// $MWXS_L is the plugin-wide lib singleton global (see docs/rules/conventions.md) and cannot be
// renamed; the sniff's suggestion of $m_w_x_s_l is not an option. Suppressed file-wide rather than
// line-by-line because it applies to every use of the global throughout this file.
// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $MWXS_L is the established lib singleton global

/**
 * Pulse Integration Class
 *
 * Handles sending sync logs to the centralized Pulse API
 */
class MyWorks_Pulse_Integration {

	/**
	 * Resolve a plugin table name through the whitelist helper.
	 *
	 * Every method here is static (cron entry points, no $this), so the lib singleton is read from
	 * the global. Prefer this over building names from MW_WC_XERO_SYNC_PLUGIN_DB_TABLE_PREFIX
	 * directly — see docs/rules/security.md. Falls back to the prefix constant rather than
	 * returning empty, so a cron step can't silently stop working if the global isn't up yet.
	 *
	 * @param string $suffix Whitelisted table suffix, e.g. 'log', 'map_products', 'map_tax'.
	 * @return string Full table name, or '' if it cannot be resolved at all.
	 */
	private static function get_plugin_table( $suffix ) {
		// Shared implementation — see MyWorks_WC_Xero_Sync_Core::resolve_plugin_table().
		return MyWorks_WC_Xero_Sync_Core::resolve_plugin_table( $suffix );
	}

	/**
	 * Pulse API endpoint
	 */
	const PULSE_API_ENDPOINT = 'https://pulse.myworks.software/';

	/**
	 * Cache key and lifetime for the order average.
	 *
	 * Six hours: the daily snapshot and any Mixpanel event in between reuse one count
	 * rather than re-running it per send.
	 */
	# gitleaks reads the _KEY suffix plus a longish value as a credential. This is a
	# transient name, not a secret - it is the argument to get_transient() below.
	const ORDERS_AVG_CACHE_KEY = 'mw_wc_xero_sync_orders_30d_avg'; // gitleaks:allow
	const ORDERS_AVG_CACHE_TTL = 21600; // 6 hours

	/**
	 * Average number of orders per month for this store.
	 *
	 * Counted as the last 90 days of orders divided by 3, per the agreed definition. The
	 * field is named orders_30d_avg because that is the agreed field name; the 90/3 window
	 * smooths out a single quiet or busy month, which a straight 30-day count would not.
	 *
	 * Storage-mode aware: HPOS keeps orders in {prefix}wc_orders with a prefixed status and
	 * date_created_gmt, legacy keeps them in wp_posts with post_date_gmt. Mirrors the
	 * implementation in WooQBO 3.1.0 and WooQBD 1.9.0 so all three products report the
	 * same number for the same store.
	 *
	 * @since 1.4.2
	 * @param bool $force Skip the cache and recount.
	 * @return float Orders per month, to two decimals. 0 when nothing can be counted.
	 */
	public static function get_orders_30d_avg( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::ORDERS_AVG_CACHE_KEY );
			if ( false !== $cached ) {
				return (float) $cached;
			}
		}

		$avg = 0.0;

		if ( function_exists( 'wc_get_order' ) ) {
			global $wpdb;

			# 90 days back from now, in UTC, to match the *_gmt columns being compared.
			$since = gmdate( 'Y-m-d H:i:s', time() - ( 90 * DAY_IN_SECONDS ) );
			# Upper bound too: an order dated in the future - a hand-edited date, an import, or
			# a skewed server clock - is not an order placed in the last 90 days.
			$now = gmdate( 'Y-m-d H:i:s' );

			# Same idiom as MyWorks_WC_Xero_Sync_Lib::is_hpos_enabled(), which is private.
			$hpos = class_exists( 'Automattic\\WooCommerce\\Utilities\\OrderUtil' )
				&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

			# Failed and cancelled orders are left out: a failed payment never became an
			# order, and a cancelled one did not stand. Both statuses are listed with and
			# without the wc- prefix so the same list works for HPOS and legacy storage.
			if ( $hpos ) {
				$orders_table = $wpdb->prefix . 'wc_orders';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix; value is prepared
				$count = $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM `" . esc_sql( $orders_table ) . "`"
					. " WHERE `type` = 'shop_order'"
					. " AND `status` NOT IN ('auto-draft','trash','draft','wc-checkout-draft','checkout-draft','wc-failed','failed','wc-cancelled','cancelled')"
					. " AND `date_created_gmt` >= %s AND `date_created_gmt` <= %s",
					$since,
					$now
				) );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- counting orders for a telemetry metric
				$count = $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM `{$wpdb->posts}`"
					. " WHERE `post_type` = 'shop_order'"
					. " AND `post_status` NOT IN ('auto-draft','trash','draft','wc-checkout-draft','checkout-draft','wc-failed','failed','wc-cancelled','cancelled')"
					. " AND `post_date_gmt` >= %s AND `post_date_gmt` <= %s",
					$since,
					$now
				) );
			}

			# get_var() returns null only when the query itself failed - an empty store still
			# returns "0". Returning before set_transient() means a failure is retried on the
			# next call, rather than pinning every integration to 0 orders for six hours.
			if ( null === $count ) {
				return 0.0;
			}

			$avg = round( ( (int) $count ) / 3, 2 );

			# Cache only a real count. Outside this block WooCommerce is not loaded - deactivated,
			# or mid-update - and the 0.0 below is 'cannot measure', not 'no orders'. Caching that
			# would outlive the outage by up to six hours. Same reasoning as the null-count return.
			set_transient( self::ORDERS_AVG_CACHE_KEY, $avg, self::ORDERS_AVG_CACHE_TTL );
		}

		return (float) $avg;
	}

	/**
	 * Strip protocol and www from URL
	 *
	 * @param string $url The URL to clean
	 * @return string Clean domain without protocol or www
	 */
	private static function clean_domain( $url ) {
		// Parse the URL
		$parsed = wp_parse_url( $url );

		// Get the host
		$domain = isset( $parsed['host'] ) ? $parsed['host'] : $url;

		// Remove 'www.' prefix if present
		$domain = preg_replace( '/^www\./i', '', $domain );

		return $domain;
	}

	/**
	 * Initialize the Pulse integration
	 */
	public static function init() {
		// Run one-time migrations
		self::db_migration();
		self::migrate_add_name_columns();

		// Set up WP-Cron
		add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_schedule' ) );
		add_action( 'mw_pulse', array( __CLASS__, 'send_sync_logs_to_api' ) );
		add_action( 'mw_pulse_daily', array( __CLASS__, 'send_pulse_snapshot' ) );

		// Schedule cron if not already scheduled
		if ( ! wp_next_scheduled( 'mw_pulse' ) ) {
			wp_schedule_event( time(), 'every_10_minutes', 'mw_pulse' );
		}

		// Schedule daily snapshot if not already scheduled
		if ( ! wp_next_scheduled( 'mw_pulse_daily' ) ) {
			self::schedule_snapshot_cron();
		}

	}

	/**
	 * One-time migration to add name columns to mapping tables
	 */
	private static function migrate_add_name_columns() {
		// Check if migration already ran
		if ( get_option( 'mw_wc_xero_sync_pulse_name_columns_migrated', false ) ) {
			return;
		}

		global $wpdb;

		// Both column additions must succeed before this is recorded as done — the option is the only
		// thing stopping it running again, so marking it complete after a failure leaves the columns
		// missing permanently and later mapping queries silently report incomplete data.
		//
		// The ALTER statements are written out in full at each site rather than passed into a helper:
		// a variable holding SQL can't be verified as safe by the security scan, even when its value
		// is a literal. Keeping them literal keeps the statements checkable.
		$payment_table = self::get_plugin_table( 'map_payment_method' );
		$payment_ok    = false;
		$payment_has   = self::column_exists( $payment_table, 'xero_account_name' );
		if ( true === $payment_has ) {
			$payment_ok = true;
		} elseif ( false === $payment_has ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name whitelist-validated then esc_sql()'d; the remainder is a literal
			$payment_ok = ( false !== $wpdb->query( "ALTER TABLE `" . esc_sql( $payment_table ) . "` ADD COLUMN `xero_account_name` varchar(255) NOT NULL DEFAULT '' AFTER `X_ACC_ID`" ) );
		}

		$tax_table = self::get_plugin_table( 'map_tax' );
		$tax_ok    = false;
		$tax_has   = self::column_exists( $tax_table, 'xero_tax_name' );
		if ( true === $tax_has ) {
			$tax_ok = true;
		} elseif ( false === $tax_has ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name whitelist-validated then esc_sql()'d; the remainder is a literal
			$tax_ok = ( false !== $wpdb->query( "ALTER TABLE `" . esc_sql( $tax_table ) . "` ADD COLUMN `xero_tax_name` varchar(255) NOT NULL DEFAULT '' AFTER `xero_tax`" ) );
		}

		if ( $payment_ok && $tax_ok ) {
			update_option( 'mw_wc_xero_sync_pulse_name_columns_migrated', true );
		}
	}

	/**
	 * Does a column exist on a plugin table?
	 *
	 * @param string $table  Fully-resolved table name (may be '' if resolution failed).
	 * @param string $column Column name to look for.
	 * @return bool|null True if present, false if genuinely absent, null if the check couldn't run —
	 *                   the three are deliberately distinct, so a failed check isn't mistaken for a
	 *                   missing column and used to justify an ALTER against nothing.
	 */
	private static function column_exists( $table, $column ) {
		global $wpdb;

		if ( '' === $table ) {
			return null;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SHOW COLUMNS FROM `" . esc_sql( $table ) . "` LIKE %s", $column )
		);

		if ( null === $rows ) {
			return null;
		}

		return ! empty( $rows );
	}


	/**
	 * Add custom cron schedule - every 10 minutes
	 *
	 * @param array $schedules Existing schedules
	 * @return array Modified schedules
	 */
	public static function add_cron_schedule( $schedules ) {
		$schedules['every_10_minutes'] = array(
			'interval' => 600,
			'display'  => __( 'Every 10 minutes', 'myworks-sync-for-xero' ),
		);
		return $schedules;
	}

	/**
	 * Get the per-site Pulse signing key.
	 *
	 * The key is the server-derived per-site token (pulse_site_token) delivered
	 * in the licensing response and stored encrypted. The plugin no longer holds
	 * a master secret; the dashboard derives the token server-side and Pulse
	 * verifies signatures by recomputing it. Returns '' when no token is stored
	 * (Pulse unavailable) so callers can skip sending.
	 *
	 * @return string The signing key (64 character hex string), or '' if unavailable
	 */
	private static function get_signing_key() {
		global $MWXS_L;
		if ( ! isset( $MWXS_L ) || ! is_object( $MWXS_L ) || ! method_exists( $MWXS_L, 'decrypt_secret' ) ) {
			return '';
		}
		$signing_key = $MWXS_L->decrypt_secret( $MWXS_L->get_option( 'mw_wc_xero_pulse_site_token', '' ) );
		// Only accept a well-formed 64-char hex token; anything else (empty, corrupted, undecryptable)
		// triggers the no-token skip so Pulse never signs with a bogus key. Refs #122.
		return ( is_string( $signing_key ) && preg_match( '/\A[a-f0-9]{64}\z/i', $signing_key ) ) ? $signing_key : '';
	}

	/**
	 * Get the license key from plugin options
	 *
	 * @return string License key
	 */
	private static function get_license_key() {
		return get_option( 'mw_wc_xero_license', '' );
	}

	/**
	 * Get the user ID from plugin options
	 *
	 * @return int User ID
	 */
	private static function get_user_id() {
		return (int) get_option( 'mw_wc_xero_user_id', 0 );
	}

	/**
	 * Get logs that haven't been sent yet
	 *
	 * @return array Array of log entries
	 */
	public static function get_pending_logs() {
		global $wpdb;
		$table = self::get_plugin_table( 'log' );
		if ( '' === $table ) {
			// No safe table name: return empty rather than building an invalid FROM clause, whose
			// failure would be indistinguishable from "no pending logs".
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for custom log table
		$logs = $wpdb->get_results(
			"SELECT
				id,
				added_date as timestamp,
				log_type,
				log_title as title,
				details as message,
				status,
				wc_id,
				xero_id
			FROM " . esc_sql( $table ) . "
			WHERE sent_to_pulse = 0
			ORDER BY added_date ASC
			LIMIT 1000",
			ARRAY_A
		);

		if ( ! $logs ) {
			return array();
		}

		// Transform logs to match Pulse API format
		$transformed_logs = array();
		$license_key = self::get_license_key();
		$user_id = self::get_user_id();
		$store_url = self::clean_domain( get_site_url() );

		foreach ( $logs as $log ) {
			// Map status: 0 = error, 1 = success, 2+ = other
			$status_int = (int) $log['status'];
			if ( $status_int < 1 ) {
				$type = 'error';
			} elseif ( $status_int === 1 ) {
				$type = 'success';
			} else {
				$type = 'info';
			}

			// Determine log_activity from title
			$log_activity = '';
			$title = $log['title'];
			if ( stripos( $title, 'create' ) !== false ) {
				$log_activity = 'Create';
			} elseif ( stripos( $title, 'update' ) !== false ) {
				$log_activity = 'Update';
			} elseif ( stripos( $title, 'delete' ) !== false ) {
				$log_activity = 'Delete';
			} elseif ( stripos( $title, 'import' ) !== false ) {
				$log_activity = 'Import';
			} elseif ( stripos( $title, 'sync' ) !== false ) {
				$log_activity = 'Sync';
			}

			$transformed_logs[] = array(
				'id'           => $log['id'],
				'timestamp'    => gmdate( 'c', strtotime( $log['timestamp'] ) ), // ISO 8601 format
				'user_id'      => $user_id,
				'license_key'  => $license_key,
				'store_url'    => $store_url,
				'product'      => 'WOOXERO',
				'log_type'     => $log['log_type'],
				'log_activity' => $log_activity,
				'type'         => $type,
				'title'        => $title,
				'message'      => $log['message'],
				'realm'    	   => '',
			);
		}

		return $transformed_logs;
	}

	/**
	 * Mark logs as sent to Pulse API
	 *
	 * @param array $logs Array of log entries that were sent
	 */
	public static function mark_logs_as_sent( $logs ) {
		global $wpdb;

		$table = self::get_plugin_table( 'log' );
		if ( '' === $table ) {
			return;
		}

		$ids = array_column( $logs, 'id' );
		if ( empty( $ids ) ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// $placeholders is a generated list of %d markers (one per id), not user data — the ids
		// themselves are passed to prepare(). The sniffs can't see placeholders that arrive via a
		// variable, hence the suppressions; the table name is esc_sql()'d as it can't be a placeholder.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch update for custom log table
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `" . esc_sql( $table ) . "`
				SET sent_to_pulse = 1, sent_to_pulse_at = NOW()
				WHERE id IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder -- $placeholders is a generated list of %d markers, not user data; the ids are passed to prepare()
				$ids
			)
		);
	}

	/**
	 * Send batched logs to central API with jitter
	 *
	 * This function is called by WP-Cron every 10 minutes.
	 * The jitter ensures requests are spread evenly across the time window.
	 */
	public static function send_sync_logs_to_api() {

		$user_id = self::get_user_id();
		if ( empty( $user_id ) ) {
			return;
		}

		// Collect logs from local database
		$logs = self::get_pending_logs();

		if ( empty( $logs ) ) {
			return;
		}

		// Prepare payload
		$payload = wp_json_encode( array( 'logs' => $logs ) );
		if ( ! $payload ) {
			return;
		}

		$license_key = self::get_license_key();
		if ( empty( $license_key ) ) {
			return;
		}

		$timestamp = gmdate( 'c' );

		// Sign with the per-site Pulse token; skip if unavailable
		$signing_key = self::get_signing_key();
		if ( empty( $signing_key ) ) {
			return;
		}
		$string_to_sign = $license_key . $timestamp . $payload;
		$signature = hash_hmac( 'sha256', $string_to_sign, $signing_key );

		// Try to compress if available
		$body = $payload;
		$headers = array(
			'Content-Type'      => 'application/json',
			'X-License-Key'     => $license_key,
			'X-Signature'       => $signature,
			'X-Timestamp'       => $timestamp,
			'X-Plugin-Version'  => MW_WC_XERO_SYNC_PLUGIN_VERSION,
		);

		if ( function_exists( 'gzencode' ) ) {
			$compressed = gzencode( $payload, 6 );
			if ( $compressed !== false ) {
				$body = $compressed;
				$headers['Content-Encoding'] = 'gzip';
			}
		}

		// Send request
		$response = wp_remote_post(
			self::PULSE_API_ENDPOINT,
			array(
				'headers' => $headers,
				'body'    => $body,
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			// Log error, will retry next cycle
			return;
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( $status_code === 200 ) {
			// Mark logs as sent
			self::mark_logs_as_sent( $logs );
		} else {
		}
	}

	/**
	 * Database migration: Add columns for tracking sent status
	 *
	 * Run this during plugin update to add the necessary columns.
	 */
	public static function db_migration() {
		global $wpdb;
		$table = self::get_plugin_table( 'log' );
		if ( '' === $table ) {
			// No safe name: bail rather than running DDL against an invalid table.
			return;
		}

		// Check if column exists
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Column existence check for migration
		$column_exists = $wpdb->get_var(
			"SELECT COUNT(*)
			FROM INFORMATION_SCHEMA.COLUMNS
			WHERE TABLE_SCHEMA = DATABASE()
			AND TABLE_NAME = '" . esc_sql( $table ) . "'
			AND COLUMN_NAME = 'sent_to_pulse'"
		);

		if ( ! $column_exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ALTER TABLE for migration
			$wpdb->query(
				"ALTER TABLE " . esc_sql( $table ) . "
				ADD COLUMN sent_to_pulse TINYINT(1) NOT NULL DEFAULT 0,
				ADD COLUMN sent_to_pulse_at DATETIME NULL,
				ADD INDEX idx_sent_to_pulse (sent_to_pulse)"
			);
		}
	}

	/**
	 * Get all plugin settings for snapshot
	 *
	 * @return array All settings as key-value pairs
	 */
	private static function get_all_settings() {
		$settings = array();

		// List all plugin setting keys
		$option_keys = array(
			// Sync configuration
			'mw_wc_xero_sync_aotc_rcm_data',
			'mw_wc_xero_sync_block_syncing_orders_before_id',
			'mw_wc_xero_sync_order_sync_as',
			'mw_wc_xero_sync_osa_pr_map_data',
			'mw_wc_xero_sync_s_order_when_status_in',
			'mw_wc_xero_sync_s_order_notes_to',
			'mw_wc_xero_sync_wc_user_roles_as_customer',
			'mw_wc_xero_sync_xero_inv_status_for_unp_ord',

			// Real-time sync settings
			'mw_wc_xero_sync_rt_push_items',
			'mw_wc_xero_sync_rt_pull_items',

			// Default mappings
			'mw_wc_xero_sync_default_tracking_category',
			'mw_wc_xero_sync_default_xero_account_foli',
			'mw_wc_xero_sync_default_xero_cogs_account_fnp',
			'mw_wc_xero_sync_default_xero_inventory_asset_account_fnp',
			'mw_wc_xero_sync_default_xero_product',
			'mw_wc_xero_sync_default_xero_sales_account_fnp',
			'mw_wc_xero_sync_default_xero_shipping_product',
			'mw_wc_xero_sync_fee_line_item_xero_product',
			'mw_wc_xero_sync_otli_xero_product',

			// Order settings
			'mw_wc_xero_sync_order_date_val',
			'mw_wc_xero_sync_order_line_item_desc_val_s',
			'mw_wc_xero_sync_only_add_slim_into_xero_oli',
			'mw_wc_xero_sync_new_customer_dname_format',

			// Payment settings
			'mw_wc_xero_sync_payment_pull_wc_order_status',
			'mw_wc_xero_sync_prevent_payment_pull_wc_order_status',
			'mw_wc_xero_sync_xero_payment_reference_val_s',

			// Product/Inventory settings
			'mw_wc_xero_sync_pulled_product_wc_status',
			'mw_wc_xero_sync_ivnt_pull_interval_time',

			// Tax settings
			'mw_wc_xero_sync_non_taxable_rate',

			// Currency settings
			'mw_wc_xero_sync_s_wc_store_currencies',

			// Queue and cron settings
			'mw_wc_xero_sync_queue_cron_interval_time',

			// Log settings
			'mw_wc_xero_sync_save_log_for_days',

			// Timestamps and metadata
			'mw_wc_xero_automap_last_c_data',
			'mw_wc_xero_last_cost_pull_timestamp',
			'mw_wc_xero_last_ivnt_pull_timestamp',
			'mw_wc_xero_last_payment_pull_timestamp',
			'mw_wc_xero_last_price_pull_timestamp',
			'mw_wc_xero_last_product_pull_timestamp',
			'mw_wc_xero_lbcr_chk_count_dt',

			// Database version
			'mw_wc_xero_plugin_db_version',
		);

		foreach ( $option_keys as $key ) {
			$value = get_option( $key );
			if ( $value !== false ) {
				$settings[ $key ] = $value;
			}
		}

		// IMPORTANT: Remove any sensitive data
		unset( $settings['mw_wc_xero_f_xc_key'] );
		unset( $settings['mw_wc_xero_localkey'] );
		unset( $settings['mw_wc_xero_plugin_licensing_secret_key'] );

		// Replace Xero account IDs with names for settings that contain account IDs
		$account_names = self::get_xero_account_names();
		$account_settings = array(
			'mw_wc_xero_sync_default_xero_account_foli',
			'mw_wc_xero_sync_default_xero_sales_account_fnp',
			'mw_wc_xero_sync_default_xero_inventory_asset_account_fnp',
			'mw_wc_xero_sync_default_xero_cogs_account_fnp',
		);

		foreach ( $account_settings as $setting_key ) {
			if ( isset( $settings[ $setting_key ] ) && ! empty( $settings[ $setting_key ] ) ) {
				$account_id = $settings[ $setting_key ];
				// Replace with name if available, otherwise keep ID
				if ( isset( $account_names[ $account_id ] ) && ! empty( $account_names[ $account_id ] ) ) {
					$settings[ $setting_key ] = $account_names[ $account_id ];
				}
			}
		}

		// Replace Xero product IDs with names for settings that contain product IDs
		$product_names = self::get_xero_product_names();
		$product_settings = array(
			'mw_wc_xero_sync_default_xero_product',
			'mw_wc_xero_sync_default_xero_shipping_product',
			'mw_wc_xero_sync_fee_line_item_xero_product',
			'mw_wc_xero_sync_otli_xero_product',
		);

		global $MWXS_L;
		foreach ( $product_settings as $setting_key ) {
			if ( isset( $settings[ $setting_key ] ) && ! empty( $settings[ $setting_key ] ) ) {
				$product_id = $settings[ $setting_key ];
				// Replace with name if available, otherwise keep ID
				if ( isset( $product_names[ $product_id ] ) && ! empty( $product_names[ $product_id ] ) ) {
					$old_value = $settings[ $setting_key ];
					$settings[ $setting_key ] = $product_names[ $product_id ];
				} else {
				}
			}
		}

		return $settings;
	}

	/**
	 * Get Xero account names for ID-to-name mapping
	 *
	 * @return array Associative array of account IDs/codes => names
	 */
	private static function get_xero_account_names() {
		global $MWXS_L;

		// Check if library is available and has the method
		if ( ! isset( $MWXS_L ) || ! is_object( $MWXS_L ) || ! method_exists( $MWXS_L, 'xero_get_accounts_kva' ) ) {
			return array();
		}

		// Establish Xero connection if method available
		if ( method_exists( $MWXS_L, 'xero_connect' ) ) {
			$MWXS_L->xero_connect();
		}

		try {
			$accounts = $MWXS_L->xero_get_accounts_kva();
			if ( is_array( $accounts ) ) {
				return $accounts;
			}
		} catch ( Exception $e ) {
			// Snapshot telemetry is best-effort, but a silent failure here degrades the snapshot to
			// an empty mapping with no record anywhere. Leave a diagnosable trail.
			if ( isset( $MWXS_L ) && is_object( $MWXS_L ) && method_exists( $MWXS_L, 'save_log' ) ) {
				$MWXS_L->save_log( array( 'type' => 'Queue', 'title' => 'Pulse Snapshot: Xero accounts Lookup Failed', 'details' => 'Lookup failed while building the Pulse snapshot. Exception: ' . get_class( $e ) . ' (code ' . (int) $e->getCode() . '). Message withheld: Xero exception text can contain response data, and log details are forwarded to Pulse.', 'status' => 0 ) );
			}
		}

		return array();
	}

	/**
	 * Get Xero product/item names for ID-to-name mapping
	 *
	 * @return array Associative array of product IDs => names
	 */
	private static function get_xero_product_names() {
		global $MWXS_L;

		// Check if library is available
		if ( ! isset( $MWXS_L ) || ! is_object( $MWXS_L ) || ! method_exists( $MWXS_L, 'xero_connect' ) ) {
			return array();
		}

		// Establish Xero connection
		$MWXS_L->xero_connect();

		// Check if connected
		if ( ! method_exists( $MWXS_L, 'is_xero_connected' ) || ! $MWXS_L->is_xero_connected() ) {
			return array();
		}

		try {
			// Fetch Xero items/products
			$tenant_id = method_exists( $MWXS_L, 'get_xero_tenant_id' ) ? $MWXS_L->get_xero_tenant_id() : null;
			if ( empty( $tenant_id ) ) {
				return array();
			}

			$result = $MWXS_L->X_API_I()->getItems( $tenant_id );
			if ( ! empty( $result ) && method_exists( $result, 'getItems' ) ) {
				$items = $result->getItems();
				if ( is_array( $items ) && ! empty( $items ) ) {
					$product_names = array();
					foreach ( $items as $item ) {
						$item_id = $item->getItemID();
						$item_name = $item->getName();
						$item_code = $item->getCode();

						// Always use ItemID as key (matches what's stored in settings)
						if ( ! empty( $item_id ) ) {
							$product_names[ $item_id ] = $item_name;
						}

						// Also add by code if available
						if ( ! empty( $item_code ) ) {
							$product_names[ $item_code ] = $item_name;
						}
					}

					return $product_names;
				}
			}
		} catch ( Exception $e ) {
			// Snapshot telemetry is best-effort, but a silent failure here degrades the snapshot to
			// an empty mapping with no record anywhere. Leave a diagnosable trail.
			if ( isset( $MWXS_L ) && is_object( $MWXS_L ) && method_exists( $MWXS_L, 'save_log' ) ) {
				$MWXS_L->save_log( array( 'type' => 'Queue', 'title' => 'Pulse Snapshot: Xero products Lookup Failed', 'details' => 'Lookup failed while building the Pulse snapshot. Exception: ' . get_class( $e ) . ' (code ' . (int) $e->getCode() . '). Message withheld: Xero exception text can contain response data, and log details are forwarded to Pulse.', 'status' => 0 ) );
			}
		}

		return array();
	}

	/**
	 * Get Xero tax rate names for ID-to-name mapping
	 *
	 * @return array Associative array of tax identifiers => names
	 */
	private static function get_xero_tax_names() {
		global $MWXS_L;

		// Check if library is available and has the method
		if ( ! isset( $MWXS_L ) || ! is_object( $MWXS_L ) || ! method_exists( $MWXS_L, 'xero_get_tax_rates_kva' ) ) {
			return array();
		}

		// Establish Xero connection if method available
		if ( method_exists( $MWXS_L, 'xero_connect' ) ) {
			$MWXS_L->xero_connect();
		}

		try {
			$tax_rates = $MWXS_L->xero_get_tax_rates_kva();
			if ( is_array( $tax_rates ) ) {
				return $tax_rates;
			}
		} catch ( Exception $e ) {
			// Snapshot telemetry is best-effort, but a silent failure here degrades the snapshot to
			// an empty mapping with no record anywhere. Leave a diagnosable trail.
			if ( isset( $MWXS_L ) && is_object( $MWXS_L ) && method_exists( $MWXS_L, 'save_log' ) ) {
				$MWXS_L->save_log( array( 'type' => 'Queue', 'title' => 'Pulse Snapshot: Xero tax rates Lookup Failed', 'details' => 'Lookup failed while building the Pulse snapshot. Exception: ' . get_class( $e ) . ' (code ' . (int) $e->getCode() . '). Message withheld: Xero exception text can contain response data, and log details are forwarded to Pulse.', 'status' => 0 ) );
			}
		}

		return array();
	}

	/**
	 * Get product and variation mapping counts
	 *
	 * @return array|null Counts of products, variations, and total
	 */
	private static function get_product_mapping_counts() {
		global $wpdb;
		$product_table = self::get_plugin_table( 'map_products' );
		$variation_table = self::get_plugin_table( 'map_variations' );
		if ( '' === $product_table || '' === $variation_table ) {
			// Return null, not zeros — the snapshot must be able to tell a resolution failure apart
			// from a store that genuinely has no product mappings.
			return null;
		}

		// get_var() returns null on a failed query and (int) null is 0, so without checking
		// last_error a database failure would be published as a genuine count of zero. Check
		// immediately after each query, before the cast.
		$wpdb->last_error = '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Count query for custom table
		$product_raw = $wpdb->get_var(
			"SELECT COUNT(*) FROM " . esc_sql( $product_table )
		);
		if ( '' !== (string) $wpdb->last_error || null === $product_raw ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Count query for custom table
		$variation_raw = $wpdb->get_var(
			"SELECT COUNT(*) FROM " . esc_sql( $variation_table )
		);
		if ( '' !== (string) $wpdb->last_error || null === $variation_raw ) {
			return null;
		}

		$product_count   = (int) $product_raw;
		$variation_count = (int) $variation_raw;

		return array(
			'products'   => $product_count,
			'variations' => $variation_count,
			'total'      => $product_count + $variation_count,
		);
	}

	/**
	 * Get all payment method mappings
	 *
	 * @return array|null Payment method mappings
	 */
	private static function get_payment_mappings() {
		global $wpdb;
		$table = self::get_plugin_table( 'map_payment_method' );
		if ( '' === $table ) {
			// null = failure, distinct from an empty mapping set.
			return null;
		}

		// get_results() returns an empty array for BOTH a failed query and a genuinely empty
		// table, so last_error is the only way to tell them apart. Without this an outage would
		// be published as "this store has no mappings".
		$wpdb->last_error = '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Query for custom table
		$results = $wpdb->get_results(
			"SELECT wc_payment_method, currency, enable_payment, X_ACC_ID, xero_account_name, enable_txn_fee, txn_fee_x_product, x_invoice_ddd, aps_order_status, enable_refund
			FROM " . esc_sql( $table ) . "
			ORDER BY wc_payment_method",
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return null;
		}

		if ( empty( $results ) ) {
			return array();
		}

		// Get Xero product names for ID-to-name mapping
		$product_names = self::get_xero_product_names();

		$mappings = array();
		foreach ( $results as $row ) {
			$account_id = $row['X_ACC_ID'];
			$account_name = $row['xero_account_name'];

			// Use name if available, otherwise fall back to ID
			$account_value = ! empty( $account_name ) ? $account_name : $account_id;

			// Replace product ID with name if available
			$txn_fee_product_id = $row['txn_fee_x_product'];
			$txn_fee_product_value = $txn_fee_product_id;
			if ( ! empty( $txn_fee_product_id ) && isset( $product_names[ $txn_fee_product_id ] ) && ! empty( $product_names[ $txn_fee_product_id ] ) ) {
				$txn_fee_product_value = $product_names[ $txn_fee_product_id ];
			}

			$mappings[] = array(
				'wc_method'       => $row['wc_payment_method'],
				'currency'        => $row['currency'],
				'enable_payment'  => (int) $row['enable_payment'],
				'xero_account_id' => $account_value,
				'enable_txn_fee'  => (int) $row['enable_txn_fee'],
				'txn_fee_product' => $txn_fee_product_value,
				'invoice_ddd'     => (int) $row['x_invoice_ddd'],
				'aps_status'      => $row['aps_order_status'],
				'enable_refund'   => (int) $row['enable_refund'],
			);
		}

		return $mappings;
	}

	/**
	 * Get all tax rate mappings
	 *
	 * @return array|null Tax rate mappings
	 */
	private static function get_tax_mappings() {
		global $wpdb;
		$table = self::get_plugin_table( 'map_tax' );
		if ( '' === $table ) {
			// null = failure, distinct from an empty mapping set.
			return null;
		}

		// get_results() returns an empty array for BOTH a failed query and a genuinely empty
		// table, so last_error is the only way to tell them apart. Without this an outage would
		// be published as "this store has no mappings".
		$wpdb->last_error = '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Query for custom table
		$results = $wpdb->get_results(
			"SELECT wc_tax_id, xero_tax, xero_tax_name
			FROM " . esc_sql( $table ) . "
			ORDER BY wc_tax_id",
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return null;
		}

		if ( empty( $results ) ) {
			return array();
		}

		$mappings = array();
		foreach ( $results as $row ) {
			$xero_tax_id = $row['xero_tax'];
			$xero_tax_name = $row['xero_tax_name'];

			// Use name if available, otherwise fall back to ID
			$xero_tax_value = ! empty( $xero_tax_name ) ? $xero_tax_name : $xero_tax_id;

			$mappings[] = array(
				'wc_tax'   => $row['wc_tax_id'],
				'xero_tax' => $xero_tax_value,
			);
		}

		return $mappings;
	}

	/**
	 * Collect and send daily store snapshot to Pulse
	 *
	 * @return bool Success status
	 */
	public static function send_pulse_snapshot() {
		global $MWXS_L;

		$user_id = self::get_user_id();
		if ( empty( $user_id ) ) {
			return false;
		}

		$license_key = self::get_license_key();
		if ( empty( $license_key ) ) {
			return false;
		}

		// Skip early when Pulse has no per-site token — avoids the Xero API calls below. Refs #122.
		$signing_key = self::get_signing_key();
		if ( empty( $signing_key ) ) {
			return false;
		}

		$store_url = self::clean_domain( get_site_url() );

		// Collect snapshot data. The three mapping getters return null on a table-resolution or query
		// failure, which is deliberately distinct from a legitimately empty mapping set: publishing
		// zeros/empties for a failed read would look like a store that had deliberately unmapped
		// everything. Abort the whole snapshot instead of sending data we can't stand behind.
		$product_counts   = self::get_product_mapping_counts();
		$payment_mappings = self::get_payment_mappings();
		$tax_mappings     = self::get_tax_mappings();

		if ( null === $product_counts || null === $payment_mappings || null === $tax_mappings ) {
			return false;
		}

		$snapshot = array(
			'settings' => self::get_all_settings(),
			'mappings' => array(
				'products'        => $product_counts,
				'payment_methods' => $payment_mappings,
				'tax_rates'       => $tax_mappings,
			),
			'orders_30d_avg' => self::get_orders_30d_avg(),
			'collected_at' => gmdate( 'c' ),
		);

		// Build payload
		$payload = array(
			'license_key' => $license_key,
			'store_url'   => $store_url,
			'product'     => 'WOOXERO',
			'snapshot'    => $snapshot,
		);

		$json_payload = wp_json_encode( $payload );
		if ( ! $json_payload ) {
			return false;
		}

		// Debug: Log the snapshot payload

		$timestamp = gmdate( 'c' );

		// Signing key already validated at the top of this method.
		$string_to_sign = $license_key . $timestamp . $json_payload;
		$signature = hash_hmac( 'sha256', $string_to_sign, $signing_key );

		// Compress
		$body = $json_payload;
		$headers = array(
			'Content-Type'     => 'application/json',
			'X-License-Key'    => $license_key,
			'X-Timestamp'      => $timestamp,
			'X-Signature'      => $signature,
			'X-Plugin-Version' => MW_WC_XERO_SYNC_PLUGIN_VERSION,
		);

		if ( function_exists( 'gzencode' ) ) {
			$compressed = gzencode( $json_payload, 9 );
			if ( $compressed !== false ) {
				$body = $compressed;
				$headers['Content-Encoding'] = 'gzip';
			}
		}

		// Send request
		$response = wp_remote_post(
			self::PULSE_API_ENDPOINT . 'snapshot',
			array(
				'timeout' => 30,
				'headers' => $headers,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code !== 200 ) {
			return false;
		}

		return true;
	}

	/**
	 * Schedule daily snapshot cron job
	 */
	private static function schedule_snapshot_cron() {
		// Random offset 0-6 hours to spread load across stores
		$random_offset = rand( 0, 21600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_rand

		// Schedule for midnight UTC (0:00 UTC) with random offset
		$tomorrow_utc = gmdate( 'Y-m-d', strtotime( '+1 day' ) );
		$next_midnight_utc = strtotime( $tomorrow_utc . ' 00:00:00 UTC' );
		$first_run = $next_midnight_utc + $random_offset;

		wp_schedule_event( $first_run, 'daily', 'mw_pulse_daily' );
	}

	/**
	 * Clean up on plugin deactivation
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'mw_pulse' );
		wp_clear_scheduled_hook( 'mw_pulse_daily' );
	}
}
