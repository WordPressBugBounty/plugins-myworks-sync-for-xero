<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// Reason: MWXS is the established plugin prefix (short form of MyWorks Xero Sync)
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
// Reason: Custom database tables for queue and sync management require direct database access

/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://myworks.software
 * @since      1.0.0
 *
 * @package    MyWorks_WC_Xero_Sync
 * @subpackage MyWorks_WC_Xero_Sync/admin
 */

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    MyWorks_WC_Xero_Sync
 * @subpackage MyWorks_WC_Xero_Sync/admin
 * @author     MyWorks Software <support@myworks.software>
 */
class MyWorks_WC_Xero_Sync_Admin {

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param      string    $plugin_name       The name of this plugin.
	 * @param      string    $version    The version of this plugin.
	 */
	
	private $dlobj;
	public function __construct( $plugin_name, $version ) {

		$this->plugin_name = $plugin_name;
		$this->version = $version;
		
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- MWXS is the established plugin prefix (short form of MyWorks Xero Sync)
		global $MWXS_L, $MWXS_A;
		if(class_exists('MyWorks_WC_Xero_Sync_Lib')){
			$MWXS_L = new MyWorks_WC_Xero_Sync_Lib();
		}

		if(class_exists('MyWorks_WC_Xero_Sync_Wc_Data_List')){
			$this->dlobj = new MyWorks_WC_Xero_Sync_Wc_Data_List();
		}

		$MWXS_A = $this;
		
		# Ajax Request Page
		require_once plugin_dir_path( __FILE__ ).'ajax-actions.php';
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
	 * Register the stylesheets for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function admin_enqueue_styles($hook) {		

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in MyWorks_WC_Xero_Sync_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The MyWorks_WC_Xero_Sync_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */
		
		global $MWXS_L;
		wp_enqueue_style( $this->plugin_name.'-widget', plugin_dir_url( __FILE__ ) . 'css/wc-widget-css.css', array(), $this->version, 'all' );

		// Enqueue inline styles (previously output via echo)
		wp_enqueue_style( $this->plugin_name.'-inline', plugin_dir_url( __FILE__ ) . 'css/myworks-sync-for-xero-admin-inline.css', array(), $this->version, 'all' );

		if($MWXS_L->is_plugin_admin_page()){
			wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/'.$this->plugin_name.'-admin.css', array(), $this->version, 'all' );
		}
		
		if($MWXS_L->is_plugin_admin_page('connection')){
			wp_enqueue_style( $this->plugin_name.'-bootstrap-min', plugin_dir_url( __FILE__ ) . 'css/bootstrap.min.css', array(), '5.2.3', 'all' );
			wp_enqueue_style( $this->plugin_name.'-connection', plugin_dir_url( __FILE__ ) . 'css/connection-page.css', array(), $this->version, 'all' );
		}

		if($this->is_include_css_js_lib('select2')){
			wp_enqueue_style( $this->plugin_name.'-select2', plugin_dir_url( __FILE__ ) . 'css/select2.min.css', array(), '4.0.13', 'all' );
		}

		if($this->is_include_css_js_lib('bootstrap-switch')){
			wp_enqueue_style( $this->plugin_name.'-bootstrap-switch', plugin_dir_url( __FILE__ ) . 'css/bootstrap-switch.css', array(), '3.3.4', 'all' );
		}

		if($this->is_include_css_js_lib('toggle-switch')){
			wp_enqueue_style( $this->plugin_name.'-toggle-switch', plugin_dir_url( __FILE__ ) . 'css/toggle-switch.css', array(), $this->version, 'all' );
		}

		if($this->is_include_css_js_lib('extra')){
			wp_enqueue_style( $this->plugin_name.'-extra', plugin_dir_url( __FILE__ ) . 'css/admin-pages-extra.css', array(), $this->version, 'all' );
			wp_enqueue_style( $this->plugin_name.'-font-awesome', plugin_dir_url( __FILE__ ) . 'css/font-awesome.css', array(), $this->version, 'all' );
		}
		
		if($this->is_include_css_js_lib('sweetalert')){
			wp_enqueue_style( $this->plugin_name.'-sweetalert', plugin_dir_url( __FILE__ ) . 'css/sweetalert.css', array(), $this->version, 'all' );
		}

		if($this->is_include_css_js_lib('datepicker')){
			# themes/base
			wp_enqueue_style( $this->plugin_name.'-jquery-ui', plugin_dir_url( __FILE__ ) . 'css/jquery-ui.min.css', array(), '1.13.2', 'all' );
		}
	}
	
	/**
	 * Register the JavaScript for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function admin_enqueue_scripts($hook) {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in MyWorks_WC_Xero_Sync_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The MyWorks_WC_Xero_Sync_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */
		
		global $MWXS_L;
		# New
		$is_order_edit_page = false;
		if($hook === 'post.php'){
			global $post;
			if(isset($post) && ($this->is_hpos_enabled() ? (wc_get_order($post->ID) !== false) : $post->post_type === 'shop_order')){
				$is_order_edit_page = true;
			}
		}
		
		// Also check for HPOS order edit pages
		if($this->is_hpos_enabled() && ($hook === 'woocommerce_page_wc-orders' || strpos($hook, 'wc-orders') !== false)){
			$is_order_edit_page = true;
		}

		if($is_order_edit_page || $MWXS_L->is_plugin_admin_page()){
			wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/'.$this->plugin_name.'-admin.js', array( 'jquery' ), $this->version, false );

			// If on order edit page, pass sync data for meta box
			if ($is_order_edit_page) {
				global $post, $theorder;

				// Get order ID from post or theorder
				$order_id = 0;
				if ($this->is_hpos_enabled() && isset($theorder)) {
					$order_id = $theorder->get_id();
				} elseif (isset($post) && $post->ID) {
					$order_id = $post->ID;
				}

				if ($order_id > 0) {
					$sync_window_url = $MWXS_L->get_sync_window_url();

					wp_localize_script($this->plugin_name, 'mwxsSyncData', array(
						'syncUrl' => esc_url_raw($sync_window_url),
						'orderId' => absint($order_id),
					));
				}
			}
		}

		if($this->is_include_css_js_lib('select2')){
			wp_enqueue_script( $this->plugin_name.'-select2', plugin_dir_url( __FILE__ ) . 'js/select2.min.js', array(), '4.0.13', true );
		}

		if($this->is_include_css_js_lib('tablesorter')){
			wp_enqueue_script( $this->plugin_name.'-tablesorter', plugin_dir_url( __FILE__ ) . 'js/jquery.tablesorter.min.js', array('jquery'), '2.31.3', true );
		}

		if($this->is_include_css_js_lib('bootstrap-switch')){
			wp_enqueue_script( $this->plugin_name.'-bootstrap-switch', plugin_dir_url( __FILE__ ) . 'js/bootstrap-switch.min.js', array(), '3.3.4', true );
		}
		
		if($this->is_include_css_js_lib('chart')){
			wp_enqueue_script( $this->plugin_name.'-chart', plugin_dir_url( __FILE__ ) . 'js/chart.umd.min.js', array(), '4.2.1', true );
		}
		
		if($this->is_include_css_js_lib('sweetalert')){
			wp_enqueue_script( $this->plugin_name.'-sweetalert', plugin_dir_url( __FILE__ ) . 'js/sweetalert.min.js', array(), '2.1.2', false );
		}
		
		if($this->is_include_css_js_lib('datepicker')){
			wp_enqueue_script('jquery-ui-datepicker');
		}
	}

	public function enqueue_styles() {
		#->
	}

	public function enqueue_scripts() {
		#->
	}

	# Check if lib needed in page
	private function is_include_css_js_lib($lib){
		global $MWXS_L;
		if($lib == 'select2'){
			if($MWXS_L->is_plugin_admin_page('map') || $MWXS_L->is_plugin_admin_page('settings') || $MWXS_L->is_plugin_admin_page('compatibility')){
				return true;
			}
		}

		if($lib == 'tablesorter'){
			if($MWXS_L->is_plugin_admin_page('map') || $MWXS_L->is_plugin_admin_page('push') || $MWXS_L->is_plugin_admin_page('pull')){
				return true;
			}
		}

		if($lib == 'bootstrap-switch'){
			if($MWXS_L->is_plugin_admin_page('map','payment-method') || $MWXS_L->is_plugin_admin_page('settings') || $MWXS_L->is_plugin_admin_page('compatibility')){
				return true;
			}
		}

		if($lib == 'toggle-switch'){
			if($MWXS_L->is_plugin_admin_page('settings')){
				return true;
			}
		}

		if($lib == 'chart'){
			if($MWXS_L->is_plugin_admin_page('','dashboard')){
				return true;
			}
		}

		if($lib == 'extra'){
			if($MWXS_L->is_plugin_admin_page('map') || $MWXS_L->is_plugin_admin_page('settings')){
				return true;
			}
		}

		if($lib == 'sweetalert'){
			if($MWXS_L->is_plugin_admin_page('map') || $MWXS_L->is_plugin_admin_page('settings') || $MWXS_L->is_plugin_admin_page('compatibility')){
				return true;
			}
		}

		if($lib == 'datepicker'){
			if($MWXS_L->is_plugin_admin_page('push','order') || $MWXS_L->is_plugin_admin_page('push','payment')){
				return true;
			}
		}

		return false;
	}
	
	# Admin Menu
	public function create_admin_menus(){
		global $MWXS_L;
		global $wpdb;
		
		if(!class_exists('WooCommerce')) return false;
		if(!$MWXS_L->if_user_m_wc()){return false;}
		
		$parent_m_slug = 'myworks-wc-xero-sync';

		$is_license_active = $MWXS_L->is_license_active();
		
		#Main
		add_menu_page( 
			'MyWorks Sync<br><span style="font-size:10px;">Xero</span>',
			'MyWorks Sync<br><span style="font-size:10px;">Xero</span>',
			'read', 
			$parent_m_slug,
			array($this, 'admin_menu_home'),
			plugin_dir_url( __FILE__ ) . 'image/menu-icon-sync.png', 
			3
		);
		
		#Dashboard		
		add_submenu_page( 
			$parent_m_slug,
			__( 'Dashboard', 'myworks-sync-for-xero' ),
			__( 'Dashboard', 'myworks-sync-for-xero' ),
			'read',
			'myworks-wc-xero-sync',
			array($this, 'admin_menu_home')
		);				
		
		#Queue
		if($is_license_active){
			$sqm = true;
			if($sqm){
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom plugin table for queue management
			$q_i_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `" . esc_sql($MWXS_L->gdtn('queue')) . "` WHERE `id` >0 AND `run` = 0 ");
				add_submenu_page(
					$parent_m_slug,
					__( 'Queue', 'myworks-sync-for-xero' ),
					/* translators: %d: number of items in queue */
					sprintf( __( 'Queue (%d)', 'myworks-sync-for-xero' ), $q_i_count ),
					'read',
					'myworks-wc-xero-sync-queue',
					array($this, 'admin_menu_queue')
				);
			}
		}
		
		#Connection
		add_submenu_page(
			$parent_m_slug,
			__( 'Connection', 'myworks-sync-for-xero' ),
			__( 'Connection', 'myworks-sync-for-xero' ),
			'read',
			'myworks-wc-xero-sync-connection',
			array($this, 'admin_menu_connection')
		);
		
		if($is_license_active){
			if($MWXS_L->is_xero_connected() || $MWXS_L->is_queue_sync_e()){
				#Settings
				add_submenu_page(
					$parent_m_slug,
					__( 'Settings', 'myworks-sync-for-xero' ),
					__( 'Settings', 'myworks-sync-for-xero' ),
					'read',
					'myworks-wc-xero-sync-settings',
					array($this, 'admin_menu_settings')
				);
			}
			
			#Log
			add_submenu_page(
				$parent_m_slug,
				__( 'Log', 'myworks-sync-for-xero' ),
				__( 'Log', 'myworks-sync-for-xero' ),
				'read',
				'myworks-wc-xero-sync-log',
				array($this, 'admin_menu_log')
			);
			
			if($MWXS_L->is_xero_connected() || $MWXS_L->is_queue_sync_e()){
				#Map
				add_submenu_page(
					$parent_m_slug,
					__( 'Map', 'myworks-sync-for-xero' ),
					__( 'Map', 'myworks-sync-for-xero' ),
					'read',
					'myworks-wc-xero-sync-map',
					array($this, 'admin_menu_map')
				);
				
				#Push
				add_submenu_page(
					$parent_m_slug,
					__( 'Push', 'myworks-sync-for-xero' ),
					__( 'Push', 'myworks-sync-for-xero' ),
					'read',
					'myworks-wc-xero-sync-push',
					array($this, 'admin_menu_push')
				);
				
				#Pull
				add_submenu_page(
					$parent_m_slug,
					__( 'Pull', 'myworks-sync-for-xero' ),
					__( 'Pull', 'myworks-sync-for-xero' ),
					'read',
					'myworks-wc-xero-sync-pull',
					array($this, 'admin_menu_pull')
				);
				
				#Compatibility
				$scm = true;
				if($scm){
					add_submenu_page(
						$parent_m_slug,
						__( 'Compatibility', 'myworks-sync-for-xero' ),
						__( 'Compatibility', 'myworks-sync-for-xero' ),
						'read',
						'myworks-wc-xero-sync-compatibility',
						array($this, 'admin_menu_compatibility')
					);
				}
			}
		}
	}
	
