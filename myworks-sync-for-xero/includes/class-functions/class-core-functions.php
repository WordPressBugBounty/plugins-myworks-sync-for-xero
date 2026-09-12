<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core functions require direct database access for custom tables
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
 * @author     MyWorks Software <support@myworks.design>
 */

class MyWorks_WC_Xero_Sync_Core{
	#Variables
	public $session_prefix;	
	public $per_page_keyword;	
	public $default_show_per_page;	
	public $show_per_page;
	public $log_save_days;
	public $db_table_prefix;
	
	private $session = null;
	
	protected $server_timezone;

	#Options
	private $use_php_session = false;
	
	/**
	 * Check if HPOS is enabled
	 * @return bool
	 */
	private function is_hpos_enabled() {
		return class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil') && 
		       \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}
	
	public function __construct(){
		$this->session_prefix = MyWorks_WC_Xero_Sync_C_Settings::Get_C_Setting('session_prefix');
		$this->per_page_keyword = MyWorks_WC_Xero_Sync_C_Settings::Get_C_Setting('per_page_keyword');
		$this->default_show_per_page = MyWorks_WC_Xero_Sync_C_Settings::Get_C_Setting('default_show_per_page');
		$this->show_per_page = MyWorks_WC_Xero_Sync_C_Settings::Get_C_Setting('show_per_page');
		$this->log_save_days = MyWorks_WC_Xero_Sync_C_Settings::Get_C_Setting('log_save_days');
		$this->db_table_prefix = MyWorks_WC_Xero_Sync_C_Settings::Get_C_Setting('db_table_prefix');

		if(function_exists('date_default_timezone_get')){
			$this->server_timezone = date_default_timezone_get();
		}
		
	}
	
	private function use_php_session(){
		# PHP session not supported now
		return (bool) $this->use_php_session;
	}
	
	public function initialize_session(){
		# New
		if(!is_user_logged_in()){
			return false;
		}
		
		if(is_null($this->session) && !empty($this->session_prefix)){
			if(!$this->wc_session_includes()){
				return false;
			}
			$session_class = 'MyWorks_WC_Xero_Sync_Lib_Session_Handler';
			if(is_null($this->session ) || !$this->session instanceof $session_class){
				$this->session = new $session_class($this->gdtn('sessions'));
				$this->session->init();
			}
		}		
	}
	
	private function wc_session_includes(){
		if(!class_exists('WC_Session')){
			// WC_ABSPATH is undefined when WooCommerce failed to load this request
			// (mid-upgrade file swap or broken WC install) - bail instead of fataling,
			// and skip the session handler too since it extends WC_Session.
			if(!defined('WC_ABSPATH') || !file_exists(WC_ABSPATH . 'includes/abstracts/abstract-wc-session.php')){
				return false;
			}
			require_once WC_ABSPATH . 'includes/abstracts/abstract-wc-session.php';
		}
		require_once plugin_dir_path( __FILE__ ) . 'class-session-handler.php';
		return true;
	}
	
	/*Plugin setting related functions*/
	public function update_option($option,$value,$autoload=null){
		if(is_null($autoload)){
			$autoload = false;
		}
		
		return update_option($option,$value,$autoload);
	}
	
	public function get_option($key,$default=''){
		$ov = get_option($key);
		if(empty($ov)){
			$ov = $default;
		}
		return $ov;
	}
	
	public function option_checked($option,$df=false){
		$ov = $this->get_option($option);
		
		if($ov == 'true'){
			return true;
		}
		
		if($df && empty($ov)){
			return true;
		}
		
		return false;
	}
	
