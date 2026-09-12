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
 */

global $wpdb;
$page_url = $UP.'custom-field';

$table = $MWXS_L->gdtn('map_custom_fields');

# POST Action
if ( ! empty( $_POST ) && check_admin_referer( 'myworks_wc_xero_sync_map_wc_xero_custom_field', 'map_wc_xero_custom_field' ) ) {
	#$MWXS_L->_p($_POST);
	$wpdb->query($wpdb->prepare("DELETE FROM `" . esc_sql($table) . "` WHERE `id` > %d", 0));
	$wpdb->query($wpdb->prepare("TRUNCATE TABLE `" . esc_sql($table) . "`"));

	if(isset($_POST['wq_mcf_wcf']) && is_array($_POST['wq_mcf_wcf']) && isset($_POST['wq_mcf_qcf']) && is_array($_POST['wq_mcf_qcf'])){
		$post_data = $val = $MWXS_L->array_sanitize($_POST);

		$wq_mcf_wcf = array_map('trim',$post_data['wq_mcf_wcf']);
		$wq_mcf_wcf_tf = (isset($post_data['wq_mcf_wcf_tf']))?array_map('trim',$post_data['wq_mcf_wcf_tf']):array();

		$wq_mcf_wcf_tf_ft = (isset($post_data['wq_mcf_wcf_tf_ft']))?array_map('trim',$post_data['wq_mcf_wcf_tf_ft']):array();
		$wq_mcf_wcf_tf_ev = (isset($post_data['wq_mcf_wcf_tf_ev']))?array_map('trim',$post_data['wq_mcf_wcf_tf_ev']):array();
		$ext_valid_ft = ['Date'];
		$ext_valid_ev = ['yyyy-mm-dd','dd-mm-yyyy','mm-dd-yyyy','yyyy/mm/dd','dd/mm/yyyy','mm/dd/yyyy','yy/mm/dd'];

		if(array_filter($wq_mcf_wcf)) {
			$wq_mcf_qcf = array_map('trim',$post_data['wq_mcf_qcf']);

			$values = array();
			$place_holders = array();
			$query = "INSERT INTO `" . esc_sql($table) . "` (entity, wc_field, x_field, ext_data) VALUES ";
			
			for($i = 0; $i < count($wq_mcf_wcf); $i++){
				if($wq_mcf_wcf[$i]!='' && isset($wq_mcf_qcf[$i]) && $wq_mcf_qcf[$i]!=''){
					if($wq_mcf_wcf[$i] == 'mcf_wc_oth_cus_field_manual_add'){
						if(isset($wq_mcf_wcf_tf[$i]) && !empty($wq_mcf_wcf_tf[$i]) && $wq_mcf_wcf_tf[$i]!='mcf_wc_oth_cus_field_manual_add'){
							
							$ext_data = '';
							if(isset($wq_mcf_wcf_tf_ft[$i]) && !empty($wq_mcf_wcf_tf_ft[$i]) && in_array($wq_mcf_wcf_tf_ft[$i],$ext_valid_ft)){
								if(isset($wq_mcf_wcf_tf_ev[$i]) && !empty($wq_mcf_wcf_tf_ev[$i]) && in_array($wq_mcf_wcf_tf_ev[$i],$ext_valid_ev)){
									$ext_data_a = array('field_type'=>$wq_mcf_wcf_tf_ft[$i],'ext_val'=>$wq_mcf_wcf_tf_ev[$i]);
									$ext_data = serialize($ext_data_a);
								}
							}

							array_push($values, 'Order', $wq_mcf_wcf_tf[$i], $wq_mcf_qcf[$i], $ext_data);
						}
					}else{
						$ext_data = '';
						if(isset($wq_mcf_wcf_tf_ft[$i]) && !empty($wq_mcf_wcf_tf_ft[$i]) && in_array($wq_mcf_wcf_tf_ft[$i],$ext_valid_ft)){
							if(isset($wq_mcf_wcf_tf_ev[$i]) && !empty($wq_mcf_wcf_tf_ev[$i]) && in_array($wq_mcf_wcf_tf_ev[$i],$ext_valid_ev)){
								$ext_data_a = array('field_type'=>$wq_mcf_wcf_tf_ft[$i],'ext_val'=>$wq_mcf_wcf_tf_ev[$i]);
								$ext_data = serialize($ext_data_a);
							}
						}

						array_push($values, 'Order', $wq_mcf_wcf[$i], $wq_mcf_qcf[$i], $ext_data);
					}

					$place_holders[] = "('%s', '%s', '%s', '%s')";
				}				
			}

			$query .= implode(', ', $place_holders);
			if(!empty($values)){
				$query = $wpdb->prepare($query, ...$values); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query($query); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query prepared with wpdb->prepare
			}
		}
	}
	
	$MWXS_L->set_session_val('map_page_update_message',__('Custom fields mapped successfully.','myworks-sync-for-xero'));

	// Track Mixpanel event
	MyWorks_Mixpanel_Integration::track_event( 'Map: Saved', array( 'map_type' => 'custom-field' ) );

	$MWXS_L->redirect($page_url);
}

