<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin interface requires direct queries for map/log/queue tables
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// Reason: Variables in this partial template are local scope passed from parent, not globals
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Partial template
 *
 * Variables in scope:
 * @var mixed $wpdb WordPress database object
 * @var mixed $page_url Current page URL
 * @var mixed $UP URL prefix from parent
 * @var mixed $table Database table name
 * @var mixed $MWXS_L Library singleton instance
 * @var mixed $this Parent class instance (from dlobj)
 */

global $wpdb;
$page_url = $UP.'payment-method';

// Security: Use validated table name
$table = $MWXS_L->get_validated_table_name('map_payment_method');

// Fetch Xero accounts for both display and save operations
$MWXS_L->xero_connect();
$xaa = $MWXS_L->xero_get_accounts_kva();

# POST Action
if (! empty( $_POST ) && check_admin_referer( 'myworks_wc_xero_sync_map_wc_xero_payment_methods', 'map_wc_xero_payment_methods' ) ) {
	# A nonce proves intent, not authorization: this block writes mappings, so require the
	# WooCommerce capability too rather than relying on the parent page's gating.
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'You do not have permission to change these mappings.', 'myworks-sync-for-xero' ), '', array( 'response' => 403 ) );
	}

	#$MWXS_L->_p($_POST);	
	// Security: Only execute if table is validated
	if ($table) {
		$wpdb->query($wpdb->prepare("DELETE FROM `" . esc_sql($table) . "` WHERE `id` > %d", 0));
		$wpdb->query($wpdb->prepare("TRUNCATE TABLE `" . esc_sql($table) . "`"));
	}	
	
	foreach($_POST as $k=>$val){
		$k = $MWXS_L->sanitize($k);
		$val = $MWXS_L->array_sanitize($val);
		
		$kpm = false;
		 if (preg_match('#^kpm__#', $k) === 1) {
			$kpm = true;
		 }
		
		if($kpm){
			$p_method_str = $k;
			$p_method_arr = array();
			
			if($kpm){
				$p_method_arr = explode('__',$p_method_str);
			}				

			$p_method = '';
			$p_cur = '';
			
			if(is_array($p_method_arr) && count($p_method_arr)>2){
				$p_method = $p_method_arr[1];
				$p_cur = $p_method_arr[2];
			}
			
			if(!empty($p_method) && !empty($p_cur)){
				$p_map_ep = (isset($_POST[$p_method.'__'.$p_cur.'_ep']))?1:0;
				$x_ba_id  = $MWXS_L->var_p('pm__'.$p_method.'__'.$p_cur);

				$p_map_erefund = (isset($_POST[$p_method.'__'.$p_cur.'_erefund']))?1:0;

				$p_map_etfngli = (isset($_POST[$p_method.'__'.$p_cur.'_etfngli']))?1:0;
				$p_map_tfnlixp  = $MWXS_L->var_p($p_method.'__'.$p_cur.'_tfnlixp');

				$p_map_iddd  = $MWXS_L->var_p($p_method.'__'.$p_cur.'_iddd');
				$p_map_apsos = $MWXS_L->var_p($p_method.'__'.$p_cur.'_apsos');
				
				# Save
				$pm_map_save_data = array();

				$pm_map_save_data['wc_payment_method'] = $p_method;
				$pm_map_save_data['currency'] = $p_cur;

				$pm_map_save_data['enable_payment'] = $p_map_ep;
				$pm_map_save_data['X_ACC_ID'] = $x_ba_id;

				// Look up and store account name
				$account_name = '';
				if (isset($xaa) && is_array($xaa) && isset($xaa[$x_ba_id])) {
					$account_name = $xaa[$x_ba_id];
				}
				$pm_map_save_data['xero_account_name'] = $account_name;

				$pm_map_save_data['enable_refund'] = $p_map_erefund;

				$pm_map_save_data['enable_txn_fee'] = $p_map_etfngli;
				$pm_map_save_data['txn_fee_x_product'] = $p_map_tfnlixp;

				$pm_map_save_data['x_invoice_ddd'] = (int) $p_map_iddd;
				$pm_map_save_data['aps_order_status'] = $p_map_apsos;
				
				$pm_map_save_data = array_map(array($MWXS_L, 'sanitize'), $pm_map_save_data);					
				$wpdb->insert($table, $pm_map_save_data);
			}
		}
	}
	
	$MWXS_L->set_session_val('map_page_update_message',__('Payment methods mapped successfully.','myworks-sync-for-xero'));

	// Clear dashboard cache after mapping changes
	delete_transient( MyWorks_WC_Xero_Sync_Wc_Data_List::DASHBOARD_CACHE_KEY );

	// Track Mixpanel event
	MyWorks_Mixpanel_Integration::track_event( 'Map: Saved', array( 'map_type' => 'payment-method' ) );

	$MWXS_L->redirect($page_url);

}

