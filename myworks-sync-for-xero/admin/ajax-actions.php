<?php
if ( ! defined( 'ABSPATH' ) )
exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// Reason: MWXS is the established plugin prefix (short form of MyWorks Xero Sync)
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
// Reason: AJAX handlers require direct queries for queue and sync operations

# License
function myworks_wc_xero_sync_check_license(){
	if ( ! empty( $_POST ) && check_admin_referer( 'myworks_wc_xero_sync_check_license', 'check_plugin_license' ) ) {
		// process form data
		global $MWXS_L;
		
		$mw_wc_xero_sync_localkey = get_option('mw_wc_xero_localkey','');
		$mw_wc_xero_sync_localkey = $MWXS_L->sanitize($mw_wc_xero_sync_localkey);
		
		$mw_wc_xero_sync_license =  $MWXS_L->var_p('mw_wc_xero_license');	
		
		if($mw_wc_xero_sync_license!=$MWXS_L->get_option('mw_wc_xero_license')){
			#$MWXS_L->initialize_session();
			#$MWXS_L->set_session_val('new_license_check',1);
		}		
		
		if($MWXS_L->is_valid_license($mw_wc_xero_sync_license,$mw_wc_xero_sync_localkey,true)){
			echo 'License Activated';

			// Track Mixpanel event for license activation
			MyWorks_Mixpanel_Integration::track_event( 'Plugin: Activated' );
		}else{
			echo 'Invalid License key';
		}		
	}
	wp_die();
}

function myworks_wc_xero_sync_del_license_local_key(){
	if ( ! empty( $_POST ) && check_admin_referer( 'myworks_wc_xero_sync_del_license_local_key', 'del_license_local_key' ) ) {
		delete_option('mw_wc_xero_localkey');
		echo 'Success';
	}	
	wp_die();
}

# Dashboard Graph
function myworks_wc_xero_sync_refresh_log_chart(){
	// Verify nonce
	if ( ! empty( $_POST ) && check_admin_referer( 'myworks_wc_xero_sync_refresh_log_chart', 'refresh_log_chart_nonce' ) ) {
		// Check user capability
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized access', 'myworks-sync-for-xero' ) );
		}

		global $MWXS_L;
		$vp = $MWXS_L->var_p('period');

		// Validate period parameter (whitelist allowed values)
		$allowed_periods = array( 'today', 'month', 'year' );
		if ( ! in_array( $vp, $allowed_periods, true ) ) {
			$vp = 'month'; // default fallback
		}

		$MWXS_L->initialize_session();
		$MWXS_L->set_session_val('dashboard_graph_period',$vp);
		require_once('admin-page-hcj-functions.php');
		myworks_woo_sync_for_xero_get_log_chart_output($vp);
	}
	wp_die();
}

# Connection Key
function myworks_wc_xero_sync_save_xero_c_key(){
	if ( ! empty( $_POST ) && check_admin_referer( 'myworks_wc_xero_sync_save_xero_c_key', 'save_xero_c_key' ) ) {
		global $MWXS_L;		
		$f_xc_key = $MWXS_L->var_p('f_xc_key');
		
		if(!empty($f_xc_key)){			
			if(strlen($f_xc_key) == '35' && $MWXS_L->validate_connection_key($f_xc_key)){
				$MWXS_L->update_option('mw_wc_xero_f_xc_key',$f_xc_key);
				echo '<br><span style="color:green;">Connection key saved</span>';
			}			
		}
	}
	wp_die();
}

# Quick Refresh
function myworks_wc_xero_sync_quick_refresh_cp(){
	if ( ! empty( $_POST ) && check_admin_referer( 'myworks_wc_xero_sync_quick_refresh_cp', 'quick_refresh_cp' ) ) {
		global $MWXS_L;
		
		if(!$MWXS_L->is_xero_connected()){$MWXS_L->xero_connect();}
		
		if($MWXS_L->is_xero_connected()){
			$tci = (int) $MWXS_L->xero_refresh_customers();
			$tpi = (int) $MWXS_L->xero_refresh_products();
			
			# Clear Invalid Mappings
			if($tci > 0){
				$MWXS_L->clear_customer_invalid_mappings();
			}
			
			if($tpi > 0){
				$MWXS_L->clear_product_invalid_mappings();
				$MWXS_L->clear_variation_invalid_mappings();
			}			
			
			// Security: Use WordPress escaping functions directly
			echo '<br>Total Customer Imported: <b>' . esc_html($tci) . '</b><br>';
			echo 'Total Product Imported: <b>' . esc_html($tpi) . '</b>';			
			
		}else{
			echo '<br><span style="color:red;">Xero connection problem</span>';
		}
	}
	wp_die();
}