$wc_avl_cf_list = $MWXS_L->get_wc_avl_cf_map_fields();
$xero_avl_cf_list = $MWXS_L->get_xero_avl_cf_map_fields();
$cf_map_data = $MWXS_L->get_tbl($table);

require_once plugin_dir_path( __FILE__ ) . 'map-nav.php';
?>

<style type="text/css">
	select.mcf_select{float:none;width:220px;}	
</style>

<div class="container map-product-responsive">
    <div class="page_title">
		<h4><?php esc_html_e( 'Custom Fields Mappings', 'myworks-sync-for-xero' );?></h4>
	</div>

    <!--<br>-->
    <div class="card">
		<div class="card-content">
			<div class="row">
				<form method="POST" class="col s12 m12 l12">
					<div class="row">
						<div class="col s12 m12 l12">
							<div class="myworks-wc-qbo-sync-table-responsive">
								<table class="mw-qbo-sync-map-table menu-blue-bg menu-bg-a new-table">
									<thead>
										<tr>											
											<th width="60%">
                                                <?php esc_html_e( 'WooCommerce Order Field ', 'myworks-sync-for-xero' );?>
                                            </th>                                           
                                            <th width="5%"></th>
											<th width="25%" class="title-description mwxs_tsns">
												<?php esc_html_e( 'Xero Order Field', 'myworks-sync-for-xero' );?>
											</th>
                                            <th width="10%"></th>
                                        </tr>
                                    </thead>

                                    <tbody id="wq_mcf_tb">
										<?php if(is_array($cf_map_data) && !empty($cf_map_data)):?>
										<?php foreach($cf_map_data as $cfm_data):?>
										<tr>
											<td>
												<?php if(is_array($wc_avl_cf_list) && count($wc_avl_cf_list) && array_key_exists($cfm_data['wc_field'],$wc_avl_cf_list)):?>
												<select class="mcf_select" name="wq_mcf_wcf[]">
													<?php $MWXS_L->only_option($cfm_data['wc_field'],$wc_avl_cf_list);?>
												</select>
												<?php else:?>
												<input type="text" value="<?php echo esc_attr($cfm_data['wc_field']);?>" class="mcf_txt" name="wq_mcf_wcf[]"/>
												<?php endif;?>

												<?php
												$ext_ft_ev_txt = '';
												$m_ed_field_n = '';
												$m_ed_field_type = '';
												$m_ed_ext_val = '';
												if(!empty($cfm_data['ext_data'])){
													$ext_data = $cfm_data['ext_data'];
													$ext_data = unserialize($ext_data);												
													if(is_array($ext_data) && !empty($ext_data)){
														if(isset($ext_data['field_type']) && isset($ext_data['ext_val'])){
															if(!empty($ext_data['field_type']) && !empty($ext_data['ext_val'])){
																$ext_ft_ev_txt = $ext_data['field_type'].' ('.$ext_data['ext_val'].')';																
																
																$m_ed_field_n = $cfm_data['wc_field'];
																$m_ed_field_type = $ext_data['field_type'];
																$m_ed_ext_val = $ext_data['ext_val'];
															}
														}
													}
												}

												if(!empty($ext_ft_ev_txt)){
													echo '&nbsp;<span>'.esc_html($ext_ft_ev_txt).'</span>';
												}											
												?>

												<input type="hidden" class="mcf_txt" name="wq_mcf_wcf_tf[]" value="<?php echo esc_attr($m_ed_field_n);?>"/>											
												<input type="hidden" class="mcf_txt" name="wq_mcf_wcf_tf_ft[]" value="<?php echo esc_attr($m_ed_field_type);?>"/>
												<input type="hidden" class="mcf_txt" name="wq_mcf_wcf_tf_ev[]" value="<?php echo esc_attr($m_ed_ext_val);?>"/>
											</td>
											<td></td>
											<td>											
												<?php if(is_array($xero_avl_cf_list) && count($xero_avl_cf_list) && array_key_exists($cfm_data['x_field'],$xero_avl_cf_list)):?>
													<select class="mcf_select" name="wq_mcf_qcf[]">
														<?php $MWXS_L->only_option($cfm_data['x_field'],$xero_avl_cf_list);?>
													</select>
												<?php else:?>
													<input type="text" value="<?php echo esc_attr($cfm_data['x_field']);?>" class="mcf_txt" name="wq_mcf_qcf[]"/>
												<?php endif;?>
											</td>
											<td><a href="#" class="remove_field">Remove</a></td>
										</tr>										
										<?php endforeach;?>
										<?php endif;?>										
                                    </tbody>
                                </table>
                            </div>
                            <div style="padding:10px 0px 0px 20px;">
								<a data-cft="order" class="wq_mcf_afb" href="javascript:void(0)">Add Fields</a>
							</div>
                        </div>
                    </div>
                   
					<div class="row">
						<?php wp_nonce_field( 'myworks_wc_xero_sync_map_wc_xero_custom_field', 'map_wc_xero_custom_field' ); ?>
						<div class="input-field col s12 m6 l4">
							<button id="mcf_sb" class="waves-effect waves-light btn save-btn mw-qbo-sync-green" disabled>
								<?php esc_html_e( 'Save', 'myworks-sync-for-xero' );?>
							</button>
						</div>
					</div>

                    <div id="wq_mcf_clone_fields" style="display:none;">
                        <table>
                            <tr>
                                <td>
                                    <select class="mcf_select mcfws_nf" name="wq_mcf_wcf[]">
                                        <option value=""></option>
										<?php $MWXS_L->only_option('',$wc_avl_cf_list);?>
                                        <option value="mcf_wc_oth_cus_field_manual_add">Others(Add manually)</option>
                                    </select>

                                    &nbsp;
                                    <input type="hidden" class="mcf_txt wmwt_cl" name="wq_mcf_wcf_tf[]"/>

                                    &nbsp;							
                                    <select title="Field Type" name="wq_mcf_wcf_tf_ft[]" class="mcf_select_e wmwt_cl_ft" style="float:none; display:none;">
                                        <option value="">Normal</option>
                                        <option value="Date">Date</option>
                                    </select>

                                    &nbsp;                                    
                                    <select name="wq_mcf_wcf_tf_ev[]" class="mcf_select_e wmwt_cl_ev"  style="float:none; display:none;">
                                        <option value="yyyy-mm-dd">yyyy-mm-dd</option>
                                        <option value="dd-mm-yyyy">dd-mm-yyyy</option>
                                        <option value="mm-dd-yyyy">mm-dd-yyyy</option>
                                        
                                        <option value="yyyy/mm/dd">yyyy/mm/dd</option>
                                        <option value="dd/mm/yyyy">dd/mm/yyyy</option>
                                        <option value="mm/dd/yyyy">mm/dd/yyyy</option>
                                        
                                        <option value="yy/mm/dd">yy/mm/dd</option>
                                    </select>
                                </td>
                                <td></td>
                                <td>
                                    <select class="mcf_select" name="wq_mcf_qcf[]">
                                        <option value=""></option> 
										<?php $MWXS_L->only_option('',$xero_avl_cf_list);?>
                                    </select>
                                </td>
                                <td><a href="#" class="remove_field">Remove</a></td>
                            </tr>
                        </table>
                    </div>
					
                </form>
            </div>
        </div>
    </div>