$is_lre_p = false;
if($MWXS_L->is_plg_lc_p_l() || $MWXS_L->is_plg_lc_p_r() || $MWXS_L->is_plg_lc_p_empty()){
	$is_lre_p = true;
}

$lrep_disabled = ($is_lre_p)?' disabled':'';

$wc_p_methods = $this->dlobj->get_wc_active_payment_gateways();
// Fallback mapping for orders whose payment method is empty, "other", or otherwise unmapped.
// Keyed 'other' to match WooCommerce core's manual-order "Other" value. Refs #78
if(is_array($wc_p_methods)){
	$wc_p_methods['other'] = __('Other (Orders with no payment method)', 'myworks-sync-for-xero');
}
$wc_currency_list = array();
if($MWXS_L->is_wc_multicurrency_enabled()){
	$wc_store_currencies_s = trim($MWXS_L->get_option('mw_wc_xero_sync_s_wc_store_currencies'));
	if(!empty($wc_store_currencies_s)){
		$wc_currency_list = explode(',',$wc_store_currencies_s);
	}
}

if(empty($wc_currency_list)){
	$wc_currency_list[] = get_woocommerce_currency();
}

$is_ajax_dd = $MWXS_L->is_s2_ajax_dd();

if(!$is_ajax_dd){
	$xpsb = 'Name';	
}

$is_valid_pm = false;
$pm_map_data = array();
if(is_array($wc_p_methods) && !empty($wc_p_methods) && is_array($wc_currency_list) && !empty($wc_currency_list)){
	$is_valid_pm = true;
	$pm_map_data = $MWXS_L->get_tbl($table);
}

$wc_order_statuses = wc_get_order_statuses();

