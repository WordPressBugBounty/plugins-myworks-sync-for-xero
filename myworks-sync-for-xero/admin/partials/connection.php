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
$page_url = 'admin.php?page=myworks-wc-xero-sync-connection';
$pfn = MW_WC_XERO_SYNC_PLUGIN_NAME;

$mw_wc_xero_license = $MWXS_L->get_option('mw_wc_xero_license','');
$mw_wc_xero_localkey = $MWXS_L->get_option('mw_wc_xero_localkey','');

$connection_key_section = false;
$f_xc_key = '';
$pls = $MWXS_L->get_license_status();
?>

<div class="ncd-cnt body-content">
<div class="margin">

<?php 
	if($MWXS_L->is_valid_license($mw_wc_xero_license,$mw_wc_xero_localkey)):
	$pls = $MWXS_L->get_license_status();
	$ldfcpv = $MWXS_L->get_ldfcpv();
	
	$is_xero_connected = $MWXS_L->is_xero_connected();
	#$is_xero_connected = false;
	$xero_company_info = false;

	$x_c_status_txt = 'Not Connected';

	// Track connection status changes for Intercom
	$previous_connection_status = get_option( 'mw_wc_xero_connection_status', '' );
	$current_connection_status = $is_xero_connected ? 'connected' : 'disconnected';

	if($is_xero_connected){
		$xero_company_info = $MWXS_L->get_connected_xero_cd();
		#$MWXS_L->_p($xero_company_info);
		$x_c_status_txt = 'Connected';

		// Track connection event if status changed from disconnected to connected
		if ( $previous_connection_status === 'disconnected' || empty( $previous_connection_status ) ) {
			if ( class_exists( 'MyWorks_Intercom_Integration' ) ) {
				MyWorks_Intercom_Integration::track_event( 'Accounting: Connected' );
			}
			update_option( 'mw_wc_xero_connection_status', 'connected' );
		}
	} else {
		// Track disconnection event if status changed from connected to disconnected
		if ( $previous_connection_status === 'connected' ) {
			if ( class_exists( 'MyWorks_Intercom_Integration' ) ) {
				MyWorks_Intercom_Integration::track_event( 'Accounting: Disconnected' );
			}
			update_option( 'mw_wc_xero_connection_status', 'disconnected' );
		}
	}
	
	$x_p_d_url = 'https://support.myworks.software/hc/en-us/categories/10141789949335-WooCommerce-Sync-for-Xero';
	$f_xc_key = $MWXS_L->get_option('mw_wc_xero_f_xc_key');
?>

