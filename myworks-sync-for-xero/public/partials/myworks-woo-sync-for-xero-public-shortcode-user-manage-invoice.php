<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// Reason: MWXS is the established plugin prefix (short form of MyWorks Xero Sync)
if ( ! defined( 'WPINC' ) ) {
	die;
}

global $MWXS_L;
global $wpdb;

$wc_user_id      = (int) get_current_user_id();
$xero_contact_id = '';
$xero_invoices   = array();

if ( $wc_user_id > 0 ) {
	$validated_map_tbl = $MWXS_L->get_validated_table_name( 'map_customers' );
	if ( $validated_map_tbl ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name validated via whitelist
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT `X_C_ID` FROM `" . esc_sql( $validated_map_tbl ) . "` WHERE `W_C_ID` = %d AND `X_C_ID` !='' LIMIT 1", $wc_user_id ),
			ARRAY_A
		);
		if ( $row && ! empty( $row['X_C_ID'] ) ) {
			$xero_contact_id = $row['X_C_ID'];
		}
	}
}

$inv_page = max( 1, (int) filter_input( INPUT_GET, 'inv_page', FILTER_SANITIZE_NUMBER_INT ) );
$per_page = 10;

$MWXS_L->xero_connect();

if ( ! empty( $xero_contact_id ) && $MWXS_L->is_xero_connected() ) {
	try {
		$result = $MWXS_L->X_API_I()->getInvoices(
			$MWXS_L->get_xero_tenant_id(), // xero_tenant_id
			null,                           // if_modified_since
			'Type=="ACCREC"',               // where
			'Date DESC',                    // order
			null,                           // ids
			null,                           // invoice_numbers
			array( $xero_contact_id ),      // contact_ids
			array( 'AUTHORISED', 'PAID' ),  // statuses
			$inv_page,                      // page
			null,                           // include_archived
			null,                           // created_by_my_app
			$MWXS_L->x_unitdp(),           // unitdp
			false,                          // summary_only
			$per_page                       // page_size
		);
		if ( $result ) {
			$xero_invoices = $result->getInvoices();
		}
	} catch ( Exception $e ) {
		$xero_invoices = array();
	}
}

$has_invoices = ! empty( $xero_contact_id ) && is_array( $xero_invoices ) && count( $xero_invoices ) > 0;

// Fetch online invoice URLs for AUTHORISED invoices (used for Pay button).
$online_urls = array();
if ( $has_invoices ) {
	$_sc_tenant_id = $MWXS_L->get_xero_tenant_id();
	foreach ( $xero_invoices as $_sc_inv ) {
		if ( 'AUTHORISED' !== (string) $_sc_inv->getStatus() ) {
			continue;
		}
		try {
			$_sc_result = $MWXS_L->X_API_I()->getOnlineInvoice( $_sc_tenant_id, $_sc_inv->getInvoiceId() );
			if ( $_sc_result && $_sc_result->getOnlineInvoices() ) {
				$_sc_url = $_sc_result->getOnlineInvoices()[0]->getOnlineInvoiceUrl();
				if ( $_sc_url ) {
					$online_urls[ $_sc_inv->getInvoiceId() ] = (string) $_sc_url;
				}
			}
		} catch ( Exception $e ) {
			// Skip — online URL is best-effort.
		}
	}
	unset( $_sc_tenant_id, $_sc_inv, $_sc_result, $_sc_url );
}

$date_format        = get_option( 'date_format' );
$wc_currency_symbol = get_woocommerce_currency_symbol();

$status_labels = array(
	'AUTHORISED' => __( 'Open', 'myworks-sync-for-xero' ),
	'PAID'       => __( 'Paid', 'myworks-sync-for-xero' ),
	'VOIDED'     => __( 'Voided', 'myworks-sync-for-xero' ),
);

