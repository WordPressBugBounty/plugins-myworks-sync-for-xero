<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// Reason: Variables in this partial template are local scope passed from parent, not globals

if(!defined( 'ABSPATH' )){

/**
 * Partial template
 *
 * Variables in scope:
 * @var mixed $MWXS_L Library singleton instance
 * @var mixed $this Parent class instance (from dlobj)
 */
	exit;
}

global $MWXS_L;
global $MWXS_A;

# For Debug
$is_debug = false;
$is_xc_required = true;
$exit_after_debug = false;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Debug mode check, read-only GET parameters for development
if(isset($_GET['debug']) && $_GET['debug'] == 1){
	$is_debug = true;
	$xc = (isset($_GET['xc']) && $_GET['xc'] == 1)?true:false;
	if(!$xc){
		$is_xc_required = false;
	}

	if(isset($_GET['exit']) && $_GET['exit'] == 1){
		$exit_after_debug = true;
	}
}
// phpcs:enable WordPress.Security.NonceVerification.Recommended

if($is_xc_required){
	$MWXS_L->xero_connect();
}

if($is_debug){
	$MWXS_L->debug();

	#->Customer
	#$debug_data = $MWXS_L->get_wc_customer_info(2);
	#$MWXS_L->_p($debug_data);
	
	#->Order
	#$debug_data = $MWXS_L->get_wc_customer_info_from_order(22);
	#$MWXS_L->_p($debug_data);
	#$debug_data = $MWXS_L->get_wc_order_details_from_order(14);
	#$MWXS_L->_p($debug_data);
	#echo $MWXS_L->get_xero_customer_for_order_sync($debug_data);
	#$MWXS_L->X_Add_Invoice($debug_data);
	#$MWXS_A->hook_order_add(array('order_id'=>85,'f_p_p'=>true));
	#$MWXS_A->hook_order_add(137);
	#$MWXS_L->_p($MWXS_L->check_xero_invoice_get_obj(array('wc_inv_id'=>82,'wc_inv_num'=>'')));
	#$MWXS_L->_p($MWXS_L->get_xero_order_sync_as(14));
	
	#->Payment
	#$MWXS_A->hook_payment_add(array('order_id'=>97,'f_p_p'=>true));
	
	#->Product
	#$debug_data = $MWXS_L->get_wc_product_info(26);
	#$MWXS_L->_p($debug_data);
	#$MWXS_L->X_Add_Product($debug_data);
	#$MWXS_A->hook_product_add(array('product_id'=>26,'f_p_p'=>true));

	#->Variation
	#$debug_data = $MWXS_L->get_wc_variation_info(61);
	#$MWXS_L->_p($debug_data);
	#$MWXS_A->hook_variation_add(array('variation_id'=>61,'f_p_p'=>true));
	
	#->Log
	#$MWXS_L->add_text_into_log_file('Test');
	
	#->Queue
	#$x_id = 'g6734e2d-2c2c-1111-980b-90c49ff68890';
	#$w_id = 1;
	#$MWXS_L->wx_queue_add('Product',$w_id,'Push');
	#$MWXS_L->wx_queue_add('Product',$x_id,'Pull');	

	#->Others
	#$MWXS_L->_p($MWXS_L->db_check_get_fields_details());
	#$terms = get_the_terms ( 91, 'product_cat' );
	#$MWXS_L->_p($terms);
	
	if($exit_after_debug){
		exit();
	}
}

$dashboard_graph_period = $MWXS_L->get_session_val('dashboard_graph_period','month');

$dbsd = $this->dlobj->get_dashboard_status_data();

$wc_p_methods = $this->dlobj->get_wc_active_payment_gateways();
$wc_apg_count = (is_array($wc_p_methods))?count($wc_p_methods):0;
?>

<div class="qcpp_cnt" title="Version: <?php echo esc_attr(MW_WC_XERO_SYNC_PLUGIN_VERSION);?>">
	<img width="300"  alt="WooCommerce Sync for Xero - by MyWorks Software" src="<?php echo esc_url(plugins_url( MW_WC_XERO_SYNC_PLUGIN_NAME.'/admin/image/mwd-logo.png' )) ?>" class="mw-qbo-sync-logo">
