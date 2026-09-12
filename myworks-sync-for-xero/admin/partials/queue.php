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

$page_url = admin_url('admin.php?page=myworks-wc-xero-sync-queue');

// Security: Use validated table name
$table = $MWXS_L->get_validated_table_name('queue');

$del_queue_id = (int) $MWXS_L->var_g('del_queue',0);
if($del_queue_id > 0 && $table){
	$wpdb->query($wpdb->prepare("DELETE FROM `" . esc_sql($table) . "` WHERE `id` = %d", $del_queue_id));
	// Clear queue count cache
	delete_transient('mwxs_queue_count_' . md5('Pending' . '' . serialize(array())));
	$MWXS_L->redirect($page_url);
}

# Data Listing / Search
$MWXS_L->set_per_page_from_url();
$items_per_page = $MWXS_L->get_item_per_page();

$MWXS_L->set_and_get('queue_search');
$queue_search = $MWXS_L->get_session_val('queue_search');
$queue_search = $MWXS_L->sanitize($queue_search);

$MWXS_L->set_and_get('queue_st_search');
$queue_st_search = $MWXS_L->get_session_val('queue_st_search');
$queue_st_search = $MWXS_L->sanitize($queue_st_search);

if(empty($queue_st_search)){
	$queue_st_search = 'Pending';
}

// Security: Use validated table and proper prepared statements
if ($table) {
	// Get total count with proper prepared statement and caching
	$cache_key = 'mwxs_queue_count_' . md5($queue_st_search . $queue_search);
	$total_records = get_transient($cache_key);

	if (false === $total_records) {
		// Build query based on status and search conditions
		if($queue_st_search == 'Pending'){
			if(!empty($queue_search)){
				$search_like = '%' . $wpdb->esc_like($queue_search) . '%';
				$total_records = $wpdb->get_var($wpdb->prepare(
					"SELECT COUNT(*) FROM `" . esc_sql($table) . "` WHERE `id` > 0 AND `run` = 0 AND (`item_type` LIKE %s OR `item_action` LIKE %s OR `item_id` = %s)",
					$search_like,
					$search_like,
					$queue_search
				));
			} else {
				$total_records = $wpdb->get_var($wpdb->prepare(
					"SELECT COUNT(*) FROM `" . esc_sql($table) . "` WHERE `id` > 0 AND `run` = 0"
				));
			}
		} elseif($queue_st_search == 'Previous'){
			if(!empty($queue_search)){
				$search_like = '%' . $wpdb->esc_like($queue_search) . '%';
				$total_records = $wpdb->get_var($wpdb->prepare(
					"SELECT COUNT(*) FROM `" . esc_sql($table) . "` WHERE `id` > 0 AND `run` = 1 AND (`item_type` LIKE %s OR `item_action` LIKE %s OR `item_id` = %s)",
					$search_like,
					$search_like,
					$queue_search
				));
			} else {
				$total_records = $wpdb->get_var($wpdb->prepare(
					"SELECT COUNT(*) FROM `" . esc_sql($table) . "` WHERE `id` > 0 AND `run` = 1"
				));
			}
		} else {
			// All
			if(!empty($queue_search)){
				$search_like = '%' . $wpdb->esc_like($queue_search) . '%';
				$total_records = $wpdb->get_var($wpdb->prepare(
					"SELECT COUNT(*) FROM `" . esc_sql($table) . "` WHERE `id` > 0 AND (`item_type` LIKE %s OR `item_action` LIKE %s OR `item_id` = %s)",
					$search_like,
					$search_like,
					$queue_search
				));
			} else {
				$total_records = $wpdb->get_var($wpdb->prepare(
					"SELECT COUNT(*) FROM `" . esc_sql($table) . "` WHERE `id` > 0"
				));
			}
		}
		// Cache for 5 minutes
		set_transient($cache_key, $total_records, 5 * MINUTE_IN_SECONDS);
	}

	$page = $MWXS_L->get_page_var();
	$offset = ( $page * $items_per_page ) - $items_per_page;

	// Get list data with proper prepared statement
	if($queue_st_search == 'Pending'){
		if(!empty($queue_search)){
			$search_like = '%' . $wpdb->esc_like($queue_search) . '%';
			$queue_q = $wpdb->prepare(
				"SELECT * FROM `" . esc_sql($table) . "` WHERE `id` > 0 AND `run` = 0 AND (`item_type` LIKE %s OR `item_action` LIKE %s OR `item_id` = %s) ORDER BY `id` DESC LIMIT %d, %d",
				$search_like,
				$search_like,
				$queue_search,
				$offset,
				$items_per_page
			);
		} else {
			$queue_q = $wpdb->prepare(
				"SELECT * FROM `" . esc_sql($table) . "` WHERE `id` > 0 AND `run` = 0 ORDER BY `id` DESC LIMIT %d, %d",
				$offset,
				$items_per_page
			);
		}
	} elseif($queue_st_search == 'Previous'){
		if(!empty($queue_search)){
			$search_like = '%' . $wpdb->esc_like($queue_search) . '%';
			$queue_q = $wpdb->prepare(
				"SELECT * FROM `" . esc_sql($table) . "` WHERE `id` > 0 AND `run` = 1 AND (`item_type` LIKE %s OR `item_action` LIKE %s OR `item_id` = %s) ORDER BY `id` DESC LIMIT %d, %d",
				$search_like,
				$search_like,
				$queue_search,
				$offset,
				$items_per_page
			);
		} else {
			$queue_q = $wpdb->prepare(
				"SELECT * FROM `" . esc_sql($table) . "` WHERE `id` > 0 AND `run` = 1 ORDER BY `id` DESC LIMIT %d, %d",
				$offset,
				$items_per_page
			);
		}
	} else {
		// All
		if(!empty($queue_search)){
			$search_like = '%' . $wpdb->esc_like($queue_search) . '%';
			$queue_q = $wpdb->prepare(
				"SELECT * FROM `" . esc_sql($table) . "` WHERE `id` > 0 AND (`item_type` LIKE %s OR `item_action` LIKE %s OR `item_id` = %s) ORDER BY `id` DESC LIMIT %d, %d",
				$search_like,
				$search_like,
				$queue_search,
				$offset,
				$items_per_page
			);
		} else {
			$queue_q = $wpdb->prepare(
				"SELECT * FROM `" . esc_sql($table) . "` WHERE `id` > 0 ORDER BY `id` DESC LIMIT %d, %d",
				$offset,
				$items_per_page
			);
		}
	}
} else {
	$total_records = 0;
	$queue_q = "";
}