function myworks_wc_xero_sync_quick_refresh_customers(){
	if ( ! empty( $_POST ) && check_admin_referer( 'myworks_wc_xero_sync_quick_refresh_customers', 'quick_refresh_customers' ) ) {
		global $MWXS_L;
		
		if(!$MWXS_L->is_xero_connected()){$MWXS_L->xero_connect();}
		
		if($MWXS_L->is_xero_connected()){
			$tci = (int) $MWXS_L->xero_refresh_customers();
			
			# Clear Invalid Mappings
			if($tci > 0){
				$MWXS_L->clear_customer_invalid_mappings();
			}
			
			echo 'Total Customer Imported: <b>'.esc_html($tci).'</b>';
			
		}else{
			echo '<font style="color:red;">Xero connection problem</font>';
		}
	}
	wp_die();
}

function myworks_wc_xero_sync_quick_refresh_products(){
	if ( ! empty( $_POST ) && check_admin_referer( 'myworks_wc_xero_sync_quick_refresh_products', 'quick_refresh_products' ) ) {
		global $MWXS_L;
		
		if(!$MWXS_L->is_xero_connected()){$MWXS_L->xero_connect();}
		
		if($MWXS_L->is_xero_connected()){			
			$tpi = (int) $MWXS_L->xero_refresh_products();
			
			# Clear Invalid Mappings
			if($tpi > 0){
				$MWXS_L->clear_product_invalid_mappings();
				$MWXS_L->clear_variation_invalid_mappings();
			}
			
			echo 'Total Product Imported: <b>'.esc_html($tpi).'</b>';
			
		}else{
			echo '<font style="color:red;">Xero connection problem</font>';
		}
	}
	wp_die();
}

