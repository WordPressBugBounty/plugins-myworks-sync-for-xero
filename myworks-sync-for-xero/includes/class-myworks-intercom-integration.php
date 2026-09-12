<?php
/**
 * Intercom Integration for MyWorks Plugin
 *
 * Adds Intercom messenger widget to plugin admin pages with JWT authentication
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
 * Intercom Integration Class
 *
 * Handles Intercom messenger widget with JWT authentication
 */
class MyWorks_Intercom_Integration {

	/**
	 * Intercom App ID
	 * Loaded from license API response
	 */
	private static $app_id = '';

	/**
	 * Intercom Identity Verification Secret
	 * Loaded from license API response
	 */
	private static $identity_secret = '';

	/**
	 * Initialize the Intercom integration
	 */
	public static function init() {
		global $MWXS_L;

		// Load app ID from license API
		self::$app_id = get_option( 'mw_wc_xero_intercom_app_id', '' );

		// Load and decrypt identity secret from license API
		$encrypted_secret = get_option( 'mw_wc_xero_intercom_secret', '' );
		if ( isset( $MWXS_L ) && is_object( $MWXS_L ) && method_exists( $MWXS_L, 'decrypt_secret' ) ) {
			self::$identity_secret = $MWXS_L->decrypt_secret( $encrypted_secret );
		} else {
			// Fallback to plaintext if decryption not available
			self::$identity_secret = $encrypted_secret;
		}

		// Hook into admin_init to ensure we're in admin context
		add_action( 'admin_init', array( __CLASS__, 'setup_hooks' ) );

		// Schedule daily error summary cron if not already scheduled
		if ( ! wp_next_scheduled( 'mwxs_intercom_daily_error_summary' ) ) {
			// Random offset 0-6 hours to spread load across stores
			$random_offset = rand( 0, 21600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_rand

			// Schedule for midnight UTC (0:00 UTC) with random offset
			$tomorrow_utc = gmdate( 'Y-m-d', strtotime( '+1 day' ) );
			$next_midnight_utc = strtotime( $tomorrow_utc . ' 00:00:00 UTC' );
			$first_run = $next_midnight_utc + $random_offset;

			wp_schedule_event( $first_run, 'daily', 'mwxs_intercom_daily_error_summary' );
		}

		// Hook the cron action
		add_action( 'mwxs_intercom_daily_error_summary', array( __CLASS__, 'send_daily_error_summary' ) );
	}

	/**
	 * Get the Intercom widget URL
	 *
	 * @return string Widget URL
	 */
	private static function get_widget_url() {
		if ( empty( self::$app_id ) ) {
			return '';
		}
		return 'https://widget.intercom.io/widget/' . self::$app_id;
	}

	/**
	 * Set up admin hooks after WordPress is fully loaded
	 */
	public static function setup_hooks() {
		add_action( 'admin_footer', array( __CLASS__, 'render_intercom_widget' ) );
	}

	/**
	 * Get user data for Intercom
	 *
	 * @return array|false User data array or false if not available
	 */
	private static function get_user_data() {
		// Get account data from license API response
		$account_id = get_option( 'mw_wc_xero_user_id', 0 );
		$account_email = get_option( 'mw_wc_xero_user_email', '' );
		$account_name = get_option( 'mw_wc_xero_user_name', '' );
		$registration_date = get_option( 'mw_wc_xero_registration_date', 0 );

		// Return false if no account ID (license not activated)
		if ( empty( $account_id ) ) {
			return false;
		}

		// Get currently logged-in WordPress user
		$current_user = wp_get_current_user();
		$wp_user_email = $current_user->user_email;
		$wp_user_first = $current_user->user_firstname;
		$wp_user_last = $current_user->user_lastname;
		$wp_user_display = $current_user->display_name;
		$wp_user_id = $current_user->ID;

		// Build WordPress user name
		$wp_user_name = '';
		if ( ! empty( $wp_user_first ) && ! empty( $wp_user_last ) ) {
			$wp_user_name = $wp_user_first . ' ' . $wp_user_last;
		} elseif ( ! empty( $wp_user_display ) ) {
			$wp_user_name = $wp_user_display;
		} else {
			$wp_user_name = $wp_user_email;
		}

		// Get Store URL from license domain or site URL
		$store_url = get_option( 'mw_wc_xero_domain', '' );
		if ( empty( $store_url ) ) {
			$store_url = get_site_url();
		}

		// Clean Store URL - remove protocol and www, but keep installation directory
		$store_url = preg_replace( '#^https?://#', '', $store_url ); // Remove http:// or https://
		$store_url = preg_replace( '#^www\.#', '', $store_url );      // Remove www.

		// Get License Key
		$license_key = get_option( 'mw_wc_xero_license', '' );

		// Get MyWorks Plan from license data
		$plan = self::get_license_plan();

		return array(
			// Account data (for company association)
			'account_id'            => $account_id,
			'account_email'         => $account_email,
			'account_name'          => $account_name,
			'registration_date'     => $registration_date,

			// WordPress user data (for individual identification)
			'wp_user_id'            => $wp_user_id,
			'wp_user_email'         => $wp_user_email,
			'wp_user_name'          => $wp_user_name,
			'wp_user_first'         => $wp_user_first,
			'wp_user_last'          => $wp_user_last,

			// Shared data
			'Store URL'             => $store_url,
			'eCommerce Platform'    => 'WooCommerce',
			'Accounting Platform'   => 'Xero',
			'MyWorks Plan'          => $plan,
			'License Key'           => $license_key,
			# Store-level metric, single-sourced from the Pulse integration so Pulse, Mixpanel
			# and Intercom all report the same number.
			'orders_30d_avg'        => class_exists( 'MyWorks_Pulse_Integration' ) ? MyWorks_Pulse_Integration::get_orders_30d_avg() : 0,
		);
	}

	/**
	 * Get the license plan name
	 *
	 * @return string Plan name (Launch, Rise, Grow, Scale, Soar)
	 */
	private static function get_license_plan() {
		global $MWXS_L;

		// Check if the library is loaded
		if ( ! isset( $MWXS_L ) || ! is_object( $MWXS_L ) ) {
			return '';
		}

		// Check if method exists
		if ( ! method_exists( $MWXS_L, 'get_ldfcpv' ) ) {
			return '';
		}

		// Get license data
		$license_data = $MWXS_L->get_ldfcpv();

		// Return plan if available
		if ( isset( $license_data['plan'] ) && ! empty( $license_data['plan'] ) ) {
			return $license_data['plan'];
		}

		return '';
	}

	/**
	 * Get company data for Intercom
	 *
	 * @return array Company data
	 */
	private static function get_company_data() {
		// Get site URL and parse domain
		$site_url = get_site_url();
		$parsed_url = wp_parse_url( $site_url );
		$site_domain = isset( $parsed_url['host'] ) ? $parsed_url['host'] : '';

		// Get company name from license response
		$company_name = get_option( 'mw_wc_xero_company_name', '' );

		// Check if we have a valid domain from license
		$license_domain = get_option( 'mw_wc_xero_domain', '' );
		if ( ! empty( $license_domain ) ) {
			$license_parsed = wp_parse_url( $license_domain );
			if ( isset( $license_parsed['host'] ) ) {
				$site_domain = $license_parsed['host'];
			}
		}

		// Use company name as company ID
		$company_id = $company_name;

		return array(
			'company_id'  => $company_id,
			'name'        => $company_name,
			'site_domain' => $site_domain,
		);
	}

	/**
	 * Generate Intercom JWT token for identity verification
	 *
	 * @param string $user_email The user email to generate token for
	 * @return string|false The JWT token or false if secret not configured
	 */
	private static function generate_intercom_jwt( $user_email ) {
		if ( empty( self::$identity_secret ) ) {
			return false;
		}

		// JWT Header
		$header = rtrim( strtr( base64_encode( wp_json_encode( array( 'alg' => 'HS256', 'typ' => 'JWT' ) ) ), '+/', '-_' ), '=' );

		// JWT Payload — matches Intercom's required format
		$payload = rtrim( strtr( base64_encode( wp_json_encode( array(
			'user_id' => $user_email, // Must match user_id passed to Intercom boot
			'email'   => $user_email,
			'exp'     => time() + 3600, // Expires in 1 hour
		) ) ), '+/', '-_' ), '=' );

		// Signature
		$signature = rtrim( strtr( base64_encode( hash_hmac( 'sha256', $header . '.' . $payload, self::$identity_secret, true ) ), '+/', '-_' ), '=' );

		return $header . '.' . $payload . '.' . $signature;
	}

	/**
	 * Check if we should show Intercom on current admin page
	 *
	 * @return bool True if should show, false otherwise
	 */
	private static function should_show_intercom() {
		// Only show on plugin pages
		$screen = get_current_screen();

		if ( ! $screen ) {
			return false;
		}

		// Check if we're on a MyWorks plugin page
		$plugin_pages = array(
			'toplevel_page_myworks-wc-xero-sync',
			'myworks-xero-sync_page_myworks-wc-xero-sync-connection',
			'myworks-xero-sync_page_myworks-wc-xero-sync-settings',
			'myworks-xero-sync_page_myworks-wc-xero-sync-map-data',
			'myworks-xero-sync_page_myworks-wc-xero-sync-logs',
			'myworks-xero-sync_page_myworks-wc-xero-sync-queue',
		);

		// Check if current page ID starts with our plugin prefix
		if ( strpos( $screen->id, 'myworks-wc-xero-sync' ) !== false ) {
			return true;
		}

		return in_array( $screen->id, $plugin_pages, true );
	}

	/**
	 * Render the Intercom messenger widget
	 */
	public static function render_intercom_widget() {
		// Check if we should show on this page
		if ( ! self::should_show_intercom() ) {
			return;
		}

		// Get user data
		$user_data = self::get_user_data();

		if ( ! $user_data ) {
			return;
		}

		// Generate JWT for identity verification using WordPress user email
		$user_jwt = self::generate_intercom_jwt( $user_data['wp_user_email'] );

		// Check if app ID is configured
		if ( empty( self::$app_id ) ) {
			return;
		}

		// Get company data
		$company_data = self::get_company_data();

		// Build Intercom settings - identify the actual logged-in WordPress user
		$intercom_settings = array(
			'app_id'       			=> self::$app_id,
			'api_base'              => 'https://api-iam.intercom.io',
			'email'        			=> $user_data['wp_user_email'], // The logged-in WordPress user's email
			'name'         			=> $user_data['wp_user_name'], // The logged-in WordPress user's name
			'user_id'      			=> $user_data['wp_user_email'], // Use WordPress email as user_id (Intercom unique identifier)
			'MyWorks Plan'      	=> $user_data['MyWorks Plan'], // Their MyWorks plan
			'MyWorks Account Email' => $user_data['account_email'], // The main account email from license response
			'Accounting Platform'   => $user_data['Accounting Platform'],   // "Xero"
			'eCommerce Platform'    => $user_data['eCommerce Platform'], // "WooCommerce"
			'Store URL'				=> $user_data['Store URL'], // Store URL
			'License Key'			=> $user_data['License Key'], // License Key
			'orders_30d_avg'		=> $user_data['orders_30d_avg'], // Avg orders/month: last 90 days / 3

			// Associate with their company/account
			'company'      => array(
				'company_id'          	 => $company_data['name'], // Account ID from license
				'name'                	 => $company_data['name'], // Company name from license
				'Plan'                	 => $user_data['MyWorks Plan'], // Their MyWorks plan
				'Account Email'       	 => $user_data['account_email'], // Main account email from license
				'Accounting Platform MW' => $user_data['Accounting Platform'], // "Xero"
				'eCommerce Platform MW'  => $user_data['eCommerce Platform'], // "WooCommerce"
				'Store URL MW'           => $user_data['Store URL'], // Store URL
				'License Key MW'		 => $user_data['License Key'], // License Key
				'orders_30d_avg'		 => $user_data['orders_30d_avg'], // Avg orders/month: last 90 days / 3
			),
		);

		// Add registration date (Signed Up) if available
		if ( ! empty( $user_data['registration_date'] ) ) {
			// Convert to Unix timestamp if needed
			$intercom_settings['Signed up'] = is_numeric( $user_data['registration_date'] )
				? (int) $user_data['registration_date']
				: strtotime( $user_data['registration_date'] );
		}

		// Add JWT if available (for identity verification)
		if ( $user_jwt ) {
			$intercom_settings['intercom_user_jwt'] = $user_jwt;
		}

		// Get widget URL
		$widget_url = self::get_widget_url();

		// Output Intercom widget code
		?>
		<!-- Intercom Messenger Widget -->
		<script>
		// Intercom widget loader
		(function(){var w=window;var ic=w.Intercom;if(typeof ic==="function"){ic('reattach_activator');ic('update',w.intercomSettings);}else{var d=document;var i=function(){i.c(arguments);};i.q=[];i.c=function(args){i.q.push(args);};w.Intercom=i;var l=function(){var s=d.createElement('script');s.type='text/javascript';s.async=true;s.src='<?php echo esc_url( $widget_url ); ?>';var x=d.getElementsByTagName('script')[0];x.parentNode.insertBefore(s,x);};if(document.readyState==='complete'){l();}else if(w.attachEvent){w.attachEvent('onload',l);}else{w.addEventListener('load',l,false);}}})();
		</script>

		<script>
		// Boot Intercom with user and company data
		var intercomSettings = <?php echo wp_json_encode( $intercom_settings ); ?>;

		// Debug: Log Intercom settings

		// Identify the actual logged-in user
		window.Intercom('boot', intercomSettings);
		</script>
		<?php
		// Output and clear any queued events
		self::output_queued_events();
		?>
		<!-- End Intercom Messenger Widget -->
		<?php
	}

	/**
	 * Track the plugin deactivation event.
	 * Queued and sent via JS when the admin next visits a plugin page.
	 *
	 * @since 1.3.2
	 * @return bool True if event was queued, false otherwise
	 */
	public static function track_deactivation_event() {
		return self::track_event( 'Plugin: Deactivated' );
	}

	/**
	 * Track an event to Intercom
	 * Events are queued and sent when user next visits a plugin page with Intercom loaded
	 *
	 * @since 1.3.2
	 * @param string $event_name The event name (e.g., "Plugin: Activated")
	 * @param array  $metadata   Optional event metadata
	 * @return bool True if event was queued, false otherwise
	 */
	public static function track_event( $event_name, $metadata = array() ) {
		// Check if Intercom is configured
		if ( empty( self::$app_id ) && empty( get_option( 'mw_wc_xero_intercom_app_id', '' ) ) ) {
			return false;
		}

		// Get queued events
		$queued_events = get_option( 'mw_wc_xero_intercom_queued_events', array() );

		// Add new event to queue
		$queued_events[] = array(
			'event_name' => $event_name,
			'metadata'   => $metadata,
			'timestamp'  => time(),
		);

		// Save queue (limit to last 50 events to prevent bloat)
		$queued_events = array_slice( $queued_events, -50 );
		update_option( 'mw_wc_xero_intercom_queued_events', $queued_events );

		return true;
	}

	/**
	 * Output queued events as JavaScript and clear the queue
	 * Called automatically when Intercom widget is rendered
	 *
	 * @since 1.3.2
	 */
	private static function output_queued_events() {
		$queued_events = get_option( 'mw_wc_xero_intercom_queued_events', array() );

		if ( empty( $queued_events ) ) {
			return;
		}

		echo "\n<script>\n";
		echo "// Send queued Intercom events\n";

		foreach ( $queued_events as $event ) {
			$event_name = esc_js( $event['event_name'] );
			$has_metadata = ! empty( $event['metadata'] );

			if ( $has_metadata ) {
				$metadata = wp_json_encode( $event['metadata'] );
				echo "window.Intercom('trackEvent', '" . esc_js( $event['event_name'] ) . "', " . wp_json_encode( $event['metadata'] ) . ");\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode and esc_js used
			} else {
				echo "window.Intercom('trackEvent', '" . esc_js( $event['event_name'] ) . "');\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_js used
			}
		}

		echo "</script>\n";

		// Clear the queue after sending
		delete_option( 'mw_wc_xero_intercom_queued_events' );
	}

	/**
	 * Track first sync event if it hasn't been tracked yet
	 *
	 * @since 1.3.2
	 * @param string $sync_type The type of first sync (order, customer, product, inventory)
	 * @return bool True if first sync was tracked, false if already tracked
	 */
	public static function track_first_sync( $sync_type ) {
		$option_key = 'mw_wc_xero_intercom_first_sync_' . $sync_type;
		$already_synced = get_option( $option_key, false );

		if ( $already_synced ) {
			return false; // Already tracked
		}

		// Track the event
		$event_name = 'First Sync: ' . ucfirst( $sync_type );
		self::track_event( $event_name );

		// Mark as tracked
		update_option( $option_key, true );

		return true;
	}

	/**
	 * Track milestone events for orders synced
	 *
	 * @since 1.3.2
	 * @param int $total_orders_synced Total number of orders synced
	 * @return bool True if milestone was tracked, false otherwise
	 */
	public static function track_milestone( $total_orders_synced ) {
		// Define milestones
		$milestones = array( 100, 500, 1000, 5000, 10000, 25000, 50000, 100000 );

		// Get already tracked milestones
		$tracked_milestones = get_option( 'mw_wc_xero_intercom_tracked_milestones', array() );

		// Check which milestone(s) were just reached
		foreach ( $milestones as $milestone ) {
			if ( $total_orders_synced >= $milestone && ! in_array( $milestone, $tracked_milestones, true ) ) {
				// Track the milestone
				self::track_event( 'Sync: Milestone', array(
					'orders_synced' => $milestone,
				) );

				// Mark as tracked
				$tracked_milestones[] = $milestone;
			}
		}

		// Save tracked milestones
		update_option( 'mw_wc_xero_intercom_tracked_milestones', $tracked_milestones );

		return true;
	}

	/**
	 * Track plan change event
	 *
	 * @since 1.3.2
	 * @param string $previous_plan Previous plan name
	 * @param string $new_plan New plan name
	 * @return bool True if event was tracked
	 */
	public static function track_plan_change( $previous_plan, $new_plan ) {
		if ( empty( $previous_plan ) || empty( $new_plan ) || $previous_plan === $new_plan ) {
			return false;
		}

		self::track_event( 'Plan: Changed', array(
			'previous_plan' => $previous_plan,
			'new_plan'      => $new_plan,
		) );

		return true;
	}

	/**
	 * Send daily error summary to Intercom
	 * Runs via cron job daily at midnight UTC with random 0-6 hour offset
	 *
	 * @since 1.3.2
	 * @return bool True if summary was sent, false otherwise
	 */
	public static function send_daily_error_summary() {
		global $wpdb;

		// Get error logs from the last 24 hours.
		// Resolve the table through the plugin's whitelist helper rather than hand-building it from
		// $wpdb->prefix — the plugin's table prefix comes from MW_WC_XERO_SYNC_PLUGIN_DB_TABLE_PREFIX
		// and is not guaranteed to equal $wpdb->prefix. See docs/rules/security.md.
		// Shared implementation, so this fallback logic exists in exactly one place —
		// see MyWorks_WC_Xero_Sync_Core::resolve_plugin_table().
		$table_name = MyWorks_WC_Xero_Sync_Core::resolve_plugin_table( 'log' );
		if ( '' === $table_name ) {
			return false;
		}

		// Check if table exists
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time table existence check
		$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '" . esc_sql( $wpdb->esc_like( $table_name ) ) . "'" ) === $table_name;
		if ( ! $table_exists ) {
			return false;
		}

		// Get timestamp for 24 hours ago
		$yesterday = gmdate( 'Y-m-d H:i:s', strtotime( '-24 hours' ) );

		// Query error logs (status = 0 means error). The table name can't be a placeholder, so it is
		// resolved through the whitelist above and concatenated with esc_sql() — matching the pattern
		// in docs/rules/security.md. The only value in the query is passed to prepare().
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name validated via whitelist then esc_sql()'d; the only value is prepared
		$error_logs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT log_type, log_title, details
				FROM `" . esc_sql( $table_name ) . "`
				WHERE status = 0
				AND added_date >= %s
				ORDER BY added_date DESC",
				$yesterday
			),
			ARRAY_A
		);