	public function plugin_get_all_options(){
		global $wpdb;
		$option_arr = array();
		
		$whr = '';
		$prepare_params = array();
		
		# "'{FN}'"
		$ignore_opts = array();
		
		// Security: Get validated option prefix
		$option_prefix = sanitize_text_field($this->get_s_o_p());
		$like_value = $option_prefix . '%';
		$prepare_params[] = $like_value;
		
		if(!empty($ignore_opts)){			
			// Security: Sanitize option names and build placeholders
			$placeholders = implode(',', array_fill(0, count($ignore_opts), '%s'));
			$whr = " AND `option_name` NOT IN({$placeholders}) ";
			$prepare_params = array_merge($prepare_params, array_map('sanitize_text_field', $ignore_opts));
		}
		
		// Security: Use prepared statement
		if(!empty($ignore_opts)){
			$sql = "SELECT * FROM `" . esc_sql($wpdb->options) . "` WHERE `option_name` LIKE %s" . $whr; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$option_data = $this->get_data($wpdb->prepare($sql, ...$prepare_params)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name is escaped with esc_sql(), values are properly prepared
		} else {
			$sql = "SELECT * FROM `" . esc_sql($wpdb->options) . "` WHERE `option_name` LIKE %s";
			$option_data = $this->get_data($wpdb->prepare($sql, $like_value)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		if(is_array($option_data) && count($option_data)){
			foreach($option_data as $Option){
				$option_arr[$Option['option_name']] = $Option['option_value'];
			}
		}
		
		return $option_arr;
	}
	
	public function get_s_o_p(){
		return 'mw_wc_xero_sync_';
	}
	
	public function is_s2_dd(){
		if($this->option_checked('mw_wc_xero_sync_enable_select2_dd',true)){
			return true;
		}
	}
	
	public function is_s2_ajax_dd(){		
		if(!$this->is_s2_dd()){
			return false;
		}
		
		if($this->option_checked('mw_wc_xero_sync_select2_ajax_dd')){
			return true;
		}
	}
	
	/*DB base functions*/

	# Get all plugin DB table names
	public function get_plugin_db_tbl_list(){
		global $wpdb;
		// Security: Use prepared statement for SHOW TABLES
		$table_prefix = sanitize_text_field($this->db_table_prefix) . '%';
		$tl_q = $wpdb->prepare("SHOW TABLES LIKE %s", $table_prefix);
		$tbl_list = $this->get_data($tl_q);

		$p_tbls = array();
		if(is_array($tbl_list) && count($tbl_list)){
			foreach($tbl_list as $tl){
				if(is_array($tl) && count($tl)){
					$tl_v = current($tl);$tl_v = (string) $tl_v;$tl_v = trim($tl_v);
					if($tl_v!=''){
						$p_tbls[] = $tl_v;
					}
				}
			}
		}
		return $p_tbls;
	}

	# Get all plugin DB tables with fields
	public function db_check_get_fields_details($s_tbf_list=array()){
		$tb_f_list = array();
		$tbls = $this->get_plugin_db_tbl_list();
		if(is_array($tbls) && count($tbls)){
			foreach($tbls as $tln){
				// Security: Validate table name before using in SHOW COLUMNS
				$safe_table_name = sanitize_text_field($tln);
				// Additional validation: ensure it's a valid table name format
				if (!preg_match('/^[a-zA-Z0-9_]+$/', $safe_table_name)) {
					continue; // Skip invalid table names
				}
				$tcq = "SHOW COLUMNS FROM `{$safe_table_name}`";
				$tc_list = $this->get_data($tcq);
				$tc_tmp_arr = array();
				if(is_array($tc_list) && count($tc_list)){
					foreach($tc_list as $tc_l){
						$tc_tmp_arr[$tc_l['Field']] = $tc_l;
					}
				}				
				$tb_f_list[$tln] = $tc_tmp_arr;
			}
		}		
		return $tb_f_list;
	}
	
	#Get plugin db table name
	public function gdtn($t){
		return $this->db_table_prefix.$t;
	}
	
	/**
	 * Get validated table name to prevent SQL injection
	 * Resolve a plugin table name from a static context.
	 *
	 * The Pulse and Intercom integration classes are entirely static (cron entry points, no $this),
	 * so they can't call get_validated_table_name() directly. They both need the same
	 * whitelist-then-constant-fallback logic, and two independent copies of security-sensitive
	 * fallback logic drift as the whitelist changes — so it lives here once.
	 *
	 * Falls back to MW_WC_XERO_SYNC_PLUGIN_DB_TABLE_PREFIX rather than returning empty, so a cron
	 * step can't silently stop working if the lib global isn't up yet. Callers must still handle ''.
	 *
	 * @param string $table_suffix Whitelisted table suffix, e.g. 'log', 'map_products'.
	 * @return string Full table name, or '' if it cannot be resolved at all.
	 */
	public static function resolve_plugin_table($table_suffix) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- MWXS is the established plugin prefix; $MWXS_L is the lib singleton global
		global $MWXS_L;

		// Enforce the whitelist HERE too, not just inside get_validated_table_name(). The fallback
		// below concatenates the suffix directly, so without this an invalid suffix would resolve to a
		// real-looking table name whenever the lib global happened to be unavailable — exactly the
		// hole the whitelist exists to close.
		$table_suffix = sanitize_text_field( $table_suffix );
		if ( ! in_array( $table_suffix, self::allowed_table_suffixes(), true ) ) {
			return '';
		}

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- See above.
		if ( isset( $MWXS_L ) && is_object( $MWXS_L ) && method_exists( $MWXS_L, 'get_validated_table_name' ) ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- See above.
			$resolved = $MWXS_L->get_validated_table_name( $table_suffix );
			if ( is_string( $resolved ) && '' !== $resolved ) {
				return $resolved;
			}
		}

		if ( defined( 'MW_WC_XERO_SYNC_PLUGIN_DB_TABLE_PREFIX' ) ) {
			return MW_WC_XERO_SYNC_PLUGIN_DB_TABLE_PREFIX . $table_suffix;
		}

		return '';
	}

	/**
	 * The one list of plugin table suffixes that may be resolved into a table name.
	 *
	 * Shared by get_validated_table_name() and resolve_plugin_table() so there is a single
	 * whitelist to keep current — `sessions` is deliberately absent, see
	 * docs/rules/conventions.md.
	 *
	 * @return string[]
	 */
	private static function allowed_table_suffixes() {
		return array(
			'map_customers',
			'map_products',
			'map_payment_method',
			'map_tax',
			'map_multiple',
			'map_categories',
			'map_variations',
			'map_shipping_method',
			'map_cf',
			'map_custom_fields',
			'customers',
			'products',
			'queue',
			'log',
		);
	}

	/**
	 *
	 * @param string $table_suffix The table suffix (without prefix)
	 * @return string|false The validated full table name or false if invalid
	 */
	public function get_validated_table_name($table_suffix) {
		// Whitelist of allowed table suffixes — single source, shared with the static resolver above.
		$allowed_tables = self::allowed_table_suffixes();

		// Validate input
		$table_suffix = sanitize_text_field($table_suffix);
		
		// Check if table is in whitelist
		if (!in_array($table_suffix, $allowed_tables, true)) {
			// Logged unconditionally, NOT gated on WP_DEBUG: this is a rejected access attempt, and
			// production is exactly where an audit record is wanted. Only the sanitized suffix is
			// recorded — never a path or the caller's input verbatim.
			wc_get_logger()->warning( 'MW Xero Sync Security: Invalid table access attempt: ' . sanitize_text_field( $table_suffix ), array( 'source' => 'myworks-sync-for-xero' ) );
			return false;
		}
		
		// Return validated full table name
		return $this->gdtn($table_suffix);
	}
	
	/**
	 * Get validated table name from full table name (includes prefix)
	 * 
	 * @param string $full_table_name The full table name including prefix
	 * @return string|false The validated full table name or false if invalid
	 */
	public function get_validated_table_name_from_full($full_table_name) {
		// Extract suffix by removing prefix
		$prefix = $this->db_table_prefix;
		if (strpos($full_table_name, $prefix) === 0) {
			$table_suffix = substr($full_table_name, strlen($prefix));
			return $this->get_validated_table_name($table_suffix);
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			wc_get_logger()->warning( 'MW Xero Sync Security: Invalid full table name format: ' . sanitize_text_field( $full_table_name ), array( 'source' => 'myworks-sync-for-xero' ) );
		}
		return false;
	}
	
	# All data
	public function get_data($query){
		global $wpdb;
		$query = trim($query);
		if($query!=''){
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query validated by caller
			return $wpdb->get_results($query,ARRAY_A);
		}
	}
	
	# Single row
	public function get_row($query){
		global $wpdb;
		$query = trim($query);
		if($query!=''){
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query validated by caller
			return $wpdb->get_row($query,ARRAY_A);
		}
	}
	
	# Key field row by value field
	public function get_row_by_val($tbl,$field,$field_val){
        global $wpdb;
        if($tbl!='' && $field!='' && $field_val!=''){
            $tbl_q = "SELECT * FROM `" . esc_sql($tbl) . "` WHERE `" . esc_sql($field) . "` = %s"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $tbl_data = $this->get_row($wpdb->prepare($tbl_q,$field_val)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            return $tbl_data;
        }
        
		return array();
    }
	
	# Key field data by value field
	public function get_field_by_val($tbl,$get_field,$field,$field_val){
        global $wpdb;
        if($tbl!='' && $get_field!='' && $field!='' && $field_val!=''){
            $tbl_q = "SELECT `" . esc_sql($get_field) . "` FROM `" . esc_sql($tbl) . "` WHERE `" . esc_sql($field) . "` = %s"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $tbl_data = $this->get_row($wpdb->prepare($tbl_q,$field_val)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            return (isset($tbl_data[$get_field]))?$tbl_data[$get_field]:'';
        }
        else{
            return '';
        }
    }
	
	# Table data
	public function get_tbl($tbl,$fields='*',$whr='',$orderby='',$limit='',$group_by='',$having=''){
		if($tbl!=''){

			if(trim($fields)==''){$fields='*';}

			$tl_q = "SELECT $fields FROM $tbl ";

			if($whr!=''){
				$tl_q.="WHERE $whr ";
			}

			if($group_by!=''){
				$tl_q.="GROUP BY $group_by ";
			}

			if($having!=''){
				$tl_q.="HAVING $having ";
			}

			if($orderby!=''){
				$tl_q.="ORDER BY $orderby ";
			}

			if($limit!=''){
				$tl_q.="LIMIT $limit ";
			}


			return $this->get_data($tl_q);
		}
	}
	
	/*HTML elements functions*/
	
	# Select option values from an array
	public function only_option($selected='',$opt_arr = array(),$s_key='',$s_val='',$return=false,$dsbl_arr=array()){
		$options='';
		if(is_array($opt_arr) && count($opt_arr)>0){
			foreach ($opt_arr as $key => $value) {
				$sel_text = '';

				if($s_key!='' && $s_val!=''){
                    #Multi
                    if(is_array($selected) && !empty($selected)){
                        if(in_array($value[$s_key],$selected)){$sel_text = ' selected';}
                    }else{
                        if($value[$s_key] == $selected){$sel_text = ' selected';}
                    }
					
					$odsbl = '';
					/*
					if(is_array($dsbl_arr) && isset($dsbl_arr[$value[$s_key]])){
						$odsbl = ' disabled';
					}
					*/
					
					if($return){
						$options.='<option'.esc_attr($odsbl).' value="'.esc_attr($value[$s_key]).'" '.esc_attr($sel_text).'>'.esc_html(stripslashes($value[$s_val])).'</option>';
					}else{
						echo '<option'.esc_attr($odsbl).' value="'.esc_attr($value[$s_key]).'" '.esc_attr($sel_text).'>'.esc_html(stripslashes($value[$s_val])).'</option>';
					}

				}else{
                    #Multi
                    if(is_array($selected) && !empty($selected)){
                        if(in_array($key,$selected)){$sel_text = ' selected';}
                    }else{
                        if($key == $selected){$sel_text = ' selected';}
                    }
					
					$odsbl = '';					
					if(is_array($dsbl_arr) && isset($dsbl_arr[$key])){
						$odsbl = ' disabled';
					}
					
					if($return){
						$options.='<option'.esc_attr($odsbl).' value="'.esc_attr($key).'" '.esc_attr($sel_text).'>'.esc_html(stripslashes($value)).'</option>';
					}else{
						echo '<option'.esc_attr($odsbl).' value="'.esc_attr($key).'" '.esc_attr($sel_text).'>'.esc_html(stripslashes($value)).'</option>';
					}

				}
			}
		}
		if($return){
			return $options;
		}
	}
	
	# Select option values from db table
	public function option_html($selected='',$t_name='',$key_field='',$val_field='',$whr='',$orderby='',$limit='',$return=false){
		if($t_name!='' && $key_field!='' && $val_field!=''){
			$op_fields = "$key_field,$val_field";
			$op_data = $this->get_tbl($t_name,$op_fields,$whr,$orderby,$limit);			
			
			if($this->start_with($val_field,'CONCAT(') || $this->start_with($val_field,'CONCAT_WS(')){
				$vfa = preg_split('/\s+/', $val_field);
				$val_field = end($vfa);
			}
			
			if($return){
				return $this->only_option($selected,$op_data,$key_field,$val_field,$return);
			}
			$this->only_option($selected,$op_data,$key_field,$val_field,$return);
		}
	}
	
	/*Query functions*/
	
	# Key value pair from table into an array
	public function get_key_value_options_from_table($blank_option=false,$t_name='',$key_field='',$val_field='',$whr='',$orderby='',$limit=''){
		$kv_arr = array();
		if($t_name!='' && $key_field!='' && $val_field!=''){
			$op_fields = "$key_field,$val_field";
			$op_data = $this->get_tbl($t_name,$op_fields,$whr,$orderby,$limit);
			
			if($this->start_with($val_field,'CONCAT(') || $this->start_with($val_field,'CONCAT_WS(')){
				$vfa = preg_split('/\s+/', $val_field);
				$val_field = end($vfa);
			}
			
			if(is_array($op_data) && count($op_data)>0){
				if($blank_option){
					$kv_arr[''] = '';
				}
				foreach ($op_data as $key => $value) {
					$kv_arr[$value[$key_field]] = $value[$val_field];
				}
			}
		}
		return $kv_arr;
	}
	
	/*Log functions*/
	public function add_test_log($log_txt,$clear_last_day=true,$append=true){
		if(trim($log_txt)==''){
			return;
		}
		$f_log_txt = trim($log_txt).PHP_EOL;
		$log_file_name = plugin_dir_path( dirname( __FILE__ ) ) .'log'.DIRECTORY_SEPARATOR.'dev-test.log';
		$f_ot = ($append)?'a':'w';
		
		if($clear_last_day && $append && file_exists($log_file_name)){
			if((time()-filemtime($log_file_name)) > 86400){
				$f_ot = 'w';
			}
		}

		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		
		if ( $f_ot === 'w' ) {
			$wp_filesystem->put_contents( $log_file_name, "\n" . $f_log_txt );
		} else {
			$existing_content = $wp_filesystem->exists( $log_file_name ) ? $wp_filesystem->get_contents( $log_file_name ) : '';
			$wp_filesystem->put_contents( $log_file_name, $existing_content . "\n" . $f_log_txt );
		}
	}
	
	/*Print-debug functions*/
	// phpcs:disable WordPress.PHP.DevelopmentFunctions -- Debug function wrapped in WP_DEBUG
	public function _p($item='',$dump=false){
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}
		echo '<pre>';
		if(is_object($item) || is_array($item)){
			if($dump){
				// Use serialize for detailed dump
				echo esc_html( serialize($item) );
			}else{
				$json = wp_json_encode($item, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
				echo esc_html( $json !== false ? $json : serialize($item) );
			}
		}else{
			if($dump){
				echo esc_html( (string) $item );
			}else{
				echo esc_html($item);
			}

		}
		echo '</pre>';
	}
	// phpcs:enable WordPress.PHP.DevelopmentFunctions
	
	/*Encryption functions - using openssl*/
	private function get_encryption_key(){
		$key = '';//52
		return $key;
	}
	
	private function get_encryption_iv(){
		$iv = '';//16
		return $iv;
	}
	
	public function encrypt($input_string, $key=''){
		$key = $this->get_encryption_key();
		$iv = $this->get_encryption_iv();
		
		if(empty($key) || empty($iv)){
			return $input_string;
		}
		
		$encrypt_method = "AES-256-CBC";
		$key = hash( 'sha256', $key );
		$iv = substr( hash( 'sha256', $iv ), 0, 16 );
		return base64_encode( openssl_encrypt( $input_string, $encrypt_method, $key, 0, $iv ) );
	}
	
	public function decrypt($input_string, $key=''){
		$key = $this->get_encryption_key();
		$iv = $this->get_encryption_iv();
		
		if(empty($key) || empty($iv)){
			return $input_string;
		}
		
		$encrypt_method = "AES-256-CBC";
		$key = hash( 'sha256', $key );
		$iv = substr( hash( 'sha256', $iv ), 0, 16 );
		return openssl_decrypt( base64_decode( $input_string ), $encrypt_method, $key, 0, $iv );
	}
	
	/*Datetime functions*/
	public function get_wp_timezone(){
		$tz = $this->get_option('timezone_string');
		if(empty($tz)){
			$tz = $this->server_timezone;
		}
		return $tz;
	}

	public function get_xero_timezone(){
		return 'UTC';
	}
	
	public function get_cdt($timezone='',$format='Y-m-d H:i:s'){
		if(empty($timezone)){			
			$timezone = $this->get_wp_timezone();
		}
		
		if($timezone!=''){
			$now = new DateTime('now', new DateTimeZone($timezone));
			$datetime = $now->format($format);
			return $datetime;
		}
		return gmdate($format);
	}
	
	public function now($format='Y-m-d H:i:s',$timezone=''){
		return $this->get_cdt($timezone,$format);
	}
	
	function convert_dt_timezone($d_time,$toTz,$fromTz=''){
		if(!empty($toTz) || !empty($d_time)){
			return $d_time;
		}
		
		if(empty($fromTz)){
			$fromTz = $this->server_timezone;
		}

        $date = new DateTime($d_time, new DateTimeZone($fromTz));
        $date->setTimezone(new DateTimeZone($toTz));
        $d_time= $date->format('Y-m-d H:i:s');
        return $d_time;
    }
	
	public function format_date($date,$format="Y-m-d"){
		if($date!='' && $date!=NULL && $date!='0000-00-00 00:00:00'){
			$date = strtotime($date);
			return gmdate($format,$date);
		}
	}

	# Xero API Response Date
	public function format_xero_date($str,$format='Y-m-d H:i:s',$convert_to_wp_date=true){
		$formated_date = '';
		if(!empty($str)){			
			$match = preg_match( '/([\d]{13})/', $str, $date );
			if($match && is_array($date) && isset($date[1]) && !empty($date[1])){
				$timestamp = $date[1]/1000;
				if($timestamp > 0){
					$datetime = new \DateTime();
					$datetime->setTimestamp($timestamp);
					$formated_date = $datetime->format('Y-m-d H:i:s');
				}
			}
			
			if(!empty($formated_date)){
				if($convert_to_wp_date){
					$formated_date = $this->convert_dt_timezone($formated_date,$this->get_wp_timezone(),$this->get_xero_timezone());
				}
			}
		}
		return $formated_date;
	}
	
	/**
	 * Drop bytes that are not valid UTF-8.
	 *
	 * Every string this class hands to the Xero SDK ends up in json_encode(), which the SDK turns
	 * into a throw on JSON_ERROR_UTF8 - so half a character (a byte-based substr() through 'é') or
	 * latin1 bytes from an old table became an uncaught GuzzleHttp InvalidArgumentException instead
	 * of a logged sync error. Refs #148
	 *
	 * @param  mixed $str Value to clean; non-strings and '' are returned untouched.
	 * @return mixed The string with every malformed byte removed.
	 */
	public function clean_utf8($str){
		if(!is_string($str) || $str === ''){
			return $str;
		}
		# preg_match() with /u fails on malformed UTF-8 - no mbstring or iconv dependency.
		if(preg_match('//u', $str) === 1){
			return $str;
		}
		# Keep every complete, well-formed UTF-8 sequence (group 1); any other byte matches the
		# trailing `.` alone and is dropped.
		$clean = preg_replace('/([\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2})|./s', '$1', $str);
		return is_string($clean) ? $clean : '';
	}

	# Get array value by key
	public function get_array_isset($data,$kw,$df='',$decode=true,$trim=0,$as=false,$ra=array(),$ss=false){
		$return = $df;
		if(is_array($data) && count($data)){
			if(isset($data[$kw])){
				$return = $data[$kw];
				$return = trim($return);
				if($decode){					
					$return= wp_strip_all_tags($return);					
					if($ss){
						$return = stripslashes($return);
					}					
					
					$return = htmlspecialchars_decode($return,ENT_QUOTES);					
					$return = html_entity_decode($return,ENT_QUOTES);
				}
				# Clean before measuring, and measure in characters: Xero's limits are character counts,
				# and a byte-based substr() cut multibyte names in half - the SDK then refused the whole
				# payload with "Malformed UTF-8 characters". Refs #148
				$return = $this->clean_utf8($return);
				if($trim){
					if(mb_strlen($return,'UTF-8') > $trim){
						$return = mb_substr($return,0,$trim,'UTF-8');
					}
				}
				if($as){
					$return = addslashes($return);
				}
				if(is_array($ra) && count($ra)){
					$return = str_replace($ra,'',$return);
				}
			}
		}
		return $return;
	}
	
	public function get_plugin_domain(){
		$siteurl = $this->get_option('siteurl'); #get_site_url
		if(!empty($siteurl)){
			$psurl = wp_parse_url($siteurl);
			if(is_array($psurl) && isset($psurl['host'])){
				return $psurl['host'];
			}
		}
		
		// Security: Use WordPress sanitization for $_SERVER variables
		return (isset($_SERVER['SERVER_NAME'])) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_NAME'])) : '';
	}
	
	public function get_plugin_ip(){
		// Security: Use WordPress sanitization for $_SERVER variables
		$s_laddr = (isset($_SERVER['LOCAL_ADDR'])) ? sanitize_text_field(wp_unslash($_SERVER['LOCAL_ADDR'])) : '';
		$usersip = isset($_SERVER['SERVER_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_ADDR'])) : $s_laddr;
		
		$sname = $this->get_plugin_domain();
		if(empty($usersip) && !empty($sname)){
			$usersip = gethostbyname($sname);
		}
		return $usersip;
	}
	
	public function get_plugin_connection_dir(){
		$dirpath = dirname(__FILE__);
		return $dirpath;
	}
	
	# Session Functions
	public function set_and_get($keyword){
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Session value getter, nonce verified by caller
		if(!empty($keyword) && isset($_GET[$keyword])){
			$this->set_session_val($keyword,$this->var_g($keyword));
		}
	}
	
	public function set_and_post($keyword){
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification handled by caller
		if(!empty($keyword) && isset($_POST[$keyword])){
			$this->set_session_val($keyword,$this->var_p($keyword));
		}
	}
	
	public function set_session_val($keyword,$value){
		if(is_null($this->session)){
			return false;
		}
		
		$this->session->set($this->session_prefix.$keyword, $value);
	}
	
	public function get_session_val($keyword,$default='',$reset=false){
		if(is_null($this->session)){
			return $default;
		}
		
		$val = $default;
		if(!empty($keyword) && $this->session->__isset($this->session_prefix.$keyword)){
			$val = $this->session->get($this->session_prefix.$keyword,$default);
			if($reset){
				$this->session->__unset($this->session_prefix.$keyword);
			}
		}
		return $val;
	}
	
	public function isset_session($keyword){
		if(is_null($this->session)){
			return false;
		}
		
		if(!empty($keyword) && $this->session->__isset($this->session_prefix.$keyword)){
			return true;
		}
		return false;
	}
	
	public function unset_session($keyword){
		if(is_null($this->session)){
			return false;
		}
		
		if(!empty($keyword) &&$this->session->__isset($this->session_prefix.$keyword)){
			$this->session->__unset($this->session_prefix.$keyword);
		}
	}
	
	# Pagination Functions
	public function default_show_per_page(){
		$dspp = (int) $this->default_show_per_page;
		if($dspp < 1){
			$dspp = 20;
		}
		
		if($dspp > 500){
			$dspp = 500;
		}
		
		return $dspp;
	}
	
	public function get_offset($page, $items_per_page){
		return ( $page * $items_per_page ) - $items_per_page;
	}
	
	public function set_per_page_from_url($unique=''){
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display preference from URL, non-sensitive pagination setting
		if(isset($_GET[$this->per_page_keyword]) && (int) $_GET[$this->per_page_keyword]>0){
			$pp = (int) $_GET[$this->per_page_keyword];
			if(!$pp){$pp=$this->default_show_per_page();}
			$this->set_session_val('item_per_page'.$unique,$pp);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}
	
	public function get_item_per_page($unique='',$default=0){
		$dspp = $this->default_show_per_page();
		
		$default = (int) $default;
		if($default > 0){
			$dspp = $default;
		}		
		
		$itemPerPage = $this->get_session_val('item_per_page'.$unique,$dspp);
		return $itemPerPage;
	}
	
	public function get_page_var(){
		#$page = (get_query_var('paged')) ? (int) get_query_var('paged') : 1;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Pagination parameter, read-only display setting
		$page = isset($_GET['paged']) ? (int) $_GET['paged'] : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if(!$page){$page=1;}
		return $page;
	}
	
	public  function get_paginate_links($total_records=0,$items_per_page=20,$show_total=true,$page=''){
		if($page==''){
			$page = $this->get_page_var();
		}

		if($total_records>0){
			echo '<div class="mwqs_paginate_div mwqbd_pd">';
			$i_text = ($total_records>1)?'items':'item';
			if($show_total){				
				$total_pages = ceil($total_records / $items_per_page);
				$pgn_txt = $this->get_pagination_count_txt($page,$total_pages,$total_records,$items_per_page);

				echo '<div>'.esc_html($pgn_txt).'</div>';
			}
			
			if($total_records>$items_per_page){
				echo'<div class="pagination">';
			
				echo wp_kses_post(paginate_links( array(
					'base' => add_query_arg( 'paged', '%#%' ),
					'format' => '',
					'prev_text' => '&laquo;',
					'next_text' => '&raquo;',
					'total' => ceil($total_records / $items_per_page),
					'current' => $page,
					'end_size' =>2,
					'mid_size' =>3

				)));

				echo '</div>';
			}

			echo '</div>';
		}
	}
	
	public function get_pagination_count_txt($page,$total_pages,$count,$itemPerPage){
		$cur_page = ($page==0)?1:$page;
		if ($page != 0) $page--;

		$txt = '';
		if($cur_page<=$total_pages){
			$e_text = ($count>1)?'entries':'entry';
			$txt = 'Showing '.($page*$itemPerPage+1).' to '.(($total_pages==$cur_page || $itemPerPage>=$count)?$count:($page+1)*$itemPerPage).' of '.$count.' '.$e_text;
		}
		return $txt;
	}
	
	#Check if user can manage woocommerce
	public function if_user_m_wc(){
		if(current_user_can('manage_woocommerce') || current_user_can('view_woocommerce_report')){
			return true;
		}
		
		return false;
	}
	
	/*Country*/
	public function get_wc_country_list(){
		$countries_obj   = new WC_Countries();
		$countries   = $countries_obj->__get('countries');
		return $countries;
	}
	
	public function get_country_name_from_code($code=''){
		if($code!=''){			 
			 $countries   = $this->get_wc_country_list();
			 if(is_array($countries) && isset($countries[$code])){
				 return $countries[$code];
			 }
		}
		return $code;
	}

	public function get_wc_currency_list(){
		if(function_exists('get_woocommerce_currencies')){
			$currencies = get_woocommerce_currencies();
			return array_combine(array_keys($currencies), array_keys($currencies));
		}

		return [];
	}

	public function is_wc_multicurrency_enabled(){	
		# WooCommerce Payments Multi-Currency
		$enabled = get_option('wcpay_multi_currency_enabled', 'no');
		if($enabled === 'yes'){
			return true;
		}
		
		if(class_exists('\WCPay\MultiCurrency\MultiCurrency', false) && method_exists('\WCPay\MultiCurrency\MultiCurrency', 'instance')){
			$multi_currency = \WCPay\MultiCurrency\MultiCurrency::instance();
			if($multi_currency){
				if(method_exists($multi_currency, 'is_enabled')){
					if($multi_currency->is_enabled()){
						return true;
					}
				}elseif(method_exists($multi_currency, 'get_enabled_currencies')){
					if(!empty($multi_currency->get_enabled_currencies())){
						return true;
					}
				}				
			}
		}

		# Woo Multi-Currency (by WooCommerce.com / WPML)
		if(function_exists( 'wcml_multi_currency')){
			$multi_currency = wcml_multi_currency();
			if($multi_currency && isset($multi_currency->settings['enabled']) && $multi_currency->settings['enabled']){
				return true;
			}
		}

		if(class_exists('WOOMULTI_CURRENCY_F', false) && method_exists('WOOMULTI_CURRENCY_F', 'instance')){
			$wmc = WOOMULTI_CURRENCY_F::instance();
			if($wmc && method_exists($wmc, 'get_enable') && $wmc->get_enable()){
				return true;
			}
		}

		# Aelia Currency Switcher
		if(class_exists('WC_Aelia_CurrencySwitcher', false) && method_exists('WC_Aelia_CurrencySwitcher', 'instance')){
			$aelia_cs = WC_Aelia_CurrencySwitcher::instance();
			if($aelia_cs){
				return true;
			}
		}

		# CURCY - Multi Currency for WooCommerce (VillaTheme)
		$curcy_settings = get_option('woo_multi_currency_params');
		if(is_array($curcy_settings) && !empty($curcy_settings['enable'])){			
			return true;
		}

		# WPML Multilingual & Multicurrency for WooCommerce
		global $woocommerce_wpml;
		if (isset($woocommerce_wpml) && is_object($woocommerce_wpml) &&  is_a( $woocommerce_wpml, 'woocommerce_wpml' ) ) {
			if ( isset( $woocommerce_wpml->settings['enable_multi_currency'] ) && $woocommerce_wpml->settings['enable_multi_currency'] == WCML_MULTI_CURRENCIES_INDEPENDENT ) {
				return true;
			}
		}		
		
		return false;
	}
	
	/*Currency*/
	
	#Other Functions
	public function get_html_msg($title,$body){
		echo '
		<html>
			<head>
				<title>'.esc_html($title).'</title>
			</head>

			<body>
				<h1>'.esc_html($body).'</h1>
			</body>
		</html>';		
	}
	
	public function var_p($key,$default=''){
		if($key!=''){
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- General purpose POST sanitizer, nonce verification handled by caller
			if(isset($_POST[$key])){
				if(!is_array($_POST[$key])){
					// Use WordPress sanitization for text inputs
					return sanitize_text_field(wp_unslash($_POST[$key]));
				}
				else{
					// Use WordPress sanitization for array inputs
					return array_map('sanitize_text_field', wp_unslash($_POST[$key]));
				}
			}
			// phpcs:enable WordPress.Security.NonceVerification.Missing
		}

		return $default;
	}

	public function var_g($key,$default=''){
		if($key!=''){
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- General purpose GET sanitizer, nonce verification handled by caller
			if(isset($_GET[$key])){
				return sanitize_text_field(wp_unslash($_GET[$key]));
			}
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
		}

		return $default;
	}
	
	public function show_sync_window_message($id, $message, $progress=0, $tot=0) {		
		$d = array('message' => $message , 'progress' => $progress,'total' => $tot,'cur' => $id);
		echo json_encode($d);
		ob_flush();
		flush();
		die();
	}

	public function get_current_request_protocol(){
		// Security: Sanitize $_SERVER variables
		if(!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])){
			return sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_PROTO']));
		}
		// IIS sets HTTPS='off' (lowercase) on plain HTTP, so compare case-insensitively.
		return (isset($_SERVER['HTTPS']) && !empty($_SERVER['HTTPS']) && strtolower(sanitize_text_field(wp_unslash($_SERVER['HTTPS'])))!='off') ? "https" : "http";
	}

	public function get_sync_window_url(){
		$request_protocol = $this->get_current_request_protocol();

		// Security: Sanitize $_SERVER variables with validation
		$http_host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
		$script_name = isset($_SERVER['SCRIPT_NAME']) ? sanitize_text_field(wp_unslash($_SERVER['SCRIPT_NAME'])) : '';
		$current_url = $request_protocol.'://'.$http_host.$script_name;
		$current_url = esc_url($current_url);
		
		$sync_window_url = site_url('index.php?mw_xero_sync_public_sync_window=1');

		if(strpos($current_url, 's://')===false){
			$sync_window_url = str_replace('s://','://',$sync_window_url);
		}else{
			if(strpos($sync_window_url, 's://')===false){
				$sync_window_url = str_replace('://','s://',$sync_window_url);
			}
		}

		if(strpos($current_url, '://www.')===false){
			$sync_window_url = str_replace('://www.','://',$sync_window_url);
		}else{
			if(strpos($sync_window_url, '://www.')===false){
				$sync_window_url = str_replace('://','://www.',$sync_window_url);
			}
		}

		return esc_url($sync_window_url);
	}
	
	public function redirect($url){
		/*
		if($url!=''){
			$url = esc_url_raw($url);
			wp_redirect( $url );
			exit;
		}
		*/
	}
	
	public function start_with($haystack, $needle){
		return $needle === "" || strrpos($haystack, $needle, -strlen($haystack)) !== FALSE;
	}

	public function array_sanitize($data){		
		if (is_array($data) && !empty($data)) {
			foreach ($data as $key => $value) {
				$data[$key] = $this->array_sanitize($value);
			}
		} else {
			# For empty array - It will return array
			if(is_array($data)){
				return $data;
			}
			
			$data = $this->sanitize($data);
		}

		return $data;		  
	}
	
	public function sanitize($txt,$textarea=false){
		if($txt != ''){
			$txt = trim($txt);
		}
		
		if(empty($txt)){
			return $txt;
		}

		$txt = esc_sql( $txt );
		
		if($textarea){
			$txt = sanitize_textarea_field( $txt );
		}else{
			$txt = sanitize_text_field( $txt );
		}

		return $txt;
	}

	public function escape($str){
		if(!empty($str)){
			$str = esc_html( $str );
			$str = esc_js( $str );			
		}

		return $str;
	}
	
	public function trim_add_slash($str){
		return addslashes(trim($str));
	}
	public function is_plugin_active($plugin,$diff_filename=''){
		$active = false;
		$plugin = trim($plugin);
		$diff_filename = trim($diff_filename);
		$plugin_file = ($diff_filename!='')?$diff_filename:$plugin;
		
		if(function_exists('is_plugin_active')){
			if( is_plugin_active( $plugin.'/'.$plugin_file.'.php' ) ) {
				$active = true;
			}
		}else{
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- active_plugins is a WordPress core option name, not a plugin-defined hook
			$active = in_array( $plugin.'/'.$plugin_file.'.php', (array) get_option( 'active_plugins', array() ) );
		}
		
		return $active;
	}
	
	public function is_plugin_admin_page($pn='',$param=''){
		// Security: Sanitize $_SERVER variables
		if(!is_admin() || empty($_SERVER['QUERY_STRING'])){
			return false;
		}

		$qsc = 'myworks-wc-xero-sync';
		if(!empty($pn)){
			$qsc .= '-'.$pn;
		}
		
		$query_string = explode('=', sanitize_text_field(wp_unslash($_SERVER['QUERY_STRING'])));
		
		if(is_array($query_string) && isset($query_string[1]) && !empty($query_string[1]) && $query_string[0] == 'page'){
			if( strpos( $query_string[1], $qsc ) !== false ) {
				if(empty($param)){
					return true;
				}else{
					if( !is_array($param) && $param == 'dashboard' && empty($pn) && ($query_string[1] == $qsc || strpos( $query_string[1], $qsc ) !== false)){					
						return true;
					}
					
					if(isset($query_string[2]) && !empty($query_string[2])){
						if(is_array($param)){
							if(in_array($query_string[2],$param)){
								return true;
							}
							
							foreach($param as $v){
								if( strpos( $query_string[2], $v ) !== false ) {
									return true;
								}
							}
						}else{
							if($query_string[2] == $param){
								return true;
							}
							
							if( strpos( $query_string[2], $param ) !== false ) {
								return true;
							}
						}						
					}
				}				
			}
		}

		return false;
	}
	
	public function gptd(){
		return 'myworks-sync-for-xero';
	}
	
	#Automap fields
	public function wc_customer_automap_fields(){
		return (array) MyWorks_WC_Xero_Sync_C_Settings::Get_C_Setting('wc_customer_automap_fields');
	}
	
	public function xero_customer_automap_fields(){
		return (array) MyWorks_WC_Xero_Sync_C_Settings::Get_C_Setting('xero_customer_automap_fields');
	}
	
	public function wc_product_automap_fields(){
		return (array) MyWorks_WC_Xero_Sync_C_Settings::Get_C_Setting('wc_product_automap_fields');
	}
	
	public function xero_product_automap_fields(){
		return (array) MyWorks_WC_Xero_Sync_C_Settings::Get_C_Setting('xero_product_automap_fields');
	}
	
	public function wc_variation_automap_fields(){
		return (array) MyWorks_WC_Xero_Sync_C_Settings::Get_C_Setting('wc_variation_automap_fields');
	}
	
	public function xero_variation_automap_fields(){
		return (array) MyWorks_WC_Xero_Sync_C_Settings::Get_C_Setting('xero_variation_automap_fields');
	}
	
	public function stripslash_get_data($q_data,$ss_fields){
		$qd_n = array();
		if(is_array($q_data) && !empty($q_data) && is_array($ss_fields) && !empty($ss_fields)){
			foreach($q_data as $k => $v){
				
				if(is_array($v) && !empty($v)){
					$v = array_map('stripslashes',$v);
				}
				
				$qd_n[$k] = $v;
			}
		}
		if(empty($qd_n)){
			$qd_n = $q_data;
		}
		return $qd_n;
	}
	
	#WC Data Functions
	public function cus_meta_usk(){
		global $wpdb;
		$us_k = array();
		$us_k[] = 'rich_editing';
		$us_k[] = 'syntax_highlighting';
		$us_k[] = 'comment_shortcuts';
		$us_k[] = 'admin_color';
		$us_k[] = 'use_ssl';
		$us_k[] = 'show_admin_bar_front';
		$us_k[] = 'locale';
		$us_k[] = $wpdb->prefix.'capabilities';
		$us_k[] = $wpdb->prefix.'user_level';
		$us_k[] = 'session_tokens';
		$us_k[] = 'last_update';
		$us_k[] = '_stripe_customer_id';
		$us_k[] = '_woocommerce_persistent_cart_1';
		
		$us_k[] = '_woocommerce_tracks_anon_id';
		$us_k[] = $wpdb->prefix.'dashboard_quick_press_last_post_id';
		$us_k[] = 'community-events-location';
		$us_k[] = $wpdb->prefix.'user-settings';
		$us_k[] = $wpdb->prefix.'user-settings-time';
		
		$us_k[] = 'meta-box-order_product';
		$us_k[] = 'closedpostboxes_dashboard';
		$us_k[] = 'metaboxhidden_dashboard';
		
		return $us_k ;
	}
	
	# Decimal, Round Etc
	public function bcdiv_m($_ro, $_lo, $_scale=0) {
		return round($_ro/$_lo, $_scale);
	}
	
	public function trim_after_decimal_place($amount,$dp=2){
		$amount = trim($amount);
		$dp = (int) $dp;		
		if ($amount!='' && $d_pos = strpos($amount, '.') !== false && $dp>0) {
			$a_dp = strlen(substr(strrchr($amount, "."), 1));
			if($a_dp > $dp){
				$amount = floatval($amount);
				$amount = $this->bcdiv_m($amount, 1, $dp);			
			}
		}		
		return $amount;
	}
	
	# Push , Pull Check
	public function check_if_real_time_push_enable_for_item($item=''){
		if(!empty($item)){
			#if($item == 'Inventory'){return true;}
			
			$rt_push_items = (string) $this->get_option('mw_wc_xero_sync_rt_push_items');
			if(!empty($rt_push_items)){
				$rt_push_items = explode(',',$rt_push_items);
				if(is_array($rt_push_items) && !empty($rt_push_items)){
					if(in_array($item,$rt_push_items)){
						return true;
					}
				}
			}
		}
		
		return false;
	}
	
	public function check_if_real_time_pull_enable_for_item($item=''){
		if(!empty($item)){
			$rt_pull_items = (string) $this->get_option('mw_wc_xero_sync_rt_pull_items');
			if(!empty($rt_pull_items)){
				$rt_pull_items = explode(',',$rt_pull_items);
				if(is_array($rt_pull_items) && !empty($rt_pull_items)){
					if(in_array($item,$rt_pull_items)){
						return true;
					}
				}
			}
		}
		
		return false;
	}
	
	# WC user role by ID
	public function get_wc_user_role_by_id($user_id,$user_data=null){
		$user_id = (int) $user_id;
		if($user_id > 0){
			if(is_null($user_data)){
				$user_data = get_userdata($user_id);
			}
			
			if(is_object($user_data) && isset($user_data->roles) && is_array($user_data->roles)){
				return $user_data->roles[0];
			}
		}
		
		return '';
	}

	/**
	 * Add info to WC-logs, limited to hooks from *this plugin* and include stack trace.
	 *
	 * @param mixed $data Debug data.
	 * @param bool  $debug_mode Debug mode flag.
	 * @param bool  $include_hooks Whether to include fired hook trace for this plugin only.
	 * @param bool  $include_stack Whether to include PHP stack trace.
	 */
	public function add_wc_debug_log( $data, $debug_mode = false, $include_hooks = false, $include_stack = false ) { // phpcs:ignore
		// Check if debug logging is enabled via constant (takes precedence)
		$logging_enabled = defined( 'MWXS_DEBUG_LOGGING' ) ? MWXS_DEBUG_LOGGING : $debug_mode;
		
		if ( function_exists( 'wc_get_logger' ) && $logging_enabled ) {
			$log = wc_get_logger();

			// Normalize data
			if ( $data instanceof WP_Error ) {
				$data = $data->get_error_message();
			} elseif ( is_object( $data ) || is_array( $data ) ) {
				// Handle Xero API objects specially
				if ( is_object( $data ) && strpos( get_class( $data ), 'XeroAPI' ) !== false ) {
					// Use serialize for Xero API objects as they contain non-serializable properties
					// @codingStandardsIgnoreLine
					$data = serialize( $data );
				} elseif ( is_array( $data ) && !empty( $data ) ) {
					// Check if array contains Xero API objects
					$first_item = reset( $data );
					if ( is_object( $first_item ) && strpos( get_class( $first_item ), 'XeroAPI' ) !== false ) {
						$data = serialize( $data );
					} else {
						$json_data = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
						$data = $json_data !== false ? $json_data : serialize( $data );
					}
				} else {
					$json_data = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
					$data = $json_data !== false ? $json_data : serialize( $data );
				}
			}

			$output  = "=== DEBUG LOG ENTRY ===\n";
			$output .= "Message: " . $data . "\n";

			// 🔍 Hook trace (this plugin only)
			if ( $include_hooks ) {
				global $wp_actions, $wp_filter;

				$plugin_base = plugin_dir_path( __FILE__ ); // this plugin’s folder
				$fired_hooks = array_keys( $wp_actions );

				$output .= "Hook Trace (This Plugin Only):\n";
				$output .= str_repeat( '-', 50 ) . "\n";

				foreach ( $fired_hooks as $hook ) {
					if ( isset( $wp_filter[ $hook ] ) ) {
						foreach ( $wp_filter[ $hook ]->callbacks as $priority => $functions ) {
							foreach ( $functions as $function ) {
								$callback = $function['function'];
								$name     = 'Unknown';
								$file     = '';

								if ( is_string( $callback ) && function_exists( $callback ) ) {
									$ref  = new ReflectionFunction( $callback );
									$file = $ref->getFileName();
									$name = $callback;
								} elseif ( is_array( $callback ) ) {
									if ( method_exists( $callback[0], $callback[1] ) ) {
										$ref  = new ReflectionMethod( $callback[0], $callback[1] );
										$file = $ref->getFileName();
										$name = is_object( $callback[0] )
											? get_class( $callback[0] ) . '->' . $callback[1]
											: $callback[0] . '::' . $callback[1];
									}
								} elseif ( $callback instanceof Closure ) {
									$ref  = new ReflectionFunction( $callback );
									$file = $ref->getFileName();
									$name = 'Closure';
								}

								// Filter: only include callbacks from this plugin folder
								if ( $file && strpos( $file, $plugin_base ) === 0 ) {
									$output .= sprintf(
										"🔥 %s (hook: %s, priority %d)\n   ↳ %s\n",
										$name,
										$hook,
										$priority,
										$file
									);
								}
							}
						}
					}
				}

				$output .= str_repeat( '-', 50 ) . "\n";
			}

			$log->debug( $output . PHP_EOL, array( 'source' => 'Myworks-Sync-For-Xero-Debug-Log' ) );
		}
	}
	
	#Xero API Rate Limiting Functions
	public function check_xero_rate_limits($response_headers = null) {
		$current_time = time();
		$minute_key = 'xero_api_minute_' . floor($current_time / 60);
		$day_key = 'xero_api_day_' . gmdate('Y-m-d', $current_time);
		
		// Get current counts
		$minute_calls = (int) get_transient($minute_key);
		$daily_calls = (int) get_option($day_key, 0);
		
		// Update from headers if available
		if ($response_headers) {
			$min_remaining = isset($response_headers['X-MinLimit-Remaining']) ? (int) $response_headers['X-MinLimit-Remaining'][0] : null;
			$day_remaining = isset($response_headers['X-DayLimit-Remaining']) ? (int) $response_headers['X-DayLimit-Remaining'][0] : null;
			
			if ($min_remaining !== null) {
				$minute_calls = 60 - $min_remaining;
			}
			if ($day_remaining !== null) {
				$daily_calls = 5000 - $day_remaining;
			}
		}
		
		return array(
			'minute_calls' => $minute_calls,
			'daily_calls' => $daily_calls,
			'minute_limit_reached' => $minute_calls >= 58, // Leave buffer of 2
			'daily_limit_reached' => $daily_calls >= 4950,  // Leave buffer of 50
			'should_delay' => $minute_calls >= 50 // Start slowing down at 50 calls
		);
	}
	
	public function increment_xero_api_count($response_headers = null) {
		$current_time = time();
		$minute_key = 'xero_api_minute_' . floor($current_time / 60);
		$day_key = 'xero_api_day_' . gmdate('Y-m-d', $current_time);
		
		// Increment counters
		$minute_calls = (int) get_transient($minute_key) + 1;
		$daily_calls = (int) get_option($day_key, 0) + 1;
		
		// Update transients
		set_transient($minute_key, $minute_calls, 120); // 2 minute expiry
		update_option($day_key, $daily_calls);
		
		// Update from headers if available
		if ($response_headers) {
			$min_remaining = isset($response_headers['X-MinLimit-Remaining']) ? (int) $response_headers['X-MinLimit-Remaining'][0] : null;
			$day_remaining = isset($response_headers['X-DayLimit-Remaining']) ? (int) $response_headers['X-DayLimit-Remaining'][0] : null;
			
			if ($min_remaining !== null) {
				set_transient($minute_key, 60 - $min_remaining, 120);
			}
			if ($day_remaining !== null) {
				update_option($day_key, 5000 - $day_remaining);
			}
		}
		
		$this->add_wc_debug_log("API Call Count - Minute: $minute_calls/60, Daily: $daily_calls/5000", true, false, false);
	}
	
	public function should_delay_api_call() {
		$limits = $this->check_xero_rate_limits();
		
		if ($limits['minute_limit_reached']) {
			$wait_time = 60 - (time() % 60) + 1; // Wait until next minute
			$this->add_wc_debug_log("Rate limit reached - waiting {$wait_time} seconds", true, false, false);
			return $wait_time;
		}
		
		if ($limits['should_delay']) {
			$delay = min(5, $limits['minute_calls'] - 45); // Progressive delay 1-5 seconds
			return $delay;
		}
		
		return 0;
	}
	
	
	#Queue processing with rate limiting
	public function process_queue_with_rate_limiting() {
		global $wpdb;
		
		$limits = $this->check_xero_rate_limits();
		if ($limits['daily_limit_reached']) {
			$this->add_wc_debug_log("Daily API limit reached - skipping queue processing", true, false, false);
			return false;
		}
		
		// Reduce queue processing when approaching limits
		$max_items = 10;
		if ($limits['should_delay']) {
			$max_items = 3; // Process fewer items when approaching limit
		}
		
		$table = $this->gdtn('queue');
		$sql = "SELECT * FROM `" . esc_sql($table) . "` WHERE `run` = 0 ORDER BY `added_date` ASC LIMIT " . $max_items;
		$queue_data = $this->get_data($sql);
		
		if (is_array($queue_data) && !empty($queue_data)) {
			$this->add_wc_debug_log("Processing " . count($queue_data) . " queue items with rate limiting", true, false, false);
			return $queue_data;
		}
		
		return false;
	}
	
	#API usage monitoring and logging
	public function log_api_usage_summary() {
		$current_stats = get_transient('xero_api_current_stats') ?: array('minute_count' => 0, 'daily_count' => 0);
		$daily_usage = get_option('xero_daily_api_usage') ?: array();
		$today = gmdate('Y-m-d');
		
		// Calculate usage statistics
		$minute_usage = $current_stats['minute_count'];
		$daily_usage_count = $current_stats['daily_count'];
		$minute_remaining = max(0, 60 - $minute_usage);
		$daily_remaining = max(0, 5000 - $daily_usage_count);
		
		// Calculate percentage used
		$minute_percent = round(($minute_usage / 60) * 100, 1);
		$daily_percent = round(($daily_usage_count / 5000) * 100, 1);
		
		$usage_summary = sprintf(
			"=== XERO API USAGE SUMMARY ===\nDate: %s\nMinute Usage: %d/60 (%s%% - %d remaining)\nDaily Usage: %d/5000 (%s%% - %d remaining)\nDaily Limit Status: %s\nMinute Limit Status: %s",
			$today,
			$minute_usage,
			$minute_percent,
			$minute_remaining,
			$daily_usage_count,
			$daily_percent,
			$daily_remaining,
			($daily_usage_count >= 5000) ? 'EXCEEDED' : (($daily_usage_count >= 4500) ? 'WARNING' : 'OK'),
			($minute_usage >= 60) ? 'EXCEEDED' : (($minute_usage >= 50) ? 'WARNING' : 'OK')
		);
		
		// Log to WooCommerce logs
		$this->add_wc_debug_log($usage_summary, true, false, false);
		
		// Save daily usage history
		$daily_usage[$today] = array(
			'total_calls' => $daily_usage_count,
			'last_updated' => current_time('mysql'),
			'minute_peak' => $minute_usage
		);
		
		// Keep only last 30 days of usage data
		$daily_usage = array_slice($daily_usage, -30, 30, true);
		update_option('xero_daily_api_usage', $daily_usage);
		
		return array(
			'minute_usage' => $minute_usage,
			'daily_usage' => $daily_usage_count,
			'minute_remaining' => $minute_remaining,
			'daily_remaining' => $daily_remaining,
			'minute_percent' => $minute_percent,
			'daily_percent' => $daily_percent
		);
	}
	
	#Reset API counters (for testing or manual reset)
	public function reset_xero_api_counters() {
		delete_transient('xero_api_current_stats');
		delete_transient('xero_api_minute_start');
		$this->add_wc_debug_log("Xero API rate limit counters have been manually reset", true, false, false);
	}
	
	#Log Entry
	public function save_log($ld){
		if(is_array($ld) && !empty($ld) && isset($ld['status']) && isset($ld['title']) && !empty($ld['title']) && isset($ld['details'])){
			global $wpdb;

			$table = $this->gdtn('log');
			
			$save_log_for_days = $this->get_option('mw_wc_xero_sync_save_log_for_days');
			#$this->log_save_days			
			
			$save_log_for_days = (empty($save_log_for_days))?30:$save_log_for_days;
			
			if($save_log_for_days != 'NL'){
				$save_log_for_days = (int) $save_log_for_days;
				if($save_log_for_days > 0){
					$log_last_date = gmdate('Y-m-d',strtotime("-{$save_log_for_days} days",strtotime($this->now())));
					$log_last_date = $log_last_date.' 23:59:59';
					
					$wpdb->query($wpdb->prepare("DELETE FROM `" . esc_sql($table) . "` WHERE `added_date` < %s", $log_last_date)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
			}
			
			$sld = array();
			$log_type = (isset($ld['type']) && !empty($ld['type']))?trim($ld['type']):'Other';
			$sld['log_type'] = $log_type;
			$sld['log_title'] = $this->sanitize($ld['title']);
			$sld['details'] = $this->sanitize($ld['details']);
			$status = (int) $ld['status'];
			$status = abs($status);			
			$sld['status'] = $status;
			$sld['wc_id'] = (isset($ld['wc_id']))?(int) $ld['wc_id']:0;
			$sld['xero_id'] = (isset($ld['xero_id']))?$this->sanitize($ld['xero_id']):'';
			$sld['added_date'] = $this->now();
			$sld = array_map('trim',$sld);
			
			$wpdb->insert($table, $sld);

			// Track first successful sync in Mixpanel and Intercom
			if ( $status === 1 ) {
				$mixpanel_type_map = array(
					'Order'     => 'order',
					'Invoice'   => 'order',
					'Customer'  => 'customer',
					'Contact'   => 'customer',
					'Product'   => 'product',
					'Item'      => 'product',
					'Variation' => 'product',
					'Inventory' => 'inventory',
				);

				if ( isset( $mixpanel_type_map[ $log_type ] ) ) {
					MyWorks_Mixpanel_Integration::track_first_sync( $mixpanel_type_map[ $log_type ] );

					// Track first sync in Intercom
					if ( class_exists( 'MyWorks_Intercom_Integration' ) ) {
						MyWorks_Intercom_Integration::track_first_sync( $mixpanel_type_map[ $log_type ] );
					}
				}

				// Track milestone for orders synced in Intercom
				if ( in_array( $log_type, array( 'Order', 'Invoice' ), true ) ) {
					if ( class_exists( 'MyWorks_Intercom_Integration' ) ) {
						// Get total count of synced orders
						$table = $this->gdtn('log');
						$total_orders = $wpdb->get_var(
							"SELECT COUNT(DISTINCT wc_id)
							FROM `" . esc_sql($table) . "`
							WHERE log_type IN ('Order', 'Invoice')
							AND status = 1
							AND wc_id > 0"
						);

						if ( $total_orders ) {
							MyWorks_Intercom_Integration::track_milestone( intval( $total_orders ) );
						}
					}
				}
			}
		}
	}
	
	# WooCommerce version
	public function get_woo_version_number(){
		if(!function_exists('get_plugins')){
			require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		}
		
		$p_folder = get_plugins( '/' . 'woocommerce' );
		$p_file = 'woocommerce.php';		
		
		if(is_array($p_folder) && isset($p_folder[$p_file]['Version'])){
			return $p_folder[$p_file]['Version'];
		}else{		
			return NULL;
		}
	}
	
	# WooCommerce variation name trim
	# Character-based, and cleaned first, for the same reason as get_array_isset(): this name goes
	# to Xero as the item name, and a byte-based split through a multibyte character left half of
	# it behind. Refs #148
	public function get_woo_v_name_trimmed($v_name){
		$v_name = trim($v_name);
		$v_name = $this->clean_utf8($v_name);
		if(!empty($v_name) && mb_strlen($v_name,'UTF-8') > 50){
			$fs = mb_substr($v_name, 0, 24, 'UTF-8');
			$ls = mb_substr($v_name, -25, null, 'UTF-8');
			$v_name = $fs.' '.$ls;
		}
		return $v_name;
	}
	
	# Queue add function
	public function wx_queue_add($item_type,$wx_id,$item_action,$priority=1,$wc_hook='',$ext_data=array(),$note=''){
		
		// $this->add_wc_debug_log( $item_type, true, true, true );
		// $this->add_wc_debug_log( $item_action, true, true, true );
		
		$a_w_id = false;
		$item_id = 0; $xero_id = '';
		if(!is_int($wx_id) && strlen($wx_id) == 36){
			$xero_id = trim($wx_id);			
		}else{
			$item_id = (int) $wx_id;			
		}
		
		if(!empty($item_type) && ($item_id > 0 || !empty($xero_id) || $a_w_id) && !empty($item_action)){
			global $wpdb;
			$table = $this->gdtn('queue');
			if(!empty($xero_id)){
				$eq_q = $wpdb->prepare("SELECT id FROM `" . esc_sql($table) . "` WHERE `run` = 0 AND `item_type` = %s AND `item_action` = %s AND `xero_id` = %s ",$item_type,$item_action,$xero_id); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}else{
				$eq_q = $wpdb->prepare("SELECT id FROM `" . esc_sql($table) . "` WHERE `run` = 0 AND `item_type` = %s AND `item_action` = %s AND `item_id` = %d ",$item_type,$item_action,$item_id); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			
			$eq_d = $this->get_row($eq_q);
			if(empty($eq_d)){
				$save_queue_data = array();
				$ext_data_s = '';
				if(is_array($ext_data) && !empty($ext_data)){
					$ext_data_s = serialize($ext_data);
				}
				
				$save_queue_data['item_type'] = $item_type;
				$save_queue_data['item_action'] = $item_action;
				$save_queue_data['item_id'] = $item_id;
				$save_queue_data['xero_id'] = $xero_id;
				$save_queue_data['priority'] = intval($priority);
				$save_queue_data['woocommerce_hook'] = $wc_hook;
				$save_queue_data['ext_data'] = $ext_data_s;
				$save_queue_data['run'] = 0;
				$save_queue_data['note'] = $note;		
				$save_queue_data['status'] = 'q';
				
				$save_queue_data['added_date'] = $this->now();
				
				$wpdb->insert($table, $save_queue_data);
				return $wpdb->insert_id;
			}else{
				return $eq_d['id'];
			}
		}
		
		return false;
	}

	public function wx_queue_exists($item_type,$wx_id,$item_action){
		$a_w_id = false;
		$item_id = 0; $xero_id = '';
		if(!is_int($wx_id) && strlen($wx_id) == 36){
			$xero_id = trim($wx_id);			
		}else{
			$item_id = (int) $wx_id;			
		}
		
		if(!empty($item_type) && ($item_id > 0 || !empty($xero_id) || $a_w_id) && !empty($item_action)){
			global $wpdb;
			$table = $this->gdtn('queue');
			if(!empty($xero_id)){
				$eq_q = $wpdb->prepare("SELECT id FROM `" . esc_sql($table) . "` WHERE `run` = 0 AND `item_type` = %s AND `item_action` = %s AND `xero_id` = %s ",$item_type,$item_action,$xero_id); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}else{
				$eq_q = $wpdb->prepare("SELECT id FROM `" . esc_sql($table) . "` WHERE `run` = 0 AND `item_type` = %s AND `item_action` = %s AND `item_id` = %d ",$item_type,$item_action,$item_id); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			
			$eq_d = $this->get_row($eq_q);
			if(!empty($eq_d)){
				return true;
			}
		}

		return false;
	}
	
	public function wx_queue_remove($item_type,$wx_id,$item_action,$run=0){
		#-> Will be added later if needed
	}
	
	# Xero Unit Decimal Places
	public function x_unitdp(){
		return 4;
	}
	
	# Xero Fields Char Limits
	public function xfcl($f){
		if(!empty($f)){
			#->
		}
	}
	
	#Test Dev Log File Entry
	public function add_text_into_log_file($log_txt,$clear_last_day=true,$append=true,$lf='dev.log'){
		if(empty($log_txt)){
			return;
		}
		
		if(is_object($log_txt) || is_array($log_txt)){
		$log_txt = serialize($log_txt);
		}
		
		if(empty($lf)){
			$lf = 'dev.log';
		}
		
		$f_log_txt = trim($log_txt).PHP_EOL;
		
		#$log_file_name = plugin_dir_path( dirname( dirname(__FILE__) ) ) .'log'.DIRECTORY_SEPARATOR.$lf;
		$log_file_name = MW_WC_XERO_SYNC_P_DIR_P.'log'.DIRECTORY_SEPARATOR.$lf;
		$f_ot = ($append)?'a':'w';
		
		if($clear_last_day && $append && file_exists($log_file_name)){
			if((time()-filemtime($log_file_name)) > 86400){
				$f_ot = 'w';
			}
		}

		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		
		if ( $f_ot === 'w' ) {
			$wp_filesystem->put_contents( $log_file_name, "\n" . $f_log_txt );
		} else {
			$existing_content = $wp_filesystem->exists( $log_file_name ) ? $wp_filesystem->get_contents( $log_file_name ) : '';
			$wp_filesystem->put_contents( $log_file_name, $existing_content . "\n" . $f_log_txt );
		}		
	}
	
	#Xero sync success log file
	public function add_xs_sd_into_log_file($log_txt,$clear_last_day=true,$append=true){
		if(!$this->option_checked('mw_wc_xero_sync_add_sync_success_data_into_log_file')){
			return false;
		}
			
		$this->add_text_into_log_file($log_txt,$clear_last_day,$append,'x-success.log');
	}
	
	#Xero sync error log file
	public function add_xs_ed_into_log_file($log_txt,$clear_last_day=true,$append=true){
		if(!$this->option_checked('mw_wc_xero_sync_add_sync_error_data_into_log_file')){
			return false;
		}
			
		$this->add_text_into_log_file($log_txt,$clear_last_day,$append,'x-error.log');
	}
	
	public function is_update_enabled($entity){
		return false;
	}
	
	public function null_to_string($v){
		if(is_null($v)){
			$v = (string) $v;
		}
		
		return $v;
	}
	
	public function arr_nts($arr){
		if(!is_array($arr)){
			return $arr;
		}
		
		$n_arr = array();
		if(!empty($arr)){
			foreach($arr as $k => $v){
				$n_arr[$k] = $this->null_to_string($v);
			}
		}
		return $n_arr;
		#array_map(array($this,'null_to_string'),$arr);
	}
	
	public function get_customer_formated_display_name_for_xs($customer_data,$name=''){
		if(is_array($customer_data) && !empty($customer_data)){
			$format = $this->get_option('mw_wc_xero_sync_new_customer_dname_format');
			if(empty($format)){
				$format = '{firstname} {lastname}';
			}
			
			$format = str_replace(
				array('{firstname}','{lastname}','{companyname}','{id}','{phone_number}'),
				array('{first_name}','{last_name}','{company}','{wc_cus_id}','{billing_phone}'),
				$format
			);
			
			$wc_cus_id = (int) $this->get_array_isset($customer_data,'wc_cus_id','');
			
			$s_arr = array('{first_name}','{last_name}','{company}','{wc_cus_id}','{email}','{display_name}','{billing_phone}');
			$r_arr = array(
				$customer_data['first_name'],
				$customer_data['last_name'],
				$customer_data['company'],
				$wc_cus_id,
				$customer_data['email'],			
				(isset($customer_data['display_name']))?$customer_data['display_name']:'',
				(isset($customer_data['billing_phone']))?$customer_data['billing_phone']:'',
			);
			
			$nr_chars = $this->x_nrc('customer');
			
			$x_f_n = str_replace($s_arr,$r_arr,$format);
			# Fallback
			if(empty(trim($x_f_n))){
				$x_f_n = $customer_data['first_name'].' '.$customer_data['last_name'];
			}

			if(empty(trim($x_f_n)) && isset($customer_data['display_name'])){
				$x_f_n = $customer_data['display_name'];
			}

			if(empty(trim($x_f_n))){
				$x_f_n = $customer_data['email'];
			}
			
			$x_f_n = $this->get_array_isset(array('x_f_n'=>$x_f_n),'x_f_n','',true,255,false,$nr_chars);
			return $x_f_n;
		}
		
		return $name;
	}
	
	# Xero name replace char for customer, product etc.
	public function x_nrc($t=''){
		$chars = array(':','\t','\n');
		return $chars;
	}

	# WC get customer currency by id
	public function get_wc_customer_currency($wc_cus_id){
		$wc_cus_id = (int) $wc_cus_id;
		if($wc_cus_id){
			global $wpdb;
			// Security: Use prepared statement for customer user lookup
			$om = $this->get_row($wpdb->prepare("SELECT `post_id` FROM `{$wpdb->postmeta}` WHERE `meta_key` = '_customer_user' AND `meta_value` = %s LIMIT 0,1", $wc_cus_id));
			if(is_array($om) && count($om)){
				$order_id = (int) $om['post_id'];
				if($order_id){
					// Security: Use prepared statement for order currency lookup
					$om = $this->get_row($wpdb->prepare("SELECT `meta_value` FROM `{$wpdb->postmeta}` WHERE `meta_key` = '_order_currency' AND `post_id` = %d LIMIT 0,1", $order_id));
					if(is_array($om) && count($om)){
						return $om['meta_value'];
					}
				}
			}
		}
	}
	
	# Xero error object to formatted array
	private function format_xero_errors($error){
		$e_arr = array();
		if(is_object($error) && !empty($error)){
			$e_arr = array(
				'error_number' => $error->getErrorNumber(),
				'type' => $error->getType(),
				'message' => $error->getMessage(),
				'eml' => array(),
			);

			$elements = $error->getElements();
			if(is_array($elements) && isset($elements[0]['validation_errors'])){
				$validation_errors = $elements[0]['validation_errors'];
				if(is_array($validation_errors) && !empty($validation_errors)){
					foreach($validation_errors as $ve){
						$e_arr['eml'][] = $ve['message'];
					}
				}
			}
		}
		return $e_arr;		
	}

	# For error log entry
	protected function get_error_message_from_xero_error_object($error){
		$error_message = '';
		$e_arr = $this->format_xero_errors($error);
		if(is_array($e_arr) && !empty($e_arr)){
			$error_message .= 'Error Type: '.$e_arr['type'].'('.$e_arr['error_number'].')'.PHP_EOL;			
			$eml = $e_arr['message'];
			if(is_array($e_arr['eml']) && !empty($e_arr['eml'])){
				foreach($e_arr['eml'] as $k => $v){
					$eml .= PHP_EOL.$v;
				}
			}

			$error_message .= 'Error Message: '.$eml;
		}

		return $error_message;
	}

	protected function get_error_message_from_xero_validation_errors($validation_errors){
		$v_err_msg = '';
		if(is_array($validation_errors) && !empty($validation_errors)){
			foreach($validation_errors as $ve_k => $ve_v){
				$message = $ve_v->getMessage();
				if(!empty($message)){
					$v_err_msg .= $message.PHP_EOL;
				}				
			}
		}

		if(!empty($v_err_msg)){
			$v_err_msg = 'Error Type: Validation Error'.PHP_EOL.'Error Message: '.$v_err_msg;
			trim($v_err_msg);
		}

		return $v_err_msg;
	}

	/**
	 * Xero rejects order/refund lines that resolve no account code with a terse validation
	 * error; append the fix (settings/mapping) so the log entry is actionable on its own.
	 *
	 * @param string $error_message Error message built from the Xero response.
	 * @return string
	 */
	protected function append_line_account_code_hint( $error_message ) {
		if ( ! empty( $error_message ) && false !== stripos( $error_message, 'Account code or ID must be specified' ) ) {
			$error_message .= PHP_EOL . __( 'Tip: no Xero account was resolved for one or more line items. Set the "Default Xero Account for Order Line Items" in MyWorks Sync > Settings, or map the product/variation/category to a Xero account.', 'myworks-sync-for-xero' );
		}

		return $error_message;
	}
	
	# Log page view in xero link 
	public function get_log_page_view_in_xero_link($data){
		$xero_view_link = '';
		if(is_array($data) && !empty($data) && isset($data['log_type']) && !empty($data['log_type'])){
			$xero_view_items = array(
				'Customer',
				'Product',
				'Variation',
				'Inventory',
				'Order',
				'Payment',
			);

			if(in_array($data['log_type'],$xero_view_items) && $data['status'] >= 1 && $data['wc_id'] > 0 && !empty($data['xero_id'])){				
				$chk_extra_options = true;

				if($chk_extra_options){
					$xero_url = 'https://go.xero.com/';
					$xero_id = $data['xero_id'];

					switch ($data['log_type']) {
						case "Customer":
							$xero_view_link = $xero_url.'Contacts/View/'.$xero_id;
							break;
						case "Order":
							if(strpos($data['details'],'Quote')!==false){
								$xero_view_link = '';
							}else{
								$xero_view_link = $this->get_xero_view_invoice_link_by_id($xero_id);
							}
							
							break;
						case "Product":
							$xero_view_link = $xero_url.'app/products-and-services/'.$xero_id;
							break;
						case "Variation":
							$xero_view_link = $xero_url.'app/products-and-services/'.$xero_id;
							break;
						case "Inventory":
							$xero_view_link = $xero_url.'app/products-and-services/'.ltrim($xero_id,'#');
							break;
						case "Payment":
							$xero_view_link = $xero_url.'Bank/ViewTransaction.aspx?bankTransactionID='.ltrim($xero_id,'#');
							break;
						default:

					}
				}
			}
		}
		
		if(!empty($xero_view_link)){
			echo '<a target="_blank" class="lg_qb_view mqls-xero mqls-logo" href="'.esc_url($xero_view_link).'" title="'.esc_attr__('View in Xero','myworks-sync-for-xero').'" aria-label="'.esc_attr__('View in Xero','myworks-sync-for-xero').'"><img src="'.esc_url(plugins_url('myworks-sync-for-xero/admin/image/xero-logo.svg')).'" alt="'.esc_attr__('View in Xero','myworks-sync-for-xero').'" width="25" height="25" style="width:25px;height:25px;display:block;" /></a>';
		}
	}
	
	public function get_xero_view_invoice_link_by_id($InvoiceID){
		$InvoiceID = (string) $InvoiceID;
		if(!empty($InvoiceID) && strlen($InvoiceID) == 36){
			return esc_url('https://go.xero.com/AccountsReceivable/Edit.aspx?InvoiceID='.$InvoiceID);
		}
		
		return '';
	}
	
	public function get_log_page_details_field_data_formatted($data){
		if(is_array($data) && isset($data['details']) && !empty($data['details'])){
			$details = $data['details'];
			if($data['status']==1 && $data['wc_id'] > 0 && !empty($data['xero_id'])){
				return str_replace(
					array('#'.$data['wc_id'],'#'.$data['xero_id'],'\n'),
					array(
						'{LPDW_S}#'.esc_html($data['wc_id']).'{LPDW_E}',
						'{LPDX_S}#'.esc_html($data['xero_id']).'{LPDX_E}',
						'{LB}',
					),
					$details
				);
			}else{
				return str_replace(
					array('\n'),
					array('{LB}'),
					$details
				);
			}
		}

		return $data['details'];
	}
	
	# Get mapped payment method data by order payment method and currency
	public function get_mapped_payment_method_data($wc_payment_method,$wc_currency=''){
		$wc_payment_method = $this->sanitize($wc_payment_method);
		if(!empty($wc_payment_method)){
			$wc_currency = (!empty($wc_currency))?$this->sanitize($wc_currency):$wc_currency;
			global $wpdb;
			$table = $this->gdtn('map_payment_method');
			$map_data = $this->get_row($wpdb->prepare("SELECT * FROM `" . esc_sql($table) . "` WHERE `wc_payment_method` = %s AND `currency` = %s", $wc_payment_method, $wc_currency)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return $map_data;
		}
	}

	# Resolve the payment method mapping, falling back to the "Other" row when the order's
	# payment method is empty or has no mapping of its own. Fully opt-in: returns empty when
	# the "Other" row is not configured, so behavior is unchanged unless the user maps it. Refs #78
	public function get_pm_map_data_with_other_fallback($wc_payment_method,$wc_currency=''){
		if(!empty($wc_payment_method)){
			$map_data = $this->get_mapped_payment_method_data($wc_payment_method,$wc_currency);
			if(!empty($map_data)){
				return $map_data;
			}
		}
		return $this->get_mapped_payment_method_data('other',$wc_currency);
	}
	
	# Order sync helper functions
	public function get_xosa_arr(){
		return array('Invoice','Quote');
	}
	
	protected function is_sync_fee_line(){
		return true;
	}
	
	protected function is_sync_txn_fee($invoice_data,$pmm_data=array()){
		return true;
	}
	
	#Tax related functions
	protected function get_order_line_tax_inclusive_amount($line_data, $line_type, $is_refund=false){
		if(is_array($line_data) && !empty($line_data) && !empty($line_type)){
			if($line_type == 'line_item'){
				$ltf = 'line_subtotal_tax';
				if($is_refund){
					$line_data['UnitPrice'] = abs((float) $line_data['UnitPrice']);
					if(isset($line_data[$ltf])){
						$line_data[$ltf] = abs((float) $line_data[$ltf]);
					}					
				}

				if(isset($line_data[$ltf]) && $line_data[$ltf] > 0){
					$unit_price = $line_data['UnitPrice'] + ($line_data[$ltf]/$line_data['Qty']);
					#$unit_price = ($line_data['line_subtotal']+$line_data[$ltf])/$line_data['Qty'];
					return (float) $unit_price;
				}

				return (float) $line_data['UnitPrice'];
			}

			if($line_type == 'shipping'){
				$ltf = 'total_tax';
				if($is_refund){
					$line_data['cost'] = abs((float) $line_data['cost']);
					if(isset($line_data[$ltf])){
						$line_data[$ltf] = abs((float) $line_data[$ltf]);
					}					
				}

				if(isset($line_data[$ltf]) && $line_data[$ltf] > 0){
					return (float) $line_data['cost']+$line_data[$ltf];
				}

				return (float) $line_data['cost'];
			}

			if($line_type == 'fee'){
				$ltf = '_line_tax';
				if($is_refund){
					$line_data['_line_total'] = abs((float) $line_data['_line_total']);
					if(isset($line_data[$ltf])){
						$line_data[$ltf] = abs((float) $line_data[$ltf]);
					}					
				}

				if(isset($line_data[$ltf]) && $line_data[$ltf] > 0){
					return (float) $line_data['_line_total']+$line_data[$ltf];
				}
				
				return (float) $line_data['_line_total'];
			}
		}

		return 0;
	}

	protected function get_xero_tax_code_from_line_tax_data($line_data,$line_type='line_item',$xntc=true){
		$tr1_id = 0;
		$tr2_id = 0;

		if(is_array($line_data) && !empty($line_data) && !empty($line_type)){
			$ltd_arr = array();
			$ltd = '';

			if($line_type == 'line_item'){
				$ltd = $line_data['line_tax_data'];
			}

			if($line_type == 'shipping'){
				$ltd = $line_data['taxes'];
			}

			if($line_type == 'fee'){
				$ltd = $line_data['_line_tax_data'];
			}
			
			if(!empty($ltd)){
				$ltd = maybe_unserialize($ltd);
				if(is_array($ltd) && !empty($ltd)){
					if(isset($ltd['total']) && is_array($ltd['total']) && !empty($ltd['total'])){
						foreach($ltd['total'] as $tk => $tv){
							if(is_numeric($tv)){
								$ltd_arr[$tk] = $tv;
							}
						}
					}
				}
			}

			if(is_array($ltd_arr) && !empty($ltd_arr)){
				$i=1;
				foreach($ltd_arr as $k=>$v){
					if($i==1){
						$tr1_id = (int) $k;
					}
					if($i==2){
						$tr2_id = (int) $k;
					}
					$i++;
				}
			}
		}

		$xero_tax_code = '';
		if($tr1_id>0 || $tr2_id>0){
			$xero_tax_code = $this->get_xero_mapped_tax_code($tr1_id,$tr2_id);
		}

		if(empty($xero_tax_code) && $xntc){
			$xero_tax_code = $this->get_xero_non_taxable_tax_code();
		}

		return $xero_tax_code;
	}
	
	protected function get_xero_mapped_tax_code($tax_rate_id,$tax_rate_id_2=0){
		$xero_tax_code = '';
		$tax_rate_id = (int) $tax_rate_id;
		$tax_rate_id_2 = (int) $tax_rate_id_2;
		if($tax_rate_id > 0){
			global $wpdb;
			$tax_map_table = $this->gdtn('map_tax');
			$tax_map_data = $this->get_row($wpdb->prepare("SELECT `xero_tax` FROM `" . esc_sql($tax_map_table) . "` WHERE `wc_tax_id` = %d AND `wc_tax_id_2` = %d", $tax_rate_id, $tax_rate_id_2)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if(is_array($tax_map_data) && count($tax_map_data)){
				$xero_tax_code = $tax_map_data['xero_tax'];
			}
		}

		return $xero_tax_code;
	}

	public function get_xero_non_taxable_tax_code(){
		return $this->get_option('mw_wc_xero_sync_non_taxable_rate');
	}
	
	public function invoice_due_days_options(){
		return array_combine(range(1,100), range(1,100));
	}

	# Txn fee data from Wc order
	public function get_txn_fee_data_from_order($invoice_data){
		$t_f_desc = 'Transaction Fee';
		$t_f_amnt = 0;
		$tfk = '';

		try{

		if(is_array($invoice_data) && !empty($invoice_data)){
			$pm_c = $this->get_array_isset($invoice_data,'_payment_method','',true);
			$_p_m_t = $this->get_array_isset($invoice_data,'_payment_method_title','',true);
			
			if(!empty($pm_c)){$pm_c = strtolower($pm_c);}
			if(!empty($_p_m_t)){$_p_m_t = strtolower($_p_m_t);}
			
			#New
			$skip_pm_pmt_c = true;
			
			if($skip_pm_pmt_c || strpos($pm_c, 'stripe') !== false || strpos($_p_m_t, 'stripe') !== false){
				$isf = false;
				if(isset($invoice_data['_stripe_fee'])){
					$isf = true;
					$tfk = '_stripe_fee';
				}else{
					if(isset($invoice_data['Stripe Fee'])){
						$isf = true;
						$tfk = 'Stripe Fee';
					}
				}
				
				if($isf){
					$t_f_desc = 'Stripe Fee';
				}				
			}		
			
			if($skip_pm_pmt_c || strpos($pm_c, 'paypal') !== false || strpos($_p_m_t, 'paypal') !== false){
				$ipf = false;
				
				#New WooCommerce PayPal gateway fee support
				if(isset($invoice_data['_ppcp_paypal_fees']) && !empty($invoice_data['_ppcp_paypal_fees'])){
					$_ppcp_paypal_fees = maybe_unserialize($invoice_data['_ppcp_paypal_fees']);
					#$this->_p($_ppcp_paypal_fees);
					
					#New
					if(is_array($_ppcp_paypal_fees) && !empty($_ppcp_paypal_fees) && isset($_ppcp_paypal_fees[0]['paypal_fee'])){
						$_ppcp_paypal_fees = $_ppcp_paypal_fees[0];
					}
					
					if(is_array($_ppcp_paypal_fees) && isset($_ppcp_paypal_fees['paypal_fee']) && !empty($_ppcp_paypal_fees['paypal_fee'])){
						if($_ppcp_paypal_fees['paypal_fee']['currency_code'] == $invoice_data['_order_currency']){
							$invoice_data['_paypal_transaction_fee'] = floatval($_ppcp_paypal_fees['paypal_fee']['value']);
							$ipf = true;
							$tfk = '_paypal_transaction_fee';
						}
					}
				}

				if(isset($invoice_data['_paypal_transaction_fee'])){
					$ipf = true;
					$tfk = '_paypal_transaction_fee';
				}else{
					if(isset($invoice_data['_paypal_fee'])){
						$ipf = true;
						$tfk = '_paypal_fee';
					}else{
						if(isset($invoice_data['PayPal Transaction Fee'])){
							$ipf = true;
							$tfk = 'PayPal Transaction Fee';
						}
					}
				}
				
				if($ipf){
					$t_f_desc = 'PayPal Transaction Fee';
				}				
			}

			// Adding 'woocommerce_payments' transaction fee
			if($skip_pm_pmt_c || strpos($pm_c, 'woocommerce_payments') !== false || strpos($_p_m_t, 'woocommerce_payments') !== false){
				$isf = false;
				if(isset($invoice_data['_wcpay_transaction_fee'])){
					$isf = true;
					$tfk = '_wcpay_transaction_fee';
				}else{
					if(isset($invoice_data['WooPayments Fee'])){
						$isf = true;
						$tfk = 'WooPayments Fee';
					}
				}
				
				if($isf){
					$t_f_desc = 'WooPayments Fee';
				}				
			}
			
			if(empty($tfk) && isset($invoice_data['_transaction_fee'])){
				$tfk = '_transaction_fee';
			}
			
			if(empty($tfk) && isset($invoice_data['transaction_fee'])){
				$tfk = 'transaction_fee';
			}
			
			if(!empty($tfk) && isset($invoice_data[$tfk])){
				$t_f_amnt = (float) $this->get_array_isset($invoice_data,$tfk,0);
			}
		}		
		
		}catch(\TypeError $e){
			// A meta value arrived as an unexpected type (e.g. array passed to unserialize on PHP 8).
			// Return safe defaults so the calling sync path is not fataled.
		}

		$t_f_a = array(
			't_f_desc' => $t_f_desc,
			't_f_amnt' => $t_f_amnt,
		);

		return $t_f_a;
	}
	
	# For order sync line item
	public function get_xero_product_line_item_data($xero_item_id,$xero_product_data=null){
		$r_arr = array(
			'ItemID' => $xero_item_id,
			'Name' => '',
			'Code' => '',
			'IsTrackedAsInventory' => 0,
			'SD_AccountCode' => '',
			'SD_TaxType' => '',
		);
		
		if(!empty($xero_item_id)){
			if(is_null($xero_product_data)){
				$xero_product_data = $this->get_row_by_val($this->gdtn('products'),'ItemID',$xero_item_id);
			}

			if(is_array($xero_product_data) && !empty($xero_product_data)){
				$X_Data = $xero_product_data['X_Data'];
				if(!empty($X_Data)){
					$X_Data = maybe_unserialize($X_Data);
				}

				foreach($r_arr as $k => $v){
					if(isset($xero_product_data[$k])){
						$r_arr[$k] = $xero_product_data[$k];
					}
				}

				if(is_array($X_Data) && !empty($X_Data)){
					foreach($X_Data as $x_k => $x_v){
						if(isset($r_arr[$x_k]) && !isset($xero_product_data[$x_k])){
							$r_arr[$x_k] = $x_v;
						}
					}
				}
			}
		}
		
		if(empty($r_arr['SD_AccountCode'])){
			$r_arr['SD_AccountCode'] = $this->get_option('mw_wc_xero_sync_default_xero_account_foli');
		}
		
		return $r_arr;
	}
	
	protected function add_order_note($order_id,$note){
		if($order_id > 0 && !empty($note)){
			$order = new WC_Order( $order_id );
			if(!empty($order)){
				$order->add_order_note($note);
			}
		}
	}

	# For product pull
	public function if_xero_item_exists_in_woo($product_data, $omc=false){
		if(is_array($product_data) && !empty($product_data)){
			$X_P_ID = $this->get_array_isset($product_data,'X_P_ID','',false);
			if(!empty($X_P_ID)){
				global $wpdb;				
				$table = $this->gdtn('map_products');
				$query = $wpdb->prepare("SELECT `W_P_ID` FROM `" . esc_sql($table) . "` WHERE `X_P_ID` = %s AND `W_P_ID` > 0", $X_P_ID); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$query_product = $this->get_row($query);				
				$r_arr = array();
				if(empty($query_product)){
					$variations_table = $this->gdtn('map_variations');
					$query = $wpdb->prepare("SELECT `W_V_ID` FROM `" . esc_sql($variations_table) . "` WHERE `X_P_ID` = %s AND `W_V_ID` > 0", $X_P_ID); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$query_product = $this->get_row($query);
					if(!empty($query_product)){
						$r_arr['ID'] = $query_product['W_V_ID'];
						$r_arr['is_variation'] = true;
					}
				}else{
					$r_arr['ID'] = $query_product['W_P_ID'];
				}
				
				if(!empty($r_arr)){
					$r_arr['mapped'] = true;
					return $r_arr;
				}else{
					if(!$omc && isset($product_data['Name']) && isset($product_data['Code'])){
						# Name / SKU Check
						$nr_chars = $this->x_nrc('product');
						$Name = $this->get_array_isset($product_data,'Name','',true,50,false,$nr_chars);
						$Code = $this->get_array_isset($product_data,'Code','',true,30);
						
						$post_id = 0;
						if(!empty($Code)){							
							$q = $wpdb->prepare("SELECT `post_id` FROM {$wpdb->postmeta} WHERE `meta_key` = '_sku' AND `meta_value` = %s AND `post_id` > 0 ",$Code);
							$row = $this->get_row($q);
							if(!empty($row)){
								$post_id = (int) $row['post_id'];
								
							}else{
								if(is_int($Code)){
									$post_id = $this->get_field_by_val($wpdb->posts,'ID','ID',$Code);
								}								
							}							
						}

						# Name matching removed - SKU should be the only unique identifier
						# Multiple products can have the same name in both Xero and WooCommerce

						if($post_id > 0){
							$post_type = $this->get_field_by_val($wpdb->posts,'post_type','ID',$post_id);							
							if($post_type == 'product' || $post_type == 'product_variation'){
								$r_arr['ID'] = $post_id;
								if($post_type == 'product_variation'){
									$r_arr['is_variation'] = true;
								}								
								return $r_arr;
							}
						}
					}
				}
			}
		}
		return false;
	}
	
	public function save_wp_post($post_type,$post_data,$post_meta_arr=array(),$tax_input=array()){
		if($post_type!='' && is_array($post_data) && count($post_data)){
			$wp_error = $this->get_array_isset($post_data,'wp_error',false);
			$post_data['post_type'] = $post_type;
			
			if(isset($post_data['ID']) && (int) $post_data['ID']){
				$return = wp_update_post( $post_data ,$wp_error );
			}else{
				$return = wp_insert_post( $post_data ,$wp_error );
			}

			if((int) $return && is_array($post_meta_arr) && count($post_meta_arr)){
				$post_id = (int) $return;
				foreach($post_meta_arr as $key => $val){
					update_post_meta($post_id, $key, $val);
				}				
				
				if(is_array($tax_input) && isset($tax_input['product_cat']) && is_array($tax_input['product_cat']) && !empty($tax_input['product_cat'])){					
					wp_set_object_terms($post_id, $tax_input['product_cat'], 'product_cat',true);
					unset($tax_input['product_cat']);
				}
				
				$_product_attributes = array();
				if(is_array($tax_input) && !empty($tax_input)){
					foreach($tax_input as $tx_k => $tx_v){						
						wp_set_object_terms($post_id, $tx_v, $tx_k,true);
						
						if($this->start_with($tx_k,'pa_')){
							$_product_attributes[$tx_k] = array( 
								'name'=>$tx_k, 
								'value'=>$tx_v,
								'is_visible' => '1',
								'is_variation' => '0',
								'is_taxonomy' => '1'
							);							
						}						
					}
					
					if(!empty($_product_attributes)){
						update_post_meta( $post_id,'_product_attributes',$_product_attributes);
					}
				}
			}

			return $return;
		}
	}

	# Wc custom order number feature toggle
	# Enabled via the Settings > Order toggle OR the legacy Compatibility toggle (backward compatible, no migration).
	public function is_wc_custom_order_number_enabled(){
		return $this->option_checked('mw_wc_xero_sync_use_wc_custom_order_number') || $this->option_checked('mw_wc_xero_sync_compt_p_wsnop');
	}

	# eBay integration order-number meta keys. eBay integrations (e.g. WP-Lister) store the eBay
	# order number on the WooCommerce order but can't be detected by a single plugin slug, so these
	# keys are read as an order-level fallback when no supported plugin provides a number.
	public function get_ebay_order_number_meta_keys(){
		return array('_ebay_order_id','_ebay_extended_order_id');
	}

	# Canonical order-number form: strips wrapping whitespace and literal quote characters (some
	# plugins store the number as e.g. '08-14968-99727', quotes included). Shared by the outbound
	# read and the reverse-lookup so the two sides can never disagree on what a stored value means.
	public function canonicalize_order_number($number){
		return is_scalar($number) ? trim((string) $number, " \t\n\r\0\x0B'\"") : '';
	}

	# Wc order number meta key
	public function get_woo_ord_number_meta_key(){
		$onk_f = '';

		if($this->is_wc_custom_order_number_enabled()){
			# Supported custom/sequential order number plugins (first active wins):
			# - WooCommerce Sequential Order Numbers Pro (woocommerce-sequential-order-numbers-pro)            -> _order_number_formatted
			# - WooCommerce Sequential Order Numbers (woocommerce-sequential-order-numbers)                    -> _order_number
			# - Sequential Order Number for WooCommerce by WebToffee (wt-woocommerce-sequential-order-numbers) -> _order_number
			# - Custom Order Numbers for WooCommerce by WPFactory (custom-order-numbers-for-woocommerce)       -> _alg_wc_full_custom_order_number
			if($this->is_plugin_active('woocommerce-sequential-order-numbers-pro')){
				$onk_f = '_order_number_formatted';
			}elseif($this->is_plugin_active('woocommerce-sequential-order-numbers')){
				$onk_f = '_order_number';
			}elseif($this->is_plugin_active('wt-woocommerce-sequential-order-numbers','wt-advanced-order-number')){
				$onk_f = '_order_number';
			}elseif($this->is_plugin_active('custom-order-numbers-for-woocommerce')){
				$onk_f = '_alg_wc_full_custom_order_number';
			}

			# Allow other custom order number plugins to be supported without a code change.
			# Only fired when the feature is opted in, so a stray filter cannot silently activate it.
			$onk_f = apply_filters('myworks_xero_sync_custom_order_number_meta_key', $onk_f);
		}

		return $onk_f;
	}

	# Wc order number
	public function get_woo_ord_number_from_order($order_id,$invoice_data=array()){
		$Number = '';
		$order_id = (int) $order_id;
		if($order_id > 0){
			$onk_f = $this->get_woo_ord_number_meta_key();
			$order = null;

			if(!empty($onk_f)){
				if($this->is_hpos_enabled()){
					$order = wc_get_order($order_id);
					$Number = $order ? $order->get_meta($onk_f, true) : '';
					# WPFactory stores the formatted number in _alg_wc_full_custom_order_number; fall back to the raw number.
					if(empty($Number) && $order && $onk_f === '_alg_wc_full_custom_order_number'){
						$Number = $order->get_meta('_alg_wc_custom_order_number', true);
					}
				}else{
					$Number = get_post_meta($order_id,$onk_f,true);
					if(empty($Number) && $onk_f === '_alg_wc_full_custom_order_number'){
						$Number = get_post_meta($order_id,'_alg_wc_custom_order_number',true);
					}
				}
			}

			# eBay integrations store the eBay order number in order meta with no detectable plugin
			# slug - when the feature is enabled and no supported plugin provided a number, fall
			# back to the eBay meta keys on the order itself.
			if(empty($Number) && $this->is_wc_custom_order_number_enabled()){
				foreach($this->get_ebay_order_number_meta_keys() as $ebay_onk){
					if($this->is_hpos_enabled()){
						if(is_null($order)){
							$order = wc_get_order($order_id);
						}
						$Number = $order ? $order->get_meta($ebay_onk, true) : '';
					}else{
						$Number = get_post_meta($order_id,$ebay_onk,true);
					}
					if(!empty($Number)){
						break;
					}
				}
			}

			$Number = $this->canonicalize_order_number($Number);
		}		
		
		return $Number;
	}
	
	# Next Xero Order Number
	public function use_next_xero_order_number(){
		return $this->option_checked('mw_wc_xero_sync_use_next_xero_order_number');
	}
	
	public function get_next_xero_order_number($order_id){
		$order_id = (int) $order_id;
		if($order_id > 0){
			if($this->is_hpos_enabled()){
				$order = wc_get_order($order_id);
				$Number = $order ? $order->get_meta('_myworks_xero_sync_order_number', true) : '';
			}else{
				$Number = get_post_meta($order_id,'_myworks_xero_sync_order_number',true);
			}
			$Number = (!empty($Number))?trim($Number):'';
			return $Number;
		}
		return '';
	}

	# Apply the configured Xero order number prefix to a document number.
	# Suppressed when "Use Next Xero Order #" is on, so the Xero-assigned number space stays prefix-free.
	# Idempotent: never double-prefixes a value that already starts with the prefix.
	public function apply_order_number_prefix($number){
		if($this->use_next_xero_order_number()){
			return $number;
		}

		$prefix = trim($this->get_option('mw_wc_xero_sync_order_doc_num_prefix'));
		if($prefix === '' || $number === '' || is_null($number)){
			return $number;
		}

		$number = (string) $number;
		if(strncmp($number, $prefix, strlen($prefix)) === 0){
			return $number;
		}

		return $prefix . $number;
	}

	# Remove a single leading occurrence of the configured prefix (for reverse Xero -> WooCommerce lookups,
	# where the WooCommerce side stores the order number without the prefix).
	public function strip_order_number_prefix($number){
		$prefix = trim($this->get_option('mw_wc_xero_sync_order_doc_num_prefix'));
		if($prefix === '' || $number === '' || is_null($number)){
			return $number;
		}

		$number = (string) $number;
		if(strncmp($number, $prefix, strlen($prefix)) === 0){
			return substr($number, strlen($prefix));
		}

		return $number;
	}

	public function get_x_f_ms_filter_datetime(){
		$datetime_m = '1969-04-05 11:23:45';
		return gmdate('Y-m-d', strtotime($datetime_m)) . 'T' . gmdate('H:i:s', strtotime($datetime_m));
	}
	
	# Check Woocommerce Order Exists By Xero Invoice Number
	public function check_if_woocommerce_order_exists_by_xero_iq_number($iq_number){
		if(!empty($iq_number)){			
			$meta_key_c_f = '';
			$lookup_keys = array();
			if($this->use_next_xero_order_number()){
				$meta_key_c_f = '_myworks_xero_sync_order_number';
			}else{
				$meta_key_c_f = $this->get_woo_ord_number_meta_key();
				# Xero returns the prefixed number; WooCommerce stores it without the prefix (and the
				# fallback path casts it to the order ID), so strip the prefix before any lookup.
				# Canonicalize the incoming number too, not just the stored side: an invoice created
				# before canonicalization existed can carry a wrapped value, and every variant and LIKE
				# below is built from this needle - a wrapped needle matches nothing and drops through to
				# the cast, where (int) ' 08-14968-99727 ' is 8. Refs #123, raised by CodeRabbit on #145.
				$iq_number = $this->canonicalize_order_number($this->strip_order_number_prefix($iq_number));

				# eBay integrations store the order number under their own meta keys with no
				# detectable plugin slug - include them so an eBay-numbered invoice matches its
				# order instead of falling through to the order-ID cast below (which would turn
				# e.g. '08-14968-99727' into order ID 8).
				if($this->is_wc_custom_order_number_enabled()){
					foreach($this->get_ebay_order_number_meta_keys() as $ebay_onk){
						if($ebay_onk !== $meta_key_c_f){
							$lookup_keys[] = $ebay_onk;
						}
					}
				}
			}

			if(!empty($meta_key_c_f)){
				array_unshift($lookup_keys, $meta_key_c_f);
			}

			# get_woo_ord_number_from_order() strips literal wrapping quotes before the number is
			# sent to Xero, so match the stored meta against the quoted variants too - otherwise a
			# quote-wrapped value could never be found and would fall through to the order-ID cast.
			$iq_variants = array((string) $iq_number, "'" . $iq_number . "'", '"' . $iq_number . '"');

			if($this->is_hpos_enabled()){
				// HPOS compatible method using WooCommerce order queries
				foreach($lookup_keys as $lookup_key){
					$lookup_key = $this->sanitize($lookup_key);
					foreach($iq_variants as $iq_variant){
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Specific meta query for Xero sync
						$orders = wc_get_orders(array(
							'meta_key' => $lookup_key,
							'meta_value' => $iq_variant,
							'limit' => 1,
							'return' => 'ids'
						));
						if(!empty($orders) && is_array($orders)){
							return (int) $orders[0];
						}
					}
				}

				# A stored value can be wrapped in any mix of quotes and whitespace (e.g. " '08-14968-99727' "),
				# which exact-variant matching can't enumerate - narrow with LIKE, then verify each candidate
				# in PHP with the same canonicalization the outbound sync uses.
				foreach($lookup_keys as $lookup_key){
					$lookup_key = $this->sanitize($lookup_key);
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Specific meta query for Xero sync
					$orders = wc_get_orders(array(
						'meta_query' => array(
							array(
								'key' => $lookup_key,
								'value' => (string) $iq_number,
								'compare' => 'LIKE'
							)
						),
						'limit' => 10,
						'return' => 'ids'
					));
					if(!empty($orders) && is_array($orders)){
						foreach($orders as $cand_id){
							$cand_order = wc_get_order($cand_id);
							if($cand_order && $this->canonicalize_order_number($cand_order->get_meta($lookup_key, true)) === (string) $iq_number){
								return (int) $cand_id;
							}
						}
					}
				}

				# ctype_digit, not just empty($meta_key_c_f): this fallback is reached exactly when no
				# meta key was resolved, which is the eBay configuration (feature on, no supported plugin).
				# Casting there is what turns '08-14968-99727' into order 8 - the failure #123 exists to
				# stop. #123 only stopped it when a matching order was found; this stops it outright.
				if(empty($meta_key_c_f) && ctype_digit((string) $iq_number)){
					$iq_number = (int) $iq_number;
					if($iq_number > 0){
						$order = wc_get_order($iq_number);
						if($order && is_object($order)){
							return $iq_number;
						}
					}
				}
			}else{
				// Legacy method using direct database queries
				global $wpdb;
				foreach($lookup_keys as $lookup_key){
					$lookup_key = $this->sanitize($lookup_key);
					// Security: Use prepared statement for both meta_key and meta_value variants
					$sql = "SELECT p.ID FROM `" . esc_sql($wpdb->posts) . "` p, `" . esc_sql($wpdb->postmeta) . "` pm WHERE pm.meta_key = %s AND pm.meta_value IN (%s,%s,%s) AND pm.post_id = p.ID AND p.post_type = 'shop_order'";
					$sql = $wpdb->prepare($sql, $lookup_key, $iq_variants[0], $iq_variants[1], $iq_variants[2]); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$wc_ord_data = $this->get_row($sql);
					if(is_array($wc_ord_data) && !empty($wc_ord_data)){
						return (int) $wc_ord_data['ID'];
					}
				}

				# Same wrapped-value fallback as the HPOS branch: narrow with LIKE, verify each
				# candidate in PHP with the shared canonicalization.
				foreach($lookup_keys as $lookup_key){
					$lookup_key = $this->sanitize($lookup_key);
					$sql = "SELECT p.ID, pm.meta_value FROM `" . esc_sql($wpdb->posts) . "` p, `" . esc_sql($wpdb->postmeta) . "` pm WHERE pm.meta_key = %s AND pm.meta_value LIKE %s AND pm.post_id = p.ID AND p.post_type = 'shop_order' LIMIT 10";
					$sql = $wpdb->prepare($sql, $lookup_key, '%' . $wpdb->esc_like((string) $iq_number) . '%'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above; table names come from $wpdb
					$wc_ord_rows = $wpdb->get_results($sql, ARRAY_A);
					if(is_array($wc_ord_rows) && !empty($wc_ord_rows)){
						foreach($wc_ord_rows as $wc_ord_row){
							if($this->canonicalize_order_number($wc_ord_row['meta_value']) === (string) $iq_number){
								return (int) $wc_ord_row['ID'];
							}
						}
					}
				}

				# ctype_digit, not just empty($meta_key_c_f): this fallback is reached exactly when no
				# meta key was resolved, which is the eBay configuration (feature on, no supported plugin).
				# Casting there is what turns '08-14968-99727' into order 8 - the failure #123 exists to
				# stop. #123 only stopped it when a matching order was found; this stops it outright.
				if(empty($meta_key_c_f) && ctype_digit((string) $iq_number)){
					$iq_number = (int) $iq_number;
					if($iq_number > 0){
						$sql = "SELECT `ID` FROM `" . esc_sql($wpdb->posts) . "` WHERE `ID` = %d AND `post_type` = 'shop_order'";
						$sql = $wpdb->prepare($sql, $iq_number); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
						$wc_ord_data = $this->get_row($sql);
						if(is_array($wc_ord_data) && !empty($wc_ord_data)){
							return (int) $wc_ord_data['ID'];
						}
					}
				}
			}
		}

		return false;
	}

	public function get_mapping_data_from_table_multiple($wc_type,$x_type,$wc_id=0,$x_id=''){
		$map_data = array();
		$wc_id = (int) $wc_id;
		$x_id = ($x_id!='')?$this->sanitize($x_id):'';
		if(!empty($wc_type) && !empty($x_type) && ($wc_id > 0 || !empty($x_id))){
			global $wpdb;
			$m_table = $this->gdtn('map_multiple');
			$ec_q = "SELECT * FROM `" . esc_sql($m_table) . "` WHERE `wc_type` = %s AND `x_type` = %s"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$ec_q = $wpdb->prepare($ec_q,$wc_type,$x_type); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if($wc_id > 0){
				$ec_q .= " AND `wc_id` = %d";
				$ec_q = $wpdb->prepare($ec_q,$wc_id); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}

			if(!empty($x_id)){
				$ec_q .= " AND `x_id` = %s";
				$ec_q = $wpdb->prepare($ec_q,$x_id); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}

			$ec_q .= " LIMIT 0,1";
						
			$map_data = $this->get_row($ec_q);
		}
		
		return $map_data;
	}

	public function get_wc_product_cat_arr(){
		$cl = get_categories(array('taxonomy'=>'product_cat','orderby'=>'name','hide_empty'=>0));
		#$this->_p($cl);
		$cl_arr = array();		
		if(!empty($cl)){
			foreach($cl as $pc){
				$cl_arr[$pc->term_id] = $pc->name;
			}
		}
		return $cl_arr;
	}

	public function get_wc_refund_ids_by_order_id($order_id){
		if($order_id > 0){
			$refund_ids = wc_get_orders(array(
				'type'        => 'shop_order_refund',
				'parent'      => $order_id,
				'return'      => 'ids', // or 'objects'
			));

			if(is_array($refund_ids) && !empty($refund_ids)){
				return $refund_ids;
			}
		}

		return [];
	}

	# Custom Fields
	public function get_wc_avl_cf_map_fields($o_a_f=false){
		$fields = [];
		$fields['wc_inv_id'] = 'WooCommerce Order ID';
		$fields['wc_inv_num'] = 'WooCommerce Order Number';
		# Formatted order addresses - populated by get_wc_order_details_from_order(), typically mapped to "Additional Line Item"
		$fields['wc_billing_address'] = 'Billing Address';
		$fields['wc_shipping_address'] = 'Shipping Address';

		return $fields;
	}

	public function get_xero_avl_cf_map_fields($o_a_f=false){
		$fields = [];
		$fields['Reference'] = 'Reference Number';
		$fields['Date'] = 'Issue Date';
		$fields['DueDate'] = 'Due Date';

		if(!$o_a_f){
			$fields['A_L_I'] = 'Additional Line Item';
			$fields['Tracking_Category'] = 'Tracking Category';
		}

		return $fields;
	}

	# Helper Functions
	public function x_account_dd_type_filter($data,$types){
		if(is_array($data) && !empty($data) && is_array($types) && !empty($types)){
			/*
			$filtered = array_filter($data, function($value) use ($types) {
				foreach ($types as $type) {
					if (strpos($value, "($type)") !== false) {
						return true;
					}
				}
				return false;
			});
			*/

			$pattern = '/\((' . implode('|', array_map('preg_quote', $types)) . ')\)$/';
			$filtered = array_filter($data, function($value) use ($pattern) {
				return preg_match($pattern, $value);
			});
			
			return $filtered;
		}

		return $data;
	}

	public function get_string_between($string, $start, $end){
		$string = ' ' . $string;
		$ini = strpos($string, $start);
		if ($ini == 0) return '';
		$ini += strlen($start);
		$len = strpos($string, $end, $ini) - $ini;
		return substr($string, $ini, $len);
	}
	
	public function get_string_after($string, $start){
		$arr = explode($start, $string);
		if(is_array($arr) && isset($arr[1])){
			return $arr[1];
		}

		return '';
	}

	public function cfm_ft_ev_pv($wcf_val,$wcfm_ext_data){
		if(!empty($wcf_val) && !empty($wcfm_ext_data)){
			$wcfm_ext_data = maybe_unserialize($wcfm_ext_data);
			if(is_array($wcfm_ext_data) && !empty($wcfm_ext_data)){
				if(isset($wcfm_ext_data['field_type']) && isset($wcfm_ext_data['ext_val'])){
					if(!empty($wcfm_ext_data['field_type']) && !empty($wcfm_ext_data['ext_val'])){
						if($wcfm_ext_data['field_type'] == 'Date'){
							$df_val = '';
							$df = $wcfm_ext_data['ext_val'];							
							
							$df = str_replace(array('dd','mm'),array('d','m'),$df);
							$yf = 'y';
							if (strpos($df, 'yyyy') !== false) {
								$yf = 'Y';
							}
							$df = str_replace(array('yyyy','yy'),$yf,$df);
							if (strpos($wcf_val, ':') !== false) {
								$df.= ' H:i';
							}
							
							$dto = DateTime::createFromFormat($df, $wcf_val);
							$df_val = ($dto)?$dto->format('Y-m-d'):'';
							
							if(!empty($df_val)){
								$wcf_val = $df_val;
							}
						}
					}
				}
			}
		}
		
		return $wcf_val;
	}

	/**
	 * Encrypt a secret value with random IV
	 *
	 * @param string $value The value to encrypt
	 * @return string The encrypted value (base64 encoded)
	 */
	public function encrypt_secret( $value ) {
		if ( empty( $value ) ) {
			return '';
		}

		// Check if openssl is available
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			// Fallback to base64 if openssl not available (not secure but better than plaintext)
			return 'b64:' . base64_encode( $value );
		}

		// Use WordPress AUTH_KEY for encryption
		$key = '';
		if ( defined( 'AUTH_KEY' ) && ! empty( AUTH_KEY ) ) {
			$key = substr( hash( 'sha256', AUTH_KEY ), 0, 32 );
		} elseif ( defined( 'SECURE_AUTH_KEY' ) && ! empty( SECURE_AUTH_KEY ) ) {
			$key = substr( hash( 'sha256', SECURE_AUTH_KEY ), 0, 32 );
		} else {
			// Fallback (should never be used in production)
			$key = substr( hash( 'sha256', 'myworks-fallback-key' ), 0, 32 );
		}

		$encryption_method = 'AES-256-CBC';
		$iv = openssl_random_pseudo_bytes( openssl_cipher_iv_length( $encryption_method ) );
		$encrypted = openssl_encrypt( $value, $encryption_method, $key, 0, $iv );

		// Return base64 encoded iv + encrypted data
		return base64_encode( $iv . $encrypted );
	}

	/**
	 * Decrypt a secret value
	 *
	 * @param string $encrypted The encrypted value
	 * @return string The decrypted value
	 */
	public function decrypt_secret( $encrypted ) {
		if ( empty( $encrypted ) ) {
			return '';
		}

		// Check for base64 fallback format
		if ( strpos( $encrypted, 'b64:' ) === 0 ) {
			return base64_decode( substr( $encrypted, 4 ) );
		}

		// Check if openssl is available
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			// If it's not base64 fallback and no openssl, return as is (plaintext migration case)
			return $encrypted;
		}

		// Check if this might be plaintext (not base64 encoded)
		if ( base64_encode( base64_decode( $encrypted, true ) ) !== $encrypted ) {
			// Looks like plaintext, return as is for backward compatibility
			return $encrypted;
		}

		// Use WordPress AUTH_KEY for decryption
		$key = '';
		if ( defined( 'AUTH_KEY' ) && ! empty( AUTH_KEY ) ) {
			$key = substr( hash( 'sha256', AUTH_KEY ), 0, 32 );
		} elseif ( defined( 'SECURE_AUTH_KEY' ) && ! empty( SECURE_AUTH_KEY ) ) {
			$key = substr( hash( 'sha256', SECURE_AUTH_KEY ), 0, 32 );
		} else {
			// Fallback (should never be used in production)
			$key = substr( hash( 'sha256', 'myworks-fallback-key' ), 0, 32 );
		}

		$encryption_method = 'AES-256-CBC';
		$data = base64_decode( $encrypted );
		$iv_length = openssl_cipher_iv_length( $encryption_method );
		$iv = substr( $data, 0, $iv_length );
		$encrypted_data = substr( $data, $iv_length );

		$decrypted = openssl_decrypt( $encrypted_data, $encryption_method, $key, 0, $iv );

		// If decryption failed, return original (might be plaintext)
		if ( false === $decrypted ) {
			return $encrypted;
		}

		return $decrypted;
	}

	##-->[End]<--##
}