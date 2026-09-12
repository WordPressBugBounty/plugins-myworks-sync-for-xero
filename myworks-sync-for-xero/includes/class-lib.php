<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom database tables for Xero sync operations
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       https://myworks.software/
 * @since      1.0.0
 *
 * @package    MyWorks_WC_Xero_Sync
 * @subpackage MyWorks_WC_Xero_Sync/includes
 */

/**
 * The core plugin class.
 *
 * This is used to define custom plugin functions.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @package    MyWorks_WC_Xero_Sync
 * @subpackage MyWorks_WC_Xero_Sync/includes
 * @author     MyWorks Software <support@myworks.software>
 */

# Xero Lib
require_once plugin_dir_path( __FILE__ ) . 'lib/xero-lib/vendor/autoload.php';

# Guzzle Functions - Ensure Guzzle's helper functions are loaded before Xero SDK uses them
// Composer doesn't always auto-load functions.php, so we load it explicitly
$guzzle_functions = plugin_dir_path( __FILE__ ) . 'lib/xero-lib/vendor/guzzlehttp/guzzle/src/functions.php';
if ( file_exists( $guzzle_functions ) ) {
	require_once $guzzle_functions;
}

use XeroAPI\XeroPHP\AccountingObjectSerializer;

#Plugin Lib
require_once plugin_dir_path( __FILE__ ) . 'class-config.php';
require_once plugin_dir_path( __FILE__ ) . 'class-functions/class-core-functions.php';

class MyWorks_WC_Xero_Sync_Lib extends MyWorks_WC_Xero_Sync_Core {

	/**
	 * How long a cached Xero reference list (accounts, tax rates, tracking categories/options)
	 * stays valid before it is refetched. The explicit "Rescan Xero Data" action still bypasses
	 * the cache entirely via the $realtime flag.
	 */
	const KVA_CACHE_TTL_SECONDS = 86400; // 24 hours.

	/** Prefix for the per-list cache-timestamp options (one option per list, not a shared array). */
	const KVA_CACHE_META_PREFIX = 'mw_wc_xero_sync_kva_cached_at_';

	/** Transient prefix and lifetime for the single-refresher lock on an expired list. */
	const KVA_REFRESH_LOCK_PREFIX  = 'mw_wc_xero_sync_kva_lock_';
	const KVA_REFRESH_LOCK_SECONDS = 60;

	/**
	 * Per-order push lock: option prefix, and how long a holder may keep it before another push may
	 * take it over. A push is invoice + inline payment/refunds + optional email, normally seconds; the
	 * window only matters when a request died mid-push, and then blocking a re-push of that one order
	 * for a few minutes is the safe side of the trade. Refs #146
	 */
	const ORDER_PUSH_LOCK_PREFIX  = 'mw_wc_xero_sync_order_push_lock_';
	const ORDER_PUSH_LOCK_SECONDS = 300;

	/**
	 * How far the Product pull's own watermark may sit ahead of a shared Items fetch's start before
	 * Product is given its own request instead. Enabled pulls advance within a second or two of each
	 * other every tick, so this only separates that normal drift from the minutes/hours a
	 * just-enabled pull type introduces. 5 minutes = the shortest cron interval. See X_Pull_Items().
	 */
	const PULL_WATERMARK_DRIFT_TOLERANCE = 300;

	# TRACKING_MAP_EMPTY_FLAG was removed here: the tracking map's bespoke negative cache became the
	# shared KVA one (kva_empty_flag_key()), which covers all five reference lists instead of just
	# that one. Any `mw_wc_xero_sync_tracking_map_empty` transient still set on a live site is
	# orphaned but harmless — it expires within the hour and nothing reads it. Refs #87

	/**
	 * Contacts per page on the replica sweep. Xero's default is 100 and 1000 is the documented
	 * maximum, so a 10k-contact book drops from ~100 calls to ~10. Ported from the Shopify app
	 * (myworks-shopify-xero db582dd). CONTACT_SWEEP_PAGE_SIZE and the short-page check in
	 * xero_refresh_customers() are one unit - see the comment there before changing either.
	 */
	const CONTACT_SWEEP_PAGE_SIZE = 1000;

	/**
	 * Runaway guard on the paged contact sweep (100 x 1000 = 100k contacts). Hitting it leaves the
	 * sweep marked incomplete, which suppresses the prune rather than truncating the replica.
	 */
	const CONTACT_SWEEP_PAGE_CAP = 100;

	/** Per-request memo for the tracking map; null = not yet resolved this request. */
	private $tracking_map_memo = null;

	protected $plugin_license_url;
	protected $xero_c_dashboard_url;
	
	protected $plugin_license_status = '';
	protected $is_valid_license = false;
	private $license_data_for_conn_page_view;
	
	private $XeroTenantId;
	private $AccessToken;
	
	private $XAccountingApiInstance;
	
	private $is_xero_connected;
	private $XeroCompanyDetails;
	
	private $_disable_all_sync = false;

	private $_plugin_db_version = '1.0.4';
	
	public function __construct(){
		parent::__construct();
		
		#New
		$this->plugin_license_url = MyWorks_WC_Xero_Sync_C_Settings::Get_C_Setting('plugin_license_url');
		$this->xero_c_dashboard_url = MyWorks_WC_Xero_Sync_C_Settings::Get_C_Setting('xero_c_dashboard_url');
		
		#global $wpdb;
		#$wpdb->query('SET SQL_BIG_SELECTS=1');
		
		if(!$this->is_valid_license){
			$mwxs_t0 = microtime(true); // TEMP perf (Refs #83)
			$this->is_valid_license($this->get_option('mw_wc_xero_license'),$this->get_option('mw_wc_xero_localkey'));
			$this->mwxs_perf_log('license_check (constructor)',$mwxs_t0);
		}

		$this->is_xero_connected = false;
	}

	# TEMP: log elapsed time to pinpoint admin-page slowness on the live site. View under
	# WooCommerce > Status > Logs (source: mwxs-perf). Remove after diagnosis. Refs #83
	private function mwxs_perf_log($label,$t0){
		if(function_exists('wc_get_logger')){
			$ms = round((microtime(true) - $t0) * 1000);
			wc_get_logger()->debug($label.': '.$ms.'ms', array('source'=>'mwxs-perf'));
		}
	}

	/**
	 * Strip customer email addresses out of text destined for save_log().
	 *
	 * WHY THIS IS NEEDED — save_log() rows are NOT local-only. Pulse's get_pending_logs()
	 * ([class-myworks-pulse-integration.php](class-myworks-pulse-integration.php)) selects
	 * `details as message` and forwards it off-site, so anything written into a log row can leave the
	 * merchant's store. That makes a shopper's email address in a log entry a privacy problem, not an
	 * untidy string.
	 *
	 * The non-obvious half: you can leak an address without ever naming it. Xero's ApiException
	 * message embeds the FULL request URL, and a contact lookup's URL carries the email in its
	 * `where` clause (`where=EmailAddress%3D%3D%22someone%40example.com%22`), so logging the raw
	 * exception message is enough on its own. Both the plain and URL-encoded (`%40`) forms are
	 * handled.
	 *
	 * The local-part class deliberately excludes `%` so the match cannot walk backwards through an
	 * encoded query string and swallow the endpoint name — we want to keep "…/Contacts?where=…",
	 * which is the part that tells you which call failed.
	 *
	 * Redacts more than the strict minimum where it is ambiguous; over-redacting a log line is the
	 * safe direction.
	 *
	 * @param string $text Text about to be written to a log row.
	 * @return string Same text with any email addresses replaced.
	 */
	public function redact_log_pii($text){
		return (string) preg_replace('/[A-Za-z0-9._+-]+(?:@|%40)[A-Za-z0-9._-]+\.[A-Za-z]{2,}/', '[email redacted]', (string) $text);
	}

	/**
	 * Check if HPOS is enabled
	 * @return bool
	 */
	private function is_hpos_enabled() {
		return class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil') && 
		       \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Get order date in HPOS-compatible way
	 * @param mixed $order Order object or post object
	 * @return string Formatted date string
	 */
	private function get_hpos_order_date($order) {

		if (method_exists($order, 'get_date_created')) {
			$date_created = $order->get_date_created();
			if ( !empty($date_created) ) {
				$order_date = $date_created->date('Y-m-d H:i:s');
				if ( !empty($order_date) ) {
					return $order_date;
				}
			}
		}

		if(property_exists( $order, 'post_date' ) && ! empty( $order->post_date )) {
			return $order->post_date;
		}

		// Final fallback to current time
		return current_time('mysql');
	}

	/**
	 * Generate a legacy meta-style associative array for a given HPOS order.
	 * Uses WooCommerce's internal mapping for long-term compatibility.
	 *
	 * @param WC_Order $order WooCommerce order object (HPOS or legacy).
	 * @return array Legacy-style meta key => value array.
	 */
	public function get_hpos_order_legacy_meta_array($order)
	{
		$legacy_meta = array();

		if (! $order) {
			return $legacy_meta;
		}

		if ($order instanceof WC_Order_Refund) {
			$refund = $order;
			$refund_meta_key_to_props = array(
				'_refund_amount'    => 'amount',
				'_refunded_by'      => 'refunded_by',
				'_refunded_payment' => 'refunded_payment',
				'_refund_reason'    => 'reason',
			);
			foreach ($refund_meta_key_to_props as $meta_key => $prop) {
				$value = $refund->{"get_$prop"}('edit');
				$legacy_meta[ $meta_key ] = $value;
			}
			$order_id = $order->get_parent_id();
			$order = wc_get_order($order_id);
		}

		// Native WooCommerce property mappings from WC_Order_Data_Store_CPT
		$meta_key_to_props = array(
			'_order_key'                    => 'order_key',
			'_customer_user'                => 'customer_id',
			'_payment_method'               => 'payment_method',
			'_payment_method_title'         => 'payment_method_title',
			'_transaction_id'               => 'transaction_id',
			'_customer_ip_address'          => 'customer_ip_address',
			'_customer_user_agent'          => 'customer_user_agent',
			'_created_via'                  => 'created_via',
			'_date_completed'               => 'date_completed',
			'_date_paid'                    => 'date_paid',
			'_cart_hash'                    => 'cart_hash',
			'_download_permissions_granted' => 'download_permissions_granted',
			'_recorded_sales'               => 'recorded_sales',
			'_recorded_coupon_usage_counts' => 'recorded_coupon_usage_counts',
			'_new_order_email_sent'         => 'new_order_email_sent',
			'_order_stock_reduced'          => 'order_stock_reduced',
		);

		$address_props = array(
			'billing'  => array(
				'_billing_first_name' => 'billing_first_name',
				'_billing_last_name'  => 'billing_last_name',
				'_billing_company'    => 'billing_company',
				'_billing_address_1'  => 'billing_address_1',
				'_billing_address_2'  => 'billing_address_2',
				'_billing_city'       => 'billing_city',
				'_billing_state'      => 'billing_state',
				'_billing_postcode'   => 'billing_postcode',
				'_billing_country'    => 'billing_country',
				'_billing_email'      => 'billing_email',
				'_billing_phone'      => 'billing_phone',
			),
			'shipping' => array(
				'_shipping_first_name' => 'shipping_first_name',
				'_shipping_last_name'  => 'shipping_last_name',
				'_shipping_company'    => 'shipping_company',
				'_shipping_address_1'  => 'shipping_address_1',
				'_shipping_address_2'  => 'shipping_address_2',
				'_shipping_city'       => 'shipping_city',
				'_shipping_state'      => 'shipping_state',
				'_shipping_postcode'   => 'shipping_postcode',
				'_shipping_country'    => 'shipping_country',
				'_shipping_phone'      => 'shipping_phone',
			),
		);

		$legacy_meta_key_to_props = array(
			'_order_currency'     => 'currency',
			'_cart_discount'      => 'discount_total',
			'_cart_discount_tax'  => 'discount_tax',
			'_order_shipping'     => 'shipping_total',
			'_order_shipping_tax' => 'shipping_tax',
			'_order_tax'          => 'cart_tax',
			'_order_total'        => 'total',
			'_order_version'      => 'version',
			'_prices_include_tax' => 'prices_include_tax',
		);

		// Convert core props.
		foreach ($meta_key_to_props as $meta_key => $prop) {
			$value = $order->{"get_$prop"}('edit');
			switch ($prop) {
				case 'date_paid':
				case 'date_completed':
					$value = ! is_null($value) ? $value->getTimestamp() : '';
					break;
				case 'download_permissions_granted':
				case 'recorded_sales':
				case 'recorded_coupon_usage_counts':
				case 'order_stock_reduced':
					$value = is_bool($value) ? wc_bool_to_string($value) : $value;
					break;
				case 'new_order_email_sent':
					if (! empty($value)) {
						$value = wc_bool_to_string((bool) $value);
						$value = 'yes' === $value ? 'true' : 'false';
					}
					break;
				default:
					$value = is_string($value) ? wp_unslash($value) : $value;
			}
			$legacy_meta[ $meta_key ] = $value;
		}

		// Add billing and shipping props.
		foreach ($address_props as $group => $props) {
			foreach ($props as $meta_key => $prop) {
				$value = $order->{"get_$prop"}('edit');
				$legacy_meta[ $meta_key ] = is_string($value) ? wp_unslash($value) : $value;
			}
		}

		foreach ($legacy_meta_key_to_props as $meta_key => $prop) {
			$value = $order->{"get_$prop"}('edit');
			$value = is_string($value) ? wp_slash($value) : $value;

			if ('prices_include_tax' === $prop) {
				$value = $value ? 'yes' : 'no';
			}

			$legacy_meta[ $meta_key ] = $value;
		}

		$legacy_meta['_shipping_email'] = $order->get_billing_email();

		//Legacy compatibility fields
		$legacy_meta['_paid_date']      = $order->get_date_paid() ? $order->get_date_paid()->date('Y-m-d H:i:s') : null;
		$legacy_meta['_completed_date'] = $order->get_date_completed() ? $order->get_date_completed()->date('Y-m-d H:i:s') : null;

		return $legacy_meta;
	}

	/**
	 * Retrieve order meta (compatible with both HPOS and legacy tables).
	 *
	 * @param int    $order_id Order ID.
	 * @param string $meta_key Meta key to retrieve.
	 * @param bool   $single   Whether to return a single value.
	 * @return mixed Meta value or array of values.
	 */
	private function get_order_meta_hpos($order_id, $meta_key, $single = true) {
		if ($this->is_hpos_enabled()) {
			$order = wc_get_order($order_id);
			if (! $order) {
				return $single ? '' : [];
			}

			// Get legacy-style associative array from HPOS order.
			$legacy_meta_array =  $this->get_hpos_order_legacy_meta_array($order);

			if (array_key_exists($meta_key, $legacy_meta_array)) {
				return $legacy_meta_array[$meta_key];
			}

			// Fallback: Try native meta (in case of custom meta not in legacy map).
			$fallback = $order->get_meta($meta_key, $single);
			return $fallback ?: ($single ? '' : []);
		}

		// Legacy (non-HPOS) fallback
		return get_post_meta($order_id, $meta_key, $single);
	}


	/**
	 * HPOS-compatible function to get all order meta
	 * @param int $order_id
	 * @return array
	 */
	private function get_all_order_meta_hpos($order_id) {
		if ($this->is_hpos_enabled()) {
			$order = wc_get_order($order_id);

			if (! $order) {
				return array();
			}

			$meta_data = $order->get_meta_data();
			$all_meta  = array();

			foreach ($meta_data as $meta) {
				$key   = $meta->key;
				$value = $meta->value;

				if (! isset($all_meta[ $key ])) {
					$all_meta[ $key ] = array();
				}

				$all_meta[ $key ][] = $value;
			}

			$legacy_meta = $this->get_hpos_order_legacy_meta_array($order);

			foreach ($legacy_meta as $key => $value) {
				if ($value === '' || $value === null) {
					continue;
				}
				if (! isset($all_meta[ $key ])) {
					$all_meta[ $key ] = array();
				}
				if (! in_array($value, $all_meta[ $key ], true)) {
					$all_meta[ $key ][] = $value;
				}
			}

			return $all_meta;
		}
		return get_post_meta($order_id);
	}
	
	public function debug(){		
		global $wpdb;
		
		if($this->is_xero_connected()){
			#$d = $this->get_xero_org_actions();			
			#$d = $this->xero_get_accounts_kva();
			
			#$this->_p($d);
			
		}		
	}
	
	# Init Hook Function
	public function init(){
		if($this->_plugin_db_version != $this->get_option('mw_wc_xero_plugin_db_version')){
			$this->alter_plugin_db_tables();
		}		
	}

	# Alter plugin DB tables
	private function alter_plugin_db_tables(){
		global $wpdb;
		$server_db = $this->db_check_get_fields_details();
		if(is_array($server_db) && count($server_db)){
			# Existing Tables Field Add / Update
			$is_db_updated = false;
			foreach($server_db as $k=>$v){
				# Payment Method Map
				if($k == $this->gdtn('map_payment_method')){
					// Security: Validate table name before using in SQL
					$validated_table = $this->get_validated_table_name_from_full($k);
					if ($validated_table) {
						if(!array_key_exists("aps_order_status",$v)){
							$sql = "ALTER TABLE `" . esc_sql($validated_table) . "` ADD `aps_order_status` VARCHAR(255) NOT NULL AFTER `x_invoice_ddd`;";
							// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name validated and escaped
							$wpdb->query($sql);
							$is_db_updated = true;
						}

						if(!array_key_exists("enable_refund",$v)){
							$sql = "ALTER TABLE `" . esc_sql($validated_table) . "` ADD `enable_refund` int(1) NOT NULL AFTER `aps_order_status`;";
							// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name validated and escaped
							$wpdb->query($sql);
							$is_db_updated = true;
						}
					}
				}
			}
			
			# New Tables
			$is_new_db_tbl_created = false;
			// Security: Use validated table name for map_multiple
			$table_multiple = $this->get_validated_table_name('map_multiple');
			if(!isset($server_db[$this->gdtn('map_multiple')]) && $table_multiple){
				$sql = "CREATE TABLE IF NOT EXISTS `" . esc_sql($table_multiple) . "` (
					`id` int(11) NOT NULL AUTO_INCREMENT,
					`wc_type` varchar(255) NOT NULL,
					`wc_id` bigint(20) NOT NULL,
					`x_type` varchar(255) NOT NULL,
					`x_id` varchar(255) NOT NULL,	
					PRIMARY KEY (id)
				)";

				$wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name is escaped with esc_sql(), values are properly prepared
				$is_new_db_tbl_created = true;
			}

			// Security: Use validated table name for map_categories
			$table_categories = $this->get_validated_table_name('map_categories');
			if(!isset($server_db[$this->gdtn('map_categories')]) && $table_categories){
				$sql = "CREATE TABLE IF NOT EXISTS `" . esc_sql($table_categories) . "` (
					`id` int(11) NOT NULL AUTO_INCREMENT,
					`W_CAT_ID` bigint(20) NOT NULL,
					`X_P_ID` varchar(36) NOT NULL,
					`X_ACC_CODE` varchar(255) NOT NULL,
					PRIMARY KEY (id)
				)";

				$wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- CREATE TABLE with validated name
				$is_new_db_tbl_created = true;
			}

			// Security: Use validated table name for map_custom_fields
			$table_custom_fields = $this->get_validated_table_name('map_custom_fields');
			if(!isset($server_db[$this->gdtn('map_custom_fields')]) && $table_custom_fields){
				$sql = "CREATE TABLE IF NOT EXISTS `" . esc_sql($table_custom_fields) . "` (
					`id` int(11) NOT NULL AUTO_INCREMENT,
					`entity` varchar(255) NOT NULL,			
					`wc_field` varchar(255) NOT NULL,
					`x_field` varchar(255) NOT NULL,
					`ext_data` text NOT NULL,
					PRIMARY KEY (id)
				)";

				$wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- CREATE TABLE with validated name
				$is_new_db_tbl_created = true;
			}
		}

		$this->update_option('mw_wc_xero_plugin_db_version',$this->_plugin_db_version);
	}
	
	public function get_xcd_url(){
		return $this->xero_c_dashboard_url;
	}

	public function sync_enabled(){
		if(!$this->_disable_all_sync){
			return true;
		}
		
		return false;
	}
	
	/*Connection*/
	public function is_xero_connected(){
		#return true;
		return $this->is_xero_connected;
	}
	
	public function get_connected_xero_cd(){
		return $this->XeroCompanyDetails;
	}
	
	public function get_xero_org_actions(){
		$oaa = array();
		if($this->is_xero_connected()){
			$org_actions = $this->X_API_I()->getOrganisationActions($this->XeroTenantId);
			if(!empty($org_actions)){
				$org_actions = $org_actions->getActions();
				if(is_array($org_actions) && !empty($org_actions)){
					foreach($org_actions as $Action){
						$oaa[$Action->getName()] = $Action->getStatus();
					}
				}
			}			
		}
		
		return $oaa;
	}
	
	public function xero_connect(){
		if(!$this->is_xero_connected){
			$mwxs_t_gcc = microtime(true); // TEMP perf (Refs #83)
			$this->xero_gcc();
			$this->mwxs_perf_log('xero_gcc (token/connection)',$mwxs_t_gcc);
			if(!empty($this->AccessToken) && !empty($this->XeroTenantId)){
				$config = XeroAPI\XeroPHP\Configuration::getDefaultConfiguration()->setAccessToken((string)$this->AccessToken);
				
				// Create Guzzle client with middleware to capture API calls and headers
				$stack = GuzzleHttp\HandlerStack::create();
				
				// Add middleware to log API calls and capture rate limit headers
				$stack->push(GuzzleHttp\Middleware::mapResponse(function ($response) {
					// Log the response headers
					$headers = $response->getHeaders();
					$this->add_wc_debug_log("=== XERO API RESPONSE HEADERS ===", true, false, false);
					$json_headers = wp_json_encode($headers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
				$this->add_wc_debug_log($json_headers !== false ? $json_headers : serialize($headers), true, false, false);
					
					// Extract and log rate limit headers specifically
					$min_remaining = isset($headers['X-MinLimit-Remaining']) ? $headers['X-MinLimit-Remaining'][0] : 'Not found';
					$day_remaining = isset($headers['X-DayLimit-Remaining']) ? $headers['X-DayLimit-Remaining'][0] : 'Not found';
					
					$this->add_wc_debug_log(sprintf(
						"RATE LIMIT HEADERS: X-MinLimit-Remaining: %s, X-DayLimit-Remaining: %s",
						$min_remaining,
						$day_remaining
					), true, false, false);
					
					// Update our rate limiting tracking with actual header data
					$this->increment_xero_api_count($headers);
					
					return $response;
				}));
				
				// Xero rejects an invoice whose tracked-inventory lines would take stock negative
				// with 400 "Insufficient stock. Items are eligible for backorder." unless the
				// request carries allowBackorders=true. The vendored SDK (15.0.0) has no argument
				// for it - upstream only added one in 16.1.0 - so set it on the wire here, which
				// covers every invoice create/update call site through one place. Scoped to the
				// invoice collection and the single-invoice resource: sub-resources (Email,
				// Attachments, History) and other endpoints such as /CreditNotes must not get it.
				// Pushed before the request logger so the debug log shows the parameter. Refs #136
				$stack->push(GuzzleHttp\Middleware::mapRequest(function ($request) {
					if(in_array($request->getMethod(),array('POST','PUT'),true)
						&& preg_match('#/Invoices(/[^/]+)?$#',$request->getUri()->getPath())){
						return $request->withUri(
							GuzzleHttp\Psr7\Uri::withQueryValue($request->getUri(),'allowBackorders','true')
						);
					}
					return $request;
				}));
				
				// Add middleware to log requests
				$stack->push(GuzzleHttp\Middleware::mapRequest(function ($request) {
					$this->add_wc_debug_log(sprintf(
						"XERO API REQUEST: %s %s", 
						$request->getMethod(), 
						$request->getUri()
					), true, false, false);
					return $request;
				}));
				
				// The vendored xero-php-oauth2 SDK only catches RequestException (wrapping it
				// into ApiException), but in Guzzle 7 transport failures (DNS/connect/TLS)
				// throw ConnectException, which is no longer a RequestException and would
				// escape both the SDK and our catch (ApiException) blocks as a fatal.
				// Convert it here so every AccountingApi call surfaces as ApiException.
				$stack->push(function (callable $handler) {
					return function ($request, array $options) use ($handler) {
						return $handler($request, $options)->otherwise(function ($reason) use ($request) {
							if ($reason instanceof GuzzleHttp\Exception\ConnectException) {
								throw new GuzzleHttp\Exception\RequestException($reason->getMessage(), $request, null, $reason);
							}
							throw $reason;
						});
					};
				});

				$guzzle_options = array('handler' => $stack);
				// Windows PHP ships with no CA trust store (curl.cainfo unset in php.ini),
				// so Guzzle's default TLS verification fails on every Xero API call before
				// any HTTP response exists. Point it at the CA bundle WordPress ships;
				// other platforms are untouched. (PHP_OS, not PHP_OS_FAMILY: min PHP 5.6.)
				if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
					$wp_ca_bundle = ABSPATH . WPINC . '/certificates/ca-bundle.crt';
					if (is_readable($wp_ca_bundle)) {
						$guzzle_options['verify'] = $wp_ca_bundle;
					}
				}
				$guzzle_client = new GuzzleHttp\Client($guzzle_options);
				
				$this->XAccountingApiInstance = new XeroAPI\XeroPHP\Api\AccountingApi(
				  $guzzle_client,
				  $config
				);
				
				try{
					$mwxs_t_org = microtime(true); // TEMP perf (Refs #83)
					$xero_orgs_api_r = $this->XAccountingApiInstance->getOrganisations($this->XeroTenantId);
					$this->mwxs_perf_log('xero getOrganisations (API)',$mwxs_t_org);
					#$this->_p($xero_orgs_api_r);
					if(!empty($xero_orgs_api_r)){
						$xero_orgs_api_r = $xero_orgs_api_r[0];
						$this->is_xero_connected = true;

						# One time setup on first connection
						$this->one_time_setup_afxc();

						$xccd = array(
							'Name' => $xero_orgs_api_r->getName(),
							'LegalName' => $xero_orgs_api_r->getLegalName(),
							'Email' => '',
							
							'OrganisationStatus' => $xero_orgs_api_r->getOrganisationStatus(),
							'OrganisationType' => $xero_orgs_api_r->getOrganisationType(),
							
							'CountryCode' => $xero_orgs_api_r->getCountryCode(),
							'BaseCurrency' => $xero_orgs_api_r->getBaseCurrency(),	
							
							'PaysTax' => $xero_orgs_api_r->getPaysTax(),
							'DefaultSalesTax' => $xero_orgs_api_r->getDefaultSalesTax(),
							'DefaultPurchasesTax' => $xero_orgs_api_r->getDefaultPurchasesTax(),
							
							'Edition' => $xero_orgs_api_r->getEdition(),
							'Class' => $xero_orgs_api_r->getClass(),

							'Timezone' => $xero_orgs_api_r->getTimezone(),
						);				
						
						$this->XeroCompanyDetails = $xccd;
					}
				}catch (\XeroAPI\XeroPHP\ApiException $e) {
					#$error = AccountingObjectSerializer::deserialize($e->getResponseBody(), '\XeroAPI\XeroPHP\Models\Accounting\Error',[]);
					#$e->getMessage();
				} catch ( \InvalidArgumentException $e ) {
					// Backstop: the vendored SDK's generated models throw InvalidArgumentException
					// when Xero returns an enum value the SDK doesn't know yet (new plan tiers,
					// new US organisation types). The Organisation model setters are patched to
					// tolerate these, but keep the connect flow fatal-proof for any model we missed.
					$this->add_wc_debug_log( 'Xero getOrganisations deserialization failed: ' . $e->getMessage(), true, false, false );
				}
			}
		}
	}
	
	public function X_API_I(){
		return $this->XAccountingApiInstance;
	}
	
	# Credentials
	public function get_xero_tenant_id(){
		return $this->XeroTenantId;
	}

	public function get_xero_access_token() {
		return $this->AccessToken;
	}
	
	private function xero_gcc(){
		$xcc = array();
		$cc_url = $this->get_xcd_url().'/api/xero-connection-credentials';
		
		if(!empty($cc_url)){
			#$xck = $this->get_option('mw_wc_xero_f_xc_key');
			$xck = $this->get_option('mw_wc_xero_license');
			$site_url = $this->get_option('siteurl');
			
			if(!empty($xck) && !empty($site_url) && strlen($xck) == '35' && $this->validate_connection_key($xck)){				
				$r = wp_remote_post($cc_url,array(
					'method'      => 'POST',
					'body' => array(
						'xck' => $xck,
						'site_url' => $site_url,
					)
				));
				
				if(!is_wp_error($r)){
					#$this->_p($r);
					if($r['response']['code'] == '200' && !empty($r['body'])){
						$rb = json_decode($r['body'],true);
						if($rb['status'] == 'OK' && is_array($rb['data']) && isset($rb['data']['AccessToken']) && isset($rb['data']['XeroTenantId'])){
							$this->AccessToken = $rb['data']['AccessToken'];
							$this->XeroTenantId = $rb['data']['XeroTenantId'];

							// Store Xero Tenant ID in database for analytics
							update_option('mw_wc_xero_tenant_id', $rb['data']['XeroTenantId'], false);
						}
					}
				}else{
					#echo $r->get_error_message();
				}
			}
		}		
		
		return $xcc;
	}
	
	# After first time connected to xero - only one time
	private function one_time_setup_afxc(){
		if($this->is_xero_connected()){
			if(!$this->option_checked('mw_wc_xero_ots_afxc_run')){
				# Import customers and products from xero
				$this->xero_refresh_customers();
				$this->xero_refresh_products();
				
				# Default Settings

				# Default for unmatched products
				if(empty($this->get_option('mw_wc_xero_sync_default_xero_product'))){
					$ItemID = $this->get_field_by_val($this->gdtn('products'),'ItemID','Name','Default for Unmatched Products');

					if(empty($ItemID)){						
						#X_Add_Product
						$Item = new XeroAPI\XeroPHP\Models\Accounting\Item;
						$Item->setName('Default for Unmatched Products');
						$Item->setCode('Default');
						#$Item->setDescription('');
						#$Item->setIsSold(true);
						
						$Arr_Items = array();
						array_push($Arr_Items, $Item);
						
						$Items = new XeroAPI\XeroPHP\Models\Accounting\Items;
						$Items->setItems($Arr_Items);
						
						try{
							$result = $this->X_API_I()->createItems($this->XeroTenantId,$Items,true,$this->x_unitdp());

							if(!empty($result)){
								$xr_items = $result->getItems();
								if(is_array($xr_items) && !empty($xr_items)){
									$x_item = $xr_items[0];
									$X_ItemID = $x_item->getItemID();
									if(!empty($X_ItemID)){							
										$ItemID = $X_ItemID;
										# Save into local table
										$this->save_xero_product_into_local_dbt($x_item);
									}
								}
							}
						}catch (\XeroAPI\XeroPHP\ApiException $e) {
							$error = AccountingObjectSerializer::deserialize($e->getResponseBody(), '\XeroAPI\XeroPHP\Models\Accounting\Error',[]);					
							#$ld = $this->get_error_message_from_xero_error_object($error);
						}
					}

					if(!empty($ItemID)){
						$this->update_option('mw_wc_xero_sync_default_xero_product',$ItemID);
					}
				}

				# Accounts
				$xaa = $this->xero_get_accounts_kva();

				# $xaa, not $xta. This block used to test $xta, which isn't assigned until the
				# non-taxable-rate block much further down, so it was always undefined here and the
				# whole thing silently never ran: on first connect NONE of the four default account
				# options below were auto-populated, leaving new installs with no default Xero
				# account for order line items. The values matched here ('Sales (Sales)',
				# 'Inventory (Inventory)', 'Cost of Goods Sold (Directcosts)') are the "Name (Type)"
				# strings xero_get_accounts_kva() builds - tax rates are "Name (X%)" and could never
				# have matched, which is what makes this a typo rather than intent.
				if(is_array($xaa) && !empty($xaa)){
					# 1st Condition
					foreach($xaa as $k => $v){
						if(empty($k)){
							continue;
						}

						# Default Xero Account for Order Line Items
						if(empty($this->get_option('mw_wc_xero_sync_default_xero_account_foli'))){
							if($v == 'Sales (Sales)'){
								$this->update_option('mw_wc_xero_sync_default_xero_account_foli',$k);
							}
						}

						# Default Xero Sales Account for New Products
						if(empty($this->get_option('mw_wc_xero_sync_default_xero_sales_account_fnp'))){
							if($v == 'Sales (Sales)'){
								$this->update_option('mw_wc_xero_sync_default_xero_sales_account_fnp',$k);
							}
						}

						# Default Xero Inventory Asset Account for New Products
						if(empty($this->get_option('mw_wc_xero_sync_default_xero_inventory_asset_account_fnp'))){
							if($v == 'Inventory (Inventory)'){
								$this->update_option('mw_wc_xero_sync_default_xero_inventory_asset_account_fnp',$k);
							}
						}

						# Default Xero COGS Account for New Products
						if(empty($this->get_option('mw_wc_xero_sync_default_xero_cogs_account_fnp'))){
							if($v == 'Cost of Goods Sold (Directcosts)'){
								$this->update_option('mw_wc_xero_sync_default_xero_cogs_account_fnp',$k);
							}
						}
					}

					# 2nd Condition
					foreach($xaa as $k => $v){
						if(empty($k)){
							continue;
						}

						# Default Xero Account for Order Line Items
						if(empty($this->get_option('mw_wc_xero_sync_default_xero_account_foli'))){
							if(strlen($v) > 7 && substr($v, -7) == '(Sales)'){
								$this->update_option('mw_wc_xero_sync_default_xero_account_foli',$k);
							}
						}

						# Default Xero Sales Account for New Products
						if(empty($this->get_option('mw_wc_xero_sync_default_xero_sales_account_fnp'))){
							if(strlen($v) > 7 && substr($v, -7) == '(Sales)'){
								$this->update_option('mw_wc_xero_sync_default_xero_sales_account_fnp',$k);
							}
						}

						# Default Xero Inventory Asset Account for New Products
						if(empty($this->get_option('mw_wc_xero_sync_default_xero_inventory_asset_account_fnp'))){
							if(strlen($v) > 11 && substr($v, -11) == '(Inventory)'){
								$this->update_option('mw_wc_xero_sync_default_xero_inventory_asset_account_fnp',$k);
							}
						}
						
						# Default Xero COGS Account for New Products
						if(empty($this->get_option('mw_wc_xero_sync_default_xero_cogs_account_fnp'))){
							if(strlen($v) > 13 && substr($v, -13) == '(Directcosts)'){
								$this->update_option('mw_wc_xero_sync_default_xero_cogs_account_fnp',$k);
							}
						}
					}
				}

				# Default Xero Shipping Product
				if(empty($this->get_option('mw_wc_xero_sync_default_xero_shipping_product'))){
					$ItemID = $this->get_field_by_val($this->gdtn('products'),'ItemID','Name','Shipping');
					if(empty($ItemID)){
						$ItemID = $this->get_field_by_val($this->gdtn('products'),'ItemID','Name','Freight');
					}

					if(!empty($ItemID)){
						$this->update_option('mw_wc_xero_sync_default_xero_shipping_product',$ItemID);
					}
				}

				# Xero Non-taxable Rate
				if(empty($this->get_option('mw_wc_xero_sync_non_taxable_rate'))){
					$xta = $this->xero_get_tax_rates_kva();
					if(is_array($xta) && !empty($xta)){
						foreach($xta as $k => $v){
							if(empty($k)){
								continue;
							}

							if($k == 'NONE' && $v == 'Tax Exempt (0%)'){
								$this->update_option('mw_wc_xero_sync_non_taxable_rate',$k);
								break;
							}
						}
					}
				}
				
				$this->update_option('mw_wc_xero_ots_afxc_run','true');
			}			
		}
	}
	
	#Validation
	public function validate_connection_key($key){
		$regex = '/^[A-Z0-9-]+$/i';
		return preg_match($regex, $key);
	}
	
	#Settings Save
	public function save_setting_page_data($post,$fn,$ft='',$dv='',$dt='',$ext=''){
		if(!empty($fn) && is_array($post) && !empty($post)){
			$fn = $this->get_s_o_p().$fn;
			
			if($ft == 'option_check'){
				$dv = 'false';
			}
			
			$val = isset($post[$fn])?$post[$fn]:$dv;
			
			if($ft == 'c_s'){
				if(is_array($val) && !empty($val)){
					$val = implode(',',$val);
				}else{
					$val = '';
				}
			}
			
			if(!is_array($val)){
				$val = trim($val);
				if(!empty($dt) && !empty($val)){
					if($dt == 'int'){
						$val = intval($val);
					}
					
					if($dt == 'float'){
						$val = floatval($val);
					}
				}
			}
			
			$this->update_option($fn,$val);
		}
	}
	
	public function is_queue_sync_e(){
		return true;
	}
	
	/*License*/
	public function is_valid_license($licensekey,$localkey="",$realtime=false){
		if(!$this->is_valid_license){			
			$license_data = $this->myworks_wc_xero_sync_check_license($licensekey,$localkey,$realtime);			
			if(!$realtime){
				$this->mw_license_lk_blank_check_run($licensekey,$localkey,$realtime);
			}			
			
			$this->plugin_license_status = (isset($license_data['status']))?$license_data['status']:'';
			if(isset($license_data['status']) && $license_data['status']=='Active' && !isset($license_data['trial_expired'])){
				$this->is_valid_license = true;
			}else{
				if(isset($license_data['trial_expired'])){
					$this->plugin_license_status = 'Invalid';
				}
			}
		}
		return $this->is_valid_license;
	}
	
	public function get_license_status(){
		return $this->plugin_license_status;
	}

	public function is_license_active(){
		if($this->is_valid_license && $this->plugin_license_status == 'Active'){
			return true;
		}

		return false;
	}
	
	public function get_ldfcpv(){
		return (array) $this->license_data_for_conn_page_view;
	}

	/**
	 * Force license refresh to update Intercom/Mixpanel data
	 * Used when plugin is updated to immediately load new integration data
	 *
	 * @since 1.3.2
	 * @return bool True if license is valid after refresh
	 */
	public function force_license_refresh() {
		$licensekey = $this->get_option('mw_wc_xero_license');
		$localkey = $this->get_option('mw_wc_xero_localkey');

		// Reset validation flag to force revalidation
		$this->is_valid_license = false;

		// Perform real-time license check
		$is_valid = $this->is_valid_license($licensekey, $localkey, true);

		return $is_valid;
	}

	protected function myworks_wc_xero_sync_check_license($licensekey,$localkey,$realtime){
		$results_df = array('status'=>'Invalid');
		$results = $results_df;

		$lc_url = $this->plugin_license_url;
		
		if(!empty($lc_url)){
			$site_url = $this->get_option('siteurl');
						
			if(!empty($licensekey) && !empty($site_url) && strlen($licensekey) == '35' && $this->validate_connection_key($licensekey)){
				// Use stored secret key from license API, fallback to constant for initial setup
				$stored_secret_key = $this->get_option('mw_wc_xero_plugin_licensing_secret_key', '');
				$licensing_secret_key = !empty($stored_secret_key) ? $stored_secret_key : (defined('MW_WC_XERO_SYNC_LICENSING_SECRET_KEY') ? MW_WC_XERO_SYNC_LICENSING_SECRET_KEY : '');
				$localkeydays = 5;
				$allowcheckfaildays = 5;

				// Use PHP's native CSPRNG instead of wp_rand() so we don't force-load
				// wp-includes/pluggable.php during plugin load. Doing so declared core wp_mail()
				// early and pre-empted SMTP plugins (e.g. Post SMTP), breaking outgoing email. Refs #88
				$random_number = random_int(1000000000, 9999999999);
				$check_token = time() . md5($random_number . $licensekey);
				$checkdate = gmdate("Ymd");
				$localkeyvalid = false;

				$only_remote_check=false;
				if(!$only_remote_check && !empty($localkey)){
					$localkey = str_replace("\n", '', $localkey); # Remove the line breaks
					$localdata = substr($localkey, 0, strlen($localkey) - 32); # Extract License Data
					$md5hash = substr($localkey, strlen($localkey) - 32); # Extract MD5 Hash

					if($md5hash == md5($localdata . $licensing_secret_key)){
						$localdata = strrev($localdata); # Reverse the string
						$md5hash = substr($localdata, 0, 32); # Extract MD5 Hash
						$localdata = substr($localdata, 32); # Extract License Data
						$localdata = base64_decode($localdata);
						$localkeyresults = unserialize($localdata);
						$originalcheckdate = $localkeyresults['checkdate'];

						if($md5hash == md5($originalcheckdate . $licensing_secret_key)){
							$localexpiry = gmdate("Ymd", mktime(0, 0, 0, gmdate("m"), gmdate("d") - $localkeydays, gmdate("Y")));
							if($originalcheckdate > $localexpiry){
								$localkeyvalid = true;
								$results = $localkeyresults;

								if(isset($results['validdomain'])){
									$validdomains = explode(',', $results['validdomain']);
									if (!in_array($site_url, $validdomains)) {
										$localkeyvalid = false;										
										$results = $results_df;
									}
								}
							}else{
								$realtime = true;
							}
						}
					}
				}

				if(!$only_remote_check && empty($localkey) && !$realtime){
					$f_rt_count = (int) get_option('mw_wc_qbo_desk_forced_realtime_license_check_count',0);
					if($f_rt_count <= 5){
						$f_rt_count++;
						$realtime = true;
						$this->update_option('mw_wc_qbo_desk_forced_realtime_license_check_count',$f_rt_count);
					}
				}

				if((!$localkeyvalid && $realtime) || $only_remote_check){
					$this->update_option('mw_wc_xero_license',$licensekey);			
					$r = wp_remote_post($lc_url,array(
						'method'      => 'POST',
						'body' => array(
							'licensekey' => $licensekey,
							'domain' => $site_url,
							'check_token' => $check_token,
						)
					));
					
					if(!is_wp_error($r)){
						//$this->add_wc_debug_log($r, true, false, false);
						if($r['response']['code'] == '200' && !empty($r['body'])){
							$rb = json_decode($r['body'],true);
							if(is_array($rb) && isset($rb['status'])){
								$results = $rb;
								if(isset($results['md5hash'])){
									if($results['md5hash'] != md5($licensing_secret_key . $check_token)){
										$results['status'] = "Invalid";
										#$results['description'] = "MD5 Checksum Verification Failed";
										return $results;
									}
								}

								if($results['status'] == "Active"){
									$results['checkdate'] = $checkdate;
									// Keep the per-site Pulse token out of the reversible local-key blob;
									// it lives only in the encrypted mw_wc_xero_pulse_site_token option. Refs #122.
									$localkey_results = $results;
									unset($localkey_results['pulse_site_token']);
									$data_encoded = serialize($localkey_results);
									$data_encoded = base64_encode($data_encoded);
									$data_encoded = md5($checkdate . $licensing_secret_key) . $data_encoded;
									$data_encoded = strrev($data_encoded);
									$data_encoded = $data_encoded . md5($data_encoded . $licensing_secret_key);
									$data_encoded = wordwrap($data_encoded, 80, "\n", true);
									$localkey = $data_encoded;

									$this->update_option('mw_wc_xero_localkey',$localkey);

									if(!empty($results['userid'])) {
										$this->update_option('mw_wc_xero_user_id',$results['userid']);
									}

									if(!empty($results['regdate'])) {
										$this->update_option('mw_wc_xero_registration_date', strtotime($results['regdate']));
									}

									if(!empty($results['validdomain'])) {
										$this->update_option('mw_wc_xero_domain', stripslashes($results['validdomain']));
									}

									// Store user data for Mixpanel and Intercom identification
									if(!empty($results['email'])) {
										$this->update_option('mw_wc_xero_user_email',$results['email']);
									}

									if(!empty($results['companyname'])) {
										$this->update_option('mw_wc_xero_company_name',$results['companyname']);
									}

									if(!empty($results['registeredname'])) {
										$this->update_option('mw_wc_xero_user_name', $results['registeredname']);
									}

									if(!empty($results['plugin_licensing_secret_key'])) {
										$this->update_option('mw_wc_xero_plugin_licensing_secret_key', $results['plugin_licensing_secret_key']);
									}

									if(!empty($results['intercom_app_id'])) {
										$this->update_option('mw_wc_xero_intercom_app_id', $results['intercom_app_id']);
									}

									if(!empty($results['intercom_secret'])) {
										// Encrypt the secret before storing
										$encrypted_secret = $this->encrypt_secret( $results['intercom_secret'] );
										$this->update_option('mw_wc_xero_intercom_secret', $encrypted_secret);
									}

									if(!empty($results['mixpanel_token'])) {
										// Encrypt the token before storing
										$encrypted_token = $this->encrypt_secret( $results['mixpanel_token'] );
										$this->update_option('mw_wc_xero_mixpanel_token', $encrypted_token);
									}

									// Refresh the per-site Pulse token on every active response; clear it when the
									// response omits it so Pulse fails closed (no stale token). Refs #122.
									$pulse_site_token = ( isset($results['pulse_site_token']) && is_string($results['pulse_site_token']) ) ? trim($results['pulse_site_token']) : '';
									if ( $pulse_site_token !== '' ) {
										$this->update_option('mw_wc_xero_pulse_site_token', $this->encrypt_secret( $pulse_site_token ));
									} else {
										$this->update_option('mw_wc_xero_pulse_site_token', '');
									}

								}else{
									delete_option('mw_wc_xero_localkey');
									// License is confirmed non-active — stop Pulse (fail closed). Refs #122.
									$this->update_option('mw_wc_xero_pulse_site_token', '');
								}

								$results['remotecheck'] = true;														
							}
						}else{
							#$results['description'] = "Remote Check Failed";
						}
					}else{
						#echo $r->get_error_message();
					}
				}
				
				$ldfcpv = array();
				$ldfcpv['status'] = $results['status'];
				$ldfcpv['nextduedate'] = (isset($results['nextduedate']))?$results['nextduedate']:'';
				$ldfcpv['billingcycle'] = (isset($results['billingcycle']))?$results['billingcycle']:'';
				
				$l_pln = '';
				if((isset($results['productname'])) && !empty($results['productname']) && strpos($results['productname'],' | ')!==false){
					$pn_arr = explode(' | ',$results['productname']);
					if(is_array($pn_arr) && count($pn_arr) == 2){
						$l_pln = $pn_arr[1];
					}
				}

				$ldfcpv['plan'] = $l_pln;
				$ldfcpv['productname'] = (isset($results['productname']))?$results['productname']:'';
				$ldfcpv['user_id'] = (isset($results['user_id']))?$results['user_id']:'';
				$ldfcpv['registration_date'] = (isset($results['registration_date']))?$results['registration_date']:'';
				$ldfcpv['domain'] = (isset($results['validdomain']))?$results['validdomain']:'';
				$this->license_data_for_conn_page_view = $ldfcpv;

				// Store plan and billing cycle in database for Mixpanel/Intercom
				if(!empty($l_pln)) {
					// Track plan changes for Intercom
					$previous_plan = get_option( 'mw_wc_xero_current_plan', '' );
					if ( ! empty( $l_pln ) && $l_pln !== $previous_plan && ! empty( $previous_plan ) ) {
						if ( class_exists( 'MyWorks_Intercom_Integration' ) ) {
							MyWorks_Intercom_Integration::track_plan_change( $previous_plan, $l_pln );
						}
					}
					update_option( 'mw_wc_xero_current_plan', $l_pln );

					$this->update_option('mw_wc_xero_plan', $l_pln);
				}
				if(!empty($ldfcpv['billingcycle'])) {
					$this->update_option('mw_wc_xero_billing_cycle', $ldfcpv['billingcycle']);
				}
			}
		}		
		
		return $results;
	}

	private function get_plg_lc_plan(){
		$pln = '';
		$lkr = $this->get_ldfcpv(); # cGxhbg==
		if(is_array($lkr) && !empty($lkr) && isset($lkr[base64_decode('cHJvZHVjdG5hbWU=')]) && !empty($lkr[base64_decode('cHJvZHVjdG5hbWU=')])){
			if(strpos($lkr[base64_decode('cHJvZHVjdG5hbWU=')],base64_decode('TGF1bmNo'))!==false){
				$pln = base64_decode('TGF1bmNo');
			}
			
			if(strpos($lkr[base64_decode('cHJvZHVjdG5hbWU=')],base64_decode('UmlzZQ=='))!==false){
				$pln = base64_decode('UmlzZQ==');
			}
			
			if(strpos($lkr[base64_decode('cHJvZHVjdG5hbWU=')],base64_decode('R3Jvdw=='))!==false){
				$pln = base64_decode('R3Jvdw==');
			}
			
			if(strpos($lkr[base64_decode('cHJvZHVjdG5hbWU=')],base64_decode('U2NhbGU='))!==false){
				$pln = base64_decode('U2NhbGU=');
			}

			if(strpos($lkr[base64_decode('cHJvZHVjdG5hbWU=')],base64_decode('U29hcg=='))!==false){
				$pln = base64_decode('U29hcg==');
			}
		}
		return $pln;
	}

	public function is_plg_lc_p_empty(){				
		if(empty($this->get_plg_lc_plan())){
			return true;
		}
		return false;
	}
	
	public function is_plg_lc_p_l(){				
		if($this->get_plg_lc_plan() == base64_decode('TGF1bmNo')){
			return true;
		}
		return false;
	}
	
	public function is_plg_lc_p_r(){
		if($this->get_plg_lc_plan() == base64_decode('UmlzZQ==')){
			return true;
		}
		return false;
	}
	
	public function is_plg_lc_p_g(){
		if($this->get_plg_lc_plan() == base64_decode('R3Jvdw==')){
			return true;
		}
		return false;
	}
	
	public function is_plg_lc_p_s(){
		if($this->get_plg_lc_plan() == base64_decode('U2NhbGU=')){
			return true;
		}
		return false;
	}

	public function is_plg_lc_p_sr(){
		if($this->get_plg_lc_plan() == base64_decode('U29hcg==')){
			return true;
		}
		return false;
	}

	/**
	 * Rise and above - the plan floor for the invoice branding theme.
	 *
	 * A positive list rather than "not Launch". get_plg_lc_plan() returns '' for any productname it
	 * has no case for, so today the two spellings agree; but a tier added to that method later would
	 * silently pass a negative check while the push path ignored it, and the settings screen would
	 * save a theme that never reached Xero. Four gates depend on this rule (the field lock, the
	 * dropdown fetch, the save registration and the push), so it lives in one place where a new tier
	 * is a one-line change. Refs #57
	 */
	public function is_plg_lc_p_r_up(){
		return ($this->is_plg_lc_p_r() || $this->is_plg_lc_p_g() || $this->is_plg_lc_p_s() || $this->is_plg_lc_p_sr());
	}

	public function get_osl_sm_val($prm=array()){
		$stk = base64_decode('T3JkZXJBZGQ=');
		$sl_okv = base64_decode('X19td193Y194ZXJvX3N5bmNfaW1wX29zbGNkX2RjYQ==');		
		$oslcd = get_option($sl_okv);
		
		$cy = $this->now('Y');
		$cm = $this->now('F');
		
		if(is_array($oslcd) && !empty($oslcd)){
			if(isset($oslcd[$cy]) && is_array($oslcd)){
				if(isset($oslcd[$cy][$cm]) && is_array($oslcd[$cy][$cm])){
					if(isset($oslcd[$cy][$cm][$stk]) && (int) $oslcd[$cy][$cm][$stk] > 0){
						$e_scv = (int) $oslcd[$cy][$cm][$stk];
						return $e_scv;
					}
				}
			}
		}
		return 0;
	}
	
	public function get_osl_lp_count($prm=array()){
		$osl_pm_v = 20;
		if($this->is_plg_lc_p_r()){
			$osl_pm_v = 60;
		}
		
		if($this->is_plg_lc_p_g()){
			$osl_pm_v = 300;
		}

		if($this->is_plg_lc_p_s()){
			$osl_pm_v = 1000;
		}

		return $osl_pm_v;
	}
	
	public function lp_chk_osl_allwd($prm=array()){
		# New
		$plan = $this->get_plg_lc_plan();
		if(empty($plan)){
			return true;
		}
		
		if(!$this->is_plg_lc_p_sr()){
			$e_scv = (int) $this->get_osl_sm_val();
			$osl_pm_v = $this->get_osl_lp_count();							
			if($e_scv >= $osl_pm_v){
				return false;
			}
		}
		return true;
	}

	private function process_order_sync($prm=array()){
		$E_ID = 0;
		if(is_array($prm) && isset($prm['ID']) && (int) $prm['ID'] > 0){
			$E_ID = (int) $prm['ID'];
		}
		
		$stk = base64_decode('T3JkZXJBZGQ=');
		
		$sl_okv = base64_decode('X19td193Y194ZXJvX3N5bmNfaW1wX29zbGNkX2RjYQ==');		
		$oslcd = get_option($sl_okv);
		
		$cy = $this->now('Y');
		$cm = $this->now('F');
		
		$keep_p_ym_d = false;
		
		if(is_array($oslcd) && !empty($oslcd)){
			#New
			if(!$keep_p_ym_d){
				foreach($oslcd as $y => $d){
					if($y != $cy){
						unset($oslcd[$y]);
					}else{
						if(isset($oslcd[$y]) && !empty($oslcd[$y])){
							foreach($oslcd[$y] as $m => $d){
								if($m != $cm){
									unset($oslcd[$cy][$m]);
								}
							}
						}
					}
				}
			}
			
			$e_scv_inc = false;
			if(isset($oslcd[$cy]) && is_array($oslcd)){
				if(isset($oslcd[$cy][$cm]) && is_array($oslcd[$cy][$cm])){
					if(isset($oslcd[$cy][$cm][$stk]) && (int) $oslcd[$cy][$cm][$stk] > 0){
						$ii_cv = true;						
						if($E_ID > 0 && isset($oslcd[$cy][$cm][$stk.'_IDs'])){
							$stk_id_arr = $oslcd[$cy][$cm][$stk.'_IDs'];
							if(is_array($stk_id_arr) && in_array($E_ID,$stk_id_arr)){
								$ii_cv = false;
							}else{
								$stk_id_arr[] = $E_ID;
								$oslcd[$cy][$cm][$stk.'_IDs'] = $stk_id_arr;
							}
						}
						
						if($ii_cv){
							$e_scv = (int) $oslcd[$cy][$cm][$stk];
							$e_scv++;
							$oslcd[$cy][$cm][$stk] = $e_scv;
						}
						
						$e_scv_inc = true;
					}
				}
			}
			
			if(!$e_scv_inc){
				$oslcd[$cy][$cm][$stk] = 1;				
			}
			
			if($E_ID > 0 && !isset($oslcd[$cy][$cm][$stk.'_IDs'])){
				$oslcd[$cy][$cm][$stk.'_IDs'] = array($E_ID);
			}
		}else{
			$oslcd = array();
			$oslcd[$cy] = array(
				$cm => array(
					$stk => 1,
				),
			);
			
			if($E_ID > 0 ){
				$oslcd[$cy][$cm][$stk.'_IDs'] = array($E_ID);
			}			
		}
		
		update_option($sl_okv,$oslcd,false);
	}
	
	private function mw_license_lk_blank_check_run($licensekey,$localkey="",$realtime=false){
		$recent_llk = get_option('mw_wc_xero_localkey','');		
		if(empty($recent_llk)){
			$is_lbcr = true;
			$td = gmdate('Y-m-d');
			$td_rc = 0;
			
			$lbcr_chk_opt = get_option('mw_wc_xero_lbcr_chk_count_dt','');
			if(!empty($lbcr_chk_opt) && is_array($lbcr_chk_opt)){
				if(isset($lbcr_chk_opt[$td])){
					$td_rc = (int) $lbcr_chk_opt[$td];
					if($td_rc  < 0){$td_rc = 0;}
					if($td_rc  >= 2){
						$is_lbcr = false;
					}
				}
			}
			
			if($is_lbcr){
				$td_rc++;
				$lbcr_nd = array();
				$lbcr_nd[$td] = $td_rc;
				
				$this->update_option('mw_wc_xero_lbcr_chk_count_dt',$lbcr_nd);
				
				if($this->is_valid_license($licensekey,$localkey,true)){
					return true;
				}				
			}
		}
		
		return false;
	}
	
	#Dashboard Log Graph Data
	public function get_log_chart_data(){
		global $wpdb;
		// Security: Use validated table name
		$p_log_tbl = $this->get_validated_table_name('log');
		if (!$p_log_tbl) {
			return array();
		}
		
		$today = gmdate("Y-m-d").' 00:00:00';
        $month = gmdate("Y-m-d H:i:s", mktime(0, 0, 0, gmdate("m"), gmdate("d") - 30, gmdate("Y")));
        $year = gmdate("Y-m-d H:i:s", mktime(0, 0, 0, gmdate("m") - 12, 1, gmdate("Y")));

		$invoiceData = array();
		// Security: Escape MySQL date format specifiers to avoid placeholder conflicts
		$query_today = "SELECT date_format(added_date, '%%k') AS date, COUNT(id) AS count FROM `" . esc_sql($p_log_tbl) . "` WHERE added_date > %s AND `log_type`='Order' AND `status`=1 AND `details` NOT LIKE %s GROUP BY date_format(added_date, '%%k')";
		$result_inv_today = $this->get_data($wpdb->prepare($query_today, $today, '%Draft Invoice not allowed%')); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name is escaped with esc_sql(), values are properly prepared
		if(is_array($result_inv_today) && !empty($result_inv_today)){
			foreach($result_inv_today as $data){
				$invoiceData['today'][$data['date']] = $data['count'];
			}
		}
		$query_month = "SELECT date_format(added_date, '%%e %%M') AS date, COUNT(id) AS count FROM `" . esc_sql($p_log_tbl) . "` WHERE added_date > %s AND `log_type`='Order' AND `status`=1 AND `details` NOT LIKE %s GROUP BY date_format(added_date, '%%e')";
		$result_inv_month = $this->get_data($wpdb->prepare($query_month, $month, '%Draft Invoice not allowed%')); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name is escaped with esc_sql(), values are properly prepared
		if(is_array($result_inv_month) && !empty($result_inv_month)){
			foreach($result_inv_month as $data){
				$invoiceData['month'][$data['date']] = $data['count'];
			}
		}

		$query_year = "SELECT date_format(added_date, '%%M %%Y') AS date, COUNT(id) AS count FROM `" . esc_sql($p_log_tbl) . "` WHERE added_date > %s AND `log_type`='Order' AND `status`=1 AND `details` NOT LIKE %s GROUP BY date_format(added_date, '%%M')";
		$result_inv_year = $this->get_data($wpdb->prepare($query_year, $year, '%Draft Invoice not allowed%')); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name is escaped with esc_sql(), values are properly prepared
		if(is_array($result_inv_year) && !empty($result_inv_year)){
			foreach($result_inv_year as $data){
				$invoiceData['year'][$data['date']] = $data['count'];
			}
		}
		
		$paymentData = array();
		$query_pmnt_today = "SELECT date_format(added_date, '%%k') AS date, COUNT(id) AS count FROM `" . esc_sql($p_log_tbl) . "` WHERE added_date > %s AND `log_type`='Payment' AND `status`=1 GROUP BY date_format(added_date, '%%k')";
		$result_pmnt_today = $this->get_data($wpdb->prepare($query_pmnt_today, $today)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name is escaped with esc_sql(), values are properly prepared
		if(is_array($result_pmnt_today) && !empty($result_pmnt_today)){
			foreach($result_pmnt_today as $data){
				$paymentData['today'][$data['date']] = $data['count'];
			}
		}
		$query_pmnt_month = "SELECT date_format(added_date, '%%e %%M') AS date, COUNT(id) AS count FROM `" . esc_sql($p_log_tbl) . "` WHERE added_date > %s AND `log_type`='Payment' AND `status`=1 GROUP BY date_format(added_date, '%%e')";
		$result_pmnt_month = $this->get_data($wpdb->prepare($query_pmnt_month, $month)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name is escaped with esc_sql(), values are properly prepared
		if(is_array($result_pmnt_month) && !empty($result_pmnt_month)){
			foreach($result_pmnt_month as $data){
				$paymentData['month'][$data['date']] = $data['count'];
			}
		}

		$query_pmnt_year = "SELECT date_format(added_date, '%%M %%Y') AS date, COUNT(id) AS count FROM `" . esc_sql($p_log_tbl) . "` WHERE added_date > %s AND `log_type`='Payment' AND `status`=1 GROUP BY date_format(added_date, '%%M')";
		$result_pmnt_year = $this->get_data($wpdb->prepare($query_pmnt_year, $year)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name is escaped with esc_sql(), values are properly prepared
		if(is_array($result_pmnt_year) && !empty($result_pmnt_year)){
			foreach($result_pmnt_year as $data){
				$paymentData['year'][$data['date']] = $data['count'];
			}
		}
		
		$clientData = array();
		$result_cl_today = $this->get_data("SELECT date_format(added_date, '%k') AS date, COUNT(id) AS count FROM `".$p_log_tbl."` WHERE added_date>'$today' AND `log_type`='Customer' AND `status`=1 GROUP BY date_format(added_date, '%k')");
		if(is_array($result_cl_today) && !empty($result_cl_today)){
			foreach($result_cl_today as $data){
				$clientData['today'][$data['date']] = $data['count'];
			}
		}
		$result_cl_month = $this->get_data("SELECT date_format(added_date, '%e %M') AS date, COUNT(id) AS count FROM `".$p_log_tbl."` WHERE added_date>'$month' AND `log_type`='Customer' AND `status`=1 GROUP BY date_format(added_date, '%e')");
		if(is_array($result_cl_month) && !empty($result_cl_month)){
			foreach($result_cl_month as $data){
				$clientData['month'][$data['date']] = $data['count'];
			}
		}

		$result_cl_year = $this->get_data("SELECT date_format(added_date, '%M %Y') AS date, COUNT(id) AS count FROM `".$p_log_tbl."` WHERE added_date>'$year' AND `log_type`='Customer' AND `status`=1 GROUP BY date_format(added_date, '%M')");
		if(is_array($result_cl_year) && !empty($result_cl_year)){
			foreach($result_cl_year as $data){
				$clientData['year'][$data['date']] = $data['count'];
			}
		}
		
		$errorData = array();
		$result_er_today = $this->get_data("SELECT date_format(added_date, '%k') AS date, COUNT(id) AS count FROM `".$p_log_tbl."` WHERE added_date>'$today' AND `status`=0 GROUP BY date_format(added_date, '%k')");
		if(is_array($result_er_today) && !empty($result_er_today)){
			foreach($result_er_today as $data){
				$errorData['today'][$data['date']] = $data['count'];
			}
		}
		$result_er_month = $this->get_data("SELECT date_format(added_date, '%e %M') AS date, COUNT(id) AS count FROM `".$p_log_tbl."` WHERE added_date>'$month' AND `status`=0 GROUP BY date_format(added_date, '%e')");
		if(is_array($result_er_month) && !empty($result_er_month)){
			foreach($result_er_month as $data){
				$errorData['month'][$data['date']] = $data['count'];
			}
		}

		$result_er_year = $this->get_data("SELECT date_format(added_date, '%M %Y') AS date, COUNT(id) AS count FROM `".$p_log_tbl."` WHERE added_date>'$year' AND `status`=0 GROUP BY date_format(added_date, '%M')");
		if(is_array($result_er_year) && !empty($result_er_year)){
			foreach($result_er_year as $data){
				$errorData['year'][$data['date']] = $data['count'];
			}
		}
		
		$productData = array();
		$result_prd_today = $this->get_data("SELECT date_format(added_date, '%k') AS date, COUNT(id) AS count FROM `".$p_log_tbl."` WHERE added_date>'$today' AND (`log_type`='Product' OR `log_type`='Variation') AND `status`=1 GROUP BY date_format(added_date, '%k')");
		if(is_array($result_prd_today) && !empty($result_prd_today)){
			foreach($result_prd_today as $data){
				$productData['today'][$data['date']] = $data['count'];
			}
		}

		$result_prd_month = $this->get_data("SELECT date_format(added_date, '%e %M') AS date, COUNT(id) AS count FROM `".$p_log_tbl."` WHERE added_date>'$month' AND (`log_type`='Product' OR `log_type`='Variation') AND `status`=1 GROUP BY date_format(added_date, '%e')");
		if(is_array($result_prd_month) && !empty($result_prd_month)){
			foreach($result_prd_month as $data){
				$productData['month'][$data['date']] = $data['count'];
			}
		}

		$result_prd_year = $this->get_data("SELECT date_format(added_date, '%M %Y') AS date, COUNT(id) AS count FROM `".$p_log_tbl."` WHERE added_date>'$year' AND (`log_type`='Product' OR `log_type`='Variation') AND `status`=1 GROUP BY date_format(added_date, '%M')");
		if(is_array($result_prd_year) && !empty($result_prd_year)){
			foreach($result_prd_year as $data){
				$productData['year'][$data['date']] = $data['count'];
			}
		}
		
		return array(
            'invoices' => array(
                'total' => $invoiceData,
            ),
            'clients' => array(
                'total' => $clientData,
            ),
			 'errors' => array(
                'total' => $errorData,
            ),
			'payments' => array(
                'total' => $paymentData,
            ),			
			'products' => array(
                'total' => $productData,
            ),

        );
	}
	
	#WC Data Functions
	public function get_variation_name_from_id($v_name,$p_name='',$v_id=0,$p_id=0){
		$v_name = trim($v_name);$p_name = trim($p_name);		
		
		$v_id = intval($v_id);$p_id = intval($p_id);
		if($v_name!='' && $v_id>0){
			global $wpdb;
			if(!$p_id || empty($p_name)){
				$p_data = $this->get_row($wpdb->prepare("SELECT * FROM `{$wpdb->posts}` WHERE  `ID` = %d AND `post_type` = 'product_variation'  ",$v_id));
				if(is_array($p_data) && count($p_data)){
					$p_id = (int) $p_data['post_parent'];
					#$p_name = $p_data['post_title'];
					$p_name = $this->get_field_by_val($wpdb->posts,'post_title','ID',$p_id);
				}
			}
			
			if($p_id>0 && !empty($p_name)){
				$_product_attributes_a = get_post_meta($p_id,'_product_attributes',true);
				if(is_array($_product_attributes_a) && count($_product_attributes_a)){
					$pa_k_a = array();
					foreach($_product_attributes_a as $pak => $pav){
						$pa_k_a[] = $pak;
					}
					
					$v_meta = get_post_meta($v_id);					
					
					if(is_array($v_meta) && count($v_meta)){
						$v_av_pa = array();
						foreach($v_meta as $vmk => $vmv){								
							if (substr($vmk, 0, strlen('attribute_')) == 'attribute_') {
								$vmk = substr($vmk, strlen('attribute_'));
								if(in_array($vmk,$pa_k_a)){
									$vmv = ($vmv[0])?$vmv[0]:'';
									if(!is_numeric($vmv)){
										$vmv = ucfirst($vmv);
									}
									$p_name.=' - '.$vmv;
									
									/*
									if($this->start_with($vmk,'pa_')){
										$vmk = $this->sanitize(substr($vmk,3));
									}
									$v_av_pa[$vmk] = $vmv;
									*/
								}
							}								
						}
					}
					
					return $p_name;
				}
			}
		}
		return $v_name;
	}
	
	# Automap	
	public function AutoMapCustomers($cam_wf,$cam_qf,$mo_um=false){
		global $wpdb;
		$map_count = 0;
		
		$map_tbl = $this->gdtn('map_customers');
		$x_customer_tbl = $this->gdtn('customers');
		
		if(empty($cam_wf) || empty($cam_qf)){
			return $map_count;
		}
		
		if(!is_array($this->wc_customer_automap_fields()) || !is_array($this->xero_customer_automap_fields())){
			return $map_count;
		}
		
		$cam_wf_la = $this->wc_customer_automap_fields();
		$cam_qf_la = $this->xero_customer_automap_fields();
		
		if(!isset($cam_wf_la[$cam_wf]) || !isset($cam_qf_la[$cam_qf])){
			return $map_count;
		}
		
		$roles = 'customer';
		
		$ext_roles = '';
		if($ext_roles!=''){
			$roles.=','.$ext_roles;
		}
		
		if ( ! is_array( $roles ) ){			
			$roles = array_map('trim',explode( ",", $roles ));
		}
		
		// Security: Build prepared statement with placeholders
		$placeholders = array();
		$values = array();
		
		foreach ( $roles as $role ) {
			$placeholders[] = $wpdb->usermeta . '.meta_value LIKE %s';
			$values[] = '%"' . $wpdb->esc_like($role) . '"%';
		}
		
		$capabilities_key = $wpdb->prefix . 'capabilities';
		$sql = $wpdb->prepare('SELECT ' . $wpdb->users . '.ID, ' . $wpdb->users . '.display_name, ' . $wpdb->users . '.user_email FROM ' . $wpdb->users . ' INNER JOIN ' . $wpdb->usermeta . ' ON ' . $wpdb->users . '.ID = ' . $wpdb->usermeta . '.user_id WHERE ' . $wpdb->usermeta . '.meta_key = %s AND (' . implode(' OR ', $placeholders) . ')', array_merge(array($capabilities_key), $values)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name is escaped with esc_sql(), values are properly prepared
		
		// Security: Validate and prepare column names
		if($cam_qf=='first_last'){
			$cam_qf_cl = "`FirstName` , `LastName`";
		}else{
			// Security: Validate column name against allowed fields
			$allowed_fields = $this->xero_customer_automap_fields();
			if(!isset($allowed_fields[$cam_qf])) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					wc_get_logger()->warning( 'MW Xero Sync Security: Invalid column name: ' . sanitize_text_field( $cam_qf ), array( 'source' => 'myworks-sync-for-xero' ) );
				}
				return $map_count;
			}
			$cam_qf_cl = "`" . esc_sql($cam_qf) . "`";
		}		
		
		$all_wc_customers = $this->get_data($sql);
		// Security: Use validated table name and prepared column list
		$validated_x_customer_tbl = $this->get_validated_table_name_from_full($x_customer_tbl);
		if(!$validated_x_customer_tbl) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				wc_get_logger()->warning( 'MW Xero Sync Security: Invalid customer table name', array( 'source' => 'myworks-sync-for-xero' ) );
			}
			return $map_count;
		}
		$all_qbo_customers = $this->get_data("SELECT `ContactID`, {$cam_qf_cl} FROM `" . esc_sql($validated_x_customer_tbl) . "`");
		
		if(!$mo_um){
			// Security: Use validated table names
			$validated_map_tbl = $this->get_validated_table_name_from_full($map_tbl);
			if($validated_map_tbl) {
				$wpdb->query("DELETE FROM `" . esc_sql($validated_map_tbl) . "` WHERE `id` > 0 ");
				$wpdb->query("TRUNCATE TABLE `" . esc_sql($validated_map_tbl) . "` ");
			}
		}
		
		if(is_array($all_wc_customers) && count($all_wc_customers) && is_array($all_qbo_customers) && count($all_qbo_customers)){
			foreach($all_wc_customers as $w_cus){
				$insert_id = (int) $this->check_save_automap_customer_data($w_cus,$all_qbo_customers,$cam_wf,$cam_qf,$mo_um);
				if($insert_id>0){
					$map_count++;
				}
			}
		}
		
		unset($all_wc_customers);
		unset($all_qbo_customers);
		
		return $map_count;
	}
	
	public function check_save_automap_customer_data($w_cus,$all_qbo_customers,$cam_wf,$cam_qf,$mo_um=false){
		global $wpdb;
		$map_tbl = $this->gdtn('map_customers');
		
		# For Only Unmapped
		if($mo_um){
			$ID = $w_cus['ID'];							
			$e_mr = $this->get_row($wpdb->prepare("SELECT `id` FROM {$map_tbl} WHERE `W_C_ID` = %d ",$ID)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped with esc_sql(), values are properly prepared
			if(!empty($e_mr)){
				return;
			}
		}
		
		if(!isset($w_cus[$cam_wf])){
			if($cam_wf=='first_name_last_name'){
				$w_cus[$cam_wf] = get_user_meta($w_cus['ID'],'first_name',true) . ' '. get_user_meta($w_cus['ID'],'last_name',true);
			}else{
				$w_cus[$cam_wf] = get_user_meta($w_cus['ID'],$cam_wf,true);
			}			
		}
		
		$wf_v = $this->get_array_isset($w_cus,$cam_wf,'',true);		
		
		if(!empty($cam_wf) && !empty($cam_qf)){
			foreach($all_qbo_customers as $q_cus){
				$is_match_map_customer = false;
				if(isset($q_cus[$cam_qf]) || $cam_qf == 'first_last'){
					if($cam_qf == 'first_last'){
						$qf_v = $this->get_array_isset($q_cus,'FirstName','',true) . ' '. $this->get_array_isset($q_cus,'LastName','',true);
					}else{
						$qf_v = $this->get_array_isset($q_cus,$cam_qf,'',true);
					}
					
					if($wf_v!='' && strtoupper($wf_v) == strtoupper($qf_v)){
						$is_match_map_customer = true;
					}
					
					if($is_match_map_customer){
						$save_data = array();
						$save_data['W_C_ID'] = $w_cus['ID'];
						$save_data['X_C_ID'] = $q_cus['ContactID'];
						$wpdb->insert($map_tbl,$save_data);
						return (int) $wpdb->insert_id;
						break;
					}
				}
			}
		}
	}
	
	public function AutoMapProducts($pam_wf,$pam_qf,$mo_um=false){
		global $wpdb;
		$map_count = 0;
		
		$map_tbl = $this->gdtn('map_products');
		$x_product_tbl = $this->gdtn('products');
		
		if(empty($pam_wf) || empty($pam_qf)){
			return $map_count;
		}
		
		if(!is_array($this->wc_product_automap_fields()) || !is_array($this->xero_product_automap_fields())){
			return $map_count;
		}
		
		$pam_wf_la = $this->wc_product_automap_fields();
		$pam_qf_la = $this->xero_product_automap_fields();
		
		if(!isset($pam_wf_la[$pam_wf]) || !isset($pam_qf_la[$pam_qf])){
			return $map_count;
		}
		
		$m_whr = '';
		if($pam_wf=='sku'){
			$m_whr.=" AND pm1.meta_value!=''";
		}
		
		$sql = "
			SELECT DISTINCT(p.ID), p.post_title AS name, pm1.meta_value AS sku
			FROM ".$wpdb->posts." p
			LEFT JOIN ".$wpdb->postmeta." pm1 ON ( pm1.post_id = p.ID
			AND pm1.meta_key =  '_sku' )
			WHERE p.post_type =  'product'
			AND p.post_status NOT IN('trash','auto-draft','inherit')
			{$m_whr}
		";
		
		$all_wc_products = $this->get_data($sql);
		$all_qbo_products = $this->get_data("SELECT `ItemID`, `Name` , `Code` FROM ".$x_product_tbl);
		
		if(!$mo_um){
			$wpdb->query($wpdb->prepare("DELETE FROM `" . esc_sql($map_tbl) . "` WHERE `id` > %d", 0));
			$wpdb->query($wpdb->prepare("TRUNCATE TABLE `" . esc_sql($map_tbl) . "`"));
		}
		
		if(is_array($all_wc_products) && count($all_wc_products) && is_array($all_qbo_products) && count($all_qbo_products)){
			foreach($all_wc_products as $w_pro){
				$insert_id = (int) $this->check_save_automap_product_data($w_pro,$all_qbo_products,$pam_wf,$pam_qf,$mo_um);
				if($insert_id>0){
					$map_count++;
				}
			}
		}
		unset($all_wc_products);
		unset($all_qbo_products);
		return $map_count;
	}
	
	public function check_save_automap_product_data($w_pro,$all_qbo_products,$pam_wf,$pam_qf,$mo_um=false){
		global $wpdb;
		$map_tbl = $this->gdtn('map_products');
		
		# For Only Unmapped
		if($mo_um){
			$ID = $w_pro['ID'];
			$e_mr = $this->get_row($wpdb->prepare("SELECT `id` FROM {$map_tbl} WHERE `W_P_ID` = %d ",$ID)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped with esc_sql(), values are properly prepared
			if(!empty($e_mr)){
				return;
			}
		}
		
		$wf_v = $this->get_array_isset($w_pro,$pam_wf,'',true);		
		
		foreach($all_qbo_products as $q_pro){
			$is_match_map_product = false;
			if(isset($q_pro[$pam_qf])){				
				$qf_v = $this->get_array_isset($q_pro,$pam_qf,'',true);				
				if($wf_v!='' && strtoupper($wf_v) == strtoupper($qf_v)){
					$is_match_map_product = true;
				}
			}
			
			if($is_match_map_product){
				$save_data = array();
				$save_data['W_P_ID'] = $w_pro['ID'];
				$save_data['X_P_ID'] = $q_pro['ItemID'];
				$wpdb->insert($map_tbl,$save_data);
				return (int) $wpdb->insert_id;
				break;
			}
		}
	}
	
	#With new last count functionality
	public function AutoMapVariations($vam_wf,$vam_qf,$mo_um=false){
		global $wpdb;
		
		$map_count = 0;
		
		$map_tbl = $this->gdtn('map_variations');
		$x_product_tbl = $this->gdtn('products');
		
		if(empty($vam_wf) || empty($vam_qf)){
			return $map_count;
		}
		
		if(!is_array($this->wc_variation_automap_fields()) || !is_array($this->xero_variation_automap_fields())){
			return $map_count;
		}
		
		$vam_wf_la = $this->wc_variation_automap_fields();
		$vam_qf_la = $this->xero_variation_automap_fields();
		
		if(!isset($vam_wf_la[$vam_wf]) || !isset($vam_qf_la[$vam_qf])){
			return $map_count;
		}
		
		$m_whr = '';
		$m_join = '';
		$m_slt = '';
		
		if($vam_wf=='sku'){
			$m_whr.=" AND pm1.meta_value!=''";
			$m_join.=" INNER JOIN ".$wpdb->postmeta." pm1 ON ( pm1.post_id = p.ID
			AND pm1.meta_key =  '_sku' )";
			
			$m_slt.=" , pm1.meta_value AS sku";
		}
		
		$sql_c = "
			SELECT COUNT(*)
			FROM ".$wpdb->posts." p	
			{$m_join}
			WHERE p.post_type =  'product_variation'
			AND p.post_status NOT IN('trash','auto-draft','inherit')
			{$m_whr}
		";
		
		$mml = 500;
		$amc = (int) $wpdb->get_var($sql_c); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Complex query with validated tables
		
		$mbc =  ($mml >= $amc) ? 1 : ceil($amc / $mml);
		
		/**/
		$li = 0;
		$amlcd_a = get_option('mw_wc_xero_automap_last_c_data');
		if(!is_array($amlcd_a)){$amlcd_a = array();}
		if(!empty($amlcd_a) && isset($amlcd_a['pv']) && is_array($amlcd_a['pv']) && !empty($amlcd_a['pv'])){
			$lcd_pv = $amlcd_a['pv'];
			if($lcd_pv['wk'] == $vam_wf && $lcd_pv['qk'] == $vam_qf){
				if($lcd_pv['li'] != $mbc){
					$li = $lcd_pv['li'];
				}
			}
		}
		
		$all_wc_variations = array();
		#$all_qbo_products = array();
		
		if(!$mo_um){
			$wpdb->query($wpdb->prepare("DELETE FROM `" . esc_sql($map_tbl) . "` WHERE `id` > %d", 0));
			$wpdb->query($wpdb->prepare("TRUNCATE TABLE `" . esc_sql($map_tbl) . "`"));
		}
		
		$all_qbo_products = $this->get_data("SELECT `ItemID`, `Name` , `Code` FROM ".$x_product_tbl);
		
		$li_n = 0;
		for ($i=$li; $i<$mbc; $i++) {
			$mlo = $i*$mml;
			
			$sql_d = "
				SELECT DISTINCT(p.ID), p.post_title AS name {$m_slt}
				FROM ".$wpdb->posts." p	
				{$m_join}
				WHERE p.post_type =  'product_variation'
				AND p.post_status NOT IN('trash','auto-draft','inherit')
				{$m_whr}
				ORDER BY name ASC
				LIMIT {$mlo}, {$mml}
			";
			
			$all_wc_variations = $this->get_data($sql_d);
			
			if(is_array($all_wc_variations) && count($all_wc_variations) && is_array($all_qbo_products) && count($all_qbo_products)){
				foreach($all_wc_variations as $w_pro){
					$insert_id = (int) $this->check_save_automap_variation_data($w_pro,$all_qbo_products,$vam_wf,$vam_qf,$mo_um);
					if($insert_id>0){
						$map_count++;
					}
				}
			}			
			
			$li_n = $i+1;			
		}
		
		$amlcd_a['pv'] = array(
			'wk' => $vam_wf,
			'qk' => $vam_qf,
			'li' => $li_n
		);
		
		update_option('mw_wc_xero_automap_last_c_data',$amlcd_a,'no');
		
		unset($all_wc_variations);
		unset($all_qbo_products);
		return $map_count;
	}
	
	public function check_save_automap_variation_data($w_pro,$all_qbo_products,$vam_wf,$vam_qf,$mo_um=false){
		global $wpdb;
		$map_tbl = $this->gdtn('map_variations');
		
		# For Only Unmapped
		if($mo_um){
			$ID = $w_pro['ID'];
			$e_mr = $this->get_row($wpdb->prepare("SELECT `id` FROM {$map_tbl} WHERE `W_V_ID` = %d ",$ID)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped with esc_sql(), values are properly prepared
			if(!empty($e_mr)){
				return;
			}
		}
		
		$wf_v = $this->get_array_isset($w_pro,$vam_wf,'',true);
		
		foreach($all_qbo_products as $q_pro){
			$is_match_map_variation = false;
			if(isset($q_pro[$vam_qf])){				
				$qf_v = $this->get_array_isset($q_pro,$vam_qf,'',true);				
				if($wf_v!='' && strtoupper($wf_v) == strtoupper($qf_v)){
					$is_match_map_variation = true;
				}
			}
			
			if($is_match_map_variation){
				$save_data = array();
				$save_data['W_V_ID'] = $w_pro['ID'];
				$save_data['X_P_ID'] = $q_pro['ItemID'];
				$wpdb->insert($map_tbl,$save_data);
				return (int) $wpdb->insert_id;
				break;
			}
		}
	}
	
	# Clear Invalid Mappings
	private function clear_invalid_mappings($type,$loop=false){
		$list_table = '';$it_id_field = '';$it_qb_id_field = '';
		$map_table = '';$mt_id_field = '';$mt_qb_id_field = '';
		
		switch ($type) {
			case "product":
				$list_table = $this->gdtn('products');
				$map_table = $this->gdtn('map_products');
				
				$it_qb_id_field = 'ItemID';
				$mt_qb_id_field = 'X_P_ID';
				
				break;
			case "variation":
				$list_table = $this->gdtn('products');
				$map_table = $this->gdtn('map_variations');
				
				$it_qb_id_field = 'ItemID';
				$mt_qb_id_field = 'X_P_ID';
				
				break;
			case "customer":
				$list_table = $this->gdtn('customers');
				$map_table = $this->gdtn('map_customers');
				
				$it_qb_id_field = 'ContactID';
				$mt_qb_id_field = 'X_C_ID';
				
				break;
			case "paymentmethod":
				
				break;
			default:			
		}
		
		if($list_table!='' && $map_table!='' && $it_qb_id_field!='' && $mt_qb_id_field!=''){
			global $wpdb;			
			
			if(empty($it_id_field)){
				$it_id_field = 'id';
			}
			
			if(empty($mt_id_field)){
				$mt_id_field = 'id';
			}
			
			if($loop){
				return $this->clear_invalid_mappings_by_loop($list_table,$map_table,$it_id_field,$mt_id_field,$it_qb_id_field,$mt_qb_id_field);
			}
			
			/*
			$sq = " SELECT `{$it_qb_id_field}` FROM {$list_table} ";
			$q = " DELETE FROM {$map_table} WHERE `{$mt_qb_id_field}` NOT IN ({$sq}) ";
			*/
			
			// Security: Validate table names and use esc_sql for field names
			$validated_list_table = $this->get_validated_table_name_from_full($list_table);
			$validated_map_table = $this->get_validated_table_name_from_full($map_table);
			
			if($validated_list_table && $validated_map_table) {
				$sq = "SELECT `" . esc_sql($it_qb_id_field) . "` FROM `" . esc_sql($validated_list_table) . "` WHERE `" . esc_sql($validated_list_table) . "`.`" . esc_sql($it_qb_id_field) . "` = `" . esc_sql($validated_map_table) . "`.`" . esc_sql($mt_qb_id_field) . "`";
				$q = "DELETE FROM `" . esc_sql($validated_map_table) . "` WHERE NOT EXISTS ({$sq})";
				$wpdb->query($q); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- DELETE with validated tables
			}
			return true;
		}
	}
	
	private function clear_invalid_mappings_by_loop($list_table,$map_table,$it_id_field,$mt_id_field,$it_qb_id_field,$mt_qb_id_field){
		global $wpdb;
		
		// Security: Validate table names
		$validated_list_table = $this->get_validated_table_name_from_full($list_table);
		$validated_map_table = $this->get_validated_table_name_from_full($map_table);

		if(!$validated_list_table || !$validated_map_table) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				wc_get_logger()->warning( 'MW Xero Sync Security: Invalid table names in clear_invalid_mappings_by_loop', array( 'source' => 'myworks-sync-for-xero' ) );
			}
			return 0;
		}
		
		$map_data = $this->get_data("SELECT `" . esc_sql($mt_id_field) . "`, `" . esc_sql($mt_qb_id_field) . "` FROM `" . esc_sql($validated_map_table) . "`");
		$tot_deleted = 0;
		if(is_array($map_data) && count($map_data)){
			foreach($map_data as $md){
				$mt_id_val = (int) $md[$mt_id_field];
				$mt_qb_val = $md[$mt_qb_id_field];
				$mt_qb_val = $this->sanitize($mt_qb_val);
				$ld = $this->get_row($wpdb->prepare("SELECT `" . esc_sql($it_id_field) . "` FROM `" . esc_sql($validated_list_table) . "` WHERE `" . esc_sql($it_qb_id_field) . "` !='' AND `" . esc_sql($it_qb_id_field) . "` = %s", $mt_qb_val));
				if(empty($ld)){
					$wpdb->query($wpdb->prepare("DELETE FROM `" . esc_sql($validated_map_table) . "` WHERE `" . esc_sql($mt_id_field) . "` = %d AND `" . esc_sql($mt_qb_id_field) . "` = %s", $mt_id_val, $mt_qb_val));
					$tot_deleted++;
				}
			}
		}
		return $tot_deleted;
	}
	
	public function clear_customer_invalid_mappings(){
		return $this->clear_invalid_mappings('customer',true);
	}
	
	public function clear_product_invalid_mappings(){
		return $this->clear_invalid_mappings('product',true);
	}
	
	public function clear_variation_invalid_mappings(){
		return $this->clear_invalid_mappings('variation',true);
	}
	
	# WC Customer details by ID
	public function get_wc_customer_info($customer_id,$user_info=null,$manual=false){
		$customer_data = array();
		$customer_id = (int) $customer_id;
		
		if($customer_id>0){
			if(is_null($user_info)){
				$user_info = get_userdata($customer_id);
			}
			
			if(empty($user_info)){
				return $customer_data;
			}
			
			$user_id = $user_info->ID;
			$user_meta = get_user_meta($user_id);
			
			if(!is_array($user_meta)){
				$user_meta = array();
			}
			
			$customer_data['wc_cus_id'] = $user_id;
			$customer_data['first_name'] = (isset($user_info->first_name))?$user_info->first_name:'';
			$customer_data['last_name'] = (isset($user_info->last_name))?$user_info->last_name:'';
			$customer_data['full_name'] = $customer_data['first_name'].' '.$customer_data['last_name'];
			
			$customer_data['email'] = (isset($user_info->user_email))?$user_info->user_email:'';
			$customer_data['display_name'] = (isset($user_info->display_name))?$user_info->display_name:'';
			$customer_data['username'] = (isset($user_info->user_login))?$user_info->user_login:'';
			
			$customer_data['company'] = (isset($user_meta['billing_company'][0]))?$user_meta['billing_company'][0]:'';
			
			$amk = array(
				'nickname',
				'description',
				#'wc_last_active',
				#'dismissed_wp_pointers',
				#'dismissed_update_notice',				
			);
			
			if(!empty($user_meta)){
				foreach ($user_meta as $key => $value){
					if(in_array($key,$amk) || $this->start_with($key,'billing_') || $this->start_with($key,'shipping_')){
						$customer_data[$key] = ($value[0])?$value[0]:'';
					}					
				}
			}

			$customer_data['currency'] = (string) $this->get_wc_customer_currency($user_id);
			
			$customer_data['manual'] = $manual;
		}
		
		return $customer_data;
	}
	
	public function get_wc_customer_info_from_order($order_id,$manual=false){
		$customer_data = array();
		$order_id = (int) $order_id;
		if($order_id > 0){
			$order_meta = $this->get_all_order_meta_hpos($order_id);
			if(empty($order_meta)){
				return $customer_data;
			}

			$_customer_user = (isset($order_meta['_customer_user'][0]))?(int) $order_meta['_customer_user'][0]:0;
			$customer_data['wc_cus_id'] = $_customer_user;
			$customer_data['order_id'] = $order_id;

			if(is_array($order_meta) && count($order_meta)){
				foreach ($order_meta as $key => $value){
					if($this->start_with($key,'_billing_') || $this->start_with($key,'_shipping_')){
						if($this->start_with($key,'_billing_')){
							$key = str_replace('_billing_','billing_',$key);
						}else{
							$key = str_replace('_shipping_','shipping_',$key);
						}
						$customer_data[$key] = ($value[0])?$value[0]:'';
					}
				}
			}

			$customer_data['first_name'] = $this->get_array_isset($customer_data,'billing_first_name','',true);
			$customer_data['last_name'] = $this->get_array_isset($customer_data,'billing_last_name','',true);
			$customer_data['full_name'] = $customer_data['first_name'].' '.$customer_data['last_name'];

			$customer_data['email'] = $this->get_array_isset($customer_data,'billing_email','',true);
			$customer_data['company'] = $this->get_array_isset($customer_data,'billing_company','',true);

			$_order_currency = (isset($order_meta['_order_currency'][0]))?$order_meta['_order_currency'][0]:'';
			$customer_data['currency'] = $_order_currency;

			$customer_data['manual'] = $manual;
		}

		return $customer_data;
	}

	# Order address as one line, formatted the way WooCommerce itself shows it (name, company, street, locality, country)
	# Feeds the Billing Address / Shipping Address sources in Map > Custom Fields. Returns '' when the order has no such address.
	public function get_wc_order_address_line($order_id, $address_type = 'shipping', $order = null){
		$address_line = '';

		$order_id = (int) $order_id;
		if($order_id > 0){
			# WC_Abstract_Order, not WC_Order: WC_Order_Refund extends the abstract too, so a refund
			# object handed in is reused and rejected by the method_exists() check below rather than
			# being reloaded first. A WP_Post (the legacy path hands one in) is NOT an order object,
			# so it is replaced by a real load - that path returns the address, it does not yield ''.
			if(!is_a($order, 'WC_Abstract_Order')){
				$order = wc_get_order($order_id);
			}

			# Refunds (WC_Order_Refund) have no formatted address methods and correctly yield ''
			$method = ($address_type === 'billing')?'get_formatted_billing_address':'get_formatted_shipping_address';
			if(is_object($order) && method_exists($order, $method)){
				# WooCommerce guards get_formatted_shipping_address() with has_shipping_address() but leaves
				# the billing one unguarded (verified in WC 11.0.1), so an order carrying only a name and no
				# street still formats to "John Smith" and would reach the invoice as
				# "Billing Address: John Smith". Apply the same test to both so either source means "this
				# order really has that address". Redundant for shipping, kept for symmetry.
				$has_method = ($address_type === 'billing')?'has_billing_address':'has_shipping_address';
				$has_address = (!method_exists($order, $has_method) || $order->{$has_method}());

				$formatted = ($has_address)?(string) $order->{$method}(''):'';
				if($formatted !== ''){
					# WooCommerce joins the lines with <br/> and HTML-escapes the values - flatten to a single comma-separated line
					# The (string) casts are load-bearing, not decoration: preg_replace() returns NULL on a PCRE
					# failure (backtrack/JIT limits), and passing null to trim() is deprecated on PHP 8.1 - the
					# plugin's floor - and a TypeError on PHP 9. Flagged by PHPStan on PR #144. A failed substitution
					# yields '', so the address is simply omitted, which is what every other no-address path here does.
					$address_line = (string) preg_replace('/<br\s*\/?>/i', ', ', $formatted);
					$address_line = wp_strip_all_tags($address_line);
					$address_line = html_entity_decode($address_line, ENT_QUOTES, 'UTF-8');
					$address_line = (string) preg_replace('/\s+/', ' ', $address_line);
					$address_line = trim($address_line, ' ,');
				}
			}
		}

		return $address_line;
	}

	# WC Order details by ID
	public function get_wc_order_details_from_order($order_id,$order=null,$manual=false){
		global $wpdb;
		$invoice_data = array();
		
		$order_id = (int) $order_id;
		if($order_id > 0){
			if(is_null($order)){
				$order = get_post($order_id);
			}
			
			if(is_object($order) && !empty($order)){
				$order_meta = $this->get_all_order_meta_hpos($order_id);
				if(!is_array($order_meta)){
					$order_meta = array();
				}
				
				$invoice_data['wc_inv_id'] = $order_id;
				
				#$invoice_data['wc_inv_num'] = '';
				$invoice_data['wc_inv_num'] = $this->get_woo_ord_number_from_order($order_id);
				
				$invoice_data['order_type'] = '';
				
				$order_date = $this->get_hpos_order_date($order);
				$wc_inv_date = $order_date;
				
				$s_odf = $this->get_option('mw_wc_xero_sync_order_date_val');
				if($s_odf == 'order_completed_date'){
					$wc_inv_date = (isset($order_meta['_completed_date'][0]) && !empty($order_meta['_completed_date'][0]))?$order_meta['_completed_date'][0]:$order_date;
				}
				
				if($s_odf == 'order_paid_date'){
					$wc_inv_date = (isset($order_meta['_paid_date'][0]) && !empty($order_meta['_paid_date'][0]))?$order_meta['_paid_date'][0]:$order_date;
				}
				
				if($s_odf == 'date_of_sync'){
					$wc_inv_date = $this->now('Y-m-d');
				}
				
				$invoice_data['wc_inv_date'] = $wc_inv_date;
				$invoice_data['order_date'] = $order_date;
				
				$invoice_data['customer_note'] = method_exists( $order, 'get_customer_note' ) ? $order->get_customer_note() : $order->post_excerpt;

				$invoice_data['order_status'] = method_exists( $order, 'get_status' ) ? $order->get_status() : $order->post_status;
				
				$wc_cus_id = isset($order_meta['_customer_user'][0])?(int) $order_meta['_customer_user'][0]:0;
				$invoice_data['wc_cus_id'] = $wc_cus_id;
				#Wc user role
				$wc_user_role = ($wc_cus_id > 0)?$this->get_wc_user_role_by_id($wc_cus_id):'wc_guest_user';
				$invoice_data['wc_user_role'] = $wc_user_role;
				
				if(!empty($order_meta)){
					foreach ($order_meta as $key => $value){
						$invoice_data[$key] = ($value[0])?$value[0]:'';
					}
				}

				# Formatted addresses, offered as sources in Map > Custom Fields (set after the meta fold so they can't be shadowed by order meta)
				# Resolved once for both: $order here is a WP_Post whenever the caller passed nothing
				# (get_post() above), and letting each call load its own copy costs two order
				# constructions per sync. That happens on post-based stores, which is exactly where
				# WooCommerce's own order cache is inactive (it is an HPOS feature), so nothing
				# absorbs the second one.
				$cf_order = is_a($order, 'WC_Abstract_Order')?$order:wc_get_order($order_id);
				$invoice_data['wc_billing_address'] = $this->get_wc_order_address_line($order_id, 'billing', $cf_order);
				$invoice_data['wc_shipping_address'] = $this->get_wc_order_address_line($order_id, 'shipping', $cf_order);

				$cf_map_data = array();
				
				$wc_oi_table = $wpdb->prefix.'woocommerce_order_items';
				$wc_oi_meta_table = $wpdb->prefix.'woocommerce_order_itemmeta';
				
				// Security: Escape table names even though they're WordPress core tables
				$order_items = $this->get_data($wpdb->prepare("SELECT * FROM `" . esc_sql($wc_oi_table) . "` WHERE `order_id` = %d ORDER BY order_item_id ASC ",$order_id));
				$line_items = $used_coupons = $tax_details = $shipping_details = array();
				
				$dc_gt_fees = array();
				$pw_gift_card = array();
				$gift_card = array();
				
				if(is_array($order_items) && !empty($order_items)){
					foreach($order_items as $oi){
						$order_item_id = (int) $oi['order_item_id'];
						$oi_meta = $this->get_data($wpdb->prepare("SELECT * FROM `" . esc_sql($wc_oi_meta_table) . "` WHERE `order_item_id` = %d ",$order_item_id));
						
						$om_arr = array();
						if(is_array($oi_meta) && count($oi_meta)){
							foreach($oi_meta as $om){
								$om_arr[$om['meta_key']] = $om['meta_value'];
							}
						}

						$om_arr['name'] = $oi['order_item_name'];
						$om_arr['type'] = $oi['order_item_type'];

						if($oi['order_item_type']=='line_item'){
							$om_arr['order_item_id'] = $order_item_id;
							$line_items[] = $om_arr;
						}

						if($oi['order_item_type']=='coupon'){
							$used_coupons[] = $om_arr;
						}
						
						if($oi['order_item_type']=='shipping'){
							if(isset($om_arr['name'])){
								$om_arr['name'] = $this->get_array_isset($om_arr,'name');
							}
							$shipping_details[] = $om_arr;
						}						
						
						if($oi['order_item_type']=='tax'){
							if(isset($om_arr['label'])){
								$om_arr['label'] = $this->get_array_isset($om_arr,'label');
							}
							$tax_details[] = $om_arr;
						}
						
						if($oi['order_item_type']=='fee' || $oi['order_item_type'] == 'shipping_option'){
							if(isset($om_arr['name'])){
								$om_arr['name'] = $this->get_array_isset($om_arr,'name');
							}							
							
							if($oi['order_item_type'] == 'shipping_option'){
								$om_arr['_line_total'] = $om_arr['cost'];
								$om_arr['_line_tax'] = $om_arr['tax_amount'];
								if($om_arr['total_tax']){
									$om_arr['_line_tax'] = $om_arr['total_tax'];
								}
							}
							
							$dc_gt_fees[] = $om_arr;
						}
						
						# Gift card will be added later
					}
				}
				
				$xero_inv_items = array();
				if(is_array($line_items) && !empty($line_items)){
					foreach ( $line_items as $item ) {
						$product_data = array();
					
						foreach($item as $key=>$val){
							if($this->start_with($key,'_')  && $key != '_qty'){
								$key = substr($key,1);
							}
							
							$product_data[$key] = $val;
						}
						
						$_qty = abs($product_data['_qty']);
						if(!$_qty){$_qty = 1;}
						$product_data['_qty'] = $_qty;

						$l_up = ($product_data['line_subtotal']/$product_data['_qty']);

						# Handle manually entered orders where line_subtotal is 0 but line_total has correct value
						if(isset($product_data['line_total']) && isset($product_data['line_subtotal'])){
							if((float) $product_data['line_subtotal'] == 0 && (float) $product_data['line_total'] != 0){
								$l_up = ($product_data['line_total']/$product_data['_qty']);
							}
						}

						$product_data['unit_price'] = $l_up;

						$mapped_xero_item = $this->get_mapped_xero_items_from_wc_items($product_data,$invoice_data,$cf_map_data);
						if(!empty($mapped_xero_item)){
							$xero_inv_items[] = $mapped_xero_item;
						}
					}
				}
				
				$invoice_data['used_coupons'] = $used_coupons;

				$order_shipping_total = isset($order_meta['_order_shipping'][0])?$order_meta['_order_shipping'][0]:0;
				$invoice_data['shipping_details'] = $shipping_details;
				$invoice_data['order_shipping_total'] = $order_shipping_total;

				$invoice_data['tax_details'] = $tax_details;

				$invoice_data['xero_inv_items'] = $xero_inv_items;
				
				$invoice_data['dc_gt_fees'] = $dc_gt_fees;
				
				#$invoice_data['pw_gift_card'] = $pw_gift_card;				
				#$invoice_data['gift_card'] = $gift_card;
				
				$invoice_data['manual'] = $manual;

			}
		}
		
		return $invoice_data;
	}
	
	public function get_mapped_xero_items_from_wc_items($wc_items=array(),$invoice_data=array(),$cf_map_data=array()){
		$xero_items = array();
		if(is_array($wc_items) && !empty($wc_items)){
			$map_data = array();
			global $wpdb;
			
			$wc_variation_id = (isset($wc_items['variation_id']))?(int) $wc_items['variation_id']:0;
			$wc_product_id = (isset($wc_items['product_id']))?(int) $wc_items['product_id']:0;
			
			if(empty($map_data)){				
				if($wc_variation_id > 0){
					$map_data = $this->get_row($wpdb->prepare("SELECT `X_P_ID` AS ItemID FROM `".$this->gdtn('map_variations')."` WHERE `W_V_ID` = %d AND `X_P_ID` !='' ",$wc_variation_id)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name is escaped with esc_sql(), values are properly prepared
				}
			}
			
			if(empty($map_data) && $wc_product_id > 0){				
				$map_data = $this->get_row($wpdb->prepare("SELECT `X_P_ID` AS ItemID FROM `".$this->gdtn('map_products')."` WHERE `W_P_ID` = %d AND `X_P_ID` !='' ",$wc_product_id)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name is escaped with esc_sql(), values are properly prepared			
			}
			
			$xero_item_id = '';
			$xero_product_data = array();

			# Category -> Product Map
			if(empty($map_data)){
				if($wc_product_id > 0){
					$terms = get_the_terms ( (int) $wc_product_id, 'product_cat' );
					if(!empty($terms)){
						foreach ( $terms as $term ) {
							$cat_map_data = $this->get_row_by_val($this->gdtn('map_categories'),'W_CAT_ID',$term->term_id);
							if(is_array($cat_map_data) && !empty($cat_map_data)){
								if(!empty($cat_map_data['X_P_ID'])){
									$map_data['ItemID'] = $cat_map_data['X_P_ID'];
									break;
								}
							}
						}
					}
				}
			}

			if(!empty($map_data)){
				$xero_item_id = $map_data['ItemID'];
				$xero_item_id = $this->sanitize($xero_item_id);								
			}			
			
			if(empty($map_data)){
				$xero_item_id = $this->get_option('mw_wc_xero_sync_default_xero_product');
				$xero_item_id = $this->sanitize($xero_item_id);							
			}

			if(!empty($xero_item_id)){
				$xero_product_data = $this->get_row_by_val($this->gdtn('products'),'ItemID',$xero_item_id);
			}

			if(empty($xero_product_data)){
				if(empty($xero_item_id)){
					// No Xero mapping at all — skip this line item
					return array();
				}
				// Mapped to a Xero ItemID but not in local cache — refresh from Xero API to get Code
				$this->X_Pull_Product_By_Id($xero_item_id);
				$xero_product_data = $this->get_row_by_val($this->gdtn('products'),'ItemID',$xero_item_id);
				// If still empty after API refresh, fall back to minimal data so line item still syncs
				if(empty($xero_product_data)){
					$xero_product_data = array('ItemID' => $xero_item_id);
				}
			}
			
			$wc_pv_id = ($wc_variation_id > 0)?$wc_variation_id:$wc_product_id;
			
			$Description = $this->get_array_isset($wc_items,'name','');
			
			$s_olidfv = $this->get_option('mw_wc_xero_sync_order_line_item_desc_val_s');
			
			if($s_olidfv  == 'wc_pv_short_desc'){
				$Description = $this->get_field_by_val($wpdb->posts,'post_excerpt','ID',$wc_pv_id);

				if(empty($Description) && $wc_variation_id > 0){
					$Description = $this->get_field_by_val($wpdb->posts,'post_excerpt','ID',$wc_product_id);
				}
			}

			if($s_olidfv  == 'xero_p_desc'){
				$Description = $this->get_array_isset($xero_product_data,'Description','');
			}
			
			if($s_olidfv  == 'wc_pv_backorder_s'){
				$pbs = get_post_meta($wc_pv_id,'_backorders',true);
				if($pbs == 'yes'){					
					$Description = 'Allow';
				}elseif($pbs == 'notify'){
					$Description = 'Allow, but notify customer';
				}else{
					$Description = 'Do not allow';
				}
			}
			
			$Description = trim($Description);
			if($s_olidfv  == 'no_desc' || empty($Description)){
				$Description = '-';
			}
			
			/*Extra Description*/
			#$o_li_meta_data = '';			
			
			# WooCommerce Product Add-ons
			$pv_adn_arr = array();
			
			# Add WooCommerce Custom Order Line Item Meta Into Xero Line Item Description
			if($this->option_checked('mw_wc_xero_sync_add_w_oli_meta_into_xero_oli')){
				$solm_arr = array(
					'name', '_qty', 'qty',
					'unit_price', 'product_id', 'variation_id',
					'tax_class', 'line_subtotal', 'line_subtotal_tax',
					'line_total', 'line_tax', 'line_tax_data',
					'wc_avatax_rate', 'wc_avatax_code', 'wc_cog_item_cost',
					'wc_cog_item_total_cost', 'reduced_stock', 'type','order_item_id',
					'tmcartepo_data', 'vpc-cart-data', '_order_item_wh',
					
					'line_subtotal_base_currency', 'line_total_base_currency', 'line_tax_base_currency',
				);
				
				if(is_array($pv_adn_arr) && !empty($pv_adn_arr)){
					foreach($pv_adn_arr as $paa){
						$solm_arr[] = $paa;
					}
				}
				
				# Only Add Specific Line Item Metas Into Xero Line Item Description
				$oaslim_a = array();
				$oaslim = $this->get_option('mw_wc_xero_sync_only_add_slim_into_xero_oli');
				if(!empty($oaslim)){
					$oaslim_a = explode(',',$oaslim);
					if(is_array($oaslim_a) && !empty($oaslim_a)){
						$oaslim_a = array_map('trim',$oaslim_a);
					}
				}
				
				$ext_olim_d = '';
				foreach($wc_items as $wk => $wv){
					if(empty($wv)){continue;}
					
					$is_olim_lid_add = true;
					if(in_array($wk,$solm_arr)){
						$is_olim_lid_add = false;
					}					
					
					if(!empty($oaslim_a) && !in_array($wk,$oaslim_a)){
						$is_olim_lid_add = false;
					}
					
					$is_va_pa_olim = false;
					if($wc_variation_id && $this->start_with($wk,'pa_')){						
						$is_va_pa_olim = true;
					}
					
					if($is_olim_lid_add && !$is_va_pa_olim){
						$olim_csd = @unserialize($wv);
						if ($wv === 'b:0;' || $olim_csd !== false) {
							$is_olim_lid_add = false;							
							$is_sv_a = false;
							if($wk == 'product_attributes'){
								$is_sv_a = true;
								
							}
							
							if($is_sv_a){
								if(is_array($olim_csd) && !empty($olim_csd)){									
									$iaa = false;
									if(array_keys($olim_csd) !== range(0, count($olim_csd) - 1)){
										$iaa = true;										
									}
									
									foreach($olim_csd as $sak => $sav){
										$sav = trim($sav);
										if($iaa){
											$sak = ucfirst(str_replace('_',' ',$sak));	
											$ext_olim_d.=$sak.': '.$sav.PHP_EOL;
										}else{
											$ext_olim_d.=$sav.PHP_EOL;
										}
									}
								}
							}							
						}
					}
					
					if($is_olim_lid_add){						
						if($is_va_pa_olim){
							$eolm_k = wc_attribute_label($wk);
						}else{
							$eolm_k = ucfirst(str_replace('_',' ',$wk));
						}
						
						$eolm_v = trim($wv);
						if($is_va_pa_olim){							
							$eolm_v = ucfirst(str_replace('-',' ',$wv));
						}
						
						$ext_olim_d.=$eolm_k.': '.$eolm_v.PHP_EOL;
						
					}
				}
				
				if(!empty($ext_olim_d)){
					$Description.=PHP_EOL.$ext_olim_d;
				}
				
				# SKU
				$is_lim_sku_ad = true;
				if(!empty($oaslim_a) && !in_array('sku',$oaslim_a)){
					$is_lim_sku_ad = false;
				}
				
				$lim_sku = '';
				if($is_lim_sku_ad){
					$lim_sku = $this->get_field_by_val($wpdb->prefix.'wc_product_meta_lookup','sku','product_id',$wc_pv_id);
				}
				
				if(!empty($lim_sku)){
					$Description.=PHP_EOL.'SKU: '.$lim_sku;
				}
			}
			
			$Description = str_replace(PHP_EOL . PHP_EOL,PHP_EOL,$Description);
			$Description = $this->get_array_isset(array('Description'=>$Description),'Description','',false);
			
			if(is_array($xero_product_data) && !empty($xero_product_data)){
				$xero_items_tmp = array();
				$xero_items_tmp['Description'] = $Description;
				$xero_items_tmp['UnitPrice'] = $wc_items['unit_price'];
				$xero_items_tmp['Qty'] = $wc_items['_qty'];
				
				# Xero Product Data
				$X_P_L_D = $this->get_xero_product_line_item_data($xero_product_data['ItemID'],$xero_product_data);
				$xero_items_tmp['X_ItemID'] = $xero_product_data['ItemID'];

				$xero_items_tmp['X_Code'] = $X_P_L_D['Code'];	
				$xero_items_tmp['X_IsTrackedAsInventory'] = $X_P_L_D['IsTrackedAsInventory'];

				$xero_items_tmp['X_SD_AccountCode'] = $X_P_L_D['SD_AccountCode'];
				$xero_items_tmp['X_SD_TaxType'] = $X_P_L_D['SD_TaxType'];
				
				$xero_items_tmp['Taxed'] = ($wc_items['line_tax']>0)?1:0;
				
				$xero_items = $xero_items_tmp;
				foreach($wc_items as $k => $val){
					if($k!='name' && $k!='_qty' && $k!='unit_price'){
						$xero_items[$k] = $val;
					}					
				}
			}			
		}
		
		return $xero_items;
	}
	
	# Get Product details by ID
	public function get_wc_product_info($product_id,$_product=null,$manual=false){
		$product_id = (int) $product_id;
		if($product_id>0){
			if(is_null($_product)){
				$_product = wc_get_product($product_id);
			}
			
			#$this->_p($_product);
			
			if(!is_object($_product) || empty($_product)){
				return;
			}
			
			$product_meta = get_post_meta($product_id);
			
			$product_data = array();
			
			$woo_version = $this->get_woo_version_number();
			if ( $woo_version >= 3.0 ) {
				$product_data['wc_product_id'] = $_product->get_id();
				$p_data = $_product->get_data();
				$product_data['product_type'] = '';
				$product_data['total_stock'] = '';
				
				$product_data['name'] = $p_data['name'];			
				$product_data['description'] = $p_data['description'];
				$product_data['short_description'] = $p_data['short_description'];
			}else{
				$product_data['wc_product_id'] = $_product->id;
				$product_data['product_type'] = $_product->product_type;
				$product_data['total_stock'] = $_product->total_stock;
				
				$product_data['name'] = $_product->post->post_title;			
				$product_data['description'] = $_product->post->post_content;
				$product_data['short_description'] = $_product->post->post_excerpt;
			}			
			
			if(is_array($product_meta) && count($product_meta)){
				foreach ($product_meta as $key => $value){
					$product_data[$key] = ($value[0])?$value[0]:'';
				}
			}
			
			$product_data['manual'] = $manual;
			return $product_data;	
		}
	}

	# Get Variation details by ID
	public function get_wc_variation_info($variation_id,$_variation=null,$manual=false){
		$variation_id = (int) $variation_id;
		if($variation_id>0){
			if(is_null($_variation)){
				$_variation = get_post($variation_id);
			}
			
			#$this->_p($_variation);

			if(!is_object($_variation) || empty($_variation)){
				return;
			}
			
			$variation_meta = get_post_meta($variation_id);
			
			$variation_data = array();
			$variation_data['wc_variation_id'] = $_variation->ID;
			$variation_data['wc_product_id'] = $variation_data['wc_variation_id'];

			$variation_data['name'] = $this->get_variation_name_from_id($_variation->post_title,'',$variation_id);
			$variation_data['name_t'] = $this->get_woo_v_name_trimmed($variation_data['name']);

			$variation_data['description'] = $_variation->post_content;
			$variation_data['short_description'] = $_variation->post_excerpt;

			if(is_array($variation_meta) && count($variation_meta)){
				foreach ($variation_meta as $key => $value){
					$variation_data[$key] = ($value[0])?$value[0]:'';
				}
			}

			$variation_data['is_variation'] = true;
			$variation_data['manual'] = $manual;
			return $variation_data;
		}
	}
	
	# Local Xero sync state on the order so the Orders list renders status without live API calls. Refs #83
	# Three states: invoice id present = Synced; checked_at present + no id = Not Synced (cached);
	# neither = Unknown (never checked -> one-time AJAX backfill).
	public function set_order_xero_sync_state($order_id,$invoice_id,$status='',$invoice_number=''){
		$order_id = (int) $order_id;
		$invoice_id = (string) $invoice_id;
		$invoice_number = (string) $invoice_number;
		if($order_id < 1 || strlen($invoice_id) != 36){
			return false;
		}

		if($this->is_hpos_enabled()){
			$order = wc_get_order($order_id);
			if(!$order){
				return false;
			}
			$order->update_meta_data('_mwxs_xero_invoice_id',$invoice_id);
			if($status !== ''){
				$order->update_meta_data('_mwxs_xero_invoice_status',$status);
			}
			if($invoice_number !== ''){
				$order->update_meta_data('_mwxs_xero_invoice_number',$invoice_number);
			}
			$order->update_meta_data('_mwxs_xero_checked_at',time());
			$order->save();
		}else{
			update_post_meta($order_id,'_mwxs_xero_invoice_id',$invoice_id);
			if($status !== ''){
				update_post_meta($order_id,'_mwxs_xero_invoice_status',$status);
			}
			if($invoice_number !== ''){
				update_post_meta($order_id,'_mwxs_xero_invoice_number',$invoice_number);
			}
			update_post_meta($order_id,'_mwxs_xero_checked_at',time());
		}

		return true;
	}

	# Marks an order as checked-and-not-synced (cached negative): clears the invoice id but records that
	# we looked, so the column renders "Not Synced" server-side instead of re-querying Xero every load.
	public function set_order_xero_not_synced($order_id){
		$order_id = (int) $order_id;
		if($order_id < 1){
			return false;
		}

		if($this->is_hpos_enabled()){
			$order = wc_get_order($order_id);
			if(!$order){
				return false;
			}
			$order->delete_meta_data('_mwxs_xero_invoice_id');
			$order->delete_meta_data('_mwxs_xero_invoice_status');
			$order->delete_meta_data('_mwxs_xero_invoice_number');
			$order->update_meta_data('_mwxs_xero_checked_at',time());
			$order->save();
		}else{
			delete_post_meta($order_id,'_mwxs_xero_invoice_id');
			delete_post_meta($order_id,'_mwxs_xero_invoice_status');
			delete_post_meta($order_id,'_mwxs_xero_invoice_number');
			update_post_meta($order_id,'_mwxs_xero_checked_at',time());
		}

		return true;
	}

	# Returns the stored Xero Invoice UUID for an order ('' if not synced/unknown). Fast, no API call.
	public function get_order_xero_sync_state($order_id){
		$order_id = (int) $order_id;
		if($order_id < 1){
			return '';
		}

		if($this->is_hpos_enabled()){
			$order = wc_get_order($order_id);
			$invoice_id = $order ? $order->get_meta('_mwxs_xero_invoice_id',true) : '';
		}else{
			$invoice_id = get_post_meta($order_id,'_mwxs_xero_invoice_id',true);
		}

		return (!empty($invoice_id))?(string) $invoice_id:'';
	}

	# Returns the stored Xero Invoice Number for an order ('' if none). Fast, no API call.
	public function get_order_xero_invoice_number($order_id){
		$order_id = (int) $order_id;
		if($order_id < 1){
			return '';
		}

		if($this->is_hpos_enabled()){
			$order = wc_get_order($order_id);
			$number = $order ? $order->get_meta('_mwxs_xero_invoice_number',true) : '';
		}else{
			$number = get_post_meta($order_id,'_mwxs_xero_invoice_number',true);
		}

		return (!empty($number))?(string) $number:'';
	}

	# Whether we've already checked this order's Xero status (so "no invoice id" means Not Synced, not Unknown).
	public function order_xero_status_checked($order_id){
		$order_id = (int) $order_id;
		if($order_id < 1){
			return false;
		}

		if($this->is_hpos_enabled()){
			$order = wc_get_order($order_id);
			$checked = $order ? $order->get_meta('_mwxs_xero_checked_at',true) : '';
		}else{
			$checked = get_post_meta($order_id,'_mwxs_xero_checked_at',true);
		}

		return !empty($checked);
	}

	# Unix timestamp of the last status check for an order (0 if never). Used to skip recently-checked
	# orders in the reconcile cron and avoid repeat Xero query calls. Refs #83
	public function get_order_xero_checked_at($order_id){
		$order_id = (int) $order_id;
		if($order_id < 1){
			return 0;
		}

		if($this->is_hpos_enabled()){
			$order = wc_get_order($order_id);
			$checked = $order ? $order->get_meta('_mwxs_xero_checked_at',true) : '';
		}else{
			$checked = get_post_meta($order_id,'_mwxs_xero_checked_at',true);
		}

		return (int) $checked;
	}

	# Wipes all local state back to Unknown (used by the manual Refresh so the order is re-checked live).
	public function clear_order_xero_sync_state($order_id){
		$order_id = (int) $order_id;
		if($order_id < 1){
			return false;
		}

		if($this->is_hpos_enabled()){
			$order = wc_get_order($order_id);
			if(!$order){
				return false;
			}
			$order->delete_meta_data('_mwxs_xero_invoice_id');
			$order->delete_meta_data('_mwxs_xero_invoice_status');
			$order->delete_meta_data('_mwxs_xero_invoice_number');
			$order->delete_meta_data('_mwxs_xero_checked_at');
			$order->save();
		}else{
			delete_post_meta($order_id,'_mwxs_xero_invoice_id');
			delete_post_meta($order_id,'_mwxs_xero_invoice_status');
			delete_post_meta($order_id,'_mwxs_xero_invoice_number');
			delete_post_meta($order_id,'_mwxs_xero_checked_at');
		}

		return true;
	}

	# Xero order sync as
	public function get_xero_order_sync_as($order_id=0,$invoice_data=null){
		$xosa = 'Invoice';
		$s_order_sync_as = $this->get_option('mw_wc_xero_sync_order_sync_as');
		if(!empty($s_order_sync_as)){
			$osa_arr = $this->get_xosa_arr();
			if(in_array($s_order_sync_as,$osa_arr)){
				$xosa = $s_order_sync_as;
			}else{
				$order_id = (int) $order_id;
				$wc_order = wc_get_order( $order_id );
				if($s_order_sync_as == 'Per Role' || $s_order_sync_as == 'Per Gateway' && $order_id > 0){
					if(is_null($invoice_data)){
						$wc_cus_id = $this->is_hpos_enabled() && $wc_order ? (int) $wc_order->get_customer_id() : (int) get_post_meta($order_id,'_customer_user',true);
						$wc_user_role = ($wc_cus_id > 0)?$this->get_wc_user_role_by_id($wc_cus_id):'wc_guest_user';

						$_payment_method = $this->is_hpos_enabled() && $wc_order ? $wc_order->get_payment_method() : get_post_meta($order_id,'_payment_method',true);
						$_order_currency = $this->is_hpos_enabled() && $wc_order ? $wc_order->get_currency() : get_post_meta($order_id,'_order_currency',true);

						$invoice_data = array(
							'wc_user_role' => $wc_user_role,
							'_payment_method' => $_payment_method,
							'_order_currency' => $_order_currency,
						);
					}

					if(!is_array($invoice_data) || empty($invoice_data)){
						return $xosa;
					}

					if($s_order_sync_as == 'Per Role'){
						$wc_user_role = $this->get_array_isset($invoice_data,'wc_user_role','');
						
						if(!empty($wc_user_role)){
							$osa_pr_map_data = get_option('mw_wc_xero_sync_osa_pr_map_data');
							if(is_array($osa_pr_map_data) && !empty($osa_pr_map_data)){
								if(isset($osa_pr_map_data[$wc_user_role]) && !empty($osa_pr_map_data[$wc_user_role])){
									if(in_array($osa_pr_map_data[$wc_user_role],$osa_arr)){
										$xosa = $osa_pr_map_data[$wc_user_role];
									}
								}
							}
						}						
					}
					
					if($s_order_sync_as == 'Per Gateway'){
						$_payment_method = $this->get_array_isset($invoice_data,'_payment_method','');
						$_order_currency = $this->get_array_isset($invoice_data,'_order_currency','');
						if(!empty($_payment_method) && !empty($_order_currency)){
							$pm_map_data = $this->get_mapped_payment_method_data($_payment_method,$_order_currency);
							$pg_order_sync_as = $this->get_array_isset($pm_map_data,'order_sync_as','');
							if(in_array($pg_order_sync_as,$osa_arr)){
								$xosa = $pg_order_sync_as;
							}
						}
					}
				}
			}
		}

		return $xosa;
	}
	
	# Xero Data Checking
	#-> This function is for both wc products and variations
	public function if_xero_product_exists($product_data, $omc=false){
		if(is_array($product_data) && !empty($product_data)){
			$nr_chars = $this->x_nrc('product');
			global $wpdb;
			
			$wc_product_id = (int) $this->get_array_isset($product_data,'wc_product_id',0,false);
			
			$is_variation = $this->get_array_isset($product_data,'is_variation',false,false);
			$ItemID = '';
			
			$map_tbl = ($is_variation)?$this->gdtn('map_variations'):$this->gdtn('map_products');
			$w_p_f = ($is_variation)?'W_V_ID':'W_P_ID';
			
			// Security: Validate table name and use esc_sql for field names
			$validated_map_tbl = $this->get_validated_table_name_from_full($map_tbl);
			if(!$validated_map_tbl) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					wc_get_logger()->warning( 'MW Xero Sync Security: Invalid map table name', array( 'source' => 'myworks-sync-for-xero' ) );
				}
				return '';
			}
			$query = $wpdb->prepare("SELECT `X_P_ID` FROM `" . esc_sql($validated_map_tbl) . "` WHERE `" . esc_sql($w_p_f) . "` = %d AND `X_P_ID` !='' AND `" . esc_sql($w_p_f) . "` > 0 ",$wc_product_id);
			
			$query_product = $this->get_row($query);
			if(!empty($query_product)){
				$ItemID =  $query_product['X_P_ID'];
			}
			
			# Only map check
			if($omc){
				return $ItemID;
			}
			
			$pvnf = (isset($product_data['name_t']) && !empty($product_data['name_t']))?'name_t':'name';

			$name = $this->get_array_isset($product_data,$pvnf,'',true,50,false,$nr_chars);
			$sku = $this->get_array_isset($product_data,'_sku','',true,30);
			
			if(empty($ItemID) && !empty($sku)){
				$ItemID = $this->get_field_by_val($this->gdtn('products'),'ItemID','Code',$sku);
			}
			
			# Wc ID -> Xero Code
			if(empty($ItemID) && $wc_product_id > 0){
				$ItemID = $this->get_field_by_val($this->gdtn('products'),'ItemID','Code',$wc_product_id);
			}
			
			# Name
			/*
			if(empty($ItemID) && !empty($name)){				
				$ItemID = $this->get_field_by_val($this->gdtn('products'),'ItemID','Name',$name);
			}
			*/
			
			return $ItemID;
		}
		
		return false;
	}
	
	public function if_xero_customer_exists($customer_data,$omc=false,$cbn=false,$rt_check=true,$r_obj=false){
		if(is_array($customer_data) && !empty($customer_data)){
			$nr_chars = $this->x_nrc('customer');
			global $wpdb;
			
			$wc_cus_id = (int) $this->get_array_isset($customer_data,'wc_cus_id',0,false);
			$ContactID = '';
			$map_tbl = $this->gdtn('map_customers');
			$w_c_f = 'W_C_ID';

			// Security: Validate table name and use esc_sql for field names
			$validated_map_tbl = $this->get_validated_table_name_from_full($map_tbl);
			if(!$validated_map_tbl) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					wc_get_logger()->warning( 'MW Xero Sync Security: Invalid customer map table name', array( 'source' => 'myworks-sync-for-xero' ) );
				}
				return '';
			}
			$query = $wpdb->prepare("SELECT `X_C_ID` FROM `" . esc_sql($validated_map_tbl) . "` WHERE `" . esc_sql($w_c_f) . "` = %d AND `X_C_ID` !='' AND `" . esc_sql($w_c_f) . "` > 0 ",$wc_cus_id);
			
			$query_customer = $this->get_row($query);
			if(!empty($query_customer)){
				$ContactID =  $query_customer['X_C_ID'];
			}
			
			# Only map check
			if($omc){
				return $ContactID;
			}
			
			$email = $this->get_array_isset($customer_data,'email','',true,255,false,$nr_chars);
			$full_name = $this->get_array_isset($customer_data,'full_name','',true,255,false,$nr_chars);
			
			$name = $this->get_customer_formated_display_name_for_xs($customer_data,$full_name);
			
			if(empty($ContactID) && !empty($email)){
				$ContactID = $this->get_field_by_val($this->gdtn('customers'),'ContactID','EmailAddress',$email);
			}
			
			if(empty($ContactID) && $cbn && !empty($name)){
				$ContactID = $this->get_field_by_val($this->gdtn('customers'),'ContactID','Name',$name);
			}

			# Remote Check
			if(empty($ContactID) && $rt_check){
				$name_p = ($cbn)?$name:'';
				$ContactID = $this->get_xero_customer_by_email_or_name($email,$name_p);
			}
			
			return $ContactID;
			
		}
	}
	
	public function if_xero_guest_exists($customer_data,$cbn=false,$rt_check=true,$r_obj=false){
		if(is_array($customer_data) && !empty($customer_data)){
			$nr_chars = $this->x_nrc('customer');
			global $wpdb;

			$ContactID = '';

			$email = $this->get_array_isset($customer_data,'email','',true,255,false,$nr_chars);
			$full_name = $this->get_array_isset($customer_data,'full_name','',true,255,false,$nr_chars);

			$name = $this->get_customer_formated_display_name_for_xs($customer_data,$full_name);			
			if((!$cbn && empty($email)) || ($cbn && empty($name) && empty($email))){
				return $ContactID;
			}

			#-> DB Check If Needed

			# Remote Check
			if(empty($ContactID) && $rt_check){
				$name_p = ($cbn)?$name:'';
				$ContactID = $this->get_xero_customer_by_email_or_name($email,$name_p);
			}			

			return $ContactID;
		}
	}

	public function check_save_get_xero_customer_id($customer_data){
		if(is_array($customer_data) && !empty($customer_data)){
			# Check for the failure sentinel BEFORE casting. The (string) cast turns null into '',
			# which is exactly the value that means "not in Xero" and would send us straight to
			# X_Add_Customer() - so casting first silently reinstates the duplicate-contact bug this
			# guard exists to prevent. Refs #87
			$xero_contact_id = $this->if_xero_customer_exists($customer_data);
			if($xero_contact_id === null){
				return '';
			}

			$xero_contact_id = (string) $xero_contact_id;
			if(!empty($xero_contact_id)){
				return $xero_contact_id;
			}

			$xero_contact_id = $this->X_Add_Customer($customer_data);
			if($xero_contact_id && strlen($xero_contact_id) == '36'){
				return $xero_contact_id;
			}
		}

		return '';
	}

	public function check_save_get_xero_guest_id($customer_data){
		if(is_array($customer_data) && !empty($customer_data)){
			# Same null-before-cast ordering as check_save_get_xero_customer_id(). This path matters
			# more, not less: if_xero_guest_exists() has NO local cache, so it calls Xero on every
			# single guest order. Refs #87
			$xero_contact_id = $this->if_xero_guest_exists($customer_data);
			if($xero_contact_id === null){
				return '';
			}

			$xero_contact_id = (string) $xero_contact_id;
			if(!empty($xero_contact_id)){
				return $xero_contact_id;
			}

			$xero_contact_id = $this->X_Add_Guest($customer_data);
			if($xero_contact_id && strlen($xero_contact_id) == '36'){
				return $xero_contact_id;
			}
		}
	}

	# Get xero customer ID by email or name (if not found by email)
	public function get_xero_customer_by_email_or_name($email,$name=''){
		$ContactID = '';
		if($this->is_xero_connected() && !empty($email)){
			$if_modified_since = $this->get_x_f_ms_filter_datetime();	
			#IsSupplier==FALSE IsCustomer==TRUE
			$X_Whr = 'EmailAddress ==  "'.$email.'" AND ContactStatus=="ACTIVE"';

			# summaryOnly (8th arg) is FALSE and must stay so, even though only getContactId() is read
			# below and a full contact's addresses/phones/balances are pure egress here. Xero answers
			#   [400] The supplied filter is unavailable on this endpoint when using the summaryOnly flag
			# and this lookup is filter-based by definition (where=EmailAddress==...). #87 set it to
			# true and it shipped; see bugs-fixed.md 2026-08-17.
			# try/catch: reached from hook_order_add() via the queue consumer, which marks the row
			# run=1 only AFTER the hook returns — so an escape here wedges the queue permanently.
			try{
				$result = $this->X_API_I()->getContacts($this->XeroTenantId,$if_modified_since,$X_Whr,null,null,1,null,false);
			}catch (\Throwable $e) {
				$this->save_log(array(
					'type'    => 'Customer',
					'title'   => 'Contact Lookup Failed (by email)',
					# The email is deliberately NOT written here, and the exception message is redacted:
					# Pulse forwards log details off-site, and Xero's message embeds the request URL with
					# the address in its where clause. See redact_log_pii(). The order's own log rows
					# sitting either side of this one are enough to identify which customer it was.
					'details' => 'Could not look up the Xero contact by email address. '.get_class($e).': '.$this->redact_log_pii($e->getMessage()),
					'status'  => 0,
				));

				# null, NOT ''. '' is this function's "no matching contact" answer, and the create path
				# reads that as permission to call X_Add_Customer() - so returning '' turned a Xero
				# outage into a DUPLICATE CONTACT. null is falsey, so callers that only test truthiness
				# are unchanged; the create gates check for it explicitly. Refs #87
				return null;
			}

			if(!empty($result)){
				$contacts = $result->getContacts();				
				if(is_array($contacts) && !empty($contacts)){
					$ContactID = $contacts[0]->getContactId();
				}
			}

			if(empty($ContactID) && !empty($name)){
				$ContactID = $this->get_xero_customer_by_name($name);
			}
		}

		return $ContactID;
	}
	
	# Get xero customer ID by name
	public function get_xero_customer_by_name($name,$active=true){
		$ContactID = '';
		if($this->is_xero_connected() && !empty($name)){
			$if_modified_since = $this->get_x_f_ms_filter_datetime();
			if($active){
				$X_Whr = 'Name ==  "'.$name.'" AND ContactStatus=="ACTIVE"';
			}else{
				# For append ID check
				$X_Whr = 'Name ==  "'.$name.'"';
			}

			# summaryOnly FALSE — same Xero 400 as the by-email lookup above; don't re-enable it.
			# try/catch for the same queue-wedging reason as get_xero_customer_by_email_or_name().
			try{
				$result = $this->X_API_I()->getContacts($this->XeroTenantId,$if_modified_since,$X_Whr,null,null,1,null,false);
			}catch (\Throwable $e) {
				$this->save_log(array(
					'type'    => 'Customer',
					'title'   => 'Contact Lookup Failed (by name)',
					# Customer name omitted and message redacted, same reasoning as the by-email lookup.
					'details' => 'Could not look up the Xero contact by name, so the customer was not pushed. '.get_class($e).': '.$this->redact_log_pii($e->getMessage()),
					'status'  => 0,
				));

				# null, not '' — same reasoning as the by-email lookup. This one additionally protects the
				# duplicate-name suffix logic, which reads '' as "name is free" and would skip the -{id}
				# suffix during an outage, producing a second contact with an identical name. Refs #87
				return null;
			}
			if(!empty($result)){
				$contacts = $result->getContacts();				
				if(is_array($contacts) && !empty($contacts)){
					$ContactID = $contacts[0]->getContactId();
				}
			}
		}

		return $ContactID;
	}
	
	# Get xero customer ID when syncing order
	public function get_xero_customer_for_order_sync($invoice_data){
		$X_ContactID = '';
		#return 'b1946ff6-e266-4be1-a061-592041f13fc6'; # Test
		if(is_array($invoice_data) && !empty($invoice_data)){
			$wc_inv_id = (int) $this->get_array_isset($invoice_data,'wc_inv_id',0,false);
			$wc_cus_id = (int) $this->get_array_isset($invoice_data,'wc_cus_id',0,false);
			$wc_user_role = $this->get_array_isset($invoice_data,'wc_user_role','');

			if(empty($X_ContactID)){
				if($this->option_checked('mw_wc_xero_sync_s_all_orders_to_one_xero_customer')){
					if(!empty($wc_user_role)){
						$aotc_rcm_data = get_option('mw_wc_xero_sync_aotc_rcm_data');
						if(isset($aotc_rcm_data[$wc_user_role]) && !empty($aotc_rcm_data[$wc_user_role])){
							if($aotc_rcm_data[$wc_user_role] != 'Individual'){
								$X_ContactID = $aotc_rcm_data[$wc_user_role];
							}
						}
					}					
				}else{
					if($wc_cus_id > 0){
						$customer_data = $this->get_wc_customer_info($wc_cus_id);
						$customer_data['order_id'] = $wc_inv_id;
						$X_ContactID = $this->if_xero_customer_exists($customer_data);
					}else{
						$customer_data = $this->get_wc_customer_info_from_order($wc_inv_id);
						$X_ContactID = $this->if_xero_guest_exists($customer_data);
					}			
				}
			}
		}

		return $X_ContactID;
	}

	public function if_xero_order_exists($xosa,$invoice_data){
		if(!empty($xosa) && is_array($invoice_data) && !empty($invoice_data)){
			$osa_arr = $this->get_xosa_arr();
			if(in_array($xosa,$osa_arr)){
				if($xosa == 'Invoice'){
					return $this->if_xero_invoice_exists($invoice_data);
				}

				if($xosa == 'Quote'){
					return $this->if_xero_quote_exists($invoice_data);
				}
			}
		}
		return false;
	}

	public function if_xero_invoice_exists($invoice_data){
		return $this->check_xero_invoice_get_obj($invoice_data,true);
	}
	
	public function check_xero_invoice_get_obj($invoice_data,$r_only_id=false){
		if(is_array($invoice_data) && !empty($invoice_data)){
			$wc_inv_id = (int) $this->get_array_isset($invoice_data,'wc_inv_id',0,false);
			if($wc_inv_id > 0){
				if($this->is_xero_connected()){
					$wc_inv_num = $this->get_array_isset($invoice_data,'wc_inv_num','',true,255);
					# Next Xero Order Number
					if($this->use_next_xero_order_number()){
						$n_x_o_n = $this->get_next_xero_order_number($invoice_data['wc_inv_id']);
						if(empty($n_x_o_n)){
							#return false;
						}else{
							$wc_inv_num = $n_x_o_n;
						}					
					}
					
					$InvoiceNumber = (!empty($wc_inv_num))?$wc_inv_num:$wc_inv_id;
					$InvoiceNumber = $this->apply_order_number_prefix($InvoiceNumber);
					# Reference
					# ACCREC -> sales invoice
					$if_modified_since = $this->get_x_f_ms_filter_datetime();
					$where = 'InvoiceNumber=="'.$InvoiceNumber.'" AND Type=="ACCREC" AND Status != "DELETED"';
					# summaryOnly is NOT usable here, and this must not be "optimised" back.
					#
					# Xero rejects it outright when the request also carries a filter:
					#   [400] The supplied filter is unavailable on this endpoint when using the
					#         summaryOnly flag
					# and this lookup is filter-based by definition (where=InvoiceNumber==...).
					#
					# #87 round 2 set this to true for the $r_only_id path and it shipped. The result was
					# worse than a failed optimisation: this call IS the duplicate-invoice guard, so the
					# 400 propagated out of an unguarded call, killed the queue cron mid-item, and left
					# the row at run=0 to be retried every 5 minutes forever with every row behind it
					# starved. See bugs-fixed.md 2026-08-17. Note the trap in the reasoning that let it
					# through: summaryOnly=false was ALREADY being sent on every request, so the request
					# shape looked unchanged - but false is accepted alongside a filter and true is not.
					$summary_only = false;

					# This had NO exception boundary. It is called from hook_order_add(), which the queue
					# consumer invokes BEFORE it marks the row run=1 — so anything thrown here escapes,
					# kills the whole cron request, leaves the row at run=0, and the queue retries the
					# same failing item forever while every row behind it starves. Return false (treated
					# as "no existing invoice") and log, rather than taking the queue down.
					try{
						$result = $this->X_API_I()->getInvoices($this->XeroTenantId,$if_modified_since,$where,null,null,null,null,null,1,null,null,null,$summary_only);
					}catch (\Throwable $e) {
						$this->save_log(array(
							'type'    => 'Order',
							'title'   => 'Invoice Lookup Failed #'.$wc_inv_id,
							'details' => 'Could not check whether this order already exists in Xero, so it was not pushed - pushing without that check could create a duplicate invoice. It will sync on the next attempt. '.get_class($e).': '.$this->redact_log_pii($e->getMessage()),
							'status'  => 0,
							'wc_id'   => $wc_inv_id,
						));

						# null, NOT false. false is this function's "no such invoice in Xero" answer, and
						# X_Add_Update_Invoice() reads that as permission to create — so returning false
						# here turned a Xero outage into a DUPLICATE INVOICE in the merchant's books.
						# null means "could not ask".
						#
						# An earlier version of this comment claimed "callers that only test truthiness are
						# unaffected". That was WRONG and is worth keeping as a correction, because the
						# reasoning is seductive: null being falsey does mean such a caller takes the same
						# BRANCH as before, but it says nothing about whether that branch is still correct.
						# admin/ajax-actions.php took the falsey branch and called
						# set_order_xero_not_synced() — persisting "not synced" for an order that may well
						# be synced, just because Xero was unreachable. Any consumer that WRITES a
						# conclusion from a falsey result needs the null check too, not only the ones that
						# create records. Refs #87
						return null;
					}

					$invoices = (!empty($result))?$result->getInvoices():null;
					if(is_array($invoices) && !empty($invoices)){
						if($r_only_id){
							return $invoices[0]->getInvoiceID();
						}

						return $invoices[0];
					}

					# The number lookup found nothing. Before treating the order as new, try the invoice ID
					# the order already carries locally (written by set_order_xero_sync_state() the moment a
					# create succeeds). The number can differ from what was just searched - custom order
					# numbers, a prefix change, Xero-assigned numbering - and a filtered list can miss a
					# record created seconds ago; the ID cannot. Costs nothing on a genuinely new order,
					# which has no local ID. Best-effort under HPOS: wc_get_order() may hand back an object
					# loaded earlier in this request, before another process wrote the meta. Refs #146
					$local_invoice_id = $this->get_order_xero_sync_state($wc_inv_id);
					if($local_invoice_id !== ''){
						$by_id = $this->get_xero_invoice_by_id($local_invoice_id, $wc_inv_id);
						if($by_id === null){
							return null;
						}

						if($by_id){
							return ($r_only_id)?$by_id->getInvoiceID():$by_id;
						}
					}
				}
			}
		}

		return false;
	}

	/**
	 * Fetch one sales invoice by its Xero ID.
	 *
	 * Same three-way contract as check_xero_invoice_get_obj(): the invoice object when it exists,
	 * false when Xero says it does not (404, DELETED, or not an ACCREC invoice), null when the question
	 * could not be asked. Callers that create on false must refuse on null. Refs #146
	 */
	private function get_xero_invoice_by_id($invoice_id, $wc_inv_id = 0){
		$invoice_id = trim((string) $invoice_id);
		$wc_inv_id  = (int) $wc_inv_id;
		if(strlen($invoice_id) != 36 || !$this->is_xero_connected()){
			return false;
		}

		try{
			$result = $this->X_API_I()->getInvoice($this->XeroTenantId, $invoice_id);
		}catch (\Throwable $e) {
			if($e instanceof \XeroAPI\XeroPHP\ApiException && (int) $e->getCode() === 404){
				return false;
			}

			$this->save_log(array(
				'type'    => 'Order',
				'title'   => 'Invoice Lookup Failed #'.$wc_inv_id,
				'details' => 'Could not fetch the Xero invoice this order is linked to, so the order was not pushed - pushing without that check could create a duplicate invoice. It will sync on the next attempt. '.get_class($e).': '.$this->redact_log_pii($e->getMessage()),
				'status'  => 0,
				'wc_id'   => $wc_inv_id,
				'xero_id' => $invoice_id,
			));

			return null;
		}

		$invoices = (!empty($result))?$result->getInvoices():null;
		if(is_array($invoices) && !empty($invoices)){
			$invoice = $invoices[0];
			if((string) $invoice->getType() === 'ACCREC' && (string) $invoice->getStatus() !== 'DELETED'){
				return $invoice;
			}
		}

		return false;
	}

	public function if_xero_quote_exists($invoice_data){
		
	}

	public function check_xero_quote_get_obj($invoice_data,$r_only_id=false){

	}

	public function if_xero_payment_exists($invoice_data,$xero_invoice_object){
		return $this->check_xero_payment_get_obj($invoice_data,$xero_invoice_object,true);
	}

	public function check_xero_payment_get_obj($invoice_data,$xero_invoice_object,$r_only_id=false){
		if(is_array($invoice_data) && !empty($invoice_data) && is_object($xero_invoice_object) && !empty($xero_invoice_object)){
			$wc_inv_id = (int) $this->get_array_isset($invoice_data,'wc_inv_id',0,false);
			if($wc_inv_id > 0){
				if($this->is_xero_connected()){
					$xero_invoice_id = $xero_invoice_object->getInvoiceID();
					if(!empty($xero_invoice_id)){
						$if_modified_since = $this->get_x_f_ms_filter_datetime();
						$where = 'Invoice.InvoiceID==GUID("'.$xero_invoice_id.'")  AND Status != "DELETED"';
						$result = $this->X_API_I()->getPayments($this->XeroTenantId,$if_modified_since,$where,null,1);
						$payments = $result->getPayments();
						if(is_array($payments) && !empty($payments)){
							if($r_only_id){
								return $payments[0]->getPaymentId();
							}
	
							return $payments[0];
						}
					}
				}
			}
		}

		return false;
	}

	public function if_xero_refund_exists($refund_id, $invoice_data){
		return $this->check_xero_refund_get_obj($refund_id,$invoice_data,true);
	}

	public function check_xero_refund_get_obj($refund_id,$invoice_data,$r_only_id=false){
		if($refund_id > 0 && is_array($invoice_data) && !empty($invoice_data)){
			$wc_inv_id = (int) $this->get_array_isset($invoice_data,'wc_inv_id',0,false);
			if($wc_inv_id > 0){
				if($this->is_xero_connected()){
					$wc_inv_num = $this->get_array_isset($invoice_data,'wc_inv_num','',true,255);
					/*
					# Next Xero Order Number
					if($this->use_next_xero_order_number()){
						$n_x_o_n = $this->get_next_xero_order_number($invoice_data['wc_inv_id']);
						if(empty($n_x_o_n)){
							#return false;
						}else{
							$wc_inv_num = $n_x_o_n;
						}					
					}
					*/

					$InvoiceNumber = (!empty($wc_inv_num))?$wc_inv_num:$wc_inv_id;
					$InvoiceNumber = $this->apply_order_number_prefix($InvoiceNumber);
					$CreditNoteNumber = $InvoiceNumber.'-'.$refund_id;
					$if_modified_since = $this->get_x_f_ms_filter_datetime();
					$where = 'CreditNoteNumber=="'.$CreditNoteNumber.'" AND Type=="ACCRECCREDIT" AND Status != "DELETED"';
					$result = $this->X_API_I()->getCreditNotes($this->XeroTenantId,$if_modified_since,$where,null,null,null,null);
					$credit_notes = $result->getCreditNotes();
					if(is_array($credit_notes) && !empty($credit_notes)){
						if($r_only_id){
							return $credit_notes[0]->getCreditNoteID();
						}

						return $credit_notes[0];
					}
				}
			}
		}
		return false;
	}
	
	# Xero Data Functions
	/**
	 * Delete replica rows whose Xero key wasn't seen in a sweep that ran to completion.
	 *
	 * Replaces the old truncate-then-reimport: that emptied the table on page 1, so an API error on
	 * page 3 left the replica half-populated while the mapping screens carried on reading it. Rows
	 * are upserted instead and the stale ones removed here, which means the caller MUST only call
	 * this after a complete sweep - pruning against a partial list deletes live rows.
	 *
	 * Diffs in PHP rather than issuing `NOT IN (<every seen id>)`: a 10k-contact book would mean a
	 * 10k-placeholder prepare, while the stale set is almost always tiny or empty.
	 *
	 * @param string $table_suffix Replica table suffix ('customers' or 'products').
	 * @param string $key_column   Natural key column ('ContactID' or 'ItemID').
	 * @param array  $seen_keys    Keys Xero returned during the sweep.
	 * @return int Rows deleted.
	 */
	private function prune_replica_rows($table_suffix, $key_column, $seen_keys){
		# An empty seen-list is refused rather than treated as "Xero has nothing". A fluke empty
		# response would otherwise wipe the whole replica, and every mapping lookup reads it. The
		# cost is that an org which genuinely deleted its last contact/item keeps stale rows until
		# something non-empty comes back - the safe direction of that trade. Refs #87
		if(!is_array($seen_keys) || empty($seen_keys)){
			return 0;
		}

		# Column is a literal from this class today, but whitelist it anyway so it can never become
		# request-influenced by a later caller. See docs/rules/security.md.
		if(!in_array($key_column, array('ContactID','ItemID'), true)){
			return 0;
		}

		$validated_tbl = $this->get_validated_table_name($table_suffix);
		if(!$validated_tbl){
			return 0;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and column validated via whitelist and escaped
		$existing = $wpdb->get_col("SELECT `" . esc_sql($key_column) . "` FROM `" . esc_sql($validated_tbl) . "`");
		if(!is_array($existing) || empty($existing)){
			return 0;
		}

		$stale = array_values(array_diff($existing, $seen_keys));
		if(empty($stale)){
			return 0;
		}

		# One $wpdb->delete() per stale key rather than a chunked `IN (...)`. The IN version had to
		# build its placeholder list into the SQL string, which trips WordPress.DB.PreparedSQL.NotPrepared
		# — and per docs/rules/testing.md that sniff is an ERROR in the QIT gate, and is NOT silenced
		# by the InterpolatedNotPrepared suppression used elsewhere in this file. delete() takes the
		# table and a where array and does its own preparation, so there is no SQL string to verify.
		# The row count is what makes this cheap: $stale is the difference between Xero and the
		# replica, so on the overwhelmingly common rescan it is empty or a handful. Refs #87
		$deleted = 0;
		foreach($stale as $stale_key){
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table validated via whitelist; delete() prepares the value
			$r = $wpdb->delete($validated_tbl, array($key_column => $stale_key), array('%s'));
			if($r){
				$deleted += (int) $r;
			}
		}

		return $deleted;
	}

	public function xero_refresh_customers(){
		$tci = 0;
		if(!$this->is_xero_connected()){
			return $tci;
		}

		$if_modified_since = $this->get_x_f_ms_filter_datetime();
		$seen = array();
		$complete = false;

		for($page = 1; $page <= self::CONTACT_SWEEP_PAGE_CAP; $page++){
			try{
				$result = $this->X_API_I()->getContacts(
					$this->get_xero_tenant_id(),
					$if_modified_since,
					'ContactStatus=="ACTIVE"',
					null,
					null,
					$page,
					null,
					false,
					null,
					self::CONTACT_SWEEP_PAGE_SIZE
				);
			}catch (\XeroAPI\XeroPHP\ApiException $e) {
				# This call had no try/catch at all, so an ApiException escaped through
				# one_time_setup_afxc() and fataled the connect flow. Bail WITHOUT pruning: rows
				# already upserted stay, and a partial list must never drive deletions. Refs #87
				$this->save_log(array('type'=>'Customer','title'=>'Refresh Xero Customers Error','details'=>'Xero API error on page '.$page.': '.$this->redact_log_pii($e->getMessage()),'status'=>0));

				return $tci;
			}catch (\Throwable $e) {
				# ApiException alone is not enough. The vendored SDK also throws TypeError /
				# InvalidArgumentException on payloads it does not expect (see rules/xero-sync.md on the
				# enum-leniency patch), and those would escape this sweep into the connect flow. Same
				# bail: return WITHOUT pruning, so a partial list can never drive deletions. Refs #87
				$this->save_log(array('type'=>'Customer','title'=>'Refresh Xero Customers Error','details'=>'Unexpected error on page '.$page.': '.get_class($e).': '.$this->redact_log_pii($e->getMessage()),'status'=>0));

				return $tci;
			}

			$contacts = (!empty($result))?$result->getContacts():null;
			if(!is_array($contacts) || empty($contacts)){
				# Walked off the end of the list.
				$complete = true;
				break;
			}

			foreach($contacts as $Contact){
				$contact_id = (string) $Contact->getContactID();
				if($contact_id === ''){
					continue;
				}

				# Recorded as seen before the write is attempted, and regardless of its outcome: this
				# list answers "does Xero still have this contact", which is what the prune keys off.
				# Gating it on a successful write would prune a live contact whose row failed to save.
				$seen[] = $contact_id;

				if($this->save_xero_customer_into_local_dbt($Contact)){
					$tci++;
				}
			}

			# A short page is the last page. Compared against the size we actually requested - this
			# was hardcoded to 100, matching Xero's default, so raising the page size without
			# touching it would have ended the sweep after one page and silently capped the replica
			# at 1000 contacts with no error anywhere. Refs #87
			if(count($contacts) < self::CONTACT_SWEEP_PAGE_SIZE){
				$complete = true;
				break;
			}
		}

		# Only a sweep that finished may prune. Hitting the page cap leaves $complete false on
		# purpose, so an unexpectedly huge contact book degrades to a stale replica rather than a
		# truncated one. Refs #87
		if($complete){
			$this->prune_replica_rows('customers','ContactID',$seen);
		}

		return $tci;
	}

	public function xero_refresh_products(){
		$tpi = 0;
		if(!$this->is_xero_connected()){
			return $tpi;
		}

		# Through the shared guarded helper rather than a bare getItems(): that call had no
		# try/catch either, so an ApiException fataled the connect flow via one_time_setup_afxc().
		# Returns null on failure, which must not prune. Refs #87
		$items = $this->x_get_items_since(null);
		if(!is_array($items)){
			return $tpi;
		}

		$seen = array();
		foreach($items as $Item){
			$item_id = (string) $Item->getItemID();
			if($item_id === ''){
				continue;
			}

			$seen[] = $item_id;

			if($this->save_xero_product_into_local_dbt($Item)){
				$tpi++;
			}
		}

		# The Items endpoint is unpaged, so a successful call IS the complete list - there is no
		# partial-sweep case to guard here the way the paged contact sweep needs. Refs #87
		$this->prune_replica_rows('products','ItemID',$seen);

		return $tpi;
	}

	/**
	 * Read a cached Xero reference list (WP option). Returns null when a
	 * realtime refresh is requested, the cache has aged out, or no usable cache
	 * exists, signalling the caller to fetch the list live from Xero.
	 */
	private function get_cached_kva($option_key, $realtime, &$refresh_lock_handle = null){
		# Only the stale-and-won-the-lock path below acquires the refresh lock; every other exit
		# returns without one. Callers release on their failure paths, so they need to know which
		# case they are in - releasing a lock this request never took frees another request's
		# refresh and lets a second getBrandingThemes() start, the stampede the lock prevents.
		# The handle is opaque and empty when unowned; hand it straight back to
		# release_kva_refresh_lock(), which no-ops on an empty one. Refs #57, raised on PR #143
		$refresh_lock_handle = '';

		if($realtime){
			return null;
		}

		$cached = $this->get_option($option_key, array());
		if(!is_array($cached) || empty($cached)){
			# An empty list is indistinguishable from "never cached" here, because set_cached_kva()
			# refuses to store one. So for an org that genuinely has no accounts / tax rates /
			# tracking categories, EVERY read fell through to a live Xero call — a steady leak of
			# exactly the kind this cache exists to stop, and the same failure the Shopify app hit
			# from the other direction (myworks-shopify-xero f38aac6, a watermark that never
			# persisted). The empty result is now remembered separately for a shorter window than
			# the main TTL, since "still empty" is cheap to be wrong about. Refs #87
			# ($realtime already returned above, so this only ever serves non-forced reads.)
			if($this->kva_empty_flag_set($option_key)){
				return array();
			}

			return null;
		}

		# These lists had no expiry and were only ever refreshed by the manual "Rescan Xero Data"
		# action, so a bank account or tax rate added in Xero never showed up in the mapping and
		# settings dropdowns — and mapping a gateway to a since-archived account produced sync
		# failures a merchant had no way to diagnose. Age them out so they self-heal daily.
		$cached_at = $this->get_kva_cached_at($option_key);

		# A missing timestamp means the entry predates this TTL, so its real age is unknown and could
		# be months. Treat that as a miss and refetch once, rather than starting the clock now and
		# serving a potentially very stale list for another full day.
		$is_stale = ($cached_at < 1) || ((time() - $cached_at) > self::KVA_CACHE_TTL_SECONDS);
		if(!$is_stale){
			return $cached;
		}

		# Only one request should refetch. Without this, every concurrent request that finds the entry
		# expired calls Xero simultaneously — a stampede, and the opposite of the egress reduction this
		# cache exists for. Losers serve the stale list rather than piling on.
		$refresh_lock_handle = $this->acquire_kva_refresh_lock($option_key);
		if($refresh_lock_handle === ''){
			return $cached;
		}

		return null;
	}

	/**
	 * Suffix used to build the per-list metadata/lock option names.
	 *
	 * The list keys are legacy `mw_wc_xero_app_data_*` names, so new options are namespaced under
	 * the required `mw_wc_xero_sync_` prefix instead of being derived from them directly
	 * (see docs/rules/conventions.md).
	 */
	private function kva_key_suffix($option_key){
		# Two prefixes on purpose: the original four lists use the legacy `mw_wc_xero_app_data_*`
		# names already stored on live sites, while lists added since are correctly prefixed
		# `mw_wc_xero_sync_app_data_*`. Strip the longer one first — otherwise the shorter pattern
		# never matches it and the suffix keeps a prefix, producing a needlessly long meta/lock
		# option name. Refs #87
		return str_replace(array('mw_wc_xero_sync_app_data_', 'mw_wc_xero_app_data_'), '', $option_key);
	}

	/**
	 * Read/write the cache timestamp for a reference list.
	 *
	 * One option per list, deliberately. A single shared array would be read-modify-written by each
	 * refresh, so two lists refreshing concurrently would drop each other's timestamp and force an
	 * unnecessary Xero refetch on the next read.
	 */
	private function get_kva_cached_at($option_key){
		return (int) get_option(self::KVA_CACHE_META_PREFIX.$this->kva_key_suffix($option_key), 0);
	}

	private function set_kva_cached_at($option_key, $timestamp){
		$this->update_option(self::KVA_CACHE_META_PREFIX.$this->kva_key_suffix($option_key), (int) $timestamp);
	}

	/**
	 * Try to become the single request that refreshes this list.
	 *
	 * Thin wrapper: the primitive is acquire_option_lock(), shared with the per-order push lock so
	 * both have exactly one implementation to get right. Refs #146
	 *
	 * @return string The lock handle when this request owns the refresh, '' when it does not.
	 *                Treat it as opaque; pass it back to release_kva_refresh_lock().
	 */
	private function acquire_kva_refresh_lock($option_key){
		return $this->acquire_option_lock(self::KVA_REFRESH_LOCK_PREFIX.$this->kva_key_suffix($option_key), self::KVA_REFRESH_LOCK_SECONDS);
	}

	/**
	 * Acquire a named lock stored as a non-autoloaded option row, atomically.
	 *
	 * The insert is a raw `INSERT IGNORE` against the options table, NOT add_option(). This helper's
	 * earlier version described add_option() as "an INSERT that fails when the row already exists, so
	 * it is an atomic test-and-set". It is not: add_option() does a get_option() pre-check and then an
	 * `INSERT ... ON DUPLICATE KEY UPDATE`, so two requests that both pass the pre-check both report
	 * success, and the second silently overwrites the first's lock. The window is sub-millisecond,
	 * which is why the reference-list lock never visibly failed - but a lock that is only usually
	 * atomic is the wrong foundation for a guard on accounting data. `INSERT IGNORE` is settled by the
	 * unique index on option_name: exactly one request inserts, every other one gets 0 rows.
	 *
	 * The stored value is `<acquired_at>|<token>`, not a bare timestamp. The token is what makes the
	 * lock safe to release: a request that never took the lock, or whose lock was since taken over,
	 * holds a handle that no longer matches the row and so cannot delete somebody else's lock.
	 *
	 * @param string $lock_key      Full option name, already prefixed `mw_wc_xero_sync_`.
	 * @param int    $stale_seconds After this long a lock counts as abandoned and may be taken over.
	 * @return string The lock handle when this request owns the lock, '' when it does not.
	 */
	private function acquire_option_lock($lock_key, $stale_seconds){
		global $wpdb;

		$handle = time().'|'.uniqid('', true);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the Options API cannot express an atomic insert-if-absent
		$inserted = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'no')", $lock_key, $handle));
		if($inserted === 1){
			$this->forget_option_lock_cache($lock_key);
			return $handle;
		}

		# Take over a lock left behind by a holder that died mid-flight, so whatever it guards can't get
		# stuck never running again.
		#
		# The read below and the write that follows are two statements, so the expiry check alone is a
		# check-then-set: two requests can both see the same expired lock, both overwrite it and both
		# proceed - the stampede this lock exists to stop. The takeover is therefore a compare-and-swap
		# against the exact value that was read, which the DB settles atomically; the loser sees 0 rows
		# changed. Refs #57, raised on PR #143
		#
		# Read the row directly rather than via get_option(): the insert above bypassed the Options API,
		# so a persistent object cache may still hold a stale "no such option" for this key.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the lock value must be the live row, not a cached copy
		$held = (string) $wpdb->get_var($wpdb->prepare("SELECT `option_value` FROM `{$wpdb->options}` WHERE `option_name` = %s LIMIT 1", $lock_key));
		if($held === ''){
			# The row vanished between the failed insert and this read (a release landing in the gap).
			# Retry the atomic insert rather than falling through to a CAS against nothing.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see above
			$inserted = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'no')", $lock_key, $handle));
			if($inserted === 1){
				$this->forget_option_lock_cache($lock_key);
				return $handle;
			}

			return '';
		}

		$parts      = explode('|', $held, 2);
		$held_since = (int) $parts[0];
		if($held_since > 0 && (time() - $held_since) > (int) $stale_seconds){
			if($this->cas_option_lock($lock_key, $held, $handle)){
				return $handle;
			}
		}

		return '';
	}

	/**
	 * Swap a lock's value only if it still holds $expected, atomically.
	 *
	 * Goes through $wpdb rather than update_option() because the point is the WHERE clause: the UPDATE
	 * matches on the current value, so concurrent takeovers of the same expired lock resolve to one
	 * winner in the DB instead of both overwriting. update_option() is an unconditional write and
	 * cannot express that. The option cache is dropped by hand since this bypasses the Options API;
	 * the row is non-autoloaded, so there is no alloptions bucket to worry about.
	 *
	 * @return bool True when this request won the swap.
	 */
	private function cas_option_lock($lock_key, $expected, $handle){
		global $wpdb;

		$swapped = $wpdb->update(
			$wpdb->options,
			array('option_value' => $handle),
			array('option_name' => $lock_key, 'option_value' => $expected)
		);

		# false is a DB error, 0 means another request swapped first. Only 1 is a win. The handle
		# always differs from $expected (it carries a fresh uniqid), so a matched row always reports
		# as changed - no false negative from MySQL's "same value, 0 affected rows".
		if($swapped === 1){
			$this->forget_option_lock_cache($lock_key);
			return true;
		}

		return false;
	}

	/**
	 * Release a refresh lock, but only the one this request is actually holding.
	 *
	 * @param string $handle The value returned by acquire_kva_refresh_lock(). An empty handle means
	 *                       this request never took the lock, which is the common case - every
	 *                       get_cached_kva() exit but one returns without acquiring - so it is a
	 *                       no-op rather than an error.
	 * @return bool True when a lock was actually released.
	 */
	private function release_kva_refresh_lock($option_key, $handle = ''){
		return $this->release_option_lock(self::KVA_REFRESH_LOCK_PREFIX.$this->kva_key_suffix($option_key), $handle);
	}

	/**
	 * Release a lock, but only the one this request is actually holding.
	 *
	 * @return bool True when a lock was actually released.
	 */
	private function release_option_lock($lock_key, $handle = ''){
		if(!is_string($handle) || $handle === ''){
			return false;
		}

		global $wpdb;

		# Conditional delete for the same reason the takeover is a CAS: this request may have been
		# timed out and its lock handed to another holder, and an unconditional delete_option() would
		# then free a lock it no longer owns.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- conditional delete on the handle; delete_option() cannot express the WHERE
		$deleted = $wpdb->delete($wpdb->options, array('option_name' => $lock_key, 'option_value' => $handle));

		if($deleted){
			$this->forget_option_lock_cache($lock_key);
			return true;
		}

		return false;
	}

	/**
	 * Drop the Options API's view of a lock row after touching it directly, so a later get_option()
	 * on the same key sees the DB rather than a cached stale value or a cached "no such option".
	 * The row is never autoloaded, so there is no alloptions bucket to invalidate.
	 */
	private function forget_option_lock_cache($lock_key){
		wp_cache_delete($lock_key, 'options');

		$notoptions = wp_cache_get('notoptions', 'options');
		if(is_array($notoptions) && isset($notoptions[$lock_key])){
			unset($notoptions[$lock_key]);
			wp_cache_set('notoptions', $notoptions, 'options');
		}
	}

	/**
	 * Per-order push lock, shared by every path that can create an invoice for an order: the queue
	 * consumer and the manual "Push to Xero" (Push > Orders, and the button on the order screen).
	 * The queue-row claim in mwxs_process_queue() stops two consumers taking the same row; this stops
	 * two different callers pushing the same order. Refs #146
	 *
	 * @return string Handle to pass back to release_order_push_lock(); '' when another push of this
	 *                order is already in progress (or the id is invalid).
	 */
	public function acquire_order_push_lock($order_id){
		$order_id = (int) $order_id;
		if($order_id < 1){
			return '';
		}

		return $this->acquire_option_lock(self::ORDER_PUSH_LOCK_PREFIX.$order_id, self::ORDER_PUSH_LOCK_SECONDS);
	}

	/**
	 * Release a per-order push lock taken by acquire_order_push_lock().
	 *
	 * @param  int    $order_id Order the lock was taken for.
	 * @param  string $handle   Handle returned by acquire_order_push_lock(); a mismatch releases nothing.
	 * @return bool True when a lock was actually released.
	 */
	public function release_order_push_lock($order_id, $handle = ''){
		$order_id = (int) $order_id;
		if($order_id < 1){
			return false;
		}

		return $this->release_option_lock(self::ORDER_PUSH_LOCK_PREFIX.$order_id, $handle);
	}

	/**
	 * Persist a freshly fetched Xero reference list so later admin renders read
	 * from the DB instead of querying Xero (reduces API egress). Returns the
	 * list unchanged for convenient use in a return statement.
	 *
	 * @param bool   $fetch_succeeded True when the Xero call actually returned a list. Only the caller
	 *                                can tell an empty result apart from a failed one, and the two must
	 *                                not be cached the same way. Refs #87
	 * @param string $lock_handle    The handle get_cached_kva() gave this request, so the release below
	 *                                frees only a lock this request actually holds. Refs #57, PR #143
	 */
	private function set_cached_kva($option_key, $list, $fetch_succeeded = false, $lock_handle = ''){
		if(is_array($list) && !empty($list)){
			$this->update_option($option_key, $list);
			$this->set_kva_cached_at($option_key, time());
			$this->clear_kva_empty_flag($option_key);
		}elseif($fetch_succeeded){
			# The stored list has to GO, not just be flagged. get_cached_kva() only consults the empty
			# flag when the option itself reads empty, so leaving a previously-cached non-empty list in
			# place would serve reference data Xero no longer has (a deleted tracking category still
			# offered in a mapping dropdown) AND keep refetching, since nothing would ever refresh the
			# stale timestamp. This is the list going from non-empty to empty. Refs #87
			delete_option($option_key);
			$this->set_kva_empty_flag($option_key);
		}
		# Release regardless of outcome: holding the lock after a failed fetch would block the next
		# request from retrying until the takeover window elapsed. No-ops when this request never took
		# the lock - a $realtime refresh, or a cold cache, both of which reach here without one.
		$this->release_kva_refresh_lock($option_key, $lock_handle);
		return $list;
	}

	/**
	 * Short-lived "this list came back empty" marker.
	 *
	 * Deliberately a transient and NOT the option cache: an empty list is a legitimate state (an org
	 * with no tracking categories) but also what a failed or partial fetch looks like, so it gets a
	 * one-hour memory rather than the 24h TTL. Wrong-and-empty self-corrects within the hour;
	 * wrong-and-cached-for-a-day would not. Refs #87
	 */
	private function kva_empty_flag_key($option_key){
		return 'mw_wc_xero_sync_kva_empty_'.$this->kva_key_suffix($option_key);
	}

	private function kva_empty_flag_set($option_key){
		return (bool) get_transient($this->kva_empty_flag_key($option_key));
	}

	/** Set only via set_cached_kva(), which is where "the fetch succeeded" is known. */
	private function set_kva_empty_flag($option_key){
		set_transient($this->kva_empty_flag_key($option_key), 1, HOUR_IN_SECONDS);
	}

	private function clear_kva_empty_flag($option_key){
		delete_transient($this->kva_empty_flag_key($option_key));
	}

	public function xero_get_accounts_kva($realtime = false){
		$option_key = 'mw_wc_xero_app_data_accounts';
		$lock_handle = '';
		$cached = $this->get_cached_kva($option_key, $realtime, $lock_handle);
		if($cached !== null){
			return $cached;
		}

		if($this->is_xero_connected()){
			$Accounts = $this->X_API_I()->getAccounts($this->get_xero_tenant_id(),null,null,null);
			$list = (!empty($Accounts))?$Accounts->getAccounts():null;
			# An array back means the call succeeded, so an empty one is a real answer rather than a
			# failure, and set_cached_kva() is told so. Refs #87
			if(is_array($list)){
				$X_Accounts_Kva = array();
				foreach($list as $Account){
					$xakfv = $Account->getCode();

					if(empty($xakfv) || $Account->getType() == 'BANK'){
						$xakfv = $Account->getAccountID();
					}

					$X_Accounts_Kva[$xakfv] = $Account->getName().' ('.ucfirst(strtolower($Account->getType())).')';
				}
				return $this->set_cached_kva($option_key, $X_Accounts_Kva, true, $lock_handle);
			}
		}

		return array();
	}
	
	public function xero_get_tax_rates_kva($realtime = false){
		$option_key = 'mw_wc_xero_app_data_tax_rates';
		$lock_handle = '';
		$cached = $this->get_cached_kva($option_key, $realtime, $lock_handle);
		if($cached !== null){
			return $cached;
		}

		if($this->is_xero_connected()){
			$X_Whr = 'Status=="ACTIVE"';

			$TaxRates = $this->X_API_I()->getTaxRates($this->get_xero_tenant_id(),$X_Whr,null,null,1);
			$list = (!empty($TaxRates))?$TaxRates->getTaxRates():null;
			if(is_array($list)){
				$X_TaxRates_Kva = array();
				foreach($list as $TaxRate){
					$X_TaxRates_Kva[$TaxRate->getTaxType()] = $TaxRate->getName().' ('.$TaxRate->getDisplayTaxRate().'%)';
				}
				return $this->set_cached_kva($option_key, $X_TaxRates_Kva, true, $lock_handle);
			}
		}

		return array();
	}

	/**
	 * Branding themes from Xero, keyed BrandingThemeID => Name.
	 *
	 * `GET /BrandingThemes` takes no where/order/page parameters - it returns the org's whole list in
	 * one unpaged response - so unlike the tracking helpers there is no shared "fetch once" method to
	 * hand a pre-fetched list to several caches. Refs #57
	 *
	 * The try/catch is load-bearing rather than defensive tidying, for the same reason as
	 * xero_get_active_tracking_categories(): this is reachable from the ORDER PUSH path
	 * (X_Add_Update_Invoice -> here) whenever the cache is cold, and the queue consumer only marks a
	 * row run=1 AFTER its hook returns, so anything escaping here wedges the queue on that row.
	 * Reference data must never stop an order syncing - on failure fall back to the last cached list,
	 * or an empty one if there is none, and let the invoice go over with Xero's default theme.
	 *
	 * @param bool $realtime Bypass the cache and refetch.
	 */
	public function xero_get_branding_themes_kva($realtime = false){
		# Correctly prefixed, unlike the legacy `mw_wc_xero_app_data_*` names of the older lists -
		# this one is new so there is nothing already stored on live sites to preserve.
		# kva_key_suffix() strips either prefix. Refs #87
		$option_key = 'mw_wc_xero_sync_app_data_branding_themes';
		$lock_handle = '';
		$cached = $this->get_cached_kva($option_key, $realtime, $lock_handle);
		if($cached !== null){
			return $cached;
		}

		# Same contract as the catch below, deliberately - `$is_xero_connected` starts false and only
		# flips inside xero_connect(), so this is reachable on a render that never connected, and
		# get_cached_kva() may have just handed this request the refresh lock for a stale list. Two
		# adjacent early returns behaving differently is what produced the bug below in the first
		# place, so keep them identical. Refs #57, raised on PR #143
		if(!$this->is_xero_connected()){
			$this->release_kva_refresh_lock($option_key, $lock_handle);

			$stale = $this->get_option($option_key, array());
			return (is_array($stale))?$stale:array();
		}

		try{
			$BrandingThemes = $this->X_API_I()->getBrandingThemes($this->get_xero_tenant_id());
		}catch (\Throwable $e) {
			$this->save_log(array(
				'type'    => 'Order',
				'title'   => 'Branding Theme Lookup Failed',
				'details' => 'Could not load Xero branding themes; the order syncs with the previously cached themes, or with the Xero default branding theme, rather than failing. '.get_class($e).': '.$this->redact_log_pii($e->getMessage()),
				'status'  => 0,
			));

			# Fall back to whatever is still cached rather than []. get_cached_kva() returns null for a
			# COLD cache and for a STALE-but-usable one whose refresh lock this request just won, so an
			# empty return here makes the single request that wins that lock the only one that loses its
			# themes when Xero hiccups - every concurrent request carries on serving the stale list from
			# the cache. On the order push path that is one invoice silently syncing without its branding
			# theme, once a TTL, with nothing to show for it but this log line.
			#
			# Deliberately NOT routed through set_cached_kva(): handed a non-empty list it re-stamps
			# cached_at, which would pass the stale list off as freshly fetched and suppress the retry for
			# a full 24h. Releasing the lock here matches what set_cached_kva() does on every other exit.
			# Refs #57, raised on PR #143
			$this->release_kva_refresh_lock($option_key, $lock_handle);

			$stale = $this->get_option($option_key, array());
			return (is_array($stale))?$stale:array();
		}

		$list = (!empty($BrandingThemes))?$BrandingThemes->getBrandingThemes():null;

		# An array back means the call succeeded, so an empty one is a real answer (an org with no
		# branding themes) rather than a failure, and set_cached_kva() is told so - otherwise every
		# read falls through to a live Xero call. Refs #87 for why that distinction matters.
		if(is_array($list)){
			$X_BrandingThemes_Kva = array();
			foreach($list as $BrandingTheme){
				$X_BrandingThemes_Kva[$BrandingTheme->getBrandingThemeId()] = $BrandingTheme->getName();
			}
			return $this->set_cached_kva($option_key, $X_BrandingThemes_Kva, true, $lock_handle);
		}

		return array();
	}

	/**
	 * ACTIVE tracking categories from Xero, or null when unavailable.
	 *
	 * Public so the "Rescan Xero Data" action can fetch once and hand the same list to all three
	 * tracking caches below — previously each one issued its own identical getTrackingCategories()
	 * call, and adding the structured map would have made that three. Refs #87
	 */
	public function xero_get_active_tracking_categories(){
		if(!$this->is_xero_connected()){
			return null;
		}

		$X_Whr = 'Status=="ACTIVE"';

		# try/catch is load-bearing, not defensive tidying. This is reached from the ORDER PUSH path:
		# get_order_sync_xero_tracking_category_data() -> get_cached_tracking_data_by_id_pair() ->
		# xero_get_tracking_map_kva() -> here, and neither push call site sits inside a try. The queue
		# consumer marks a row run=1 only AFTER its hook returns, so anything escaping here wedges the
		# queue on that row forever. Refs #87 introduced this route (previously the push path called
		# getTrackingCategory() for a single id), and a fresh deploy leaves the map cache cold, so the
		# FIRST order sync after an upgrade is exactly when this call happens.
		# Reference data must never be able to stop an order syncing: on failure return null, which
		# callers already treat as "couldn't ask" and fall back to their live single-category lookup.
		try{
			$TrackingCategories = $this->X_API_I()->getTrackingCategories($this->get_xero_tenant_id(),$X_Whr,null,null);
		}catch (\Throwable $e) {
			$this->save_log(array(
				'type'    => 'Order',
				'title'   => 'Tracking Category Lookup Failed',
				'details' => 'Could not load Xero tracking categories; the order sync continues without tracking rather than failing. '.get_class($e).': '.$this->redact_log_pii($e->getMessage()),
				'status'  => 0,
			));

			return null;
		}

		if(empty($TrackingCategories)){
			return null;
		}

		$list = $TrackingCategories->getTrackingCategories();

		# An empty ARRAY means the call succeeded and the org has no active tracking categories; null
		# is reserved for "couldn't ask" (not connected, or no response at all). The three caches
		# below need that distinction to decide between remembering the empty result and doing
		# nothing — collapsing both to null is what made every read refetch. Refs #87
		return (is_array($list))?$list:array();
	}

	/**
	 * @param bool       $realtime   Bypass the cache and refetch.
	 * @param array|null $categories Pre-fetched ACTIVE categories; avoids a duplicate API call when
	 *                               several tracking caches are refreshed together. Refs #87
	 */
	public function xero_get_tracking_categories_kva($realtime = false, $categories = null){
		$option_key = 'mw_wc_xero_app_data_tracking_categories';
		$lock_handle = '';
		$cached = $this->get_cached_kva($option_key, $realtime, $lock_handle);
		if($cached !== null){
			return $cached;
		}

		if(!is_array($categories)){
			$categories = $this->xero_get_active_tracking_categories();
		}

		if(is_array($categories)){
			$X_TrackingCategories_Kva = array();
			foreach($categories as $TrackingCategory){
				$X_TrackingCategories_Kva[$TrackingCategory->getTrackingCategoryID()] = $TrackingCategory->getName();
			}
			return $this->set_cached_kva($option_key, $X_TrackingCategories_Kva, true, $lock_handle);
		}

		return [];
	}

	/**
	 * @param bool       $realtime   Bypass the cache and refetch.
	 * @param array|null $categories Pre-fetched ACTIVE categories. Refs #87
	 */
	public function xero_get_tracking_options_kva($realtime = false, $categories = null){
		$option_key = 'mw_wc_xero_app_data_tracking_options';
		$lock_handle = '';
		$cached = $this->get_cached_kva($option_key, $realtime, $lock_handle);
		if($cached !== null){
			return $cached;
		}

		if(!is_array($categories)){
			$categories = $this->xero_get_active_tracking_categories();
		}

		# Note this list can build empty from a NON-empty category list (categories with no options),
		# which is the other way the old code ended up refetching forever. Refs #87
		if(is_array($categories)){
			$X_TrackingOptions_Kva = array();
			foreach($categories as $TrackingCategory){
				if(!empty($TrackingCategory->getOptions())){
					foreach($TrackingCategory->getOptions() as $TrackingOption){
						$X_TrackingOptions_Kva[$TrackingCategory->getTrackingCategoryID().'__'.$TrackingOption->getTrackingOptionID()] = $TrackingCategory->getName().' - '.$TrackingOption->getName();
					}
				}
			}
			return $this->set_cached_kva($option_key, $X_TrackingOptions_Kva, true, $lock_handle);
		}

		return [];
	}

	/**
	 * Structured `catid__optid` => [tracking_category_id, name, tracking_option_id, option] map.
	 *
	 * Exists so the per-order tracking lookups can be served from cache. The display-oriented
	 * xero_get_tracking_options_kva() deliberately isn't reused for this: its values are
	 * "Category - Option" strings, and splitting those back apart breaks on any name that itself
	 * contains " - ". Shares the same 24h TTL and refresh lock as the other reference lists, and
	 * is refreshed by the same "Rescan Xero Data" action. Refs #87
	 */
	public function xero_get_tracking_map_kva($realtime = false, $categories = null){
		# Correctly prefixed, unlike its four `mw_wc_xero_app_data_*` siblings — those names are
		# legacy and already stored on live sites, this one is new so there is nothing to preserve.
		# kva_key_suffix() strips either prefix. Refs #87
		$option_key = 'mw_wc_xero_sync_app_data_tracking_map';
		$lock_handle = '';
		$cached = $this->get_cached_kva($option_key, $realtime, $lock_handle);
		if($cached !== null){
			return $cached;
		}

		if(!is_array($categories)){
			$categories = $this->xero_get_active_tracking_categories();
		}

		if(is_array($categories)){
			$X_TrackingMap_Kva = array();
			foreach($categories as $TrackingCategory){
				if(empty($TrackingCategory->getOptions())){
					continue;
				}

				foreach($TrackingCategory->getOptions() as $TrackingOption){
					$X_TrackingMap_Kva[$TrackingCategory->getTrackingCategoryID().'__'.$TrackingOption->getTrackingOptionID()] = array(
						'tracking_category_id' => $TrackingCategory->getTrackingCategoryID(),
						'name' => $TrackingCategory->getName(),
						'tracking_option_id' => $TrackingOption->getTrackingOptionID(),
						'option' => $TrackingOption->getName(),
					);
				}
			}

			# The negative flag is now set and cleared inside set_cached_kva() along with the other
			# four lists, rather than by this one bespoke branch. The memo still has to be dropped
			# here: it is per-request state the shared helper knows nothing about, and a merchant who
			# adds a tracking category and hits "Rescan Xero Data" in the same request would
			# otherwise keep reading the stale empty memo. Refs #87
			if(!empty($X_TrackingMap_Kva)){
				$this->tracking_map_memo = null;
			}

			return $this->set_cached_kva($option_key, $X_TrackingMap_Kva, true, $lock_handle);
		}

		return [];
	}

	/**
	 * The tracking map for this request, memoised.
	 *
	 * The negative cache that used to live here is now the shared KVA one, so an empty map costs at
	 * most one build attempt an hour instead of one per order sync — which would have been more API
	 * calls than the live lookup this cache replaced. All that remains here is the per-request memo,
	 * keeping repeated lookups inside one order sync down to a single option read. Refs #87
	 */
	private function get_tracking_map_cached(){
		if($this->tracking_map_memo !== null){
			return $this->tracking_map_memo;
		}

		$map = $this->xero_get_tracking_map_kva();
		$this->tracking_map_memo = (is_array($map))?$map:array();

		return $this->tracking_map_memo;
	}

	/**
	 * getTrackingCategory() with an exception boundary. Returns null when Xero could not be asked.
	 *
	 * The three call sites this replaces are the LIVE FALLBACK on the per-order tracking path, reached
	 * whenever the cached map misses. That includes the case where
	 * xero_get_active_tracking_categories() has *just* failed and returned null, because
	 * xero_get_tracking_map_kva() turns null into an empty map which is indistinguishable from a miss.
	 * So one Xero outage produced TWO calls here, and with the second unguarded the order was marked
	 * 'e' and failed — the opposite of what the guard upstream promises ("syncs without tracking rather
	 * than failing"). Returning null makes that promise true: the callers already treat a null/empty
	 * result as "no tracking data" and build the invoice without it, which is the same thing they do
	 * for a category that simply isn't found. Refs #87, bugs-fixed.md 2026-08-17.
	 */
	private function x_get_tracking_category_safe($TrackingCategoryID){
		try{
			return $this->X_API_I()->getTrackingCategory($this->get_xero_tenant_id(),$TrackingCategoryID);
		}catch (\Throwable $e) {
			$this->save_log(array(
				'type'    => 'Order',
				'title'   => 'Tracking Category Lookup Failed',
				'details' => 'Could not load Xero tracking category '.$TrackingCategoryID.'; the order syncs without tracking rather than failing. '.get_class($e).': '.$this->redact_log_pii($e->getMessage()),
				'status'  => 0,
			));

			return null;
		}
	}

	/**
	 * Resolve a `catid__optid` pair from the cached tracking map.
	 *
	 * Returns [] on a miss so the caller can fall back to a live getTrackingCategory(). That
	 * fallback matters: the cached map is built with Status=="ACTIVE", so a mapping made against a
	 * category later archived in Xero would otherwise stop resolving — a silent behaviour change
	 * for orders that used to sync with tracking attached. Refs #87
	 */
	private function get_cached_tracking_data_by_id_pair($id_pair){
		if(empty($id_pair)){
			return [];
		}

		$map = $this->get_tracking_map_cached();
		if(isset($map[$id_pair]) && is_array($map[$id_pair])){
			return $map[$id_pair];
		}

		return [];
	}

	private function get_order_sync_xero_tracking_category_data(){
		if($this->is_xero_connected()){
			$s = $this->get_option('mw_wc_xero_sync_default_tracking_category');
			if(!empty($s)){
				# Cache first: this used to hit getTrackingCategory() on every single order sync for
				# data that changes about never. Live lookup below stays as the fallback. Refs #87
				$cached = $this->get_cached_tracking_data_by_id_pair($s);
				if(!empty($cached)){
					return $cached;
				}

				$arr = explode('__',$s);
				if(is_array($arr) && count($arr) == 2){
					$TrackingCategoryID = $arr[0];
					$TrackingOptionID = $arr[1];

					$TrackingCategoryID = $this->sanitize($TrackingCategoryID);
					$result = $this->x_get_tracking_category_safe($TrackingCategoryID);

					if(!empty($result)){
						$TrackingCategories = $result->getTrackingCategories();
						if(is_array($TrackingCategories) && !empty($TrackingCategories)){
							$TrackingCategory = $TrackingCategories[0];						
							if(!empty($TrackingCategory->getOptions())){
								foreach($TrackingCategory->getOptions() as $TrackingOption){
									if($TrackingOption->getTrackingOptionID() == $TrackingOptionID){
										return [
											'tracking_category_id' => $TrackingCategory->getTrackingCategoryID(),
											'name' => $TrackingCategory->getName(),
											'tracking_option_id' => $TrackingOption->getTrackingOptionID(),
											'option' => $TrackingOption->getName()
										];
									}
								}
							}
						}
					}

				}
			}
		}

		return [];
	}
	
	private function get_xero_tracking_data_by_id_pair($id_pair){
		if($this->is_xero_connected() && !empty($id_pair)){
			# Cache first; the live lookup below is the fallback. Refs #87
			$cached = $this->get_cached_tracking_data_by_id_pair($id_pair);
			if(!empty($cached)){
				return $cached;
			}

			$arr = explode('__',$id_pair);
			if(is_array($arr) && count($arr) == 2){
				$TrackingCategoryID = $this->sanitize($arr[0]);
				$TrackingOptionID = $this->sanitize($arr[1]);
				$result = $this->x_get_tracking_category_safe($TrackingCategoryID);
				if(!empty($result)){
					$TrackingCategories = $result->getTrackingCategories();
					if(is_array($TrackingCategories) && !empty($TrackingCategories)){
						$TrackingCategory = $TrackingCategories[0];
						if(!empty($TrackingCategory->getOptions())){
							foreach($TrackingCategory->getOptions() as $TrackingOption){
								if($TrackingOption->getTrackingOptionID() == $TrackingOptionID){
									return [
										'tracking_category_id' => $TrackingCategory->getTrackingCategoryID(),
										'name' => $TrackingCategory->getName(),
										'tracking_option_id' => $TrackingOption->getTrackingOptionID(),
										'option' => $TrackingOption->getName()
									];
								}
							}
						}
					}
				}
			}
		}
		return [];
	}

	private function get_order_sync_xero_tracking_category_data_by_option_name($option_name, $category_id = ''){
		if($this->is_xero_connected() && !empty($option_name)){
			if(empty($category_id)){
				$s = $this->get_option('mw_wc_xero_sync_default_tracking_category');
				if(!empty($s)){
					$arr = explode('__',$s);
					if(is_array($arr) && count($arr) == 2){
						$category_id = $this->sanitize($arr[0]);
					}
				}
			}

			# Cache first. Preference order matches the live path below: the default/specified
			# category wins, then any category. Both live lookups remain as the fallback, so an
			# option in a category the ACTIVE-filtered cache doesn't carry still resolves. Refs #87
			$cached_map = $this->get_tracking_map_cached();
			if(!empty($cached_map)){
				$option_name_c = strtolower(trim($option_name));
				$cached_any = [];
				foreach($cached_map as $cached_entry){
					if(!is_array($cached_entry) || !isset($cached_entry['option'])){
						continue;
					}

					if(strtolower(trim($cached_entry['option'])) != $option_name_c){
						continue;
					}

					if(!empty($category_id) && $cached_entry['tracking_category_id'] == $category_id){
						return $cached_entry;
					}

					if(empty($cached_any)){
						$cached_any = $cached_entry;
					}
				}

				if(!empty($cached_any)){
					return $cached_any;
				}
			}

			// Helper to search a single TrackingCategory object for the option name
			$search_in_category = function($TrackingCategory) use ($option_name){
				if(!empty($TrackingCategory->getOptions())){
					foreach($TrackingCategory->getOptions() as $TrackingOption){
						if(strtolower(trim($TrackingOption->getName())) == strtolower(trim($option_name))){
							return [
								'tracking_category_id' => $TrackingCategory->getTrackingCategoryID(),
								'name' => $TrackingCategory->getName(),
								'tracking_option_id' => $TrackingOption->getTrackingOptionID(),
								'option' => $TrackingOption->getName()
							];
						}
					}
				}
				return [];
			};

			// First: try the default/specified category if we have one
			if(!empty($category_id)){
				$TrackingCategoryID = $this->sanitize($category_id);
				$result = $this->x_get_tracking_category_safe($TrackingCategoryID);
				if(!empty($result)){
					$TrackingCategories = $result->getTrackingCategories();
					if(is_array($TrackingCategories) && !empty($TrackingCategories)){
						$match = $search_in_category($TrackingCategories[0]);
						if(!empty($match)){
							return $match;
						}
					}
				}
			}

			// Fallback: search all tracking categories.
			// Guarded for the same reason as x_get_tracking_category_safe(): this is the LAST resort on
			// the per-order tracking path, so it is reached precisely when earlier lookups have already
			// failed — the most likely moment for Xero to be unavailable. Unguarded, it threw and the
			// queue consumer marked the order 'e' instead of syncing it without tracking.
			try{
				$all_result = $this->X_API_I()->getTrackingCategories($this->get_xero_tenant_id(),null,null,null);
			}catch (\Throwable $e) {
				$this->save_log(array(
					'type'    => 'Order',
					'title'   => 'Tracking Category Lookup Failed',
					'details' => 'Could not load the Xero tracking category list; the order syncs without tracking rather than failing. '.get_class($e).': '.$this->redact_log_pii($e->getMessage()),
					'status'  => 0,
				));

				$all_result = null;
			}

			if(!empty($all_result)){
				$all_categories = $all_result->getTrackingCategories();
				if(is_array($all_categories) && !empty($all_categories)){
					foreach($all_categories as $TrackingCategory){
						$match = $search_in_category($TrackingCategory);
						if(!empty($match)){
							return $match;
						}
					}
				}
			}

		}
		return [];
	}

	# Data map / save after sync (push / pull)
	protected function save_xero_product_into_local_dbt($Item){
		if(is_object($Item) && !empty($Item)){
			global $wpdb;
			$tbl = $this->gdtn('products');
			
			$SD = array(
				'ItemID' => $Item->getItemID(),
				'Name' => $Item->getName(),
				'Code' => $Item->getCode(),
				'Description' => $Item->getDescription(),
				'PurchaseDescription' => $Item->getPurchaseDescription(),
				'IsTrackedAsInventory' => ($Item->getIsTrackedAsInventory())?1:0,
				'UnitPrice' => (float) $Item->getSalesDetails()->getUnitPrice(),						
			);
			
			$SD = $this->arr_nts($SD);
			
			$X_Data = array(
				'IsSold' => $Item->getIsSold(),
				'IsPurchased' => $Item->getIsPurchased(),
				'P_UnitPrice' => (float) $Item->getPurchaseDetails()->getUnitPrice(),
				
				'SD_AccountCode' => $Item->getSalesDetails()->getAccountCode(),
				'SD_TaxType' => $Item->getSalesDetails()->getTaxType(),								
				'SD_CogsAccountCode' => $Item->getSalesDetails()->getCogsAccountCode(),
				
				'PD_AccountCode' => $Item->getPurchaseDetails()->getAccountCode(),
				'PD_TaxType' => $Item->getPurchaseDetails()->getTaxType(),								
				'PD_CogsAccountCode' => $Item->getPurchaseDetails()->getCogsAccountCode(),
			);
			
			$X_Data = $this->arr_nts($X_Data);
			
			$SD['X_Data'] = serialize($X_Data);
			
			if(!empty($SD['ItemID'])){
				# replace(), not insert(): the sweep no longer truncates first, so a re-import has to
				# update the existing row instead of colliding with the UNIQUE ItemID. Every column is
				# supplied, so REPLACE's delete-and-reinsert can't blank an unlisted field. Refs #87
				$r = $wpdb->replace($tbl,$SD);
				return $r;
			}
		}
		
		return false;
	}
	
	protected function save_xero_customer_into_local_dbt($Contact){
		if(is_object($Contact) && !empty($Contact)){
			global $wpdb;
			$tbl = $this->gdtn('customers');
			
			$SD = array(
				'ContactID' => $Contact->getContactID(),
				'ContactStatus' => $Contact->getContactStatus(),
				'AccountNumber' => $Contact->getAccountNumber(),
				'CompanyNumber' => $Contact->getCompanyNumber(),
				'FirstName' => $Contact->getFirstName(),
				'LastName' => $Contact->getLastName(),
				'Name' => $Contact->getName(),
				'EmailAddress' => $Contact->getEmailAddress(),
				'DefaultCurrency' => $Contact->getDefaultCurrency(),
			);
			
			$Phones = $Contact->getPhones();
			if(is_array($Phones) && !empty($Phones)){
				foreach($Phones as $Phone){
					if($Phone->getPhoneType() == 'MOBILE' && !empty($Phone->getPhoneNumber())){
						$SD['Mobile'] = $Phone->getPhoneNumber();
						break;
					}
				}
			}
			
			$SD = $this->arr_nts($SD);
			
			$X_Data = array(
				'HasAttachments' => $Contact->getHasAttachments(),							
				#'BankAccountDetails' => $Contact->getBankAccountDetails(),
				'Website' => $Contact->getWebsite(),
				'IsCustomer' => $Contact->getIsCustomer(),
				'IsSupplier' => $Contact->getIsSupplier(),						
			);
			
			$X_Data = $this->arr_nts($X_Data);
			
			$SD['X_Data'] = serialize($X_Data);						
			if(!empty($SD['ContactID'])){
				# replace(), not insert(): the sweep no longer truncates first, so a re-import has to
				# update the existing row instead of colliding with the UNIQUE ContactID. Every column
				# is supplied, so REPLACE's delete-and-reinsert can't blank an unlisted field. Refs #87
				$r = $wpdb->replace($tbl,$SD);
				return $r;
				#echo $wpdb->last_error;
			}
		}
		
		return false;
	}
	
	protected function save_xero_product_variation_map($wc_pv_id,$X_P_ID,$is_variation=false,$pull=false){
		$wc_pv_id = intval($wc_pv_id);
		$X_P_ID = trim($X_P_ID);
		
		if($wc_pv_id > 0 && !empty($X_P_ID)){
			$table = (!$is_variation)?$this->gdtn('map_products'):$this->gdtn('map_variations');
			global $wpdb;
			
			$save_data = array();
			$wpvf = (!$is_variation)?'W_P_ID':'W_V_ID';
			
			if(!$pull){
				$save_data['X_P_ID'] = $X_P_ID;
				if($this->get_field_by_val($table,'id',$wpvf,$wc_pv_id)){
					$wpdb->update($table,$save_data,array($wpvf=>$wc_pv_id),'',array('%d'));
				}else{
					$save_data[$wpvf] = $wc_pv_id;
					$wpdb->insert($table, $save_data);
				}
			}else{	
				$save_data[$wpvf] = $wc_pv_id;
				if($this->get_field_by_val($table,'id','X_P_ID',$X_P_ID)){
					$wpdb->update($table,$save_data,array('X_P_ID'=>$X_P_ID),'',array('%s'));
				}else{					
					$save_data['X_P_ID'] = $X_P_ID;
					$wpdb->insert($table, $save_data);
				}
			}
		}
	}

	protected function save_xero_customer_map($wc_cus_id,$X_C_ID,$is_supplier=false,$pull=false){
		$wc_cus_id = intval($wc_cus_id);
		$X_C_ID = trim($X_C_ID);
		
		if($wc_cus_id > 0 && !empty($X_C_ID)){
			$table = (!$is_supplier)?$this->gdtn('map_customers'):'';
			if(empty($table)){
				return;
			}

			global $wpdb;
			
			$save_data = array();
			$wcvf = 'W_C_ID';
			
			if(!$pull){
				$save_data['X_C_ID'] = $X_C_ID;
				if($this->get_field_by_val($table,'id',$wcvf,$wc_cus_id)){
					$wpdb->update($table,$save_data,array($wcvf=>$wc_cus_id),'',array('%d'));
				}else{
					$save_data[$wcvf] = $wc_cus_id;
					$wpdb->insert($table, $save_data);
				}
			}else{
				$save_data[$wcvf] = $wc_cus_id;
				if($this->get_field_by_val($table,'id','X_C_ID',$X_C_ID)){
					$wpdb->update($table,$save_data,array('X_C_ID'=>$X_C_ID),'',array('%d'));
				}else{
					$save_data['X_C_ID'] = $X_C_ID;
					$wpdb->insert($table, $save_data);
				}
			}
		}
	}

	# Order Sync Status List
	public function get_order_sync_status_list($order_id_num_arr){
		$s_order_sync_as = $this->get_option('mw_wc_xero_sync_order_sync_as');
		if($s_order_sync_as != 'Invoice'){
			return array();
		}

		if($this->is_xero_connected() && is_array($order_id_num_arr) && !empty($order_id_num_arr)){
			$w_x_o_a = $this->xero_match_orders_to_invoices($order_id_num_arr);

			foreach($order_id_num_arr as $k => $v){
				$order_id_num_arr[$k] = (isset($w_x_o_a[$k]) && !empty($w_x_o_a[$k]))?$w_x_o_a[$k]:'';
			}

			return $order_id_num_arr;
		}

		return array();
	}

	# Query Xero for the given [order_id => order_number|id] map and return matches keyed by order_id:
	# [order_id => array('ID'=>uuid,'Type'=>'Invoice','Xero_Link'=>url)]. Persists each hit to the order
	# so subsequent Orders-list loads render server-side without an API call. Shared by the Orders-list
	# backfill and the daily reconcile cron. Refs #83
	private function xero_match_orders_to_invoices($order_id_num_arr){
		$w_x_o_a = array();
		if(!$this->is_xero_connected() || !is_array($order_id_num_arr) || empty($order_id_num_arr)){
			return $w_x_o_a;
		}

		$a_c_s = 50;
		$oina_n = (count($order_id_num_arr) > $a_c_s)?array_chunk($order_id_num_arr,$a_c_s,true):array($order_id_num_arr);

		foreach($oina_n as $v_arr){
			# Build the InvoiceNumber that Xero would carry for each order (sync-as filter + prefix / next-number).
			foreach($v_arr as $k => $v){
				if($this->get_xero_order_sync_as($k) != 'Invoice'){
					unset($v_arr[$k]);
					continue;
				}

				if($this->use_next_xero_order_number()){
					$n_x_o_n = $this->get_next_xero_order_number($k);
					if(!empty($n_x_o_n)){
						$v_arr[$k] = $n_x_o_n;
					}
				}else{
					$v_arr[$k] = $this->apply_order_number_prefix((string) $v_arr[$k]);
				}
			}

			$w_o_i_n = (!empty($v_arr))?implode(',',$v_arr):'';
			if(empty($w_o_i_n)){
				continue;
			}

			$if_modified_since = $this->get_x_f_ms_filter_datetime();
			$where = 'Type=="ACCREC" AND Status != "DELETED"';
			# An ApiException here (rate limit, expired token, transport failure) would otherwise escape and
			# kill the whole reconcile run — the daily cron and the Push > Orders AJAX both call this. Skip
			# this batch instead, leaving cached state untouched so nothing caches a false "Not Synced".
			$invoices = null;
			try{
				# summaryOnly stays FALSE. It would only read getInvoiceNumber()/getInvoiceID() so the
				# saving looked free, but Xero returns
				#   [400] The supplied filter is unavailable on this endpoint when using the
				#         summaryOnly flag
				# and this request carries both a `where` and an invoice_numbers filter. Reverted with
				# the rest of the #87 summaryOnly change - see bugs-fixed.md 2026-08-17.
				$result = $this->X_API_I()->getInvoices($this->XeroTenantId,$if_modified_since,$where,null,null,$w_o_i_n,null,null,1,null,null,null,false);
				$invoices = $result->getInvoices();
			}catch (\XeroAPI\XeroPHP\ApiException $e) {
				$this->save_log(array('type'=>'Order','title'=>'Order Sync Status Check Error','details'=>'Xero API error while matching orders to invoices: '.$e->getMessage(),'status'=>0));
				continue;
			}catch (\InvalidArgumentException $e) {
				# The vendored SDK validates enums on deserialization and throws on values Xero has added
				# but the SDK doesn't know yet — same backstop as getOrganisations(). One bad payload must
				# not abort the batch before the error is logged. See docs/rules/xero-sync.md.
				$this->save_log(array('type'=>'Order','title'=>'Order Sync Status Check Error','details'=>'Unexpected value in Xero invoice payload: '.$e->getMessage(),'status'=>0));
				continue;
			}
			# Only persist results when the API call actually returned a list (an array, possibly empty),
			# so a transient error never caches a false "Not Synced".
			if(is_array($invoices)){
				foreach($invoices as $invoice){
					$InvoiceNumber = $invoice->getInvoiceNumber();
					if(!empty($InvoiceNumber)){
						$key = array_search($InvoiceNumber, $v_arr);
						if($key !== false){
							$key = (int) $key;
							$invoice_id = $invoice->getInvoiceID();
							$w_x_o_a[$key] = array(
								'ID' => $invoice_id,
								'Type' => 'Invoice',
								'Xero_Link' => $this->get_xero_view_invoice_link_by_id($invoice_id)
							);
							# Persist so this order is queried at most once, then renders server-side. Refs #83
							$this->set_order_xero_sync_state($key,$invoice_id,'',(string) $InvoiceNumber);
						}
					}
				}

				# Cache the negative for queried orders Xero didn't return, so they render "Not Synced"
				# server-side instead of re-querying on every page load. Refs #83
				foreach(array_keys($v_arr) as $k){
					if(!isset($w_x_o_a[$k])){
						$this->set_order_xero_not_synced($k);
					}
				}
			}
		}

		return $w_x_o_a;
	}

	# Daily cron: pre-warm + re-validate local Xero sync state for orders modified in the last N days,
	# so the Orders list renders status without live API calls. Bounded (window + hard cap) and
	# rate-limit aware so it never storms the Xero API. Refs #83
	public function reconcile_order_xero_sync_status(){
		if(!$this->is_xero_connected()){
			return;
		}
		if($this->get_option('mw_wc_xero_sync_order_sync_as') != 'Invoice'){
			return;
		}

		$days = (int) apply_filters('mwxs_sync_status_recheck_days', 30);
		if($days < 1){
			$days = 30;
		}
		$max = (int) apply_filters('mwxs_sync_status_recheck_max', 1000);
		if($max < 1){
			$max = 1000;
		}

		# Skip orders already checked within this many days to avoid repeat Xero query calls.
		# 0 = always re-check. Unknown (never-checked) orders are always processed regardless. Refs #83
		$reverify_days = (int) apply_filters('mwxs_sync_status_reverify_days', 7);
		$reverify_cutoff = ($reverify_days > 0) ? (time() - ($reverify_days * DAY_IN_SECONDS)) : 0;

		$cutoff = time() - ($days * DAY_IN_SECONDS);
		$order_ids = wc_get_orders(array(
			'limit'         => $max,
			'date_modified' => '>=' . $cutoff,
			'return'        => 'ids',
			'orderby'       => 'date',
			'order'         => 'DESC',
			'type'          => 'shop_order',
		));

		if(!is_array($order_ids) || empty($order_ids)){
			return;
		}

		foreach(array_chunk($order_ids, 50) as $chunk){
			# Stop (resume next daily run) when near the Xero rate limit; state already persisted.
			$limits = $this->check_xero_rate_limits();
			if(!empty($limits['daily_limit_reached']) || !empty($limits['minute_limit_reached'])){
				break;
			}

			# Build [order_id => number|id] for the chunk and re-check live. The matcher persists
			# both outcomes (hit -> Synced with id, miss -> Not Synced), so deletions/removals made
			# directly in Xero flip the cached state automatically. Refs #83
			$map = array();
			foreach($chunk as $oid){
				$oid = (int) $oid;
				if($oid < 1 || $this->get_xero_order_sync_as($oid) != 'Invoice'){
					continue;
				}
				# Skip orders re-checked recently (already cached & fresh) to cut Xero query calls.
				# Never-checked orders return 0 here, so they're always backfilled. Refs #83
				if($reverify_cutoff > 0){
					$checked_at = $this->get_order_xero_checked_at($oid);
					if($checked_at > 0 && $checked_at >= $reverify_cutoff){
						continue;
					}
				}
				$num = $this->get_woo_ord_number_from_order($oid);
				$map[$oid] = (!empty($num)) ? $num : $oid;
			}

			if(empty($map)){
				continue;
			}

			$this->xero_match_orders_to_invoices($map);
		}
	}

	# Xero Pull Data List Functions
	public function X_Get_Items($StartsWith=''){
		if($this->is_xero_connected()){			
			$order = 'Name ASC';
			$where = null;

			$StartsWith = $this->sanitize($StartsWith);
			if(!empty($StartsWith)){
				$where = 'Name.StartsWith("'.$StartsWith.'")';
			}

			$result = $this->X_API_I()->getItems($this->XeroTenantId,null,$where,$order);
			$items = $result->getItems();
			return $items;
		}
	}
	
	# Xero Sync Functions
	# Resolve the WooCommerce cost for a product/variation, or null when there is nothing to send.
	# Reads the WooCommerce core COGS field - the same key X_Pull_Cost() writes - so the two
	# directions stay symmetric. Refs #109
	public function get_wc_product_cost($product_data){
		if(!is_array($product_data)){
			return null;
		}

		if(!array_key_exists('_cogs_total_value',$product_data)){
			return null;
		}

		$cost = $product_data['_cogs_total_value'];
		if($cost === '' || $cost === null || !is_numeric($cost)){
			return null;
		}

		return (float) $cost;
	}

	# Whether cost should be sent to Xero. Mirrors how the product push itself is gated: the
	# "Cost" push data type governs it, and a manual push bypasses the real-time setting. Refs #109
	public function is_cost_push_enabled($product_data=array()){
		$manual = false;
		if(is_array($product_data) && !empty($product_data['manual'])){
			$manual = true;
		}

		return ($manual || $this->check_if_real_time_push_enable_for_item('Cost'));
	}

	# Push the WooCommerce cost onto an existing Xero item.
	#
	# This is the plugin's first update of an existing Xero item - every other product path only
	# ever creates. It deliberately reads the item back first and mutates only UnitPrice on the
	# item's own PurchaseDetails, so whatever else is configured there (COGS account, purchase
	# account, tax type) is preserved rather than depending on how Xero merges a partial payload.
	# Refs #109
	# Returns true when the cost was written, null when there was nothing to do (cost push
	# off, no cost set, already in sync) and false only on an actual failure. The queue
	# consumer marks a row 'e' on a falsey result, so a no-op must not look like an error.
	public function X_Push_Product_Cost($ItemID,$product_data){
		if(empty($ItemID) || !is_array($product_data)){
			return null;
		}

		if(!$this->is_cost_push_enabled($product_data)){
			return null;
		}

		$cost = $this->get_wc_product_cost($product_data);
		if($cost === null){
			return null;
		}

		# Normalise to the precision Xero stores, otherwise a WooCommerce cost carrying more
		# decimals never equals the value read back and every save re-pushes it. Refs #109
		$cost = round($cost,(int) $this->x_unitdp());

		$wc_pv_id = (int) $this->get_array_isset($product_data,'wc_product_id',0,false);
		$is_variation = $this->get_array_isset($product_data,'is_variation',false,false);
		$lt = (!$is_variation)?'Product':'Variation';

		# A disconnected Xero is a genuine failure, not a no-op: the cost did not reach Xero,
		# so the queue row must stay retryable rather than be recorded as done. Refs #109
		if(!$this->is_xero_connected()){
			$this->save_log(array('type'=>'Cost','title'=>'Push Cost Error for '.$lt.' #'.$wc_pv_id,'details'=>'Not connected to Xero, so the cost was not updated.','status'=>0,'wc_id'=>$wc_pv_id,'xero_id'=>$ItemID));
			return false;
		}

		try{
			$result = $this->X_API_I()->getItem($this->get_xero_tenant_id(),$ItemID,$this->x_unitdp());
		}catch (\XeroAPI\XeroPHP\ApiException $e) {
			$error = AccountingObjectSerializer::deserialize($e->getResponseBody(), '\XeroAPI\XeroPHP\Models\Accounting\Error',[]);
			$ld = $this->get_error_message_from_xero_error_object($error);
			if(empty($ld)){
				$ld = 'Xero API error: ' . $e->getMessage();
			}
			$this->save_log(array('type'=>'Cost','title'=>'Push Cost Error for '.$lt.' #'.$wc_pv_id,'details'=>$ld,'status'=>0,'wc_id'=>$wc_pv_id,'xero_id'=>$ItemID));
			return false;
		}

		$Item = null;
		if(!empty($result)){
			$r_items = $result->getItems();
			if(is_array($r_items) && !empty($r_items)){
				$Item = $r_items[0];
			}
		}

		if(empty($Item)){
			$this->save_log(array('type'=>'Cost','title'=>'Push Cost Error for '.$lt.' #'.$wc_pv_id,'details'=>'Item could not be read back from Xero, so the cost was not updated.','status'=>0,'wc_id'=>$wc_pv_id,'xero_id'=>$ItemID));
			return false;
		}

		$PurchaseDetails = $Item->getPurchaseDetails();
		if(empty($PurchaseDetails)){
			$PurchaseDetails = new XeroAPI\XeroPHP\Models\Accounting\Purchase;
		}else{
			# Nothing to do when Xero already holds this cost. Also stops a product saved in
			# wp-admin straight after a cost pull from writing the same value back. Refs #109
			$current = $PurchaseDetails->getUnitPrice();
			if($current !== null && abs(((float) $current) - $cost) < 0.00001){
				return null;
			}
		}

		$PurchaseDetails->setUnitPrice($cost);
		$Item->setPurchaseDetails($PurchaseDetails);

		$Items = new XeroAPI\XeroPHP\Models\Accounting\Items;
		$Items->setItems([$Item]);

		try{
			$u_result = $this->X_API_I()->updateItem($this->get_xero_tenant_id(),$ItemID,$Items,$this->x_unitdp());
		}catch (\XeroAPI\XeroPHP\ApiException $e) {
			$error = AccountingObjectSerializer::deserialize($e->getResponseBody(), '\XeroAPI\XeroPHP\Models\Accounting\Error',[]);
			$ld = $this->get_error_message_from_xero_error_object($error);
			if(empty($ld)){
				$ld = 'Xero API error: ' . $e->getMessage();
			}
			$this->save_log(array('type'=>'Cost','title'=>'Push Cost Error for '.$lt.' #'.$wc_pv_id,'details'=>$ld,'status'=>0,'wc_id'=>$wc_pv_id,'xero_id'=>$ItemID));
			return false;
		}

		# Xero answers 200 with ValidationErrors rather than throwing, so without this a
		# rejected update would be logged as a success. Same shape as the contact paths. Refs #109
		if(!empty($u_result)){
			$u_items = $u_result->getItems();
			if(is_array($u_items) && !empty($u_items)){
				$validation_errors = $u_items[0]->getValidationErrors();
				if(!empty($validation_errors)){
					$ld = $this->get_error_message_from_xero_validation_errors($validation_errors);
					if(empty($ld)){
						$ld = 'Xero rejected the cost update.';
					}
					$this->save_log(array('type'=>'Cost','title'=>'Push Cost Error for '.$lt.' #'.$wc_pv_id,'details'=>$ld,'status'=>0,'wc_id'=>$wc_pv_id,'xero_id'=>$ItemID));
					return false;
				}
			}
		}

		$this->save_log(array('type'=>'Cost','title'=>'Cost Updated in Xero for '.$lt.' #'.$wc_pv_id,'details'=>'Xero item purchase cost set to '.$cost.'.','status'=>1,'wc_id'=>$wc_pv_id,'xero_id'=>$ItemID));

		return true;
	}

	public function X_Add_Product($product_data){
		if(is_array($product_data) && !empty($product_data)){
			if($this->is_xero_connected()){
				if($xero_product_id = $this->if_xero_product_exists($product_data)){
					return false;
				}
				
				$wc_pv_id = (int) $this->get_array_isset($product_data,'wc_product_id',0,false);
				$is_variation = $this->get_array_isset($product_data,'is_variation',false,false);
				$lt = (!$is_variation)?'Product':'Variation';
				
				$_manage_stock = $this->get_array_isset($product_data,'_manage_stock','no');
				$IsTrackedAsInventory = ($_manage_stock == 'yes')?true:false;
						
				$dxsa = $this->get_option('mw_wc_xero_sync_default_xero_sales_account_fnp');

				if($IsTrackedAsInventory){
					$dxcogsa = $this->get_option('mw_wc_xero_sync_default_xero_cogs_account_fnp');
					$dxiaa = $this->get_option('mw_wc_xero_sync_default_xero_inventory_asset_account_fnp');
					if(empty($dxiaa)){
						$this->save_log(array('type'=>$lt,'title'=>'Create '.$lt.' Error #'.$wc_pv_id,'details'=>'Xero Inventory Asset Account not set','status'=>0));
						return false;
					}					
					
					if(empty($dxcogsa)){
						$this->save_log(array('type'=>$lt,'title'=>'Create '.$lt.' Error #'.$wc_pv_id,'details'=>'Xero COGS Account not set (required for inventory product)','status'=>0));
						return false;
					}					
				}
				
				$nr_chars = $this->x_nrc('product');
				$name = $this->get_array_isset($product_data,'name','',true,50,false,$nr_chars);
				
				$sku = $this->get_array_isset($product_data,'_sku','',true,30);
				$description = $this->get_array_isset($product_data,'description','',true,4000);				
				
				$Item = new XeroAPI\XeroPHP\Models\Accounting\Item;
				$Item->setName($name);
				
				$Code = (!empty($sku))?$sku:$wc_pv_id;
				$Item->setCode($Code);
				$Item->setDescription($description);
				
				$Item->setIsSold(true);
				
				$UnitPrice = $this->get_array_isset($product_data,'_regular_price',0);
				$UnitPrice = str_replace(',','',$UnitPrice);
				$UnitPrice = floatval($UnitPrice);

				#SalesDetails
				$SalesDetails = new XeroAPI\XeroPHP\Models\Accounting\Purchase;
				$SalesDetails->setUnitPrice($UnitPrice);
				if(!empty($dxsa)){
					$SalesDetails->setAccountCode($dxsa);
				}

				$Item->setSalesDetails($SalesDetails);

				#PurchaseDetails
				# The COGS account is what makes an item tracked, so it stays behind the inventory
				# check - but the purchase cost is valid on untracked items too and must not
				# inherit that gate, or cost would silently skip every non-inventory product. Refs #109
				$x_purchase_cost = ($this->is_cost_push_enabled($product_data))?$this->get_wc_product_cost($product_data):null;
				$set_cogs_account = ($IsTrackedAsInventory && !empty($dxcogsa));

				if($set_cogs_account || $x_purchase_cost !== null){
					$PurchaseDetails = new XeroAPI\XeroPHP\Models\Accounting\Purchase;
					if($set_cogs_account){
						$PurchaseDetails->setCOGSAccountCode($dxcogsa);
					}
					if($x_purchase_cost !== null){
						$PurchaseDetails->setUnitPrice($x_purchase_cost);
					}
					$Item->setPurchaseDetails($PurchaseDetails);
				}
				
				$_stock = (int) $this->get_array_isset($product_data,'_stock',0);
				
				$Item->setIsTrackedAsInventory($IsTrackedAsInventory);
				if($IsTrackedAsInventory){
					$Item->setInventoryAssetAccountCode($dxiaa);
					$Item->setQuantityOnHand($_stock);		
				}
				
				$Arr_Items = array();
				array_push($Arr_Items, $Item);
				
				$Items = new XeroAPI\XeroPHP\Models\Accounting\Items;
				$Items->setItems($Arr_Items);

				#Debug
				#$this->_p($product_data);
				#$this->_p($Item);
				#return;
				
				try{
					$result = $this->X_API_I()->createItems($this->XeroTenantId,$Items,true,$this->x_unitdp());
					#$this->add_text_into_log_file($Item);
					#$this->add_text_into_log_file($result);
					
					if(!empty($result)){
						$xr_items = $result->getItems();
						if(is_array($xr_items) && !empty($xr_items)){
							$x_item = $xr_items[0];
							$X_ItemID = $x_item->getItemID();
							if(!empty($X_ItemID)){							
								$this->save_xero_product_into_local_dbt($x_item);
								$this->save_xero_product_variation_map($wc_pv_id,$X_ItemID,$is_variation);
								
								$ld = "{$lt} #{$wc_pv_id} added into Xero successfully".PHP_EOL;
								$ld.="Xero Item ID #".$X_ItemID;
								
								$this->save_log(
									array(
										'type'=>$lt,
										'title'=>'Create '.$lt.' #'.$wc_pv_id,
										'details'=>$ld,
										'status'=>1,
										'wc_id'=>$wc_pv_id,
										'xero_id'=>$X_ItemID,
										)
								);
								return $X_ItemID;
							}
						}
					}
				}catch (\XeroAPI\XeroPHP\ApiException $e) {
					$error = AccountingObjectSerializer::deserialize($e->getResponseBody(), '\XeroAPI\XeroPHP\Models\Accounting\Error',[]);
					$ld = $this->get_error_message_from_xero_error_object($error);
					if(empty($ld)){
						$ld = 'Xero API error: ' . $e->getMessage();
					}
					$this->save_log(array('type'=>$lt,'title'=>'Create '.$lt.' Error #'.$wc_pv_id,'details'=>$ld,'status'=>0,'wc_id'=>$wc_pv_id));

					return false;
				}catch (\InvalidArgumentException $e) {
					# The SDK json_encode()s the payload and throws GuzzleHttp\Exception\InvalidArgumentException
					# (a \InvalidArgumentException) when a string is not valid UTF-8. Nothing caught it, so a
					# merchant saving that product in wp-admin got the "critical error" screen. The strings are
					# cleaned upstream in get_array_isset() now; this keeps any stray case a logged sync error
					# instead of a fatal. Refs #148
					$this->save_log(array('type'=>$lt,'title'=>'Create '.$lt.' Error #'.$wc_pv_id,'details'=>'The '.strtolower($lt).' data could not be encoded for Xero: '.$e->getMessage(),'status'=>0,'wc_id'=>$wc_pv_id));

					return false;
				}				
				
				return false;
			}
		}
	}
	
	/**
	 * Create a Xero contact for a registered WooCommerce customer.
	 *
	 * @param  array $customer_data Mapped customer payload built by the caller.
	 * @return string|false The new Xero ContactID, or false when the contact was not created.
	 */
	public function X_Add_Customer($customer_data){
		if(is_array($customer_data) && !empty($customer_data)){
			if($this->is_xero_connected()){
				# Last line of defence, and it has to distinguish null from ''. This re-check exists so
				# that ANY caller reaching X_Add_Customer() still cannot create a contact that already
				# exists — but null is falsey, so treating it as "absent" meant a Xero outage sailed
				# straight past both this guard and the caller's, and createContacts() ran. The
				# resulting duplicate carries no marker either, because the duplicate-name suffix logic
				# below reads its own failed lookup as "name is free". Refs #87
				$xero_contact_id = $this->if_xero_customer_exists($customer_data);
				if($xero_contact_id === null || $xero_contact_id){
					return false;
				}
				
				$nr_chars = $this->x_nrc('customer');
				$lt = 'Customer';
				
				$wc_cus_id = (int) $this->get_array_isset($customer_data,'wc_cus_id',0,false);
				
				$first_name = $this->get_array_isset($customer_data,'first_name','',true,255,false,$nr_chars);
				$last_name = $this->get_array_isset($customer_data,'last_name','',true,255,false,$nr_chars);
				$email = $this->get_array_isset($customer_data,'email','',true,255,false,$nr_chars);
				
				$full_name = $this->get_array_isset($customer_data,'full_name','',true,255,false,$nr_chars);
				$name = $this->get_customer_formated_display_name_for_xs($customer_data,$full_name);
				
				if(empty($name)){
					$this->save_log(array('type'=>$lt,'title'=>'Create '.$lt.' Error #'.$wc_cus_id,'details'=>'Customer name is empty','status'=>0));
					return false;
				}

				# Xero duplicate name check
				if($this->option_checked('mw_wc_xero_sync_customer_append_id_if_name_taken',true)){
					$ContactID = $this->get_xero_customer_by_name($name,false);

					# null = the name lookup FAILED, so we do not know whether this name is taken.
					# !empty(null) is false, which read that as "the name is free" and skipped the
					# suffix - producing a duplicate Xero contact with an IDENTICAL name and no marker
					# on it, which is the hardest kind for a merchant to spot. Refuse to create rather
					# than guess. Refs #87
					if($ContactID === null){
						return false;
					}

					if(!empty($ContactID)){
						$name .= '-'.$wc_cus_id;
					}
				}				
				
				$Contact = new XeroAPI\XeroPHP\Models\Accounting\Contact;
				
				$Contact->setFirstName($first_name);
				$Contact->setLastName($last_name);
				$Contact->setName($name);
				$Contact->setEmailAddress($email);
				
				$Contact->setIsCustomer(1);

				#Phones
				$Phones = array();
				$billing_phone = $this->get_array_isset($customer_data,'billing_phone','');
				if(!empty($billing_phone)){
					$Phone = new XeroAPI\XeroPHP\Models\Accounting\Phone;
					$Phone->setPhoneType('MOBILE');
					$Phone->setPhoneNumber($billing_phone);
					
					$Phones[] = $Phone;
				}

				if(!empty($Phones)){
					$Contact->setPhones($Phones);
				}
				
				#Addresses
				#-> Billing / POBOX
				$Addresses = array();
				$billing_address_1 = $this->get_array_isset($customer_data,'billing_address_1','',true);
				if(!empty($billing_address_1)){
					$Address = new XeroAPI\XeroPHP\Models\Accounting\Address;

					$Address->setAddressType('POBOX');
					$Address->setAddressLine1($billing_address_1);

					$billing_address_2 = $this->get_array_isset($customer_data,'billing_address_2','',true);
					if(!empty($billing_address_2)){
						$Address->setAddressLine2($billing_address_2);
					}

					$city = $this->get_array_isset($customer_data,'billing_city','',true,255);
					$Address->setCity($city);
					
					$region = $this->get_array_isset($customer_data,'billing_state','',true,255);
					$Address->setRegion($region);

					$postal_code = $this->get_array_isset($customer_data,'billing_postcode','',true,50);
					$Address->setPostalCode($postal_code);

					$country = $this->get_array_isset($customer_data,'billing_country','',true,50);
					$Address->setCountry($country);
					
					$Addresses[] = $Address;
				}
				
				#-> Shipping / STREET
				$shipping_address_1 = $this->get_array_isset($customer_data,'shipping_address_1','',true);
				if(!empty($shipping_address_1)){
					$Address = new XeroAPI\XeroPHP\Models\Accounting\Address;

					$Address->setAddressType('STREET');
					$Address->setAddressLine1($shipping_address_1);

					$shipping_address_2 = $this->get_array_isset($customer_data,'shipping_address_2','',true);
					if(!empty($shipping_address_2)){
						$Address->setAddressLine2($shipping_address_2);
					}

					$city = $this->get_array_isset($customer_data,'shipping_city','',true,255);
					$Address->setCity($city);
					
					$region = $this->get_array_isset($customer_data,'shipping_state','',true,255);
					$Address->setRegion($region);

					$postal_code = $this->get_array_isset($customer_data,'shipping_postcode','',true,50);
					$Address->setPostalCode($postal_code);

					$country = $this->get_array_isset($customer_data,'shipping_country','',true,50);
					$Address->setCountry($country);

					$Addresses[] = $Address;					
				}

				if(!empty($Addresses)){
					$Contact->setAddresses($Addresses);
				}
				
				#DefaultCurrency
				$currency = $this->get_array_isset($customer_data,'currency','',true);
				if(!empty($currency)){
					$Contact->setDefaultCurrency($currency);
				}
				
				#ContactNumber
				if($wc_cus_id > 0){
					#$Contact->setContactNumber($wc_cus_id);
				}

				#CompanyNumber
				
				$Arr_Contacts = array();
				array_push($Arr_Contacts, $Contact);
				
				$Contacts = new XeroAPI\XeroPHP\Models\Accounting\Contacts;
				$Contacts->setContacts($Arr_Contacts);
				
				#Debug
				#$this->_p($customer_data);
				#$this->_p($Contact);
				#return;
				
				try{
					$this->add_wc_debug_log("=== XERO CREATE CONTACT REQUEST ===\nTenant ID: " . $this->XeroTenantId . "\nRequest Data:", true, false, false);
					$this->add_wc_debug_log($Contacts, true, false, false);
					
					// Check and respect rate limits before API call
					if($this->should_delay_api_call()) {
						$this->add_wc_debug_log("Rate limit check: Delaying contact creation due to rate limits", true, false, false);
						sleep(2); // 2 second delay
					}
					
					$result = $this->X_API_I()->createContacts($this->XeroTenantId,$Contacts);
					
					// Note: API usage tracking is now handled automatically by the Guzzle middleware
					$this->add_wc_debug_log("=== XERO CREATE CONTACT RESPONSE ===", true, false, false);
					$this->add_wc_debug_log($result, true, false, false);
				
					#$this->add_text_into_log_file($Contact);
					#$this->add_text_into_log_file($result);
					
					if(!empty($result)){
						$xr_contacts = $result->getContacts();
						if(is_array($xr_contacts) && !empty($xr_contacts)){
							$x_contact = $xr_contacts[0];
							
							if(!$x_contact->getHasValidationErrors()){
								$X_ContactID = $x_contact->getContactID();
								if(!empty($X_ContactID) && $X_ContactID != '00000000-0000-0000-0000-000000000000'){							
									$this->save_xero_customer_into_local_dbt($x_contact);
									$this->save_xero_customer_map($wc_cus_id,$X_ContactID);
									
									$ld = "{$lt} #{$wc_cus_id} added into Xero successfully".PHP_EOL;
									$ld.="Xero Contact ID #".$X_ContactID;

									$this->save_log(
										array(
											'type'=>$lt,
											'title'=>'Create '.$lt.' #'.$wc_cus_id,
											'details'=>$ld,
											'status'=>1,
											'wc_id'=>$wc_cus_id,
											'xero_id'=>$X_ContactID,
											)
									);
									
									return $X_ContactID;
								}
							}else{
								# Error
								# status_attribute_string ,warnings
								$validation_errors = $x_contact->getValidationErrors();
								$ld = $this->get_error_message_from_xero_validation_errors($validation_errors);
								if(!empty($ld)){
									$this->save_log(array('type'=>$lt,'title'=>'Create '.$lt.' Error #'.$wc_cus_id,'details'=>$ld,'status'=>0,'wc_id'=>$wc_cus_id));
								}
							}							
						}
					}
				}catch (\XeroAPI\XeroPHP\ApiException $e) {
					$error = AccountingObjectSerializer::deserialize($e->getResponseBody(), '\XeroAPI\XeroPHP\Models\Accounting\Error',[]);					
					$ld = $this->get_error_message_from_xero_error_object($error);
					if(!empty($ld)){						
						$this->save_log(array('type'=>$lt,'title'=>'Create '.$lt.' Error #'.$wc_cus_id,'details'=>$ld,'status'=>0,'wc_id'=>$wc_cus_id));
					}

					return false;
				}catch (\InvalidArgumentException $e) {
					# The SDK json_encode()s the payload and throws GuzzleHttp\Exception\InvalidArgumentException
					# (a \InvalidArgumentException) when a string is not valid UTF-8. Uncaught, that reaches
					# wp-admin as a "critical error" instead of a sync failure. Strings are cleaned upstream in
					# get_array_isset(); this is the same backstop X_Add_Product() carries. Refs #148
					$this->save_log(array('type'=>$lt,'title'=>'Create '.$lt.' Error #'.$wc_cus_id,'details'=>'The '.strtolower($lt).' data could not be encoded for Xero: '.$e->getMessage(),'status'=>0,'wc_id'=>$wc_cus_id));

					return false;
				}				
				
				return false;	
			}	
		}
	}

	/**
	 * Create a Xero contact for a guest (non-registered) order.
	 *
	 * Mirrors X_Add_Customer(), but keyed off the order rather than a user account.
	 *
	 * @param  array $customer_data Mapped guest payload built by the caller.
	 * @return string|false The new Xero ContactID, or false when the contact was not created.
	 */
	public function X_Add_Guest($customer_data){
		if(is_array($customer_data) && !empty($customer_data)){
			if($this->is_xero_connected()){
				# Mirrors X_Add_Customer(): null means the lookup FAILED, not that the guest is absent, so
				# creating now risks a duplicate contact. Missed on the first pass because only the
				# customer path's re-check was updated - and the guest path is the more exposed of the
				# two, since if_xero_guest_exists() has no local cache and so calls Xero on every guest
				# order.
				#
				# Guarding the duplicate-name check further down is NOT a substitute: that lookup asks a
				# different question (is this NAME taken), so it succeeding proves nothing about whether
				# the failed EMAIL lookup would have matched. A Xero contact with the same email under a
				# different name would still be duplicated. Refs #87
				$xero_contact_id = $this->if_xero_guest_exists($customer_data);
				if($xero_contact_id === null || $xero_contact_id){
					return false;
				}
				
				$nr_chars = $this->x_nrc('customer');
				$lt = 'Customer';
				$ltt = 'Customer/Guest';
				
				$order_id = (int) $this->get_array_isset($customer_data,'order_id',0,false);
				
				$first_name = $this->get_array_isset($customer_data,'first_name','',true,255,false,$nr_chars);
				$last_name = $this->get_array_isset($customer_data,'last_name','',true,255,false,$nr_chars);
				$email = $this->get_array_isset($customer_data,'email','',true,255,false,$nr_chars);
				
				$full_name = $this->get_array_isset($customer_data,'full_name','',true,255,false,$nr_chars);
				$name = $this->get_customer_formated_display_name_for_xs($customer_data,$full_name);
				
				if(empty($name)){
					$this->save_log(array('type'=>$lt,'title'=>'Create '.$ltt.' Error for Order #'.$order_id,'details'=>'Customer name is empty','status'=>0,'wc_id'=>$order_id));
					return false;
				}

				# Xero duplicate name check
				if($this->option_checked('mw_wc_xero_sync_customer_append_id_if_name_taken',true)){
					$ContactID = $this->get_xero_customer_by_name($name,false);

					# Same as X_Add_Customer(): null means the lookup failed, not that the name is free.
					# Refs #87
					if($ContactID === null){
						return false;
					}

					if(!empty($ContactID)){
						$name .= '--'.$order_id;
					}
				}
				
				$Contact = new XeroAPI\XeroPHP\Models\Accounting\Contact;
				
				$Contact->setFirstName($first_name);
				$Contact->setLastName($last_name);
				$Contact->setName($name);
				$Contact->setEmailAddress($email);
				
				$Contact->setIsCustomer(1);

				#Phones
				$Phones = array();
				$billing_phone = $this->get_array_isset($customer_data,'billing_phone','');
				if(!empty($billing_phone)){
					$Phone = new XeroAPI\XeroPHP\Models\Accounting\Phone;
					$Phone->setPhoneType('MOBILE');
					$Phone->setPhoneNumber($billing_phone);
					
					$Phones[] = $Phone;
				}

				if(!empty($Phones)){
					$Contact->setPhones($Phones);
				}
				
				#Addresses
				#-> Billing / POBOX
				$Addresses = array();
				$billing_address_1 = $this->get_array_isset($customer_data,'billing_address_1','',true);
				if(!empty($billing_address_1)){
					$Address = new XeroAPI\XeroPHP\Models\Accounting\Address;

					$Address->setAddressType('POBOX');
					$Address->setAddressLine1($billing_address_1);

					$billing_address_2 = $this->get_array_isset($customer_data,'billing_address_2','',true);
					if(!empty($billing_address_2)){
						$Address->setAddressLine2($billing_address_2);
					}

					$city = $this->get_array_isset($customer_data,'billing_city','',true,255);
					$Address->setCity($city);
					
					$region = $this->get_array_isset($customer_data,'billing_state','',true,255);
					$Address->setRegion($region);

					$postal_code = $this->get_array_isset($customer_data,'billing_postcode','',true,50);
					$Address->setPostalCode($postal_code);

					$country = $this->get_array_isset($customer_data,'billing_country','',true,50);
					$Address->setCountry($country);
					
					$Addresses[] = $Address;
				}
				
				#-> Shipping / STREET
				$shipping_address_1 = $this->get_array_isset($customer_data,'shipping_address_1','',true);
				if(!empty($shipping_address_1)){
					$Address = new XeroAPI\XeroPHP\Models\Accounting\Address;

					$Address->setAddressType('STREET');
					$Address->setAddressLine1($shipping_address_1);

					$shipping_address_2 = $this->get_array_isset($customer_data,'shipping_address_2','',true);
					if(!empty($shipping_address_2)){
						$Address->setAddressLine2($shipping_address_2);
					}

					$city = $this->get_array_isset($customer_data,'shipping_city','',true,255);
					$Address->setCity($city);
					
					$region = $this->get_array_isset($customer_data,'shipping_state','',true,255);
					$Address->setRegion($region);

					$postal_code = $this->get_array_isset($customer_data,'shipping_postcode','',true,50);
					$Address->setPostalCode($postal_code);

					$country = $this->get_array_isset($customer_data,'shipping_country','',true,50);
					$Address->setCountry($country);

					$Addresses[] = $Address;					
				}

				if(!empty($Addresses)){
					$Contact->setAddresses($Addresses);
				}
				
				#DefaultCurrency
				$currency = $this->get_array_isset($customer_data,'currency','',true);
				if(!empty($currency)){
					$Contact->setDefaultCurrency($currency);
				}
				
				#ContactNumber
				if($order_id > 0){
					#$Contact->setContactNumber($order_id);
				}
				
				#CompanyNumber
				
				$Arr_Contacts = array();
				array_push($Arr_Contacts, $Contact);
				
				$Contacts = new XeroAPI\XeroPHP\Models\Accounting\Contacts;
				$Contacts->setContacts($Arr_Contacts);
				
				#Debug
				#$this->_p($customer_data);
				#$this->_p($Contact);
				#return;
				
				try{
					$this->add_wc_debug_log("=== XERO CREATE CONTACT REQUEST ===\nTenant ID: " . $this->XeroTenantId . "\nRequest Data:", true, false, false);
					$this->add_wc_debug_log($Contacts, true, false, false);
					
					// Check and respect rate limits before API call
					if($this->should_delay_api_call()) {
						$this->add_wc_debug_log("Rate limit check: Delaying contact creation due to rate limits", true, false, false);
						sleep(2); // 2 second delay
					}
					
					$result = $this->X_API_I()->createContacts($this->XeroTenantId,$Contacts);
					
					// Note: API usage tracking is now handled automatically by the Guzzle middleware
					$this->add_wc_debug_log("=== XERO CREATE CONTACT RESPONSE ===", true, false, false);
					$this->add_wc_debug_log($result, true, false, false);
				
					#$this->add_text_into_log_file($Contact);
					#$this->add_text_into_log_file($result);
					
					if(!empty($result)){
						$xr_contacts = $result->getContacts();
						if(is_array($xr_contacts) && !empty($xr_contacts)){
							$x_contact = $xr_contacts[0];
							
							if(!$x_contact->getHasValidationErrors()){
								$X_ContactID = $x_contact->getContactID();
								if(!empty($X_ContactID) && $X_ContactID != '00000000-0000-0000-0000-000000000000'){							
									$this->save_xero_customer_into_local_dbt($x_contact);								
									
									$ld = "{$ltt} for Order #{$order_id} added into Xero successfully".PHP_EOL;
									$ld.="Xero Contact ID #".$X_ContactID;

									$this->save_log(
										array(
											'type'=>$lt,
											'title'=>'Create '.$ltt.' for Order #'.$order_id,
											'details'=>$ld,
											'status'=>1,
											'wc_id'=>$order_id,
											'xero_id'=>$X_ContactID,
											)
									);
									
									return $X_ContactID;
								}
							}else{
								# Error
								# status_attribute_string ,warnings
								$validation_errors = $x_contact->getValidationErrors();
								$ld = $this->get_error_message_from_xero_validation_errors($validation_errors);
								if(!empty($ld)){						
									$this->save_log(array('type'=>$lt,'title'=>'Create '.$ltt.' for Order Error #'.$order_id,'details'=>$ld,'status'=>0,'wc_id'=>$order_id));
								}
							}							
						}
					}
				}catch (\XeroAPI\XeroPHP\ApiException $e) {
					$error = AccountingObjectSerializer::deserialize($e->getResponseBody(), '\XeroAPI\XeroPHP\Models\Accounting\Error',[]);					
					$ld = $this->get_error_message_from_xero_error_object($error);
					if(!empty($ld)){						
						$this->save_log(array('type'=>$lt,'title'=>'Create '.$ltt.' for Order Error #'.$order_id,'details'=>$ld,'status'=>0,'wc_id'=>$order_id));
					}

					return false;
				}catch (\InvalidArgumentException $e) {
					# The SDK json_encode()s the payload and throws GuzzleHttp\Exception\InvalidArgumentException
					# (a \InvalidArgumentException) when a string is not valid UTF-8. Uncaught, that reaches
					# wp-admin as a "critical error" instead of a sync failure. Strings are cleaned upstream in
					# get_array_isset(); this is the same backstop X_Add_Product() carries. Refs #148
					$this->save_log(array('type'=>$lt,'title'=>'Create '.$ltt.' for Order Error #'.$order_id,'details'=>'The '.strtolower($lt).' data could not be encoded for Xero: '.$e->getMessage(),'status'=>0,'wc_id'=>$order_id));

					return false;
				}				
				
				return false;	
			}	
		}
	}

	/**
	 * Create a Xero credit note for a WooCommerce refund.
	 *
	 * @param  int   $refund_id    WooCommerce refund the credit note represents.
	 * @param  array $invoice_data Mapped refund payload built by the caller.
	 * @return string|false The new Xero CreditNoteID, or false when it was not created.
	 */
	public function X_Add_CreditNote($refund_id,$invoice_data){
		if($this->is_plg_lc_p_l() || $this->is_plg_lc_p_r() || $this->is_plg_lc_p_empty()){
			return false;
		}
		
		if($refund_id > 0 && is_array($invoice_data) && !empty($invoice_data)){
			if($this->is_xero_connected()){
				$refund_data = $this->get_wc_order_details_from_order($refund_id);
				if(empty($refund_data)){
					return false;
				}

				if(!isset($invoice_data['X_Exists_Checked']) && $this->if_xero_refund_exists($refund_id,$invoice_data)){
					return false;
				}

				$lt = 'Refund';
				$ltt = 'Refund';
				$log_sync_type = 'Create';

				$wc_inv_id = (int) $this->get_array_isset($invoice_data,'wc_inv_id',0,false);
				$wc_inv_num = $this->get_array_isset($invoice_data,'wc_inv_num','');

				$ord_id_num = (!empty($wc_inv_num))?$wc_inv_num:$wc_inv_id;
				$DocNumber = $this->apply_order_number_prefix($ord_id_num);
				$CreditNoteNumber = $DocNumber.'-'.$refund_id;

				# Payment method map data
				$_payment_method = $this->get_array_isset($invoice_data,'_payment_method','',true);
				$_order_currency = $this->get_array_isset($invoice_data,'_order_currency','',true);
				$_currency_applicable = $_order_currency;

				# Empty/unmapped payment methods fall back to the "Other" mapping. Refs #78
				$pm_map_data = $this->get_pm_map_data_with_other_fallback($_payment_method,$_currency_applicable);
				$enable_refund = (int) $this->get_array_isset($pm_map_data,'enable_refund',0,false);

				if($enable_refund !== 1){
					return false;
				}

				# Xero Customer
				if(isset($invoice_data['X_ContactID']) && !empty($invoice_data['X_ContactID'])){
					$X_ContactID = $this->get_array_isset($invoice_data,'X_ContactID','');
				}else{
					$X_ContactID = $this->get_xero_customer_for_order_sync($invoice_data);
				}

				if(empty($X_ContactID)){
					$this->save_log(array('type'=>$lt,'title'=>$log_sync_type.' '.$ltt.' Error #'.$refund_id,'details'=>'Xero customer not found','status'=>0));
					return false;
				}

				$refund_date = $this->get_array_isset($refund_data,'wc_inv_date','');

				$CreditNote = new XeroAPI\XeroPHP\Models\Accounting\CreditNote;
				$CreditNote->setType(XeroAPI\XeroPHP\Models\Accounting\CreditNote::TYPE_ACCRECCREDIT);

				$CreditNote->setCreditNoteNumber($CreditNoteNumber);

				# Customer
				$Contact = new XeroAPI\XeroPHP\Models\Accounting\Contact;
				$Contact->setContactId($X_ContactID);
				$CreditNote->setContact($Contact);

				# Reference
				$CreditNote->setReference($DocNumber);

				# Dates
				$CreditNote->setDate(new DateTime($refund_date));

				$_paid_date = $this->get_array_isset($invoice_data,'_paid_date','');
				if(empty($_paid_date)){
					# For WooCommerce Unpaid Orders
					
					$xero_inv_status_for_unp_ord = $this->get_option('mw_wc_xero_sync_xero_inv_status_for_unp_ord');
					if(!empty($xero_inv_status_for_unp_ord)){					
						$CreditNote->setStatus($xero_inv_status_for_unp_ord);
					}

					# Send Email
					if($this->option_checked('mw_wc_xero_sync_send_invoice_email_after_sync')){
						$CreditNote->setSentToContact(true);
					}

				}else{
					$enable_payment = (int) $this->get_array_isset($pm_map_data,'enable_payment',0,false);
					if($enable_payment){
						$CreditNote->setStatus('AUTHORISED');
					}					
				}

				$Discount_Sync_Type = 'Per_Line_Item';# Per_Line_Item|Per_Order
				$Tax_Sync_Type = 'Xero_Tax';# Order_Line_Item|Xero_Tax

				if($this->option_checked('mw_wc_xero_sync_order_tax_as_li')){
					$Tax_Sync_Type = 'Order_Line_Item';
				}

				$_prices_include_tax = (isset($invoice_data['_prices_include_tax']) && $invoice_data['_prices_include_tax'] == 'yes')?true:false;
				if($_prices_include_tax){
					$CreditNote->setLineAmountTypes('Inclusive');
				}
				
				$tracking_category_data = [];
				if($this->is_plg_lc_p_g() || $this->is_plg_lc_p_s() || $this->is_plg_lc_p_sr()){
					$tracking_category_data = $this->get_order_sync_xero_tracking_category_data();
				}				
				
				$xero_inv_items = (isset($refund_data['xero_inv_items']))?$refund_data['xero_inv_items']:array();

				# Add CreditNote items
				$LineItems = array();
				if(is_array($xero_inv_items) && !empty($xero_inv_items)){
					foreach($xero_inv_items as $xi_k => $xero_item){
						if(empty($xero_item)){ continue; }
						$LineItem = new XeroAPI\XeroPHP\Models\Accounting\LineItem;
						$LineItem->setDescription(!empty($xero_item['Description']) ? $xero_item['Description'] : '-');
						$LineItem->setQuantity($xero_item['Qty']);

						$xero_item['UnitPrice'] = abs((float) $xero_item['UnitPrice']);

						if($_prices_include_tax){
							$xero_item['UnitPrice'] = $this->get_order_line_tax_inclusive_amount($xero_item,'line_item',true);
						}
						
						$LineItem->setUnitAmount($xero_item['UnitPrice']);
						if(!empty($xero_item['X_Code'])){ $LineItem->setItemCode($xero_item['X_Code']); }

						$AccountCode = (isset($xero_item['X_SD_AccountCode']) && !empty($xero_item['X_SD_AccountCode']))?$xero_item['X_SD_AccountCode']:'';

						$is_p_mapped_acc = false;

						# Variation -> Account Map
						if((int) $xero_item['variation_id'] > 0){
							$account_map_data = $this->get_mapping_data_from_table_multiple('variation','account',(int) $xero_item['variation_id']);
							if(is_array($account_map_data) && !empty($account_map_data) && isset($account_map_data['x_id']) && !empty($account_map_data['x_id'])){
								$AccountCode = $account_map_data['x_id'];
								$is_p_mapped_acc = true;
							}
						}

						# Product -> Account Map						
						if((int) $xero_item['variation_id'] < 1 && (int) $xero_item['product_id'] > 0){
							$account_map_data = $this->get_mapping_data_from_table_multiple('product','account',(int) $xero_item['product_id']);
							if(is_array($account_map_data) && !empty($account_map_data) && isset($account_map_data['x_id']) && !empty($account_map_data['x_id'])){
								$AccountCode = $account_map_data['x_id'];
								$is_p_mapped_acc = true;
							}
						}
						
						# Category -> Account Map
						
						if(!$is_p_mapped_acc && (int) $xero_item['product_id'] > 0){
							$terms = get_the_terms ( (int) $xero_item['product_id'], 'product_cat' );
							if(!empty($terms)){
								foreach ( $terms as $term ) {
									$cat_map_data = $this->get_row_by_val($this->gdtn('map_categories'),'W_CAT_ID',$term->term_id);
									if(is_array($cat_map_data) && !empty($cat_map_data)){
										if(!empty($cat_map_data['X_ACC_CODE'])){
											$AccountCode = $cat_map_data['X_ACC_CODE'];
											break;
										}
									}
								}
							}
						}
						
						if(!empty($AccountCode)){
							$LineItem->setAccountCode($AccountCode);
						}
						
						# Discount Per Line
						if($Discount_Sync_Type == 'Per_Line_Item'){
							$DiscountAmount = ($xero_item['line_subtotal'] - $xero_item['line_total']);
							$DiscountAmount = abs((float) $DiscountAmount);
							if($DiscountAmount > 0){
								// Xero rejects DiscountRate >= 1.0 (100%). When fully discounted, set unit price to 0 instead.
								if($DiscountAmount >= ((float)$xero_item['UnitPrice'] * (float)$xero_item['Qty'])){
									$LineItem->setUnitAmount(0);
								} else {
									$LineItem->setDiscountAmount($DiscountAmount);
								}
							}
						}
						
						# Line Tax
						if($Tax_Sync_Type == 'Xero_Tax'){
							$TaxType = $this->get_xero_tax_code_from_line_tax_data($xero_item);							
						}else{
							$TaxType = $this->get_xero_non_taxable_tax_code();
						}

						if(!empty($TaxType)){
							$LineItem->setTaxType($TaxType);
						}

						# Tracking
						if(!empty($tracking_category_data)){
							$Tracking = new XeroAPI\XeroPHP\Models\Accounting\LineItemTracking;
							$Tracking->setTrackingCategoryId($tracking_category_data['tracking_category_id']);
							$Tracking->setTrackingOptionId($tracking_category_data['tracking_option_id']);
							$Tracking->setName($tracking_category_data['name']);
							$Tracking->setOption($tracking_category_data['option']);
							$LineItem->setTracking([$Tracking]);
						}

						$LineItems[] = $LineItem;
					}
				}

				# Discount Line
				if($Discount_Sync_Type == 'Per_Order'){
					#->
				}

				# Fee Line
				if($this->is_sync_fee_line()){
					$dc_gt_fees = (isset($refund_data['dc_gt_fees']))?$refund_data['dc_gt_fees']:array();
					if(is_array($dc_gt_fees) && !empty($dc_gt_fees)){
						$dxtp_code = '';
						$dxtp_id = $this->get_option('mw_wc_xero_sync_fee_line_item_xero_product');
						if(empty($dxtp_id)){
							$dxtp_id = $this->get_option('mw_wc_xero_sync_default_xero_product');
						}
						
						if(!empty($dxtp_id)){
							$X_P_L_D = $this->get_xero_product_line_item_data($dxtp_id);
							$dxtp_code = $X_P_L_D['Code'];							
						}

						if(!empty($dxtp_code)){
							foreach($dc_gt_fees as $df){
								$amount = (float) $df['_line_total'];
								$amount = abs($amount);

								if($_prices_include_tax){
									$amount = $this->get_order_line_tax_inclusive_amount($df,'fee',true);
								}

								$description = $df['name'];
								if(isset($df['_wc_checkout_add_on_value']) && !empty($df['_wc_checkout_add_on_value'])){
									$description .= ' - '.$df['_wc_checkout_add_on_value'];
								}
								if(empty($description)){ $description = '-'; }
	
								$LineItem = new XeroAPI\XeroPHP\Models\Accounting\LineItem;
								$LineItem->setDescription($description);
								$LineItem->setUnitAmount($amount);							
								$LineItem->setLineAmount($amount);
								$LineItem->setItemCode($dxtp_code);

								$AccountCode = (isset($X_P_L_D['SD_AccountCode']) && !empty($X_P_L_D['SD_AccountCode']))?$X_P_L_D['SD_AccountCode']:'';
								if(!empty($AccountCode)){
									$LineItem->setAccountCode($AccountCode);
								}
								
								# Line Tax
								if($Tax_Sync_Type == 'Xero_Tax'){
									$TaxType = $this->get_xero_tax_code_from_line_tax_data($df,'fee');								
								}else{
									$TaxType = $this->get_xero_non_taxable_tax_code();
								}

								if(!empty($TaxType)){
									$LineItem->setTaxType($TaxType);
								}

								# Tracking
								if(!empty($tracking_category_data)){
									$Tracking = new XeroAPI\XeroPHP\Models\Accounting\LineItemTracking;
									$Tracking->setTrackingCategoryId($tracking_category_data['tracking_category_id']);
									$Tracking->setTrackingOptionId($tracking_category_data['tracking_option_id']);
									$Tracking->setName($tracking_category_data['name']);
									$Tracking->setOption($tracking_category_data['option']);
									$LineItem->setTracking([$Tracking]);
								}

								$LineItems[] = $LineItem;
							}
						}						
					}
				}
				
				# Shipping Line
				$shipping_details = (isset($refund_data['shipping_details']))?$refund_data['shipping_details']:array();
				if(is_array($shipping_details) && !empty($shipping_details)){
					$dxsp_code = '';
					$dxsp_id = $this->get_option('mw_wc_xero_sync_default_xero_shipping_product');
					if(empty($dxsp_id)){
						$dxsp_id = $this->get_option('mw_wc_xero_sync_default_xero_product');
					}

					if(!empty($dxsp_id)){
						$X_P_L_D = $this->get_xero_product_line_item_data($dxsp_id);
						$dxsp_code = $X_P_L_D['Code'];
					}
					
					if(!empty($dxsp_code)){
						foreach($shipping_details as $sk => $sd){
							$shipping_method_name =  $this->get_array_isset($sd,'name','');
							$description = (!empty($shipping_method_name))?'Shipping ('.$shipping_method_name.')':'Shipping';
							$shipping_amount = (float) $this->get_array_isset($sd,'cost',0,false);
							$shipping_amount = abs($shipping_amount);

							if($_prices_include_tax){
								$shipping_amount = $this->get_order_line_tax_inclusive_amount($sd,'shipping',true);
							}
							
							$LineItem = new XeroAPI\XeroPHP\Models\Accounting\LineItem;
							$LineItem->setDescription($description);
							#$LineItem->setQuantity(1);
							$LineItem->setUnitAmount($shipping_amount);
							$LineItem->setLineAmount($shipping_amount);
							$LineItem->setItemCode($dxsp_code);

							$AccountCode = (isset($X_P_L_D['SD_AccountCode']) && !empty($X_P_L_D['SD_AccountCode']))?$X_P_L_D['SD_AccountCode']:'';
							if(!empty($AccountCode)){
								$LineItem->setAccountCode($AccountCode);
							}
							
							# Line Tax
							if($Tax_Sync_Type == 'Xero_Tax'){
								$TaxType = $this->get_xero_tax_code_from_line_tax_data($sd,'shipping');								
							}else{
								$TaxType = $this->get_xero_non_taxable_tax_code();
							}

							if(!empty($TaxType)){
								$LineItem->setTaxType($TaxType);
							}

							# Tracking
							if(!empty($tracking_category_data)){
								$Tracking = new XeroAPI\XeroPHP\Models\Accounting\LineItemTracking;
								$Tracking->setTrackingCategoryId($tracking_category_data['tracking_category_id']);
								$Tracking->setTrackingOptionId($tracking_category_data['tracking_option_id']);
								$Tracking->setName($tracking_category_data['name']);
								$Tracking->setOption($tracking_category_data['option']);
								$LineItem->setTracking([$Tracking]);
							}

							$LineItems[] = $LineItem;
						}
					}					
				}

				# Tax Line
				if($Tax_Sync_Type == 'Order_Line_Item'){
					$tax_details = (isset($refund_data['tax_details']))?$refund_data['tax_details']:array();
					if(is_array($tax_details) && !empty($tax_details)){
						$dxtp_code = '';
						$dxtp_id = $this->get_option('mw_wc_xero_sync_otli_xero_product');
						if(empty($dxtp_id)){
							$dxtp_id = $this->get_option('mw_wc_xero_sync_default_xero_product');
						}
						
						if(!empty($dxtp_id)){
							$X_P_L_D = $this->get_xero_product_line_item_data($dxtp_id);
							$dxtp_code = $X_P_L_D['Code'];							
						}

						if(!empty($dxtp_code)){
							foreach($tax_details as $tk => $td){
								$description = $td['label'];
								if(!empty($td['name'])){
									$description.= ' - '.$td['name'];
								}
								if(empty($description)){ $description = '-'; }

								$tax_amount = (float) $this->get_array_isset($td,'tax_amount',0,false);
								$tax_amount = abs($tax_amount);
								$shipping_tax_amount = (float) $this->get_array_isset($td,'shipping_tax_amount',0,false);
								$shipping_tax_amount = abs($shipping_tax_amount);

								$LineItem = new XeroAPI\XeroPHP\Models\Accounting\LineItem;
								$LineItem->setDescription($description);
								#$LineItem->setQuantity(1);
								$LineItem->setUnitAmount($tax_amount+$shipping_tax_amount);
								$LineItem->setLineAmount($tax_amount+$shipping_tax_amount);
								$LineItem->setItemCode($dxtp_code);

								$AccountCode = (isset($X_P_L_D['SD_AccountCode']) && !empty($X_P_L_D['SD_AccountCode']))?$X_P_L_D['SD_AccountCode']:'';
								if(!empty($AccountCode)){
									$LineItem->setAccountCode($AccountCode);
								}

								# Line Tax
								$TaxType = $this->get_xero_non_taxable_tax_code();
								if(!empty($TaxType)){
									$LineItem->setTaxType($TaxType);
								}

								# Tracking
								if(!empty($tracking_category_data)){
									$Tracking = new XeroAPI\XeroPHP\Models\Accounting\LineItemTracking;
									$Tracking->setTrackingCategoryId($tracking_category_data['tracking_category_id']);
									$Tracking->setTrackingOptionId($tracking_category_data['tracking_option_id']);
									$Tracking->setName($tracking_category_data['name']);
									$Tracking->setOption($tracking_category_data['option']);
									$LineItem->setTracking([$Tracking]);
								}

								$LineItems[] = $LineItem;
							}
						}
					}
				}

				#->
				if(!empty($LineItems)){
					$CreditNote->setLineItems($LineItems);
				}

				# Currency
				$CreditNote->setCurrencyCode($_currency_applicable);

				$Arr_CreditNotes = array();
				array_push($Arr_CreditNotes, $CreditNote);
				
				$CreditNotes = new XeroAPI\XeroPHP\Models\Accounting\CreditNotes;
				$CreditNotes->setCreditNotes($Arr_CreditNotes);

				#Debug
				#$this->_p($invoice_data);
				#$this->_p($refund_data);			
				#$this->_p($CreditNote);
				#return;

				try{
					# Add
					$this->add_wc_debug_log("=== XERO CREATE CREDIT NOTE REQUEST ===\nTenant ID: " . $this->XeroTenantId . "\nRequest Data:", true, false, false);
					$this->add_wc_debug_log($CreditNotes, true, false, false);
					
					// Check and respect rate limits before API call
					if($this->should_delay_api_call()) {
						$this->add_wc_debug_log("Rate limit check: Delaying credit note creation due to rate limits", true, false, false);
						sleep(2); // 2 second delay
					}
					
					$result = $this->X_API_I()->createCreditNotes($this->XeroTenantId,$CreditNotes);
					
					// Note: API usage tracking is now handled automatically by the Guzzle middleware
					$this->add_wc_debug_log("=== XERO CREATE CREDIT NOTE RESPONSE ===", true, false, false);
					$this->add_wc_debug_log($result, true, false, false);

					#$this->add_text_into_log_file($CreditNote);
					#$this->add_text_into_log_file($result);
					#$this->_p($result);

					if(!empty($result)){
						$xr_credit_notes = $result->getCreditNotes();
						if(is_array($xr_credit_notes) && !empty($xr_credit_notes)){
							$x_credit_note = $xr_credit_notes[0];

							if(!$x_credit_note->getHasErrors()){
								$X_CreditNoteID = $x_credit_note->getCreditNoteID();
								if(!empty($X_CreditNoteID) && $X_CreditNoteID != '00000000-0000-0000-0000-000000000000'){
									$ld = "{$ltt} #{$refund_id} added into Xero successfully".PHP_EOL;
									$ld.="Xero CreditNote ID #".$X_CreditNoteID;									

									$this->save_log(
										array(
											'type'=>$lt,
											'title'=>'Create '.$ltt.' #'.$refund_id,
											'details'=>$ld,
											'status'=>1,
											'wc_id'=>$refund_id,
											'xero_id'=>$X_CreditNoteID,
											)
									);
									
									return $X_CreditNoteID;
								}
							}else{
								# Error
								# status_attribute_string ,warnings
								$validation_errors = $x_credit_note->getValidationErrors();
								
								$ld = $this->append_line_account_code_hint($this->get_error_message_from_xero_validation_errors($validation_errors));
								if(!empty($ld)){
									$this->save_log(array('type'=>$lt,'title'=>'Create '.$ltt.' Error #'.$refund_id,'details'=>$ld,'status'=>0,'wc_id'=>$refund_id));
								}								

								return false;
							}							
						}
					}

				}catch (\XeroAPI\XeroPHP\ApiException $e) {
					$this->add_wc_debug_log("=== XERO API ERROR ===\nHTTP Code: " . $e->getCode() . "\nResponse Body: " . $e->getResponseBody(), true, false, false);
					$error = AccountingObjectSerializer::deserialize($e->getResponseBody(), '\XeroAPI\XeroPHP\Models\Accounting\Error',[]);

					$ld = $this->append_line_account_code_hint($this->get_error_message_from_xero_error_object($error));
					if(!empty($ld)){
						$this->save_log(array('type'=>$lt,'title'=>'Create '.$ltt.' Error #'.$refund_id,'details'=>$ld,'status'=>0,'wc_id'=>$refund_id));
					}					

					return false;
				}catch (\InvalidArgumentException $e) {
					# The SDK json_encode()s the payload and throws GuzzleHttp\Exception\InvalidArgumentException
					# (a \InvalidArgumentException) when a string is not valid UTF-8. Uncaught, that reaches
					# wp-admin as a "critical error" instead of a sync failure. Strings are cleaned upstream in
					# get_array_isset(); this is the same backstop X_Add_Product() carries. Refs #148
					$this->save_log(array('type'=>$lt,'title'=>'Create '.$ltt.' Error #'.$refund_id,'details'=>'The '.strtolower($lt).' data could not be encoded for Xero: '.$e->getMessage(),'status'=>0,'wc_id'=>$refund_id));

					return false;
				}
			}
		}

		return false;
	}

	public function X_Add_Invoice($invoice_data){
		return $this->X_Add_Update_Invoice($invoice_data);
	}

	public function X_Update_Invoice($invoice_data, $xero_invoice_object){
		return $this->X_Add_Update_Invoice($invoice_data,true,$xero_invoice_object);
	}

	/**
	 * Create or update the Xero invoice for a WooCommerce order.
	 *
	 * Callers must hold the order push lock (see acquire_order_push_lock()) so two paths cannot
	 * create two invoices for one order. Refs #146
	 *
	 * @param  array  $invoice_data        Mapped order payload built by the caller.
	 * @param  bool   $is_update           True to update $xero_invoice_object instead of creating.
	 * @param  object $xero_invoice_object Existing Xero invoice, required when $is_update is true.
	 * @return string|false The Xero InvoiceID, or false when the invoice was not written.
	 */
	public function X_Add_Update_Invoice($invoice_data, $is_update=false, $xero_invoice_object=null){
		if(is_array($invoice_data) && !empty($invoice_data)){
			if($this->is_xero_connected()){
				# The duplicate-invoice guard. Three outcomes, and they must not be conflated:
				#   a truthy id -> already in Xero, don't create
				#   false       -> genuinely absent, safe to create
				#   null        -> the lookup FAILED, so absence is unknown. Creating now is how a Xero
				#                  outage becomes a duplicate invoice, so refuse and let it retry.
				# The old single condition treated null as false, because null is falsey. Refs #87
				if(!$is_update){
					$xero_invoice_id = $this->if_xero_invoice_exists($invoice_data);

					if($xero_invoice_id === null){
						return false;
					}

					if($xero_invoice_id){
						return false;
					}
				}

				$lt = 'Order';
				$ltt = 'Order';# Invoice
				$log_sync_type = ($is_update)?'Update':'Create';
				
				$wc_inv_id = (int) $this->get_array_isset($invoice_data,'wc_inv_id',0,false);
				$wc_inv_num = $this->get_array_isset($invoice_data,'wc_inv_num','');

				$ord_id_num = (!empty($wc_inv_num))?$wc_inv_num:$wc_inv_id;
				$DocNumber = $this->apply_order_number_prefix($ord_id_num);

				# Payment method map data
				$_payment_method = $this->get_array_isset($invoice_data,'_payment_method','',true);
				$_order_currency = $this->get_array_isset($invoice_data,'_order_currency','',true);
				$_currency_applicable = $_order_currency;

				$pm_map_data = $this->get_mapped_payment_method_data($_payment_method,$_currency_applicable);

				# Xero Customer
				if(isset($invoice_data['X_ContactID']) && !empty($invoice_data['X_ContactID'])){
					$X_ContactID = $this->get_array_isset($invoice_data,'X_ContactID','');
				}else{
					$X_ContactID = $this->get_xero_customer_for_order_sync($invoice_data);
				}

				if(empty($X_ContactID)){
					$this->save_log(array('type'=>$lt,'title'=>$log_sync_type.' '.$lt.' Error #'.$ord_id_num,'details'=>'Xero customer not found','status'=>0,'wc_id'=>$wc_inv_id));
					return false;
				}

				if($is_update && (!is_object($xero_invoice_object) || empty($xero_invoice_object))){
					return false;
				}

				$wc_inv_date = $this->get_array_isset($invoice_data,'wc_inv_date','');
				#$wc_inv_date = $this->format_date($wc_inv_date);
				
				$wc_inv_due_date = $wc_inv_date;
				$inv_due_date_days = (int) $this->get_array_isset($pm_map_data,'x_invoice_ddd',0,false);

				if(!empty($wc_inv_date) && $inv_due_date_days > 0){
					$wc_inv_due_date = gmdate('Y-m-d',strtotime($wc_inv_date . "+{$inv_due_date_days} days"));
				}

				$is_update_and_paid_invoice = false;
				$xero_line_items = [];
				if($is_update){
					if($this->if_xero_payment_exists($invoice_data,$xero_invoice_object)){
						$is_update_and_paid_invoice = true;
					}

					$Invoice = $xero_invoice_object;
					if($Invoice->getContact()->getContactId() != $X_ContactID){
						$this->save_log(array('type'=>$lt,'title'=>$log_sync_type.' '.$lt.' Error #'.$ord_id_num,'details'=>'Customer Mismatch','status'=>0,'wc_id'=>$wc_inv_id));
						return false;
					}

					$xero_line_items = $xero_invoice_object->getLineItems();
					# Unset Lines					
					$Invoice->offsetUnset('line_items');

					# Unset Amounts
					$Invoice->offsetUnset('sub_total');
					$Invoice->offsetUnset('total_tax');
					$Invoice->offsetUnset('total');
					$Invoice->offsetUnset('total_discount');
					$Invoice->offsetUnset('is_discounted');
					$Invoice->offsetUnset('amount_due');			

				}else{
					$Invoice = new XeroAPI\XeroPHP\Models\Accounting\Invoice;
					$Invoice->setType(XeroAPI\XeroPHP\Models\Accounting\Invoice::TYPE_ACCREC);

					# Next Xero Order Number
					if(!$this->use_next_xero_order_number()){
						$Invoice->setInvoiceNumber($DocNumber);
					}

					# Customer
					$Contact = new XeroAPI\XeroPHP\Models\Accounting\Contact;
					$Contact->setContactId($X_ContactID);
					$Invoice->setContact($Contact);
				}			
				
				# Reference
				$Invoice->setReference($DocNumber);
				
				# Dates
				if(!$is_update_and_paid_invoice){
					$Invoice->setDate(new DateTime($wc_inv_date));
					$Invoice->setDueDate(new DateTime($wc_inv_due_date));
				}				

				# URL
				$wc_order_url = admin_url('post.php?post='.$wc_inv_id.'&action=edit');
				if(!empty($wc_order_url)){
					if(strpos(strtolower($wc_order_url), 'xero') === false) {
						$Invoice->setUrl($wc_order_url);
					}
				}				
				
				#_date_paid
				$_paid_date = $this->get_array_isset($invoice_data,'_paid_date','');

				# Status
				if(!$is_update_and_paid_invoice){
					$xero_inv_status_for_unp_ord = $this->get_option('mw_wc_xero_sync_xero_inv_status_for_unp_ord');
					if(empty($_paid_date)){
						# For WooCommerce Unpaid Orders

						if(!empty($xero_inv_status_for_unp_ord)){
							$Invoice->setStatus($xero_inv_status_for_unp_ord);
						}

						# Send Email
						if($this->option_checked('mw_wc_xero_sync_send_invoice_email_after_sync')){
							$Invoice->setSentToContact(true);
						}

					}else{
						$enable_payment = (int) $this->get_array_isset($pm_map_data,'enable_payment',0,false);
						if($enable_payment){
							$Invoice->setStatus('AUTHORISED');
						} elseif(!empty($xero_inv_status_for_unp_ord)){
							# Payment sync disabled — apply the configured invoice status setting
							$Invoice->setStatus($xero_inv_status_for_unp_ord);
						}
					}
				}
				
				$Discount_Sync_Type = 'Per_Line_Item';# Per_Line_Item|Per_Order
				$Tax_Sync_Type = 'Xero_Tax';# Order_Line_Item|Xero_Tax

				if($this->option_checked('mw_wc_xero_sync_order_tax_as_li')){
					$Tax_Sync_Type = 'Order_Line_Item';
				}

				$_prices_include_tax = (isset($invoice_data['_prices_include_tax']) && $invoice_data['_prices_include_tax'] == 'yes')?true:false;
				if($_prices_include_tax){
					$Invoice->setLineAmountTypes('Inclusive');
				}

				# Branding Theme
				# Same plan rule as the Settings > Order field's locked/disabled state, through the one
				# shared predicate so the two cannot drift apart. Refs #57
				if($this->is_plg_lc_p_r_up()){
					$branding_theme_id = $this->get_option('mw_wc_xero_sync_default_branding_theme');

					# Nothing chosen means "leave it to Xero", which applies the org's default theme.
					# Skipped on an invoice Xero has already taken payment against, the same way date,
					# due date and status are above - re-branding a paid invoice is not what the
					# setting asks for.
					if(!empty($branding_theme_id) && !$is_update_and_paid_invoice){
						# Verified against the cached list rather than sent blind: Xero rejects the
						# whole invoice when the BrandingThemeID is unknown, so a theme deleted in Xero
						# after it was picked here would fail the order sync over a cosmetic setting.
						# An empty list means the lookup failed or the org has none - either way skip
						# the theme rather than block the sync on it.
						$branding_themes = $this->xero_get_branding_themes_kva();
						if(is_array($branding_themes) && isset($branding_themes[$branding_theme_id])){
							$Invoice->setBrandingThemeId($branding_theme_id);
						}
					}
				}
				
				$tracking_category_data = [];
				$cf_tracking_override = [];
				if($this->is_plg_lc_p_g() || $this->is_plg_lc_p_s() || $this->is_plg_lc_p_sr()){
					$tracking_category_data = $this->get_order_sync_xero_tracking_category_data();
				}

				$xero_inv_items = (isset($invoice_data['xero_inv_items']))?$invoice_data['xero_inv_items']:array();

				# Add Invoice items
				$LineItems = array();
				if(is_array($xero_inv_items) && !empty($xero_inv_items)){
					foreach($xero_inv_items as $xi_k => $xero_item){
						if(empty($xero_item)){ continue; }
						$LineItem = new XeroAPI\XeroPHP\Models\Accounting\LineItem;
						$LineItem->setDescription(!empty($xero_item['Description']) ? $xero_item['Description'] : '-');
						$LineItem->setQuantity($xero_item['Qty']);

						if($_prices_include_tax){
							$xero_item['UnitPrice'] = $this->get_order_line_tax_inclusive_amount($xero_item,'line_item');
						}
						
						$LineItem->setUnitAmount($xero_item['UnitPrice']);
						if(!empty($xero_item['X_Code'])){ $LineItem->setItemCode($xero_item['X_Code']); }

						$AccountCode = (isset($xero_item['X_SD_AccountCode']) && !empty($xero_item['X_SD_AccountCode']))?$xero_item['X_SD_AccountCode']:'';

						$is_p_mapped_acc = false;

						# Variation -> Account Map
						if((int) $xero_item['variation_id'] > 0){
							$account_map_data = $this->get_mapping_data_from_table_multiple('variation','account',(int) $xero_item['variation_id']);
							if(is_array($account_map_data) && !empty($account_map_data) && isset($account_map_data['x_id']) && !empty($account_map_data['x_id'])){
								$AccountCode = $account_map_data['x_id'];
								$is_p_mapped_acc = true;
							}
						}

						# Product -> Account Map						
						if((int) $xero_item['variation_id'] < 1 && (int) $xero_item['product_id'] > 0){
							$account_map_data = $this->get_mapping_data_from_table_multiple('product','account',(int) $xero_item['product_id']);
							if(is_array($account_map_data) && !empty($account_map_data) && isset($account_map_data['x_id']) && !empty($account_map_data['x_id'])){
								$AccountCode = $account_map_data['x_id'];
								$is_p_mapped_acc = true;
							}
						}
						
						# Category -> Account Map
						
						if(!$is_p_mapped_acc && (int) $xero_item['product_id'] > 0){
							$terms = get_the_terms ( (int) $xero_item['product_id'], 'product_cat' );
							if(!empty($terms)){
								foreach ( $terms as $term ) {
									$cat_map_data = $this->get_row_by_val($this->gdtn('map_categories'),'W_CAT_ID',$term->term_id);
									if(is_array($cat_map_data) && !empty($cat_map_data)){
										if(!empty($cat_map_data['X_ACC_CODE'])){
											$AccountCode = $cat_map_data['X_ACC_CODE'];
											break;
										}
									}
								}
							}
						}
						
						if(!empty($AccountCode)){
							$LineItem->setAccountCode($AccountCode);
						}
						
						# Discount Per Line
						if($Discount_Sync_Type == 'Per_Line_Item'){
							if($_prices_include_tax){
								# For tax-inclusive orders, calculate discount including tax to match WooCommerce logic
								$total_before_discount = ($xero_item['line_subtotal'] + $xero_item['line_subtotal_tax']);
								$total_after_discount = ($xero_item['line_total'] + $xero_item['line_tax']);
								$DiscountAmount = ($total_before_discount - $total_after_discount);
							} else {
								# For tax-exclusive orders, use original calculation
								$DiscountAmount = ($xero_item['line_subtotal'] - $xero_item['line_total']);
							}
							if($DiscountAmount > 0){
								// Xero rejects DiscountRate >= 1.0 (100%). When fully discounted, set unit price to 0 instead.
								if($DiscountAmount >= ((float)$xero_item['UnitPrice'] * (float)$xero_item['Qty'])){
									$LineItem->setUnitAmount(0);
								} else {
									$LineItem->setDiscountAmount($DiscountAmount);
								}
							}
						}
						
						# Line Tax
						if($Tax_Sync_Type == 'Xero_Tax'){
							$TaxType = $this->get_xero_tax_code_from_line_tax_data($xero_item);							
						}else{
							$TaxType = $this->get_xero_non_taxable_tax_code();
						}

						if(!empty($TaxType)){
							$LineItem->setTaxType($TaxType);
						}

						# Tracking
						if(!empty($tracking_category_data)){
							$Tracking = new XeroAPI\XeroPHP\Models\Accounting\LineItemTracking;
							$Tracking->setTrackingCategoryId($tracking_category_data['tracking_category_id']);
							$Tracking->setTrackingOptionId($tracking_category_data['tracking_option_id']);
							$Tracking->setName($tracking_category_data['name']);
							$Tracking->setOption($tracking_category_data['option']);
							$LineItem->setTracking([$Tracking]);
						}

						$LineItems[] = $LineItem;
					}
				}

				# Discount Line
				if($Discount_Sync_Type == 'Per_Order'){
					#->
				}

				# Fee Line
				if($this->is_sync_fee_line()){
					$dc_gt_fees = (isset($invoice_data['dc_gt_fees']))?$invoice_data['dc_gt_fees']:array();
					if(is_array($dc_gt_fees) && !empty($dc_gt_fees)){
						$dxtp_code = '';
						$dxtp_id = $this->get_option('mw_wc_xero_sync_fee_line_item_xero_product');
						if(empty($dxtp_id)){
							$dxtp_id = $this->get_option('mw_wc_xero_sync_default_xero_product');
						}
						
						if(!empty($dxtp_id)){
							$X_P_L_D = $this->get_xero_product_line_item_data($dxtp_id);
							$dxtp_code = $X_P_L_D['Code'];							
						}

						if(!empty($dxtp_code)){
							foreach($dc_gt_fees as $df){
								$amount = $df['_line_total'];

								if($_prices_include_tax){
									$amount = $this->get_order_line_tax_inclusive_amount($df,'fee');
								}

								$description = $df['name'];
								if(isset($df['_wc_checkout_add_on_value']) && !empty($df['_wc_checkout_add_on_value'])){
									$description .= ' - '.$df['_wc_checkout_add_on_value'];
								}
								if(empty($description)){ $description = '-'; }
	
								$LineItem = new XeroAPI\XeroPHP\Models\Accounting\LineItem;
								$LineItem->setDescription($description);
								$LineItem->setUnitAmount($amount);							
								$LineItem->setLineAmount($amount);
								$LineItem->setItemCode($dxtp_code);

								$AccountCode = (isset($X_P_L_D['SD_AccountCode']) && !empty($X_P_L_D['SD_AccountCode']))?$X_P_L_D['SD_AccountCode']:'';
								if(!empty($AccountCode)){
									$LineItem->setAccountCode($AccountCode);
								}
								
								# Line Tax
								if($Tax_Sync_Type == 'Xero_Tax'){
									$TaxType = $this->get_xero_tax_code_from_line_tax_data($df,'fee');								
								}else{
									$TaxType = $this->get_xero_non_taxable_tax_code();
								}

								if(!empty($TaxType)){
									$LineItem->setTaxType($TaxType);
								}

								# Tracking
								if(!empty($tracking_category_data)){
									$Tracking = new XeroAPI\XeroPHP\Models\Accounting\LineItemTracking;
									$Tracking->setTrackingCategoryId($tracking_category_data['tracking_category_id']);
									$Tracking->setTrackingOptionId($tracking_category_data['tracking_option_id']);
									$Tracking->setName($tracking_category_data['name']);
									$Tracking->setOption($tracking_category_data['option']);
									$LineItem->setTracking([$Tracking]);
								}

								$LineItems[] = $LineItem;
							}
						}						
					}
				}
				
				# Shipping Line
				$shipping_details = (isset($invoice_data['shipping_details']))?$invoice_data['shipping_details']:array();
				if(is_array($shipping_details) && !empty($shipping_details)){
					$dxsp_code = '';
					$dxsp_id = $this->get_option('mw_wc_xero_sync_default_xero_shipping_product');
					if(empty($dxsp_id)){
						$dxsp_id = $this->get_option('mw_wc_xero_sync_default_xero_product');
					}

					if(!empty($dxsp_id)){
						$X_P_L_D = $this->get_xero_product_line_item_data($dxsp_id);
						$dxsp_code = $X_P_L_D['Code'];
					}
					
					if(!empty($dxsp_code)){
						foreach($shipping_details as $sk => $sd){
							$shipping_method_name =  $this->get_array_isset($sd,'name','');
							$description = (!empty($shipping_method_name))?'Shipping ('.$shipping_method_name.')':'Shipping';
							$shipping_amount = (float) $this->get_array_isset($sd,'cost',0,false);

							if($_prices_include_tax){
								$shipping_amount = $this->get_order_line_tax_inclusive_amount($sd,'shipping');
							}
							
							$LineItem = new XeroAPI\XeroPHP\Models\Accounting\LineItem;
							$LineItem->setDescription($description);
							#$LineItem->setQuantity(1);
							$LineItem->setUnitAmount($shipping_amount);
							$LineItem->setLineAmount($shipping_amount);
							$LineItem->setItemCode($dxsp_code);

							$AccountCode = (isset($X_P_L_D['SD_AccountCode']) && !empty($X_P_L_D['SD_AccountCode']))?$X_P_L_D['SD_AccountCode']:'';
							if(!empty($AccountCode)){
								$LineItem->setAccountCode($AccountCode);
							}
							
							# Line Tax
							if($Tax_Sync_Type == 'Xero_Tax'){
								$TaxType = $this->get_xero_tax_code_from_line_tax_data($sd,'shipping');								
							}else{
								$TaxType = $this->get_xero_non_taxable_tax_code();
							}

							if(!empty($TaxType)){
								$LineItem->setTaxType($TaxType);
							}

							# Tracking
							if(!empty($tracking_category_data)){
								$Tracking = new XeroAPI\XeroPHP\Models\Accounting\LineItemTracking;
								$Tracking->setTrackingCategoryId($tracking_category_data['tracking_category_id']);
								$Tracking->setTrackingOptionId($tracking_category_data['tracking_option_id']);
								$Tracking->setName($tracking_category_data['name']);
								$Tracking->setOption($tracking_category_data['option']);
								$LineItem->setTracking([$Tracking]);
							}

							$LineItems[] = $LineItem;
						}
					}					
				}

				# Tax Line
				if($Tax_Sync_Type == 'Order_Line_Item'){
					$tax_details = (isset($invoice_data['tax_details']))?$invoice_data['tax_details']:array();
					if(is_array($tax_details) && !empty($tax_details)){
						$dxtp_code = '';
						$dxtp_id = $this->get_option('mw_wc_xero_sync_otli_xero_product');
						if(empty($dxtp_id)){
							$dxtp_id = $this->get_option('mw_wc_xero_sync_default_xero_product');
						}
						
						if(!empty($dxtp_id)){
							$X_P_L_D = $this->get_xero_product_line_item_data($dxtp_id);
							$dxtp_code = $X_P_L_D['Code'];							
						}

						if(!empty($dxtp_code)){
							foreach($tax_details as $tk => $td){
								$description = $td['label'];
								if(!empty($td['name'])){
									$description.= ' - '.$td['name'];
								}
								if(empty($description)){ $description = '-'; }

								$tax_amount = (float) $this->get_array_isset($td,'tax_amount',0,false);
								$shipping_tax_amount = (float) $this->get_array_isset($td,'shipping_tax_amount',0,false);

								$LineItem = new XeroAPI\XeroPHP\Models\Accounting\LineItem;
								$LineItem->setDescription($description);
								#$LineItem->setQuantity(1);
								$LineItem->setUnitAmount($tax_amount+$shipping_tax_amount);
								$LineItem->setLineAmount($tax_amount+$shipping_tax_amount);
								$LineItem->setItemCode($dxtp_code);

								$AccountCode = (isset($X_P_L_D['SD_AccountCode']) && !empty($X_P_L_D['SD_AccountCode']))?$X_P_L_D['SD_AccountCode']:'';
								if(!empty($AccountCode)){
									$LineItem->setAccountCode($AccountCode);
								}

								# Line Tax
								$TaxType = $this->get_xero_non_taxable_tax_code();
								if(!empty($TaxType)){
									$LineItem->setTaxType($TaxType);
								}

								# Tracking
								if(!empty($tracking_category_data)){
									$Tracking = new XeroAPI\XeroPHP\Models\Accounting\LineItemTracking;
									$Tracking->setTrackingCategoryId($tracking_category_data['tracking_category_id']);
									$Tracking->setTrackingOptionId($tracking_category_data['tracking_option_id']);
									$Tracking->setName($tracking_category_data['name']);
									$Tracking->setOption($tracking_category_data['option']);
									$LineItem->setTracking([$Tracking]);
								}

								$LineItems[] = $LineItem;
							}
						}
					}
				}

				# Txn fee line
				$enable_txn_fee = (int) $this->get_array_isset($pm_map_data,'enable_txn_fee',0,false);
				if($enable_txn_fee){
					$txn_fee_data = $this->get_txn_fee_data_from_order($invoice_data);
					$txn_fee_desc = $txn_fee_data['t_f_desc'];
					$txn_fee_amount = (float) $txn_fee_data['t_f_amnt'];

					if($txn_fee_amount > 0){
						$dxtfp_code = '';
						$dxtfp_id = $this->get_array_isset($pm_map_data,'txn_fee_x_product','');
						if(empty($dxtfp_id)){
							$dxtfp_id = $this->get_option('mw_wc_xero_sync_default_xero_product');
						}
						
						if(!empty($dxtfp_id)){
							$X_P_L_D = $this->get_xero_product_line_item_data($dxtfp_id);
							$dxtfp_code = $X_P_L_D['Code'];							
						}

						if(!empty($dxtfp_code)){
							$txn_fee_amount = -1 * abs($txn_fee_amount);
							$description = $txn_fee_desc;
							if(empty($description)){ $description = '-'; }

							$LineItem = new XeroAPI\XeroPHP\Models\Accounting\LineItem;
							$LineItem->setDescription($description);
							#$LineItem->setQuantity(1);
							$LineItem->setUnitAmount($txn_fee_amount);
							$LineItem->setLineAmount($txn_fee_amount);
							$LineItem->setItemCode($dxtfp_code);

							$AccountCode = (isset($X_P_L_D['SD_AccountCode']) && !empty($X_P_L_D['SD_AccountCode']))?$X_P_L_D['SD_AccountCode']:'';
							if(!empty($AccountCode)){
								$LineItem->setAccountCode($AccountCode);
							}

							# Line Tax
							$TaxType = $this->get_xero_non_taxable_tax_code();
							if(!empty($TaxType)){
								$LineItem->setTaxType($TaxType);
							}

							# Tracking
							if(!empty($tracking_category_data)){
								$Tracking = new XeroAPI\XeroPHP\Models\Accounting\LineItemTracking;
								$Tracking->setTrackingCategoryId($tracking_category_data['tracking_category_id']);
								$Tracking->setTrackingOptionId($tracking_category_data['tracking_option_id']);
								$Tracking->setName($tracking_category_data['name']);
								$Tracking->setOption($tracking_category_data['option']);
								$LineItem->setTracking([$Tracking]);
							}

							$LineItems[] = $LineItem;
						}
					}					
				}

				$customer_note = $this->get_array_isset($invoice_data,'customer_note','',true);
				# Order note line
				$sync_order_note_to = $this->get_option('mw_wc_xero_sync_s_order_notes_to');
				if($sync_order_note_to == 'Line_Item' && !empty($customer_note)){
					$LineItem = new XeroAPI\XeroPHP\Models\Accounting\LineItem;
					$LineItem->setDescription($customer_note);
					$LineItems[] = $LineItem;
				}

				# Custom Field Mapping
				$is_lre_p = false;
				if($this->is_plg_lc_p_l() || $this->is_plg_lc_p_r() || $this->is_plg_lc_p_empty()){
					$is_lre_p = true;
				}

				$cf_map_data = [];
				if(!$is_lre_p){
					$cf_map_data = $this->get_tbl($this->gdtn('map_custom_fields'));
				}
				
				if(is_array($cf_map_data) && !empty($cf_map_data)){
					$qacfm = $this->get_xero_avl_cf_map_fields();
					$wc_avl_cf_fields = $this->get_wc_avl_cf_map_fields();
					$wc_cf_address_keys = array('wc_billing_address', 'wc_shipping_address');
					foreach($cf_map_data as $cfm_data){
						$wcfm_k = trim($cfm_data['wc_field']);
						$wcfm_v = trim($cfm_data['x_field']);

						if(empty($wcfm_k) || empty($wcfm_v)){
							continue;
						}

						$is_static_val = false;
						if(!empty($wcfm_k) && substr($wcfm_k, 0, 1) == '{' && substr($wcfm_k, -1) == '}'){
							$is_static_val = true;
						}

						$wcfm_ext_data = '';
						if(!$is_static_val && !empty($cfm_data['ext_data'])){
							$wcfm_ext_data = $cfm_data['ext_data'];
						}

						$wcf_val = '';
						if($is_static_val){
							$wcf_val = $this->get_string_between($wcfm_k,'{','}');
							$wcf_val = trim($wcf_val);
						}else{
							switch ($wcfm_k) {
								case "":								
									break;
								
								default:
									if(isset($invoice_data[$wcfm_k])){
										if(!is_array($invoice_data[$wcfm_k]) && !is_object($invoice_data[$wcfm_k])){
											$wcf_val = $this->get_array_isset($invoice_data,$wcfm_k,'',true);
										}
									}
							}
						}

						if(!empty($wcf_val) && (isset($qacfm[$wcfm_v]) || (substr_count($wcfm_v,'__') == 1 && strlen($wcfm_v) > 10))){
							$wcf_val = $this->cfm_ft_ev_pv($wcf_val,$wcfm_ext_data);
							switch ($wcfm_v) {
								case "":								
									break;
								
								default:
									try {
										$qacfm_af = $this->get_xero_avl_cf_map_fields(true);
										$ivqf = false;
										if(is_array($qacfm_af) && !empty($qacfm_af) && isset($qacfm_af[$wcfm_v])){
											$ivqf = true;
										}

										if($ivqf){
											if(($wcfm_v == 'Date' || $wcfm_v == 'DueDate') && !empty($wcf_val)){
												$wcf_val = new DateTime($wcf_val);
											}
											
											if(!empty($wcf_val)){
												if($is_static_val && is_string($wcf_val)){
													$wcf_val = str_replace('__EOL__', PHP_EOL, $wcf_val);
												}

												$Invoice->{"set".$wcfm_v}($wcf_val);
											}											
										}else{
											if($wcfm_v == 'A_L_I' && !empty($wcf_val)){
												if($is_static_val && is_string($wcf_val)){
													$wcf_val = str_replace('__EOL__', PHP_EOL, $wcf_val);
												}

												# Address sources carry their label so the line reads as an address on the invoice, not a stray item.
												# Only here - a Billing/Shipping Address mapped to Reference gets the bare address.
												if(!$is_static_val && in_array($wcfm_k, $wc_cf_address_keys, true) && isset($wc_avl_cf_fields[$wcfm_k])){
													$wcf_val = $wc_avl_cf_fields[$wcfm_k].': '.$wcf_val;
												}

												$LineItem = new XeroAPI\XeroPHP\Models\Accounting\LineItem;
												$LineItem->setDescription($wcf_val);
												$LineItems[] = $LineItem;
											}

											if($wcfm_v == 'Tracking_Category' && !empty($wcf_val)){
												if($this->is_plg_lc_p_g() || $this->is_plg_lc_p_s() || $this->is_plg_lc_p_sr()){
													$cf_tc = $this->get_order_sync_xero_tracking_category_data_by_option_name($wcf_val);
													if(!empty($cf_tc)){
														$cf_tracking_override = $cf_tc;
													}
												}
											}

											if(substr_count($wcfm_v,'__') == 1 && strlen($wcfm_v) > 10){
												if($this->is_plg_lc_p_g() || $this->is_plg_lc_p_s() || $this->is_plg_lc_p_sr()){
													$cf_tc = $this->get_xero_tracking_data_by_id_pair($wcfm_v);
													if(!empty($cf_tc)){
														$cf_tracking_override = $cf_tc;
													}
												}
											}
										}
									}catch(Exception $e) {
										#->
									}
							}
						}
					}
				}
				
				#->
				if(!empty($cf_tracking_override) && !empty($LineItems)){
					foreach($LineItems as $LI){
						$li_desc = $LI->getDescription();
						if(!empty($li_desc) && stripos(trim($li_desc),'Shipping') === 0){
							continue;
						}
						$Tracking = new XeroAPI\XeroPHP\Models\Accounting\LineItemTracking;
						$Tracking->setTrackingCategoryId($cf_tracking_override['tracking_category_id']);
						$Tracking->setTrackingOptionId($cf_tracking_override['tracking_option_id']);
						$Tracking->setName($cf_tracking_override['name']);
						$Tracking->setOption($cf_tracking_override['option']);
						$LI->setTracking([$Tracking]);
					}
				}

				if(!empty($LineItems)){
					#$this->_p($LineItems);
					if($is_update && is_array($xero_line_items) && !empty($xero_line_items)){
						foreach($LineItems as $k => $v){
							if(isset($xero_line_items[$k])){
								$LineItems[$k]->setLineItemId($xero_line_items[$k]->getLineItemId());
							}
						}						
					}

					#$this->_p($LineItems);
					#return false;
					$Invoice->setLineItems($LineItems);
				}

				# Currency
				$Invoice->setCurrencyCode($_currency_applicable);
				
				$Arr_Invoices = array();
				array_push($Arr_Invoices, $Invoice);
				
				$Invoices = new XeroAPI\XeroPHP\Models\Accounting\Invoices;
				$Invoices->setInvoices($Arr_Invoices);

				// $this->add_wc_debug_log( $invoice_data, true, true, true );
				// $this->add_wc_debug_log( $LineItems, true, true, true );
				// $this->add_wc_debug_log( $Arr_Invoices, true, true, true );
				
				#Debug
				#$this->_p($invoice_data);				
				#$this->_p($Invoice);
				#return;
				
				try{
					# Update
					if($is_update){
						$delay = $this->should_delay_api_call();
						if ($delay > 0) {
							sleep($delay);
						}
						
						$this->add_wc_debug_log("=== XERO UPDATE INVOICE REQUEST ===\nTenant ID: " . $this->XeroTenantId . "\nInvoice ID: " . $Invoice->getInvoiceId() . "\nRequest Data:", true, false, false);
						$this->add_wc_debug_log($Invoices, true, false, false);
						$result = $this->X_API_I()->updateInvoice($this->XeroTenantId,$Invoice->getInvoiceId(),$Invoices);
						$this->add_wc_debug_log("=== XERO UPDATE INVOICE RESPONSE ===", true, false, false);
						$this->add_wc_debug_log($result, true, false, false);

						#$this->add_text_into_log_file($Invoice);
						#$this->add_text_into_log_file($result);
						#$this->_p($result);
						
						if(!empty($result)){
							$xr_invoices = $result->getInvoices();
							if(is_array($xr_invoices) && !empty($xr_invoices)){
								$x_invoice = $xr_invoices[0];

								if(!$x_invoice->getHasErrors()){
									$X_InvoiceID = $x_invoice->getInvoiceID();
									if(!empty($X_InvoiceID) && $X_InvoiceID != '00000000-0000-0000-0000-000000000000'){
										$ld = "{$ltt} #{$ord_id_num} updated in Xero successfully".PHP_EOL;
										$ld.="Xero Invoice ID #".$X_InvoiceID;										

										$this->save_log(
											array(
												'type'=>$lt,
												'title'=>'Update '.$ltt.' #'.$ord_id_num,
												'details'=>$ld,
												'status'=>1,
												'wc_id'=>$wc_inv_id,
												'xero_id'=>$X_InvoiceID,
												)
										);

										# Send Email
										/*
										if($this->option_checked('mw_wc_xero_sync_send_invoice_email_after_sync')){
											$RequestEmpty = new XeroAPI\XeroPHP\Models\Accounting\RequestEmpty;
											$resultEmail = $this->X_API_I()->emailInvoice($this->XeroTenantId,$X_InvoiceID,$RequestEmpty);
										}
										*/
										
										# Order Note
										$o_note = __('Order updated in Xero - MyWorks Sync','myworks-sync-for-xero');
										$this->add_order_note($wc_inv_id,$o_note);

										# Store local sync state so the Orders list renders status without a live API call. Refs #83
										$this->set_order_xero_sync_state($wc_inv_id,$X_InvoiceID,'AUTHORISED',(string) $x_invoice->getInvoiceNumber());

										return $X_InvoiceID;
									}
								}else{
									# Error
									# status_attribute_string ,warnings
									$validation_errors = $x_invoice->getValidationErrors();
									
									$ld = $this->append_line_account_code_hint($this->get_error_message_from_xero_validation_errors($validation_errors));
									if(!empty($ld)){
										$this->save_log(array('type'=>$lt,'title'=>'Update '.$ltt.' Error #'.$ord_id_num,'details'=>$ld,'status'=>0,'wc_id'=>$wc_inv_id));
									}

									# Order Note
									$o_note = __('Order attempted update to Xero but failed. Check MyWorks Sync > Log for more info.','myworks-sync-for-xero');
									$this->add_order_note($wc_inv_id,$o_note);

									return false;
								}							
							}
						}

						return false;
					}					

					# Add with rate limiting
					$delay = $this->should_delay_api_call();
					if ($delay > 0) {
						sleep($delay);
					}
					
					$this->add_wc_debug_log("=== XERO CREATE INVOICE REQUEST ===\nTenant ID: " . $this->XeroTenantId . "\nRequest Data:", true, false, false);
					$this->add_wc_debug_log($Invoices, true, false, false);
					$result = $this->X_API_I()->createInvoices($this->XeroTenantId,$Invoices);
					$this->add_wc_debug_log("=== XERO CREATE INVOICE RESPONSE ===", true, false, false);
					$this->add_wc_debug_log($result, true, false, false);
					
					// Note: API usage tracking is now handled automatically by the Guzzle middleware
				
					#$this->add_text_into_log_file($Invoice);
					#$this->add_text_into_log_file($result);
					#$this->_p($result);
					
					if(!empty($result)){
						$xr_invoices = $result->getInvoices();
						if(is_array($xr_invoices) && !empty($xr_invoices)){
							$x_invoice = $xr_invoices[0];

							if(!$x_invoice->getHasErrors()){
								$X_InvoiceID = $x_invoice->getInvoiceID();
								if(!empty($X_InvoiceID) && $X_InvoiceID != '00000000-0000-0000-0000-000000000000'){
									$ld = "{$ltt} #{$ord_id_num} added into Xero successfully".PHP_EOL;
									$ld.="Xero Invoice ID #".$X_InvoiceID;

									# Next Xero Order Number
									if($this->use_next_xero_order_number()){
										$X_InvoiceNumber = (string) $x_invoice->getInvoiceNumber();
										if($this->is_hpos_enabled()) {
											$wc_order = wc_get_order( $wc_inv_id );
											if($wc_order) {
												$wc_order->update_meta_data( '_myworks_xero_sync_order_number', $X_InvoiceNumber );
												$wc_order->save();
											}
										}else{
											update_post_meta($wc_inv_id,'_myworks_xero_sync_order_number',$X_InvoiceNumber,false);
										}
									}

									$this->save_log(
										array(
											'type'=>$lt,
											'title'=>'Create '.$ltt.' #'.$ord_id_num,
											'details'=>$ld,
											'status'=>1,
											'wc_id'=>$wc_inv_id,
											'xero_id'=>$X_InvoiceID,
											)
									);

									# Order Note
									$o_note = __('Order synced to Xero - MyWorks Sync','myworks-sync-for-xero');
									$this->add_order_note($wc_inv_id,$o_note);

									# Store local sync state so the Orders list renders status without a live API call. Refs #83
									# This MUST happen before the optional invoice email: emailInvoice() shares the outer
									# try/catch with createInvoices(), so a mail failure used to land in the invoice-failure
									# path *after* Xero had already created the invoice — leaving a successful create marked
									# unsynced and re-pushed on the next run as a duplicate.
									$_state_saved = $this->set_order_xero_sync_state($wc_inv_id,$X_InvoiceID,'AUTHORISED',(string) $x_invoice->getInvoiceNumber());
									if($_state_saved === false){
										# The invoice DOES exist in Xero but the order could not be linked to it, so the next
										# sync pass may create a duplicate. Log both IDs loudly so it can be reconciled by
										# hand. Deliberately NOT bailing out here: returning early would report this push as
										# failed, which is what actually guarantees the duplicate re-push we're warning about.
										$this->save_log(array('type'=>$lt,'title'=>'Sync State Not Saved for '.$ltt.' #'.$ord_id_num,'details'=>'Invoice was created in Xero but could not be linked to the WooCommerce order. Link it manually to avoid a duplicate on the next sync. WooCommerce order ID: '.$wc_inv_id.', Xero invoice ID: '.$X_InvoiceID,'status'=>0,'wc_id'=>$wc_inv_id,'xero_id'=>$X_InvoiceID));
									}

									$this->process_order_sync(array('ID'=>$wc_inv_id));

									# Send Email — best effort, and never fatal: the invoice already exists in Xero and the
									# order is already recorded as synced, so a mail failure is logged and nothing else.
									if($this->option_checked('mw_wc_xero_sync_send_invoice_email_after_sync')){
										try{
											$RequestEmpty = new XeroAPI\XeroPHP\Models\Accounting\RequestEmpty;
											$resultEmail = $this->X_API_I()->emailInvoice($this->XeroTenantId,$X_InvoiceID,$RequestEmpty);
										}catch (\Exception $e) {
											$this->save_log(array('type'=>$lt,'title'=>'Email '.$ltt.' Error #'.$ord_id_num,'details'=>'Invoice synced successfully but Xero could not email it: '.$e->getMessage(),'status'=>0,'wc_id'=>$wc_inv_id,'xero_id'=>$X_InvoiceID));
										}
									}

									return $X_InvoiceID;
								}
							}else{
								# Error
								# status_attribute_string ,warnings
								$validation_errors = $x_invoice->getValidationErrors();
								
								$ld = $this->append_line_account_code_hint($this->get_error_message_from_xero_validation_errors($validation_errors));
								if(!empty($ld)){
									$this->save_log(array('type'=>$lt,'title'=>'Create '.$ltt.' Error #'.$ord_id_num,'details'=>$ld,'status'=>0,'wc_id'=>$wc_inv_id));
								}

								# Order Note
								$o_note = __('Order attempted sync to Xero but failed. Check MyWorks Sync > Log for more info.','myworks-sync-for-xero');
								$this->add_order_note($wc_inv_id,$o_note);

								return false;
							}							
						}
					}
				}catch (\XeroAPI\XeroPHP\ApiException $e) {
					$this->add_wc_debug_log("=== XERO API ERROR ===\nHTTP Code: " . $e->getCode() . "\nResponse Body: " . $e->getResponseBody(), true, false, false);
					$error = AccountingObjectSerializer::deserialize($e->getResponseBody(), '\XeroAPI\XeroPHP\Models\Accounting\Error',[]);

					$ld = $this->append_line_account_code_hint($this->get_error_message_from_xero_error_object($error));
					if(!empty($ld)){
						$this->save_log(array('type'=>$lt,'title'=>'Create '.$ltt.' Error #'.$ord_id_num,'details'=>$ld,'status'=>0,'wc_id'=>$wc_inv_id));
					}

					# Order Note
					$o_note = __('Order attempted sync to Xero but failed. Check MyWorks Sync > Log for more info.','myworks-sync-for-xero');
					$this->add_order_note($wc_inv_id,$o_note);

					return false;
				}catch (\InvalidArgumentException $e) {
					# The SDK json_encode()s the payload and throws GuzzleHttp\Exception\InvalidArgumentException
					# (a \InvalidArgumentException) when a string is not valid UTF-8. Uncaught, that reaches
					# wp-admin as a "critical error" instead of a sync failure. Strings are cleaned upstream in
					# get_array_isset(); this is the same backstop X_Add_Product() carries. Refs #148
					$this->save_log(array('type'=>$lt,'title'=>'Create '.$ltt.' Error #'.$ord_id_num,'details'=>'The '.strtolower($lt).' data could not be encoded for Xero: '.$e->getMessage(),'status'=>0,'wc_id'=>$wc_inv_id));

					# Order Note
					$o_note = __('Order attempted sync to Xero but failed. Check MyWorks Sync > Log for more info.','myworks-sync-for-xero');
					$this->add_order_note($wc_inv_id,$o_note);

					return false;
				}

			}
		}
	}
	
	/**
	 * Create a Xero payment against an order's existing Xero invoice.
	 *
	 * @param  array  $invoice_data        Mapped order payload built by the caller.
	 * @param  object $xero_invoice_object The Xero invoice the payment is applied to.
	 * @return string|false The new Xero PaymentID, or false when the payment was not created.
	 */
	public function X_Add_Payment($invoice_data,$xero_invoice_object){	
		if(is_array($invoice_data) && !empty($invoice_data) && is_object($xero_invoice_object) && !empty($xero_invoice_object)){
			if($this->is_xero_connected()){
				if($xero_payment_id = $this->if_xero_payment_exists($invoice_data,$xero_invoice_object)){
					return false;
				}

				if($xero_invoice_object->getStatus() != 'AUTHORISED'){
					return false;
				}
				
				$lt = 'Payment';
				$ltt = 'Payment';

				$wc_inv_id = (int) $this->get_array_isset($invoice_data,'wc_inv_id',0,false);
				$wc_inv_num = $this->get_array_isset($invoice_data,'wc_inv_num','');

				$ord_id_num = (!empty($wc_inv_num))?$wc_inv_num:$wc_inv_id;

				$_order_total = (float) $this->get_array_isset($invoice_data,'_order_total',0,false);
				$payment_amount = $_order_total;

				if($payment_amount == 0 || $payment_amount < 0){
					return false;
				}

				#$wc_inv_date = $this->get_array_isset($invoice_data,'wc_inv_date','');
				$order_date = $this->get_array_isset($invoice_data,'order_date','');

				$artificial_payment_allowed = false;
				if(isset($invoice_data['is_artificial_payment']) && $invoice_data['is_artificial_payment']){
					$artificial_payment_allowed = true;
				}
				
				$_paid_date = $this->get_array_isset($invoice_data,'_paid_date','');				
				if(!$artificial_payment_allowed && empty($_paid_date)){
					return false;
				}

				$_transaction_id = $this->get_array_isset($invoice_data,'_transaction_id','');

				$_payment_method = $this->get_array_isset($invoice_data,'_payment_method','',true);
				$_payment_method_title = $this->get_array_isset($invoice_data,'_payment_method_title','',true);
				$_order_currency = $this->get_array_isset($invoice_data,'_order_currency','',true);

				if(empty($_order_currency)){
					return false;
				}

				$_currency_applicable = $_order_currency;

				# Empty/unmapped payment methods fall back to the "Other" mapping. Refs #78
				$pm_map_data = $this->get_pm_map_data_with_other_fallback($_payment_method,$_currency_applicable);
				$enable_payment = (int) $this->get_array_isset($pm_map_data,'enable_payment',0,false);
				$AccountID = $this->get_array_isset($pm_map_data,'X_ACC_ID','');

				if($enable_payment != 1 || empty($AccountID)){
					return false;
				}

				$enable_txn_fee = (int) $this->get_array_isset($pm_map_data,'enable_txn_fee',0,false);
				if($enable_txn_fee){
					$txn_fee_data = $this->get_txn_fee_data_from_order($invoice_data);
					$txn_fee_amount = (float) $txn_fee_data['t_f_amnt'];
					if($txn_fee_amount > 0){
						$payment_amount = (float) ($payment_amount-$txn_fee_amount);
					}
				}

				// Cap payment at Xero's outstanding balance to prevent "payment exceeds amount outstanding" error
				// caused by rounding differences or invoice total mismatches
				$xero_amount_due = (float) $xero_invoice_object->getAmountDue();
				if($xero_amount_due > 0 && $payment_amount > $xero_amount_due){
					$payment_amount = $xero_amount_due;
				}

				if($payment_amount == 0 || $payment_amount < 0){
					return false;
				}

				$xero_invoice_id = $xero_invoice_object->getInvoiceID();
				
				$Payment = new XeroAPI\XeroPHP\Models\Accounting\Payment;

				$Invoice = new XeroAPI\XeroPHP\Models\Accounting\Invoice;				
				$Invoice->setInvoiceID($xero_invoice_id);
				
				$BankAccount = new XeroAPI\XeroPHP\Models\Accounting\Account;				
				$BankAccount->setAccountID($AccountID);
				
				$Payment->setInvoice($Invoice);
				$Payment->setAccount($BankAccount);
				
				$Payment->setAmount($payment_amount);

				$payment_date = (!empty($_paid_date))?$_paid_date:$order_date;				
				$Payment->setDate(new DateTime($payment_date));				

				#$Reference = (!empty($_transaction_id))?$_transaction_id:$_payment_method_title;
				# New - Reference based on setting
				$reference_setting = $this->get_option('mw_wc_xero_sync_xero_payment_reference_val_s');
				$Reference = $wc_inv_id;
				if($reference_setting == 'OrderNumber' && !empty($wc_inv_num)){
					$Reference = $wc_inv_num;
				}elseif($reference_setting == 'TxnId' && !empty($_transaction_id)){
					$Reference = $_transaction_id;
				}
				
				$Payment->setReference($Reference);
				
				#Debug
				#$this->_p($invoice_data);
				#$this->_p($xero_invoice_object);
				#$this->_p($Payment);
				#return;
				
				try{
					$this->add_wc_debug_log("=== XERO CREATE PAYMENT REQUEST ===\nTenant ID: " . $this->XeroTenantId . "\nRequest Data:", true, false, false);
					$this->add_wc_debug_log($Payment, true, false, false);
					
					// Check and respect rate limits before API call
					if($this->should_delay_api_call()) {
						$this->add_wc_debug_log("Rate limit check: Delaying payment creation due to rate limits", true, false, false);
						sleep(2); // 2 second delay
					}
					
					$result = $this->X_API_I()->createPayment($this->XeroTenantId,$Payment);
					
					// Note: API usage tracking is now handled automatically by the Guzzle middleware
					$this->add_wc_debug_log("=== XERO CREATE PAYMENT RESPONSE ===", true, false, false);
					$this->add_wc_debug_log($result, true, false, false);
					
					#$this->_p($result);
					#$this->add_text_into_log_file($Payment);
					#$this->add_text_into_log_file($result);
					
					if(!empty($result)){
						$xr_payments = $result->getPayments();
						if(is_array($xr_payments) && !empty($xr_payments)){
							$x_payment = $xr_payments[0];
							$X_PaymentID = $x_payment->getPaymentID();
							if(!empty($X_PaymentID)){								
								$ld = "{$ltt} for Order #{$ord_id_num} added into Xero successfully".PHP_EOL;
								$ld.="Xero Payment ID #".$X_PaymentID;

								$this->save_log(
									array(
										'type'=>$lt,
										'title'=>'Create '.$ltt.' for Order #'.$ord_id_num,
										'details'=>$ld,
										'status'=>1,
										'wc_id'=>$wc_inv_id,
										'xero_id'=>$X_PaymentID,
										)
								);
								
								return $X_PaymentID;
							}
						}
					}
				}catch (\XeroAPI\XeroPHP\ApiException $e) {
					$error = AccountingObjectSerializer::deserialize($e->getResponseBody(), '\XeroAPI\XeroPHP\Models\Accounting\Error',[]);					
					$ld = $this->get_error_message_from_xero_error_object($error);
					if(!empty($ld)){						
						$this->save_log(array('type'=>$lt,'title'=>'Create '.$ltt.' for Order Error #'.$ord_id_num,'details'=>$ld,'status'=>0,'wc_id'=>$wc_inv_id));
					}

					return false;
				}catch (\InvalidArgumentException $e) {
					# The SDK json_encode()s the payload and throws GuzzleHttp\Exception\InvalidArgumentException
					# (a \InvalidArgumentException) when a string is not valid UTF-8. Uncaught, that reaches
					# wp-admin as a "critical error" instead of a sync failure. Strings are cleaned upstream in
					# get_array_isset(); this is the same backstop X_Add_Product() carries. Refs #148
					$this->save_log(array('type'=>$lt,'title'=>'Create '.$ltt.' for Order Error #'.$ord_id_num,'details'=>'The '.strtolower($lt).' data could not be encoded for Xero: '.$e->getMessage(),'status'=>0,'wc_id'=>$wc_inv_id));

					return false;
				}				
				
				return false;
			}
		}
	}

	/**
	 * Effective If-Modified-Since for an Items-based pull type.
	 *
	 * Extracted from the four pull functions, which each carried an identical copy. The stored
	 * watermark is Xero-local "now" formatted as though it were UTC — odd, but preserved exactly,
	 * because changing it would silently shift the sync window on every existing install. Refs #87
	 */
	private function get_pull_watermark($option_key,$default_interval_mins){
		$last_timestamp = $this->get_option($option_key);
		if(!empty($last_timestamp)){
			return $last_timestamp;
		}

		$now = new DateTime("now", new DateTimeZone($this->get_xero_timezone()));
		$datetime = $now->format('Y-m-d H:i:s');
		$datetime_m = gmdate('Y-m-d H:i:s',strtotime("-{$default_interval_mins} minutes",strtotime($datetime)));

		return gmdate('Y-m-d', strtotime($datetime_m)) . 'T' . gmdate('H:i:s', strtotime($datetime_m));
	}

	/**
	 * Watermark to store once a pull run has finished: Xero-local "now", same format as above.
	 */
	private function get_pull_watermark_now(){
		$now = new DateTime("now", new DateTimeZone($this->get_xero_timezone()));
		$datetime = $now->format('Y-m-d H:i:s');

		return gmdate('Y-m-d', strtotime($datetime)) . 'T' . gmdate('H:i:s', strtotime($datetime));
	}

	/**
	 * Watermark option + pre-existing fallback window for each Items-based pull type. The intervals
	 * differ per type and are the values those functions already used — don't "tidy" them into one
	 * number, they change how much history a first-ever run pulls. Refs #87
	 */
	private function get_item_pull_types(){
		return array(
			'Inventory' => array('option' => 'mw_wc_xero_last_ivnt_pull_timestamp', 'interval' => 180),
			'Product'   => array('option' => 'mw_wc_xero_last_product_pull_timestamp', 'interval' => 5),
			'Pricing'   => array('option' => 'mw_wc_xero_last_price_pull_timestamp', 'interval' => 180),
			'Cost'      => array('option' => 'mw_wc_xero_last_cost_pull_timestamp', 'interval' => 180),
		);
	}

	/**
	 * Run the enabled Items-based pulls from as few Xero fetches as possible.
	 *
	 * Inventory, Product, Pricing and Cost each called getItems() separately, and Product/Pricing
	 * issued an identical query — so one cron tick with all four enabled fetched the catalogue four
	 * times. getItems() has neither summaryOnly nor pagination, so every one of those was a full
	 * unbounded response, every 5 minutes by default.
	 *
	 * Two groups remain rather than one, deliberately:
	 *  - Product / Pricing / Inventory share a fetch (no `where`, no `unitdp`), matching what all
	 *    three already sent. Inventory's `IsTrackedAsInventory=True` filter is applied client-side
	 *    instead — but only when Product or Pricing is also enabled, otherwise Inventory alone would
	 *    end up fetching MORE than its own filtered call did.
	 *  - Cost keeps its own fetch because it passes `unitdp` (4dp). Folding it in would hand Pricing
	 *    4dp unit prices and change what gets written to `_price` — see #109 and the open
	 *    129-fix-xero-unit-price-rounding work. Precision is not worth one saved call.
	 *
	 * The shared fetch uses the EARLIEST watermark of its group so no type loses coverage; every
	 * consumer is idempotent (skips unchanged values), so being handed items older than its own
	 * watermark is a no-op. Refs #87
	 *
	 * @param array $types Enabled pull types, e.g. array('Inventory','Pricing').
	 */
	public function X_Pull_Items($types){
		if(!$this->is_xero_connected() || !is_array($types) || empty($types)){
			return;
		}

		$registry = $this->get_item_pull_types();
		$types = array_values(array_intersect(array_keys($registry),$types));
		if(empty($types)){
			return;
		}

		# Cost always stands alone (unitdp), so it is never part of the shared group.
		$shared = array_values(array_intersect(array('Inventory','Product','Pricing'),$types));
		$has_unfiltered = (in_array('Product',$shared) || in_array('Pricing',$shared));

		if(!$has_unfiltered){
			# Inventory on its own: leave it with its own narrower, filtered request.
			$shared = array();
		}

		$shared_since = (!empty($shared))?$this->get_earliest_pull_watermark($shared,$registry):'';

		# X_Pull_Product is the only consumer here that CREATES WooCommerce products; the other two
		# only update already-mapped ones. So a window wider than Product's own is not harmless for
		# it: an item whose WooCommerce product was created and later deleted has no mapping row
		# (hook_delete_product_mapping removes it), and re-seeing that item would re-create the
		# product the merchant deleted. Steady state has all enabled watermarks within a second or
		# two of each other, so this only bites right after a pull type is switched on and its
		# fallback window drags the shared start backwards. In that case Product drops out and keeps
		# its own narrow request — one extra call, rarely, instead of a resurrected product. Refs #87
		$product_standalone = false;
		if(in_array('Product',$shared)){
			$product_since = $this->get_pull_watermark($registry['Product']['option'],$registry['Product']['interval']);
			$drift = strtotime($product_since) - strtotime($shared_since);
			if($drift > self::PULL_WATERMARK_DRIFT_TOLERANCE){
				$product_standalone = true;
				$shared = array_values(array_diff($shared,array('Product')));
				$has_unfiltered = (in_array('Pricing',$shared));
				if(!$has_unfiltered){
					# Only Inventory left: back to its own filtered request.
					$shared = array();
				}else{
					$shared_since = $this->get_earliest_pull_watermark($shared,$registry);
				}
			}
		}

		if(!empty($shared)){
			# Stamped BEFORE the fetch and handed to each consumer to store on success. Taking "now"
			# after the processing loop instead would move the watermark past any item Xero changed
			# while the fetch and loop were running — that item isn't in this response and the next
			# run would start after it, skipping it permanently. Re-covering a few seconds is free
			# because every consumer is idempotent. Refs #87
			$run_started = $this->get_pull_watermark_now();
			$items = $this->x_get_items_since($shared_since,null,null);
			if(is_array($items)){
				# Order preserved from the previous per-type call sequence in mwxs_process_ivnt_pull().
				if(in_array('Inventory',$shared)){
					$this->X_Pull_Inventory($items,$run_started);
				}

				if(in_array('Product',$shared)){
					$this->X_Pull_Product($items,$run_started);
				}

				if(in_array('Pricing',$shared)){
					$this->X_Pull_Price($items,$run_started);
				}
			}
		}elseif(in_array('Inventory',$types)){
			$this->X_Pull_Inventory();
		}

		if($product_standalone){
			$this->X_Pull_Product();
		}

		if(in_array('Cost',$types)){
			$this->X_Pull_Cost();
		}
	}

	/**
	 * Earliest watermark across a group of pull types. These strings are fixed-width
	 * Y-m-d\TH:i:s, so a plain string comparison is also a chronological one. Refs #87
	 */
	private function get_earliest_pull_watermark($types,$registry){
		$earliest = '';
		foreach($types as $type){
			if(!isset($registry[$type])){
				continue;
			}

			$wm = $this->get_pull_watermark($registry[$type]['option'],$registry[$type]['interval']);
			if(empty($earliest) || strcmp($wm,$earliest) < 0){
				$earliest = $wm;
			}
		}

		return $earliest;
	}

	/**
	 * One guarded getItems() call. Returns the item array, or null when the call failed — callers
	 * must treat null as "don't advance any watermark", so a transport blip can't silently skip a
	 * sync window. Refs #87
	 */
	private function x_get_items_since($if_modified_since,$where=null,$unitdp=null){
		try{
			$result = $this->X_API_I()->getItems($this->get_xero_tenant_id(),$if_modified_since,$where,null,$unitdp);
		}catch (\XeroAPI\XeroPHP\ApiException $e) {
			$error = AccountingObjectSerializer::deserialize($e->getResponseBody(), '\XeroAPI\XeroPHP\Models\Accounting\Error',[]);
			$ld = $this->get_error_message_from_xero_error_object($error);
			if(empty($ld)){
				$ld = 'Xero API error: ' . $e->getMessage();
			}
			$this->save_log(array('type'=>'Product','title'=>'Pull Items Error','details'=>$ld,'status'=>0));

			return null;
		}catch (\Throwable $e) {
			# Non-ApiException throwables (SDK TypeError et al) must land here too, and must return null
			# rather than an empty array: callers treat null as "could not fetch" and skip advancing the
			# pull watermark, whereas an empty array reads as "nothing changed" and would advance it,
			# permanently skipping that window. Refs #87
			$this->save_log(array('type'=>'Product','title'=>'Pull Items Error','details'=>'Unexpected error: '.get_class($e).': '.$this->redact_log_pii($e->getMessage()),'status'=>0));

			return null;
		}

		if(empty($result)){
			return null;
		}

		$items = $result->getItems();

		return (is_array($items))?$items:null;
	}

	/**
	 * @param array|null  $shared_items Items already fetched by X_Pull_Items(); when null this
	 *                                  fetches its own, so a standalone call behaves as before.
	 * @param string|null $run_started  Watermark stamped before the shared fetch, to store on
	 *                                  success. Ignored (recomputed) on the standalone path.
	 */
	public function X_Pull_Price($shared_items=null,$run_started=null){
		if($this->is_xero_connected()){
			$items = $shared_items;
			if(!is_array($items)){
				# Stamp before the fetch, not after processing — see X_Pull_Items(). Refs #87
				$run_started = $this->get_pull_watermark_now();
				$if_modified_since = $this->get_pull_watermark('mw_wc_xero_last_price_pull_timestamp',180);
				$items = $this->x_get_items_since($if_modified_since);
			}

			# The watermark used to be written BEFORE the API call, so a failed call permanently
			# skipped that window and those price changes never reached WooCommerce. Advance it only
			# once there is a real response, matching what X_Pull_Cost() already did. Refs #87
			if(!is_array($items) || empty($run_started)){
				return;
			}

			if(!empty($items)){
				foreach($items as $Item){
					if(empty($Item->getSalesDetails())){
						continue;
					}

					$ItemID = $Item->getItemID();
					$UnitPrice = floatval($Item->getSalesDetails()->getUnitPrice());
					$item_data = array(
						'Name' => $Item->getName(),
						'Code' => $Item->getCode(),
					);

					$this->UpdateWooCommercePrice($ItemID,$UnitPrice,$item_data);
				}
			}

			$this->update_option('mw_wc_xero_last_price_pull_timestamp',$run_started);
		}
	}
	
	public function UpdateWooCommercePrice($ItemID,$UnitPrice,$item_data=array()){
		if(!empty($ItemID)){
			global $wpdb;
			$map_data = $this->get_data($wpdb->prepare("SELECT `W_P_ID` FROM `".$this->gdtn('map_products')."` WHERE `X_P_ID` = %s AND `W_P_ID` > 0 ",$ItemID)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name is escaped with esc_sql(), values are properly prepared
			$is_variation = false;
			if(empty($map_data)){
				$map_data = $this->get_data($wpdb->prepare("SELECT `W_V_ID` FROM `".$this->gdtn('map_variations')."` WHERE `X_P_ID` = %s AND `W_V_ID` > 0 ",$ItemID)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name is escaped with esc_sql(), values are properly prepared
				$is_variation = true;
			}

			if(empty($map_data)){
				return false;
			}

			$item_code = '';

			$ltp = '';
			$ext_log = '';			
			if(is_array($item_data) && !empty($item_data)){
				if(isset($item_data['Name'])){
					$ext_log = PHP_EOL.'Name: '.$item_data['Name'];
				}
				
				if(isset($item_data['Code'])){
					$item_code = $item_data['Code'];
				}
			}

			if(is_array($map_data)){
				foreach($map_data as $map_data_c){
					$wc_product_id = 0;
					if($is_variation){
						$wc_variation_id = $map_data_c['W_V_ID'];
						$wc_product_id = $wc_variation_id;
					}else{
						$wc_product_id = $map_data_c['W_P_ID'];
					}

					if(!$wc_product_id){
						continue;
					}

					$product_meta = get_post_meta($wc_product_id);
					if(!is_array($product_meta) || empty($product_meta)){
						continue;
					}

					$update_price_f = true;
					$_sale_price = (isset($product_meta['_sale_price'][0]))? (float) $product_meta['_sale_price'][0]:0;
					if($_sale_price > 0){
						$_price = (isset($product_meta['_regular_price'][0]))? (float) $product_meta['_regular_price'][0]:0;
						$update_price_f = false;
					}else{
						$_price = (isset($product_meta['_price'][0]))? (float) $product_meta['_price'][0]:0;
					}
					
					if($UnitPrice != $_price){
						$_price = number_format(floatval($_price),2);
						if($update_price_f){
							update_post_meta($wc_product_id, '_price', $UnitPrice);
						}

						update_post_meta($wc_product_id, '_regular_price', $UnitPrice);

						$x_lt_id = (!empty($item_code))?$item_code:$ItemID;
						
						$log_title = $ltp.'Import Product Price #'.$x_lt_id;
						$log_details = "WooCommerce Product #{$wc_product_id} price updated from {$_price} to {$UnitPrice}".$ext_log;
						$this->save_log(array('type'=>'Price','title'=>$log_title,'details'=>$log_details,'status'=>1,'wc_id'=>$wc_product_id,'xero_id'=>$ItemID));
					}else{
						# Same Price
					}
				}
			}

		}
	}
	
	# Cost deliberately keeps its own getItems() call: it needs unitdp (4dp) and sharing a
	# 4dp fetch with Pricing would change what gets written to _price. See X_Pull_Items(). Refs #87
	public function X_Pull_Cost(){
		if($this->is_xero_connected()){
			$if_modified_since = $this->get_pull_watermark('mw_wc_xero_last_cost_pull_timestamp',180);
			$new_timestamp = $this->get_pull_watermark_now();

			try{
				$result = $this->X_API_I()->getItems($this->get_xero_tenant_id(),$if_modified_since,null,null,$this->x_unitdp());
			}catch (\XeroAPI\XeroPHP\ApiException $e) {
				$error = AccountingObjectSerializer::deserialize($e->getResponseBody(), '\XeroAPI\XeroPHP\Models\Accounting\Error',[]);
				$ld = $this->get_error_message_from_xero_error_object($error);
				if(empty($ld)){
					$ld = 'Xero API error: ' . $e->getMessage();
				}
				$this->save_log(array('type'=>'Cost','title'=>'Pull Cost Error','details'=>$ld,'status'=>0));

				return;
			}catch (\Throwable $e) {
				# As above - return before the watermark advances, so a failed window is retried rather
				# than silently skipped. Refs #87
				$this->save_log(array('type'=>'Cost','title'=>'Pull Cost Error','details'=>'Unexpected error: '.get_class($e).': '.$this->redact_log_pii($e->getMessage()),'status'=>0));

				return;
			}

			if(!empty($result)){
				$items = $result->getItems();
				if(is_array($items) && !empty($items)){
					foreach($items as $Item){
						if(empty($Item->getPurchaseDetails()) || is_null($Item->getPurchaseDetails()->getUnitPrice())){
							continue;
						}

						$ItemID = $Item->getItemID();
						$UnitCost = floatval($Item->getPurchaseDetails()->getUnitPrice());
						$item_data = array(
							'Name' => $Item->getName(),
							'Code' => $Item->getCode(),
						);

						$this->UpdateWooCommerceCost($ItemID,$UnitCost,$item_data);
					}
				}

				if(!empty($new_timestamp)){
					$this->update_option('mw_wc_xero_last_cost_pull_timestamp',$new_timestamp);
				}
			}
		}
	}

	public function UpdateWooCommerceCost($ItemID,$UnitCost,$item_data=array()){
		if(!empty($ItemID)){
			global $wpdb;
			$map_data = $this->get_data($wpdb->prepare("SELECT `W_P_ID` FROM `".$this->gdtn('map_products')."` WHERE `X_P_ID` = %s AND `W_P_ID` > 0 ",$ItemID)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name is escaped with esc_sql(), values are properly prepared
			$is_variation = false;
			if(empty($map_data)){
				$map_data = $this->get_data($wpdb->prepare("SELECT `W_V_ID` FROM `".$this->gdtn('map_variations')."` WHERE `X_P_ID` = %s AND `W_V_ID` > 0 ",$ItemID)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name is escaped with esc_sql(), values are properly prepared
				$is_variation = true;
			}

			if(empty($map_data)){
				return false;
			}

			$item_code = '';

			$ltp = '';
			$ext_log = '';
			if(is_array($item_data) && !empty($item_data)){
				if(isset($item_data['Name'])){
					$ext_log = PHP_EOL.'Name: '.$item_data['Name'];
				}

				if(isset($item_data['Code'])){
					$item_code = $item_data['Code'];
				}
			}

			if(is_array($map_data)){
				foreach($map_data as $map_data_c){
					$wc_product_id = 0;
					if($is_variation){
						$wc_variation_id = $map_data_c['W_V_ID'];
						$wc_product_id = $wc_variation_id;
					}else{
						$wc_product_id = $map_data_c['W_P_ID'];
					}

					if(!$wc_product_id){
						continue;
					}

					$_cost = (float) get_post_meta($wc_product_id, '_cogs_total_value', true);

					if($UnitCost != $_cost){
						$_cost = number_format(floatval($_cost),2);
						update_post_meta($wc_product_id, '_cogs_total_value', $UnitCost);

						$x_lt_id = (!empty($item_code))?$item_code:$ItemID;

						$log_title = $ltp.'Import Product Cost #'.$x_lt_id;
						$log_details = "WooCommerce Product #{$wc_product_id} cost updated from {$_cost} to {$UnitCost}".$ext_log;
						$this->save_log(array('type'=>'Cost','title'=>$log_title,'details'=>$log_details,'status'=>1,'wc_id'=>$wc_product_id,'xero_id'=>$ItemID));
					}else{
						# Same Cost
					}
				}
			}

		}
	}

	/**
	 * @param array|null  $shared_items Items already fetched by X_Pull_Items(); when null this
	 *                                  fetches its own with the IsTrackedAsInventory filter, so a
	 *                                  standalone call behaves as before. A shared list is NOT
	 *                                  pre-filtered, so the tracked-only check is applied here.
	 * @param string|null $run_started  Watermark stamped before the shared fetch, to store on
	 *                                  success. Ignored (recomputed) on the standalone path.
	 */
	public function X_Pull_Inventory($shared_items=null,$run_started=null){
		if($this->is_xero_connected()){
			$items = $shared_items;
			$is_shared = is_array($items);
			if(!$is_shared){
				# Stamp before the fetch, not after processing — see X_Pull_Items(). Refs #87
				$run_started = $this->get_pull_watermark_now();
				$if_modified_since = $this->get_pull_watermark('mw_wc_xero_last_ivnt_pull_timestamp',180);
				$items = $this->x_get_items_since($if_modified_since,'IsTrackedAsInventory=True');
			}

			# Watermark used to be written BEFORE the API call, so a failed call permanently skipped
			# that window and those stock changes never reached WooCommerce. Refs #87
			if(!is_array($items) || empty($run_started)){
				return;
			}

			if(!empty($items)){
				foreach($items as $Item){
					# A shared fetch carries the whole catalogue; the standalone call filters
					# server-side via `where`, so only the shared path needs this guard.
					if($is_shared && !$Item->getIsTrackedAsInventory()){
						continue;
					}

					$ItemID = $Item->getItemID();
					$QuantityOnHand = $Item->getQuantityOnHand(); # Float value in Xero
					$item_data = array(
						'Name' => $Item->getName(),
						'Code' => $Item->getCode(),
					);

					$this->UpdateWooCommerceInventory($ItemID,$QuantityOnHand,$item_data);
				}
			}

			$this->update_option('mw_wc_xero_last_ivnt_pull_timestamp',$run_started);
		}
	}

	public function UpdateWooCommerceInventory($ItemID,$QuantityOnHand,$item_data=array()){
		if(!empty($ItemID)){
			global $wpdb;
			$map_data = $this->get_data($wpdb->prepare("SELECT `W_P_ID` FROM `".$this->gdtn('map_products')."` WHERE `X_P_ID` = %s AND `W_P_ID` > 0 ",$ItemID)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name is escaped with esc_sql(), values are properly prepared
			$is_variation = false;
			if(empty($map_data)){
				$map_data = $this->get_data($wpdb->prepare("SELECT `W_V_ID` FROM `".$this->gdtn('map_variations')."` WHERE `X_P_ID` = %s AND `W_V_ID` > 0 ",$ItemID)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name is escaped with esc_sql(), values are properly prepared
				$is_variation = true;
			}

			if(empty($map_data)){
				return false;
			}

			$item_code = '';

			$ltp = '';
			$ext_log = '';			
			if(is_array($item_data) && !empty($item_data)){
				if(isset($item_data['Name'])){
					$ext_log = PHP_EOL.'Name: '.$item_data['Name'];
				}
				
				if(isset($item_data['Code'])){
					$item_code = $item_data['Code'];
				}
			}
			
			if(is_array($map_data)){
				$is_v_parent_stock_status_updated = false;
				foreach($map_data as $map_data_c){
					$wc_product_id = 0;
					$is_variation_parent = false;
					$parent_id = 0;

					if($is_variation){
						$wc_variation_id = $map_data_c['W_V_ID'];
						$variation_manage_stock = get_post_meta($wc_variation_id,'_manage_stock',true);
						$parent_id = (int) $this->get_field_by_val($wpdb->posts,'post_parent','ID',$wc_variation_id);
						if($variation_manage_stock=='yes'){
							$wc_product_id = $wc_variation_id;
						}else{								
							if($parent_id){
								$wc_product_id = $parent_id;
								$is_variation_parent = true;
							}
						}
					}else{
						$wc_product_id = $map_data_c['W_P_ID'];
					}

					if(!$wc_product_id){
						continue;
					}

					$product_meta = get_post_meta($wc_product_id);
					if(!is_array($product_meta) || empty($product_meta)){
						continue;
					}

					$_manage_stock = (isset($product_meta['_manage_stock'][0]))?$product_meta['_manage_stock'][0]:'no';
					$_backorders = (isset($product_meta['_backorders'][0]))?$product_meta['_backorders'][0]:'no';
					$_stock = (isset($product_meta['_stock'][0]))?$product_meta['_stock'][0]:0;
					if(is_null($_stock) || empty($_stock)){
						$_stock = 0;
					}

					$is_valid_wc_inventory = false;
					if($_manage_stock=='yes'){
						$is_valid_wc_inventory = true;
					}

					if(!$is_valid_wc_inventory){
						continue;
					}

					# Parent
					if($is_variation_parent){
						if(!$this->is_xero_connected()){
							continue;
						}

						$parent_x_item_id = $this->get_field_by_val($this->gdtn('map_products'),'X_P_ID','W_P_ID',$wc_product_id);
						if(empty($parent_x_item_id)){
							continue;
						}
						
						$xp_qty = false;
						$result = $this->X_API_I()->getItem($this->XeroTenantId,$parent_x_item_id);
						if(!empty($result)){
							$items = $result->getItems();
							if(is_array($items) && !empty($items)){
								$xp_item = $items[0];
								$QuantityOnHand = $xp_item->getQuantityOnHand();
								$xp_qty = true;
							}
						}

						if(!$xp_qty){
							continue;
						}
					}

					$QuantityOnHand = floatval($QuantityOnHand);
					$_stock = floatval($_stock);

					// WooCommerce does not support decimal inventory; treat as match if integer parts are equal
					// to prevent infinite re-sync when Xero has a decimal qty (e.g. Xero=19.8, WC=19). Refs #82
					if($QuantityOnHand!=$_stock && (int)$QuantityOnHand !== (int)$_stock){
						$_stock = number_format(floatval($_stock),2);
						wc_update_product_stock($wc_product_id,(int) $QuantityOnHand);

						$QuantityOnHand = number_format(floatval($QuantityOnHand),2);

						$x_lt_id = (!empty($item_code))?$item_code:$ItemID;
						
						$log_title = $ltp.'Import Inventory #'.$x_lt_id;
						$log_details = "WooCommerce Product #{$wc_product_id} stock updated from {$_stock} to {$QuantityOnHand}".$ext_log;
						$this->save_log(array('type'=>'Inventory','title'=>$log_title,'details'=>$log_details,'status'=>1,'wc_id'=>$wc_product_id,'xero_id'=>$ItemID));
					}else{
						# Same Qty
					}
				}
			}
		}
	}

	public function PaymentPull_UpdateWooCommerceOrderStatus($order_id,$InvoiceNumber){
		$order_id = (int) $order_id;
		if($order_id > 0 && !empty($InvoiceNumber)){
			global $wpdb;
			# HPOS orders do not live in $wpdb->posts, so the legacy post_status read returned empty and
			# this whole method silently did nothing on HPOS stores — no status update, no payment log.
			# Everything below compares against the `wc-` prefixed internal form: post_status uses it, and
			# both the payment_pull / prevent_payment_pull settings store keys straight out of
			# wc_get_order_statuses() (see admin/partials/settings.php). get_status() returns the
			# UNPREFIXED status, so re-prefix it here or those comparisons would silently never match.
			# 'trash' / 'auto-draft' are not wc- prefixed and must stay as-is.
			if($this->is_hpos_enabled()){
				$_o_obj = wc_get_order($order_id);
				$e_order_status = '';
				if($_o_obj && is_a($_o_obj,'WC_Order')){
					$_o_status = (string) $_o_obj->get_status();
					if($_o_status === 'trash' || $_o_status === 'auto-draft' || strpos($_o_status,'wc-') === 0){
						$e_order_status = $_o_status;
					}elseif($_o_status !== ''){
						$e_order_status = 'wc-'.$_o_status;
					}
				}
			}else{
				$e_order_status = $this->get_field_by_val($wpdb->posts,'post_status','ID',$order_id);
			}
			if(!empty($e_order_status)){
				if($e_order_status == 'trash' || $e_order_status == 'auto-draft'){
					return false;
				}

				$prevent_status_l = $this->get_option('mw_wc_xero_sync_prevent_payment_pull_wc_order_status');
				if(!empty($prevent_status_l)){
					$prevent_status_l = explode(',',$prevent_status_l);
					if(is_array($prevent_status_l) && in_array($e_order_status,$prevent_status_l)){
						return false;
					}
				}

				$ext_log = '';
				$ltp = '';

				$n_order_status = $this->get_option('mw_wc_xero_sync_payment_pull_wc_order_status');
				if(!empty($n_order_status) && $e_order_status != $n_order_status){					
					$order = new WC_Order($order_id);
					$r = $order->update_status( $n_order_status );
					if($r){
						$w_order_statuses = wc_get_order_statuses();
						if(is_array($w_order_statuses)){
							if(isset($w_order_statuses[$e_order_status])){
								$e_order_status = $w_order_statuses[$e_order_status];
							}

							if(isset($w_order_statuses[$n_order_status])){
								$n_order_status = $w_order_statuses[$n_order_status];
							}

							$order_note = 'Order status changed from '.$e_order_status.' to '.$n_order_status;
							$order_note.=PHP_EOL;
							$order_note.='Payment Pull - MyWorks WooCommerce Sync for Xero';
							$order->add_order_note($order_note);
							
							$log_title = $ltp.'Pull Payment for order #'.$InvoiceNumber;
							$log_details = "WooCommerce Order #{$InvoiceNumber} status changed from {$e_order_status} to {$n_order_status}".$ext_log;
							$this->save_log(array('type'=>'Payment','title'=>$log_title,'details'=>$log_details,'status'=>1,'wc_id'=>$order_id,'xero_id'=>''));
						}
					}else{
						$wp_err_txt = 'Order Status Update Error';
						$log_title = $ltp.'Pull Payment Error for order #'.$InvoiceNumber;
						$log_details = "Error: ".$wp_err_txt;
						$this->save_log(array('type'=>'Payment','title'=>$log_title,'details'=>$log_details,'status'=>0,'wc_id'=>$order_id,'xero_id'=>''));
					}
				}
			}
		}	
		
		return false;
	}

	public function X_Pull_Payment(){
		if($this->is_xero_connected()){
			$if_modified_since = null;

			$now = new DateTime("now", new DateTimeZone($this->get_xero_timezone()));
			$datetime = $now->format('Y-m-d H:i:s');

			$last_timestamp = $this->get_option('mw_wc_xero_last_payment_pull_timestamp');
			if(!empty($last_timestamp)){
				$if_modified_since = $last_timestamp;
			}else{
				$interval_mins = 5;				
				$datetime_m = gmdate('Y-m-d H:i:s',strtotime("-{$interval_mins} minutes",strtotime($datetime)));
				$if_modified_since = gmdate('Y-m-d', strtotime($datetime_m)) . 'T' . gmdate('H:i:s', strtotime($datetime_m));				
			}

			$last_timestamp = gmdate('Y-m-d', strtotime($datetime)) . 'T' . gmdate('H:i:s', strtotime($datetime));
			if(!empty($last_timestamp)){
				$this->update_option('mw_wc_xero_last_payment_pull_timestamp',$last_timestamp);
			}

			$where = 'Invoice.Type="ACCREC" AND Status != "DELETED"';
			$page = null;
			$result = $this->X_API_I()->getPayments($this->XeroTenantId,$if_modified_since,$where,null,$page);
			if(!empty($result)){
				$payments = $result->getPayments();
				if(is_array($payments) && !empty($payments)){
					foreach($payments as $payment){
						if(!empty($payment->getInvoice()) && $payment->getInvoice()->getType() == 'ACCREC'){
							$InvoiceID = $payment->getInvoice()->getInvoiceID();
							$InvoiceNumber = $payment->getInvoice()->getInvoiceNumber();
							if(!empty($InvoiceNumber)){
								$order_id = $this->check_if_woocommerce_order_exists_by_xero_iq_number($InvoiceNumber);
								if($order_id){
									$this->PaymentPull_UpdateWooCommerceOrderStatus($order_id,$InvoiceNumber);
								}
							}
						}
					}
				}
			}
		}
	}

	/**
	 * @param array|null  $shared_items Items already fetched by X_Pull_Items(); when null this
	 *                                  fetches its own, so a standalone call behaves as before.
	 * @param string|null $run_started  Watermark stamped before the shared fetch, to store on
	 *                                  success. Ignored (recomputed) on the standalone path.
	 */
	public function X_Pull_Product($shared_items=null,$run_started=null){
		if($this->is_xero_connected()){
			$items = $shared_items;
			if(!is_array($items)){
				# Stamp before the fetch, not after processing — see X_Pull_Items(). Refs #87
				$run_started = $this->get_pull_watermark_now();
				$if_modified_since = $this->get_pull_watermark('mw_wc_xero_last_product_pull_timestamp',5);
				$items = $this->x_get_items_since($if_modified_since);
			}

			# Watermark used to be written BEFORE the API call, so a failed call permanently skipped
			# that window and those products were never pulled. Refs #87
			if(!is_array($items) || empty($run_started)){
				return;
			}

			if(!empty($items)){
				foreach($items as $Item){
					$this->AddUpdateWooCommerceProduct($Item);
				}
			}

			$this->update_option('mw_wc_xero_last_product_pull_timestamp',$run_started);
		}
	}

	public function X_Pull_Product_By_Id($ItemId){		
		if(!empty($ItemId) && $this->is_xero_connected()){			
			$ItemId = $this->sanitize($ItemId);
			$result = $this->X_API_I()->getItem($this->XeroTenantId,$ItemId);

			if(!empty($result)){
				$items = $result->getItems();
				if(is_array($items) && !empty($items)){
					$Item = $items[0];					
					
					return $this->AddUpdateWooCommerceProduct($Item);
				}
			}
		}

		return false;
	}

	protected function AddUpdateWooCommerceProduct($Item){
		if(!is_object($Item) || empty($Item)){
			return false;
		}

		$ItemId = $Item->getItemID();
		
		$item_data = array(
			'Name' => $Item->getName(),
			'Code' => $Item->getCode(),
			'Description' => $Item->getDescription(),
			'IsTrackedAsInventory' => $Item->getIsTrackedAsInventory(),
			'UnitPrice' => 0,
			'TaxType' => '',

		);
		
		if(!empty($Item->getSalesDetails())){
			$item_data['UnitPrice'] = floatval($Item->getSalesDetails()->getUnitPrice());
			$item_data['TaxType'] = $Item->getSalesDetails()->getTaxType();
		}

		if($Item->getIsTrackedAsInventory()){
			$item_data['QuantityOnHand'] = $Item->getQuantityOnHand();
		}
		
		$item_data['X_P_ID'] = $ItemId;
		$mapped_data = $this->if_xero_item_exists_in_woo($item_data,false);

		$ltp = '';
		$x_lt_id = (!empty($item_data['Code']))?$item_data['Code']:$ItemId;
		$ext_log = (!empty($item_data['Name']))?PHP_EOL.'Name: '.$item_data['Name']:'';

		if(empty($mapped_data)){						
			$wc_product_data = array();
			$wc_product_data['wp_error'] = true;

			$wc_product_data['post_title'] = $item_data['Name'];
			$wc_product_data['post_content'] = $item_data['Description'];

			$post_status = $this->get_option('mw_wc_xero_sync_pulled_product_wc_status','draft');
			$wc_product_data['post_status'] = $post_status;

			$wc_product_meta = array();

			if(!empty($item_data['Code'])){
				$wc_product_meta['_sku'] = $item_data['Code'];
			}

			$_manage_stock = ($item_data['IsTrackedAsInventory'])?'yes':'no';						
			$wc_product_meta['_manage_stock'] = $_manage_stock;

			if($_manage_stock == 'yes'){
				$_stock = $item_data['QuantityOnHand'];
				$wc_product_meta['_stock'] = $_stock;
				if($_stock && $_stock>0){
					$wc_product_meta['_stock_status'] = 'instock';
				}else{
					$wc_product_meta['_stock_status'] = 'outofstock';
				}
			}

			if($_manage_stock == 'no' || $this->get_option('woocommerce_manage_stock')  != 'yes'){
				$wc_product_meta['_stock_status'] = 'instock';
			}

			$_tax_status = (!empty($item_data['TaxType']) && $item_data['TaxType'] != 'NONE')?'taxable':'';
			$wc_product_meta['_tax_status'] = $_tax_status;

			$_price = $item_data['UnitPrice'];
			$wc_product_meta['_regular_price'] = $_price;
			$wc_product_meta['_price'] = $_price;

			$wc_product_meta['total_sales'] = '0';
			$wc_product_meta['_downloadable'] = 'no';
			$wc_product_meta['_visibility'] = 'visible';
			$wc_product_meta['_virtual'] = 'no';

			$wc_product_meta['_purchase_note'] = '';

			$tax_input = array();

			#$this->_p($Item);
			#$this->_p($wc_product_data);
			#$this->_p($wc_product_meta);
			#return false;

			$return = $this->save_wp_post('product',$wc_product_data,$wc_product_meta,$tax_input);
			if(!is_wp_error($return) && (int) $return){
				$post_id = (int) $return;							
				$this->save_xero_product_variation_map($post_id,$ItemId,false,true);

				$log_title = $ltp.'Import Product #'.$x_lt_id;
				$log_details = "Product #{$x_lt_id} has been imported, WooCommerce Product ID is #".$post_id.$ext_log;
				$this->save_log(array('type'=>'Product','title'=>$log_title,'details'=>$log_details,'status'=>1,'wc_id'=>$post_id,'xero_id'=>$ItemId));
				
				return true;
			}else{
				if(isset($wc_product_data['wp_error'])){
					$log_details = 'Error: '.$return->get_error_message();
				}else{
					$log_details = 'Error: Wordpress save post error';
				}

				$log_title = $ltp.'Import Product Error #'.$x_lt_id;
				$this->save_log(array('type'=>'Product','title'=>$log_title,'details'=>$log_details,'status'=>0,'wc_id'=>0,'xero_id'=>$ItemId));
			}
		}else{
			# Update
		}

		return false;
	}	

	public function pp_interval_d_options(){
		if($this->is_plg_lc_p_l() || $this->is_plg_lc_p_r()){
			$arr = [
				'MWXS_5min',
				'MWXS_10min',
				'MWXS_15min',				
			];

			if($this->is_plg_lc_p_l()){
				$arr[] = 'MWXS_30min';
			}

			return $arr;
		}

		return [];
	}

	public function pp_interval_start_option(){
		if($this->is_plg_lc_p_l()){
			return 'MWXS_60min';
		}

		if($this->is_plg_lc_p_r()){
			return 'MWXS_30min';
		}

		return 'MWXS_5min';
	}

	##-->[End]<--##
	
}