require_once plugin_dir_path( __FILE__ ) . 'map-nav.php';
?>
<style>
	.pmm_aps{display:none;}
	.hide_advanced_payment_sync{display:none;}
	.mw_pmm_tbl{border-bottom:1px solid #DDDDDD;}
</style>

<div class="container map-product-responsive">
	<div class="page_title">
		<div class="page_title flex-box">
			<h4><?php esc_html_e( 'Payment Method Mappings', 'myworks-sync-for-xero' );?></h4>
			<div class="dashboard_main_buttons p-mapbtn">
				<button class="show_advanced_payment_sync" id="show_advanced_payment_sync">Show Advanced Options</button>
				<button class="hide_advanced_payment_sync" id="hide_advanced_payment_sync">Hide Advanced Options</button>
			</div>
		</div>
	</div>
	
	<div class="card">
		<div class="card-content">
			<div class="row">
				<?php if($is_valid_pm):?>
				<form method="POST" class="col s12 m12 l12" id="mw_pmm_form" onsubmit="javascript:return mw_pmm_f_validation();">
					<div class="row">
						<div class="col s12 m12 l12">
							<?php foreach($wc_p_methods as $pm_key => $pm_val):?>
							<div class="pm_map_list" style="margin:10px 0px 10px 0px;">
								<h5><?php echo ($pm_key === 'other') ? esc_html($pm_val) : esc_html($pm_val).' ('.esc_html($pm_key).')';?></h5>
								<div class="myworks-wc-qbo-sync-table-responsive">
									<table class="mw-qbo-sync-settings-table menu-blue-bg menu-bg-a new-table mw_pmm_tbl" style="width:100%" cellpadding="5" cellspacing="5">
										<thead>
											<tr>
												<th width="40%">&nbsp;</th>
												<?php foreach($wc_currency_list as $c_val):?>
												<th><b><?php echo esc_html($c_val);?></b></th>
												<?php endforeach;?>
												<th>&nbsp;</th>
											</tr>
										</thead>
										
										<tbody>
											<tr class="pmm_dps">
												<td height="40">
													<?php esc_html_e( 'Enable Payment Syncing', 'myworks-sync-for-xero' );?>
												</td>
												
												<?php foreach($wc_currency_list as $c_val):?>
												<td>
													<input data-cba="pm__<?php echo esc_attr($pm_key);?>__<?php echo esc_attr($c_val);?>" type="checkbox" class="pm_chk_ep pm_chk" value="1" name="<?php echo esc_attr($pm_key);?>__<?php echo esc_attr($c_val);?>_ep" id="<?php echo esc_attr($pm_key);?>__<?php echo esc_attr($c_val);?>_ep">
												</td>												
												<?php endforeach;?>
												
												<td>
													<?php
														$tt = 'Enable the syncing of payments for this gateway & specific currency. If not enabled, payments will not be synced in real time to Xero.';
														myworks_woo_sync_for_xero_set_tooltip($tt);
													?>
												</td>
											</tr>
											
											<tr class="pmm_dps">
												<td><?php esc_html_e( 'Xero Bank Account', 'myworks-sync-for-xero' );?></td>
												<?php foreach($wc_currency_list as $c_val):?>
												<td class="new-widt">
													<input type="hidden" name="kpm__<?php echo esc_attr($pm_key);?>__<?php echo esc_attr($c_val);?>">
													
													<select class="qbo_select dd_xba" name="pm__<?php echo esc_attr($pm_key);?>__<?php echo esc_attr($c_val);?>" id="pm__<?php echo esc_attr($pm_key);?>__<?php echo esc_attr($c_val);?>" style="background-color:#f4f4f4" disabled>														
														<?php $MWXS_L->only_option('',$xaa);?>
													</select>
												</td>
												<?php endforeach;?>
												
												<td>
													<?php
														$tt = 'Choose the Bank Account in Xero that payments from your woocommerce gateway will be deposited into in real life / in Xero.';
														myworks_woo_sync_for_xero_set_tooltip($tt);
													?>
												</td>
											</tr>
											
											<tr class="pmm_dps">
												<td height="40">
													<?php 
														if(!$is_lre_p){
															esc_html_e( 'Enable Refund Syncing', 'myworks-sync-for-xero' );
														}else{
															echo '<span style="color:lightgray;">';
															esc_html_e( 'Enable Refund Syncing', 'myworks-sync-for-xero' );
															echo '</span>';
															echo '&nbsp;';
															echo '<img style="vertical-align: middle;" src="' . esc_url(MW_WC_XERO_SYNC_P_DIR_U.'admin/image/lock-icon.svg') . '" alt="(Locked)" width="16" height="16" title="Not available for the current plan">';
														}
														
													?>
												</td>

												<?php foreach($wc_currency_list as $c_val):?>
												<td>
													<input<?php echo esc_attr($lrep_disabled);?> class="pm_chk pm_chk_erefund" type="checkbox" value="1" name="<?php echo esc_attr($pm_key);?>__<?php echo esc_attr($c_val);?>_erefund" id="<?php echo esc_attr($pm_key);?>__<?php echo esc_attr($c_val);?>_erefund">
												</td>
												<?php endforeach;?>

												<td>
													<?php
													$tt = 'Enable the syncing of refunds made with this gateway in this specific currency. If not enabled, refunds will not be synced to Xero.';
													myworks_woo_sync_for_xero_set_tooltip($tt);
													?>												
												</td>
											</tr>
											
											<tr class="pmm_dps">
												<td height="40">
													<?php esc_html_e( 'Enable Transaction Fee As Negative Line Item', 'myworks-sync-for-xero' );?>
												</td>

												<?php foreach($wc_currency_list as $c_val):?>
												<td>
													<input class="pm_chk pm_chk_etfngli" type="checkbox" value="1" name="<?php echo esc_attr($pm_key);?>__<?php echo esc_attr($c_val);?>_etfngli" id="<?php echo esc_attr($pm_key);?>__<?php echo esc_attr($c_val);?>_etfngli" data-cba="<?php echo esc_attr($pm_key);?>__<?php echo esc_attr($c_val);?>_tfnlixp">
												</td>
												<?php endforeach;?>

												<td>
													<?php
													$tt = 'Enable this to sync transaction fee as negative line item in the order.';
													myworks_woo_sync_for_xero_set_tooltip($tt);
													?>												
												</td>
											</tr>
											
											<tr class="pmm_dps">
												<td height="40">
													<?php esc_html_e( 'Transaction Fee Negative Line Item Product', 'myworks-sync-for-xero' );?>
												</td>

												<?php foreach($wc_currency_list as $c_val):?>
												<?php													
													$dd_ext_class = '';													
													if($is_ajax_dd){
														$dd_ext_class = 'mwqs_dynamic_select';														
														
													}
												?>
												
												<td>
													<select class="qbo_select <?php echo esc_attr($dd_ext_class);?>" name="<?php echo esc_attr($pm_key);?>__<?php echo esc_attr($c_val);?>_tfnlixp" id="<?php echo esc_attr($pm_key);?>__<?php echo esc_attr($c_val);?>_tfnlixp" style="background-color:#f4f4f4" disabled>
														<?php 
															if($is_ajax_dd){
																$X_ItemID = '';
																if(is_array($pm_map_data) && !empty($pm_map_data)){
																	foreach($pm_map_data as $list){
																		if($list['wc_payment_method'] == $pm_key && $list['currency'] == $c_val){
																			$X_ItemID = $list['txn_fee_x_product'];
																			break;
																		}
																	}
																}

																if(!empty($X_ItemID)){
																	$x_item_name = $MWXS_L->get_field_by_val($MWXS_L->gdtn('products'),'Name','ItemID',$X_ItemID);
																	if(!empty($x_item_name)){
																		echo '<option value="'.esc_attr($X_ItemID).'">'.esc_html(stripslashes($x_item_name)).'</option>';
																	}
																}else{
																	echo '<option value=""></option>';
																}
															}else{
																echo '<option value=""></option>';
																$MWXS_L->option_html('', $MWXS_L->gdtn('products'),'ItemID','Name','',$xpsb.' ASC');
															}
														?>
													</select>
												</td>
												<?php endforeach;?>
												
												<td>
													<?php
													$tt = 'Choose the Xero product for txn fee negative line item.';
													myworks_woo_sync_for_xero_set_tooltip($tt);
													?>												
												</td>
											</tr>
											
											<tr class="pmm_dps">
												<td height="40">
													<?php esc_html_e( 'Xero Invoice Due Date Delay', 'myworks-sync-for-xero' );?>
												</td>

												<?php foreach($wc_currency_list as $c_val):?>
												<td>
													<select class="qbo_select <?php echo esc_attr($dd_ext_class);?>" name="<?php echo esc_attr($pm_key);?>__<?php echo esc_attr($c_val);?>_iddd" id="<?php echo esc_attr($pm_key);?>__<?php echo esc_attr($c_val);?>_iddd">
														<option value="0">0</option>
														<?php $MWXS_L->only_option('',$MWXS_L->invoice_due_days_options());?>
													</select>
												</td>
												<?php endforeach;?>

												<td>
													<?php
													$tt = 'Select the amount of days from the order date to set the Xero Invoice Due Date field. The default is 0 - the same date as the WooCommerce order.';
													myworks_woo_sync_for_xero_set_tooltip($tt);
													?>												
												</td>
											</tr>
											
											<tr class="pmm_aps">
												<td height="40">
													<?php esc_html_e( 'Sync artificial payment when order is marked as', 'myworks-sync-for-xero' );?>
													<br>
													<span style="font-size:10px;color:grey;"><?php esc_html_e( 'This setting is ONLY for gateways like COD or Check where the payment is actually not recorded in WooCommerce.', 'myworks-sync-for-xero' );?></span>
												</td>
												
												<?php foreach($wc_currency_list as $c_val):?>
												<td>
													<select class="qbo_select <?php echo esc_attr($dd_ext_class);?>" name="<?php echo esc_attr($pm_key);?>__<?php echo esc_attr($c_val);?>_apsos" id="<?php echo esc_attr($pm_key);?>__<?php echo esc_attr($c_val);?>_apsos">
														<option value=""></option>
														<?php $MWXS_L->only_option('',$wc_order_statuses);?>
													</select>
												</td>
												<?php endforeach;?>
												
												<td>
													<?php
													$tt = 'This setting is ONLY for gateways like COD or BACS where the payment is actually not recorded in WooCommerce. When orders are placed with these types of gateways, there is no actual payment recorded in WooCommerce, so the payment can only be synced to Xero when the order reaches a certain status.';
													myworks_woo_sync_for_xero_set_tooltip($tt);
													?>												
												</td>
											</tr>

										</tbody>
									</table>								
								</div>
							</div>
							<?php endforeach;?>
						</div>
					</div>					
					
					<div class="row">
						<?php wp_nonce_field( 'myworks_wc_xero_sync_map_wc_xero_payment_methods', 'map_wc_xero_payment_methods' ); ?>
						<div class="input-field col s12 m6 l4">
							<button id="mw_pmm_sbtn" class="waves-effect waves-light btn save-btn mw-qbo-sync-green" disabled>
								<?php esc_html_e( 'Save', 'myworks-sync-for-xero' );?>
							</button>
						</div>
					</div>					
				</form>
				<?php endif;?>
			</div>
		</div>
	</div>
</div>

<?php if($is_valid_pm):?>

<script type="text/javascript">
	jQuery(document).ready(function($){	
		/*On Click Checking*/	
		$('.pm_chk_ep , .pm_chk_etfngli').on('switchChange.bootstrapSwitch', function () {
			let chk_ba = $(this).attr('data-cba');
			if(chk_ba==''){return false;}

			let hide_tr = $(this).hasClass('pm_chk_etfngli');

			if($(this).is(':checked')){
				$('#'+chk_ba).removeAttr('disabled');
				$('#'+chk_ba).css('background-color','#ffffff');

				if(hide_tr){
					$('#'+chk_ba).parent('td').parent('tr').fadeIn("slow");
				}
				
			}else{
				$('#'+chk_ba).val('');
				$('#'+chk_ba).attr('disabled','disabled');
				$('#'+chk_ba).css('background-color','#f4f4f4');

				if(hide_tr){
					$('#'+chk_ba).parent('td').parent('tr').fadeOut("slow");
				}				
			}			
		});

		/*Show Hide Advance Payment Sync*/
		$('#show_advanced_payment_sync').on('click', function(e){
			$('#show_advanced_payment_sync').hide();
			$('.pmm_aps').show();
			$('#hide_advanced_payment_sync').show();			
		});

		jQuery('#hide_advanced_payment_sync').on('click', function(e){
			$('#show_advanced_payment_sync').show();
			$('.pmm_aps').hide();
			$('#hide_advanced_payment_sync').hide();			
		});
		
		/*Select Values*/
		<?php if(is_array($pm_map_data) && !empty($pm_map_data)):?>
		<?php foreach($pm_map_data as $list):?>		
		
		<?php if($list['enable_payment'] == 1):?>
		$('#<?php echo esc_js($list['wc_payment_method']);?>__<?php echo esc_js($list['currency']);?>_ep').prop('checked', true);
		$('#pm__<?php echo esc_js($list['wc_payment_method']);?>__<?php echo esc_js($list['currency']);?>').val('<?php echo esc_js($list['X_ACC_ID']);?>');
		<?php endif;?>

		<?php if($list['enable_refund'] == 1 && !$is_lre_p):?>
		$('#<?php echo esc_js($list['wc_payment_method']);?>__<?php echo esc_js($list['currency']);?>_erefund').prop('checked', true);
		<?php endif;?>

		
		<?php if($list['enable_txn_fee'] == 1):?>
		$('#<?php echo esc_js($list['wc_payment_method']);?>__<?php echo esc_js($list['currency']);?>_etfngli').prop('checked', true);
		$('#<?php echo esc_js($list['wc_payment_method']);?>__<?php echo esc_js($list['currency']);?>_tfnlixp').val('<?php echo esc_js($list['txn_fee_x_product']);?>');
		<?php endif;?>
		
		$('#<?php echo esc_js($list['wc_payment_method']);?>__<?php echo esc_js($list['currency']);?>_iddd').val('<?php echo esc_js($list['x_invoice_ddd']);?>');
		$('#<?php echo esc_js($list['wc_payment_method']);?>__<?php echo esc_js($list['currency']);?>_apsos').val('<?php echo esc_js($list['aps_order_status']);?>');
		
		<?php endforeach;?>
		<?php endif;?>
		
		/*Checking in Loop*/
		$('.pm_chk_ep , .pm_chk_etfngli').each(function(){
			let chk_ba = $(this).attr('data-cba');
			if(chk_ba==''){return false;}

			let hide_tr = $(this).hasClass('pm_chk_etfngli');
			
			if($(this).is(':checked')){
				$('#'+chk_ba).removeAttr('disabled');
				$('#'+chk_ba).css('background-color','#ffffff');

				if(hide_tr){
					$('#'+chk_ba).parent('td').parent('tr').show();	
				}				
			}else{
				$('#'+chk_ba).val('');
				$('#'+chk_ba).attr('disabled','disabled');
				$('#'+chk_ba).css('background-color','#f4f4f4');

				if(hide_tr){
					$('#'+chk_ba).parent('td').parent('tr').hide();
				}				
			}
		});
		
		/*Bootstrap Switch*/
		$('input.pm_chk').attr('data-size','small');
		$('input.pm_chk').bootstrapSwitch();

		/*Enable Save Button*/
		$('#mw_pmm_sbtn').removeAttr('disabled');

		/*Bank Account Filters*/
		$('.dd_xba option').filter(function() {
			return $.trim(this.text).indexOf('(Bank)') === -1;
		}).remove();
		
		//$(".dd_xba").prepend('<option value=""></option>');
	});
	
	/*Form Validation*/
	function mw_pmm_f_validation(){
		let ive = false;
		jQuery('.pm_chk_ep').each(function(){
			if(jQuery(this).is(':checked')){
				let epf_id = jQuery(this).attr('id');
				let epf_id_st = epf_id.substring(0,epf_id.length - 3);				
				let xmba_v = jQuery('#pm__'+epf_id_st).val();
				
				if(mw_xero_sync_string_is_empty(xmba_v)){
					ive = true;
				}
			}
		});
		
		if(ive){
			alert('Plesae select Xero bank account for all enabled payments gateways');
			return false;
		}		
	}	
</script>
<?php myworks_woo_sync_for_xero_get_select2_js('.qbo_select','xero_product');?>

<?php endif;?>