	# Menu Functions
	private function include_admin_menu_page($page){
		$ap_xc_ra = array(			
			'connection',
			'settings',
		);
		
		// Security: Validate page parameter to prevent file inclusion attacks
		$allowed_pages = array(
			'dashboard',
			'queue', 
			'connection',
			'settings',
			'log',
			'map',
			'push',
			'pull',
			'compatibility'
		);
		
		// Sanitize and validate the page parameter
		$page = sanitize_file_name($page);
		if (!in_array($page, $allowed_pages, true)) {
			// Log security violation
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				wc_get_logger()->warning( 'MW Xero Sync Security: Invalid page access attempt: ' . sanitize_text_field( $page ), array( 'source' => 'myworks-sync-for-xero' ) );
			}
			wp_die('Invalid page request', 'Security Error', array('response' => 403));
		}
		
		global $MWXS_L;
		
		#Session - Plugin Admin Pages
		$MWXS_L->initialize_session();
		
		if($MWXS_L->is_queue_sync_e() && in_array($page,$ap_xc_ra)){
			$MWXS_L->xero_connect();
		}
		
		require_once plugin_dir_path( __FILE__ ) .'admin-page-hcj-functions.php';
		
		// Security: Use validated page name with path verification
		$partial_file = plugin_dir_path( __FILE__ ) . 'partials/' . $page . '.php';
		
