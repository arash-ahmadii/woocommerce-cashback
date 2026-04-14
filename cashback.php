<?php
/**
 * Plugin Name: Dashweb Cashback
 * Description: Public WooCommerce cashback wallet plugin by dashweb.agency.
 * Version: 1.1.3
 * Author: dashweb.agency
 * Text Domain: dashweb.agency
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DWCB_CASHBACK_VERSION', '1.1.3' );
define( 'DWCB_CASHBACK_FILE', __FILE__ );
define( 'DWCB_CASHBACK_PATH', plugin_dir_path( __FILE__ ) );
define( 'DWCB_CASHBACK_URL', plugin_dir_url( __FILE__ ) );

require_once DWCB_CASHBACK_PATH . 'includes/class-gs-cashback-plugin.php';

function dwcb_cashback() {
	return DWCB_Cashback_Plugin::instance();
}

register_activation_hook( __FILE__, array( 'DWCB_Cashback_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'DWCB_Cashback_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', 'dwcb_cashback' );
