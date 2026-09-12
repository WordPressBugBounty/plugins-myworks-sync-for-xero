<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin interface requires direct queries for map/log/queue tables
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// Reason: Variables in this partial template are local scope passed from parent, not globals

if(!defined( 'ABSPATH' )){

/**
 * Partial template
 *
 * Variables in scope:
 * @var mixed $MWXS_L Library singleton instance
 * @var mixed $wpdb WordPress database object
 * @var mixed $page_url Current page URL
 * @var mixed $table Database table name
 * @var mixed $items_per_page Items per page for pagination
 * @var mixed $total_records Total records count
 * @var mixed $offset Pagination offset
 */
	exit;
}

global $MWXS_L;
global $wpdb;

$page_url = admin_url('admin.php?page=myworks-wc-xero-sync-log');

$table = $MWXS_L->gdtn('log');

#Delete
$del_log_id = (int) $MWXS_L->var_g('del_log',0);
if($del_log_id > 0){
	$wpdb->query($wpdb->prepare("DELETE FROM `" . esc_sql($table) . "` WHERE `id` = %d", $del_log_id));
	// Clear log count cache
	delete_transient('mwxs_log_count_' . md5(''));
	$MWXS_L->redirect($page_url);
}

# Data Listing / Search
$MWXS_L->set_per_page_from_url();
$items_per_page = $MWXS_L->get_item_per_page();

$MWXS_L->set_and_get('log_search');
$log_search = $MWXS_L->get_session_val('log_search');

$log_search = $MWXS_L->sanitize($log_search);

// Get total count with proper prepared statement and caching
$cache_key = 'mwxs_log_count_' . md5($log_search);
$total_records = get_transient($cache_key);

if (false === $total_records) {
	if(!empty($log_search)){
		$search_like = '%' . $wpdb->esc_like($log_search) . '%';
		$total_records = $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM `" . esc_sql($table) . "` WHERE `id` > 0 AND (`details` LIKE %s OR `log_type` LIKE %s OR `log_title` LIKE %s)",
			$search_like,
			$search_like,
			$search_like
		));
	} else {
		$total_records = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `" . esc_sql($table) . "` WHERE `id` > 0"));
	}
	// Cache for 5 minutes
	set_transient($cache_key, $total_records, 5 * MINUTE_IN_SECONDS);
}

$page = $MWXS_L->get_page_var();
$offset = ( $page * $items_per_page ) - $items_per_page;

// Get list data with proper prepared statement
if(!empty($log_search)){
	$search_like = '%' . $wpdb->esc_like($log_search) . '%';
	$log_q = $wpdb->prepare(
		"SELECT * FROM `" . esc_sql($table) . "` WHERE `id` > 0 AND (`details` LIKE %s OR `log_type` LIKE %s OR `log_title` LIKE %s) ORDER BY `id` DESC LIMIT %d, %d",
		$search_like,
		$search_like,
		$search_like,
		$offset,
		$items_per_page
	);
} else {
	$log_q = $wpdb->prepare(
		"SELECT * FROM `" . esc_sql($table) . "` WHERE `id` > 0 ORDER BY `id` DESC LIMIT %d, %d",
		$offset,
		$items_per_page
	);
}
$log_data = $MWXS_L->get_data($log_q);

// Issue #362: Intercom "ask for help" icon only when configured.
$intercom_enabled = (bool) $MWXS_L->get_option('mw_wc_xero_intercom_app_id');
$sync_window_url  = $MWXS_L->get_sync_window_url();
?>