<!--Design-->
<div class="rows">
	<div class="cols-md-8 left-row">
		<div class="left-outer">
			<div class="left-iner">
				<div class="lt-top">
					<div class="lkq">
						<div class="lkg-img">
							<img src="<?php echo esc_url(plugins_url( $pfn.'/admin/image/quick.png' )) ?>" class="img-res" alt="">
						</div>
						<div class="lkg-content">
							<h3><?php esc_html_e('Xero Connection','myworks-sync-for-xero');?></h3>
							<span><?php esc_html_e('WooCommerce Sync for Xero','myworks-sync-for-xero');?></span>
						</div>
					</div>
				</div>
				
				<div class="md-licence">
					<div class="inr-l">
						<div class="value-key">
							<label for="mw_wc_xero_license"><?php esc_html_e('License key','myworks-sync-for-xero');?></label>
							<input title="<?php echo esc_attr__('To update your license key, deactivate and re-activate the plugin. All your settings and mappings will be saved.','myworks-sync-for-xero');?>" type="text" name="mw_wc_xero_license" id="mw_wc_qbo_sync_license_update" value="<?php echo esc_attr($mw_wc_xero_license);?>" disabled>
							
							<div class="refresh-key">
								<img src="<?php echo esc_url(plugins_url( $pfn.'/admin/image/refresh-key.png' )) ?>" class="img-res">
								<span>
									<a id="mwqs_dllk" title="<?php echo esc_attr__('Refresh your license information','myworks-sync-for-xero');?>" href="javascript:void(0);"><?php esc_html_e('Refresh License','myworks-sync-for-xero');?></a>
								</span>
								<?php 
									wp_nonce_field( 'myworks_wc_xero_sync_del_license_local_key', 'del_license_local_key' );
								?>
							</div>
						</div>
					</div>
					
					<div class="licence-list">
						<ul>
							<li class="current">
								<div class="left-status">
									<?php esc_html_e('Status','myworks-sync-for-xero');?>
								</div>
								<div class="right-status">
									<?php echo (isset($ldfcpv['status']))?esc_html($ldfcpv['status']):''?>
								</div>
							</li>
							
							<li>
								<div class="left-status">
									<?php esc_html_e('Plan','myworks-sync-for-xero');?>
								</div>
								<div class="right-status">
									<?php echo (isset($ldfcpv['plan']))?esc_html($ldfcpv['plan']):''?>
								</div>
							</li>
							
							<li style="display:none;">
								<div class="left-status">
									<?php esc_html_e('Next Due Date','myworks-sync-for-xero');?>
								</div>
								<div class="right-status">
									<?php #echo (isset($ldfcpv['nextduedate']) && !empty($ldfcpv['nextduedate']) && $ldfcpv['nextduedate'] != '0000-00-00')?date('M j, Y',strtotime($ldfcpv['nextduedate'])):''?>
								</div>
							</li>
							
							<li>
								<div class="left-status">
									<?php esc_html_e('Billing Cycle','myworks-sync-for-xero');?>
								</div>
								<div class="right-status">
									<?php echo (isset($ldfcpv['billingcycle']))?esc_html($ldfcpv['billingcycle']):''?>
								</div>
							</li>

							<?php if(!$MWXS_L->is_plg_lc_p_sr()):?>
							<li>
								<div class="left-status">
									<?php esc_html_e('Monthly Orders','myworks-sync-for-xero');?>
								</div>								
								<div class="right-status">
									<?php echo (int) $MWXS_L->get_osl_sm_val();?> of <?php echo (int) $MWXS_L->get_osl_lp_count();?>
								</div>
							</li>
							<?php endif;?>
						</ul>
					</div>
				</div>
				
				<?php  if($is_xero_connected):?>
				<div class="quick-book">
					<div class="quick-pdng">
						<h3><?php esc_html_e('Manage Xero Connection','myworks-sync-for-xero');?></h3>
						<p>
						<?php esc_html_e('You\'re already connected to Xero, you can manage your connection here.','myworks-sync-for-xero');?></p>
						<div class="Connect-now">
							<a target="_blank" href="<?php echo esc_url($MWXS_L->get_xcd_url());?>" class="CmnBtn"><?php esc_html_e('Manage Connection','myworks-sync-for-xero');?></a>
						</div>
					</div>
				</div>
				<?php else:?>
				
				<div class="quick-book">
					<div class="quick-pdng">
						<h3><?php esc_html_e('Connect to Xero','myworks-sync-for-xero');?></h3>
						<p>
							<?php esc_html_e('Your license key is active, click here to connect to your Xero account.','myworks-sync-for-xero');?>
						</p>
						<div class="Connect-now">
							<a  target="_blank" href="<?php echo esc_url($MWXS_L->get_xcd_url());?>" class="CmnBtn"><?php esc_html_e('Connect','myworks-sync-for-xero');?></a>
						</div>
						
						<?php if($connection_key_section):?>
						<div>
							<br>
							<p><?php esc_html_e('Already connected? Enter your connection key here','myworks-sync-for-xero');?></p>
							<div>
								<input style="min-width:300px;" type="text" id="f_xc_key" value="<?php echo esc_attr($f_xc_key);?>" placeholder="<?php esc_attr_e('Connection Key','myworks-sync-for-xero');?>">								
							</div>
							<div class="Connect-now">
								<a id="a_sxck" href="#" class="CmnBtn"><?php echo (empty($f_xc_key)) ? esc_html__('Save','myworks-sync-for-xero') : esc_html__('Update','myworks-sync-for-xero');?></a>
								<?php 
									wp_nonce_field( 'myworks_wc_xero_sync_save_xero_c_key', 'save_xero_c_key' );
								?>
							</div>
							<p id="sxk_am"></p>
						</div>
						<?php endif;?>

					</div>
				</div>
				<?php endif;?>				
			</div>
		</div>
	</div>
	
	<div class="cols-md-4 right-row">
		<div class="side-bar">
			<div class="i-img">
				<img src="<?php echo esc_url(plugins_url( $pfn.'/admin/image/i.png' )) ?>" class="img-res" alt="">
			</div>
			<h3><?php esc_html_e('Connection Info','myworks-sync-for-xero');?></h3>
			<?php  if($is_xero_connected):?>
			<?php if(is_array($xero_company_info) && !empty($xero_company_info)):?>
			<div class="usa-block">
				<h3>
					<?php
						echo esc_html($xero_company_info['Name']);
						if(!empty($xero_company_info['CountryCode'])){
							echo ' ['.esc_html($xero_company_info['CountryCode']).']';
						}
					?>
				</h3>
				<span>
					<?php echo esc_html($xero_company_info['Email']);?>
				</span>
			</div>
			<?php endif;?>
			
			<div class="licence-list">
				<ul>
					<li class="current">
						<div class="left-status">
							<?php esc_html_e('Status','myworks-sync-for-xero');?>
						</div>
						<div class="right-status">
							<?php echo esc_html($x_c_status_txt);?>
						</div>
					</li>					
				</ul>
			</div>
			<?php else:?>
			<div class="licence-list">
				<ul>
					<li class="current">
						<div class="left-status">
							<?php esc_html_e('Status','myworks-sync-for-xero');?>
						</div>
						<div class="right-status">
							<span style="color:red;"><?php echo esc_html($x_c_status_txt);?></span>
						</div>
					</li>					   
				</ul>
			</div>
			<!--Connection Refresh If Needed-->
			<?php endif;?>
		</div>
		
		<div class="side-bar dflt">
			<h3><?php esc_html_e('Check out our Documentation!','myworks-sync-for-xero');?></h3>
			<div class="usa-block">
				<p>
					<?php esc_html_e('Need help setting up or starting to use the sync? Check out our helpful documentation and videos to get up and running easily!','myworks-sync-for-xero');?>
					<br>
				</p>
				<div class="Connect-now">
					<a href="<?php echo esc_url($x_p_d_url);?>" target="_blank" class="CmnBtn">
						<?php esc_html_e('Documentation','myworks-sync-for-xero');?>
					</a>
				</div>
			</div>
		</div>
		
		<div class="side-bar dflt">
			<h3><?php esc_html_e('Still need help? Easily open a ticket.','myworks-sync-for-xero');?></h3>
			<div class="usa-block">
				<p>
					<?php esc_html_e('Have a question and can\'t find an answer in our documentation? Our helpful support team is always online via support ticket to give you a hand.','myworks-sync-for-xero');?>
				</p>
				<div class="Connect-now">
					<a href="http://myworks.software/account/submitticket.php" target="_blank" class="CmnBtn">Open Ticket</a>
				</div>
			</div>
		</div>
	</div>