# Clear Mappings
function myworks_wc_xero_sync_clear_all_mappings(){
	if ( ! empty( $_POST ) && check_admin_referer( 'myworks_wc_xero_sync_clear_all_mappings', 'clear_all_mappings' ) ) {
		global $MWXS_L;
		global $wpdb;		
		
		// Security: Use prepared statements and validate table names
		$table_customers = $MWXS_L->get_validated_table_name('map_customers');
		$table_products = $MWXS_L->get_validated_table_name('map_products'); 
		$table_payment_method = $MWXS_L->get_validated_table_name('map_payment_method');
		$table_tax = $MWXS_L->get_validated_table_name('map_tax');
		
		// Clear customers mapping
		if ($table_customers) {
			$wpdb->query($wpdb->prepare("DELETE FROM `" . esc_sql($table_customers) . "` WHERE `id` > %d", 0)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query($wpdb->prepare("TRUNCATE TABLE `" . esc_sql($table_customers) . "`"));
		}
		
		// Clear products mapping  
		if ($table_products) {
			$wpdb->query($wpdb->prepare("DELETE FROM `" . esc_sql($table_products) . "` WHERE `id` > %d", 0)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query($wpdb->prepare("TRUNCATE TABLE `" . esc_sql($table_products) . "`"));
		}
		
		// Clear payment method mapping
		if ($table_payment_method) {
			$wpdb->query($wpdb->prepare("DELETE FROM `" . esc_sql($table_payment_method) . "` WHERE `id` > %d", 0)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query($wpdb->prepare("TRUNCATE TABLE `" . esc_sql($table_payment_method) . "`"));
		}
		
		// Clear tax mapping
		if ($table_tax) {
			$wpdb->query($wpdb->prepare("DELETE FROM `" . esc_sql($table_tax) . "` WHERE `id` > %d", 0)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query($wpdb->prepare("TRUNCATE TABLE `" . esc_sql($table_tax) . "`"));
		}
		
		// Clear multiple mapping
		$table_multiple = $MWXS_L->get_validated_table_name('map_multiple');
		if ($table_multiple) {
			$wpdb->query($wpdb->prepare("DELETE FROM `" . esc_sql($table_multiple) . "` WHERE `id` > %d", 0)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query($wpdb->prepare("TRUNCATE TABLE `" . esc_sql($table_multiple) . "`"));
		}
		
		echo 'Success';
	}
	wp_die();
}

function myworks_wc_xero_sync_clear_customer_mappings(){
	if ( ! empty( $_POST ) && check_admin_referer( 'myworks_wc_xero_sync_clear_customer_mappings', 'clear_customer_mappings' ) ) {
		global $MWXS_L;
		global $wpdb;
		
		// Security: Use validated table name
		$table = $MWXS_L->get_validated_table_name('map_customers');
		
		if ($table) {
			$wpdb->query($wpdb->prepare("DELETE FROM `" . esc_sql($table) . "` WHERE `id` > %d", 0)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query($wpdb->prepare("TRUNCATE TABLE `" . esc_sql($table) . "`"));
			echo 'Success';
		} else {
			echo 'Error: Invalid table access';
		}
	}
	wp_die();
}

function myworks_wc_xero_sync_clear_product_mappings(){
	if ( ! empty( $_POST ) && check_admin_referer( 'myworks_wc_xero_sync_clear_product_mappings', 'clear_product_mappings' ) ) {
		global $MWXS_L;
		global $wpdb;
		
		// Security: Use validated table names
		$table_products = $MWXS_L->get_validated_table_name('map_products');
		$table_multiple = $MWXS_L->get_validated_table_name('map_multiple');
		
		if ($table_products && $table_multiple) {
			// Clear products mapping
			$wpdb->query($wpdb->prepare("DELETE FROM `" . esc_sql($table_products) . "` WHERE `id` > %d", 0)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query($wpdb->prepare("TRUNCATE TABLE `" . esc_sql($table_products) . "`"));

			// Clear account mappings for products
			$wpdb->query($wpdb->prepare("DELETE FROM `" . esc_sql($table_multiple) . "` WHERE `wc_type` = %s AND `x_type` = %s", 'product', 'account')); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			
			echo 'Success';
		} else {
			echo 'Error: Invalid table access';
		}
	}
	wp_die();
}

function myworks_wc_xero_sync_clear_variation_mappings(){
	if ( ! empty( $_POST ) && check_admin_referer( 'myworks_wc_xero_sync_clear_variation_mappings', 'clear_variation_mappings' ) ) {
		global $MWXS_L;
		global $wpdb;
		
		// Security: Use validated table names
		$table_variations = $MWXS_L->get_validated_table_name('map_variations');
		$table_multiple = $MWXS_L->get_validated_table_name('map_multiple');
		
		if ($table_variations && $table_multiple) {
			// Clear variations mapping
			$wpdb->query($wpdb->prepare("DELETE FROM `" . esc_sql($table_variations) . "` WHERE `id` > %d", 0)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query($wpdb->prepare("TRUNCATE TABLE `" . esc_sql($table_variations) . "`"));
			
			// Clear account mappings for variations
			$wpdb->query($wpdb->prepare("DELETE FROM `" . esc_sql($table_multiple) . "` WHERE `wc_type` = %s AND `x_type` = %s", 'variation', 'account')); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			echo 'Success';
		} else {
			echo 'Error: Invalid table access';
		}
	}
	wp_die();
}

# Clear Logs
function myworks_wc_xero_sync_clear_all_logs(){
	if ( ! empty( $_POST ) && check_admin_referer( 'myworks_wc_xero_sync_clear_all_logs', 'clear_all_logs' ) ) {
		global $MWXS_L;
		global $wpdb;

		// Security: Use validated table name
		$table = $MWXS_L->get_validated_table_name('log');
		
		if ($table) {
			$wpdb->query($wpdb->prepare("DELETE FROM `" . esc_sql($table) . "` WHERE `id` > %d", 0)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query($wpdb->prepare("TRUNCATE TABLE `" . esc_sql($table) . "`"));
			echo 'Success';
		} else {
			echo 'Error: Invalid table access';
		}
	}	
	wp_die();
}

function myworks_wc_xero_sync_clear_all_log_errors(){
	if ( ! empty( $_POST ) && check_admin_referer( 'myworks_wc_xero_sync_clear_all_log_errors', 'clear_all_log_errors' ) ) {
		global $MWXS_L;
		global $wpdb;
		
		// Security: Use validated table name
		$table = $MWXS_L->get_validated_table_name('log');
		
		if ($table) {
			$wpdb->query($wpdb->prepare("DELETE FROM `" . esc_sql($table) . "` WHERE `status` = %d", 0)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			echo 'Success';
		} else {
			echo 'Error: Invalid table access';
		}
	}	
	wp_die();
}

# Clear Queue
function myworks_wc_xero_sync_clear_all_pending_queues(){
	if ( ! empty( $_POST ) && check_admin_referer( 'myworks_wc_xero_sync_clear_all_pending_queues', 'clear_all_pending_queues' ) ) {
		global $MWXS_L;
		global $wpdb;
		
		// Security: Use validated table name
		$table = $MWXS_L->get_validated_table_name('queue');
		
		if ($table) {
			$wpdb->query($wpdb->prepare("DELETE FROM `" . esc_sql($table) . "` WHERE `run` = %d", 0)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			echo 'Success';
		} else {
			echo 'Error: Invalid table access';
		}
	}	
	wp_die();
}

function myworks_wc_xero_sync_clear_all_queues(){
	if ( ! empty( $_POST ) && check_admin_referer( 'myworks_wc_xero_sync_clear_all_queues', 'clear_all_queues' ) ) {
		global $MWXS_L;
		global $wpdb;
		
		// Security: Use validated table name
		$table = $MWXS_L->get_validated_table_name('queue');
		
		if ($table) {
			$wpdb->query($wpdb->prepare("DELETE FROM `" . esc_sql($table) . "` WHERE `id` > %d", 0)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query($wpdb->prepare("TRUNCATE TABLE `" . esc_sql($table) . "`"));
			echo 'Success';
		} else {
			echo 'Error: Invalid table access';
		}
	}	
	wp_die();
}

# Auto Map
function myworks_wc_xero_sync_automap_customers_wf_xf(){
	if ( ! empty( $_POST ) && check_admin_referer( 'myworks_wc_xero_sync_automap_customers_wf_xf', 'automap_customers_wf_xf' ) ) {
		global $MWXS_L;
		
		$cam_wf = $MWXS_L->var_p('cam_wf');		
		$cam_qf = $MWXS_L->var_p('cam_qf');		
		
		$mo_um = false;
		if(isset($_POST['mo_um']) && $_POST['mo_um'] == 'true'){
			$mo_um = true;
		}
		
		$map_count = (int) $MWXS_L->AutoMapCustomers($cam_wf,$cam_qf,$mo_um);
		
		echo 'Total Customer Mapped: '.esc_html($map_count);
	}
	wp_die();
}

function myworks_wc_xero_sync_automap_products_wf_xf(){
	if ( ! empty( $_POST ) && check_admin_referer( 'myworks_wc_xero_sync_automap_products_wf_xf', 'automap_products_wf_xf' ) ) {
		global $MWXS_L;		
		
		$pam_wf = $MWXS_L->var_p('pam_wf');		
		$pam_qf = $MWXS_L->var_p('pam_qf');
		
		$mo_um = false;
		if(isset($_POST['mo_um']) && $_POST['mo_um'] == 'true'){
			$mo_um = true;
		}
		
		$map_count = (int) $MWXS_L->AutoMapProducts($pam_wf,$pam_qf,$mo_um);
		
		echo 'Total Product Mapped: '.esc_html($map_count);
	}	
	wp_die();
}

function myworks_wc_xero_sync_automap_variations_wf_xf(){
	if ( ! empty( $_POST ) && check_admin_referer( 'myworks_wc_xero_sync_automap_variations_wf_xf', 'automap_variations_wf_xf' ) ) {
		global $MWXS_L;		
		
		$vam_wf = $MWXS_L->var_p('vam_wf');		
		$vam_qf = $MWXS_L->var_p('vam_qf');
		
		$mo_um = false;
		if(isset($_POST['mo_um']) && $_POST['mo_um'] == 'true'){
			$mo_um = true;
		}
		
		$map_count = (int) $MWXS_L->AutoMapVariations($vam_wf,$vam_qf,$mo_um);
		
		echo 'Total Variation Mapped: '.esc_html($map_count);
	}	
	wp_die();
}

function myworks_wc_xero_sync_window(){
	if ( ! empty( $_POST ) && check_admin_referer( 'myworks_wc_xero_sync_window', 'window_xero_sync' ) ) {
		global $MWXS_L;
		global $MWXS_A;

		# A nonce proves intent, not authorization: this handler pushes orders, customers, products
		# and variations to Xero and pulls from it, so it needs the capability check itself rather
		# than relying on the admin-menu gate that rendered the nonce. See docs/rules/security.md.
		# Added with #146 (CodeRabbit, PR #147) because the order-push branch was touched.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Unauthorized access', 'myworks-sync-for-xero' ) ) );
		}
		
		$sync_type = $MWXS_L->var_p('sync_type');
		$item_type = $MWXS_L->var_p('item_type');
		if($sync_type == 'pull'){
			$id = $MWXS_L->var_p('id');
		}else{
			$id = (int) $MWXS_L->var_p('id');
		}
		
		$cur_item = (int) $MWXS_L->var_p('cur_item');
		$tot_item = (int ) $MWXS_L->var_p('tot_item');
		
		$check_sync_valid = true;
		
		if($sync_type!='push' && $sync_type!='pull'){
			$check_sync_valid = false;
		}
		
		if($item_type!='customer' && $item_type!='order' && $item_type!='product' && $item_type!='variation'){
			$check_sync_valid = false;
		}
		
		if(($sync_type != 'pull' && $id < 1) || ($sync_type == 'pull' && empty($id)) || !$cur_item || !$tot_item){
			$check_sync_valid = false;
		}
		
		if($check_sync_valid){
			// Track Mixpanel event for Push Started (only on first item)
			if ( $sync_type == 'push' && $cur_item == 1 ) {
				MyWorks_Mixpanel_Integration::track_event(
					'Push: Started',
					array(
						'push_type' => $item_type,
						'total_items' => $tot_item,
					)
				);
			}

			try{
				$key =  $cur_item;
				$per = $key/$tot_item*100;
				$per = ceil($per);
				$msg = "<span class='error_red'>Something went wrong.</span>";
				$sync_status = 0;

				if($sync_type=='push'){
					if($item_type=='customer'){				
						$r = $MWXS_A->hook_user_register(array('user_id'=>$id,'f_p_p'=>true));
						if($r){
							$msg = "<span class='success_green'>Customer #".esc_html($id)." has been pushed into Xero</span>";
						}else{
							$msg = "<span class='error_red'>There was an error pushing customer #".esc_html($id)." , Check MyWorks Sync > Log for additional details.</span>";
						}						
					}
					
					if($item_type=='product'){						
						$r = $MWXS_A->hook_product_add(array('product_id'=>$id,'f_p_p'=>true));
						if(is_array($r)){
							$is_update = (isset($r['update']) && $r['update'] === 1)?true:false;
							$r_id = '';
							if(isset($r['sync_status']) && $r['sync_status'] === 1){
								$sync_status = 1;
								$r_id = (isset($r['x_id']))?$r['x_id']:'';
							}

							if($sync_status === 1){
								if($is_update){
									$msg = "<span class='success_green'>Product #".esc_html($id)." has been updated into Xero</span>";
								}else{
									$msg = "<span class='success_green'>Product #".esc_html($id)." has been pushed into Xero</span>";
								}
							}else{
								if($is_update){
									if(isset($r['msgs']) && !empty($r['msgs'])){
										$msg = "<span class='error_red'>There was an error updating product #".esc_html($id)." - ".esc_html($r['msgs'][0])."</span>";
									}else{
										$msg = "<span class='error_red'>There was an error updating product #".esc_html($id)." , Check MyWorks Sync > Log for additional details.</span>";
									}
								}else{
									if(isset($r['msgs']) && !empty($r['msgs'])){
										$msg = "<span class='error_red'>There was an error pushing product #".esc_html($id)." - ".esc_html($r['msgs'][0])."</span>";
									}else{
										$msg = "<span class='error_red'>There was an error pushing product #".esc_html($id)." , Check MyWorks Sync > Log for additional details.</span>";
									}
								}								
							}
						}else{
							if($r){
								$msg = "<span class='success_green'>Product #".esc_html($id)." has been pushed into Xero</span>";
							}else{
								$msg = "<span class='error_red'>There was an error pushing product #".esc_html($id)." , Check MyWorks Sync > Log for additional details.</span>";
							}
						}												
					}
					
					if($item_type=='variation'){
						$r = $MWXS_A->hook_variation_add(array('variation_id'=>$id,'f_p_p'=>true));
						if(is_array($r)){
							$is_update = (isset($r['update']) && $r['update'] === 1)?true:false;
							$r_id = '';
							if(isset($r['sync_status']) && $r['sync_status'] === 1){
								$sync_status = 1;
								$r_id = (isset($r['x_id']))?$r['x_id']:'';
							}

							if($sync_status === 1){
								if($is_update){
									$msg = "<span class='success_green'>Variation #".esc_html($id)." has been updated into Xero</span>";
								}else{
									$msg = "<span class='success_green'>Variation #".esc_html($id)." has been pushed into Xero</span>";
								}
							}else{
								if($is_update){
									if(isset($r['msgs']) && !empty($r['msgs'])){
										$msg = "<span class='error_red'>There was an error updating variation #".esc_html($id)." - ".esc_html($r['msgs'][0])."</span>";
									}else{
										$msg = "<span class='error_red'>There was an error updating variation #".esc_html($id)." , Check MyWorks Sync > Log for additional details.</span>";
									}
								}else{
									if(isset($r['msgs']) && !empty($r['msgs'])){
										$msg = "<span class='error_red'>There was an error pushing variation #".esc_html($id)." - ".esc_html($r['msgs'][0])."</span>";
									}else{
										$msg = "<span class='error_red'>There was an error pushing variation #".esc_html($id)." , Check MyWorks Sync > Log for additional details.</span>";
									}
								}								
							}
						}else{
							if($r){
								$msg = "<span class='success_green'>Variation #".esc_html($id)." has been pushed into Xero</span>";
							}else{
								$msg = "<span class='error_red'>There was an error pushing variation #".esc_html($id)." , Check MyWorks Sync > Log for additional details.</span>";
							}
						}												
					}
					
					if($item_type=='order'){
						# Per-order lock shared with the queue consumer, so a manual push cannot create an
						# invoice alongside a cron run that is pushing the same order right now. Refs #146
						$order_lock = $MWXS_L->acquire_order_push_lock($id);
						if($order_lock === ''){
							$MWXS_L->save_log(array('type'=>'Order','title'=>'Create Order Skipped #'.$id,'details'=>'This order is already being synced by another process (usually the queue), so the manual push was not started. Check the log again in a moment before pushing it again.','status'=>2,'wc_id'=>$id));
							$msg = "<span class='error_red'>Order #".esc_html($id)." is already being synced by another process. Check MyWorks Sync > Log in a moment.</span>";
						}else{
							try{
								$r = $MWXS_A->hook_order_add(array('order_id'=>$id,'f_p_p'=>true));
							}finally{
								$MWXS_L->release_order_push_lock($id, $order_lock);
							}

							if($r){
								$msg = "<span class='success_green'>Order #".esc_html($id)." has been pushed into Xero</span>";
							}else{
								$msg = "<span class='error_red'>There was an error pushing order #".esc_html($id)." , Check MyWorks Sync > Log for additional details.</span>";
							}
						}
					}
				}
				
				if($sync_type=='pull'){
					$MWXS_L->xero_connect();
					if($item_type=='product'){						
						$r = $MWXS_L->X_Pull_Product_By_Id($id);
						if($r){
							$msg = "<span class='success_green'>Product #".esc_html($id)." has been pulled into WooCommerce</span>";
						}else{
							$msg = "<span class='error_red'>There was an error pulling product #".esc_html($id)." , Check MyWorks Sync > Log for additional details.</span>";
						}
					}
				}
				
				$MWXS_L->show_sync_window_message($key, $msg , $per, $tot_item);
				
			}catch (Exception $e) {
				$Exception = $e->getMessage();
				$MWXS_L->save_log(array('type'=>ucfirst($item_type),'title'=>'Push '.ucfirst($item_type).' Error #'.$id,'details'=>'Exception: '.$Exception,'status'=>0));
			}
		}
	}
	wp_die();
}

function myworks_wc_xero_sync_order_sync_status_list(){
	if ( ! empty( $_POST ) && check_admin_referer( 'myworks_wc_xero_sync_order_sync_status_list', 'order_sync_status_list' ) ) {
		# A nonce proves intent, not authorization: this handler re-queries Xero live and re-persists
		# order sync state, so gate it on the WooCommerce order capability too.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Unauthorized access', 'myworks-sync-for-xero' ) ) );
		}

		global $MWXS_L;

		# Manual Refresh: re-check the given order IDs live (build the number map server-side so the
		# matcher re-validates and re-persists Synced/Not-Synced regardless of cached state). Refs #83
		# Read the flag and the ids through var_p() — sanitize_text_field(wp_unslash(...)) across the
		# array — so the branch condition and the loop agree on one sanitized representation.
		$force_refresh    = $MWXS_L->var_p('force');
		$posted_order_ids = $MWXS_L->var_p('order_ids');
		if(!empty($force_refresh) && is_array($posted_order_ids) && !empty($posted_order_ids)){
			$order_id_num_arr = array();
			foreach($posted_order_ids as $oid){
				# Validate rather than cast: (int) turns "1abc" into 1 and an array into 1, which would
				# silently point the refresh at order 1 instead of rejecting the input.
				$oid = filter_var($oid, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
				if($oid === false){
					continue;
				}
				$num = $MWXS_L->get_woo_ord_number_from_order($oid);
				$order_id_num_arr[$oid] = (!empty($num)) ? $num : $oid;
			}
		}else{
			$order_id_num_arr = $MWXS_L->var_p('order_id_num_arr');
		}

		if(is_array($order_id_num_arr) && !empty($order_id_num_arr)){
			$MWXS_L->xero_connect();
			$order_id_num_arr = $MWXS_L->get_order_sync_status_list($order_id_num_arr);

			if(empty($order_id_num_arr)){
				$order_id_num_arr = array_fill_keys(array_keys($order_id_num_arr), null);
			}			
		}
		
		#$MWXS_L->_p($order_id_num_arr);
		echo json_encode($order_id_num_arr);
	}
	wp_die();
}

# Order edit-page "Xero Status" metabox: async re-verify a single order live (off the page-load path),
# write back local state, and return the current status so the metabox can refresh itself. Refs #83
function myworks_wc_xero_sync_order_status_mb(){
	check_ajax_referer( 'myworks_wc_xero_sync_order_status_mb', 'nonce' );

	# Writes order meta via set_order_xero_sync_state()/set_order_xero_not_synced(), so a nonce alone
	# is not sufficient authorization.
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => esc_html__( 'Unauthorized access', 'myworks-sync-for-xero' ) ) );
	}

	global $MWXS_L, $MWXS_A;

	$order_id = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
	$resp = array('state'=>'unknown','invoice_id'=>'','invoice_number'=>'','html'=>'');

	# A positive integer isn't proof the order exists. Resolve it before querying Xero or writing sync
	# state, so a bogus ID can't drive an API call or persist meta against a non-order.
	if ( $order_id > 0 ) {
		$mb_order = wc_get_order( $order_id );
		if ( ! $mb_order || ! is_a( $mb_order, 'WC_Order' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Order not found', 'myworks-sync-for-xero' ) ) );
		}
	}

	if($order_id > 0){
		$MWXS_L->xero_connect();
		if($MWXS_L->is_xero_connected()){
			$invoice_data = $MWXS_L->get_wc_order_details_from_order($order_id);
			if(is_array($invoice_data) && !empty($invoice_data)){
				$order_sync_as = $MWXS_L->get_xero_order_sync_as($order_id,$invoice_data);
				if($order_sync_as == 'Invoice'){
					$xero_invoice = $MWXS_L->check_xero_invoice_get_obj($invoice_data);

					// null = the Xero lookup FAILED, which is not the same as "this order is not in
					// Xero". This branch persists its answer via set_order_xero_not_synced(), so
					// treating a failure as a negative would write a wrong fact to the order: an order
					// that IS synced gets recorded as not synced because Xero happened to be down.
					// Report the state as unknown and change nothing. Refs #87
					if($xero_invoice === null){
						$resp['state'] = 'unverified';
						$resp['msg'] = __('Could not reach Xero to check this order. Its sync status is unchanged - try again shortly.', 'myworks-sync-for-xero');
					}elseif(is_object($xero_invoice) && !empty($xero_invoice)){
						$invoice_id = (string) $xero_invoice->getInvoiceID();
						$invoice_number = (string) $xero_invoice->getInvoiceNumber();
						$MWXS_L->set_order_xero_sync_state($order_id,$invoice_id,'',$invoice_number);
						$resp['state'] = 'synced';
						$resp['invoice_id'] = $invoice_id;
						$resp['invoice_number'] = $invoice_number;
					}else{
						$MWXS_L->set_order_xero_not_synced($order_id);
						$resp['state'] = 'not_synced';
					}
				}else{
					$resp['state'] = 'other';
				}
			}
		}else{
			$resp['state'] = 'disconnected';
		}
	}

	# Render the metabox body server-side (reuses the same builder) so the JS just swaps it in.
	if(is_object($MWXS_A) && method_exists($MWXS_A,'mwxs_metabox_status_html') && $resp['state'] !== 'other' && $resp['state'] !== 'unknown'){
		$resp['html'] = $MWXS_A->mwxs_metabox_status_html($resp['state'],$resp['invoice_id'],$resp['invoice_number']);
	}

	wp_send_json($resp);
}

function myworks_wc_xero_sync_rescan_data() {
	$nonce = isset( $_POST['rescan_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['rescan_nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'myworks_wc_xero_sync_rescan_data' ) ) {
		wp_send_json_error( array( 'message' => esc_html__( 'Security check failed', 'myworks-sync-for-xero' ) ) );
		wp_die();
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => esc_html__( 'Unauthorized access', 'myworks-sync-for-xero' ) ) );
		wp_die();
	}

	$rescan_type = isset( $_POST['rescan_type'] ) ? sanitize_text_field( wp_unslash( $_POST['rescan_type'] ) ) : '';

	global $MWXS_L;

	if ( ! $MWXS_L->is_xero_connected() ) {
		$MWXS_L->xero_connect();
	}

	if ( ! $MWXS_L->is_xero_connected() ) {
		wp_send_json_error( array( 'message' => esc_html__( 'Xero Not Connected', 'myworks-sync-for-xero' ) ) );
		wp_die();
	}

	$response = array( 'message' => '', 'reload' => false );

	switch ( $rescan_type ) {
		case 'xero_products':
			$count               = (int) $MWXS_L->xero_refresh_products();
			$response['message'] = wp_kses_post( sprintf( __( '<p>Total Products Recognized: %d</p>', 'myworks-sync-for-xero' ), $count ) );
			break;

		case 'xero_customers':
			$count               = (int) $MWXS_L->xero_refresh_customers();
			$response['message'] = wp_kses_post( sprintf( __( '<p>Total Customers Recognized: %d</p>', 'myworks-sync-for-xero' ), $count ) );
			break;

		case 'xero_data':
			$accounts            = $MWXS_L->xero_get_accounts_kva( true );
			$tax_rates           = $MWXS_L->xero_get_tax_rates_kva( true );
			// One getTrackingCategories() call feeds all three tracking caches (list, options and
			// the structured per-order map). They each used to fetch the same list themselves. Refs #87.
			$tracking_source     = $MWXS_L->xero_get_active_tracking_categories();
			$tracking_categories = $MWXS_L->xero_get_tracking_categories_kva( true, $tracking_source );
			$MWXS_L->xero_get_tracking_options_kva( true, $tracking_source );
			$MWXS_L->xero_get_tracking_map_kva( true, $tracking_source );
			$branding_themes     = $MWXS_L->xero_get_branding_themes_kva( true );
			$response['message'] = wp_kses_post(
				sprintf( __( '<p>Accounts: %d</p>', 'myworks-sync-for-xero' ), count( $accounts ) ) .
				sprintf( __( '<p>Tax Rates: %d</p>', 'myworks-sync-for-xero' ), count( $tax_rates ) ) .
				sprintf( __( '<p>Tracking Categories: %d</p>', 'myworks-sync-for-xero' ), count( $tracking_categories ) ) .
				sprintf( __( '<p>Branding Themes: %d</p>', 'myworks-sync-for-xero' ), count( $branding_themes ) )
			);
			$response['reload']  = true;
			break;

		default:
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid rescan type', 'myworks-sync-for-xero' ) ) );
			wp_die();
	}

	wp_send_json_success( $response );
	wp_die();
}

function myworks_wc_xero_sync_order_invoice_pdf() {
	global $MWXS_L;

    if (!current_user_can('manage_woocommerce')) {
        wp_die('Unauthorized', '', ['response' => 403]);
    }

    check_admin_referer('view_xero_invoice_pdf');

    $invoice_id = isset($_GET['invoice_id']) ? sanitize_text_field(wp_unslash($_GET['invoice_id'])) : '';
    if (!$invoice_id) {
        wp_die('Missing Invoice ID', '', ['response' => 400]);
    }

    // This value is concatenated into the api.xero.com request path below, so require the fixed GUID
    // shape rather than accepting any non-empty string. Same check the customer-facing PDF handler
    // already applies (class-myworks-woo-sync-for-xero-lib-frontend.php), and consistent with how
    // X_Add_Payment()/check_xero_invoice_get_obj() treat Xero identifiers.
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $invoice_id)) {
        wp_die('Invalid Invoice ID', '', ['response' => 400]);
    }

	$MWXS_L->xero_connect();

    $access_token = $MWXS_L->get_xero_access_token();
    $tenant_id    = $MWXS_L->get_xero_tenant_id();

    if (empty($access_token) || empty($tenant_id)) {
        wp_die('Missing Xero Authorization Details', '', ['response' => 400]);
    }

    $url = "https://api.xero.com/api.xro/2.0/Invoices/$invoice_id";

    $args = array(
        'headers' => array(
            'Authorization' => "Bearer {$access_token}",
            'Xero-tenant-id' => $tenant_id,
            'Accept' => 'application/pdf',
        ),
        'timeout' => 30,
        'sslverify' => true,
    );

    $response = wp_remote_get($url, $args);
    
    if (is_wp_error($response)) {
        wp_die('Failed to fetch invoice PDF: ' . esc_html($response->get_error_message()), '', ['response' => 500]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    $http_code = wp_remote_retrieve_response_code($response);
    $pdf = wp_remote_retrieve_body($response);

    if ($http_code !== 200 || empty($pdf)) {
        wp_die('Failed to fetch invoice PDF', '', ['response' => 500]);
    }

    // Validate that the content is actually a PDF file
    if (substr($pdf, 0, 4) !== '%PDF') {
        wp_die('Invalid PDF content received from Xero', '', ['response' => 500]);
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="xero-invoice.pdf"');
    header('Content-Length: ' . strlen($pdf));

    echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary PDF content cannot be escaped
    exit;
}