$queue_data = $MWXS_L->get_data($queue_q);

# Queue cron countdown
$show_countdown = false;

$cdt = gmdate('Y-m-d H:i:s');
$next_queue_cron_run = wp_next_scheduled('mw_wc_xero_sync_queue_cron_hook');

if(!empty($next_queue_cron_run)){
	$show_countdown = true;

	$s_ncrt_cdt_diff = $next_queue_cron_run-strtotime($cdt);

	$next_queue_cron_run = gmdate('Y-m-d H:i:s',$next_queue_cron_run);
	$start_date = new DateTime($cdt);
	$since_start = $start_date->diff(new DateTime($next_queue_cron_run));

	$min_d = $since_start->i;
	$min_s = $since_start->s;

	$qcit = $MWXS_L->get_option('mw_wc_xero_sync_queue_cron_interval_time');
	if(empty($qcit)){$qcit = 'MWXS_5min';}

	$ncrt_int = str_replace(array('MWXS_','min'),'',$qcit);
	$ncrt_int = (int) $ncrt_int;

	if($ncrt_int < 5){
		$ncrt_int = 5;
	}

	$ncrt_int = $ncrt_int*60;
}

?>

<br><br>
<div class="container queue-outr-sec">
	<div class="page_title"><h4><?php esc_html_e( 'Queue', 'myworks-sync-for-xero' );?></h4></div>
	<div class="mw_wc_filter">
		<input placeholder="Search Queue" type="text" id="queue_search" value="<?php echo esc_attr($queue_search);?>">
		&nbsp;

		<select id="queue_st_search" style="width:130px !important;">
			<?php 
				$MWXS_L->only_option(
					$queue_st_search,
					array('Pending'=>'Pending','Previous'=>'Previous','All'=>'All')
				);
			?>
		</select>
		
		<?php myworks_woo_sync_for_xero_filter_reset_show_entries_html($page_url,$items_per_page);?>
	</div>
	
	<br>
	
	<?php if($show_countdown):?>
	<div id="mwqs_q_ncr_tdv">
		<h3 style="text-align:center"><?php echo esc_html($min_d);?> min, <?php echo esc_html($min_s);?> sec</h3>
		<p style="text-align:center; margin-top:-20px;">until next queue sync</p>
	</div>
	<?php endif;?>
	
	<div class="myworks-wc-qbo-sync-table-responsive">
		<table class="wp-list-table widefat fixed striped posts  menu-blue-bg">
			<thead>
				<tr>
					<th width="10%">#</th>
					<th width="25%">Item Type</th>
					<th width="15%">Item Action</th>
					<th width="25%">Item ID</th>
					<th width="15%">Added</th>
					<th style="text-align:center;" width="10%">Action</th>
				</tr>
			</thead>
			
			<tbody id="mwqs-queue-list">
				<?php if(is_array($queue_data) && !empty($queue_data)):?>
				<?php foreach($queue_data as $data):?>
							
				<tr <?php echo ($data['run'] == 1)?' style="opacity:0.5;"':'';?>>
					<td><?php echo (int) $data['id'];?></td>
					<td><?php echo esc_html($data['item_type'])?></td>
					<td><?php echo esc_html($data['item_action'])?></td>
					<td><?php echo (!empty($data['xero_id']))?esc_html($data['xero_id']):esc_html($data['item_id'])?></td>		
					<td><?php echo esc_html($data['added_date'])?></td>
					<td style="text-align:center;">
						<a class="mwqslld_btn" title="Delete" href="javascript:void(0);" onclick="javascript:if(confirm('<?php echo esc_js(__('Are you sure, you want to delete this!','myworks-sync-for-xero'))?>')){window.location='<?php echo esc_url_raw($page_url);?>&del_queue=<?php echo (int) $data['id']?>';}">x</a>
					</td>

				</tr>

				<?php endforeach;?>
				<?php else:?>
					
				<tr>
					<td colspan="6">
						<span class="mwxs_tnd">
							<?php esc_html_e( 'No queue entries found.', 'myworks-sync-for-xero' );?>
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
		<?php echo esc_html(str_repeat('&nbsp;',3));?>
		<?php wp_nonce_field( 'myworks_wc_xero_sync_clear_all_pending_queues', 'clear_all_pending_queues' ); ?>		
		<button id="mwqs_clear_all_pending_queues_btn" class="mwxsb-s"><?php esc_html_e( 'Clear all pending queues', 'myworks-sync-for-xero' );?></button>
		&nbsp;
		
		<?php wp_nonce_field( 'myworks_wc_xero_sync_clear_all_queues', 'clear_all_queues' ); ?>		
		<button id="mwqs_clear_all_queues_btn" class="mwxsb-b"><?php esc_html_e( 'Clear all queues', 'myworks-sync-for-xero' );?></button>
	</div>
	<br>
	<br>
	<?php endif;?>
