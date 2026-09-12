<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// Reason: Variables in this partial template are local scope passed from parent, not globals
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Partial template
 *
 * Variables in scope:
 * @var mixed $UP URL prefix from parent
 */

$a_class = 'active';
?>

<nav class="mw-qbo-sync-grey">
	<div class="nav-wrapper">
		<a class="brand-logo left" href="javascript:void(0)">
			<img alt="" src="<?php echo esc_url(plugins_url(MW_WC_XERO_SYNC_PLUGIN_NAME.'/admin/image/mwd-logo.png'));?>">
		</a>
		<ul class="hide-on-med-and-down right">
			<li class="cust-icon <?php if($tab=='customer') echo esc_attr($a_class);?>">				
				<a href="<?php echo esc_url($UP).'customer';?>"><?php esc_html_e('Customer','myworks-sync-for-xero');?></a>
			</li>
			
			<li class="pro-icon <?php if($tab=='product') echo esc_attr($a_class);?>">				
				<a href="<?php echo esc_url($UP).'product';?>"><?php esc_html_e('Product','myworks-sync-for-xero');?></a>
			</li>
			
			<li class="vari-icon <?php if($tab=='variation') echo esc_attr($a_class);?>">				
				<a href="<?php echo esc_url($UP).'variation';?>"><?php esc_html_e('Variation','myworks-sync-for-xero');?></a>
			</li>
			
			<li class="cat-icon <?php if($tab=='category') echo esc_attr($a_class);?>">				
				<a href="<?php echo esc_url($UP).'category';?>"><?php esc_html_e('Category','myworks-sync-for-xero');?></a>
			</li>
			
			<li class="pay-icon <?php if($tab=='payment-method') echo esc_attr($a_class);?>">				
				<a href="<?php echo esc_url($UP).'payment-method';?>"><?php esc_html_e('Payment Method','myworks-sync-for-xero');?></a>
			</li>
			
			<li class="tax-icon <?php if($tab=='tax-class') echo esc_attr($a_class);?>">				
				<a href="<?php echo esc_url($UP).'tax-class';?>"><?php esc_html_e('Tax Rate','myworks-sync-for-xero');?></a>
			</li>

			<li class="cf-icon <?php if($tab=='custom-field') echo esc_attr($a_class);?>">
				<?php if($is_lre_p):?>
				<a style="color:lightgray;"><?php esc_html_e('Custom Fields','myworks-sync-for-xero');?>
					<!-- &nbsp; -->
					<img style="vertical-align: middle;" src="<?php echo esc_url(MW_WC_XERO_SYNC_P_DIR_U.'admin/image/lock-icon.svg');?>" alt="(Locked)" width="16" height="16" title="Not available for the current plan">
				</a>
				<?php else:?>				
				<a href="<?php echo esc_url($UP).'custom-field';?>"><?php esc_html_e('Custom Fields','myworks-sync-for-xero');?></a>
				<?php endif;?>
			</li>
		</ul>
	</div>
</nav>

<?php require_once dirname(plugin_dir_path( __FILE__ )).DIRECTORY_SEPARATOR.'guidelines.php';?>