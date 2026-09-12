<?php
if ( ! defined( 'WPINC' ) ) {
	die;
}

class MyWorks_WC_Xero_Sync_Lib_Frontend {

	private $per_page = 10;

	/**
	 * How long a cached online-invoice URL is reused. These URLs are stable for the life of the
	 * invoice, so this bounds staleness if Xero ever reissues one; it isn't a correctness limit.
	 */
	const ONLINE_URL_CACHE_TTL = WEEK_IN_SECONDS;

	public function __construct() {
		if ( get_option( 'mw_wc_xero_sync_invoice_tab_in_cus_acc_area' ) == 'true' ) {
			add_action( 'init', array( $this, 'invoice_user_management_account_endpoints' ) );
			add_filter( 'woocommerce_account_menu_items', array( $this, 'invoice_user_management_account_menu_items' ) );
			add_action( 'woocommerce_account_invoices_endpoint', array( $this, 'invoice_user_management_endpoint_content' ) );
			add_action( 'wp_ajax_myworks_wc_xero_sync_customer_invoice_pdf', array( $this, 'customer_invoice_pdf' ) );
		}
	}

	public function invoice_user_management_account_endpoints() {
		add_rewrite_endpoint( 'invoices', EP_PAGES );
		if ( get_option( 'mwxs_acc_inv_shortcode' ) != 'true' ) {
			flush_rewrite_rules();
			update_option( 'mwxs_acc_inv_shortcode', 'true', true );
		}
	}

	public function invoice_user_management_account_menu_items( $items ) {
		$items['invoices'] = __( 'Invoices', 'myworks-sync-for-xero' );
		return $items;
	}