</div>

<script>
	jQuery(document).ready(function($){
		$('#a_sxck').click(function(e){
			e.preventDefault();
			
			let f_xc_key = $('#f_xc_key').val();
			f_xc_key = $.trim(f_xc_key);
			if(f_xc_key == '' || f_xc_key.length != 35){
				$('#sxk_am').html('<br><span style="color:red;">Please enter a valid connection key</span>');
				return false;
			}
			
			var data = {
				"action": 'myworks_wc_xero_sync_save_xero_c_key',				
				"save_xero_c_key": $('#save_xero_c_key').val(),
				"f_xc_key": f_xc_key
			};
			
			$('#sxk_am').html('<br>Saving...');
			
			jQuery.ajax({
				type: "POST",
				url: ajaxurl,
				data: data,
				cache:  false ,
				//datatype: "json",
				success: function(r){
					if(r!=0 && r!=''){
						$('#sxk_am').html(r);
						location.reload();
					}else{
						$('#sxk_am').html('<br><span style="color:red;">Something went wrong</span>');
					}					
				},
				error: function(r) {
					$('#sxk_am').html('<br><span style="color:red;">Something went wrong</span>');
				}
			});
		});

		$('#mwqs_dllk').click(function(){
			if(confirm('Are you sure, you want to refresh local license data?')){
				jQuery(this).html('Loading...');
				var data = {
					"action": 'myworks_wc_xero_sync_del_license_local_key',
					"del_license_local_key": jQuery('#del_license_local_key').val(),
				};
				jQuery.ajax({
				   type: "POST",
				   url: ajaxurl,
				   data: data,
				   cache:  false ,
				   //datatype: "json",
				   success: function(result){
					   if(result!=0 && result!=''){					
						location.reload();
					   }else{
						 jQuery('#mwqs_dllk').html('Error!');					 
					   }				  
				   },
				   error: function(result) { 		
						jQuery('#mwqs_dllk').html('Error!');
				   }
				});
			}		
		});
	});
</script>

<?php else:?>

<?php
	$ilm = '';
	switch($pls){
		case 'Invalid':
			$ilm = __('Please enter a valid license key to proceed.','myworks-sync-for-xero');
			break;
		
		case 'Expired':
			$ilm = __('Your license key is expired. Please check your account with us to renew your plan or enter a valid license key.','myworks-sync-for-xero');
			break;
		
		case 'Suspended':
			$ilm = __('Your license key is suspended. Please check your account with us to renew your plan or enter a valid license key.','myworks-sync-for-xero');
			break;
		
		default:
			$ilm = __('Please enter a valid license key to proceed.','myworks-sync-for-xero');
	}
?>

<?php if($pls != 'Active'):?>
<div class="qbd_input_license">
	<p><?php echo esc_html($ilm);?></p>
	<div class="mwqs_conection_license_check">
		<form method="post" id="myworks_wc_xero_sync_check_license">
			<label for ="mw_wc_xero_license">Please enter your license key below. Don't have one? Sign up for a MyWorks account. </label>
			<input type="text" name="mw_wc_xero_license" id="mw_wc_xero_license" value="<?php echo esc_attr($mw_wc_xero_license);?>">
			<?php wp_nonce_field( 'myworks_wc_xero_sync_check_license', 'check_plugin_license' ); ?>
			
			<input size="30" type="submit" value="Enter" class="button button-primary">
			<span id="mwqs_license_chk_loader" style="visibility:hidden;">
				<img src="<?php echo esc_url( plugins_url( 'image/ajax-loader.gif', dirname(__FILE__) ) );?>" alt="Loading..." />
			</span>
		</form>
	</div>
</div>
<?php endif;?>

<?php endif;?>
</div>
</div>