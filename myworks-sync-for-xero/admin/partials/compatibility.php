<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// Reason: Variables in this partial template are local scope passed from parent, not globals

if(!defined( 'ABSPATH' )){

/**
 * Partial template
 *
 * Variables in scope:
 * @var mixed $MWXS_L Library singleton instance
 * @var mixed $page_url Current page URL
 */
	exit;
}

global $MWXS_L;
$page_url = admin_url('admin.php?page=myworks-wc-xero-sync-compatibility');

# Save
if ( ! empty( $_POST ) && check_admin_referer( 'myworks_wc_xero_sync_save_compt_nonce', 'wc_xero_save_compt_s' ) ) {
	$compt_s_saved = false;

	# WooCommerce Sequential Order Numbers Pro
	if(isset($_POST['comp_wsnop'])){
		$compt_s_saved = true;
		$compt_p_wsnop = '';
		if(isset($_POST['mw_wc_xero_sync_compt_p_wsnop'])){
			$compt_p_wsnop = 'true';
		}

		$MWXS_L->update_option('mw_wc_xero_sync_compt_p_wsnop',$compt_p_wsnop);
	}

	# WooCommerce Order Fee Line Items
	if(isset($_POST['comp_fli'])){
		$compt_s_saved = true;
		$fee_line_item_xero_product = $MWXS_L->var_p('mw_wc_xero_sync_fee_line_item_xero_product');
		$MWXS_L->update_option('mw_wc_xero_sync_fee_line_item_xero_product',$fee_line_item_xero_product);
	}
	
	if($compt_s_saved){
		$MWXS_L->set_session_val('compt_save_status','Compatibility settings saved successfully.');
		$MWXS_L->redirect($page_url);
	}	
}

$compt_save_status = $MWXS_L->get_session_val('compt_save_status','',true);

$is_compt = false;
$is_order_num_compt = false;
$show_no_plugin_compt = true;

$is_ajax_dd = $MWXS_L->is_s2_ajax_dd();
$x_p_o_params = array();
$x_c_o_params = array();

if(!$is_ajax_dd){
	$xpsb = 'Name';	
	$x_p_o_params = array('', $MWXS_L->gdtn('products'),'ItemID','Name','',$xpsb.' ASC','',false);
	
	#$xcsb = 'Name';	
	#$x_c_o_params = array('', $MWXS_L->gdtn('customers'),'ContactID','Name','',$xcsb.' ASC','',false);
}

$settings_data = $MWXS_L->plugin_get_all_options();
$s_o_s_arr = array();

if(is_array($settings_data) && !empty($settings_data)){
	$sl_fields = array(
		'fee_line_item_xero_product',		
	);
	
	if(is_array($sl_fields) && !empty($sl_fields)){
		foreach($sl_fields as $v){
			$v = $MWXS_L->get_s_o_p().$v;			
			$sv = (isset($settings_data[$v]))?$settings_data[$v]:'';
			if(!empty($sv)){				
				$s_o_s_arr['#'.$v] = $sv;
			}			
		}
	}
}
?>

