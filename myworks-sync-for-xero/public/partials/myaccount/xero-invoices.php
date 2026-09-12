<?php
/**
 * My Account > Xero Invoices
 *
 * Shows Xero invoices on the customer account page.
 *
 * This template can be overridden by copying it to:
 * yourtheme/woocommerce/myaccount/xero-invoices.php
 *
 * @package MyWorks_WC_Xero_Sync
 * @version 1.0.0
 *
 * Variables available (passed via wc_get_template):
 * @var int    $current_page
 * @var array  $xero_invoices
 * @var bool   $has_invoices
 * @var bool   $has_more
 * @var bool   $is_mapped
 * @var array  $online_urls     Map of invoice UUID => Xero online invoice URL (AUTHORISED only).
 * @var string $wp_button_class
 */

defined( 'ABSPATH' ) || exit;

global $MWXS_L;

$columns = apply_filters(
	'myworks_xero_account_invoices_columns',
	array(
		'invoice-number'     => __( 'Invoice', 'myworks-sync-for-xero' ),
		'invoice-date'       => __( 'Date', 'myworks-sync-for-xero' ),
		'invoice-amount-due' => __( 'Amount Due', 'myworks-sync-for-xero' ),
		'invoice-status'     => __( 'Status', 'myworks-sync-for-xero' ),
		'invoice-actions'    => __( 'Actions', 'myworks-sync-for-xero' ),
	)
);

$date_format        = get_option( 'date_format' ) ?: 'F j, Y';
$wc_currency_symbol = get_woocommerce_currency_symbol();

/**
 * Parse a Xero date value into a Unix timestamp.
 * Handles both \DateTime objects and the /Date(ms+offset)/ string format.
 */
$parse_xero_date = function( $val ) {
	if ( $val instanceof \DateTime ) {
		return $val->getTimestamp();
	}
	if ( preg_match( '/\/Date\((\d+)([+-]\d{4})?\)\//', (string) $val, $m ) ) {
		return (int) ( $m[1] / 1000 );
	}
	return null;
};

$status_labels = array(
	'AUTHORISED' => __( 'Open', 'myworks-sync-for-xero' ),
	'PAID'       => __( 'Paid', 'myworks-sync-for-xero' ),
	'VOIDED'     => __( 'Voided', 'myworks-sync-for-xero' ),
);

do_action( 'myworks_xero_before_account_invoices', $has_invoices );
?>