</div>

<script type="text/javascript">
    jQuery(document).ready(function($){
        var max_fields = 100;
		var x = <?php echo esc_js((int) count($cf_map_data));?>;

        if(x>0){
			$('#mcf_sb').removeAttr('disabled');
		}

        $('.wq_mcf_afb').click(function(e){
			e.preventDefault();
			var cft = $(this).data('cft');
			$('#mcf_sb').removeAttr('disabled');
			if(x < max_fields){
				x++;				
				var na_fields = $('#wq_mcf_clone_fields').html();
				na_fields = na_fields.replace('<table>','').replace('<tbody>','').replace('</tbody>','').replace('</table>','');
				na_fields = na_fields.trim();				
				$("#wq_mcf_tb").append(na_fields);
			}else{
				alert('Max '+max_fields+' allowed.')
			}
		});

        $("#wq_mcf_tb").on("click",".remove_field", function(e){
			e.preventDefault();			
			$(this).parent('td').parent('tr').remove();
			x--;
		});

        $(document).on('change', '.mcfws_nf', function() {	
			if($(this).val() == 'mcf_wc_oth_cus_field_manual_add'){
				$(this).next('input.wmwt_cl').attr('type','text');
				$(this).next('input.wmwt_cl').next('select.wmwt_cl_ft').show();
			}else{
				$(this).next('input.wmwt_cl').val('');
				$(this).next('input.wmwt_cl').attr('type','hidden');
				
				$(this).next('input.wmwt_cl').next('select.wmwt_cl_ft').val('');
				$(this).next('input.wmwt_cl').next('select.wmwt_cl_ft').hide();
				
				$(this).next('input.wmwt_cl').next('select.wmwt_cl_ft').next('select.wmwt_cl_ev').val('yyyy-mm-dd');
				$(this).next('input.wmwt_cl').next('select.wmwt_cl_ft').next('select.wmwt_cl_ev').hide();
			}
		});
		
		$(document).on('change', '.wmwt_cl_ft', function() {			
			if($(this).val() == 'Date'){				
				$(this).next('select.wmwt_cl_ev').show();
			}else{
				$(this).next('select.wmwt_cl_ev').val('yyyy-mm-dd');
				$(this).next('select.wmwt_cl_ev').hide();
			}
		});
    });
</script>