$columns = apply_filters(
	'myworks_xero_account_invoices_columns',
	array(
		'invoice-number'     => __( 'Invoice', 'myworks-sync-for-xero' ),
		'invoice-date'       => __( 'Date', 'myworks-sync-for-xero' ),
		'invoice-due-date'   => __( 'Due Date', 'myworks-sync-for-xero' ),
		'invoice-amount-due' => __( 'Amount Due', 'myworks-sync-for-xero' ),
		'invoice-total'      => __( 'Total', 'myworks-sync-for-xero' ),
		'invoice-status'     => __( 'Status', 'myworks-sync-for-xero' ),
		'invoice-actions'    => __( 'Actions', 'myworks-sync-for-xero' ),
	)
);

$wp_button_class = function_exists( 'wc_wp_theme_get_element_class_name' ) ? wc_wp_theme_get_element_class_name( 'button' ) : '';
$wp_button_class = $wp_button_class ? ' ' . $wp_button_class : '';

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
				$inv_date     = $invoice->getDate();
				$inv_due      = $invoice->getDueDate();
				$fmt          = $date_format ? $date_format : 'Y-m-d';
				$inv_date_str = ( $inv_date instanceof \DateTime ) ? $inv_date->format( $fmt ) : (string) $inv_date;
				$inv_due_str  = ( $inv_due instanceof \DateTime ) ? $inv_due->format( $fmt ) : (string) $inv_due;
				$amount_due   = (float) $invoice->getAmountDue();
				$total        = (float) $invoice->getTotal();
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
								<time datetime="<?php echo esc_attr( $inv_date instanceof \DateTime ? $inv_date->format( 'c' ) : $inv_date_str ); ?>">
									<?php echo esc_html( $inv_date_str ); ?>
								</time>

							<?php elseif ( 'invoice-due-date' === $column_id ) : ?>
								<time datetime="<?php echo esc_attr( $inv_due instanceof \DateTime ? $inv_due->format( 'c' ) : $inv_due_str ); ?>">
									<?php echo esc_html( $inv_due_str ); ?>
								</time>

							<?php elseif ( 'invoice-amount-due' === $column_id ) : ?>
								<?php echo esc_html( $currency_sym . number_format( $amount_due, 2 ) ); ?>

							<?php elseif ( 'invoice-total' === $column_id ) : ?>
								<?php echo esc_html( $currency_sym . number_format( $total, 2 ) ); ?>

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

	<?php
	$showing_less = count( $xero_invoices ) < $per_page;
	if ( $inv_page > 1 || ! $showing_less ) :
	?>
	<div class="woocommerce-pagination woocommerce-pagination--without-numbers woocommerce-Pagination">
		<?php if ( $inv_page > 1 ) : ?>
			<a class="woocommerce-button woocommerce-button--previous woocommerce-Button woocommerce-Button--previous button<?php echo esc_attr( $wp_button_class ); ?>" href="<?php echo esc_url( add_query_arg( 'inv_page', $inv_page - 1 ) ); ?>">
				<?php esc_html_e( 'Previous', 'myworks-sync-for-xero' ); ?>
			</a>
		<?php endif; ?>
		<?php if ( ! $showing_less ) : ?>
			<a class="woocommerce-button woocommerce-button--next woocommerce-Button woocommerce-Button--next button<?php echo esc_attr( $wp_button_class ); ?>" href="<?php echo esc_url( add_query_arg( 'inv_page', $inv_page + 1 ) ); ?>">
				<?php esc_html_e( 'Next', 'myworks-sync-for-xero' ); ?>
			</a>
		<?php endif; ?>
	</div>
	<?php endif; ?>

<?php elseif ( empty( $xero_contact_id ) ) : ?>

	<?php wc_print_notice( esc_html__( 'No invoices available — your account is not mapped to a Xero contact.', 'myworks-sync-for-xero' ), 'notice' ); ?>

<?php else : ?>

	<?php wc_print_notice( esc_html__( 'No invoices found.', 'myworks-sync-for-xero' ), 'notice' ); ?>

<?php endif; ?>

<?php do_action( 'myworks_xero_after_account_invoices', $has_invoices ); ?>