<?php if ( $has_invoices ) : ?>

	<table class="woocommerce-orders-table woocommerce-MyAccount-orders shop_table shop_table_responsive my_account_orders account-orders-table">
		<thead>
			<tr>
				<?php foreach ( $columns as $column_id => $column_name ) : ?>
					<th class="woocommerce-orders-table__header woocommerce-orders-table__header-<?php echo esc_attr( $column_id ); ?>" scope="col">
						<span class="nobr"><?php echo esc_html( $column_name ); ?></span>
					</th>
				<?php endforeach; ?>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $xero_invoices as $invoice ) :
				$inv_number   = (string) $invoice->getInvoiceNumber();
				$inv_id       = (string) $invoice->getInvoiceId();
				$inv_date_ts  = $parse_xero_date( $invoice->getDate() );
				$amount_due   = (float) $invoice->getAmountDue();
				$status       = (string) $invoice->getStatus();
				$inv_currency = (string) $invoice->getCurrencyCode();
				$currency_sym = $inv_currency ? get_woocommerce_currency_symbol( $inv_currency ) : $wc_currency_symbol;
				$status_label = isset( $status_labels[ $status ] ) ? $status_labels[ $status ] : $status;
				$pdf_url      = wp_nonce_url(
					add_query_arg(
						array( 'action' => 'myworks_wc_xero_sync_customer_invoice_pdf', 'invoice_id' => $inv_id ),
						admin_url( 'admin-ajax.php' )
					),
					'mwxs_customer_invoice_pdf'
				);
				$online_url   = isset( $online_urls[ $inv_id ] ) ? $online_urls[ $inv_id ] : '';
			?>
			<tr class="woocommerce-orders-table__row woocommerce-orders-table__row--status-<?php echo esc_attr( strtolower( $status ) ); ?> order">
				<?php foreach ( $columns as $column_id => $column_name ) : ?>

					<?php if ( 'invoice-number' === $column_id ) : ?>
						<th class="woocommerce-orders-table__cell woocommerce-orders-table__cell-<?php echo esc_attr( $column_id ); ?>" data-title="<?php echo esc_attr( $column_name ); ?>" scope="row">
							#<?php echo esc_html( $inv_number ); ?>
						</th>

					<?php else : ?>
						<td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-<?php echo esc_attr( $column_id ); ?>" data-title="<?php echo esc_attr( $column_name ); ?>">

							<?php if ( has_action( 'myworks_xero_account_invoices_column_' . $column_id ) ) : ?>
								<?php do_action( 'myworks_xero_account_invoices_column_' . $column_id, $invoice ); ?>

							<?php elseif ( 'invoice-date' === $column_id ) : ?>
								<?php if ( $inv_date_ts ) : ?>
								<time datetime="<?php echo esc_attr( gmdate( 'c', $inv_date_ts ) ); ?>">
									<?php echo esc_html( date_i18n( $date_format, $inv_date_ts ) ); ?>
								</time>
								<?php endif; ?>

							<?php elseif ( 'invoice-amount-due' === $column_id ) : ?>
								<?php echo wp_kses_post( wc_price( $amount_due, array( 'currency' => $inv_currency ?: get_woocommerce_currency() ) ) ); ?>

							<?php elseif ( 'invoice-status' === $column_id ) : ?>
								<?php echo esc_html( $status_label ); ?>

							<?php elseif ( 'invoice-actions' === $column_id ) : ?>
								<a href="<?php echo esc_url( $pdf_url ); ?>" class="woocommerce-button<?php echo esc_attr( $wp_button_class ); ?> button" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr( sprintf( __( 'View PDF for invoice %s', 'myworks-sync-for-xero' ), $inv_number ) ); ?>">
									<?php esc_html_e( 'PDF', 'myworks-sync-for-xero' ); ?>
								</a>
								<?php if ( 'AUTHORISED' === $status && ! empty( $online_url ) ) : ?>
									<a href="<?php echo esc_url( $online_url ); ?>" class="woocommerce-button<?php echo esc_attr( $wp_button_class ); ?> button pay" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr( sprintf( __( 'Pay invoice %s', 'myworks-sync-for-xero' ), $inv_number ) ); ?>">
										<?php esc_html_e( 'Pay', 'myworks-sync-for-xero' ); ?>
									</a>
								<?php endif; ?>

							<?php endif; ?>
						</td>
					<?php endif; ?>

				<?php endforeach; ?>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( 1 < $current_page || $has_more ) : ?>
		<div class="woocommerce-pagination woocommerce-pagination--without-numbers woocommerce-Pagination">
			<?php if ( 1 < $current_page ) : ?>
				<a class="woocommerce-button woocommerce-button--previous woocommerce-Button woocommerce-Button--previous button<?php echo esc_attr( $wp_button_class ); ?>" href="<?php echo esc_url( wc_get_endpoint_url( 'invoices', $current_page - 1, wc_get_page_permalink( 'myaccount' ) ) ); ?>">
					<?php esc_html_e( 'Previous', 'myworks-sync-for-xero' ); ?>
				</a>
			<?php endif; ?>

			<?php if ( $has_more ) : ?>
				<a class="woocommerce-button woocommerce-button--next woocommerce-Button woocommerce-Button--next button<?php echo esc_attr( $wp_button_class ); ?>" href="<?php echo esc_url( wc_get_endpoint_url( 'invoices', $current_page + 1, wc_get_page_permalink( 'myaccount' ) ) ); ?>">
					<?php esc_html_e( 'Next', 'myworks-sync-for-xero' ); ?>
				</a>
			<?php endif; ?>
		</div>
	<?php endif; ?>

<?php elseif ( ! $is_mapped ) : ?>

	<?php wc_print_notice( esc_html__( 'No invoices available — your account is not mapped to a Xero contact.', 'myworks-sync-for-xero' ), 'notice' ); ?>

<?php else : ?>

	<?php wc_print_notice( esc_html__( 'No invoices found.', 'myworks-sync-for-xero' ), 'notice' ); ?>

<?php endif; ?>

<?php do_action( 'myworks_xero_after_account_invoices', $has_invoices ); ?>