	/**
	 * Frontend AJAX handler: streams a Xero invoice PDF to the logged-in customer.
	 * Verifies the invoice belongs to the requesting user via map_customers before fetching.
	 */
	public function customer_invoice_pdf() {
		global $MWXS_L, $wpdb;

		check_ajax_referer( 'mwxs_customer_invoice_pdf' );

		if ( ! is_user_logged_in() ) {
			wp_die( 'Unauthorized', '', array( 'response' => 403 ) );
		}

		$invoice_id = isset( $_GET['invoice_id'] ) ? sanitize_text_field( wp_unslash( $_GET['invoice_id'] ) ) : '';
		// Validate UUID format (8-4-4-4-12 hex).
		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $invoice_id ) ) {
			wp_die( 'Invalid Invoice ID', '', array( 'response' => 400 ) );
		}

		// Verify the invoice belongs to the logged-in user's mapped Xero contact.
		$wc_user_id      = (int) get_current_user_id();
		$xero_contact_id = '';
		$validated_map_tbl = $MWXS_L->get_validated_table_name( 'map_customers' );
		if ( $validated_map_tbl ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table validated via whitelist
			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT `X_C_ID` FROM `" . esc_sql( $validated_map_tbl ) . "` WHERE `W_C_ID` = %d AND `X_C_ID` !='' LIMIT 1", $wc_user_id ),
				ARRAY_A
			);
			if ( $row && ! empty( $row['X_C_ID'] ) ) {
				$xero_contact_id = $row['X_C_ID'];
			}
		}

		if ( empty( $xero_contact_id ) ) {
			wp_die( 'Unauthorized', '', array( 'response' => 403 ) );
		}

		$MWXS_L->xero_connect();
		if ( ! $MWXS_L->is_xero_connected() ) {
			wp_die( 'Xero not connected', '', array( 'response' => 503 ) );
		}

		// Confirm the invoice belongs to this contact before streaming.
		// summary_only is FALSE and must stay so. It looks like the ideal case for it — this is only an
		// existence/ownership check, nothing here reads the invoice contents, and the PDF itself comes
		// from the separate wp_remote_get() below. But Xero answers
		//   [400] The supplied filter is unavailable on this endpoint when using the summaryOnly flag
		// and this request filters on both `ids` and `contact_ids`. #87 set it to true and it shipped;
		// here that meant a 403 on a customer's own invoice download. See bugs-fixed.md 2026-08-17.
		try {
			$check = $MWXS_L->X_API_I()->getInvoices(
				$MWXS_L->get_xero_tenant_id(),
				null, null, null,
				array( $invoice_id ),       // ids
				null,
				array( $xero_contact_id ),  // contact_ids — must match
				null,
				1, null, null, null, false, 1
			);
		} catch ( Exception $e ) {
			wp_die( 'Unauthorized', '', array( 'response' => 403 ) );
		}

		if ( ! $check || empty( $check->getInvoices() ) ) {
			wp_die( 'Unauthorized', '', array( 'response' => 403 ) );
		}

		$access_token = $MWXS_L->get_xero_access_token();
		$tenant_id    = $MWXS_L->get_xero_tenant_id();

		if ( empty( $access_token ) || empty( $tenant_id ) ) {
			wp_die( 'Missing Xero Authorization Details', '', array( 'response' => 400 ) );
		}

		$response = wp_remote_get(
			"https://api.xero.com/api.xro/2.0/Invoices/{$invoice_id}",
			array(
				'headers' => array(
					'Authorization'  => "Bearer {$access_token}",
					'Xero-tenant-id' => $tenant_id,
					'Accept'         => 'application/pdf',
				),
				'timeout'   => 30,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_die( 'Failed to fetch invoice PDF: ' . esc_html( $response->get_error_message() ), '', array( 'response' => 500 ) );
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$pdf       = wp_remote_retrieve_body( $response );

		if ( $http_code !== 200 || empty( $pdf ) || substr( $pdf, 0, 4 ) !== '%PDF' ) {
			wp_die( 'Failed to fetch invoice PDF', '', array( 'response' => 500 ) );
		}

		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="xero-invoice.pdf"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary PDF content
		exit;
	}

	/**
	 * Fetch online invoice URLs for a set of invoices (AUTHORISED only).
	 * Returns an array keyed by invoice UUID => online URL string.
	 *
	 * One getOnlineInvoice() call per AUTHORISED invoice, so a 10-row page cost up to 10 Xero calls
	 * on EVERY view — and this is a customer-facing page, so it scaled with shopper traffic rather
	 * than merchant activity. The URL is a stable per-invoice value, so it is cached per invoice
	 * UUID and only uncached invoices are fetched. Refs #87
	 *
	 * @param array $invoices  Array of Xero Invoice SDK objects.
	 * @return array
	 */
	private function get_online_invoice_urls( array $invoices ) {
		global $MWXS_L;

		$online_urls = array();
		$tenant_id   = $MWXS_L->get_xero_tenant_id();

		foreach ( $invoices as $invoice ) {
			if ( 'AUTHORISED' !== (string) $invoice->getStatus() ) {
				continue;
			}

			$invoice_id = (string) $invoice->getInvoiceId();
			if ( empty( $invoice_id ) ) {
				continue;
			}

			// Transients land in wp_options, so the key carries the mandatory plugin prefix.
			$cache_key = 'mw_wc_xero_sync_oiu_' . md5( $tenant_id . '|' . $invoice_id );
			$cached    = get_transient( $cache_key );
			if ( ! empty( $cached ) && is_string( $cached ) ) {
				$online_urls[ $invoice_id ] = $cached;
				continue;
			}

			try {
				$result = $MWXS_L->X_API_I()->getOnlineInvoice( $tenant_id, $invoice_id );
				if ( $result && $result->getOnlineInvoices() ) {
					$url = $result->getOnlineInvoices()[0]->getOnlineInvoiceUrl();
					if ( $url ) {
						$online_urls[ $invoice_id ] = (string) $url;
						set_transient( $cache_key, (string) $url, self::ONLINE_URL_CACHE_TTL );
					}
				}
			} catch ( Exception $e ) {
				// Skip — online URL is best-effort. Deliberately not cached: a failure here is
				// transient (rate limit, transport), and caching it would suppress the Pay button
				// for the whole TTL over one bad request.
			}
		}

		return $online_urls;
	}

	/**
	 * Render the invoices endpoint — mirrors exactly how WooCommerce renders
	 * woocommerce_account_orders_endpoint via wc_get_template().
	 *
	 * @param mixed $current_page  Query var value passed by WooCommerce endpoint system.
	 */
	public function invoice_user_management_endpoint_content( $current_page ) {
		global $MWXS_L, $wpdb;

		$current_page = max( 1, absint( $current_page ) );

		// Resolve Xero ContactID for the current WC user.
		$wc_user_id      = (int) get_current_user_id();
		$xero_contact_id = '';

		if ( $wc_user_id > 0 ) {
			$validated_map_tbl = $MWXS_L->get_validated_table_name( 'map_customers' );
			if ( $validated_map_tbl ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table validated via whitelist
				$row = $wpdb->get_row(
					$wpdb->prepare( "SELECT `X_C_ID` FROM `" . esc_sql( $validated_map_tbl ) . "` WHERE `W_C_ID` = %d AND `X_C_ID` !='' LIMIT 1", $wc_user_id ),
					ARRAY_A
				);
				if ( $row && ! empty( $row['X_C_ID'] ) ) {
					$xero_contact_id = $row['X_C_ID'];
				}
			}
		}

		$xero_invoices = array();

		if ( ! empty( $xero_contact_id ) ) {
			$MWXS_L->xero_connect();

			if ( $MWXS_L->is_xero_connected() ) {
				try {
					$result = $MWXS_L->X_API_I()->getInvoices(
						$MWXS_L->get_xero_tenant_id(),
						null,
						'Type=="ACCREC"',
						'Date DESC',
						null,
						null,
						array( $xero_contact_id ),
						array( 'AUTHORISED', 'PAID' ),
						$current_page,
						null,
						null,
						$MWXS_L->x_unitdp(),
						false,
						$this->per_page
					);
					if ( $result ) {
						$xero_invoices = $result->getInvoices();
					}
				} catch ( Exception $e ) {
					$xero_invoices = array();
				}
			}
		}

		$has_invoices = is_array( $xero_invoices ) && count( $xero_invoices ) > 0;
		$has_more     = $has_invoices && count( $xero_invoices ) >= $this->per_page;
		$online_urls  = $has_invoices ? $this->get_online_invoice_urls( $xero_invoices ) : array();

		wc_get_template(
			'myaccount/xero-invoices.php',
			array(
				'current_page'    => $current_page,
				'xero_invoices'   => $xero_invoices,
				'has_invoices'    => $has_invoices,
				'has_more'        => $has_more,
				'is_mapped'       => ! empty( $xero_contact_id ),
				'online_urls'     => $online_urls,
				'wp_button_class' => function_exists( 'wc_wp_theme_get_element_class_name' ) && wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '',
			),
			'',
			plugin_dir_path( __DIR__ ) . 'public/partials/'
		);
	}
}

new MyWorks_WC_Xero_Sync_Lib_Frontend();
