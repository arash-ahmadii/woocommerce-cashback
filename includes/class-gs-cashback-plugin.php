<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DWCB_Cashback_Plugin {

	/**
	 * @var self|null
	 */
	private static $instance = null;

	const ENDPOINT = 'cashback';

	/**
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * @return void
	 */
	public static function activate() {
		self::create_ledger_table();
		self::add_rewrite_endpoint_rule();
		flush_rewrite_rules();
	}

	/**
	 * @return void
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}

	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'bootstrap' ), 5 );
	}

	/**
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'dashweb-cashback', false, dirname( plugin_basename( DWCB_CASHBACK_FILE ) ) . '/languages' );
	}

	/**
	 * @return void
	 */
	public function bootstrap() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'notice_wc_required' ) );
			return;
		}

		self::create_ledger_table();
		self::add_rewrite_endpoint_rule();

		add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_action( 'woocommerce_review_order_before_payment', array( $this, 'render_checkout_cashback_field' ) );
		add_action( 'woocommerce_checkout_update_order_review', array( $this, 'capture_checkout_toggle' ) );
		add_action( 'woocommerce_checkout_process', array( $this, 'capture_checkout_toggle_from_post' ) );
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'apply_checkout_discount' ), 20 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'store_discount_intent_on_order' ), 20, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'clear_cashback_session_after_order' ) );

		add_action( 'woocommerce_order_status_processing', array( $this, 'debit_cashback_after_paid_order' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'debit_cashback_after_paid_order' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'credit_cashback_for_completed_order' ), 20 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'credit_cashback_for_processing_order' ), 20 );
		add_action( 'woocommerce_order_refunded', array( $this, 'handle_order_refunded' ), 20, 2 );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'restore_debit_on_cancelled_or_failed' ), 20 );
		add_action( 'woocommerce_order_status_failed', array( $this, 'restore_debit_on_cancelled_or_failed' ), 20 );

		add_filter( 'woocommerce_account_menu_items', array( $this, 'add_my_account_menu_item' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render_my_account_cashback_page' ) );
		add_action( 'woocommerce_account_dashboard', array( $this, 'render_dashboard_widget' ) );
		add_filter( 'woocommerce_get_order_item_totals', array( $this, 'append_cashback_rows_to_order_totals' ), 20, 3 );
		add_shortcode( 'gs_cashback_balance', array( $this, 'render_balance_shortcode' ) );
	}

	/**
	 * @return void
	 */
	public static function add_rewrite_endpoint_rule() {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/**
	 * @return void
	 */
	public function notice_wc_required() {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Grand Sky Cashback requires WooCommerce.', 'gs-cashback' ) . '</p></div>';
	}

	/**
	 * @return void
	 */
	public function register_settings_page() {
		add_submenu_page(
			'woocommerce',
			__( 'Cashback', 'gs-cashback' ),
			__( 'Cashback', 'gs-cashback' ),
			'manage_woocommerce',
			'gs-cashback-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * @return void
	 */
	public function render_settings_page() {
		if ( isset( $_POST['gs_cashback_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gs_cashback_settings_nonce'] ) ), 'gs_cashback_save_settings' ) ) {
			$rate     = isset( $_POST['gs_cashback_rate'] ) ? (float) wp_unslash( $_POST['gs_cashback_rate'] ) : 5;
			$max      = isset( $_POST['gs_cashback_max_redeem_percent'] ) ? (float) wp_unslash( $_POST['gs_cashback_max_redeem_percent'] ) : 30;
			$min      = isset( $_POST['gs_cashback_min_order_total'] ) ? (float) wp_unslash( $_POST['gs_cashback_min_order_total'] ) : 0;
			$expiry   = isset( $_POST['gs_cashback_expiry_days'] ) ? (int) wp_unslash( $_POST['gs_cashback_expiry_days'] ) : 0;
			$ship_in  = ! empty( $_POST['gs_cashback_include_shipping'] ) ? 'yes' : 'no';
			$tax_in   = ! empty( $_POST['gs_cashback_include_tax'] ) ? 'yes' : 'no';
			$on_proc  = ! empty( $_POST['gs_cashback_award_on_processing'] ) ? 'yes' : 'no';

			update_option( 'gs_cashback_rate', max( 0, min( 100, $rate ) ) );
			update_option( 'gs_cashback_max_redeem_percent', max( 1, min( 100, $max ) ) );
			update_option( 'gs_cashback_min_order_total', max( 0, $min ) );
			update_option( 'gs_cashback_expiry_days', max( 0, $expiry ) );
			update_option( 'gs_cashback_include_shipping', $ship_in );
			update_option( 'gs_cashback_include_tax', $tax_in );
			update_option( 'gs_cashback_award_on_processing', $on_proc );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Cashback settings saved.', 'gs-cashback' ) . '</p></div>';
		}
		if ( isset( $_POST['gs_cashback_adjust_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gs_cashback_adjust_nonce'] ) ), 'gs_cashback_adjust_balance' ) ) {
			$this->handle_manual_adjustment();
		}

		$rate    = $this->get_rate_percent();
		$max     = $this->get_max_redeem_percent();
		$min     = $this->get_min_order_total();
		$expiry  = $this->get_expiry_days();
		$ship_in = 'yes' === get_option( 'gs_cashback_include_shipping', 'no' );
		$tax_in  = 'yes' === get_option( 'gs_cashback_include_tax', 'no' );
		$on_proc = 'yes' === get_option( 'gs_cashback_award_on_processing', 'no' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Cashback Settings', 'gs-cashback' ); ?></h1>
			<form method="post">
				<?php wp_nonce_field( 'gs_cashback_save_settings', 'gs_cashback_settings_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="gs_cashback_rate"><?php esc_html_e( 'Cashback Rate (%)', 'gs-cashback' ); ?></label></th>
						<td><input name="gs_cashback_rate" id="gs_cashback_rate" type="number" min="0" max="100" step="0.1" value="<?php echo esc_attr( (string) $rate ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="gs_cashback_max_redeem_percent"><?php esc_html_e( 'Max redeemable per order (%)', 'gs-cashback' ); ?></label></th>
						<td><input name="gs_cashback_max_redeem_percent" id="gs_cashback_max_redeem_percent" type="number" min="1" max="100" step="1" value="<?php echo esc_attr( (string) $max ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="gs_cashback_min_order_total"><?php esc_html_e( 'Minimum order total for cashback', 'gs-cashback' ); ?></label></th>
						<td><input name="gs_cashback_min_order_total" id="gs_cashback_min_order_total" type="number" min="0" step="0.01" value="<?php echo esc_attr( (string) $min ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="gs_cashback_expiry_days"><?php esc_html_e( 'Expiry in days (0 = never)', 'gs-cashback' ); ?></label></th>
						<td><input name="gs_cashback_expiry_days" id="gs_cashback_expiry_days" type="number" min="0" step="1" value="<?php echo esc_attr( (string) $expiry ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Include in cashback base', 'gs-cashback' ); ?></th>
						<td>
							<label><input type="checkbox" name="gs_cashback_include_shipping" value="1" <?php checked( $ship_in, true ); ?> /> <?php esc_html_e( 'Include shipping costs', 'gs-cashback' ); ?></label><br>
							<label><input type="checkbox" name="gs_cashback_include_tax" value="1" <?php checked( $tax_in, true ); ?> /> <?php esc_html_e( 'Include tax', 'gs-cashback' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Credit trigger status', 'gs-cashback' ); ?></th>
						<td>
							<label><input type="checkbox" name="gs_cashback_award_on_processing" value="1" <?php checked( $on_proc, true ); ?> /> <?php esc_html_e( 'Credit already on Processing', 'gs-cashback' ); ?></label>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save', 'gs-cashback' ) ); ?>
			</form>

			<hr>
			<h2><?php esc_html_e( 'Manual balance transaction', 'gs-cashback' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'gs_cashback_adjust_balance', 'gs_cashback_adjust_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="gs_adjust_user"><?php esc_html_e( 'Customer (email or user ID)', 'gs-cashback' ); ?></label></th>
						<td><input name="gs_adjust_user" id="gs_adjust_user" type="text" class="regular-text" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="gs_adjust_amount"><?php esc_html_e( 'Amount (+/-)', 'gs-cashback' ); ?></label></th>
						<td><input name="gs_adjust_amount" id="gs_adjust_amount" type="number" step="0.01" class="regular-text" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="gs_adjust_note"><?php esc_html_e( 'Note', 'gs-cashback' ); ?></label></th>
						<td><input name="gs_adjust_note" id="gs_adjust_note" type="text" class="regular-text" value="<?php echo esc_attr__( 'Manual adjustment by admin', 'gs-cashback' ); ?>"></td>
					</tr>
				</table>
				<?php submit_button( __( 'Submit transaction', 'gs-cashback' ), 'secondary' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * @return void
	 */
	public function enqueue_assets() {
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			wp_enqueue_style(
				'gs-cashback-frontend',
				DWCB_CASHBACK_URL . 'assets/css/frontend.css',
				array(),
				DWCB_CASHBACK_VERSION
			);
		}
		if ( function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page() ) {
			wp_enqueue_script(
				'gs-cashback-checkout',
				DWCB_CASHBACK_URL . 'assets/js/checkout.js',
				array( 'jquery', 'wc-checkout' ),
				DWCB_CASHBACK_VERSION,
				true
			);
		}
	}

	/**
	 * @return void
	 */
	public function render_checkout_cashback_field() {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$balance = $this->get_user_balance( get_current_user_id() );
		if ( $balance <= 0 ) {
			return;
		}
		$max_possible = $this->get_redeemable_amount_from_cart( WC()->cart, $balance );
		if ( $max_possible <= 0 ) {
			return;
		}
		$checked = WC()->session && 'yes' === WC()->session->get( 'gs_cashback_use', 'no' );
		echo '<div class="gs-cashback-checkout woocommerce-additional-fields">';
		echo '<h3>' . esc_html__( 'Cashback', 'gs-cashback' ) . '</h3>';
		echo '<p><label><input type="checkbox" name="gs_use_cashback" value="1" ' . checked( $checked, true, false ) . ' /> ' . esc_html__( 'Use cashback balance for this order', 'gs-cashback' ) . '</label></p>';
		echo '<p class="gs-cashback-muted">' . sprintf( esc_html__( 'Available: %1$s · Max redeemable now: %2$s', 'gs-cashback' ), wp_kses_post( wc_price( $balance ) ), wp_kses_post( wc_price( $max_possible ) ) ) . '</p>';
		echo '</div>';
	}

	/**
	 * @param string $posted_data Posted checkout payload.
	 * @return void
	 */
	public function capture_checkout_toggle( $posted_data ) {
		if ( ! WC()->session ) {
			return;
		}
		parse_str( $posted_data, $data );
		$use = ! empty( $data['gs_use_cashback'] ) ? 'yes' : 'no';
		WC()->session->set( 'gs_cashback_use', $use );
	}

	public function capture_checkout_toggle_from_post() {
		if ( ! WC()->session ) {
			return;
		}
		$use = ! empty( $_POST['gs_use_cashback'] ) ? 'yes' : 'no'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		WC()->session->set( 'gs_cashback_use', $use );
	}

	/**
	 * @param WC_Cart $cart Cart.
	 * @return void
	 */
	public function apply_checkout_discount( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		if ( ! is_user_logged_in() || ! WC()->session || ! $cart instanceof WC_Cart ) {
			return;
		}
		if ( 'yes' !== WC()->session->get( 'gs_cashback_use', 'no' ) ) {
			WC()->session->set( 'gs_cashback_discount_amount', 0 );
			return;
		}

		$balance  = $this->get_user_balance( get_current_user_id() );
		$discount = $this->get_redeemable_amount_from_cart( $cart, $balance );
		if ( $discount <= 0 ) {
			WC()->session->set( 'gs_cashback_discount_amount', 0 );
			return;
		}

		$cart->add_fee( __( 'Cashback Balance', 'gs-cashback' ), -$discount, false );
		WC()->session->set( 'gs_cashback_discount_amount', $discount );
	}

	/**
	 * @param WC_Order $order Order object.
	 * @param array    $data Checkout data.
	 * @return void
	 */
	public function store_discount_intent_on_order( $order, $data ) {
		if ( ! $order instanceof WC_Order || ! is_user_logged_in() || ! WC()->session ) {
			return;
		}
		$order->update_meta_data( '_gs_cashback_rate_percent', wc_format_decimal( $this->get_rate_percent(), 2 ) );
		$order->update_meta_data( '_gs_cashback_estimated_amount', wc_format_decimal( $this->calculate_cashback_estimate_for_order( $order ), 2 ) );
		if ( 'yes' !== WC()->session->get( 'gs_cashback_use', 'no' ) ) {
			return;
		}
		$amount = (float) WC()->session->get( 'gs_cashback_discount_amount', 0 );
		if ( $amount > 0 ) {
			$order->update_meta_data( '_gs_cashback_discount_requested', wc_format_decimal( $amount, 2 ) );
		}
	}

	/**
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function clear_cashback_session_after_order( $order_id ) {
		if ( WC()->session ) {
			WC()->session->set( 'gs_cashback_use', 'no' );
			WC()->session->set( 'gs_cashback_discount_amount', 0 );
		}
	}

	/**
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function debit_cashback_after_paid_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		if ( $order->get_meta( '_gs_cashback_debit_done', true ) ) {
			return;
		}
		$user_id = (int) $order->get_user_id();
		if ( $user_id <= 0 ) {
			return;
		}
		$requested = (float) $order->get_meta( '_gs_cashback_discount_requested', true );
		if ( $requested <= 0 ) {
			return;
		}
		$balance = $this->get_user_balance( $user_id );
		$debit   = min( $balance, $requested );
		if ( $debit <= 0 ) {
			return;
		}
		$this->insert_ledger_transaction( $user_id, $order->get_id(), 'debit_checkout', -$debit, __( 'Redeemed at checkout', 'gs-cashback' ) );
		$order->update_meta_data( '_gs_cashback_debit_done', 'yes' );
		$order->update_meta_data( '_gs_cashback_debit_amount', wc_format_decimal( $debit, 2 ) );
		$order->save();
	}

	public function credit_cashback_for_processing_order( $order_id ) {
		if ( 'yes' !== get_option( 'gs_cashback_award_on_processing', 'no' ) ) {
			return;
		}
		$this->credit_cashback_for_order( $order_id );
	}

	/**
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function credit_cashback_for_completed_order( $order_id ) {
		$this->credit_cashback_for_order( $order_id );
	}

	/**
	 * @param int $order_id Order ID.
	 * @return void
	 */
	private function credit_cashback_for_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		if ( $order->get_meta( '_gs_cashback_credit_done', true ) ) {
			return;
		}
		$user_id = (int) $order->get_user_id();
		if ( $user_id <= 0 ) {
			return;
		}
		$eligible_total = max( 0, (float) $order->get_subtotal() - (float) $order->get_discount_total() );
		if ( 'yes' === get_option( 'gs_cashback_include_shipping', 'no' ) ) {
			$eligible_total += (float) $order->get_shipping_total();
		}
		if ( 'yes' === get_option( 'gs_cashback_include_tax', 'no' ) ) {
			$eligible_total += (float) $order->get_total_tax();
		}
		$debited_cashback = (float) $order->get_meta( '_gs_cashback_debit_amount', true );
		if ( $debited_cashback > 0 ) {
			$eligible_total = max( 0, $eligible_total - $debited_cashback );
		}
		if ( $eligible_total < $this->get_min_order_total() ) {
			$order->update_meta_data( '_gs_cashback_credit_done', 'skipped' );
			$order->save();
			return;
		}
		$rate   = $this->get_rate_percent() / 100;
		$credit = round( $eligible_total * $rate, 2 );
		if ( $credit <= 0 ) {
			$order->update_meta_data( '_gs_cashback_credit_done', 'skipped' );
			$order->save();
			return;
		}
		$expires_at = $this->compute_expiry_datetime_utc();
		$this->insert_ledger_transaction( $user_id, $order->get_id(), 'credit_cashback', $credit, __( 'Cashback from order', 'gs-cashback' ), $expires_at, array( 'source' => 'order_complete' ) );
		$order->update_meta_data( '_gs_cashback_credit_done', 'yes' );
		$order->update_meta_data( '_gs_cashback_credit_amount', wc_format_decimal( $credit, 2 ) );
		$order->save();
	}

	public function handle_order_refunded( $order_id, $refund_id ) {
		$order  = wc_get_order( $order_id );
		$refund = wc_get_order( $refund_id );
		if ( ! $order || ! $refund instanceof WC_Order_Refund ) {
			return;
		}
		if ( $refund->get_meta( '_gs_cashback_refund_processed', true ) ) {
			return;
		}
		$user_id = (int) $order->get_user_id();
		if ( $user_id <= 0 ) {
			$refund->update_meta_data( '_gs_cashback_refund_processed', 'skipped' );
			$refund->save();
			return;
		}

		$parent_total = max( 0.01, (float) $order->get_total() );
		$cum_ratio    = min( 1, (float) $order->get_total_refunded() / $parent_total );

		$credited      = (float) $order->get_meta( '_gs_cashback_credit_amount', true );
		$credit_alread = (float) $order->get_meta( '_gs_cashback_credit_adjusted_total', true );
		$credit_target = round( max( 0, $credited ) * $cum_ratio, 2 );
		$credit_delta  = round( max( 0, $credit_target - $credit_alread ), 2 );
		if ( $credit_delta > 0 ) {
			$this->insert_ledger_transaction( $user_id, $order_id, 'debit_refund_adjustment', -$credit_delta, __( 'Cashback adjustment due to refund', 'gs-cashback' ), null, array( 'refund_id' => $refund_id ) );
			$order->update_meta_data( '_gs_cashback_credit_adjusted_total', wc_format_decimal( $credit_alread + $credit_delta, 2 ) );
		}

		$debited       = (float) $order->get_meta( '_gs_cashback_debit_amount', true );
		$rest_already  = (float) $order->get_meta( '_gs_cashback_debit_restored_total', true );
		$restore_target = round( max( 0, $debited ) * $cum_ratio, 2 );
		$restore_delta  = round( max( 0, $restore_target - $rest_already ), 2 );
		if ( $restore_delta > 0 ) {
			$this->insert_ledger_transaction( $user_id, $order_id, 'credit_refund_restore', $restore_delta, __( 'Restore redeemed cashback', 'gs-cashback' ), null, array( 'refund_id' => $refund_id ) );
			$order->update_meta_data( '_gs_cashback_debit_restored_total', wc_format_decimal( $rest_already + $restore_delta, 2 ) );
		}

		$order->save();
		$refund->update_meta_data( '_gs_cashback_refund_processed', 'yes' );
		$refund->save();
	}

	public function restore_debit_on_cancelled_or_failed( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		if ( $order->get_meta( '_gs_cashback_cancel_restore_done', true ) ) {
			return;
		}
		if ( (float) $order->get_total_refunded() > 0 ) {
			return;
		}
		$user_id = (int) $order->get_user_id();
		if ( $user_id <= 0 ) {
			return;
		}
		$debited = (float) $order->get_meta( '_gs_cashback_debit_amount', true );
		if ( $debited <= 0 ) {
			return;
		}
		$this->insert_ledger_transaction( $user_id, $order_id, 'credit_cancel_restore', $debited, __( 'Cashback restored (order cancelled/failed)', 'gs-cashback' ) );
		$order->update_meta_data( '_gs_cashback_cancel_restore_done', 'yes' );
		$order->save();
	}

	/**
	 * @param array<string,string> $items Items.
	 * @return array<string,string>
	 */
	public function add_my_account_menu_item( $items ) {
		$logout = '';
		if ( isset( $items['customer-logout'] ) ) {
			$logout = $items['customer-logout'];
			unset( $items['customer-logout'] );
		}
		$items[ self::ENDPOINT ] = __( 'Cashback', 'gs-cashback' );
		if ( $logout ) {
			$items['customer-logout'] = $logout;
		}
		return $items;
	}

	/**
	 * @return void
	 */
	public function render_my_account_cashback_page() {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$user_id      = get_current_user_id();
		$balance      = $this->get_user_balance( $user_id );
		$transactions = $this->get_user_transactions( $user_id, 25 );
		$pending      = $this->get_pending_cashback_amount( $user_id );
		?>
		<div class="gs-cashback-panel">
			<h3><?php esc_html_e( 'Your Cashback', 'gs-cashback' ); ?></h3>
			<div class="gs-cashback-balance">
				<span class="gs-cashback-label"><?php esc_html_e( 'Available balance', 'gs-cashback' ); ?></span>
				<strong><?php echo wp_kses_post( wc_price( $balance ) ); ?></strong>
			</div>
			<p class="gs-cashback-muted">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: cashback percentage, 2: max redeem percent */
						__( 'You earn %1$s%% cashback per completed purchase and can redeem up to %2$s%% per order.', 'gs-cashback' ),
						$this->get_rate_percent(),
						$this->get_max_redeem_percent()
					)
				);
				?>
			</p>
			<p class="gs-cashback-muted"><?php echo esc_html( sprintf( __( 'Pending from ongoing orders: %s', 'gs-cashback' ), wp_strip_all_tags( wc_price( $pending ) ) ) ); ?></p>
			<h4><?php esc_html_e( 'Transactions', 'gs-cashback' ); ?></h4>
			<table class="shop_table shop_table_responsive my_account_orders gs-cashback-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'gs-cashback' ); ?></th>
						<th><?php esc_html_e( 'Type', 'gs-cashback' ); ?></th>
						<th><?php esc_html_e( 'Note', 'gs-cashback' ); ?></th>
						<th><?php esc_html_e( 'Amount', 'gs-cashback' ); ?></th>
						<th><?php esc_html_e( 'Expiry', 'gs-cashback' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $transactions ) ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'No cashback transactions yet.', 'gs-cashback' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $transactions as $txn ) : ?>
							<tr>
								<td><?php echo esc_html( wp_date( 'Y-m-d H:i', strtotime( (string) $txn['created_at'] ) ) ); ?></td>
								<td><?php echo esc_html( $this->label_for_type( (string) $txn['type'] ) ); ?></td>
								<td><?php echo esc_html( (string) $txn['note'] ); ?></td>
								<td class="<?php echo ( (float) $txn['amount'] >= 0 ) ? 'gs-positive' : 'gs-negative'; ?>">
									<?php echo wp_kses_post( wc_price( (float) $txn['amount'] ) ); ?>
								</td>
								<td><?php echo ! empty( $txn['expires_at'] ) ? esc_html( wp_date( 'Y-m-d', strtotime( (string) $txn['expires_at'] ) ) ) : '—'; ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public function render_dashboard_widget() {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$balance = $this->get_user_balance( get_current_user_id() );
		echo '<div class="gs-cashback-panel" style="margin-top:18px">';
		echo '<h4>' . esc_html__( 'Cashback Balance', 'gs-cashback' ) . '</h4>';
		echo '<p><strong>' . wp_kses_post( wc_price( $balance ) ) . '</strong></p>';
		echo '<p><a class="button" href="' . esc_url( wc_get_account_endpoint_url( self::ENDPOINT ) ) . '">' . esc_html__( 'Cashback Details', 'gs-cashback' ) . '</a></p>';
		echo '</div>';
	}

	public function render_balance_shortcode( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		$balance = $this->get_user_balance( get_current_user_id() );
		return '<span class="gs-cashback-shortcode-balance">' . wp_kses_post( wc_price( $balance ) ) . '</span>';
	}

	public function append_cashback_rows_to_order_totals( $totals, $order, $tax_display ) {
		if ( ! $order instanceof WC_Order ) {
			return $totals;
		}
		$debited = (float) $order->get_meta( '_gs_cashback_debit_amount', true );

		$clean_totals = array();
		foreach ( $totals as $key => $row ) {
			$label_text = isset( $row['label'] ) ? wp_strip_all_tags( (string) $row['label'] ) : '';
			if ( false !== stripos( $label_text, 'cashback' ) ) {
				continue;
			}
			$clean_totals[ $key ] = $row;
		}

		$injected = array();
		foreach ( $clean_totals as $key => $row ) {
			if ( 'order_total' === $key ) {
				if ( $debited > 0 ) {
					$injected['gs_cashback_used'] = array(
						'label' => __( 'Cashback Redeemed', 'gs-cashback' ),
						'value' => '-' . wc_price( $debited, array( 'currency' => $order->get_currency() ) ),
					);
				}
			}
			$injected[ $key ] = $row;
		}

		return $injected;
	}

	/**
	 * @param string $type Type.
	 * @return string
	 */
	private function label_for_type( $type ) {
		$map = array(
			'credit_cashback' => __( 'Cashback', 'gs-cashback' ),
			'debit_checkout'  => __( 'Redemption', 'gs-cashback' ),
			'adjustment'      => __( 'Adjustment', 'gs-cashback' ),
			'adjustment_credit' => __( 'Manual credit', 'gs-cashback' ),
			'adjustment_debit'  => __( 'Manual debit', 'gs-cashback' ),
			'debit_refund_adjustment' => __( 'Adjustment after refund', 'gs-cashback' ),
			'credit_refund_restore'   => __( 'Redeemed cashback restored', 'gs-cashback' ),
			'credit_cancel_restore'   => __( 'Reversal (cancelled)', 'gs-cashback' ),
		);
		return isset( $map[ $type ] ) ? $map[ $type ] : $type;
	}

	/**
	 * @param WC_Cart $cart Cart.
	 * @param float   $balance User balance.
	 * @return float
	 */
	private function get_redeemable_amount_from_cart( $cart, $balance ) {
		if ( ! $cart instanceof WC_Cart || $balance <= 0 ) {
			return 0.0;
		}
		$total_before_fees = max( 0, (float) $cart->get_subtotal() );
		if ( $total_before_fees <= 0 ) {
			return 0.0;
		}
		$max_percent = $this->get_max_redeem_percent() / 100;
		$max_allowed = $total_before_fees * $max_percent;
		return round( max( 0, min( $balance, $max_allowed ) ), 2 );
	}

	/**
	 * @return float
	 */
	private function get_rate_percent() {
		return (float) get_option( 'gs_cashback_rate', 5 );
	}

	/**
	 * @return float
	 */
	private function get_max_redeem_percent() {
		return (float) get_option( 'gs_cashback_max_redeem_percent', 30 );
	}

	/**
	 * @return float
	 */
	private function get_min_order_total() {
		return (float) get_option( 'gs_cashback_min_order_total', 0 );
	}

	/**
	 * @return int
	 */
	private function get_expiry_days() {
		return (int) get_option( 'gs_cashback_expiry_days', 0 );
	}

	/**
	 * @param int $user_id User ID.
	 * @return float
	 */
	private function get_user_balance( $user_id ) {
		global $wpdb;
		$table = $this->table_name();
		$sum   = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(
					CASE
						WHEN amount >= 0 THEN CASE WHEN expires_at IS NULL OR expires_at >= UTC_TIMESTAMP() THEN amount ELSE 0 END
						ELSE amount
					END
				),0)
				FROM {$table}
				WHERE user_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id
			)
		);
		return round( (float) $sum, 2 );
	}

	/**
	 * @param int $user_id User ID.
	 * @param int $limit Limit.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_user_transactions( $user_id, $limit = 25 ) {
		global $wpdb;
		$table = $this->table_name();
		$sql   = $wpdb->prepare(
			"SELECT id, type, amount, note, expires_at, created_at FROM {$table} WHERE user_id = %d ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$user_id,
			$limit
		);
		$rows  = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param int                    $user_id User ID.
	 * @param int                    $order_id Order ID.
	 * @param string                 $type Transaction type.
	 * @param float                  $amount Amount.
	 * @param string                 $note Note.
	 * @param string|null            $expires_at UTC datetime.
	 * @param array<string,mixed>    $meta Meta payload.
	 * @return void
	 */
	private function insert_ledger_transaction( $user_id, $order_id, $type, $amount, $note, $expires_at = null, $meta = array() ) {
		global $wpdb;
		$wpdb->insert(
			$this->table_name(),
			array(
				'user_id'    => $user_id,
				'order_id'   => $order_id,
				'type'       => $type,
				'amount'     => wc_format_decimal( $amount, 2 ),
				'note'       => $note,
				'meta'       => wp_json_encode( $meta ),
				'expires_at' => $expires_at,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%f', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * @param int $user_id User ID.
	 * @return float
	 */
	private function get_pending_cashback_amount( $user_id ) {
		if ( $user_id <= 0 ) {
			return 0;
		}
		$orders = wc_get_orders(
			array(
				'customer'    => $user_id,
				'status'      => array( 'processing' ),
				'limit'       => 20,
				'return'      => 'objects',
			)
		);
		if ( ! is_array( $orders ) ) {
			return 0;
		}
		$sum = 0.0;
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			if ( $order->get_meta( '_gs_cashback_credit_done', true ) ) {
				continue;
			}
			$eligible = max( 0, (float) $order->get_subtotal() - (float) $order->get_discount_total() );
			if ( 'yes' === get_option( 'gs_cashback_include_shipping', 'no' ) ) {
				$eligible += (float) $order->get_shipping_total();
			}
			if ( 'yes' === get_option( 'gs_cashback_include_tax', 'no' ) ) {
				$eligible += (float) $order->get_total_tax();
			}
			$sum += max( 0, round( $eligible * ( $this->get_rate_percent() / 100 ), 2 ) );
		}
		return round( $sum, 2 );
	}

	/**
	 * @param WC_Order $order Order.
	 * @return float
	 */
	private function calculate_cashback_estimate_for_order( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return 0;
		}
		$eligible_total = max( 0, (float) $order->get_subtotal() - (float) $order->get_discount_total() );
		if ( 'yes' === get_option( 'gs_cashback_include_shipping', 'no' ) ) {
			$eligible_total += (float) $order->get_shipping_total();
		}
		if ( 'yes' === get_option( 'gs_cashback_include_tax', 'no' ) ) {
			$eligible_total += (float) $order->get_total_tax();
		}
		$debited_cashback = (float) $order->get_meta( '_gs_cashback_debit_amount', true );
		if ( $debited_cashback > 0 ) {
			$eligible_total = max( 0, $eligible_total - $debited_cashback );
		}
		if ( $eligible_total < $this->get_min_order_total() ) {
			return 0;
		}
		return round( max( 0, $eligible_total * ( $this->get_rate_percent() / 100 ) ), 2 );
	}

	private function compute_expiry_datetime_utc() {
		$days = $this->get_expiry_days();
		if ( $days <= 0 ) {
			return null;
		}
		return gmdate( 'Y-m-d H:i:s', time() + ( DAY_IN_SECONDS * $days ) );
	}

	/**
	 * @return void
	 */
	private function handle_manual_adjustment() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$user_raw = isset( $_POST['gs_adjust_user'] ) ? sanitize_text_field( wp_unslash( $_POST['gs_adjust_user'] ) ) : '';
		$amount   = isset( $_POST['gs_adjust_amount'] ) ? (float) wp_unslash( $_POST['gs_adjust_amount'] ) : 0;
		$note     = isset( $_POST['gs_adjust_note'] ) ? sanitize_text_field( wp_unslash( $_POST['gs_adjust_note'] ) ) : __( 'Manual adjustment', 'gs-cashback' );
		if ( 0.0 === $amount || '' === $user_raw ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Invalid inputs for manual transaction.', 'gs-cashback' ) . '</p></div>';
			return;
		}
		$user = null;
		if ( is_email( $user_raw ) ) {
			$user = get_user_by( 'email', $user_raw );
		} elseif ( ctype_digit( $user_raw ) ) {
			$user = get_user_by( 'id', (int) $user_raw );
		}
		if ( ! $user ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Customer not found.', 'gs-cashback' ) . '</p></div>';
			return;
		}
		$type = $amount > 0 ? 'adjustment_credit' : 'adjustment_debit';
		$this->insert_ledger_transaction( (int) $user->ID, 0, $type, $amount, $note, null, array( 'admin_id' => get_current_user_id() ) );
		echo '<div class="notice notice-success"><p>' . esc_html__( 'Manual transaction completed successfully.', 'gs-cashback' ) . '</p></div>';
	}

	/**
	 * @return string
	 */
	private function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'gs_cashback_ledger';
	}

	/**
	 * @return void
	 */
	private static function create_ledger_table() {
		global $wpdb;
		$table           = $wpdb->prefix . 'gs_cashback_ledger';
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			order_id BIGINT(20) UNSIGNED DEFAULT 0,
			type VARCHAR(40) NOT NULL,
			amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
			note VARCHAR(255) DEFAULT '',
			meta LONGTEXT NULL,
			expires_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY order_id (order_id),
			KEY type (type),
			KEY expires_at (expires_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
