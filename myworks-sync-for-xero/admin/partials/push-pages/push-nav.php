<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// Reason: Variables in this partial template are local scope passed from parent, not globals
if(!defined( 'ABSPATH' )){

/**
 * Partial template
 *
 * Variables in scope:
 * @var mixed $UP URL prefix from parent
 */
	exit;
}

$a_class = 'active';
?>

<nav class="mw-qbo-sync-grey">
	<div class="nav-wrapper">
		<a class="brand-logo left" href="javascript:void(0)">
			<img alt="" src="<?php echo esc_url(plugins_url(MW_WC_XERO_SYNC_PLUGIN_NAME.'/admin/image/mwd-logo.png'));?>">
		</a>
		<ul class="hide-on-med-and-down right">
			<!--
			<li class="cust-icon <?php if($tab=='customer') echo esc_attr($a_class);?>">				
				<a href="<?php echo esc_url($UP).'customer';?>"><?php esc_html_e('Customer','myworks-sync-for-xero');?></a>
			</li>
			-->
			
			<li class="ord-icon <?php if($tab=='order') echo esc_attr($a_class);?>">				
				<a href="<?php echo esc_url($UP).'order';?>"><?php esc_html_e('Order','myworks-sync-for-xero');?></a>
			</li>
			
			<li class="pro-icon <?php if($tab=='product') echo esc_attr($a_class);?>">				
				<a href="<?php echo esc_url($UP).'product';?>"><?php esc_html_e('Product','myworks-sync-for-xero');?></a>
			</li>
			
			<li class="vari-icon <?php if($tab=='variation') echo esc_attr($a_class);?>">				
				<a href="<?php echo esc_url($UP).'variation';?>"><?php esc_html_e('Variation','myworks-sync-for-xero');?></a>
			</li>
		</ul>
	</div>
</nav>

<?php require_once dirname(plugin_dir_path( __FILE__ )).DIRECTORY_SEPARATOR.'guidelines.php';?>