</div>

<div id="mw_wc_qbo_sync_grph_div" style="background:white;">
	<div class="page_title">
		<div class="dashboard_main_buttons">
			<!-- Rescan Xero Data Dropdown (Dashboard) -->
			<input type="hidden" id="mwxs_db_rescan_nonce" value="<?php echo esc_attr( wp_create_nonce( 'myworks_wc_xero_sync_rescan_data' ) ); ?>">
			<div class="aoutomated-outer g-d-o-btn" id="mwxs_db_rescan_dropdown_wrap" style="display:inline-block; vertical-align:middle;">
				<div class="col col-m auto-map-btn">
					<span class="dropbtn col-m-btn" id="mwxs_db_rescan_btn"><?php esc_html_e( 'Rescan Xero Data', 'myworks-sync-for-xero' ); ?> <i class="fa fa-angle-down"></i></span>
					<div class="dropdown-content">
						<ul class="guide-accordion">
							<li><a href="javascript:void(0);" class="mwxs-db-rescan-item" data-type="xero_products"><?php esc_html_e( 'Rescan Products', 'myworks-sync-for-xero' ); ?></a></li>
							<li><a href="javascript:void(0);" class="mwxs-db-rescan-item" data-type="xero_customers"><?php esc_html_e( 'Rescan Customers', 'myworks-sync-for-xero' ); ?></a></li>
							<li><a href="javascript:void(0);" class="mwxs-db-rescan-item" data-type="xero_data"><?php esc_html_e( 'Rescan Other Lists', 'myworks-sync-for-xero' ); ?></a></li>
						</ul>
					</div>
				</div>
			</div>
			<!-- Rescan Result Modal (Dashboard) -->
			<div id="mwxs_db_rescan_modal_overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:999999;">
				<div id="mwxs_db_rescan_modal" style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); background:#fff; border-radius:6px; padding:30px 35px; min-width:280px; text-align:center; box-shadow:0 4px 20px rgba(0,0,0,0.3);">
					<div id="mwxs_db_rescan_modal_icon" style="font-size:42px; color:#2196F3; margin-bottom:10px;">&#10003;</div>
					<h3 style="margin:0 0 15px; color:#333; font-size:18px;" id="mwxs_db_rescan_modal_title"><?php esc_html_e( 'Rescan Complete!', 'myworks-sync-for-xero' ); ?></h3>
					<div id="mwxs_db_rescan_modal_body" style="font-size:15px; color:#555; line-height:1.8;"></div>
					<button id="mwxs_db_rescan_modal_close" style="margin-top:20px; background:#017cb7; color:#fff; border:none; padding:10px 28px; border-radius:4px; font-size:14px; cursor:pointer;"><?php esc_html_e( 'OK', 'myworks-sync-for-xero' ); ?></button>
				</div>
			</div>
		</div>

		<script type="text/javascript">
		jQuery(document).ready(function($){
			$('.mwxs-db-rescan-item').on('click', function(){
				var type = $(this).data('type');
				var $btn = $('#mwxs_db_rescan_btn');
				var originalHtml = $btn.html();
				$btn.html('<?php echo esc_js( __( 'Rescanning...', 'myworks-sync-for-xero' ) ); ?> <span class="spinner is-active" style="float:none;margin:0 0 0 4px;vertical-align:middle;"></span>').prop('disabled', true);

				jQuery.ajax({
					type: 'POST',
					url: ajaxurl,
					data: {
						action: 'myworks_wc_xero_sync_rescan_data',
						rescan_nonce: $('#mwxs_db_rescan_nonce').val(),
						rescan_type: type
					},
					success: function(response){
						$btn.html(originalHtml).prop('disabled', false);
						if ( response && response.success ) {
							var data = response.data;
							if ( data.redirect ) {
								window.location.href = data.redirect;
								return;
							}
							var msg = data.message || '';
							if ( type === 'xero_customers' ) {
								var m = msg.match(/(\d+)/);
								if ( m ) $('#mwxs_xero_customer_count').text(m[1]);
							}
							if ( type === 'xero_products' ) {
								var m = msg.match(/(\d+)/);
								if ( m ) $('#mwxs_xero_product_count').text(m[1]);
							}
							$('#mwxs_db_rescan_modal_icon').html('&#10003;').css('color','#2196F3');
							$('#mwxs_db_rescan_modal_title').text('<?php echo esc_js( __( 'Rescan Complete!', 'myworks-sync-for-xero' ) ); ?>');
							$('#mwxs_db_rescan_modal_body').text(msg);
							$('#mwxs_db_rescan_modal_overlay').fadeIn(200);
							if ( data.reload ) {
								setTimeout(function(){ location.reload(); }, 1500);
							}
						} else {
							var errMsg = (response && response.data && response.data.message) ? response.data.message : '<?php echo esc_js( __( 'An error occurred. Please try again.', 'myworks-sync-for-xero' ) ); ?>';
							$('#mwxs_db_rescan_modal_icon').html('&#10007;').css('color','#e53935');
							$('#mwxs_db_rescan_modal_title').text('<?php echo esc_js( __( 'Rescan Failed', 'myworks-sync-for-xero' ) ); ?>');
							$('#mwxs_db_rescan_modal_body').html(errMsg);
							$('#mwxs_db_rescan_modal_overlay').fadeIn(200);
						}
					},
					error: function(){
						$btn.html(originalHtml).prop('disabled', false);
						$('#mwxs_db_rescan_modal_icon').html('&#10007;').css('color','#e53935');
						$('#mwxs_db_rescan_modal_title').text('<?php echo esc_js( __( 'Rescan Failed', 'myworks-sync-for-xero' ) ); ?>');
						$('#mwxs_db_rescan_modal_body').html('<?php echo esc_js( __( 'An error occurred. Please try again.', 'myworks-sync-for-xero' ) ); ?>');
						$('#mwxs_db_rescan_modal_overlay').fadeIn(200);
					}
				});
			});

			$('#mwxs_db_rescan_modal_close, #mwxs_db_rescan_modal_overlay').on('click', function(e){
				if ( e.target === this ) $('#mwxs_db_rescan_modal_overlay').fadeOut(200);
			});
		});
		</script>
	</div>

	<!--Graph / Chart-->
	<?php wp_nonce_field( 'myworks_wc_xero_sync_refresh_log_chart', 'refresh_log_chart_nonce' ); ?>
	<div id="mw_wc_qbo_sync_grph_div_new">
		<?php myworks_woo_sync_for_xero_get_log_chart_output($dashboard_graph_period);?>
	</div>
