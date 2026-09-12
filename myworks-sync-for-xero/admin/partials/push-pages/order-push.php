<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// Reason: Variables in this partial template are local scope passed from parent, not globals

if(!defined( 'ABSPATH' )){

/**
 * Partial template
 *
 * Variables in scope:
 * @var mixed $wpdb WordPress database object
 * @var mixed $page_url Current page URL
 * @var mixed $UP URL prefix from parent
 * @var mixed $MWXS_L Library singleton instance
 * @var mixed $items_per_page Items per page for pagination
 * @var mixed $total_records Total records count
 * @var mixed $this Parent class instance (from dlobj)
 * @var mixed $offset Pagination offset
 */
	exit;
}

global $wpdb;
$page_url = $UP.'order';

# Data Listing / Search
$MWXS_L->set_per_page_from_url();
$items_per_page = $MWXS_L->get_item_per_page();

$MWXS_L->set_and_get('order_push_search');
$order_push_search = $MWXS_L->get_session_val('order_push_search');

$MWXS_L->set_and_get('order_date_from');
$order_date_from = $MWXS_L->get_session_val('order_date_from');

$MWXS_L->set_and_get('order_date_to');
$order_date_to = $MWXS_L->get_session_val('order_date_to');

$MWXS_L->set_and_get('order_status_srch');
$order_status_srch = $MWXS_L->get_session_val('order_status_srch');

# Xero sync status filter + sort (persisted like the other filters). Refs #83
$MWXS_L->set_and_get('order_sync_srch');
$order_sync_srch = $MWXS_L->get_session_val('order_sync_srch');

$MWXS_L->set_and_get('mwxs_orderby');
$mwxs_orderby = $MWXS_L->get_session_val('mwxs_orderby');

$MWXS_L->set_and_get('mwxs_order');
$mwxs_order = $MWXS_L->get_session_val('mwxs_order');

$total_records = $this->dlobj->count_wc_order_list($order_push_search,$order_date_from,$order_date_to,$order_status_srch,$order_sync_srch);

$offset = $MWXS_L->get_offset($MWXS_L->get_page_var(),$items_per_page);

$Limit = ' '.$offset.' , '.$items_per_page;

$wc_order_list = $this->dlobj->get_wc_order_list(false,$Limit,$order_push_search,$order_date_from,$order_date_to,$order_status_srch,$order_sync_srch,$mwxs_orderby,$mwxs_order);
#$MWXS_L->_p($wc_order_list);

// Server-side, cross-page sort header link builder. Refs #83
$mwxs_sort_base = $page_url
	.'&order_push_search='.rawurlencode($order_push_search)
	.'&order_date_from='.rawurlencode($order_date_from)
	.'&order_date_to='.rawurlencode($order_date_to)
	.'&order_status_srch='.rawurlencode($order_status_srch)
	.'&order_sync_srch='.rawurlencode($order_sync_srch);
// Re-use the existing tablesorter header icons (bg.gif / asc.gif / desc.gif) via class names. Refs #83
$mwxs_sort_thclass = function($key) use($mwxs_orderby,$mwxs_order){
	$cls = 'tablesorter-header';
	if($mwxs_orderby === $key){
		$cls .= (strtolower($mwxs_order) === 'asc') ? ' tablesorter-headerAsc' : ' tablesorter-headerDesc';
	}
	return $cls;
};
$mwxs_sort_header = function($key,$label) use($mwxs_sort_base,$mwxs_orderby,$mwxs_order){
	$next = ($mwxs_orderby === $key && strtolower($mwxs_order) === 'asc') ? 'desc' : 'asc';
	$url  = $mwxs_sort_base.'&mwxs_orderby='.$key.'&mwxs_order='.$next;
	return '<a href="'.esc_url($url).'" style="color:inherit;text-decoration:none;white-space:nowrap;display:block;">'.$label.'</a>';
};

# Static data
$wc_order_statuses = wc_get_order_statuses();

$wc_currency_symbol = get_woocommerce_currency_symbol();
#$wc_currency_symbol = '$';

# Syns Status Related
$order_id_num_arr = array();

