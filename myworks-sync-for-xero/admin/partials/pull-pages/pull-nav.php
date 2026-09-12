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
			<li class="pro-icon <?php if($tab=='product') echo esc_attr($a_class);?>">				
				<a href="<?php echo esc_url($UP).'product';?>"><?php esc_html_e('Product','myworks-sync-for-xero');?></a>
			</li>
		</ul>
	</div>
</nav>