<br><br>
<div class="container log-outr-sec mq_lp_cont">
	<div class="page_title"><h4><?php esc_html_e( 'Sync Log', 'myworks-sync-for-xero' );?></h4></div>
	<div class="mw_wc_filter">
		<input placeholder="Search Log" type="text" id="log_search" value="<?php echo esc_attr($log_search);?>">
		
		<?php myworks_woo_sync_for_xero_filter_reset_show_entries_html($page_url,$items_per_page);?>
	</div>
	
	<br>
	
	<div class="mq_lp_cdt">
		<?php esc_html_e( 'Current Datetime', 'myworks-sync-for-xero' );?>: <?php echo esc_html($MWXS_L->now('Y-m-d '));?> 
		<b><?php echo esc_html($MWXS_L->now('h:i:s A'));?></b>
	</div>
	
	<br>
	
	<div class="myworks-wc-qbo-sync-table-responsive">
		<table class="wp-list-table widefat fixed striped posts  menu-blue-bg">
			<thead>
				<tr>
					<th width="32%">&nbsp;</th>
					<th width="40%">Message</th>
					<th width="12%">Date</th>
					<th style="text-align:center;" width="16%">Action</th>
				</tr>
			</thead>
			
			<tbody id="mwqs-log-list">
				<?php if(is_array($log_data) && !empty($log_data)):?>
				<?php foreach($log_data as $data):?>
				
				<?php
					$is_log_error    = (!$data['status'] || $data['status'] < 1) ? true : false;
					$ls_class        = ($is_log_error) ? ' cl_err' : '';
					$details         = $MWXS_L->get_log_page_details_field_data_formatted($data);

					// Issue #362: resolve WC view link and retry target from wc_id.
					$wc_view_url     = '';
					$wc_view_label   = '';
					$retry_item_type = '';
					$retry_id        = 0;
					$_log_type       = (string)($data['log_type'] ?? '');
					$_log_title      = (string)($data['log_title'] ?? '');
					$_wc_id          = (int)($data['wc_id'] ?? 0);

					// Fallback: parse WC ID from log title when not stored. New Order/Payment error rows now
					// persist wc_id (see X_Add_Update_Invoice/X_Add_Payment), so this only carries historic rows.
					// Order/Payment titles are built from the order *number*, which is NOT the post ID once a
					// custom or sequential order number is in play — trusting it blindly could point Retry at an
					// unrelated order and fabricate an invoice. Only accept the parsed value when the candidate
					// order's own number matches it. Refs #362
					if($_wc_id === 0 && !empty($_log_title) && preg_match('/#(\d+)/', $_log_title, $_tm)){
						$_parsed = (int)$_tm[1];
						if($_parsed > 0){
							if($_log_type === 'Order' || $_log_type === 'Payment'){
								$_cand = wc_get_order($_parsed);
								if($_cand && is_a($_cand,'WC_Order') && (string)$_cand->get_order_number() === (string)$_parsed){
									$_wc_id = $_parsed;
								}
							}else{
								// Customer/Product/Variation titles carry the WC object ID directly.
								$_wc_id = $_parsed;
							}
						}
					}
					// Inventory titles contain a Xero SKU (#ABC...), not a numeric WC ID — parse from details instead.
					if($_wc_id === 0 && $_log_type === 'Inventory' && !empty($data['details']) && preg_match('/#(\d+)/', $data['details'], $_dm)){
						$_wc_id = (int)$_dm[1];
					}

					if($_wc_id > 0){
						switch($_log_type){
							case 'Order':
								$_ord = wc_get_order($_wc_id);
								if($_ord && is_a($_ord,'WC_Order')){
									$wc_view_url     = $_ord->get_edit_order_url();
									$wc_view_label   = 'WooCommerce Order';
									$retry_item_type = 'order';
									$retry_id        = $_wc_id;
								}
								break;
							case 'Payment':
								$_ord = wc_get_order($_wc_id);
								if($_ord && is_a($_ord,'WC_Order')){
									$wc_view_url   = $_ord->get_edit_order_url();
									$wc_view_label = 'WooCommerce Order';
									// No retry for payments.
								}
								break;
							case 'Customer':
								if(get_user_by('id',$_wc_id)){
									$wc_view_url     = get_edit_user_link($_wc_id);
									$wc_view_label   = 'WooCommerce Customer';
									$retry_item_type = 'customer';
									$retry_id        = $_wc_id;
								}
								break;
							case 'Product':
								$_post = get_post($_wc_id);
								if($_post && $_post->post_type === 'product'){
									$wc_view_url     = get_edit_post_link($_wc_id,'');
									$wc_view_label   = 'WooCommerce Product';
									$retry_item_type = 'product';
									$retry_id        = $_wc_id;
								}
								break;
							case 'Inventory':
								$_post = get_post($_wc_id);
								if($_post && in_array($_post->post_type, array('product','product_variation'), true)){
									$_link_id      = ($_post->post_type === 'product_variation') ? ($_post->post_parent ?: $_wc_id) : $_wc_id;
									$wc_view_url   = get_edit_post_link($_link_id,'');
									$wc_view_label = 'WooCommerce Product';
									// No retry for inventory.
								}
								break;
							case 'Variation':
								$_post = get_post($_wc_id);
								if($_post && $_post->post_type === 'product_variation'){
									$_pid            = $_post->post_parent ?: $_wc_id;
									$wc_view_url     = get_edit_post_link($_pid,'');
									$wc_view_label   = 'WooCommerce Product';
									$retry_item_type = 'variation';
									$retry_id        = $_wc_id;
								}
								break;
						}
					}
				?>

				<tr>
					<td>
						<h4 class="mq_lp_lth"><?php echo esc_html($data['log_type'])?></h4>
						<div class="mq_lp_tbd<?php echo esc_attr($ls_class);?>">
							<?php echo esc_html(stripslashes($data['log_title']));?>
						</div>
					</td>

					<td <?php echo ($is_log_error)?' style="color:#dd281a;"':'';?>>
						<?php
							echo wp_kses(str_replace(
								['{LPDW_S}','{LPDX_S}','{LPDW_E}','{LPDX_E}','{LB}'],
								['<span class="lm_wid">','<span class="lm_qid">','</span>','</span>','<br>'],
								esc_html(stripcslashes($details))
							), ['span' => ['class' => []], 'br' => []]);
						?>
					</td>

					<td>
						<span class="mq_lp_ltime"><?php echo esc_html(gmdate('h:i:s A',strtotime($data['added_date'])));?></span>
						<span><?php echo esc_html(gmdate('Y-m-d',strtotime($data['added_date'])));?></span>
					</td>

					<td style="text-align:center;" class="mqls-action-cell">
						<div class="mqls-actions">
						<?php if(!empty($wc_view_url)):?>
						<a class="lg_qb_view mqls-wc mqls-logo" target="_blank" href="<?php echo esc_url($wc_view_url);?>" title="<?php echo esc_attr('View ' . $wc_view_label);?>" aria-label="<?php echo esc_attr('View ' . $wc_view_label);?>"><img src="<?php echo esc_url(plugins_url('myworks-sync-for-xero/admin/image/woocommerce-logo.svg'));?>" alt="<?php echo esc_attr('View ' . $wc_view_label);?>" width="25" height="25" style="width:25px;height:25px;display:block;" /></a>
						<?php endif;?>
						<?php $MWXS_L->get_log_page_view_in_xero_link($data);?>
						<?php if($is_log_error && !empty($retry_item_type) && !empty($retry_id)):?>
						<a class="lg_qb_view mqls-retry" href="javascript:void(0);" title="<?php echo esc_attr__('Retry sync','myworks-sync-for-xero');?>" aria-label="<?php echo esc_attr__('Retry sync','myworks-sync-for-xero');?>" onclick="mqlsXeroRetry('<?php echo esc_js($retry_item_type);?>',<?php echo (int)$retry_id;?>);"><span class="dashicons dashicons-update"></span></a>
						<?php endif;?>
						<?php if($is_log_error && $intercom_enabled):
							// Details are stored with literal \n separators (rendered as {LB} -> <br> in the
							// table cell); replace them before stripslashes(), which would otherwise eat the
							// backslash and leave a stray "n" glued between the lines. Refs #362
							$help_err_msg = wp_strip_all_tags( stripslashes( str_replace( '\n', ' ', $data['details'] ?? '' ) ) );
							$help_err_msg = function_exists( 'mb_substr' ) ? mb_substr( $help_err_msg, 0, 300 ) : substr( $help_err_msg, 0, 300 );
						?>
						<a class="lg_qb_view mqls-help" href="javascript:void(0);" title="<?php echo esc_attr__('Ask MyWorks about this error','myworks-sync-for-xero');?>" aria-label="<?php echo esc_attr__('Ask MyWorks about this error','myworks-sync-for-xero');?>" onclick="mqlsXeroHelp('<?php echo esc_js($_log_type);?>',<?php echo esc_attr(wp_json_encode($help_err_msg));?>,<?php echo esc_attr(wp_json_encode(wp_strip_all_tags(stripslashes($data['log_title'] ?? ''))));?>);"><span class="dashicons dashicons-editor-help"></span></a>
						<?php endif;?>
						</div>
					</td>
				</tr>
				
				<?php endforeach;?>
				<?php else:?>
				
				<tr>
					<td colspan="4">
						<span class="mwxs_tnd">
							<?php esc_html_e( 'No logs found.', 'myworks-sync-for-xero' );?>
						</span>
					</td>
				</tr>
				
				<?php endif;?>
			</tbody>
		</table>
	</div>
	<?php $MWXS_L->get_paginate_links($total_records,$items_per_page);?>
	
	<?php if($total_records > 0):?>
	<br>
	<div>
		<?php wp_nonce_field( 'myworks_wc_xero_sync_clear_all_logs', 'clear_all_logs' ); ?>
		<button id="mwqs_clear_all_logs_btn"><?php esc_html_e( 'Clear Entire Log', 'myworks-sync-for-xero' );?></button>
		&nbsp;
		<?php wp_nonce_field( 'myworks_wc_xero_sync_clear_all_log_errors', 'clear_all_log_errors' ); ?>
		<button id="mwqs_clear_all_log_errors_btn"><?php esc_html_e( 'Clear Error Logs', 'myworks-sync-for-xero' );?></button>
	</div>
	<?php endif;?>