		// Additional security check: verify file exists and is within expected directory
		if (!file_exists($partial_file) || !is_readable($partial_file)) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				wc_get_logger()->warning( 'MW Xero Sync Security: Attempted to include non-existent file: ' . sanitize_text_field( $partial_file ), array( 'source' => 'myworks-sync-for-xero' ) );
			}
			wp_die('Page not found', 'Security Error', array('response' => 404));
		}
		
		// Verify file is within the partials directory (prevent directory traversal)
		$real_path = realpath($partial_file);
		$expected_dir = realpath(plugin_dir_path( __FILE__ ) . 'partials/');
		
		if ($real_path === false || strpos($real_path, $expected_dir) !== 0) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				wc_get_logger()->warning( 'MW Xero Sync Security: Directory traversal attempt detected: ' . sanitize_text_field( $partial_file ), array( 'source' => 'myworks-sync-for-xero' ) );
			}
			wp_die('Invalid file path', 'Security Error', array('response' => 403));
		}
		
		require_once $partial_file;

		# Hide WP Admin Notices in Plugin Pages
		myworks_woo_sync_for_xero_hide_wp_notices();
	}
	
	public function admin_menu_home(){
		$this->include_admin_menu_page('dashboard');
	}
	
	public function admin_menu_queue(){
		$this->include_admin_menu_page('queue');
	}
	
	public function admin_menu_connection(){
		$this->include_admin_menu_page('connection');
	}
	
	public function admin_menu_settings(){
		$this->include_admin_menu_page('settings');
	}
	
	public function admin_menu_log(){
		$this->include_admin_menu_page('log');
	}
	
	public function admin_menu_map(){
		$this->include_admin_menu_page('map');
	}	
	
	public function admin_menu_push(){
		$this->include_admin_menu_page('push');
	}
	
	public function admin_menu_pull(){
		$this->include_admin_menu_page('pull');
	}
	
	public function admin_menu_compatibility(){
		$this->include_admin_menu_page('compatibility');
	}

	# All Cron Schedules
	public function hook_cron_schedules($schedules){
		global $MWXS_L;
		if(!isset($schedules["MWXS_5min"])){
			$schedules["MWXS_5min"] = array(
				'interval' => 5*60,
				'display' => esc_html__('Once every 5 minutes', 'myworks-sync-for-xero')
			);
		}

		if(!isset($schedules["MWXS_10min"])){
			$schedules["MWXS_10min"] = array(
				'interval' => 10*60,
				'display' => esc_html__('Once every 10 minutes', 'myworks-sync-for-xero')
			);
		}

		if(!isset($schedules["MWXS_15min"])){
			$schedules["MWXS_15min"] = array(
				'interval' => 15*60,
				'display' => esc_html__('Once every 15 minutes', 'myworks-sync-for-xero')
			);
		}

		if(!isset($schedules["MWXS_30min"])){
			$schedules["MWXS_30min"] = array(
				'interval' => 30*60,
				'display' => esc_html__('Once every 30 minutes', 'myworks-sync-for-xero')
			);
		}

		if(!isset($schedules["MWXS_60min"])){
			$schedules["MWXS_60min"] = array(
				'interval' => 60*60,
				'display' => esc_html__('Once every 1 hour', 'myworks-sync-for-xero')
			);
		}

		if(!isset($schedules["MWXS_360min"])){
			$schedules["MWXS_360min"] = array(
				'interval' => 360*60,
				'display' => esc_html__('Once every 6 hour', 'myworks-sync-for-xero')
			);
		}

		return $schedules;
	}

	# Set Queue Cron
	public function mwxs_queue_cron_set(){
		global $MWXS_L;
		$ppi_d_options = $MWXS_L->pp_interval_d_options();
		$qcit = $MWXS_L->get_option('mw_wc_xero_sync_queue_cron_interval_time');

		if(empty($qcit) || in_array($qcit,$ppi_d_options)){
			$qcit = $MWXS_L->pp_interval_start_option();
		}

		$qc_hook_name = 'mw_wc_xero_sync_queue_cron_hook';

		$is_set_cron = true;
		if($is_set_cron){
			if(!wp_next_scheduled($qc_hook_name)){		
				wp_schedule_event(time(), $qcit, $qc_hook_name);
			}
		}

		add_action($qc_hook_name, array($this,'mwxs_process_queue'));
	}

	# Set Inventory Pull Cron - Using this for all pull now
	public function mwxs_ivnt_pull_cron_set(){
		global $MWXS_L;
		$s_rt_pull_items = $MWXS_L->get_option('mw_wc_xero_sync_rt_pull_items');
		if(!empty($s_rt_pull_items)){
			$s_rt_pull_items = explode(',',$s_rt_pull_items);
		}

		if(is_array($s_rt_pull_items) && !empty($s_rt_pull_items)){
			if(in_array('Inventory',$s_rt_pull_items) || in_array('Product',$s_rt_pull_items) || in_array('Pricing',$s_rt_pull_items) || in_array('Cost',$s_rt_pull_items) || in_array('Payment',$s_rt_pull_items)){
				$ppi_d_options = $MWXS_L->pp_interval_d_options();
				$ipit = $MWXS_L->get_option('mw_wc_xero_sync_ivnt_pull_interval_time');
				if(empty($ipit) || in_array($ipit,$ppi_d_options)){
					$ipit = $MWXS_L->pp_interval_start_option();
				}
				
				$ip_hook_name = 'mw_wc_xero_sync_ivnt_pull_hook';

				$is_set_cron = true;
				if($is_set_cron){
					if(!wp_next_scheduled($ip_hook_name)){		
						wp_schedule_event(time(), $ipit, $ip_hook_name);
					}
				}

				add_action($ip_hook_name, array($this,'mwxs_process_ivnt_pull'));
			}
		}
	}

	# Daily cron to pre-warm / re-validate local Xero sync state for the Orders list. Refs #83
	public function mwxs_sync_status_cron_set(){
		global $MWXS_L;

		$ss_hook_name = 'mw_wc_xero_sync_status_reconcile_hook';
		if(!wp_next_scheduled($ss_hook_name)){
			wp_schedule_event(time(), 'daily', $ss_hook_name);
		}

		# Reconcile is self-guarding (connected + sync-as Invoice + bounded + rate-limited).
		add_action($ss_hook_name, array($MWXS_L,'reconcile_order_xero_sync_status'));
	}

	/**
	 * Run one queue item's push hook with an exception boundary, and report success as a bool.
	 *
	 * WHY THIS EXISTS - the failure it prevents is severe and was observed, not theorised. In
	 * mwxs_process_queue() the `UPDATE ... SET run = 1` for a row sits AFTER its hook call, so
	 * anything that escapes the hook kills the whole cron request: the row stays at run = 0, the next
	 * tick re-selects the SAME failing row, and every row behind it starves. The queue wedges
	 * permanently with nothing in the log to say why, because the end-of-run log never executes
	 * either. On 2026-08-17 a staging store sat like that for an hour - one order retried every five
	 * minutes, its payment and a second order stuck behind it, and the last "Queue Sync Run" entry
	 * eleven days old.
	 *
	 * All six item types need it, not just Order: they share the identical dispatch-then-UPDATE shape,
	 * so any one of them could wedge the queue the same way. Several Xero calls reachable from these
	 * hooks still have no boundary of their own (check_xero_payment_get_obj's getPayments,
	 * check_xero_refund_get_obj's getCreditNotes, the per-order getTrackingCategory lookups), which is
	 * exactly why containment belongs HERE, at the one choke point, rather than being chased call site
	 * by call site.
	 *
	 * Throwable, not Exception, so an Error/TypeError from the vendored SDK is contained too. A
	 * contained failure marks the row 'e' and the drain moves on - a stuck row is worse than a
	 * recorded failure, because the merchant can see and retry the latter.
	 *
	 * @param string $method    Hook method on this class, e.g. 'hook_order_add'.
	 * @param array  $args      Argument array passed to it.
	 * @param string $item_type Queue item type, for the log entry.
	 * @param int    $item_id   Queue item id, for the log entry.
	 * @return bool True only when the hook itself returned truthy.
	 */
	private function mwxs_queue_run_hook($method,$args,$item_type,$item_id){
		global $MWXS_L;

		try{
			return (bool) call_user_func(array($this,$method),$args);
		}catch (\Throwable $e) {
			$MWXS_L->save_log(array(
				'type'    => $item_type,
				'title'   => 'Queue Item Failed #'.$item_id,
				# Message redacted: this boundary catches ANY throwable from six different push hooks, several of
				# which carry a customer email in the failing request URL. See MyWorks_WC_Xero_Sync_Lib::redact_log_pii().
				'details' => $item_type.' push threw and was contained so the queue could continue draining. '.get_class($e).': '.$MWXS_L->redact_log_pii($e->getMessage()).' @ '.$e->getFile().':'.$e->getLine(),
				'status'  => 0,
				'wc_id'   => (int) $item_id,
			));

			return false;
		}
	}

	#Process Queue
	/**
	 * Log the "monthly order sync limit reached" signature, at most once a day.
	 *
	 * Throttled through a transient because the limit guards fire on every order, product and
	 * inventory event, which would otherwise flood the log. Wording is deliberately
	 * conditional: the queue-adds on the limit paths only fire for sync types that have
	 * real-time push enabled, so promising that everything is queued would leave a
	 * manual-workflow merchant waiting for a reset that never syncs anything.
	 */
	public function mwxs_log_osl_limit_once(){
		if(get_transient('mw_wc_xero_sync_osl_limit_logged')){
			return;
		}

		global $MWXS_L;
		$MWXS_L->save_log(array(
			'type'    => 'Order',
			'title'   => 'Monthly Order Sync Limit Reached',
			'details' => 'Monthly limit of orders synced has been reached. Order, product and variation syncing is paused; payments and refunds continue to sync. Activity for sync types that have real-time push enabled is being queued and will sync automatically when your plan resets — anything else will need a manual push. To manage your plan and view more details, visit MyWorks Sync > Connection.',
			'status'  => 0,
		));

		set_transient('mw_wc_xero_sync_osl_limit_logged', true, DAY_IN_SECONDS);
	}

	/**
	 * Warn on the plugin's own admin pages while the monthly order sync limit is active (#319).
	 */
	public function mwxs_osl_limit_admin_notice(){
		global $MWXS_L;
		if(empty($MWXS_L) || !is_object($MWXS_L)){
			return;
		}

		// Capability gate, matching create_admin_menus(). The page-prefix check below reads a query
		// parameter, which says where the user *asked* to be, not what they are allowed to see — and
		// admin_notices fires on its own, independently of whether the menu page itself renders. This
		// notice discloses licence-plan and sync-limit state, so it needs the same
		// manage_woocommerce-level check the menu uses. See docs/rules/security.md: a nonce or a
		// screen check is not authorization.
		if(!$MWXS_L->if_user_m_wc()){
			return;
		}

		// var_g() already returns sanitize_text_field(wp_unslash(...)) — see conventions.md.
		// Strict prefix, not a substring: every plugin menu slug starts with myworks-wc-xero.
		$page = (string) $MWXS_L->var_g('page');
		if(strpos($page, 'myworks-wc-xero') !== 0){
			return;
		}

		if($MWXS_L->lp_chk_osl_allwd()){
			return;
		}

		// Materialize card-panel, not WordPress notice classes, per the admin-UI convention in
		// docs/rules/conventions.md. This only renders on the plugin's own pages, so the
		// bundled Materialize CSS is enqueued and the component is styled.
		$connection_url = admin_url('admin.php?page=myworks-wc-xero-sync-connection');
		echo '<div class="card-panel orange lighten-5 mwxs-osl-limit-notice"><p><strong>' . esc_html__('MyWorks Sync — Monthly Order Sync Limit Reached', 'myworks-sync-for-xero') . '</strong><br>' .
			sprintf(
				esc_html__('Order, product and variation syncing is paused. Activity for sync types that have real-time push enabled is still being added to the queue and will sync automatically when your plan resets next month — anything else will need a manual push. Payments and refunds continue to sync. %s', 'myworks-sync-for-xero'),
				'<a href="' . esc_url($connection_url) . '">' . esc_html__('Manage your plan', 'myworks-sync-for-xero') . '</a>'
			) . '</p></div>';
	}

	public function mwxs_process_queue(){
		global $MWXS_L;
		global $wpdb;
		#$MWXS_L->save_log(array('type'=>'Queue','title'=>'Queue Sync WP Cron','details'=>'Queue Sync Function Executed.','status'=>2));
		
		// Check rate limits before processing queue
		$rate_limits = $MWXS_L->check_xero_rate_limits();
		if ($rate_limits['daily_limit_reached']) {
			$MWXS_L->save_log(array('type'=>'Queue','title'=>'Queue Sync Rate Limited','details'=>'Daily API limit reached - queue processing skipped','status'=>0));
			return;
		}
		
		// Reduce queue size when approaching limits
		$queue_limit_n = 20; // Normal processing limit
		if ($rate_limits['should_delay']) {
			$queue_limit_n = 5; // Process fewer items when approaching limit
			$MWXS_L->save_log(array('type'=>'Queue','title'=>'Queue Sync Rate Limited','details'=>'Approaching API limits - processing reduced queue size','status'=>2));
		}

		// Each queue item sleeps 2s (deliberate, prevents duplicate Xero invoices - Refs #84), so a
		// full batch can outlast max_execution_time. The loop below resets the limit per item via
		// wc_set_time_limit(), but shared hosts commonly put set_time_limit() in disable_functions,
		// which makes that a silent no-op on exactly the hosts most likely to have a low limit. When
		// we genuinely can't extend the limit, shrink the batch instead: being killed mid-batch is
		// the real duplicate footgun, since a row's run flag is only written after its hook returns.
		$mwxs_can_extend_time = ( function_exists( 'set_time_limit' ) && strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) === false );
		$mwxs_max_exec        = (int) ini_get( 'max_execution_time' );
		if ( ! $mwxs_can_extend_time && $mwxs_max_exec > 0 ) {
			// Budget ~4s per item (2s sleep + Xero round-trip) and keep 25% headroom for the rest of the run.
			$mwxs_safe_items = (int) floor( ( $mwxs_max_exec * 0.75 ) / 4 );
			if ( $mwxs_safe_items < 1 ) {
				$mwxs_safe_items = 1;
			}
			if ( $mwxs_safe_items < $queue_limit_n ) {
				$MWXS_L->save_log(array('type'=>'Queue','title'=>'Queue Batch Size Reduced','details'=>'set_time_limit() is disabled on this host and max_execution_time is '.$mwxs_max_exec.'s, so the batch was reduced from '.$queue_limit_n.' to '.$mwxs_safe_items.' items. This avoids the run being killed part-way through, which can leave already-synced orders flagged as pending and re-push them as duplicates.','status'=>2));
				$queue_limit_n = $mwxs_safe_items;
			}
		}

		$queue_limit = " LIMIT " . (int) $queue_limit_n;
		
		// Log that rate limit monitoring is now handled by middleware
		$MWXS_L->add_wc_debug_log("Queue processing starting - rate limit monitoring via Guzzle middleware", true, false, false);
		
		# Individual Item Type IDs
		$order_ids = array();
		$payment_ids = array();
		$refund_ids = array();

		$customer_ids = array();
		$product_ids = array();
		$variation_ids = array();

		$table = $MWXS_L->gdtn('queue');

		// When the monthly cap is ALREADY exhausted at query time, keep the limit-guarded types
		// out of the batch window. The per-item recheck further down skips them, but this query
		// is capped (LIMIT 20 or fewer), so a backlog of held Order/Product/Variation rows would
		// fill every batch and starve the Payment/Refund/Customer rows behind them — each cron
		// run repeating the same blocked window until the plan resets. That is the very stall the
		// scoping exists to prevent. The per-item recheck stays, for a cap reached mid-batch.
		// The NOT IN list is a hardcoded literal, so there is nothing to prepare here.
		$osl_batch_where = '';
		if(!$MWXS_L->lp_chk_osl_allwd()){
			$osl_batch_where = " AND `item_type` NOT IN ('Order','Product','Variation')";
		}

		$sql = "SELECT * FROM `" . esc_sql($table) . "` WHERE `run` = 0" . $osl_batch_where . " ORDER BY `added_date` ASC" . $queue_limit;
		$queue_data = $MWXS_L->get_data($sql);

		if(is_array($queue_data) && !empty($queue_data)){
			# Xero Connection
			$MWXS_L->xero_connect();
			
			$log_txt = "Queue Sync Run Started".PHP_EOL;
			$queue_total_count = count($queue_data);
			$queue_run_count = 0;

			# Individual Run Counts
			$order_count = 0;
			$payment_count = 0;
			$refund_count = 0;

			$customer_count = 0;
			$product_count = 0;
			$variation_count = 0;
			
			$dfp = 'Y-m-d H:i:s';
			// max_execution_time is measured against the whole PHP request, so the budget has to be too.
			// Timing from here would ignore what the rate-limit checks, xero_connect() and the queue
			// query already spent, overestimating the time left and risking the mid-item kill this
			// guard exists to prevent.
			$mwxs_queue_started = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true );
			foreach($queue_data as $data){
				// Each item below sleeps 2s (deliberate - it prevents duplicate Xero invoices, Refs #84),
				// so a full LIMIT 20 batch spends ~40s asleep and would blow past a 30s max_execution_time.
				// A mid-batch kill is the real duplicate footgun: a row's run flag is only written after its
				// hook returns, so already-pushed orders would still look pending and get re-pushed. Reset
				// the limit per item instead of shortening the delay. wc_set_time_limit() is a no-op where
				// the host disables set_time_limit(), so this is best-effort by design.
				if( function_exists( 'wc_set_time_limit' ) ){
					wc_set_time_limit( 0 );
				}

				// The batch size was chosen up front, but a slow Xero round-trip can still burn the
				// budget mid-run. When the limit can't be extended, stop BEFORE starting an item there
				// isn't time to finish: breaking out leaves the remaining rows at run = 0 for the next
				// tick, which is safe, whereas being killed part-way through an item is exactly what
				// leaves an already-pushed order looking pending and re-pushes it as a duplicate.
				if( ! $mwxs_can_extend_time && $mwxs_max_exec > 0 ){
					$mwxs_time_left = $mwxs_max_exec - ( microtime( true ) - $mwxs_queue_started );
					if( $mwxs_time_left < 6 ){ // ~2s sleep + one Xero round-trip, plus headroom.
						$MWXS_L->save_log(array('type'=>'Queue','title'=>'Queue Run Stopped Early','details'=>'Stopped before starting the next queue item with only '.round($mwxs_time_left,1).'s of max_execution_time left, to avoid being killed mid-item. Remaining items stay queued for the next run.','status'=>2));
						break;
					}
				}

				$log_type = '';

				$row_id = $data['id'];
				$item_type = $data['item_type'];
				$item_id = (int) $data['item_id'];
				$item_action = $data['item_action'];

				// Monthly order sync limit (#319). Re-checked per item, not once per run: each
				// new order pushed below increments the month's count, so a store that queued
				// more orders than its plan allows re-hits the cap part-way through a drain.
				// Once that happens the item's own limit guard refuses it and returns false,
				// while this loop would still mark the row as run — silently discarding the
				// change. Skipping leaves the row at run = 0 so it drains after the reset.
				//
				// Scoped to the types that ARE limit-guarded. Payment, Refund and Customer are
				// deliberately unguarded (their handlers apply no limit and would succeed), so
				// holding them here would strand a payment for an invoice that is already in
				// Xero — leaving the merchant an unpaid invoice until the plan resets. `continue`
				// rather than `break` for the same reason: one held order must not stop the
				// payments and refunds behind it in the batch.
				if(in_array($item_type, array('Order','Product','Variation'), true) && !$MWXS_L->lp_chk_osl_allwd()){
					$this->mwxs_log_osl_limit_once();
					continue;
				}

				// Claim the row BEFORE dispatching it, atomically. The UPDATE only matches while `run` is
				// still 0, so when two consumers overlap - a host cron alongside WP-Cron, WP-CLI (which
				// takes no cron lock), two web nodes - exactly one changes the row and the other sees 0
				// rows and moves on. Until this, `run = 1` was written only AFTER the hook returned, so a
				// second consumer reading the table mid-push found the same row still pending and pushed
				// it again: two Xero invoices for one order, two seconds apart, on a live store. Refs #146
				//
				// Status 'r' marks a claimed-but-unfinished row; the per-type UPDATE below settles it to
				// 's'/'e' when the hook returns. If the request dies mid-push the row keeps 'r' and is NOT
				// retried: the push may already have reached Xero, and a re-push is exactly the duplicate
				// this guards against. The merchant can re-push from Push > Orders, where the existence
				// check finds an invoice that did land and takes the update path.
				//
				// Only claim what this loop actually dispatches. Rows it has never handled (a 'Pull'
				// action, for one) used to be selected and left untouched at run = 0; claiming them here
				// would silently swallow them, so they keep their old behaviour.
				if($item_action !== 'Push' || !in_array($item_type, array('Order','Payment','Refund','Customer','Product','Variation'), true)){
					continue;
				}

				$claimed = $wpdb->query($wpdb->prepare("UPDATE `" . esc_sql($table) . "` SET `run` = 1, `status` = 'r', `run_datetime` = %s WHERE `id` = %d AND `run` = 0",$MWXS_L->now($dfp),$row_id)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if($claimed !== 1){
					continue;
				}

				$success = 0;
				$run = 0;
				$status = '';

				if($item_type=='Order' && $item_action=='Push'){
					if(!in_array($item_id,$order_ids)){
						// 2 second delay between syncs to prevent duplicate order processing
						sleep(2);

						$order_ids[] = $item_id;

						// Per-order lock, shared with the manual "Push to Xero" path. The row claim above
						// stops two consumers taking this row; this stops a different caller - a manual
						// push, or a second consumer holding a duplicate row for the same order - creating
						// alongside this one. When the lock is busy the other push owns the order right now:
						// hand the row back (run = 0) so the next tick re-checks, finds the invoice that
						// push created, and takes the update path instead of creating. Refs #146
						$order_lock = $MWXS_L->acquire_order_push_lock($item_id);
						if($order_lock === ''){
							$MWXS_L->save_log(array('type'=>'Order','title'=>'Create Order Skipped #'.$item_id,'details'=>'Another sync of this order is already in progress, so this queue item was handed back to the queue for the next run instead of pushing the order a second time.','status'=>2,'wc_id'=>$item_id));
							$wpdb->query($wpdb->prepare("UPDATE `" . esc_sql($table) . "` SET `run` = 0, `status` = 'q', `run_datetime` = NULL WHERE `id` = %d",$row_id)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
							continue;
						}

						$log_type = 'Order';
						$order_count++;
						$queue_run_count++;
						$run = 1;

						if($this->mwxs_queue_run_hook('hook_order_add',array('order_id'=>$item_id,'f_q_f'=>true),'Order',$item_id)){
							$success = 1;
						}

						$MWXS_L->release_order_push_lock($item_id, $order_lock);

						$status = ($success)?'s':'e';
					}
					
					$run_datetime = $MWXS_L->now($dfp);
					$wpdb->query($wpdb->prepare("UPDATE `" . esc_sql($table) . "` SET `run` = %d, `run_datetime` = %s, `status` = %s WHERE `id` = %d",1,$run_datetime,$status,$row_id)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
				
				if($item_type=='Payment' && $item_action=='Push'){
					if(!in_array($item_id,$payment_ids)){
						// 2 second delay between syncs to prevent duplicate order processing
						sleep(2);

						$payment_ids[] = $item_id;
						$log_type = 'Payment';
						$payment_count++;
						$queue_run_count++;
						$run = 1;

						if($this->mwxs_queue_run_hook('hook_payment_add',array('order_id'=>$item_id,'f_q_f'=>true),'Payment',$item_id)){
							$success = 1;
						}

						$status = ($success)?'s':'e';
					}
					
					$run_datetime = $MWXS_L->now($dfp);
					$wpdb->query($wpdb->prepare("UPDATE `" . esc_sql($table) . "` SET `run` = %d, `run_datetime` = %s, `status` = %s WHERE `id` = %d",1,$run_datetime,$status,$row_id)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}

				if($item_type=='Refund' && $item_action=='Push'){
					if(!in_array($item_id,$refund_ids)){
						// 2 second delay between syncs to prevent duplicate order processing
						sleep(2);

						$refund_ids[] = $item_id;
						$log_type = 'Refund';
						$refund_count++;
						$queue_run_count++;
						$run = 1;

						$order_id = 0;
						$extra = $data['ext_data'];
						if(!empty($extra)){
							$extra = unserialize($extra);
							if(is_array($extra) && isset($extra['order_id'])){
								$order_id = (int) $extra['order_id'];
							}
						}

						if($this->mwxs_queue_run_hook('hook_refund_add',array('refund_id'=>$item_id,'order_id'=>$order_id,'f_q_f'=>true),'Refund',$item_id)){
							$success = 1;
						}

						$status = ($success)?'s':'e';
					}
					
					$run_datetime = $MWXS_L->now($dfp);
					$wpdb->query($wpdb->prepare("UPDATE `" . esc_sql($table) . "` SET `run` = %d, `run_datetime` = %s, `status` = %s WHERE `id` = %d",1,$run_datetime,$status,$row_id)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}

				if($item_type=='Customer' && $item_action=='Push'){
					if(!in_array($item_id,$customer_ids)){
						// 2 second delay between syncs to prevent duplicate order processing
						sleep(2);

						$customer_ids[] = $item_id;
						$log_type = 'Customer';
						$customer_count++;
						$queue_run_count++;
						$run = 1;

						if($this->mwxs_queue_run_hook('hook_user_add',array('user_id'=>$item_id,'f_q_f'=>true),'Customer',$item_id)){
							$success = 1;
						}

						$status = ($success)?'s':'e';
					}

					$run_datetime = $MWXS_L->now($dfp);
					$wpdb->query($wpdb->prepare("UPDATE `" . esc_sql($table) . "` SET `run` = %d, `run_datetime` = %s, `status` = %s WHERE `id` = %d",1,$run_datetime,$status,$row_id)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}

				if($item_type=='Product' && $item_action=='Push'){
					if(!in_array($item_id,$product_ids)){
						// 2 second delay between syncs to prevent duplicate order processing
						sleep(2);

						$product_ids[] = $item_id;
						$log_type = 'Product';
						$product_count++;
						$queue_run_count++;
						$run = 1;

						if($this->mwxs_queue_run_hook('hook_product_add',array('product_id'=>$item_id,'f_q_f'=>true),'Product',$item_id)){
							$success = 1;
						}

						$status = ($success)?'s':'e';
					}

					$run_datetime = $MWXS_L->now($dfp);
					$wpdb->query($wpdb->prepare("UPDATE `" . esc_sql($table) . "` SET `run` = %d, `run_datetime` = %s, `status` = %s WHERE `id` = %d",1,$run_datetime,$status,$row_id)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
				
				if($item_type=='Variation' && $item_action=='Push'){
					if(!in_array($item_id,$variation_ids)){
						// 2 second delay between syncs to prevent duplicate order processing
						sleep(2);

						$variation_ids[] = $item_id;
						$log_type = 'Variation';
						$variation_count++;
						$queue_run_count++;
						$run = 1;

						if($this->mwxs_queue_run_hook('hook_variation_add',array('variation_id'=>$item_id,'f_q_f'=>true),'Variation',$item_id)){
							$success = 1;
						}

						$status = ($success)?'s':'e';
					}

					$run_datetime = $MWXS_L->now($dfp);
					$wpdb->query($wpdb->prepare("UPDATE `" . esc_sql($table) . "` SET `run` = %d, `run_datetime` = %s, `status` = %s WHERE `id` = %d",1,$run_datetime,$status,$row_id)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}

			}

			if($order_count){$log_txt.="Total Order Sync Run: $order_count".PHP_EOL;}
			if($payment_count){$log_txt.="Total Payment Sync Run: $payment_count".PHP_EOL;}
			if($refund_count){$log_txt.="Total Refund Sync Run: $refund_count".PHP_EOL;}

			if($customer_count){$log_txt.="Total Customer Sync Run: $customer_count".PHP_EOL;}
			if($product_count){$log_txt.="Total Product Sync Run: $product_count".PHP_EOL;}
			if($variation_count){$log_txt.="Total Variation Sync Run: $variation_count".PHP_EOL;}

			$log_txt.="Total Items in Queue: $queue_total_count".PHP_EOL;
			$log_txt.="Queue Sync Run Ended";

			if($queue_run_count > 0){
				$MWXS_L->save_log(array('type'=>'Queue','title'=>'Queue Sync Run','details'=>$log_txt,'status'=>2));
			}
		}
	}
	
	# Process Inventory Pull - Using this for all pull now
	public function mwxs_process_ivnt_pull(){
		global $MWXS_L;
		$s_rt_pull_items = $MWXS_L->get_option('mw_wc_xero_sync_rt_pull_items');
		if(!empty($s_rt_pull_items)){
			$s_rt_pull_items = explode(',',$s_rt_pull_items);
		}

		if(is_array($s_rt_pull_items) && !empty($s_rt_pull_items)){
			if(in_array('Inventory',$s_rt_pull_items) || in_array('Product',$s_rt_pull_items) || in_array('Pricing',$s_rt_pull_items) || in_array('Cost',$s_rt_pull_items) || in_array('Payment',$s_rt_pull_items)){
				$MWXS_L->xero_connect();

				# Inventory / Product / Pricing / Cost all read the Xero Items endpoint and used to
				# fetch it once each — the same unbounded catalogue up to four times per tick, every
				# 5 minutes by default. X_Pull_Items() collapses them into as few fetches as the
				# unitdp/filter differences allow. Refs #87
				$item_pull_types = array();

				if(in_array('Inventory',$s_rt_pull_items)){
					$item_pull_types[] = 'Inventory';
				}

				if(in_array('Product',$s_rt_pull_items)){
					$item_pull_types[] = 'Product';
				}

				# Pricing stays gated on the licence tier exactly as before.
				if(in_array('Pricing',$s_rt_pull_items)){
					if(!$MWXS_L->is_plg_lc_p_l() && !$MWXS_L->is_plg_lc_p_r()){
						$item_pull_types[] = 'Pricing';
					}
				}

				if(in_array('Cost',$s_rt_pull_items)){
					$item_pull_types[] = 'Cost';
				}

				if(!empty($item_pull_types)){
					$MWXS_L->X_Pull_Items($item_pull_types);
				}

				if(in_array('Payment',$s_rt_pull_items)){
					$MWXS_L->X_Pull_Payment();
				}
			}
		}
	}

	# Admin Head
	public function mwxs_admin_head(){
		// Admin menu icon styling moved to myworks-sync-for-xero-admin-inline.css
	}
	
	# Admin Footer
	public function mwxs_admin_footer(){
		global $MWXS_L;
		require_once plugin_dir_path( __FILE__ ) .'admin-page-hcj-functions.php';
		myworks_woo_sync_for_xero_wc_admin_pages_footer_content();
	}

	#Meta Boxes
	public function mwxs_add_meta_boxes(){
		#Order Edit Page
		add_meta_box( 'mb_xs_mwxs', __('Xero Status','myworks-sync-for-xero'), array($this,'meta_box_content_mb_xs_mwxs'), 'shop_order', 'side', 'core' );
		add_meta_box('mb_xs_mwxs', __('Xero Status', 'myworks-sync-for-xero'), array($this,'meta_box_content_mb_xs_mwxs'), 'woocommerce_page_wc-orders', 'side', 'core');
	}

	# Order Edit Page Xero Status Meta Box Content
	public function meta_box_content_mb_xs_mwxs($post){
		global $MWXS_L, $theorder;

		if ($this->is_hpos_enabled()) {
			\Automattic\WooCommerce\Utilities\OrderUtil::init_theorder_object( $post );
		}

		$order = $theorder;
		if(!is_object($order)){
			return;
		}

		$order_id = (int) $order->get_id();
		if($order_id < 1){
			return;
		}

		# Only invoices show a sync status (matches prior behavior). Cheap when sync-as is the global 'Invoice'.
		if($MWXS_L->get_xero_order_sync_as($order_id) != 'Invoice'){
			return;
		}

		# Render from LOCAL state only - no live Xero call during page render (that was the 6s+ block).
		# An async re-verify (below) runs the same live check off the critical path and refreshes this box. Refs #83
		$invoice_id = $MWXS_L->get_order_xero_sync_state($order_id);
		if(!empty($invoice_id)){
			$initial = $this->mwxs_metabox_status_html('synced',$invoice_id,$MWXS_L->get_order_xero_invoice_number($order_id));
		}elseif($MWXS_L->order_xero_status_checked($order_id)){
			$initial = $this->mwxs_metabox_status_html('not_synced');
		}else{
			$initial = $this->mwxs_metabox_status_html('checking');
		}

		echo '<div id="mwxs_mb_status" data-order-id="'.esc_attr($order_id).'">'.$initial.'</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_* internally

		$mb_nonce = wp_create_nonce('myworks_wc_xero_sync_order_status_mb');
		?>
		<script>
		jQuery(function($){
			var $box = $("#mwxs_mb_status");
			if(!$box.length){ return; }
			var mwxsMbNonce = "<?php echo esc_js($mb_nonce); ?>";
			var mwxsMbOrderId = $box.data("order-id");

			// Verification failed. Render the explicit "could not check" state — never "Not Synced",
			// which would offer "Push to Xero" on what may be a false negative and risk a duplicate
			// invoice.
			//
			// Only a CONFIRMED synced result survives a failed check, identified by the
			// data-mwxs-verified marker. Do not test for #mwxs_odp_ptx here: both the synced and
			// not_synced branches render that button, so keying on it would leave "Not Synced" +
			// "Push to Xero" on screen after a failed verification — the same duplicate-invoice path.
			function mwxsMbUnverified(){
				$box.find(".mwxs-mb-refresh").removeClass("mwxs-spinning");
				if($box.find('[data-mwxs-verified="synced"]').length === 0){
					$box.html(<?php echo wp_json_encode( $this->mwxs_metabox_status_html( 'unverified' ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>);
				}
			}

			function mwxsMbLoad(){
				$.ajax({
					type:"POST", url: ajaxurl, cache:false, dataType:"json",
					// This does a live Xero lookup. Without a bound, a hung request leaves the box on
					// "Checking…" forever; with one, the error handler renders the "Could not check"
					// state and offers a retry. 30s comfortably covers a normal round-trip.
					timeout: 30000,
					data:{ action:"myworks_wc_xero_sync_order_status_mb", nonce: mwxsMbNonce, order_id: mwxsMbOrderId },
					success: function(r){
						if(r && typeof r.html === "string" && r.html !== ""){ $box.html(r.html); return; }
						// wp_send_json_error() replies with HTTP 200 and success:false, so it lands here,
						// not in the error handler — e.g. the capability check on this endpoint. Without
						// this the box would sit on "Checking…" forever.
						if(r && r.success === false){ mwxsMbUnverified(); return; }
						$box.find(".mwxs-mb-refresh").removeClass("mwxs-spinning");
					},
					error: function(){ mwxsMbUnverified(); }
				});
			}

			// Manual refresh (delegated, since the box HTML is replaced on each load). Refs #83
			$(document).on("click", ".mwxs-mb-refresh", function(e){
				e.preventDefault();
				$(this).addClass("mwxs-spinning"); // spin in place until the box is repainted
				mwxsMbLoad();
			});

			// Run the live status check only after the page (tab) has fully finished loading. Refs #83
			$(window).on("load", function(){ mwxsMbLoad(); });
		});
		</script>
		<?php
	}

	# Renders the order-edit "Xero Status" metabox body for a given state. Shared by the initial
	# (local-state) render and the async re-verify AJAX so the markup stays identical. Refs #83
	public function mwxs_metabox_status_html($state,$invoice_id='',$invoice_number=''){
		global $MWXS_L;
		ob_start();

		# Manual refresh control beside the Status label (re-checks live, mirrors the Orders list). Refs #83
		// The refresh anchor is emitted inline at each echo site below rather than held in a variable:
		// the security scan flags any variable in an echo as unescaped output, and it can't follow
		// escaping through one. Note wp_kses_post() is NOT usable here — `javascript:` isn't in
		// wp_allowed_protocols(), so it would strip the href and leave an anchor that isn't keyboard
		// focusable. Styling lives in .mwxs-mb-refresh in the admin CSS.

		if($state === 'disconnected'){
			echo '<p>'.esc_html__('Xero Not Connected','myworks-sync-for-xero').'</p>';
		}elseif($state === 'synced' && !empty($invoice_id)){
			# data-mwxs-verified marks this as a CONFIRMED synced result. The JS uses it to decide what may
			# survive a failed re-verification — it must not key on the presence of #mwxs_odp_ptx, which
			# both this branch and the not_synced branch render.
			echo '<p class="mw_qbo_sync_status_p" data-mwxs-verified="synced"><strong>'.esc_html__('Status:','myworks-sync-for-xero').'</strong> &nbsp;&nbsp;<span class="mw_qbo_sync_status_span mw_qbo_sync_status_paid">'.esc_html__('Synced','myworks-sync-for-xero').'</span>' . ' <a href="javascript:void(0);" class="mwxs-mb-refresh" title="' . esc_attr__('Refresh Xero status','myworks-sync-for-xero') . '">&#x21bb;</a></p>';
			if($invoice_number !== ''){
				echo '<p class="mw_qbo_sync_number_p"><strong>'.esc_html__('Number:','myworks-sync-for-xero').'</strong>&nbsp;<span class="mw_qbo_sync_status_span mw_qbo_sync_status_info">'.esc_html($invoice_number).'</span></p>';
			}
			echo '<p><a href="javascript:void(0);"><button id="mwxs_odp_ptx" class="button" type="button">'.esc_html__('Update in Xero','myworks-sync-for-xero').'</button></a></p>';

			$vix_href = $MWXS_L->get_xero_view_invoice_link_by_id($invoice_id);
			$pdf_url = wp_nonce_url(admin_url('admin-ajax.php?action=myworks_wc_xero_sync_order_invoice_pdf&invoice_id=' . $invoice_id),'view_xero_invoice_pdf');
			echo '<p>';
			echo '<a target="_blank" href="'.esc_url($vix_href).'" title="'.esc_attr__('Click to view it in Xero','myworks-sync-for-xero').'"><button class="button" type="button">'.esc_html__('View','myworks-sync-for-xero').'</button></a>';
			echo '<a target="_blank" href="'.esc_url($pdf_url).'" title="'.esc_attr__('View/Save as PDF','myworks-sync-for-xero').'" class="mwxs-pdf-button"><button class="button" type="button">PDF</button></a>';
			echo '</p>';
		}elseif($state === 'not_synced'){
			echo '<p class="mw_qbo_sync_status_p"><strong>'.esc_html__('Status:','myworks-sync-for-xero').'</strong> &nbsp;&nbsp;<span class="mw_qbo_sync_status_span mw_qbo_sync_status_due">'.esc_html__('Not Synced','myworks-sync-for-xero').'</span>' . ' <a href="javascript:void(0);" class="mwxs-mb-refresh" title="' . esc_attr__('Refresh Xero status','myworks-sync-for-xero') . '">&#x21bb;</a></p>';
			echo '<p><a href="javascript:void(0);"><button id="mwxs_odp_ptx" class="button" type="button">'.esc_html__('Push to Xero','myworks-sync-for-xero').'</button></a></p>';
		}elseif($state === 'unverified'){
			# Verification could not be completed (timeout, expired nonce, Xero unreachable). Deliberately
			# NOT rendered as 'Not Synced': a failed check is not proof the order is absent from Xero, and
			# offering "Push to Xero" on a false negative is how a duplicate invoice gets created. Offer a
			# retry only.
			echo '<p class="mw_qbo_sync_status_p"><strong>'.esc_html__('Status:','myworks-sync-for-xero').'</strong> &nbsp;&nbsp;<span class="mw_qbo_sync_status_span mw_qbo_sync_status_info">'.esc_html__('Could not check','myworks-sync-for-xero').'</span>' . ' <a href="javascript:void(0);" class="mwxs-mb-refresh" title="' . esc_attr__('Retry Xero status check','myworks-sync-for-xero') . '">&#x21bb;</a></p>';
			echo '<p class="description">'.esc_html__('The Xero status could not be verified. Retry before pushing, so an order already in Xero is not sent twice.','myworks-sync-for-xero').'</p>';
		}else{
			echo '<p class="mw_qbo_sync_status_p"><strong>'.esc_html__('Status:','myworks-sync-for-xero').'</strong> &nbsp;&nbsp;<span class="mw_qbo_sync_status_span mw_qbo_sync_status_info">'.esc_html__('Checking…','myworks-sync-for-xero').'</span></p>';
		}

		return ob_get_clean();
	}

	# WooCommerce Order Page Custom Column - Xero Sync Status
	public function mwxs_add_woocommerce_order_page_columns($columns){
		global $MWXS_L;
		$columns['c_xs_mwxs'] = __( 'Xero Status','myworks-sync-for-xero');
		return $columns;
	}

	/**
	 * HPOS compatible version of custom_orders_list_column_content
	 */
	public function mwxs_woocommerce_order_page_columns_content_hpos( $column, $order ){
		global $MWXS_L;

		// For HPOS, the second parameter can be either an Order object or order ID
		if ( is_object( $order ) && method_exists( $order, 'get_id' ) ) {
			// It's an Order object
			$order_id = $order->get_id();
		} else {
			// It's an order ID
			$order_id = (int) $order;
			$order = wc_get_order( $order_id );
		}
		
		if ( ! $order_id || ! $order ) {
			return;
		}

		switch($column){
			case 'c_xs_mwxs' :
				if($order_id > 0){
					$this->mwxs_render_order_xero_status_cell($order_id);
				}
				break;
		}
	}

	# Renders the "Xero Status" cell from local state — three states, all wrapped in #c_xs_{id} so the
	# Refresh button can repaint any cell. Synced/Not-Synced render server-side (no AJAX / no API);
	# only never-checked (Unknown) orders are queued for the one-time backfill AJAX. Refs #83
	private function mwxs_render_order_xero_status_cell($order_id){
		global $MWXS_L;

		$order_id = (int) $order_id;
		if($order_id < 1){
			return;
		}

		echo '<div id="c_xs_'.esc_attr($order_id).'" class="c_xs_data">';

		$invoice_id = $MWXS_L->get_order_xero_sync_state($order_id);
		if(!empty($invoice_id)){
			echo $this->mwxs_xero_status_html('synced',$invoice_id); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_* internally
		}elseif($MWXS_L->order_xero_status_checked($order_id)){
			echo $this->mwxs_xero_status_html('not_synced'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static safe markup
		}else{
			# Unknown locally -> queue for the one-time backfill AJAX and show a placeholder.
			$MWXS_L->initialize_session();
			$wc_order_id_num_list = (array) $MWXS_L->get_session_val('wc_order_id_num_list',array());
			$wc_inv_no = $MWXS_L->get_woo_ord_number_from_order($order_id);
			if(!isset($wc_order_id_num_list[$order_id])){
				$wc_order_id_num_list[$order_id] = (!empty($wc_inv_no))?$wc_inv_no:$order_id;
			}
			$MWXS_L->set_session_val('wc_order_id_num_list',$wc_order_id_num_list);
			echo '...';
		}

		echo '</div>';
	}

	# Builds the inner status markup for the Xero Status cell (matches the AJAX-rendered markup, with
	# inline display so server-rendered spans aren't hidden by the .x_ss fade rule). Refs #83
	private function mwxs_xero_status_html($state,$invoice_id=''){
		global $MWXS_L;

		if($state === 'synced'){
			$link = $MWXS_L->get_xero_view_invoice_link_by_id($invoice_id);
			$inner = esc_html__('Synced','myworks-sync-for-xero');
			if(!empty($link)){
				$title = esc_attr__('Click to view it in Xero','myworks-sync-for-xero');
				$inner = '<a style="color:white;" target="_blank" href="'.esc_url($link).'"><span title="'.$title.'">'.$inner.'</span></a>';
			}
			return '<span class="x_ss" style="display:inline;"><span class="mw_qbo_sync_status_span mw_qbo_sync_status_paid">'.$inner.'</span></span>';
		}

		return '<span class="x_ss" style="display:inline;"><span class="mw_qbo_sync_status_span mw_qbo_sync_status_due">'.esc_html__('Not Synced','myworks-sync-for-xero').'</span></span>';
	}

	public function mwxs_woocommerce_order_page_columns_content($column,$post_id=0){
		global $MWXS_L;
		global $post, $woocommerce, $the_order;

		$order_id = 0;
		if(is_object($post) && !empty($post)){
			if(isset($post->ID) && isset($post->post_type) && $post->post_type == 'shop_order'){
				$order_id = (int) $post->ID;
			}
		}

		# Fallback to the post ID WordPress passes as the second argument. The hook is
		# registered with accepted_args 2, but this callback used to declare only one.
		if(!$order_id && $post_id){
			$order_id = (int) $post_id;
		}

		switch($column){
			case 'c_xs_mwxs' :
				if($order_id > 0){
					$this->mwxs_render_order_xero_status_cell($order_id);
				}
				break;
		}
	}

	#Init Hooks
	private function call_init_hooks(){
		
	}

	private function call_admin_init_hooks(){
		// HPOS compatible admin column hooks
		if ($this->is_hpos_enabled()) {
			// HPOS enabled - use WooCommerce's HPOS-compatible hooks
			add_filter( 'manage_woocommerce_page_wc-orders_columns', array($this,'mwxs_add_woocommerce_order_page_columns'), 11);
			add_action( 'manage_woocommerce_page_wc-orders_custom_column', array($this,'mwxs_woocommerce_order_page_columns_content_hpos'), 10, 2 );
		} else {
			// Legacy - traditional post-based orders
			add_filter( 'manage_edit-shop_order_columns', array($this,'mwxs_add_woocommerce_order_page_columns'),11);
			add_action( 'manage_shop_order_posts_custom_column' , array($this,'mwxs_woocommerce_order_page_columns_content'), 10, 2 );
		}

		# Admin Head
		add_action('admin_head', array($this,'mwxs_admin_head'));

		# Admin Footer
		add_action('admin_footer', array($this,'mwxs_admin_footer'));

		#Meta Boxes
		add_action( 'add_meta_boxes', array($this,'mwxs_add_meta_boxes') );
	}
	
	# Hook Functions
	public function mwxs_hook_init(){
		global $MWXS_L;
		
		$MWXS_L->init();
		$this->call_init_hooks();
		$this->mwxs_queue_cron_set();
		$this->mwxs_ivnt_pull_cron_set();
		$this->mwxs_sync_status_cron_set();
	}

	public function mwxs_hook_admin_init(){
		global $MWXS_L;
		$this->call_admin_init_hooks();
	}
	
	# Sync Hook Functions
	
	public function hook_user_add($user_info,$from_order=false,$customer_data=array()){
		if(!class_exists('WooCommerce')) return false;
		global $MWXS_L;
		
		$sync_instant = false;
		$manual = false;
		
		if(is_array($user_info)){
			$user_id = (int) $user_info['user_id'];
			if(isset($user_info['f_p_p'])){
				$sync_instant = true;
				$manual = true;
			}

			if(isset($user_info['f_q_f'])){
				$sync_instant = true;
				if(isset($user_info['manual']) && $user_info['manual']){
					$manual = true;
				}
			}
		}else{
			$user_id = (int) $user_info;
		}
		
		if(!$manual && !$from_order && !$MWXS_L->check_if_real_time_push_enable_for_item('Customer')){
			return false;
		}
		
		if($user_id > 0){
			$user_data = get_userdata($user_id);
			$wc_user_role = $MWXS_L->get_wc_user_role_by_id($user_id,$user_data);
			
			# Sync all orders to one Xero Customer
			if($MWXS_L->option_checked('mw_wc_xero_sync_s_all_orders_to_one_xero_customer')){
				$io_cs = true;
				
				if(!empty($wc_user_role)){
					$aotc_rcm_data = get_option('mw_wc_xero_sync_aotc_rcm_data');
					if(is_array($aotc_rcm_data) && !empty($aotc_rcm_data)){
						if(isset($aotc_rcm_data[$wc_user_role]) && !empty($aotc_rcm_data[$wc_user_role])){
							if($aotc_rcm_data[$wc_user_role] != 'Individual'){
								$io_cs = false;								
							}
						}
					}
				}
				
				if(!$io_cs){
					return false;
				}
			}
			
			# Wc user roles as customer - Disabled for now
			$is_sync_user_role = true;
			
			if(!$is_sync_user_role && isset($user_data->roles) && is_array($user_data->roles)){
				$sc_roles = $MWXS_L->get_option('mw_wc_xero_sync_wc_user_roles_as_customer');
				if(!empty($sc_roles)){
					$sc_roles = explode(',',$sc_roles);
					if(is_array($sc_roles) && count($sc_roles)){
						foreach($sc_roles as $sr){
							if(in_array($sr,$user_data->roles)){
								$is_sync_user_role = true;
								break;
							}
						}
					}
				}

				if(!$is_sync_user_role){
					if(in_array('customer',$user_data->roles)){
						$is_sync_user_role = true;						
					}
				}
			}
			
			if(!$manual && !$is_sync_user_role){
				return false;
			}
			
			#Queue-Add
			if(!$sync_instant){
				$MWXS_L->wx_queue_add('Customer',$user_id,'Push',2);				
				return;
			}
			
			if(empty($customer_data)){
				$customer_data = $MWXS_L->get_wc_customer_info($user_id,$user_data,$manual);
			}
			
			if(!empty($customer_data)){
				# null = the Xero lookup failed, so we do not know whether this contact exists. Bail
				# rather than fall into the create branch: null is falsey, so the old `if(!$id)` read an
				# outage as "not in Xero" and created a duplicate. X_Add_Customer() now refuses this too,
				# but returning here keeps the reason in the log instead of a bare false. Refs #87
				$xero_contact_id = $MWXS_L->if_xero_customer_exists($customer_data);
				if($xero_contact_id === null){
					return false;
				}

				if(!$xero_contact_id){
					$MWXS_L->xero_connect();
					# Add					
					$ContactID = $MWXS_L->X_Add_Customer($customer_data);			
					if(!empty($ContactID) && strlen($ContactID) == 36){
						return true;
					}
				}else{
					#Update
				}
			}
		}		
		
	}
	
	public function hook_order_add($order_info){
		if(!class_exists('WooCommerce')) return false;
		global $MWXS_L;

		//$MWXS_L->add_wc_debug_log( $order_info, true, true, true );
		
		$sync_instant = false;
		$manual = false;
		
		if(is_array($order_info)){
			$order_id = (int) $order_info['order_id'];
			if(isset($order_info['f_p_p'])){
				$sync_instant = true;
				$manual = true;
			}

			if(isset($order_info['f_q_f'])){
				$sync_instant = true;
				if(isset($order_info['manual']) && $order_info['manual']){
					$manual = true;
				}
			}
		}else{
			$order_id = (int) $order_info;
			#$MWXS_L->save_log(array('type'=>'Order','title'=>'Order Hook Add Test #'.$order_id,'details'=>'Test','status'=>2));
		}
		
		if(!$manual && !$MWXS_L->check_if_real_time_push_enable_for_item('Order')){
			return false;
		}

		if($order_id > 0){
			// Monthly order sync limit (#319): queue the order instead of dropping it, so it
			// syncs automatically when the plan resets, and log the limit once a day so
			// support keeps a signature for "orders stopped syncing".
			if(!$MWXS_L->lp_chk_osl_allwd()){
				$this->mwxs_log_osl_limit_once();

				// item_action must be 'Push' — that is what the drain dispatches on
				// (mwxs_process_queue: item_type=='Order' && item_action=='Push'). The O_S_A
				// ext_data the instant path passes is not needed here: the drain re-enters
				// hook_order_add(), which recomputes it via get_xero_order_sync_as().
				if($MWXS_L->check_if_real_time_push_enable_for_item('Order') && !$MWXS_L->wx_queue_exists('Order',$order_id,'Push')){
					$MWXS_L->wx_queue_add('Order',$order_id,'Push',1,'mw_osl_limit:hook_order_add');
				}

				return false;
			}
			
			# Minimum Order ID Restriction
			$ord_min_id  = (int) $MWXS_L->get_option('mw_wc_xero_sync_block_syncing_orders_before_id');
			if($ord_min_id > 0 && $order_id < $ord_min_id){
				if($manual){
					$error_data = array('type'=>'Order','title'=>'Create Order Error #'.$order_id,'details'=>'Order sync not allowed for ID less than #'.$ord_min_id,'status'=>0);
					$MWXS_L->save_log($error_data);
				}
				return false;
			}
			
			$order = $this->is_hpos_enabled() ? wc_get_order($order_id) : get_post($order_id);	
			if(!is_object($order) || empty($order)){
				if($manual){					
					$MWXS_L->save_log(array('type'=>'Order','title'=>'Create Order Error #'.$order_id,'details'=>'Woocommerce order not found.','status'=>0));
				}
				return false;
			}

			$order_type = $this->is_hpos_enabled() && $order ? $order->get_type() : $order->post_type;
			
			if($order_type!='shop_order'){
				if($manual){
					$MWXS_L->save_log(array('type'=>'Order','title'=>'Create Order Error #'.$order_id,'details'=>'Woocommerce order is not valid.','status'=>0));
				}
				return false;
			}

			$order_status = method_exists($order, 'get_status') ? 'wc-'.$order->get_status() : $order->post_status;
			
			if($order_status=='auto-draft'){
				return false;
			}
			
			if(!$manual && $order_status=='draft'){
				return false;
			}

			# Order Status Restriction (Realtime)
			$only_sync_status = $MWXS_L->get_option('mw_wc_xero_sync_s_order_when_status_in');
			if(!empty($only_sync_status)){
				$only_sync_status = explode(',',$only_sync_status);
			}

			if(!$manual && (!is_array($only_sync_status) || (is_array($only_sync_status) && !in_array($order_status,$only_sync_status)))){
				return false;
			}
			
			# $0 Order Restriction
			if($MWXS_L->option_checked('mw_wc_xero_sync_do_not_sync_0_orders')){
				$_order_total = $this->is_hpos_enabled() && $order ? (float) $order->get_total() : (float) get_post_meta($order_id,'_order_total',true);
				if($_order_total == 0 || $_order_total < 0){
					if($manual){
						$error_data = array('type'=>'Order','title'=>'Create Order Error #'.$order_id,'details'=>'Syncing Order amount 0 not allowed in settings','status'=>0);
						$MWXS_L->save_log($error_data);
					}
					return false;
				}
			}
			
			$invoice_data = $MWXS_L->get_wc_order_details_from_order($order_id,$order,$manual);
			if(!empty($invoice_data)){
				$order_sync_as = $MWXS_L->get_xero_order_sync_as($order_id,$invoice_data);
				#Queue-Add
				if(!$sync_instant){
					$extra = array('O_S_A'=>$order_sync_as);
					$MWXS_L->wx_queue_add('Order',$order_id,'Push',1,'',$extra);
					return;
				}
				
				$xero_connect_f_called = false;
				
				$wc_cus_id = (int) $MWXS_L->get_array_isset($invoice_data,'wc_cus_id',0,false);
				$wc_user_role = $MWXS_L->get_array_isset($invoice_data,'wc_user_role','');
				$is_guest = ($wc_cus_id > 0)?false:true;

				$customer_xrt_check = ($is_guest)?true:false;
				#$customer_xrt_check = true;

				$is_sync_customer = true;
				
				# Xero Connection
				if($customer_xrt_check){				
					$MWXS_L->xero_connect();
					$xero_connect_f_called = true;
				}
				
				$X_ContactID = $MWXS_L->get_xero_customer_for_order_sync($invoice_data);
				
				if(!empty($X_ContactID)){
					$is_sync_customer = false;
				}

				if($is_sync_customer && $MWXS_L->option_checked('mw_wc_xero_sync_s_all_orders_to_one_xero_customer')){
					if(!empty($wc_user_role)){		
						$aotc_rcm_data = get_option('mw_wc_xero_sync_aotc_rcm_data');
						if(is_array($aotc_rcm_data) && !empty($aotc_rcm_data)){
							if(isset($aotc_rcm_data[$wc_user_role]) && !empty($aotc_rcm_data[$wc_user_role])){
								if($aotc_rcm_data[$wc_user_role] != 'Individual'){
									$is_sync_customer = false;
								}
							}
						}
					}
				}

				if($is_sync_customer){
					if(!$xero_connect_f_called){
						$MWXS_L->xero_connect();
						$xero_connect_f_called = true;
					}

					if($wc_cus_id > 0){
						$customer_data = $MWXS_L->get_wc_customer_info($wc_cus_id);
						$customer_data['order_id'] = $order_id;
						$X_ContactID = $MWXS_L->check_save_get_xero_customer_id($customer_data);						
					}else{
						$customer_data = $MWXS_L->get_wc_customer_info_from_order($order_id);
						$X_ContactID = $MWXS_L->check_save_get_xero_guest_id($customer_data);						
					}
				}
				
				if(empty($X_ContactID)){
					$error_data = array('type'=>'Order','title'=>'Create Order Error #'.$order_id,'details'=>'Xero customer not found.','status'=>0);
					$MWXS_L->save_log($error_data);
					return false;

				}

				$invoice_data['X_ContactID'] = $X_ContactID;
				
				# Xero Connection
				if(!$xero_connect_f_called){
					$MWXS_L->xero_connect();
				}				

				if($order_sync_as == 'Invoice'){
					$return = false;
					$_payment_method = $MWXS_L->get_array_isset($invoice_data,'_payment_method','',true);
					$_order_currency = $MWXS_L->get_array_isset($invoice_data,'_order_currency','',true);
					$_paid_date = $MWXS_L->get_array_isset($invoice_data,'_paid_date','');

					$_currency_applicable = $_order_currency;
					$pm_map_data = $MWXS_L->get_mapped_payment_method_data($_payment_method,$_currency_applicable);
					
					$enable_payment = (int) $MWXS_L->get_array_isset($pm_map_data,'enable_payment',0,false);
					$aps_order_status = $MWXS_L->get_array_isset($pm_map_data,'aps_order_status','',true);
					$is_artificial_payment = false;
					$current_order_status = $this->is_hpos_enabled() ? $order->get_status() : $order->post_status;
					if($enable_payment && !empty($aps_order_status) && $current_order_status == $aps_order_status){
						$is_artificial_payment = true;
					}

					$sync_artificial_or_not_synced_payment = false;
					if($is_artificial_payment || ($enable_payment && !empty($_paid_date))){
						$sync_artificial_or_not_synced_payment = true;
					}
					
					$enable_refund = (int) $MWXS_L->get_array_isset($pm_map_data,'enable_refund',0,false);
					if($MWXS_L->is_plg_lc_p_l() || $MWXS_L->is_plg_lc_p_r() || $MWXS_L->is_plg_lc_p_empty()){
						$enable_refund = false;
					}
					
					$xero_invoice_object = $MWXS_L->check_xero_invoice_get_obj($invoice_data);
					if(!$xero_invoice_object){

						//$MWXS_L->add_wc_debug_log($invoice_data, true, true, true );

						# Add
						$InvoiceID = $MWXS_L->X_Add_Invoice($invoice_data);			
						if(!empty($InvoiceID) && strlen($InvoiceID) == 36){
							# Payment
							if(!empty($_paid_date) && (($manual && isset($order_info['f_p_p'])) || !$MWXS_L->wx_queue_exists('Payment',$order_id,'Push'))){
								$this->hook_payment_add(array('order_id'=>$order_id,'f_p_p'=>true));
								$sync_artificial_or_not_synced_payment = false;
							}

							# Refund
							if($enable_refund){
								$refund_ids = $MWXS_L->get_wc_refund_ids_by_order_id($order_id);
								if(!empty($refund_ids)){
									foreach($refund_ids as $refund_id){
										if(($manual && isset($order_info['f_p_p'])) || !$MWXS_L->wx_queue_exists('Refund',$refund_id,'Push')){
											$this->hook_refund_add(array('refund_id'=>$refund_id,'order_id'=>$order_id,'f_p_p'=>true));
										}
									}
								}
							}														
												
							$return = true;
						}
					}else{
						# Update
						# Check if invoice has payments in Xero before attempting update
						if($MWXS_L->if_xero_payment_exists($invoice_data,$xero_invoice_object)){
							// Skip update - Xero doesn't allow updates to paid invoices
							$log_data = array(
								'type'    => 'Order',
								'title'   => 'Update Order Skipped #'.$order_id,
								'details' => 'Invoice already has payment(s) applied in Xero. Xero does not allow updates to invoices with payments.',
								'status'  => 2, // Info status
								'wc_id'   => $order_id,
								'xero_id' => (string) $xero_invoice_object->getInvoiceID(),
							);
							$MWXS_L->save_log($log_data);
							$return = false;
						}else{
							$InvoiceID = $MWXS_L->X_Update_Invoice($invoice_data,$xero_invoice_object);
							if(!empty($InvoiceID) && strlen($InvoiceID) == 36){
								$return = true;
							}
						}
						
						# Refund
						if($enable_refund){
							$refund_ids = $MWXS_L->get_wc_refund_ids_by_order_id($order_id);
							if(!empty($refund_ids)){
								foreach($refund_ids as $refund_id){
									if(!$MWXS_L->wx_queue_exists('Refund',$refund_id,'Push')){
										$CreditNoteId = $MWXS_L->X_Add_CreditNote($refund_id,$invoice_data);		
										if(!empty($CreditNoteId) && strlen($CreditNoteId) == 36){
											#-> Refund Added
										}
									}
								}
							}
						}
					}
					
					# Artificial Payment Sync
					if($sync_artificial_or_not_synced_payment){
						$invoice_data['is_artificial_payment'] = $is_artificial_payment;
						if(!$xero_payment_id = $MWXS_L->if_xero_payment_exists($invoice_data,$xero_invoice_object)){
							$PaymentId = $MWXS_L->X_Add_Payment($invoice_data,$xero_invoice_object);		
							if(!empty($PaymentId) && strlen($PaymentId) == 36){
								#-> Payment Added
							}
						}
					}

					return $return;
				}
				
				if($order_sync_as == 'Quote'){
					#->
				}
			}
		}
	}
	
	public function hook_order_update($post_ID, $post_after, $post_before){
		
	}
	
	public function hook_order_cancelled($order_id){
		if(!class_exists('WooCommerce')) return false;
		global $MWXS_L;

		if($MWXS_L->is_plg_lc_p_l() || $MWXS_L->is_plg_lc_p_r() || $MWXS_L->is_plg_lc_p_empty()){
			return false;
		}

		if(!$MWXS_L->option_checked('mw_wc_xero_sync_void_xero_order_on_wc_cancelled')){
			return false;
		}

		if(!$MWXS_L->check_if_real_time_push_enable_for_item('Order')){
			return false;
		}

		$order_id = (int) $order_id;
		if($order_id < 1){
			return false;
		}

		$order = $this->is_hpos_enabled() ? wc_get_order($order_id) : get_post($order_id);
		if(!is_object($order) || empty($order)){
			return false;
		}

		$order_type = $this->is_hpos_enabled() && $order ? $order->get_type() : $order->post_type;
		if($order_type !== 'shop_order'){
			return false;
		}

		$order_status = $this->is_hpos_enabled() ? $order->get_status() : str_replace('wc-', '', $order->post_status);
		if($order_status !== 'cancelled'){
			return false;
		}

		$invoice_data = $MWXS_L->get_wc_order_details_from_order($order_id, $order);
		if(empty($invoice_data)){
			return false;
		}

		// The try starts BEFORE xero_connect(): both check_xero_invoice_get_obj() (getInvoices) and
		// if_xero_payment_exists() (getPayments) hit the Xero API, and they used to sit outside the
		// boundary. A transport failure or SDK exception there bypassed the error log and order note
		// entirely and could abort the whole cancellation hook. Catching \Throwable rather than
		// Exception also covers the SDK's TypeError/Error cases on unexpected payloads.
		try{
			$MWXS_L->xero_connect();
			if(!$MWXS_L->is_xero_connected()){
				return false;
			}

			$xero_invoice_object = $MWXS_L->check_xero_invoice_get_obj($invoice_data);
			if(!$xero_invoice_object){
				$MWXS_L->save_log(array('type'=>'Order','title'=>'Void Order Error #'.$order_id,'details'=>'Xero invoice not found for this order.','status'=>0));
				return false;
			}

			$xero_invoice_id = $xero_invoice_object->getInvoiceID();
			if(empty($xero_invoice_id)){
				return false;
			}

			// Cannot void an invoice that has a payment applied
			if($MWXS_L->if_xero_payment_exists($invoice_data, $xero_invoice_object)){
				$wc_inv_num = $MWXS_L->get_array_isset($invoice_data,'wc_inv_num','');
				$ord_id_num = (!empty($wc_inv_num)) ? $wc_inv_num : $order_id;
				$MWXS_L->save_log(array('type'=>'Order','title'=>'Void Order Error #'.$ord_id_num,'details'=>'Cannot void Xero invoice #'.$xero_invoice_id.' — a payment is already applied. Remove the payment in Xero first.','status'=>0));
				$wc_order = wc_get_order( $order_id );
				if($wc_order){
					$wc_order->add_order_note( __( 'Failed to void order in Xero — a payment is already applied to the Xero invoice. Remove the payment in Xero first.', 'myworks-sync-for-xero' ) );
				}
				return false;
			}

			// Use the existing invoice object and change its status to VOIDED
			$Invoice = $xero_invoice_object;
			$Invoice->offsetUnset('line_items');
			$Invoice->offsetUnset('sub_total');
			$Invoice->offsetUnset('total_tax');
			$Invoice->offsetUnset('total');
			$Invoice->offsetUnset('total_discount');
			$Invoice->setStatus('VOIDED');

			$Arr_Invoices = array($Invoice);
			$Invoices = new XeroAPI\XeroPHP\Models\Accounting\Invoices;
			$Invoices->setInvoices($Arr_Invoices);

			$result = $MWXS_L->X_API_I()->updateInvoice($MWXS_L->get_xero_tenant_id(), $xero_invoice_id, $Invoices);

			if(!empty($result)){
				$xr_invoices = $result->getInvoices();
				if(is_array($xr_invoices) && !empty($xr_invoices)){
					$x_invoice = $xr_invoices[0];
					if(!$x_invoice->getHasErrors()){
						$wc_inv_num = $MWXS_L->get_array_isset($invoice_data,'wc_inv_num','');
						$ord_id_num = (!empty($wc_inv_num)) ? $wc_inv_num : $order_id;
						$MWXS_L->save_log(array(
							'type'   => 'Order',
							'title'  => 'Void Order #'.$ord_id_num,
							'details'=> 'Order #'.$ord_id_num.' voided in Xero successfully.'.PHP_EOL.'Xero Invoice ID #'.$xero_invoice_id,
							'status' => 1,
							'wc_id'  => $order_id,
							'xero_id'=> $xero_invoice_id,
						));
						$wc_order = wc_get_order( $order_id );
						if($wc_order){
							$wc_order->add_order_note( __( 'Order voided in Xero successfully. Xero Invoice ID: ', 'myworks-sync-for-xero' ) . $xero_invoice_id );
						}
						# Record the voided status locally (keeps id so the Orders list still shows "Synced", matching prior behavior). Refs #83
						$MWXS_L->set_order_xero_sync_state($order_id,$xero_invoice_id,'VOIDED');
						return true;
					}
				}
			}

			$MWXS_L->save_log(array('type'=>'Order','title'=>'Void Order Error #'.$order_id,'details'=>'Failed to void Xero invoice.','status'=>0));
			$wc_order = wc_get_order( $order_id );
			if($wc_order){
				$wc_order->add_order_note( __( 'Failed to void order in Xero.', 'myworks-sync-for-xero' ) );
			}
		}catch(\Throwable $e){
			$MWXS_L->save_log(array('type'=>'Order','title'=>'Void Order Error #'.$order_id,'details'=>'Exception: '.$e->getMessage(),'status'=>0));
			$wc_order = wc_get_order( $order_id );
			if($wc_order){
				$wc_order->add_order_note( __( 'Failed to void order in Xero. Error: ', 'myworks-sync-for-xero' ) . $e->getMessage() );
			}
		}

		return false;
	}
	
	public function hook_refund_add($order_info=0,$refund_id=0){
		if(!class_exists('WooCommerce')) return false;
		global $MWXS_L;

		if($MWXS_L->is_plg_lc_p_l() || $MWXS_L->is_plg_lc_p_r() || $MWXS_L->is_plg_lc_p_empty()){
			return false;
		}
		
		$sync_instant = false;
		$manual = false;

		if(is_array($order_info)){
			$order_id = (int) $order_info['order_id'];
			$refund_id = (int) $order_info['refund_id'];
			if(isset($order_info['f_p_p'])){
				$sync_instant = true;
				$manual = true;
			}

			if(isset($order_info['f_q_f'])){
				$sync_instant = true;
				if(isset($order_info['manual']) && $order_info['manual']){
					$manual = true;
				}
			}
		}else{
			$order_id = (int) $order_info;
			if((int) $refund_id < 1 && $order_id > 0){
				$refund_ids = $MWXS_L->get_wc_refund_ids_by_order_id($order_id);
				if(!empty($refund_ids)){
					$refund_id = (int) $refund_ids[count($refund_ids) - 1];
				}
			}			
		}
		
		if(!$manual && !$MWXS_L->check_if_real_time_push_enable_for_item('Order')){
			return false;
		}

		if($order_id > 0 && $refund_id > 0){
			$order = $this->is_hpos_enabled() ? wc_get_order($order_id) : get_post($order_id);			
			if(!is_object($order) || empty($order)){
				if($manual){
					$error_data = array('type'=>'Refund','title'=>'Create Refund Error #'.$refund_id,'details'=>'Woocommerce order not found.','status'=>0);
					$MWXS_L->save_log($error_data);
				}
				return false;
			}

			$order_type = $this->is_hpos_enabled() && $order ? $order->get_type() : $order->post_type;
			
			if(!$order || $order_type !== 'shop_order'){
				if($manual){
					$error_data = array('type'=>'Refund','title'=>'Create Refund Error #'.$refund_id,'details'=>'Woocommerce order is not valid.','status'=>0);
					$MWXS_L->save_log($error_data);
				}
				return false;
			}

			if(method_exists($order, 'get_status') ? (in_array($order->get_status(), array('auto-draft', 'trash', 'draft'))) : ($order->post_status=='auto-draft' || $order->post_status=='trash' || $order->post_status=='draft')){				
				return false;
			}

			$invoice_data = $MWXS_L->get_wc_order_details_from_order($order_id,$order,$manual);

			if(!empty($invoice_data)){				
				$_payment_method = $MWXS_L->get_array_isset($invoice_data,'_payment_method','',true);
				$_order_currency = $MWXS_L->get_array_isset($invoice_data,'_order_currency','',true);

				$_currency_applicable = $_order_currency;

				$pm_map_data = $MWXS_L->get_mapped_payment_method_data($_payment_method,$_currency_applicable);
				$enable_refund = (int) $MWXS_L->get_array_isset($pm_map_data,'enable_refund',0,false);

				if($enable_refund !== 1){
					return false;
				}
				
				#Queue-Add
				if(!$sync_instant){
					$extra = ['order_id'=>$order_id];
					$MWXS_L->wx_queue_add('Refund',$refund_id,'Push',0,'',$extra);
					return;
				}

				$order_sync_as = $MWXS_L->get_xero_order_sync_as($order_id,$invoice_data);

				$xero_order_object = null;

				# Xero Connection
				$MWXS_L->xero_connect();

				if(!$MWXS_L->is_xero_connected()){
					return false;
				}

				# Xero Order check before refund sync
				if($order_sync_as == 'Invoice'){
					$xero_order_object = $MWXS_L->check_xero_invoice_get_obj($invoice_data);
				}

				if(!$xero_order_object){
					$MWXS_L->save_log(array('type'=>'Refund','title'=>'Create Refund Error #'.$refund_id,'details'=>'Xero order not found.','status'=>0));
					return false;
				}

				$invoice_data['X_ContactID'] = $xero_order_object->getContact()->getContactId();

				$xero_credit_note_id = $MWXS_L->if_xero_refund_exists($refund_id,$invoice_data);

				if(!$xero_credit_note_id){
					# Add - Refund doesn't exist, create new credit note
					$invoice_data['X_Exists_Checked'] = true;
					$CreditNoteId = $MWXS_L->X_Add_CreditNote($refund_id,$invoice_data);
					if(!empty($CreditNoteId) && strlen($CreditNoteId) == 36){
						return true;
					}
				} else {
					# Update - Refund already exists in Xero
					return true; // Success - refund already synced
				}
			}			
		}

		return false;
	}
	
	public function hook_payment_add($order_info){
		if(!class_exists('WooCommerce')) return false;
		global $MWXS_L;
		
		$sync_instant = false;
		$manual = false;
		
		if(is_array($order_info)){
			$order_id = (int) $order_info['order_id'];
			if(isset($order_info['f_p_p'])){
				$sync_instant = true;
				$manual = true;
			}

			if(isset($order_info['f_q_f'])){
				$sync_instant = true;
				if(isset($order_info['manual']) && $order_info['manual']){
					$manual = true;
				}
			}
		}else{
			$order_id = (int) $order_info;
		}
		
		if(!$manual && !$MWXS_L->check_if_real_time_push_enable_for_item('Payment')){
			return false;
		}
		
		if($order_id > 0){

			$order = $this->is_hpos_enabled() ? wc_get_order($order_id) : get_post($order_id);
			$order_type = $this->is_hpos_enabled() && $order ? $order->get_type() : $order->post_type;
			$order_status = $this->is_hpos_enabled() ? $order->get_status() : $order->post_status;
			
			if(!is_object($order) || empty($order)){
				if($manual){					
					$MWXS_L->save_log(array('type'=>'Payment','title'=>'Create Payment Error for Order #'.$order_id,'details'=>'Woocommerce order not found.','status'=>0));
				}
				return false;
			}
			
			if($order_type!='shop_order'){
				if($manual){
					$MWXS_L->save_log(array('type'=>'Payment','title'=>'Create Payment Error for Order #'.$order_id,'details'=>'Woocommerce order is not valid.','status'=>0));
				}
				return false;
			}
			
			if($order_status=='auto-draft'){
				return false;
			}
			
			if(!$manual && $order_status=='draft'){
				return false;
			}

			$invoice_data = $MWXS_L->get_wc_order_details_from_order($order_id,$order,$manual);
			if(!empty($invoice_data)){
				
				$artificial_payment_allowed = false;
				$_paid_date = $MWXS_L->get_array_isset($invoice_data,'_paid_date','');
				
				if(!$artificial_payment_allowed && empty($_paid_date)){
					if($manual){
						$MWXS_L->save_log(array('type'=>'Order','title'=>'Create Payment Error for Order #'.$order_id,'details'=>'Invalid Payment - Payment date is empty.','status'=>0));
					}					
					return false;
				}
				
				# Payment enabled for gateway check
				$_payment_method = $MWXS_L->get_array_isset($invoice_data,'_payment_method','',true);

				#$_payment_method_title = $MWXS_L->get_array_isset($invoice_data,'_payment_method_title','',true);
				$_order_currency = $MWXS_L->get_array_isset($invoice_data,'_order_currency','',true);
				if(empty($_order_currency)){
					if($manual){
						$MWXS_L->save_log(array('type'=>'Payment','title'=>'Create Payment Error for Order #'.$order_id,'details'=>'Order currency not found.','status'=>0));
					}
					return false;
				}

				$_currency_applicable = $_order_currency;

				# Empty/unmapped payment methods fall back to the "Other" mapping. Refs #78
				$pm_map_data = $MWXS_L->get_pm_map_data_with_other_fallback($_payment_method,$_currency_applicable);
				$enable_payment = (int) $MWXS_L->get_array_isset($pm_map_data,'enable_payment',0,false);
				$_order_total = (float) $MWXS_L->get_array_isset($invoice_data,'_order_total',0,false);

				$is_valid_payment = false;
				if($enable_payment == 1 && $_order_total>0){
					$is_valid_payment = true;
				}
				
				if(!$is_valid_payment){
					if($manual){
						$MWXS_L->save_log(array('type'=>'Payment','title'=>'Create Payment Error for Order #'.$order_id,'details'=>'Payment not enabled or invalid payment amount for gateway:'.$_payment_method.', currency:'.$_currency_applicable,'status'=>0));
					}
					return false;
				}

				$order_sync_as = $MWXS_L->get_xero_order_sync_as($order_id,$invoice_data);
				if($order_sync_as != 'Invoice'){
					return false;
				}

				#Queue-Add
				if(!$sync_instant){
					$extra = null;
					$MWXS_L->wx_queue_add('Payment',$order_id,'Push',0,'',$extra);
					return;
				}

				# Xero Connection
				$MWXS_L->xero_connect();

				# Xero Invoice check before payment sync
				$xero_invoice_object = $MWXS_L->check_xero_invoice_get_obj($invoice_data);				
				
				if(!$xero_invoice_object){
					$MWXS_L->save_log(array('type'=>'Order','title'=>'Create Payment Error for Order #'.$order_id,'details'=>'Xero invoice not found.','status'=>0));
					return false;
				}

				if($xero_invoice_object->getStatus() != 'AUTHORISED'){
					$MWXS_L->save_log(array('type'=>'Order','title'=>'Create Payment Error for Order #'.$order_id,'details'=>'Xero invoice not ready to accept payment.','status'=>0));
					return false;
				}

				if(!$xero_payment_id = $MWXS_L->if_xero_payment_exists($invoice_data,$xero_invoice_object)){
					# Add
					$PaymentId = $MWXS_L->X_Add_Payment($invoice_data,$xero_invoice_object);		
					if(!empty($PaymentId) && strlen($PaymentId) == 36){
						return true;
					}
				}
			}
		}
	}
	
	public function hook_product_add($product_info){
		if(!class_exists('WooCommerce')) return false;
		global $MWXS_L;
		global $wpdb;
		
		$sync_instant = false;
		$manual = false;
		
		if(is_array($product_info)){
			$product_id = (int) $product_info['product_id'];
			if(isset($product_info['f_p_p'])){
				$sync_instant = true;
				$manual = true;
			}
			
			if(isset($product_info['f_q_f'])){
				$sync_instant = true;
				if(isset($product_info['manual']) && $product_info['manual']){
					$manual = true;
				}
			}
		}else{
			$product_id = (int) $product_info;
		}
		
		# Cost can be enabled on its own. Admit it here or ticking only "Cost" would return
		# before the update branch below is ever reached. Refs #109
		$push_product = ($manual || $MWXS_L->check_if_real_time_push_enable_for_item('Product'));
		$push_cost = ($manual || $MWXS_L->check_if_real_time_push_enable_for_item('Cost'));

		if(!$push_product && !$push_cost){
			return false;
		}
		
		if($product_id>0){
			// Monthly order sync limit (#319): all syncing pauses, so hold the change in the
			// queue instead of pushing it, and let it drain when the plan resets.
			if(!$MWXS_L->lp_chk_osl_allwd()){
				$this->mwxs_log_osl_limit_once();

				// Only queue when real-time push is enabled, matching the order path. A manual
				// push sets $manual = true and so skips the real-time check above; queueing it
				// here would make it sync automatically after the reset, which is exactly what
				// a manual-only workflow does not want.
				//
				// Cost counts as well as Product. This function admits EITHER (see $push_product /
				// $push_cost above — #109 made a Cost-only configuration valid), so testing Product
				// alone silently dropped every cost change made while the limit was exhausted: the
				// enablement gate let it in, this branch queued nothing, and `return false` lost it
				// with no retry after the plan reset. The drain re-enters this hook with f_q_f, where
				// $push_cost is still true, so a queued row syncs the cost correctly.
				if(($MWXS_L->check_if_real_time_push_enable_for_item('Product') || $MWXS_L->check_if_real_time_push_enable_for_item('Cost')) && !$MWXS_L->wx_queue_exists('Product',$product_id,'Push')){
					$MWXS_L->wx_queue_add('Product',$product_id,'Push',2,'mw_osl_limit:hook_product_add');
				}

				return false;
			}

			#Queue-Add
			if(!$sync_instant){
				$MWXS_L->wx_queue_add('Product',$product_id,'Push',2);
				return;
			}
			
			$_product = wc_get_product($product_id);
			
			if(empty($_product)){
				$MWXS_L->save_log(array('type'=>'Product','title'=>'Create Product Error #'.$product_id,'details'=>'Woocommerce product not found','status'=>0));
				return false;
			}

			if($_product->post->post_type!='product'){
				if($manual){					
					$MWXS_L->save_log(array('type'=>'Product','title'=>'Create Product Error #'.$product_id,'details'=>'Woocommerce product is not valid.','status'=>0));
				}
				return false;
			}
			
			if($_product->post->post_status=='auto-draft'){
				return false;
			}
			
			if(!$manual && $_product->post->post_status=='draft'){
				return false;
			}
			
			# Skip Parent Variation Product
			$cvp_q = $wpdb->prepare("SELECT post_parent FROM {$wpdb->posts} WHERE post_type = 'product_variation' AND post_parent=%d",$product_id);
			$chk_v_parent = $MWXS_L->get_row($cvp_q);
			if(is_array($chk_v_parent) && !empty($chk_v_parent)){
				return false;
			}

			$r_arr = [
				'update' => 0,
				'sync_status' => 0,
				'x_id' => '',
			];
			
			$product_data = $MWXS_L->get_wc_product_info($product_id,$_product,$manual);
			if(!empty($product_data)){
				if(!isset($product_data['_manage_stock'])){
					return false;
				}
				
				if(!$xero_product_id = $MWXS_L->if_xero_product_exists($product_data)){
					# Cost on its own must not start creating products in Xero - only mapped
					# items get their cost updated. Skipping is the intended outcome here, so
					# report it as a no-op - returning false would have the queue consumer
					# record status 'e' for a $lc that was never meant to sync. Refs #109
					if(!$push_product){
						if($manual){
							$r_arr['msgs'] = ['Product is not mapped in Xero.'];
							return $r_arr;
						}

						return true;
					}

					$MWXS_L->xero_connect();
					# Add					
					$ItemID = $MWXS_L->X_Add_Product($product_data);
					if(!empty($ItemID) && strlen($ItemID) == 36){
						return true;
					}
				}else{
					#Update
					# The product itself is not re-pushed - only the cost, and only when the "Cost"
					# push data type is on. Refs #109
					$MWXS_L->xero_connect();
					$cost_result = $MWXS_L->X_Push_Product_Cost($xero_product_id,$product_data);

					if($manual){
						$r_arr['msgs'] = ['Product already present in Xero.'];
						return $r_arr;
					}

					# The queue consumer writes status 'e' on a falsey return. An item that is
					# already mapped and needed no cost change has not failed, so only a real
					# cost failure (false) should be reported as one. Refs #109
					return ($cost_result !== false);
				}
				
			}
		}
		
		return false;
	}
	
	public function hook_variation_add($variation_info){
		if(!class_exists('WooCommerce')) return false;
		global $MWXS_L;
		global $wpdb;
		
		$sync_instant = false;
		$manual = false;

		if(is_array($variation_info)){
			$variation_id = (int) $variation_info['variation_id'];
			if(isset($variation_info['f_p_p'])){
				$sync_instant = true;
				$manual = true;
			}
			
			if(isset($variation_info['f_q_f'])){
				$sync_instant = true;
				if(isset($variation_info['manual']) && $variation_info['manual']){
					$manual = true;
				}
			}
		}else{
			$variation_id = (int) $variation_info;
		}
		
		# Cost can be enabled on its own. Admit it here or ticking only "Cost" would return
		# before the update branch below is ever reached. Refs #109
		$push_variation = ($manual || $MWXS_L->check_if_real_time_push_enable_for_item('Variation'));
		$push_cost = ($manual || $MWXS_L->check_if_real_time_push_enable_for_item('Cost'));

		if(!$push_variation && !$push_cost){
			return false;
		}

		if($variation_id>0){
			// Monthly order sync limit (#319): all syncing pauses, so hold the change in the
			// queue instead of pushing it, and let it drain when the plan resets.
			if(!$MWXS_L->lp_chk_osl_allwd()){
				$this->mwxs_log_osl_limit_once();

				// Only queue when real-time push is enabled, and count Cost as well as Variation —
				// see the fuller note on the product path for why testing Variation alone silently
				// lost cost changes made while the limit was exhausted.
				if(($MWXS_L->check_if_real_time_push_enable_for_item('Variation') || $MWXS_L->check_if_real_time_push_enable_for_item('Cost')) && !$MWXS_L->wx_queue_exists('Variation',$variation_id,'Push')){
					$MWXS_L->wx_queue_add('Variation',$variation_id,'Push',2,'mw_osl_limit:hook_variation_add');
				}

				return false;
			}

			#Queue-Add
			if(!$sync_instant){
				$MWXS_L->wx_queue_add('Variation',$variation_id,'Push',2);
				return;
			}

			$_variation = get_post($variation_id);

			if(empty($_variation)){
				$MWXS_L->save_log(array('type'=>'Variation','title'=>'Create Variation Error #'.$variation_id,'details'=>'Woocommerce variation not found','status'=>0));
				return false;
			}
			
			if($_variation->post_type!='product_variation'){
				if($manual){					
					$MWXS_L->save_log(array('type'=>'Variation','title'=>'Create Variation Error #'.$variation_id,'details'=>'Woocommerce variation is not valid.','status'=>0));
				}
				return false;
			}

			if($_variation->post_status=='auto-draft'){
				return false;
			}
			
			if(!$manual && $_variation->post_status=='draft'){
				return false;
			}

			$r_arr = [
				'update' => 0,
				'sync_status' => 0,
				'x_id' => '',
			];
			
			$variation_data = $MWXS_L->get_wc_variation_info($variation_id,$_variation,$manual);
			if(!empty($variation_data)){
				if(!isset($variation_data['_manage_stock'])){
					return false;
				}
				
				if(!$xero_product_id = $MWXS_L->if_xero_product_exists($variation_data)){
					# Cost on its own must not start creating variations in Xero - only mapped
					# items get their cost updated. Skipping is the intended outcome here, so
					# report it as a no-op - returning false would have the queue consumer
					# record status 'e' for a $lc that was never meant to sync. Refs #109
					if(!$push_variation){
						if($manual){
							$r_arr['msgs'] = ['Variation is not mapped in Xero.'];
							return $r_arr;
						}

						return true;
					}

					$MWXS_L->xero_connect();
					# Add					
					$ItemID = $MWXS_L->X_Add_Product($variation_data);
					if(!empty($ItemID) && strlen($ItemID) == 36){
						return true;
					}
				}else{
					#Update
					# The variation itself is not re-pushed - only the cost, and only when the "Cost"
					# push data type is on. Refs #109
					$MWXS_L->xero_connect();
					$cost_result = $MWXS_L->X_Push_Product_Cost($xero_product_id,$variation_data);

					if($manual){
						$r_arr['msgs'] = ['Variation already present in Xero.'];
						return $r_arr;
					}

					# The queue consumer writes status 'e' on a falsey return. An item that is
					# already mapped and needed no cost change has not failed, so only a real
					# cost failure (false) should be reported as one. Refs #109
					return ($cost_result !== false);
				}
			}
		}

		return false;
	}
	
	public function hook_product_stock_update($ivnt_sync_info){
		
	}
	
	public function hook_variation_stock_update($ivnt_sync_info){
		
	}
	
	# Delete Variation Mapping
	public function hook_delete_variation_mapping($variation_id){
		if(!class_exists('WooCommerce')) return;
		global $post_type;
		if ( $post_type != 'product_variation' ) return false;

		$variation_id = (int) $variation_id;
		if($variation_id > 0){
			global $MWXS_L;
			global $wpdb;

			$vmt = $MWXS_L->gdtn('map_variations');
			$wpdb->query($wpdb->prepare("DELETE FROM `" . esc_sql($vmt) . "` WHERE `W_V_ID` = %d",$variation_id)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	# Delete Product Mapping
	public function hook_delete_product_mapping($product_id){
		if(!class_exists('WooCommerce')) return;
		global $post_type;
		if ( $post_type != 'product' ) return false;

		$product_id = (int) $product_id;
		if($product_id > 0){
			global $MWXS_L;
			global $wpdb;

			$pmt = $MWXS_L->gdtn('map_products');
			$wpdb->query($wpdb->prepare("DELETE FROM `" . esc_sql($pmt) . "` WHERE `W_P_ID` = %d",$product_id)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}
	
}