</div>

<script type="text/javascript">
	function search_item(){		
		let queue_search = jQuery('#queue_search').val();
		queue_search = jQuery.trim(queue_search);
		
		let queue_st_search = jQuery('#queue_st_search').val();
		queue_st_search = jQuery.trim(queue_st_search);
		
		let q_st_s_sv = '<?php echo esc_js($queue_st_search);?>';
		if(queue_search == '' && queue_st_search == 'Pending' && q_st_s_sv == 'Pending'){			
			queue_st_search = '';
		}
		
		if(queue_search!='' || queue_st_search!=''){
			window.location = '<?php echo esc_url_raw($page_url);?>&queue_search='+queue_search+'&queue_st_search='+queue_st_search;
		}else{
			alert('<?php echo esc_js(__('Please enter or select search term.','myworks-sync-for-xero'))?>');
		}
	}

	function reset_item(){		
		window.location = '<?php echo esc_url_raw($page_url);?>&queue_search=&queue_st_search=';
	}

	function mwxs_queue_cron_counter(duration){
		var timer = duration, minutes, seconds;
		var x = setInterval(function() {
			minutes = parseInt(timer / 60, 10);
			seconds = parseInt(timer % 60, 10);
			
			if(seconds>=0){
				document.getElementById("mwqs_q_ncr_tdv").innerHTML = '<h3 style="text-align:center">'+minutes+' min, '+seconds+' sec</h3><p style="text-align:center; margin-top:-20px;">until next queue sync</p>';
			}			

			if (--timer < 0) {
				timer = '<?php echo esc_js($ncrt_int);?>';
			}						
		}, 1000);
	}	
	
	jQuery(document).ready(function($){
		<?php if($total_records > 0):?>		
		$('#mwqs_clear_all_pending_queues_btn').click(function(){
			if(confirm('<?php echo esc_js(__('This will clear all pending queue entries. OK to proceed?','myworks-sync-for-xero'))?>')){
				var data = {
					"action": 'myworks_wc_xero_sync_clear_all_pending_queues',
					"clear_all_pending_queues": $('#clear_all_pending_queues').val(),
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
					   $('#mwqs_clear_all_pending_queues_btn').html(btn_text);
					   if(result!=0 && result!=''){						 
						 window.location='<?php echo esc_url_raw($page_url);?>';
					   }else{
						 alert('Error!');			 
					   }					   	
				   },
				   error: function(result) {
						$('#mwqs_clear_all_pending_queues_btn').html(btn_text);
						alert('Error!');					
				   }
				});
			}
		});
		
		$('#mwqs_clear_all_queues_btn').click(function(){
			if(confirm('<?php echo esc_js(__('This will clear all queue entries. OK to proceed?','myworks-sync-for-xero'))?>')){
				var data = {
					"action": 'myworks_wc_xero_sync_clear_all_queues',
					"clear_all_queues": $('#clear_all_queues').val(),
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
					    $('#mwqs_clear_all_queues_btn').html(btn_text);
					   if(result!=0 && result!=''){						 
						 window.location='<?php echo esc_url_raw($page_url);?>';
					   }else{
						 alert('Error!');			 
					   }				  
				   },
				   error: function(result) {
						$('#mwqs_clear_all_queues_btn').html(btn_text);
						alert('Error!');					
				   }
				});
			}
		});
		<?php endif;?>

		<?php if($show_countdown):?>
		mwxs_queue_cron_counter('<?php echo esc_js($s_ncrt_cdt_diff);?>');
		<?php endif;?>
	});	
</script>