		// If no errors, send event with 0 count
		if ( empty( $error_logs ) ) {
			self::track_event( 'Sync: Daily Error Summary', array(
				'error_count' => 0,
				'top_error'   => 'No errors',
			) );
			return true;
		}

		// Count total errors
		$error_count = count( $error_logs );

		// Find most common error by log_title
		$error_frequency = array();
		foreach ( $error_logs as $log ) {
			$error_key = $log['log_type'] . ': ' . $log['log_title'];
			if ( ! isset( $error_frequency[ $error_key ] ) ) {
				$error_frequency[ $error_key ] = 0;
			}
			$error_frequency[ $error_key ]++;
		}

		// Sort by frequency and get top error
		arsort( $error_frequency );
		$top_error = key( $error_frequency );
		$top_error_count = reset( $error_frequency );

		// Add frequency to top error string
		$top_error_display = $top_error . ' (' . $top_error_count . 'x)';

		// Track the event
		self::track_event( 'Sync: Daily Error Summary', array(
			'error_count' => $error_count,
			'top_error'   => $top_error_display,
		) );

		return true;
	}

	/**
	 * Clear scheduled cron jobs on deactivation
	 *
	 * @since 1.3.2
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( 'mwxs_intercom_daily_error_summary' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'mwxs_intercom_daily_error_summary' );
		}
	}
}
