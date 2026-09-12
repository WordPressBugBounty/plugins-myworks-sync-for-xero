<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// Reason: MWXS is the established plugin prefix (short form of MyWorks Xero Sync)

/*Plugin admin pages html css js functions*/

# Woocommerce admin pages footer content
if(!function_exists('myworks_woo_sync_for_xero_wc_admin_pages_footer_content')){
	function myworks_woo_sync_for_xero_wc_admin_pages_footer_content($data=array()){
		global $MWXS_L;

		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		$is_orders_screen = ($screen && in_array($screen->id, array('woocommerce_page_wc-orders','edit-shop_order'), true));

		# Unknown orders queued by the column render for a one-time backfill.
		$wc_order_id_num_list = $MWXS_L->get_session_val('wc_order_id_num_list',array(),true);
		$has_pending = (is_array($wc_order_id_num_list) && !empty($wc_order_id_num_list));

		if(!$is_orders_screen && !$has_pending){
			return;
		}

		wp_nonce_field( 'myworks_wc_xero_sync_order_sync_status_list', 'order_sync_status_list' );
		// Keep these unescaped here and escape at the point of output below — escaping late is both
		// the WordPress rule and what lets the security scan verify it (it can't follow escaping
		// through a variable, and esc_attr() applied twice would double-encode entities).
		$pending_data  = $has_pending ? (array) $MWXS_L->array_sanitize($wc_order_id_num_list) : array();
		$refresh_title = __('Refresh Xero statuses','myworks-sync-for-xero');
		?>
		<script>
		jQuery(document).ready(function($){
			var mwxsNonce = $("#order_sync_status_list").val();

			function mwxsRepaintXeroStatus(w_x_o_a){
				if(!w_x_o_a || $.isEmptyObject(w_x_o_a)){ return; }
				$.each(w_x_o_a, function(key,val){
					var html;
					if(val !== "" && !$.isEmptyObject(val)){
						var link = (val && val.Xero_Link) ? val.Xero_Link : "";
						var inner = link ? ("<a style='color:white;' target='_blank' href='"+link+"'><span title='Click to view it in Xero'>Synced</span></a>") : "Synced";
						html = '<span class="x_ss" style="display:inline;"><span class="mw_qbo_sync_status_span mw_qbo_sync_status_paid">'+inner+'</span></span>';
					}else{
						html = '<span class="x_ss" style="display:inline;"><span class="mw_qbo_sync_status_span mw_qbo_sync_status_due">Not Synced</span></span>';
					}
					$("#c_xs_"+key).html(html);
				});
			}

			<?php // JSON_HEX_TAG et al so a value containing "</script" can't terminate this block early. ?>
			var mwxsPending = <?php echo wp_json_encode( $pending_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>;
			if(mwxsPending && !$.isEmptyObject(mwxsPending)){
				$.ajax({
					type:"POST", url: ajaxurl, cache:false, dataType:"json",
					data:{ action:"myworks_wc_xero_sync_order_sync_status_list", order_sync_status_list: mwxsNonce, order_id_num_arr: mwxsPending },
					success: function(result){ mwxsRepaintXeroStatus(typeof result === "string" ? JSON.parse(result) : result); },
					error: function(){ $("div.c_xs_data").html("!"); }
				});
			}

			<?php if($is_orders_screen): ?>
			var $mwxsHdr = $("th#c_xs_mwxs, th.column-c_xs_mwxs").first();
			if($mwxsHdr.length && !$mwxsHdr.find(".mwxs-refresh-status").length){
				$mwxsHdr.append(' <a href="javascript:void(0);" class="mwxs-refresh-status" title="<?php echo esc_attr( $refresh_title ); ?>">&#x21bb;</a>');
			}
			$(document).on("click",".mwxs-refresh-status",function(e){
				e.preventDefault();
				var $icon = $(this);
				var ids = [];
				$("div.c_xs_data").each(function(){
					var oid = (this.id || "").substring(5); // strip "c_xs_"
					if(oid){ ids.push(oid); $(this).html("..."); }
				});
				if(!ids.length){ return; }
				$icon.addClass("mwxs-spinning"); // spin until the re-check finishes (header icon persists)
				$.ajax({
					type:"POST", url: ajaxurl, cache:false, dataType:"json",
					data:{ action:"myworks_wc_xero_sync_order_sync_status_list", order_sync_status_list: mwxsNonce, force:1, order_ids: ids },
					success: function(result){ mwxsRepaintXeroStatus(typeof result === "string" ? JSON.parse(result) : result); },
					error: function(){ $("div.c_xs_data").html("!"); },
					complete: function(){ $icon.removeClass("mwxs-spinning"); }
				});
			});
			<?php endif; ?>
		});
		</script>
		<?php
	}
}

# Hide wp notices in our plugin pages
if(!function_exists('myworks_woo_sync_for_xero_hide_wp_notices')){
	function myworks_woo_sync_for_xero_hide_wp_notices(){
		// Add body class for CSS targeting (styles in myworks-sync-for-xero-admin-inline.css)
		echo '<script>document.body.classList.add("myworks-hide-wp-notices");</script>';
	}
}

# Select 2
if(!function_exists('myworks_woo_sync_for_xero_get_select2_js')){
	function myworks_woo_sync_for_xero_get_select2_js($item='select',$d_item='',$prevent_lib_load=false){
		global $MWXS_L;
		$item = $MWXS_L->sanitize($item);
		if(empty($item)){
			return '';
		}
		
		$is_ajax_dd = 0;
		if($MWXS_L->is_s2_ajax_dd()){
			$is_ajax_dd = 1;
		}
		
		$json_data_url = '';
		if($d_item=='xero_product'){
			$json_data_url = site_url('index.php?mw_xero_sync_public_api=1&t=get_json_item_list&item=xero_product');
		}
		
		if($d_item=='xero_customer'){
			$json_data_url = site_url('index.php?mw_xero_sync_public_api=1&t=get_json_item_list&item=xero_customer');
		}
		
		$s2_lib = '';
		if(!$prevent_lib_load){		
			# Lib Not needed here
		}
		
		echo '
		<script type="text/javascript">
			jQuery(document).ready(function(){
				jQuery(\''.esc_js($item).'\').addClass(\'mwqs_s2\');
			});

			jQuery(function($){
				var is_ajax_dd = '.esc_js($is_ajax_dd).';
				jQuery(\''.esc_js($item).'\').each(function(){
					var s2_width = jQuery(this).closest(\'td\').length ? \'100%\' : \'resolve\';
					if(jQuery(this).prop(\'multiple\')){
						jQuery(this).select2({width: s2_width});
						jQuery(this).removeClass(\'mwqs_s2\');
					}else if(jQuery(this).hasClass(\'mwqs_dynamic_select\') && is_ajax_dd==1){
						jQuery(this).select2({
							width: s2_width,
							ajax: {
							  url: "'.esc_url_raw($json_data_url).'",
							  dataType: \'json\',
							  delay: 250,
							  data: function (params) {
								  return {
									  q: params.term // search term
								  };
							  },
							  processResults: function (data) {
								  return {
									  results: data
								  };
							  },
							  cache: true
						  },
						  minimumInputLength: 3
						});
					}else{
						jQuery(this).select2({width: s2_width});
					}
					jQuery(this).removeClass(\'mwqs_s2\');
				});

				// Select2 first-child padding style moved to myworks-sync-for-xero-admin-inline.css

			});
		</script>
		';		
	}
}

# Sweet Alert
if(!function_exists('myworks_woo_sync_for_xero_set_admin_sweet_alert')){
	function myworks_woo_sync_for_xero_set_admin_sweet_alert($save_status=''){
		if($save_status){
			global $MWXS_L;
			if($save_status=='admin-success-green'){
				echo '<script>swal("Rock On!", "Your settings have been saved.", "success")</script>';
			}elseif($save_status=='red lighten-2'){
				echo '<script>swal("Oops!", "Hmmmm something went wrong.", "error")</script>';
			}elseif($save_status!='admin-success-green' && $save_status!='red lighten-2' && $save_status!='error'){
				echo '<script>swal("Rock On!", "'.esc_js($save_status).'", "success")</script>';
			}else{
				echo '<script>swal("Oops!", "Hmmmm something went wrong.", "error")</script>';
			}
			echo '<script type="text/javascript">
			jQuery(document).ready(function(e){
				jQuery(".confirm").on("click",function(e){
					jQuery(".sweet-overlay").hide();
					jQuery(".showSweetAlert").hide();
					jQuery("body").removeClass("stop-scrolling");
				});
			});
			</script>';
		}
	}
}

# Tooltip
if(!function_exists('myworks_woo_sync_for_xero_set_tooltip')){
	function myworks_woo_sync_for_xero_set_tooltip($tt,$ti='?'){
		global $MWXS_L;
		$ti = (string) $ti;
				
		echo '<div class="material-icons tooltipped right tooltip">';
		echo esc_html($ti);		
		echo '<span class="tooltiptext">'.wp_kses(str_replace(['{LB}','{BOLD_S}','{BOLD_E}'],['<br>','<b>','</b>'],esc_html($tt)), ['br' => [], 'b' => []]).'</span>';
		echo '</div>';
	}
}

# Table Sorter
if(!function_exists('myworks_woo_sync_for_xero_get_tablesorter_js')){
	function myworks_woo_sync_for_xero_get_tablesorter_js($item='table'){
		global $MWXS_L;

		$item = $MWXS_L->sanitize($item);
		if(empty($item)){
			return '';
		}
		
		echo '
		<script type="text/javascript">
			jQuery(function($){
				//jQuery(\''.esc_js($item).'\').addClass(\'tablesorter-blue\');
				jQuery(\''.esc_js($item).' th\').css(\'cursor\',\'pointer\');
				
				jQuery(\''.esc_js($item).' th\').each(function(){
					if(jQuery(this).hasClass(\'mwxs_tsns\')){
						jQuery(this).css(\'cursor\',\'context-menu\');
						jQuery(this).attr("disabled", true);
						return;
					}

					var sort_th_title = jQuery(this).attr(\'title\');
					if (sort_th_title == null){
						sort_th_title = \'\';
					}
					
					if(sort_th_title==\'\'){
						sort_th_title = jQuery(this).text();
					}
					
					sort_th_title = jQuery.trim(sort_th_title);				  
					if(sort_th_title!=\'\'){
						sort_th_title = \'Sort By \'+sort_th_title;
						jQuery(this).attr(\'title\',sort_th_title);
					}else{
						//jQuery(this).addClass(\'{sorter: false}\');
						jQuery(this).attr(\'data-sorter\',\'false\');
						jQuery(this).attr(\'data-filter\',\'false\');
					}				  
				});
				
				jQuery(\''.esc_js($item).'\').tablesorter();
			});
		</script>
		';		
	}
}

if(!function_exists('myworks_woo_sync_for_xero_get_log_chart_output')){
	function myworks_woo_sync_for_xero_get_log_chart_output($viewPeriod=''){
		global $MWXS_L;
		$data = $MWXS_L->get_log_chart_data();
		//$MWXS_L->_p($data);
		if (!in_array($viewPeriod, array('today', 'month', 'year'))) {
			$viewPeriod = 'today';
		}
		
		$invoiceData = (isset($data['invoices']['total'][$viewPeriod]))?$data['invoices']['total'][$viewPeriod]:array();
		$clientData = (isset($data['clients']['total'][$viewPeriod]))?$data['clients']['total'][$viewPeriod]:array();
		//$errorData = (isset($data['errors']['total'][$viewPeriod]))?$data['errors']['total'][$viewPeriod]:array();

		$paymentData = (isset($data['payments']['total'][$viewPeriod]))?$data['payments']['total'][$viewPeriod]:array();
		
		$productData = (isset($data['products']['total'][$viewPeriod]))?$data['products']['total'][$viewPeriod]:array();
		//$depositData = (isset($data['deposits']['total'][$viewPeriod]))?$data['deposits']['total'][$viewPeriod]:array();

		if ($viewPeriod == 'today') {
			$graphLabels = array();
			$graphDataInv = array();
			$graphDataCus = array();
			//$graphDataErr = array();
			$graphDataPmnt = array();
			$graphDataPrdt = array();
			$graphDataDpst = array();

			for ($i = 0; $i <= gmdate("H"); $i++) {
				$graphLabels[] = gmdate("ga", mktime($i, gmdate("i"), gmdate("s"), gmdate("m"), gmdate("d"), gmdate("Y")));
				$graphDataInv[] = isset($invoiceData[$i]) ? $invoiceData[$i] : 0;
				$graphDataCus[] = isset($clientData[$i]) ? $clientData[$i] : 0;
				//$graphDataErr[] = isset($errorData[$i]) ? $errorData[$i] : 0;
				
				$graphDataPmnt[] = isset($paymentData[$i]) ? $paymentData[$i] : 0;

				$graphDataPrdt[] = isset($productData[$i]) ? $productData[$i] : 0;
				$graphDataDpst[] = isset($depositData[$i]) ? $depositData[$i] : 0;
			}

		} elseif ($viewPeriod == 'month') {
			$graphLabels = array();
			$graphDataInv = array();
			$graphDataCus = array();
			//$graphDataErr = array();

			$graphDataPmnt = array();
			$graphDataPrdt = array();
			$graphDataDpst = array();
			
			for ($i = 0; $i < 30; $i++) {
				$time = mktime(0, 0, 0, gmdate("m"), gmdate("d") - $i, gmdate("Y"));
				$graphLabels[] = gmdate("jS", $time);
				$graphDataInv[] = isset($invoiceData[gmdate("j F", $time)]) ? $invoiceData[gmdate("j F", $time)] : 0;
				$graphDataCus[] = isset($clientData[gmdate("j F", $time)]) ? $clientData[gmdate("j F", $time)] : 0;
				//$graphDataErr[] = isset($errorData[date("j F", $time)]) ? $errorData[date("j F", $time)] : 0;
				
				$graphDataPmnt[] = isset($paymentData[gmdate("j F", $time)]) ? $paymentData[gmdate("j F", $time)] : 0;

				$graphDataPrdt[] = isset($productData[gmdate("j F", $time)]) ? $productData[gmdate("j F", $time)] : 0;
				$graphDataDpst[] = isset($depositData[gmdate("j F", $time)]) ? $depositData[gmdate("j F", $time)] : 0;
			}

			$graphLabels = array_reverse($graphLabels);

			$graphDataInv = array_reverse($graphDataInv);
			$graphDataCus = array_reverse($graphDataCus);
			//$graphDataErr = array_reverse($graphDataErr);
			
			$graphDataPmnt = array_reverse($graphDataPmnt);

			$graphDataPrdt = array_reverse($graphDataPrdt);
			$graphDataDpst = array_reverse($graphDataDpst);

		} elseif ($viewPeriod == 'year') {

			$graphLabels = array();

			$graphDataInv = array();
			$graphDataCus = array();
			//$graphDataErr = array();
			
			$graphDataPmnt = array();
			$graphDataPrdt = array();
			$graphDataDpst = array();

			for ($i = 0; $i < 12; $i++) {
				$time = mktime(0, 0, 0, gmdate("m") - $i, 1, gmdate("Y"));
				$graphLabels[] = gmdate("F y", $time);
				$graphDataInv[] = isset($invoiceData[gmdate("F Y", $time)]) ? $invoiceData[gmdate("F Y", $time)] : 0;
				$graphDataCus[] = isset($clientData[gmdate("F Y", $time)]) ? $clientData[gmdate("F Y", $time)] : 0;
				//$graphDataErr[] = isset($errorData[date("F Y", $time)]) ? $errorData[date("F Y", $time)] : 0;
				
				$graphDataPmnt[] = isset($paymentData[gmdate("F Y", $time)]) ? $paymentData[gmdate("F Y", $time)] : 0;
				$graphDataPrdt[] = isset($productData[gmdate("F Y", $time)]) ? $productData[gmdate("F Y", $time)] : 0;
				$graphDataDpst[] = isset($depositData[gmdate("F Y", $time)]) ? $depositData[gmdate("F Y", $time)] : 0;
			}

			$graphLabels = array_reverse($graphLabels);

			$graphDataInv = array_reverse($graphDataInv);
			$graphDataCus = array_reverse($graphDataCus);
			//$graphDataErr = array_reverse($graphDataErr);

			$graphDataPmnt = array_reverse($graphDataPmnt);
			$graphDataPrdt = array_reverse($graphDataPrdt);
			$graphDataDpst = array_reverse($graphDataDpst);

		}
		
		// Security: Store original array for proper JSON encoding
		$graphLabelsArray = $MWXS_L->array_sanitize($graphLabels);

		// Keep data as arrays for wp_localize_script (no implode needed)
		$graphDataInvArray = array_map('intval', $graphDataInv);
		$graphDataCusArray = array_map('intval', $graphDataCus);
		$graphDataPmntArray = array_map('intval', $graphDataPmnt);
		$graphDataPrdtArray = array_map('intval', $graphDataPrdt);

		$activeToday = ($viewPeriod == 'today') ? ' active' : '';
		$activeThisMonth = ($viewPeriod == 'month') ? ' active' : '';
		$activeThisYear = ($viewPeriod == 'year') ? ' active' : '';

		#colors
		$client_bg_color_rgb = '220,220,220,0.5';
		$client_border_color_rgb = '220,220,220,1';
		$client_point_bg_color_rgb = '220,220,220,1';
		$client_point_border_color = '#fff';
		
		$payment_bg_color_rgb = '66, 134, 244, 0.5';
		$payment_border_color_rgb = '66, 134, 244, 1';
		$payment_point_bg_color_rgb = '66, 134, 244, 1';
		$payment_point_border_color = '#fff';

		$deposit_bg_color_rgb = '66, 238, 244, 0.5';
		$deposit_border_color_rgb = '66, 238, 244, 1';
		$deposit_point_bg_color_rgb = '66, 238, 244, 1';
		$deposit_point_border_color = '#fff';

		$product_bg_color_rgb = '232, 163, 2, 0.5';
		$product_border_color_rgb = '232, 163, 2,1';
		$product_point_bg_color_rgb = '232, 163, 2, 1';
		$product_point_border_color = '#fff';

		$help_txt = __('Click on colors or labels for enable/disable','myworks-sync-for-xero');

		// Enqueue Chart.js script
		wp_enqueue_script(
			'myworks-sync-for-xero-log-chart',
			plugin_dir_url(__FILE__) . 'js/myworks-sync-for-xero-log-chart.js',
			array('jquery', 'myworks-sync-for-xero-chart'),
			'1.0.0',
			true
		);

		// Pass chart data to JavaScript
		wp_localize_script('myworks-sync-for-xero-log-chart', 'mwxsChartData', array(
			'labels'   => $graphLabelsArray,
			'customer' => $graphDataCusArray,
			'order'    => $graphDataInvArray,
			'payment'  => array(
				'data'            => $graphDataPmntArray,
				'bgColor'         => $payment_bg_color_rgb,
				'borderColor'     => $payment_border_color_rgb,
				'pointBgColor'    => $payment_point_bg_color_rgb,
				'pointBorderColor' => $payment_point_border_color,
			),
			'product'  => array(
				'data'            => $graphDataPrdtArray,
				'bgColor'         => $product_bg_color_rgb,
				'borderColor'     => $product_border_color_rgb,
				'pointBgColor'    => $product_point_bg_color_rgb,
				'pointBorderColor' => $product_point_border_color,
			),
			'client'   => array(
				'bgColor'         => $client_bg_color_rgb,
				'borderColor'     => $client_border_color_rgb,
				'pointBgColor'    => $client_point_bg_color_rgb,
				'pointBorderColor' => $client_point_border_color,
			),
		));

		echo '
		<div style="padding:20px;">
			<div class="btn-group btn-group-sm btn-period-chooser" role="group" aria-label="...">
				<button type="button" class="btn btn-default'.esc_attr($activeToday).'" data-period="today">Today</button>
				<button type="button" class="btn btn-default'.esc_attr($activeThisMonth).'" data-period="month">This Month</button>
				<button type="button" class="btn btn-default'.esc_attr($activeThisYear).'" data-period="year">This Year</button>
			</div>
			<p>'.esc_html($help_txt).'</p>
		</div>

		<div style="width:100%;height:450px;">
			<div id="ChartParent_MWQS">
				<canvas id="Chart_MWQS" height="400"></canvas>
			</div>
		</div>';
	}
}

#Admin Page
if(!function_exists('myworks_woo_sync_for_xero_filter_reset_show_entries_html')){
	function myworks_woo_sync_for_xero_filter_reset_show_entries_html($page_url,$items_per_page){
		global $MWXS_L;
		
		$fb_t = __('Filter', 'myworks-sync-for-xero');
		$rb_t = __('Reset', 'myworks-sync-for-xero');
		$se_t = __('Show entries', 'myworks-sync-for-xero');
		
		echo '&nbsp;';
		echo '<button onclick="javascript:search_item();" class="btn btn-info">'.esc_html($fb_t).'</button>';
		echo '&nbsp;';
		echo '<button onclick="javascript:reset_item();" class="btn btn-info btn-reset">'.esc_html($rb_t).'</button>';
		echo '&nbsp;';		
		echo '<span class="filter-right-sec">';
		echo '<span class="entries">'.esc_html($se_t).'</span>';
		echo '&nbsp;';
		echo '<select style="width:50px;" onchange="javascript:window.location=\''.esc_url($page_url).'&'.esc_attr($MWXS_L->per_page_keyword).'=\'+this.value;">';
		$MWXS_L->only_option($items_per_page,$MWXS_L->show_per_page);
		echo '</select>';
		echo '</span>';
	}
}

# Settings Field
if(!function_exists('myworks_woo_sync_for_xero_g_settings_field')){
	function myworks_woo_sync_for_xero_g_settings_field($sf_type,$sf_data_arr){
		global $MWXS_L;
		$fnp = $MWXS_L->get_s_o_p();
		$f_name = $sf_data_arr['name'];
		$f_name = $fnp.$f_name;
		
		$f_id = $f_name;		
		
		$f_val = $MWXS_L->get_option($f_name);
		if(isset($sf_data_arr['d_val']) && empty($f_val)){
			$f_val = $sf_data_arr['d_val'];
		}

		$is_p_r = false;
		$p_r_msg = '';
		# Locked below Grow.
		$p_lr_r_arr = [
			'default_tracking_category'
		];

		# Locked below Rise. A separate list because the two fields have genuinely different plan
		# floors; don't merge them. Refs #57
		$p_l_r_arr = [
			'default_branding_theme'
		];

		if(in_array($sf_data_arr['name'],$p_lr_r_arr) && ($MWXS_L->is_plg_lc_p_l() || $MWXS_L->is_plg_lc_p_r() || $MWXS_L->is_plg_lc_p_empty())){
			$is_p_r = true;
		}

		# Negated positive predicate, not "is Launch or empty": an unrecognised plan must stay locked,
		# and this is the same call the push path makes. Refs #57
		if(in_array($sf_data_arr['name'],$p_l_r_arr) && !$MWXS_L->is_plg_lc_p_r_up()){
			$is_p_r = true;
		}
		
		echo ($is_p_r)?'<th class="title-description" style="color:lightgray;">':'<th class="title-description">';
			echo esc_html($sf_data_arr['f_title']);
			if($is_p_r){
				echo '&nbsp;';
				echo '<img style="vertical-align: middle;" src="' . esc_url(MW_WC_XERO_SYNC_P_DIR_U.'admin/image/lock-icon.svg') . '" alt="(Locked)" width="16" height="16" title="Not available for the current plan">';				
			}
		echo '</th>';
		
		echo '<td>';
			echo '<div class="row">';
				echo '<div class="input-field col s12 m12 l12">';					
					if($sf_type == 'option_check'){
						if($f_val == 'check_if_empty'){
							$o_chkd = ' checked';
						}else{
							$o_chkd = ($MWXS_L->option_checked($f_name))?' checked':'';
						}
						
						echo '<p>';
							echo '<input type="checkbox" class="filled-in mwqs_st_chk  production-option" name="'.esc_attr($f_name).'" id="'.esc_attr($f_id).'" value="true"'.esc_attr($o_chkd).'>';
						echo '</p>';
					}
					
					if($sf_type == 'textbox'){
						echo '<input type="text" name="'.esc_attr($f_name).'" id="'.esc_attr($f_id).'" value="'.esc_attr($f_val).'">';
					}
					
					if($sf_type == 'textarea'){
						#cols="50" rows=""						
						echo '<textarea name="'.esc_attr($f_name).'" id="'.esc_attr($f_id).'">'.esc_textarea($f_val).'</textarea>';
					}
					
					if($sf_type == 'select'){
						$s_f_name = $f_name;
						$s_s_val = $f_val;
						
						$s_ext_class = '';
						$is_ajax_dd = false;
						if(isset($sf_data_arr['ajax_dd']) && $sf_data_arr['ajax_dd'] && $MWXS_L->is_s2_ajax_dd()){
							$is_ajax_dd = true;
							$s_ext_class = ' mwqs_dynamic_select';
						}
						
						$s_multiple = '';
						if(isset($sf_data_arr['multiple_select']) && $sf_data_arr['multiple_select']){
							$s_multiple = ' multiple';
							$s_ext_class .= ' mqs_multi';
							
							$s_f_name .= '[]';
							
							if(!empty($s_s_val)){
								$s_s_val = explode(',',$s_s_val);
							}
						}

						$disabled = ($is_p_r)?' disabled':'';
						
						echo '<select'.esc_attr($disabled).' name="'.esc_attr($s_f_name).'" id="'.esc_attr($f_id).'" class="filled-in production-option mw_wc_qbo_sync_select2'.esc_attr($s_ext_class).'"'.esc_attr($s_multiple).'>';
							if($is_ajax_dd){
								if(!empty($f_val)){
									$a_d_d_t = isset($sf_data_arr['a_d_d_t'])?$sf_data_arr['a_d_d_t']:'';
									$a_d_otd = '-';
									
									if($a_d_d_t == 'xero_product'){
										$a_d_otd = $MWXS_L->get_field_by_val($MWXS_L->gdtn('products'),'Name','ItemID',$f_val);
									}
									
									if($a_d_d_t == 'xero_customer'){
										$a_d_otd = $MWXS_L->get_field_by_val($MWXS_L->gdtn('customers'),'Name','ContactID',$f_val);
									}
									
									echo '<option value="'.esc_attr($f_val).'">'.esc_html(stripslashes($a_d_otd)).'</option>';
								}
							}else{
								if(isset($sf_data_arr['s_blank_option']) && $sf_data_arr['s_blank_option']){
									echo '<option value=""></option>';
								}
								
								if($sf_data_arr['s_data_src'] == 'Array'){
									$MWXS_L->only_option($s_s_val,$sf_data_arr['s_data_arr']);
								}
								
								if($sf_data_arr['s_data_src'] == 'Options'){
									# Removed
								}

								if($sf_data_arr['s_data_src'] == 'Options_Params' && isset($sf_data_arr['s_data_params']) && isset($sf_data_arr['s_data_function'])){
									if(is_array($sf_data_arr['s_data_params']) && !empty($sf_data_arr['s_data_params']) && $sf_data_arr['s_data_function']){
										$params = $sf_data_arr['s_data_params'];
										if($sf_data_arr['s_data_function'] == 'only_option'){
											if(!isset($params[5])){$params[5] = array();}
											$MWXS_L->only_option($params[0],$params[1],$params[2],$params[3],$params[4],$params[5]);
										}
										
										if($sf_data_arr['s_data_function'] == 'option_html'){
											$MWXS_L->option_html($params[0],$params[1],$params[2],$params[3],$params[4],$params[5],$params[6],$params[7]);
										}
									}
								}
							}							
						echo '</select>';
					}
					
				echo '</div>';
			echo '</div>';
		echo '</td>';
		
		echo '<td>';
			myworks_woo_sync_for_xero_set_tooltip($sf_data_arr['tt_text']);
		echo '</td>';
	}
}

# Compatibility page functions
if(!function_exists('myworks_woo_sync_for_xero_compt_page_option_check_f')){
	function myworks_woo_sync_for_xero_compt_page_option_check_f($f_name_id){
		global $MWXS_L;
		$fnp = $MWXS_L->get_s_o_p();	
		if(!empty($f_name_id) && !empty($fnp)){
			if(!$MWXS_L->start_with($f_name_id,$fnp)){
				$f_name_id = $fnp.$f_name_id;				
			}
			
			$f_id = $f_name_id;
			$o_chkd = ($MWXS_L->option_checked($f_name_id))?' checked':'';
			echo '<input type="checkbox" class="filled-in mwqs_st_chk  production-option" name="'.esc_attr($f_name_id).'" id="'.esc_attr($f_id).'" value="true"'.esc_attr($o_chkd).'>';
		}
	}
}