require_once plugin_dir_path( __FILE__ ) . 'push-nav.php';
?>
<style>	
	span.ss_pf_span{display:none;}
	.x_ss{display:none;}
</style>

<div class="container">
	<div class="page_title"><h4><?php esc_html_e( 'Order Push', 'myworks-sync-for-xero' );?></h4></div>
	<div class="card qo-push-responsive">
		<div class="card-content">			
			<div class="col s12 m12 l12">
				<div class="panel panel-primary">
					<div class="mw_wc_filter">
						<span class="search_text">Search</span>
						&nbsp;
						
						<input placeholder="<?php echo esc_attr__('Name / Company / ID / NUM','myworks-sync-for-xero')?>" type="text" id="order_push_search" value="<?php echo esc_attr($order_push_search);?>">
						&nbsp;
						
						<input style="width:130px;" class="mwqs_datepicker" placeholder="<?php echo esc_attr__('From yyyy-mm-dd','myworks-sync-for-xero')?>" type="text" id="order_date_from" value="<?php echo esc_attr($order_date_from);?>">
						&nbsp;
						
						<input style="width:130px;" class="mwqs_datepicker" placeholder="<?php echo esc_attr__('To yyyy-mm-dd','myworks-sync-for-xero')?>" type="text" id="order_date_to" value="<?php echo esc_attr($order_date_to);?>">
						&nbsp;
						
						<span>
							<select style="width:130px;" name="order_status_srch" id="order_status_srch">
								<option value="">All</option>
								<?php $MWXS_L->only_option($order_status_srch,$wc_order_statuses);?>
							</select>
						</span>

						<span>
							<select style="width:130px;" name="order_sync_srch" id="order_sync_srch">
								<option value="">All Sync Status</option>
								<option value="synced" <?php selected($order_sync_srch,'synced');?>><?php esc_html_e('Synced','myworks-sync-for-xero');?></option>
								<option value="not_synced" <?php selected($order_sync_srch,'not_synced');?>><?php esc_html_e('Not Synced','myworks-sync-for-xero');?></option>
							</select>
						</span>

						<?php myworks_woo_sync_for_xero_filter_reset_show_entries_html($page_url,$items_per_page);?>
					</div>
					<br>
					
					<?php if($total_records > 0):?>
					<div class="row">
						<div class="input-field col s12 m12 14">
							<button id="push_selected_order_btn" class="waves-effect waves-light btn save-btn mw-qbo-sync-green">
								<?php echo esc_html__('Push Selected Orders','myworks-sync-for-xero')?>
							</button>										
						</div>
					</div>
					<br>
					<?php endif;?>
					
					<div class="table-m">
						<div class="myworks-wc-qbo-sync-table-responsive">
							<table id="mwqs_invoice_push_table" class="table tablesorter">
								<thead>
									<tr class="tablesorter-headerRow">
										<th width="2%" class="sorter-false">
											<input type="checkbox" onclick="javascript:mw_xero_sync_check_all(this,'order_push_')">
										</th>
										<th width="13%" class="<?php echo esc_attr($mwxs_sort_thclass('id')); ?>"><?php echo $mwxs_sort_header('id','Order Number / ID'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- url esc_url'd, label static ?></th>
										<th width="15%" class="<?php echo esc_attr($mwxs_sort_thclass('customer')); ?>"><?php echo $mwxs_sort_header('customer','Customer'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- url esc_url'd, label static ?></th>
										<th width="15%" class="<?php echo esc_attr($mwxs_sort_thclass('company')); ?>"><?php echo $mwxs_sort_header('company','Company'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- url esc_url'd, label static ?></th>
										<th width="13%" class="<?php echo esc_attr($mwxs_sort_thclass('date')); ?>"><?php echo $mwxs_sort_header('date','Date'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- url esc_url'd, label static ?></th>
										<th width="10%" class="<?php echo esc_attr($mwxs_sort_thclass('amount')); ?>"><?php echo $mwxs_sort_header('amount','Amount'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- url esc_url'd, label static ?></th>
										<th width="13%" class="<?php echo esc_attr($mwxs_sort_thclass('payment')); ?>"><?php echo $mwxs_sort_header('payment','Payment<br>Method'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- url esc_url'd, label static ?></th>
										<th width="14%" class="<?php echo esc_attr($mwxs_sort_thclass('status')); ?>"><?php echo $mwxs_sort_header('status','Order<br>Status'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- url esc_url'd, label static ?></th>
										<th width="5%" class="<?php echo esc_attr($mwxs_sort_thclass('sync')); ?>"><?php echo $mwxs_sort_header('sync','Sync<br>Status'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- url esc_url'd, label static ?></th>
									</tr>
								</thead>
								
								<tbody>
									<?php if(!empty($wc_order_list)):?>
									<?php foreach($wc_order_list as $data):?>
									<?php 
										#$sync_status_html = '<i class="fa fa-times-circle" style="color:red"></i>';
										
										$wc_inv_no = $MWXS_L->get_woo_ord_number_from_order($data['ID'],$data);
										#$wc_inv_no = '';
										# Local cached Xero sync state: render known rows server-side; only queue unknowns for the backfill AJAX. Refs #83
											$mwxs_inv_id = $MWXS_L->get_order_xero_sync_state($data['ID']);
											$mwxs_checked = ($mwxs_inv_id !== '') ? true : $MWXS_L->order_xero_status_checked($data['ID']);
											if($mwxs_inv_id === '' && !$mwxs_checked){
												$order_id_num_arr[$data['ID']] = (!empty($wc_inv_no))?$wc_inv_no:$data['ID'];
											}
									?>
									
									<tr>
										<td><input type="checkbox" id="order_push_<?php echo esc_attr($data['ID'])?>"></td>
										
										<td>
											<a target="_blank" href="<?php echo esc_url(admin_url('post.php?post='.(int) $data['ID'].'&action=edit')) ?>">
												<?php echo (!empty($wc_inv_no)) ? esc_html($wc_inv_no) . '<br>' : ''; ?>
												<?php echo (int) $data['ID'] ?>											
											</a>
										</td>
										
										<td>
											<?php echo esc_html($data['billing_first_name']) ?> <?php echo esc_html($data['billing_last_name']) ?>
										</td>
										
										<td><?php echo esc_html($data['billing_company']) ?></td>
										<td><?php echo esc_html($data['post_date']) ?></td>
										
										<td>
											<?php echo esc_html($wc_currency_symbol); ?>
											<?php echo ($data['order_total'] != '') ? esc_html($data['order_total']) : '0.00'; ?>
										</td>
										
										<td title="<?php echo esc_attr($data['payment_method_title']) ?>">
											<?php echo esc_html($data['payment_method']) ?>
										</td>
										
										<td>
											<?php echo esc_html($MWXS_L->get_array_isset($wc_order_statuses, $data['post_status'], $data['post_status'])); ?>
										</td>
										<td class="td_ss" id="td_ss_<?php echo esc_attr($data['ID']); ?>"><?php
											if($mwxs_inv_id !== ''){
												echo '<span class="ss_pf_span">1</span><span class="x_ss" style="display:inline;"><i class="fa fa-check-circle" style="color:green"></i></span>';
											}elseif($mwxs_checked){
												echo '<span class="ss_pf_span">0</span><span class="x_ss" style="display:inline;"><i class="fa fa-times-circle" style="color:red"></i></span>';
											}
										?></td>
									</tr>
									
									<?php endforeach;?>									
									<?php else:?>
									<tr>
										<td colspan="6">
											<span class="mwxs_tnd">
												<?php esc_html_e( 'No orders found.', 'myworks-sync-for-xero' );?>
											</span>
										</td>
									</tr>
									<?php endif;?>
								</tbody>
							</table>
						</div>
					</div>
					<?php $MWXS_L->get_paginate_links($total_records,$items_per_page);?>
				</div>
			</div>
		</div>
	</div>
</div>

<?php if($total_records > 0):?>
<?php wp_nonce_field( 'myworks_wc_xero_sync_order_sync_status_list', 'order_sync_status_list' ); ?>
<?php endif;?>

<script type="text/javascript">
	function search_item(){
		let order_push_search = jQuery('#order_push_search').val();
		order_push_search = jQuery.trim(order_push_search);
		
		let order_date_from = jQuery('#order_date_from').val();
		order_date_from = jQuery.trim(order_date_from);
		
		let order_date_to = jQuery('#order_date_to').val();
		order_date_to = jQuery.trim(order_date_to);
		
		let order_status_srch = jQuery('#order_status_srch').val();
		order_status_srch = jQuery.trim(order_status_srch);
		
		var order_sync_srch = jQuery.trim(jQuery('#order_sync_srch').val());
			if(order_push_search!='' || order_date_from!='' || order_date_to!='' || order_status_srch!='' || order_sync_srch!=''){
			window.location = '<?php echo esc_url_raw($page_url);?>&order_push_search='+order_push_search+'&order_date_from='+order_date_from+'&order_date_to='+order_date_to+'&order_status_srch='+order_status_srch+'&order_sync_srch='+order_sync_srch;
		}else{
			alert('<?php echo esc_js(__('Please enter or select search term.','myworks-sync-for-xero'))?>');
		}
	}
	
	function reset_item(){
		window.location = '<?php echo esc_url_raw($page_url);?>&order_push_search=&order_date_from=&order_date_to=&order_status_srch=&order_sync_srch=&mwxs_orderby=&mwxs_order=';
	}
	
	jQuery(document).ready(function($) {
		$('.mwqs_datepicker').css('cursor','pointer');
		$( ".mwqs_datepicker" ).datepicker(
			{ 
			dateFormat: 'yy-mm-dd',
			yearRange: "-50:+0",
			changeMonth: true,
			changeYear: true
			}
		);
		
		<?php if($total_records > 0):?>
		var item_type = 'order';
		$('#push_selected_order_btn').click(function(){
			var item_ids = '';
			var item_checked = 0;
			
			jQuery( "input[id^='order_push_']" ).each(function(){
				if(jQuery(this).is(":checked")){
					item_checked = 1;
					var only_id = jQuery(this).attr('id').replace('order_push_','');
					only_id = parseInt(only_id);
					if(only_id>0){
						item_ids+=only_id+',';
					}					
				}
			});
			
			if(item_ids!=''){
				item_ids = item_ids.substring(0, item_ids.length - 1);
			}
			
			if(item_checked==0){
				alert('<?php echo esc_js(__('Please select at least one item.','myworks-sync-for-xero'));?>');
				return false;
			}
			
			popUpWindow('<?php echo esc_url_raw($sync_window_url);?>&sync_type=push&item_ids='+item_ids+'&item_type='+item_type,'mw_xs_order_push',0,0,650,350);
			return false;
		});

		//Order sync status
		var data = {
			"action": 'myworks_wc_xero_sync_order_sync_status_list',
			"order_sync_status_list": $('#order_sync_status_list').val(),
			"order_id_num_arr":<?php echo json_encode((array) $MWXS_L->array_sanitize($order_id_num_arr));?>
		};		
		
		var loading_msg = '...';
		$('td.td_ss:empty').html(loading_msg); // only unknown (server-empty) cells; known ones are server-rendered. Refs #83
		
		$.ajax({
			type: "POST",
			url: ajaxurl,
			data: data,
			cache:  false ,
			datatype: "json",
			success: function(result){
				var w_x_o_a = JSON.parse(result);				
				if(!$.isEmptyObject(w_x_o_a)){
					$.each(w_x_o_a, function(key,val){
						if(val !== "" && !$.isEmptyObject(val)){
							//val.ID.trim()
							$('#td_ss_'+key).html('<span class="ss_pf_span">1</span><span class="x_ss"><i class="fa fa-check-circle" style="color:green"></i></span>');
							//$('#order_push_'+key).attr("disabled", true);
						}else{
							$('#td_ss_'+key).html('<span class="ss_pf_span">0</span><span class="x_ss"><i class="fa fa-times-circle" style="color:red"></i></span>');
						}						
					});
					
					$('span.x_ss').each(function(i) {
						$(this).delay(100*i).fadeIn(100);
					});
				}
			},
			error: function(result) {
				$('td.td_ss:empty').html('<span class="ss_pf_span">2</span>!');
			}
		});
		<?php endif;?>
	});
</script>