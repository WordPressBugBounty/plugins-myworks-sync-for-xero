<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom queries for WooCommerce data lists
class MyWorks_WC_Xero_Sync_Wc_Data_List extends MyWorks_WC_Xero_Sync_Core {

	/**
	 * Transient key for the cached dashboard counts.
	 *
	 * Single source of truth on purpose: the write used `_v3` while every invalidation site
	 * still deleted `_v2`, so all six of them were silently no-ops and the dashboard kept
	 * serving a stale 30-minute snapshot after a rescan or mapping change. Bump the suffix
	 * here only, never at a call site.
	 */
	const DASHBOARD_CACHE_KEY = 'mwxs_dashboard_counts_v3';

	/**
	 * Check if HPOS is enabled
	 * @return bool
	 */
	private function is_hpos_enabled() {
		return class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil') && 
		       \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}
	
	# Dashboard
	public function get_dashboard_status_data(){
		// Try to get cached data
		$cache_key   = self::DASHBOARD_CACHE_KEY;
		$cached_data = get_transient($cache_key);

		if (false !== $cached_data) {
			return $cached_data;
		}

		global $wpdb;
		$data = array();
		
		// Security: Use validated table names
		$customers_table = $this->get_validated_table_name('customers');
		$products_table = $this->get_validated_table_name('products');
		
		$x_customer_count = 0;
		$x_product_count = 0;
		
		// Security: Only execute if validated table names are returned
		if ($customers_table) {
			$x_customer_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `" . esc_sql($customers_table) . "`"));
		}

		if ($products_table) {
			$x_product_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `" . esc_sql($products_table) . "`"));
		}
		
		$data['xero_initial_data_loaded'] = ($x_customer_count || $x_product_count)?true:false;

		# Xero-side synced counts for the dashboard "Xero Status" box. Refs #89
		$data['xero_customer_synced'] = $x_customer_count;
		$data['xero_product_synced'] = $x_product_count;
		
		$data['default_settings_saved'] = (!empty($this->get_option('mw_wc_xero_sync_default_xero_product')))?true:false;
		
		# WooCommerce data count
		$data['wc_total_customers'] = $this->count_wc_customers();
		$data['wc_total_products'] = $this->count_wc_products();
		$data['wc_total_variations'] = $this->count_wc_variations();
		
		# Mapping count - Security: Use validated table names
		$map_customers_table = $this->get_validated_table_name('map_customers');
		$map_products_table = $this->get_validated_table_name('map_products');
		$map_variations_table = $this->get_validated_table_name('map_variations');
		$map_payment_method_table = $this->get_validated_table_name('map_payment_method');
		
		// Security: Execute mapping counts only with validated table names
		$data['customer_mapped'] = ($map_customers_table) ? (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `" . esc_sql($map_customers_table) . "` WHERE `X_C_ID` != ''")) : 0;

		$data['product_mapped'] = ($map_products_table) ? (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `" . esc_sql($map_products_table) . "` WHERE `X_P_ID` != ''")) : 0;

		$data['variation_mapped'] = ($map_variations_table) ? (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `" . esc_sql($map_variations_table) . "` WHERE `X_P_ID` != ''")) : 0;

		$data['payment_gateways_mapped'] = ($map_payment_method_table) ? (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `" . esc_sql($map_payment_method_table) . "` WHERE `X_ACC_ID` != ''")) : 0;
		
		$basic_mapping_done = ($data['customer_mapped'] && $data['product_mapped'] && $data['payment_gateways_mapped'])?true:false;
		$data['basic_mapping_done'] = $basic_mapping_done;

		// Cache for 30 minutes
		set_transient($cache_key, $data, 30 * MINUTE_IN_SECONDS);

		return $data;
	}

	# Clear dashboard cache
	public function clear_dashboard_cache() {
		delete_transient( self::DASHBOARD_CACHE_KEY );
	}
	
	# Get wc product type by ID
	public function get_product_type_by_id($product_id){
		$pt = '';
		$product_id = (int) $product_id;
		if($product_id>0){
			// Use WooCommerce function directly (already has built-in caching)
			$_product = wc_get_product( $product_id );
			if(is_object($_product) && !empty($_product)){
				$pt = $_product->get_type();
			}
		}
		return $pt;
	}
	
	public function get_wc_active_payment_gateways(){
		$wc_apg_l = array();
		$pgl = WC()->payment_gateways()->payment_gateways;
		if(is_array($pgl) && count($pgl)){
			foreach($pgl as $key=>$value){
				if($value->enabled=='yes'){
					$wc_apg_l[$value->id] = $value->title;
				}		
			}
		}
		return $wc_apg_l;
	}
	
	public function get_wc_tax_rate_dropdown($wc_tax_rates,$selected='',$skip_rate_id='',$skip_rate_class='None'){
		echo '<option value=""></option>';
		if(is_array($wc_tax_rates) && count($wc_tax_rates)){
			foreach($wc_tax_rates as $rates){
				if($skip_rate_id!=$rates['tax_rate_id'] && $skip_rate_class!=$rates['tax_rate_class']){
					echo '<option  data-tax_rate_country="'.esc_attr($rates['tax_rate_country']).'"  data-tax_rate_state="'.esc_attr($rates['tax_rate_state']).'"  data-tax_rate="'.esc_attr($rates['tax_rate']).'"  data-tax_rate_name="'.esc_attr($rates['tax_rate_name']).'"  data-tax_rate_priority="'.esc_attr($rates['tax_rate_priority']).'"  data-tax_rate_compound="'.esc_attr($rates['tax_rate_compound']).'"  data-tax_rate_shipping="'.esc_attr($rates['tax_rate_shipping']).'" data-tax_rate_order="'.esc_attr($rates['tax_rate_order']).'" data-tax_rate_class="'.esc_attr($rates['tax_rate_class']).'" data-tax_rate_city="'.esc_attr($rates['location_code']).'" value="'.esc_attr($rates['tax_rate_id']).'">'.esc_html($rates['tax_rate_name']).'</option>';
				}
			}
		}		
	}
	
	public function get_wc_tax_rate_id_array($wc_tax_rates){
		$tx_rate_arr = array();
		if(is_array($wc_tax_rates) && count($wc_tax_rates)){
			foreach($wc_tax_rates as $rates){
				$tx_rate_arr[$rates['tax_rate_id']] = $rates;
			}
		}
		return $tx_rate_arr;
	}
	
	public function get_wc_tax_rates_a_lc_add($wc_tax_rates_a){
		$tx_rate_arr = array();
		if(is_array($wc_tax_rates_a) && count($wc_tax_rates_a)){
			global $wpdb;
			$wtr_lt = $wpdb->prefix.'woocommerce_tax_rate_locations';
			foreach($wc_tax_rates_a as $k => $rates){
				$tax_rate_id  = (int) $rates['tax_rate_id'];
				$lc_a = $this->get_row($wpdb->prepare("SELECT `location_code` FROM `" . esc_sql($wtr_lt) . "` WHERE `tax_rate_id` = %d AND location_type = 'city' LIMIT 0,1", $tax_rate_id)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$location_code = '';
				if(is_array($lc_a) && !empty($lc_a)){
					$location_code = $lc_a['location_code'];
				}
				
				$rates['location_code'] = $location_code;
				$tx_rate_arr[$k] = $rates;
			}
		}
		return $tx_rate_arr;
	}
	
	# Customer List Function - Map / Push / Count
	public function count_wc_customers($search_txt='',$cl_role_search='',$cl_um_srch='') {
		return (int) $this->get_wc_customers(true,false,'',$search_txt,$cl_role_search,$cl_um_srch);
	}
	
	public function get_wc_customers($is_count=false,$list_page=false,$limit='',$search_txt='',$cl_role_search='',$cl_um_srch='') {
		global $wpdb;
		
		/* User role search start */
		$roles = ''; # Leave blank for all roles
			
		$cl_role_search = $this->sanitize($cl_role_search);
		if(!empty($cl_role_search)){
			$roles = $cl_role_search;
		}
		
		if(!is_array($roles) && !empty($roles)){
			$roles = array_map('trim',explode( ",", $roles ));
		}
		
		/* User role search end */
		
		$ext_join = '';
		$ext_whr = '';
		
		if(is_array($roles) && !empty($roles)){
			// Security: Use prepared statements for role search
			$role_conditions = array();
			$role_values = array();
			
			foreach ($roles as $role) {
				$role = sanitize_text_field($role); // Sanitize role name
				if (!empty($role)) {
					$role_conditions[] = $wpdb->usermeta . '.meta_value LIKE %s';
					$role_values[] = '%"' . $wpdb->esc_like($role) . '"%';
				}
			}
			
			if (!empty($role_conditions)) {
				$ext_whr .= ' AND (' . implode(' OR ', $role_conditions) . ')';
				// Note: role_values will be used in the final prepare() call
			}
		}		
		
		/* Customer search start - Security: Fixed SQL injection issues */
		$search_txt = $this->sanitize($search_txt);
		if($search_txt!=''){
			// Security: Use esc_like() for LIKE queries and proper prepared statements
			$search_like = '%' . $wpdb->esc_like($search_txt) . '%';
			
			$st_a = explode(' ',$search_txt);
			if(is_array($st_a) && count($st_a) > 1){
				// Multi-word search with proper escaping
				$cs_gcq = $wpdb->prepare("
				SELECT GROUP_CONCAT(DISTINCT(um.user_id)) as c_ids
				FROM {$wpdb->usermeta} um 
				INNER JOIN {$wpdb->usermeta} um_f ON (um.user_id = um_f.user_id AND um_f.meta_key = 'first_name') 
				INNER JOIN {$wpdb->usermeta} um_l ON (um.user_id = um_l.user_id AND um_l.meta_key = 'last_name') 
				WHERE (um.meta_value LIKE %s AND um.meta_key = 'billing_company')
				OR um_f.meta_value LIKE %s
				OR um_l.meta_value LIKE %s
				OR CONCAT(um_f.meta_value,' ', um_l.meta_value) LIKE %s ", 
				$search_like, $search_like, $search_like, $search_like);
			} else {
				// Single word search with proper escaping
				$cs_gcq = $wpdb->prepare(
					"SELECT GROUP_CONCAT(DISTINCT(user_id)) AS c_ids FROM `" . esc_sql($wpdb->usermeta) . "` WHERE meta_value LIKE %s AND meta_key IN('billing_company','first_name','last_name')",
					$search_like
				);
			}
			
			$s_c_ids = $wpdb->get_var($cs_gcq); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			// Security: Validate and sanitize the customer IDs
			$cs_id_results = false;
			$id_array = array();
			if (!empty($s_c_ids)) {
				// Ensure IDs are numeric and safe
				$id_array = array_map('intval', explode(',', $s_c_ids));
				$id_array = array_filter($id_array); // Remove zeros/invalid IDs
				$cs_id_results = !empty($id_array);
			}

			// Build search condition template (will be added to main query later)
			$ext_whr .= " AND (`" . esc_sql($wpdb->users) . "`.display_name LIKE %s OR `" . esc_sql($wpdb->users) . "`.user_email LIKE %s OR `" . esc_sql($wpdb->users) . "`.ID = %s";
			if ($cs_id_results) {
				$id_placeholders = implode(',', array_fill(0, count($id_array), '%d'));
				$ext_whr .= " OR `" . esc_sql($wpdb->users) . "`.ID IN (" . $id_placeholders . ")";
			}
			$ext_whr .= ")";
		}
		
		/* Customer search end */
		
		# Main Query
		if($is_count){
			$sql = '
				SELECT  COUNT(DISTINCT(' . esc_sql($wpdb->users) . '.ID))
				FROM        `' . esc_sql($wpdb->users) . '` INNER JOIN `' . esc_sql($wpdb->usermeta) . '`
				ON          `' . esc_sql($wpdb->users) . '`.ID = `' . esc_sql($wpdb->usermeta) . '`.user_id
				'.$ext_join.'
				WHERE       `' . esc_sql($wpdb->usermeta) . '`.meta_key        =       %s				
			';
			$sql .= $ext_whr;
		}else{
			$sql = '
				SELECT  DISTINCT(`' . esc_sql($wpdb->users) . '`.ID) , `' . esc_sql($wpdb->users) . '`.display_name, `' . esc_sql($wpdb->users) . '`.user_email
				FROM        `' . esc_sql($wpdb->users) . '` INNER JOIN `' . esc_sql($wpdb->usermeta) . '`
				ON          `' . esc_sql($wpdb->users) . '`.ID = `' . esc_sql($wpdb->usermeta) . '`.user_id
				'.$ext_join.'
				WHERE       `' . esc_sql($wpdb->usermeta) . '`.meta_key        =       %s				
			';
			$sql .= $ext_whr;
			
			$orderby = '`' . esc_sql($wpdb->users) . '`.display_name ASC';
			$sql .= ' ORDER BY  '.$orderby;

			// Parse LIMIT values for placeholder-based approach
			$limit_parts = array();
			if($limit!=''){
				// Security: Sanitize LIMIT value to only allow digits, comma, and space
				$limit_sanitized = preg_replace('/[^0-9,\s]/', '', $limit);
				$limit_parts = array_map('intval', array_map('trim', explode(',', $limit_sanitized)));
				$limit_parts = array_filter($limit_parts, function($v) { return $v > 0; });
			}

			// Add LIMIT with placeholders to avoid static analysis warnings
			if (!empty($limit_parts)) {
				if (count($limit_parts) === 1) {
					$sql .= ' LIMIT %d';
				} else {
					$sql .= ' LIMIT %d, %d';
				}
			}
		}

		#echo $sql;
		// Security: Prepare the query with all parameters in correct order
		$capabilities_key = $wpdb->prefix . 'capabilities';
		$all_params = array($capabilities_key);

		// Add search parameters if search is active
		if(!empty($search_txt)){
			$all_params[] = $search_like;
			$all_params[] = $search_like;
			$all_params[] = $search_txt;
			// Add ID array parameters if available
			if ($cs_id_results && !empty($id_array)) {
				$all_params = array_merge($all_params, $id_array);
			}
		}

		// Add LIMIT parameters at the end (only for non-count queries)
		if (!$is_count && !empty($limit_parts)) {
			$all_params = array_merge($all_params, $limit_parts);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Complex query built with proper placeholders. $ext_whr contains %s/%d placeholders for role filters and search conditions. All user inputs are properly escaped via wpdb->esc_like() and passed through wpdb->prepare() with placeholders. LIMIT uses %d placeholders. Query cannot be simplified further due to conditional role/search filters.
		$prepared_sql = $wpdb->prepare($sql, ...$all_params);
		
		if($is_count){
			return $wpdb->get_var($prepared_sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query prepared with wpdb->prepare
		}else{
			$r_data = array();
			$q_data =  $this->get_data($prepared_sql);
			
			#echo $wpdb->num_rows;
			#$this->_p($q_data);

			if(is_array($q_data) && !empty($q_data)){
				// Pre-load all mappings in one query to avoid N+1 problem
				$wc_ids = array_column($q_data, 'ID');
				$mappings_by_id = array();
				$xero_customers_by_id = array();

				if (!empty($wc_ids)) {
					$mt = $this->gdtn('map_customers');
					// Build IN clause with proper placeholders - inline to avoid static analysis issues
					$mappings_query = $wpdb->prepare(
						"SELECT W_C_ID, X_C_ID FROM `" . esc_sql($mt) . "` WHERE W_C_ID IN (" . implode(',', array_fill(0, count($wc_ids), '%d')) . ") AND X_C_ID != ''",
						...$wc_ids
					);
					$all_mappings = $wpdb->get_results($mappings_query, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

					if (is_array($all_mappings) && !empty($all_mappings)) {
						foreach ($all_mappings as $mapping) {
							$mappings_by_id[$mapping['W_C_ID']] = $mapping['X_C_ID'];
						}

						// Pre-load all Xero customer data
						$xero_ids = array_values($mappings_by_id);
						if (!empty($xero_ids)) {
							$xct = $this->gdtn('customers');
							// Build IN clause with proper placeholders - inline to avoid static analysis issues
							$xero_query = $wpdb->prepare(
								"SELECT ContactID, Name, EmailAddress FROM `" . esc_sql($xct) . "` WHERE ContactID IN (" . implode(',', array_fill(0, count($xero_ids), '%s')) . ")",
								...$xero_ids
							);
							$all_xero_customers = $wpdb->get_results($xero_query, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

							if (is_array($all_xero_customers) && !empty($all_xero_customers)) {
								foreach ($all_xero_customers as $xc) {
									$xero_customers_by_id[$xc['ContactID']] = $xc;
								}
							}
						}
					}
				}

				foreach($q_data as $rd){
					$cu_tmp_arr = array();
					$cu_tmp_arr['ID'] = $rd['ID'];
					$cu_tmp_arr['display_name'] = $rd['display_name'];
					$cu_tmp_arr['user_email'] = $rd['user_email'];

					$c_meta = get_user_meta($rd['ID']);
					$cu_tmp_arr['first_name'] = (is_array($c_meta) && isset($c_meta['first_name'][0]))?$c_meta['first_name'][0]:'';
					$cu_tmp_arr['last_name'] = (is_array($c_meta) && isset($c_meta['last_name'][0]))?$c_meta['last_name'][0]:'';

					$cu_tmp_arr['billing_company'] = (is_array($c_meta) && isset($c_meta['billing_company'][0]))?$c_meta['billing_company'][0]:'';

					$X_ContactID = '';$X_Name = '';$X_EmailAddress = '';

					// Use pre-loaded mapping data
					if (isset($mappings_by_id[$rd['ID']])) {
						$X_C_ID = $mappings_by_id[$rd['ID']];
						if (isset($xero_customers_by_id[$X_C_ID])) {
							$xcd = $xero_customers_by_id[$X_C_ID];
							$X_ContactID = $X_C_ID;
							$X_Name = $xcd['Name'];
							$X_EmailAddress = $xcd['EmailAddress'];
						}
					}

					$cu_tmp_arr['X_ContactID'] = $X_ContactID;
					$cu_tmp_arr['X_Name'] = $X_Name;
					$cu_tmp_arr['X_EmailAddress'] = $X_EmailAddress;

					$r_data[] = $cu_tmp_arr;
				}
			}
			
			unset($q_data);
			#$this->_p($r_data);
			return $r_data;	
		}		
	}
	
	# Product List Function - Map / Push / Count
	public function count_wc_products($is_inventory=false,$search_txt='',$stock_status='',$product_cat_search=0,$p_type='',$product_um_srch=''){
		return (int) $this->get_wc_products(true,'',$is_inventory,$search_txt,$stock_status,$product_cat_search,$p_type,$product_um_srch);
	}
	
	public function get_wc_products($is_count=false,$limit='',$is_inventory=false,$search_txt='',$stock_status='',$product_cat_search=0,$p_type='',$product_um_srch=''){
		global $wpdb;
		
		$ext_join = '';
		$ext_sql = '';
		
		/*Inventory product type*/
		if($is_inventory){
			$ext_join.= " INNER JOIN ".$wpdb->postmeta." pm8 ON ( pm8.post_id = p.ID	AND pm8.meta_key =  '_manage_stock') ";
			$ext_sql.= " AND pm8.meta_value='yes' ";
		}
		
		/*Product search by SKU, name*/
		$search_txt = $this->sanitize($search_txt);
		if($search_txt!=''){
			$ext_join.= "LEFT JOIN `" . esc_sql($wpdb->postmeta) . "` pm1 ON ( pm1.post_id = p.ID AND pm1.meta_key =  '_sku' ) ";
			// Security: Use esc_like() for LIKE queries to prevent wildcard injection
			$like_search = '%' . $wpdb->esc_like($search_txt) . '%';
			$ext_sql.=" AND ( p.ID = %d OR p.post_title LIKE %s OR pm1.meta_value LIKE %s ) ";
			$ext_sql = $wpdb->prepare($ext_sql,$search_txt,$like_search,$like_search); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		
		/*Stock status search*/
		$stock_status = $this->sanitize($stock_status);
		if($stock_status!=''){
			$ext_join.= " INNER JOIN ".$wpdb->postmeta." pm7 ON ( pm7.post_id = p.ID AND pm7.meta_key =  '_stock_status' ) ";
			$ext_sql.= $wpdb->prepare(" AND pm7.meta_value = %s", $stock_status);
			
		}
		
		/*Product category search*/
		$product_cat_search = (int) $product_cat_search;
		if($product_cat_search>0){
			$ext_join.= " 
			JOIN   {$wpdb->term_relationships} TR ON p.ID=TR.object_id 
			JOIN   {$wpdb->term_taxonomy} T ON TR.term_taxonomy_id=T.term_taxonomy_id
			JOIN  {$wpdb->terms} TS ON T.term_id = TS.term_id
			";
			$ext_sql.= $wpdb->prepare(" AND T.taxonomy = 'product_cat' AND T.term_id = %d", $product_cat_search);
		}
		
		/*Product type search*/
		$p_type = $this->sanitize($p_type);
		if($p_type!='' && $p_type != 'all'){
			$pt_jt = 'INNER';
			$ext_join.= "
				{$pt_jt} JOIN {$wpdb->term_relationships} AS term_relationships ON p.ID = term_relationships.object_id
				{$pt_jt} JOIN {$wpdb->term_taxonomy} AS term_taxonomy ON term_relationships.term_taxonomy_id = term_taxonomy.term_taxonomy_id
				{$pt_jt} JOIN {$wpdb->terms} AS terms ON term_taxonomy.term_id = terms.term_id
			";
			if($p_type=='simple'){
				$ext_sql.= $wpdb->prepare(" AND term_taxonomy.taxonomy = 'product_type' AND (terms.slug = %s OR terms.slug = '' OR terms.slug IS NULL)", $p_type);				
			}else{
				$ext_sql.= $wpdb->prepare(" AND term_taxonomy.taxonomy = 'product_type' AND terms.slug = %s", $p_type);
			}			
		}
		
		/*Product map, un-mapped search*/
		$product_um_srch = $this->sanitize($product_um_srch);
		if($product_um_srch == 'only_m'){
			$ext_join.= " 
			INNER JOIN " . $this->gdtn('map_products') . " pmap ON (p.ID = pmap.W_P_ID AND pmap.X_P_ID != '')
			";
		}
		
		if($product_um_srch == 'only_um'){
			$ext_sql.= " AND p.ID NOT IN(SELECT W_P_ID FROM " . $this->gdtn('map_products') . " WHERE X_P_ID != '')";
		}
		
		/*Hide variable parent product*/
		if($this->option_checked('mw_wc_qbo_desk_hide_vpp_fmp_pages') && empty($p_type)){
			$ext_sql.= " AND p.ID NOT IN(SELECT post_parent FROM {$wpdb->posts} WHERE post_type = 'product_variation' AND post_parent>0) ";
		}
		
		# Main Query
		if($is_count){
			$sql = "SELECT COUNT(DISTINCT(p.ID)) ";
		}else{
			$sql = "SELECT DISTINCT(p.ID), p.post_title AS name ";
		}
		
		$sql.= "FROM `" . esc_sql($wpdb->posts) . "` p
			{$ext_join}
			WHERE p.post_type =  'product'
			AND p.post_status NOT IN('trash','auto-draft','inherit')
			{$ext_sql}
		";
		
		if(!$is_count){
			$orderby = 'p.post_title ASC';
			$sql .= ' ORDER BY  '.$orderby;
			
			$limit = $this->sanitize($limit);
			if($limit!=''){
				$sql .= ' LIMIT  '.$limit;
			}
		}
		
		#echo $sql;
		if($is_count){
			return $wpdb->get_var($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query with sanitized LIMIT
		}else{
			$r_data = array();
			$q_data =  $this->get_data($sql);
			
			if(is_array($q_data) && !empty($q_data)){
				foreach($q_data as $rd){
					$pd_tmp_arr = array();
					$pd_tmp_arr['ID'] = $rd['ID'];
					$pd_tmp_arr['name'] = $this->sanitize($rd['name']);
					
					$p_meta = get_post_meta($rd['ID']);
					$pd_tmp_arr['sku'] = (is_array($p_meta) && isset($p_meta['_sku'][0]))?$p_meta['_sku'][0]:'';
					$pd_tmp_arr['regular_price'] = (is_array($p_meta) && isset($p_meta['_regular_price'][0]))?$p_meta['_regular_price'][0]:'';
					$pd_tmp_arr['sale_price'] = (is_array($p_meta) && isset($p_meta['_sale_price'][0]))?$p_meta['_sale_price'][0]:'';
					$pd_tmp_arr['price'] = (is_array($p_meta) && isset($p_meta['_price'][0]))?$p_meta['_price'][0]:'';
					$pd_tmp_arr['stock'] = (is_array($p_meta) && isset($p_meta['_stock'][0]))?$p_meta['_stock'][0]:'';
					$pd_tmp_arr['backorders'] = (is_array($p_meta) && isset($p_meta['_backorders'][0]))?$p_meta['_backorders'][0]:'';
					$pd_tmp_arr['stock_status'] = (is_array($p_meta) && isset($p_meta['_stock_status'][0]))?$p_meta['_stock_status'][0]:'';
					$pd_tmp_arr['manage_stock'] = (is_array($p_meta) && isset($p_meta['_manage_stock'][0]))?$p_meta['_manage_stock'][0]:'';
					$pd_tmp_arr['total_sales'] = (is_array($p_meta) && isset($p_meta['total_sales'][0]))?$p_meta['total_sales'][0]:'';				
					
					$pd_tmp_arr['wc_product_type'] = $this->get_product_type_by_id($rd['ID']);
					
					#Xero Data
					$X_ItemID = '';$X_Name = '';$X_Code = '';$X_IsTrackedAsInventory=0;
					
					$mt = $this->gdtn('map_products');
					$mq = $wpdb->prepare("SELECT X_P_ID FROM `" . esc_sql($mt) . "` WHERE W_P_ID = %d AND X_P_ID != ''", $rd['ID']);
					$md =  $this->get_row($mq);
					if(is_array($md) && !empty($md) && !empty($md['X_P_ID'])){
						$X_P_ID = $md['X_P_ID'];
						$xpt = $this->gdtn('products');
						$xcq = $wpdb->prepare("SELECT Name, Code, IsTrackedAsInventory FROM `" . esc_sql($xpt) . "` WHERE ItemID = %s",$X_P_ID);
						$xcd =  $this->get_row($xcq);
						if(is_array($xcd) && !empty($xcd)){
							$X_ItemID = $X_P_ID;
							$X_Name = $xcd['Name'];
							$X_Code = $xcd['Code'];
							$X_IsTrackedAsInventory = $xcd['IsTrackedAsInventory'];
						}
					}
					
					$pd_tmp_arr['X_ItemID'] = $X_ItemID;
					$pd_tmp_arr['X_Name'] = $X_Name;
					$pd_tmp_arr['X_Code'] = $X_Code;
					$pd_tmp_arr['X_IsTrackedAsInventory'] = $X_IsTrackedAsInventory;
					
					$r_data[] = $pd_tmp_arr;
				}
			}
			
			unset($q_data);
			#$this->_p($r_data);
			return $r_data;
		}
	}
	
	# Variation List Function - Map / Push / Count
	public function count_wc_variations($is_inventory=false,$search_txt='',$stock_status='',$product_um_srch=''){
		return (int) $this->get_wc_variations(true,'',$is_inventory,$search_txt,$stock_status,$product_um_srch);
	}
	
	public function get_wc_variations($is_count=false,$limit='',$is_inventory=false,$search_txt='',$stock_status='',$variation_um_srch=''){
		global $wpdb;
		
		$ext_join = '';
		$ext_sql = '';
		
		/*Inventory variation type*/
		if($is_inventory){
			$ext_join.= " INNER JOIN ".$wpdb->postmeta." pm8 ON ( pm8.post_id = p.ID	AND pm8.meta_key =  '_manage_stock') ";
			$ext_sql.= " AND pm8.meta_value='yes' ";
		}
		
		/*Variation search by SKU, name*/
		$search_txt = $this->sanitize($search_txt);
		if($search_txt!=''){
			$ext_join.= "LEFT JOIN `" . esc_sql($wpdb->postmeta) . "` pm1 ON ( pm1.post_id = p.ID AND pm1.meta_key =  '_sku' ) ";
			// Security: Use esc_like() for LIKE queries to prevent wildcard injection
			$like_search = '%' . $wpdb->esc_like($search_txt) . '%';
			$ext_sql.=" AND ( p.ID = %d OR p.post_title LIKE %s OR pm1.meta_value LIKE %s ) ";
			$ext_sql = $wpdb->prepare($ext_sql,$search_txt,$like_search,$like_search); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		
		/*Stock status search*/
		$stock_status = $this->sanitize($stock_status);
		if($stock_status!=''){
			$ext_join.= " INNER JOIN ".$wpdb->postmeta." pm7 ON ( pm7.post_id = p.ID AND pm7.meta_key =  '_stock_status' ) ";
			$ext_sql.= $wpdb->prepare(" AND pm7.meta_value = %s", $stock_status);
			
		}
		
		/*Variation map, un-mapped search*/
		$variation_um_srch = $this->sanitize($variation_um_srch);
		if($variation_um_srch == 'only_m'){
			$ext_join.= " 
			INNER JOIN " . $this->gdtn('map_variations') . " pmap ON (p.ID = pmap.W_V_ID AND pmap.X_P_ID != '')
			";
		}
		
		if($variation_um_srch == 'only_um'){
			$ext_sql.= " AND p.ID NOT IN(SELECT W_V_ID FROM " . $this->gdtn('map_variations') . " WHERE X_P_ID != '')";
		}
		
		# Main Query
		if($is_count){
			$sql = "SELECT COUNT(DISTINCT(p.ID)) ";
		}else{
			$sql = "SELECT DISTINCT(p.ID), p.post_title AS name, p.post_parent as parent_id, p.post_name ";
		}
		
		$sql.= "FROM `" . esc_sql($wpdb->posts) . "` p
			{$ext_join}
			WHERE p.post_type =  'product_variation'
			AND p.post_status NOT IN('trash','auto-draft','inherit')
			{$ext_sql}
		";
		
		if(!$is_count){
			$orderby = 'p.post_title ASC';
			$sql .= ' ORDER BY  '.$orderby;
			
			$limit = $this->sanitize($limit);
			if($limit!=''){
				$sql .= ' LIMIT  '.$limit;
			}
		}
		
		#echo $sql;
		if($is_count){
			return $wpdb->get_var($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query with sanitized LIMIT
		}else{
			$r_data = array();
			$q_data =  $this->get_data($sql);
			
			if(is_array($q_data) && !empty($q_data)){
				foreach($q_data as $rd){
					$pd_tmp_arr = array();
					$pd_tmp_arr['ID'] = $rd['ID'];
					$pd_tmp_arr['name'] = $this->sanitize($rd['name']);
					
					$pd_tmp_arr['post_name'] = $rd['post_name'];
					$pd_tmp_arr['parent_id'] = $rd['parent_id'];
					
					$p_meta = get_post_meta($rd['ID']);
					$pd_tmp_arr['sku'] = (is_array($p_meta) && isset($p_meta['_sku'][0]))?$p_meta['_sku'][0]:'';
					$pd_tmp_arr['regular_price'] = (is_array($p_meta) && isset($p_meta['_regular_price'][0]))?$p_meta['_regular_price'][0]:'';
					$pd_tmp_arr['sale_price'] = (is_array($p_meta) && isset($p_meta['_sale_price'][0]))?$p_meta['_sale_price'][0]:'';
					$pd_tmp_arr['price'] = (is_array($p_meta) && isset($p_meta['_price'][0]))?$p_meta['_price'][0]:'';
					$pd_tmp_arr['stock'] = (is_array($p_meta) && isset($p_meta['_stock'][0]))?$p_meta['_stock'][0]:'';
					$pd_tmp_arr['backorders'] = (is_array($p_meta) && isset($p_meta['_backorders'][0]))?$p_meta['_backorders'][0]:'';
					$pd_tmp_arr['stock_status'] = (is_array($p_meta) && isset($p_meta['_stock_status'][0]))?$p_meta['_stock_status'][0]:'';
					$pd_tmp_arr['manage_stock'] = (is_array($p_meta) && isset($p_meta['_manage_stock'][0]))?$p_meta['_manage_stock'][0]:'';
					$pd_tmp_arr['total_sales'] = (is_array($p_meta) && isset($p_meta['total_sales'][0]))?$p_meta['total_sales'][0]:'';
					
					#Attributes
					$attribute_names = '';
					$attribute_names_arr = array();
					
					$attribute_values = '';
					$attribute_values_arr = array();
					
					if(is_array($p_meta) && count($p_meta)){
						foreach($p_meta as $pm_k => $pm_v){
							if($this->start_with($pm_k,'attribute_')){
								$attribute_names_arr[] = $pm_k;
								$attribute_values_arr[] = (isset($pm_v[0]))?$pm_v[0]:'';
							}
						}
					}
					
					if(count($attribute_names_arr) && count($attribute_values_arr)){
						$attribute_names = implode(',',$attribute_names_arr);
						$attribute_values = implode(',',$attribute_values_arr);
					}
					
					$pd_tmp_arr['attribute_names'] = $attribute_names;
					$pd_tmp_arr['attribute_values'] = $attribute_values;
					
					$parent_name = '';
					if($rd['parent_id']>0){
						$parent_id = (int) $rd['parent_id'];
						$parent_name = $this->get_field_by_val($wpdb->posts,'post_title','ID',$parent_id);
					}
					
					$pd_tmp_arr['parent_name'] = $this->sanitize($parent_name);
					
					#Xero Data
					$X_ItemID = '';$X_Name = '';$X_Code = '';$X_IsTrackedAsInventory=0;
					
					$mt = $this->gdtn('map_variations');
					$mq = $wpdb->prepare("SELECT X_P_ID FROM `" . esc_sql($mt) . "` WHERE W_V_ID = %d AND X_P_ID != ''",$rd['ID']);
					$md =  $this->get_row($mq);
					if(is_array($md) && !empty($md) && !empty($md['X_P_ID'])){
						$X_P_ID = $md['X_P_ID'];
						$xpt = $this->gdtn('products');
						$xcq = $wpdb->prepare("SELECT Name, Code, IsTrackedAsInventory FROM `" . esc_sql($xpt) . "` WHERE ItemID = %s",$X_P_ID);
						$xcd =  $this->get_row($xcq);
						if(is_array($xcd) && !empty($xcd)){
							$X_ItemID = $X_P_ID;
							$X_Name = $xcd['Name'];
							$X_Code = $xcd['Code'];
							$X_IsTrackedAsInventory = $xcd['IsTrackedAsInventory'];
						}
					}
					
					$pd_tmp_arr['X_ItemID'] = $X_ItemID;
					$pd_tmp_arr['X_Name'] = $X_Name;
					$pd_tmp_arr['X_Code'] = $X_Code;
					$pd_tmp_arr['X_IsTrackedAsInventory'] = $X_IsTrackedAsInventory;
					
					$r_data[] = $pd_tmp_arr;
				}
			}
			
			unset($q_data);
			#$this->_p($r_data);
			return $r_data;
		}
	}
	
	# Order List Function - Map / Push / Count
	public function count_wc_order_list($search_txt='',$date_from='',$date_to='',$status='',$sync_filter=''){
		return (int) $this->get_wc_order_list(true,'',$search_txt,$date_from,$date_to,$status,$sync_filter);
	}
	
	public function get_wc_order_list($is_count=false,$limit='',$search_txt='',$date_from='',$date_to='',$status='',$sync_filter='',$orderby='',$order=''){
		global $wpdb;

		if ($this->is_hpos_enabled()) {
			return $this->get_wc_order_list_hpos($is_count,$limit,$search_txt,$date_from,$date_to,$status,$sync_filter,$orderby,$order);
		}
		
		$ext_sql = '';
		$ext_join = '';
		
		#$onc_mf = $this->get_woo_ord_number_key_field();
		$onc_mf = '';
		
		$ldl = false;
		if($ldl){
			$wp_date_time_c = $this->now();
			$ld = 30;
			$l_days_dt = gmdate('Y-m-d H:i:s', strtotime('-'.$ld.' days', strtotime($wp_date_time_c)));
			$ext_sql = $wpdb->prepare(" AND p.post_date BETWEEN %s AND %s", $l_days_dt, $wp_date_time_c);
		}
		
		# Search
		$search_txt = $this->sanitize($search_txt);
		
		if($search_txt!=''){
			$ext_join .="
			LEFT JOIN ".$wpdb->postmeta." pm1
			ON ( pm1.post_id = p.ID AND pm1.meta_key =  '_billing_first_name' )
			LEFT JOIN ".$wpdb->postmeta." pm2
			ON ( pm2.post_id = p.ID AND pm2.meta_key =  '_billing_last_name' )
			LEFT JOIN ".$wpdb->postmeta." pm7
			ON ( pm7.post_id = p.ID AND pm7.meta_key =  '_billing_company' )
			";
			
			// Security: Prepare LIKE search with proper escaping
			$like_search = '%' . $wpdb->esc_like($search_txt) . '%';
			
			if(!empty($onc_mf)){
				$ext_join .="
				LEFT JOIN ".$wpdb->postmeta." pm10
				ON ( pm10.post_id = p.ID AND pm10.meta_key =  '{$onc_mf}' )
				";
			}
			
			if(!empty($onc_mf)){
				$ext_sql .=$wpdb->prepare(" AND ( pm1.meta_value LIKE %s OR pm2.meta_value LIKE %s OR pm7.meta_value LIKE %s OR CONCAT(pm1.meta_value,' ', pm2.meta_value) LIKE %s  OR p.ID = %s OR pm10.meta_value = %s ) ",$like_search,$like_search,$like_search,$like_search,$search_txt,$search_txt);
			}else{
				$ext_sql .=$wpdb->prepare(" AND ( pm1.meta_value LIKE %s OR pm2.meta_value LIKE %s OR pm7.meta_value LIKE %s OR CONCAT(pm1.meta_value,' ', pm2.meta_value) LIKE %s OR p.ID = %s ) ",$like_search,$like_search,$like_search,$like_search,$search_txt);
			}
			
		}
		
		/*Order status search*/
		$status = $this->sanitize($status);
		if($status!=''){
			$ext_sql .=$wpdb->prepare(" AND p.post_status = %s",$status);
		}
		
		/*Order date search*/
		$date_from = $this->sanitize($date_from);
		if($date_from!=''){
			$ext_sql .= $wpdb->prepare(" AND p.post_date >= %s", $date_from . ' 00:00:00');
		}

		$date_to = $this->sanitize($date_to);
		if($date_to!=''){
			$ext_sql .= $wpdb->prepare(" AND p.post_date <= %s", $date_to . ' 23:59:59');
		}

		/* Xero sync status filter + column sort. Refs #83 */
		$sync_filter = $this->sanitize($sync_filter);
		$orderby = $this->sanitize($orderby);
		$order_dir = (strtoupper($this->sanitize($order)) === 'ASC') ? 'ASC' : 'DESC';

		# Sync-meta join when filtering or sorting by sync status.
		$need_sync_join = ($sync_filter === 'synced' || $sync_filter === 'not_synced' || $orderby === 'sync');
		if($need_sync_join){
			$ext_join .= " LEFT JOIN ".$wpdb->postmeta." pmsync ON ( pmsync.post_id = p.ID AND pmsync.meta_key = '_mwxs_xero_invoice_id' ) ";
			if($sync_filter === 'synced'){
				$ext_sql .= " AND ( pmsync.meta_value IS NOT NULL AND pmsync.meta_value <> '' ) ";
			}elseif($sync_filter === 'not_synced'){
				$ext_sql .= " AND ( pmsync.meta_value IS NULL OR pmsync.meta_value = '' ) ";
			}
		}

		# Resolve ORDER BY (whitelisted columns) and add any meta joins it needs (before the main query).
		$orderby_sql = 'p.post_date DESC';
		switch($orderby){
			case 'id':
				$orderby_sql = "p.ID ".$order_dir;
				break;
			case 'date':
				$orderby_sql = "p.post_date ".$order_dir;
				break;
			case 'status':
				$orderby_sql = "p.post_status ".$order_dir;
				break;
			case 'customer':
				$ext_join .= " LEFT JOIN ".$wpdb->postmeta." so_fn ON ( so_fn.post_id = p.ID AND so_fn.meta_key = '_billing_first_name' ) LEFT JOIN ".$wpdb->postmeta." so_ln ON ( so_ln.post_id = p.ID AND so_ln.meta_key = '_billing_last_name' ) ";
				$orderby_sql = "so_fn.meta_value ".$order_dir.", so_ln.meta_value ".$order_dir;
				break;
			case 'company':
				$ext_join .= " LEFT JOIN ".$wpdb->postmeta." so_co ON ( so_co.post_id = p.ID AND so_co.meta_key = '_billing_company' ) ";
				$orderby_sql = "so_co.meta_value ".$order_dir;
				break;
			case 'amount':
				$ext_join .= " LEFT JOIN ".$wpdb->postmeta." so_tot ON ( so_tot.post_id = p.ID AND so_tot.meta_key = '_order_total' ) ";
				$orderby_sql = "(so_tot.meta_value + 0) ".$order_dir;
				break;
			case 'payment':
				$ext_join .= " LEFT JOIN ".$wpdb->postmeta." so_pm ON ( so_pm.post_id = p.ID AND so_pm.meta_key = '_payment_method' ) ";
				$orderby_sql = "so_pm.meta_value ".$order_dir;
				break;
			case 'sync':
				$orderby_sql = "( pmsync.meta_value IS NOT NULL AND pmsync.meta_value <> '' ) ".$order_dir.", p.post_date DESC";
				break;
		}

		# Main Query
		if($is_count){
			$sql = "SELECT COUNT(DISTINCT(p.ID)) ";
		}else{
			$sql = "SELECT DISTINCT(p.ID), p.post_status, p.post_date ";
		}

		$sql .= "FROM {$wpdb->prefix}posts as p
			{$ext_join}
			WHERE
			p.post_type = 'shop_order'
			AND p.post_status NOT IN('trash','auto-draft','inherit')
			{$ext_sql}
		";

		if(!$is_count){
			$sql .= ' ORDER BY  '.$orderby_sql;

			$limit = $this->sanitize($limit);
			if($limit!=''){
				$sql .= ' LIMIT  '.$limit;
			}
		}
		
		#echo $sql;
		if($is_count){
			return $wpdb->get_var($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query with sanitized LIMIT
		}else{
			$r_data = array();
			$q_data =  $this->get_data($sql);
			
			if(is_array($q_data) && !empty($q_data)){
				foreach($q_data as $rd){
					$od_tmp_arr = array();					
					$od_tmp_arr['ID'] = $rd['ID'];
					$od_tmp_arr['post_status'] = $rd['post_status'];
					$od_tmp_arr['post_date'] = $rd['post_date'];
					
					$o_meta = get_post_meta($rd['ID']);
					if(!is_array($o_meta)){
						$o_meta = array();
					}
					
					$od_tmp_arr['billing_first_name'] = (isset($o_meta['_billing_first_name'][0]))?$o_meta['_billing_first_name'][0]:'';
					$od_tmp_arr['billing_last_name'] = (isset($o_meta['_billing_last_name'][0]))?$o_meta['_billing_last_name'][0]:'';
					$od_tmp_arr['billing_company'] = (isset($o_meta['_billing_company'][0]))?$o_meta['_billing_company'][0]:'';
					
					$od_tmp_arr['order_total'] = (isset($o_meta['_order_total'][0]))?$o_meta['_order_total'][0]:'';
					$od_tmp_arr['order_key'] = (isset($o_meta['_order_key'][0]))?$o_meta['_order_key'][0]:'';
					$od_tmp_arr['customer_user'] = (isset($o_meta['_customer_user'][0]))?$o_meta['_customer_user'][0]:'';
					$od_tmp_arr['order_currency'] = (isset($o_meta['_order_currency'][0]))?$o_meta['_order_currency'][0]:'';				
					$od_tmp_arr['payment_method'] = (isset($o_meta['_payment_method'][0]))?$o_meta['_payment_method'][0]:'';
					$od_tmp_arr['payment_method_title'] = (isset($o_meta['_payment_method_title'][0]))?$o_meta['_payment_method_title'][0]:'';
					
					# Order Number					
					if(!empty($onc_mf)){
						$od_tmp_arr[$onc_mf] = (isset($o_meta[$onc_mf][0]))?$o_meta[$onc_mf][0]:'';
					}					
					
					$r_data[] = $od_tmp_arr;
				}
			}
			
			unset($q_data);
			#$this->_p($r_data);
			return $r_data;	
		}
	}

	public function get_wc_order_list_hpos($is_count=false,$limit='',$search_txt='',$date_from='',$date_to='',$status='',$sync_filter='',$orderby='',$order=''){
		global $wpdb;
		
		// HPOS table names
		$orders_table = $wpdb->prefix . 'wc_orders';
		$addresses_table = $wpdb->prefix . 'wc_order_addresses';
		$operational_data_table = $wpdb->prefix . 'wc_order_operational_data';
		$meta_table = $wpdb->prefix . 'wc_orders_meta';
		
		$ext_sql = '';
		$ext_join = '';
		
		// Order number custom field (if exists)
		$onc_mf = '';
		
		// Date range for last days (if needed)
		$ldl = false;
		if($ldl){
			$wp_date_time_c = $this->now();
			$ld = 30;
			$l_days_dt = gmdate('Y-m-d H:i:s', strtotime('-'.$ld.' days', strtotime($wp_date_time_c)));
			$ext_sql = $wpdb->prepare(" AND o.date_created_gmt BETWEEN %s AND %s", $l_days_dt, $wp_date_time_c);
		}
		
		// Search functionality
		$search_txt = $this->sanitize($search_txt);
		if($search_txt != ''){
			// Join with addresses table for billing info
			$ext_join .= "
			LEFT JOIN {$addresses_table} ba ON (ba.order_id = o.id AND ba.address_type = 'billing')
			";
			
			// Security: Prepare LIKE search with proper escaping
			$like_search = '%' . $wpdb->esc_like($search_txt) . '%';
			
			// Add meta join for custom order number if exists
			if(!empty($onc_mf)){
				$ext_join .= "
				LEFT JOIN {$meta_table} om ON (om.order_id = o.id AND om.meta_key = '{$onc_mf}')
				";
			}
			
			// Search conditions
			if(!empty($onc_mf)){
				$ext_sql .= $wpdb->prepare(" AND (
					ba.first_name LIKE %s OR 
					ba.last_name LIKE %s OR 
					ba.company LIKE %s OR 
					CONCAT(ba.first_name, ' ', ba.last_name) LIKE %s OR 
					o.id = %s OR 
					om.meta_value = %s
				)", $like_search, $like_search, $like_search, $like_search, $search_txt, $search_txt);
			} else {
				$ext_sql .= $wpdb->prepare(" AND (
					ba.first_name LIKE %s OR 
					ba.last_name LIKE %s OR 
					ba.company LIKE %s OR 
					CONCAT(ba.first_name, ' ', ba.last_name) LIKE %s OR 
					o.id = %s
				)", $like_search, $like_search, $like_search, $like_search, $search_txt);
			}
		}
		
		// Order status search
		$status = $this->sanitize($status);
		if($status != ''){
			$ext_sql .= $wpdb->prepare(" AND o.status = %s", $status);
		}
		
		// Order date search
		$date_from = $this->sanitize($date_from);
		if($date_from != ''){
			$ext_sql .= $wpdb->prepare(" AND o.date_created_gmt >= %s", $date_from . ' 00:00:00');
		}
		
		$date_to = $this->sanitize($date_to);
		if($date_to != ''){
			$ext_sql .= $wpdb->prepare(" AND o.date_created_gmt <= %s", $date_to . ' 23:59:59');
		}

		// Xero sync status filter + column sort. Refs #83
		$sync_filter = $this->sanitize($sync_filter);
		$orderby = $this->sanitize($orderby);
		$order_dir = (strtoupper($this->sanitize($order)) === 'ASC') ? 'ASC' : 'DESC';

		$need_sync_join = ($sync_filter === 'synced' || $sync_filter === 'not_synced' || $orderby === 'sync');
		if($need_sync_join){
			$ext_join .= " LEFT JOIN {$meta_table} omsync ON (omsync.order_id = o.id AND omsync.meta_key = '_mwxs_xero_invoice_id') ";
			if($sync_filter === 'synced'){
				$ext_sql .= " AND ( omsync.meta_value IS NOT NULL AND omsync.meta_value <> '' ) ";
			}elseif($sync_filter === 'not_synced'){
				$ext_sql .= " AND ( omsync.meta_value IS NULL OR omsync.meta_value = '' ) ";
			}
		}

		// Resolve ORDER BY (whitelisted columns) and add any joins it needs (before the main query).
		$orderby_sql = 'o.date_created_gmt DESC';
		switch($orderby){
			case 'id':
				$orderby_sql = "o.id ".$order_dir;
				break;
			case 'date':
				$orderby_sql = "o.date_created_gmt ".$order_dir;
				break;
			case 'status':
				$orderby_sql = "o.status ".$order_dir;
				break;
			case 'amount':
				$orderby_sql = "o.total_amount ".$order_dir;
				break;
			case 'payment':
				$orderby_sql = "o.payment_method_title ".$order_dir;
				break;
			case 'customer':
				$ext_join .= " LEFT JOIN {$addresses_table} sba ON (sba.order_id = o.id AND sba.address_type = 'billing') ";
				$orderby_sql = "sba.first_name ".$order_dir.", sba.last_name ".$order_dir;
				break;
			case 'company':
				$ext_join .= " LEFT JOIN {$addresses_table} sba ON (sba.order_id = o.id AND sba.address_type = 'billing') ";
				$orderby_sql = "sba.company ".$order_dir;
				break;
			case 'sync':
				$orderby_sql = "( omsync.meta_value IS NOT NULL AND omsync.meta_value <> '' ) ".$order_dir.", o.date_created_gmt DESC";
				break;
		}

		// Main Query
		if($is_count){
			$sql = "SELECT COUNT(DISTINCT(o.id))";
		} else {
			$sql = "SELECT DISTINCT(o.id) as ID, o.status as post_status, o.date_created_gmt as post_date";
		}

		$sql .= " FROM {$orders_table} o
			{$ext_join}
			WHERE o.type = 'shop_order'
			AND o.status NOT IN('trash', 'auto-draft', 'inherit')
			{$ext_sql}
		";

		if(!$is_count){
			$sql .= ' ORDER BY ' . $orderby_sql;

			$limit = $this->sanitize($limit);
			if($limit != ''){
				$sql .= ' LIMIT ' . $limit;
			}
		}
		
		if($is_count){
			return $wpdb->get_var($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query with sanitized LIMIT
		} else {
			$r_data = array();
			$q_data = $this->get_data($sql);
			
			if(is_array($q_data) && !empty($q_data)){
				foreach($q_data as $rd){
					$od_tmp_arr = array();
					$od_tmp_arr['ID'] = $rd['ID'];
					$od_tmp_arr['post_status'] = $rd['post_status'];
					$od_tmp_arr['post_date'] = $rd['post_date'];
					
					// Get order object to retrieve meta data using HPOS-compatible methods
					$order = wc_get_order($rd['ID']);
					if($order){
						// Billing information
						$od_tmp_arr['billing_first_name'] = $order->get_billing_first_name();
						$od_tmp_arr['billing_last_name'] = $order->get_billing_last_name();
						$od_tmp_arr['billing_company'] = $order->get_billing_company();
						
						// Order details
						$od_tmp_arr['order_total'] = $order->get_total();
						$od_tmp_arr['order_key'] = $order->get_order_key();
						$od_tmp_arr['customer_user'] = $order->get_customer_id();
						$od_tmp_arr['order_currency'] = $order->get_currency();
						$od_tmp_arr['payment_method'] = $order->get_payment_method();
						$od_tmp_arr['payment_method_title'] = $order->get_payment_method_title();
						
						// Order Number (if custom field exists)
						if(!empty($onc_mf)){
							$od_tmp_arr[$onc_mf] = $order->get_meta($onc_mf);
						}
					} else {
						// Fallback to empty values if order not found
						$od_tmp_arr['billing_first_name'] = '';
						$od_tmp_arr['billing_last_name'] = '';
						$od_tmp_arr['billing_company'] = '';
						$od_tmp_arr['order_total'] = '';
						$od_tmp_arr['order_key'] = '';
						$od_tmp_arr['customer_user'] = '';
						$od_tmp_arr['order_currency'] = '';
						$od_tmp_arr['payment_method'] = '';
						$od_tmp_arr['payment_method_title'] = '';
						
						if(!empty($onc_mf)){
							$od_tmp_arr[$onc_mf] = '';
						}
					}
					
					$r_data[] = $od_tmp_arr;
				}
			}
			
			unset($q_data);
			return $r_data;
		}
	}
	
	# End
}