</div>

<div class="dash-bottm mwqs_db_status_cont">
	<div class="col-sm3 mapping-stat module-stat">
		<h3><?php esc_html_e( 'Xero Status', 'myworks-sync-for-xero' );?></h3>
		<ul>
			<li>
				<a <?php if(!$MWXS_L->is_xero_connected()){echo 'class="dbst_err"';}?>>
					<?php esc_html_e( 'Xero Connection', 'myworks-sync-for-xero' );?>
				</a>
			</li>

			<li>
				<a>
					<?php esc_html_e( 'Xero Customers', 'myworks-sync-for-xero' );?>
					<span class="right-btnn" id="mwxs_xero_customer_count"><?php echo esc_html($MWXS_L->get_array_isset($dbsd,'xero_customer_synced',0));?></span>
				</a>
			</li>

			<li>
				<a>
					<?php esc_html_e( 'Xero Products', 'myworks-sync-for-xero' );?>
					<span class="right-btnn" id="mwxs_xero_product_count"><?php echo esc_html($MWXS_L->get_array_isset($dbsd,'xero_product_synced',0));?></span>
				</a>
			</li>
		</ul>
	</div>
	
	<div class="col-sm3 mapping-stat map-sta-a">
		<h3><?php esc_html_e( 'Mapping Status', 'myworks-sync-for-xero' );?></h3>
		<ul>
			<li>
				<a>
					<b><?php esc_html_e( 'Customers Mapped', 'myworks-sync-for-xero' );?></b>
					<span class="right-btnn"><?php echo esc_html($dbsd['customer_mapped']);?></span>
				</a>
			</li>
			
			<li>
				<a>
					<b><?php esc_html_e( 'Products Mapped', 'myworks-sync-for-xero' );?></b>
					<span class="right-btnn"><?php echo esc_html($dbsd['product_mapped']);?></span>
				</a>
			</li>
			
			<li>
				<a>
					<b><?php esc_html_e( 'Variations Mapped', 'myworks-sync-for-xero' );?></b>
					<span class="right-btnn"><?php echo esc_html($dbsd['variation_mapped']);?></span>
				</a>
			</li>
			
			<li>
				<a>
					<b><?php esc_html_e( 'Gateways Mapped', 'myworks-sync-for-xero' );?></b>
					<span class="right-btnn"><?php echo esc_html($dbsd['payment_gateways_mapped']);?></span>
				</a>
			</li>
		</ul>
	</div>
	
	<div class="col-sm3 mapping-stat sync-a">
		<h3><?php esc_html_e( 'WooCommerce Status', 'myworks-sync-for-xero' );?></h3>
		<ul>         	
			<li>
				<a>
					<b><?php esc_html_e( 'Customers', 'myworks-sync-for-xero' );?></b>
					<span class="right-btnn"><?php echo esc_html($dbsd['wc_total_customers']);?></span>
				</a>
			</li>
			
			<li>
				<a>
					<b><?php esc_html_e( 'Products', 'myworks-sync-for-xero' );?></b>
					<span class="right-btnn"><?php echo esc_html($dbsd['wc_total_products']);?></span>
				</a>
			</li>
			
			<li>
				<a>
					<b><?php esc_html_e( 'Variations', 'myworks-sync-for-xero' );?></b>
				<span class="right-btnn"><?php echo esc_html($dbsd['wc_total_variations']);?></span>
				</a>
			</li>

			<li>
				<a>
					<b><?php esc_html_e( 'Active Gateways', 'myworks-sync-for-xero' );?></b>
					<span class="right-btnn"><?php echo esc_html($wc_apg_count);?></span>
				</a>
			</li>
		</ul>
	</div>
