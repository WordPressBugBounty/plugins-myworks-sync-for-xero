<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// Reason: Variables in this partial template are local scope passed from parent, not globals

if(!defined( 'ABSPATH' )){

/**
 * Partial template
 *
 * Variables in scope:
 * @var mixed $MWXS_L Library singleton instance
 * @var mixed $PP Path prefix from parent
 * @var mixed $UP URL prefix from parent
 */
	exit;
}

global $MWXS_L;

$tab = $MWXS_L->var_g('tab');

// Track Mixpanel event - Map page viewed
MyWorks_Mixpanel_Integration::track_event( 'Map: Viewed', array( 'map_type' => ! empty( $tab ) ? $tab : 'dashboard' ) );

# Path Prefix
$PP = plugin_dir_path( __FILE__ ) . 'map-pages/';
# URL Prefix
$UP = admin_url('admin.php?page=myworks-wc-xero-sync-map&tab=');

$is_lre_p = false;
if($MWXS_L->is_plg_lc_p_l() || $MWXS_L->is_plg_lc_p_r() || $MWXS_L->is_plg_lc_p_empty()){
	$is_lre_p = true;
}

switch($tab){
	case "customer":
		require_once $PP . 'customer-map.php';
		break;
	case "product":
		require_once $PP . 'product-map.php';
		break;
	case "variation":
		require_once $PP . 'variation-map.php';
		break;
	case "category":
		require_once $PP . 'category-map.php';
		break;
	case "payment-method":
		require_once $PP . 'payment-method-map.php';
		break;
	case "tax-class":
		require_once $PP . 'tax-class-map.php';
		break;
	case "custom-field":
		if(!$is_lre_p){
			require_once $PP . 'cf-map.php';
		}		
		break;
	default:
		require_once $PP . 'map-dashboard.php';
}

# Mapping save msg
if($MWXS_L->isset_session('map_page_update_message')){
	$mp_ssm = $MWXS_L->get_session_val('map_page_update_message','',true);
	$save_status = (!empty($mp_ssm))?$mp_ssm:'error';
	myworks_woo_sync_for_xero_set_admin_sweet_alert($save_status);	
}