<h2 class="compt_addon_heading"><?php esc_html_e( 'Compatibility Included / Addons', 'myworks-sync-for-xero');?></h2>
<div class="container map-coupon-code-outer qo-compatibility-addons">
	<form method="post" action="<?php echo esc_url_raw($page_url);?>">
		<?php wp_nonce_field( 'myworks_wc_xero_sync_save_compt_nonce', 'wc_xero_save_compt_s'); ?>

		<?php
			$wt_son_p_f = 'wt-woocommerce-sequential-order-numbers';
			$wt_son_active = $MWXS_L->is_plugin_active($wt_son_p_f,'wt-advanced-order-number');
		?>
		<?php if($MWXS_L->is_plugin_active('woocommerce-sequential-order-numbers-pro') || $MWXS_L->is_plugin_active('woocommerce-sequential-order-numbers') || $wt_son_active):?>
		<?php
			$is_compt = true;
			$is_order_num_compt = true;
			$son_p_n = 'WooCommerce Sequential Order Numbers Pro';
			$son_p_f = 'woocommerce-sequential-order-numbers-pro';
			if(!$MWXS_L->is_plugin_active($son_p_f)){
				if($MWXS_L->is_plugin_active('woocommerce-sequential-order-numbers')){
					$son_p_n = 'WooCommerce Sequential Order Numbers';
					$son_p_f = 'woocommerce-sequential-order-numbers';
				}else{
					$son_p_n = 'Sequential Order Number for WooCommerce by WebToffee';
					$son_p_f = $wt_son_p_f;
				}
			}
		?>
		<div class="page_title">
			<h4 title="<?php echo esc_attr($son_p_f);?>"><?php echo esc_html($son_p_n);?></h4>
		</div>

		<div class="card">
			<div class="card-content">
				<div class="col s12 m12 l12">
					<div class="myworks-wc-qbo-sync-table-responsive">
						<table class="mw-qbo-sync-settings-table menu-blue-bg" width="100%">
							<tr>
								<td colspan="3">
									<b><?php esc_html_e( 'Settings', 'myworks-sync-for-xero' );?></b>
								</td>
							</tr>

							<tr>
								<td width="60%"><?php echo esc_html( 'Enable '.$son_p_n.' Support');?>:</td>
								<td>
									<?php myworks_woo_sync_for_xero_compt_page_option_check_f('compt_p_wsnop');?>
								</td>
								<td>
									<?php myworks_woo_sync_for_xero_set_tooltip('When enabled, orders will sync into Xero using the "pretty" order number created by '.$son_p_n.' - instead of the WooCommerce Order ID.');?>
								</td>
							</tr>
							
							<tr>
								<td colspan="3">
									<input type="submit" name="comp_wsnop" class="waves-effect waves-light btn save-btn mw-qbo-sync-green" value="Save">
								</td>
							</tr>
						</table>
					</div>
				</div>
			</div>
		</div>
		<?php endif;?>

		<?php if($show_no_plugin_compt):?>
		<?php
			$is_compt = true;
		?>
		<div class="page_title">
			<h4 title=""><?php echo esc_html('WooCommerce Order Fee Line Items');?></h4>
		</div>

		<div class="card">
			<div class="card-content">
				<div class="col s12 m12 l12">
					<div class="myworks-wc-qbo-sync-table-responsive">
						<table class="mw-qbo-sync-settings-table menu-blue-bg" width="100%">
							<tr>
								<td colspan="3">
									<b><?php esc_html_e( 'Settings', 'myworks-sync-for-xero' );?></b>
								</td>
							</tr>

							<tr style="display:none;">
								<td width="60%"><?php echo esc_html( 'Sync fee line items in a WooCommerce Order to Xero');?>:</td>
								<td>
									<?php myworks_woo_sync_for_xero_compt_page_option_check_f('compt_np_fli');?>
								</td>
								<td>
									<?php myworks_woo_sync_for_xero_set_tooltip('Enable/Disable syncing "fee" line items in WooCommerce Orders to Xero line.');?>
								</td>
							</tr>

							<tr>
								<?php
									myworks_woo_sync_for_xero_g_settings_field(
										'select',
										array(
											'f_title' => 'Xero Product for for Fee line item:',
											'name' => 'fee_line_item_xero_product',																	
											
											's_data_src' => 'Options_Params',
											's_data_params' => $x_p_o_params,
											's_data_function' => 'option_html',
											's_blank_option' => true,
											'ajax_dd' => $is_ajax_dd,
											'a_d_d_t' => 'xero_product',
											
											'tt_text' => 'Choose the Xero product that will be used in Xero to represent "fee" line items in WooCommerce.'
										),
									);
								?>
							</tr>
							
							<tr>
								<td colspan="3">
									<input type="submit" name="comp_fli" class="waves-effect waves-light btn save-btn mw-qbo-sync-green" value="Save">
								</td>
							</tr>
						</table>
					</div>
				</div>
			</div>
		</div>
		<?php endif;?>

		<!--If No Compatibility-->
		<?php if(!$is_compt):?>
		<table width="100%">
			<tr>
				<td colspan="3">
					<b><?php esc_html_e( 'No Compatibility Found.', 'myworks-sync-for-xero' );?></b>
				</td>
			</tr>
		</table>
		<?php endif;?>
		
	</form>
</div>

<script type="text/javascript">
	jQuery(document).ready(function($){
		<?php 
			if(is_array($s_o_s_arr) && !empty($s_o_s_arr)){
				foreach($s_o_s_arr as $k => $v){
					echo '$(\''.esc_js($k).'\').val(\''.esc_js($v).'\');';
				}
			}
		?>

		/*Bootstrap Switch*/
		$('input.mwqs_st_chk').attr('data-size','small');
		$('input.mwqs_st_chk').bootstrapSwitch();
	});
</script>

<?php 
	if(!empty($compt_save_status)){
		myworks_woo_sync_for_xero_set_admin_sweet_alert($compt_save_status);
	}
?>

<?php myworks_woo_sync_for_xero_get_select2_js('.mw_wc_qbo_sync_select2','xero_product');?>
<?php #myworks_woo_sync_for_xero_get_select2_js('.mw_wc_qbo_sync_select2_cus','xero_customer',true);?>