</div>

<?php
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Development mode check, read-only GET parameter
	$is_dev = (isset($_GET['dev']) && $_GET['dev'] == 1)?true:false;
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	if($is_debug && $is_dev):
	$lf = 'dev.log';
	$log_file_path = MW_WC_XERO_SYNC_P_DIR_P.'log'.DIRECTORY_SEPARATOR.$lf;

	if(file_exists($log_file_path)):
	global $wp_filesystem;
	if ( empty( $wp_filesystem ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
	}
	$lf_content = $wp_filesystem->get_contents($log_file_path);	
?>

<div style="margin:20px 20px 0px 0px;">
	<h5>Debug Log File (Developer)</h5>
	<textarea readonly="true" style="height:400px;background:white;width:100%;"><?php echo esc_textarea($lf_content);?></textarea>
</div>

<?php
	endif;
	endif;
?>

<!--Script-->
<script>
	function mw_wc_qbo_sync_refresh_log_chart(period){
		var data = {
			"action": 'myworks_wc_xero_sync_refresh_log_chart',
			"period": period,
			"refresh_log_chart_nonce": jQuery('#refresh_log_chart_nonce').val()
		};
		
		jQuery('#mw_wc_qbo_sync_grph_div_new').css('opacity',0.6);
		jQuery.ajax({
		   type: "POST",
		   url: ajaxurl,
		   data: data,
		   cache:  false ,
		   //datatype: "json",
		   success: function(result){
			   if(result!=0 && result!=''){
				jQuery('#mw_wc_qbo_sync_grph_div_new').html(result);
			   }else{
				 alert('Error!');			 
			   }
			   jQuery('#mw_wc_qbo_sync_grph_div_new').css('opacity',1);
		   },
		   error: function(result) {  
				alert('Error!');
				jQuery('#mw_wc_qbo_sync_grph_div_new').css('opacity',1);
		   }
		});
	}
	
	jQuery(document).ready(function($){
	});
</script>