</div>

<script type="text/javascript">
	// Issue #362: retry a failed sync via the sync-window popup.
	var mqlsXeroSyncUrl = '<?php echo esc_js($sync_window_url);?>';
	function mqlsXeroRetry(itemType, id){
		window.open(mqlsXeroSyncUrl + '&sync_type=push&item_ids=' + parseInt(id, 10) + '&item_type=' + encodeURIComponent(itemType), 'mwxs_retry', 'width=650,height=350,scrollbars=yes,resizable=yes');
	}
	// Issue #362: open Intercom messenger pre-filled with error context.
	function mqlsXeroHelp(logType, errMsg, logTitle){
		var msg = (logTitle ? logTitle + ': ' : '') + "I'm having trouble syncing a " + logType + " with the error: " + errMsg;
		if(typeof window.Intercom === 'function'){
			window.Intercom('showNewMessage', msg);
		}else{
			alert('<?php echo esc_js(__('Live chat is unavailable right now. Please contact MyWorks support.','myworks-sync-for-xero')); ?>');
		}
	}

	function search_item(){
		let log_search = jQuery('#log_search').val();
		log_search = jQuery.trim(log_search);
		
		if(log_search!=''){
			window.location = '<?php echo esc_url_raw($page_url);?>&log_search='+log_search;
		}else{
			alert('<?php echo esc_js(__('Please enter search keyword.','myworks-sync-for-xero'))?>');
		}
	}
	
	function reset_item(){		
		window.location = '<?php echo esc_url_raw($page_url);?>&log_search=';
	}
	
	<?php if($total_records > 0):?>
	jQuery(document).ready(function($){		
		$('#mwqs_clear_all_logs_btn').click(function(){
			if(confirm('<?php echo esc_js(__('This will clear all log entries. OK to proceed?','myworks-sync-for-xero'))?>')){
				var data = {
					"action": 'myworks_wc_xero_sync_clear_all_logs',
					"clear_all_logs": $('#clear_all_logs').val(),
				};
				
				var btn_text = $(this).html();
				var loading_msg = 'Loading...';
				$(this).html(loading_msg);
				
				$.ajax({
				   type: "POST",
				   url: ajaxurl,
				   data: data,
				   cache:  false ,
				   //datatype: "json",
				   success: function(result){
					   $('#mwqs_clear_all_logs_btn').html(btn_text);
					   if(result!=0 && result!=''){						 
						 window.location='<?php echo esc_url_raw($page_url);?>';
					   }else{
						 alert('Error!');			 
					   }					   	
				   },
				   error: function(result) {
						$('#mwqs_clear_all_logs_btn').html(btn_text);
						alert('Error!');					
				   }
				});
			}
		});
		
		$('#mwqs_clear_all_log_errors_btn').click(function(){			
			if(confirm('<?php echo esc_js(__('This will clear all error log entries. OK to proceed?','myworks-sync-for-xero'))?>')){
				var data = {
					"action": 'myworks_wc_xero_sync_clear_all_log_errors',
					"clear_all_log_errors": $('#clear_all_log_errors').val(),
				};
				
				var btn_text = $(this).html();				
				var loading_msg = 'Loading...';
				$(this).html(loading_msg);
				
				$.ajax({
				   type: "POST",
				   url: ajaxurl,
				   data: data,
				   cache:  false ,
				   //datatype: "json",
				   success: function(result){
					    $('#mwqs_clear_all_log_errors_btn').html(btn_text);
					   if(result!=0 && result!=''){						 
						 window.location='<?php echo esc_url_raw($page_url);?>';
					   }else{
						 alert('Error!');			 
					   }				  
				   },
				   error: function(result) {
						$('#mwqs_clear_all_log_errors_btn').html(btn_text);
						alert('Error!');					
				   }
				});
			}
		});
	});
	<?php endif;?>
</script>