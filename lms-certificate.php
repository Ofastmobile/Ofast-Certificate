<?php

/**
 * Plugin Name: OFAST Certificate Management System
 * Plugin URI: https://ofastshop.com
 * Description: Complete certificate management system for WooCommerce courses with student/vendor requests, verification, and PDF generation
 * Version: 1.0.0
 * Author: Ofastshop Digitals
 * Author URI: https://ofastshop.com
 * Text Domain: ofast-certificate
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants  
define('OFST_CERT_VERSION', '1.0.0');
define('OFST_CERT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('OFST_CERT_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * =====================================================
 * DATABASE SETUP & CORE FUNCTIONS
 * =====================================================
 */

// Create custom database tables on activation
function ofst_cert_create_tables()
{
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $table_prefix = $wpdb->prefix . 'ofst_';

    // Table 1: Certificate Requests (Student & Vendor)
    $sql1 = "CREATE TABLE IF NOT EXISTS {$table_prefix}cert_requests (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) NOT NULL,
        certificate_id varchar(50) NOT NULL,
        request_type varchar(20) NOT NULL DEFAULT 'student',
        first_name varchar(100) NOT NULL,
        last_name varchar(100) NOT NULL,
        email varchar(100) NOT NULL,
        phone varchar(20) NOT NULL,
        product_id bigint(20) NOT NULL,
        product_name varchar(255) NOT NULL,
        project_link varchar(500) DEFAULT NULL,
        instructor_name varchar(200) DEFAULT NULL,
        vendor_id bigint(20) DEFAULT NULL,
        vendor_notes text DEFAULT NULL,
        completion_date date DEFAULT NULL,
        status varchar(20) NOT NULL DEFAULT 'pending',
        rejection_reason text DEFAULT NULL,
        requested_date datetime NOT NULL,
        processed_date datetime DEFAULT NULL,
        processed_by bigint(20) DEFAULT NULL,
        certificate_file varchar(500) DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY certificate_id (certificate_id),
        KEY user_product (user_id, product_id),
        KEY status (status),
        KEY request_type (request_type)
    ) $charset_collate;";

    // Table 2: Verification Log
    $sql2 = "CREATE TABLE IF NOT EXISTS {$table_prefix}cert_verifications (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        certificate_id varchar(50) NOT NULL,
        search_method varchar(20) NOT NULL,
        search_query varchar(200) NOT NULL,
        verified_by_ip varchar(45) NOT NULL,
        verified_by_user bigint(20) DEFAULT NULL,
        result varchar(20) NOT NULL,
        verified_date datetime NOT NULL,
        PRIMARY KEY (id),
        KEY certificate_id (certificate_id),
        KEY verified_date (verified_date)
    ) $charset_collate;";

    // Table 3: System Settings
    $sql3 = "CREATE TABLE IF NOT EXISTS {$table_prefix}cert_settings (
        setting_key varchar(100) NOT NULL,
        setting_value longtext NOT NULL,
        PRIMARY KEY (setting_key)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql1);
    dbDelta($sql2);
    dbDelta($sql3);

    // Initialize default settings
    ofst_cert_init_settings();
}

// Initialize default settings
function ofst_cert_init_settings()
{
    global $wpdb;
    $table = $wpdb->prefix . 'ofst_cert_settings';

    $defaults = array(
        'cert_prefix' => 'OFSHDG',
        'cert_counter' => '1',
        'min_days_after_purchase' => '3',
        'company_name' => 'Ofastshop Digitals',
        '  support_email' => 'support@ofastshop.com',
        'from_email' => 'support@ofastshop.com',
        'from_name' => 'Ofastshop Digitals',
        'logo_url' => 'YOUR_LOGO_URL_HERE',
        'seal_url' => 'YOUR_SEAL_URL_HERE',
        'signature_url' => 'YOUR_SIGNATURE_URL_HERE',
        'turnstile_site_key' => '',
        'turnstile_secret_key' => ''
    );

    foreach ($defaults as $key => $value) {
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE setting_key = %s",
            $key
        ));

        if (!$exists) {
            $wpdb->insert($table, array(
                'setting_key' => $key,
                'setting_value' => $value
            ));
        }
    }
}

// Plugin activation hook
register_activation_hook(__FILE__, 'ofst_cert_activate_plugin');

function ofst_cert_activate_plugin()
{
    ofst_cert_create_tables();
    update_option('ofst_cert_db_version', '1.0');
    flush_rewrite_rules();
}

// Plugin deactivation hook
register_deactivation_hook(__FILE__, 'ofst_cert_deactivate_plugin');

function ofst_cert_deactivate_plugin()
{
    flush_rewrite_rules();
}

// Show admin notice after activation
add_action('admin_notices', function () {
    if (get_transient('ofst_cert_activated')) {
        delete_transient('ofst_cert_activated');
?>
        <div class="notice notice-success is-dismissible">
            <p><strong>OFAST Certificate System activated!</strong> Database tables created successfully. Remaining features (forms, verification, etc.) will be added in the next step.</p>
        </div>
<?php
    }
});

// Set activation transient
add_action('activated_plugin', function ($plugin) {
    if ($plugin == plugin_basename(__FILE__)) {
        set_transient('ofst_cert_activated', true, 5);
    }
});

/**
 * =====================================================
 * CORE HELPER FUNCTIONS  
 * =====================================================
 */

// Get system setting
function ofst_cert_get_setting($key, $default = '')
{
    global $wpdb;
    $table = $wpdb->prefix . 'ofst_cert_settings';

    $value = $wpdb->get_var($wpdb->prepare(
        "SELECT setting_value FROM $table WHERE setting_key = %s",
        $key
    ));

    return $value !== null ? $value : $default;
}

// Update system setting
function ofst_cert_update_setting($key, $value)
{
    global $wpdb;
    $table = $wpdb->prefix . 'ofst_cert_settings';

    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE setting_key = %s",
        $key
    ));

    if ($exists) {
        return $wpdb->update(
            $table,
            array('setting_value' => $value),
            array('setting_key' => $key)
        );
    } else {
        return $wpdb->insert($table, array(
            'setting_key' => $key,
            'setting_value' => $value
        ));
    }
}

// Generate unique certificate ID
function ofst_cert_generate_id()
{
    $prefix = ofst_cert_get_setting('cert_prefix', 'OFSHDG');
    $counter = (int) ofst_cert_get_setting('cert_counter', 1);
    $year = date('Y');

    // Format: OFSHDG2024001
    $cert_id = $prefix . $year . str_pad($counter, 3, '0', STR_PAD_LEFT);

    // Increment counter
    ofst_cert_update_setting('cert_counter', $counter + 1);

    return $cert_id;
}

// Check if certificate already exists for user + product
function ofst_cert_check_duplicate($user_id, $product_id)
{
    global $wpdb;
    $table = $wpdb->prefix . 'ofst_cert_requests';

    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table 
        WHERE user_id = %d 
        AND product_id = %d 
        AND status IN ('pending', 'approved', 'issued')
        ORDER BY id DESC 
        LIMIT 1",
        $user_id,
        $product_id
    ));

    return $existing;
}

// Get user's purchased products (WooCommerce orders)
function ofst_cert_get_user_products($user_id, $min_days = null)
{
    if (!function_exists('wc_get_orders')) {
        return array();
    }

    if ($min_days === null) {
        $min_days = (int) ofst_cert_get_setting('min_days_after_purchase', 3);
    }

    $orders = wc_get_orders(array(
        'customer_id' => $user_id,
        'status' => array('wc-completed', 'wc-processing'),
        'limit' => -1
    ));

    $products = array();
    $min_date = date('Y-m-d', strtotime("-$min_days days"));

    foreach ($orders as $order) {
        $order_date = $order->get_date_created()->date('Y-m-d');

        // Only include products purchased before minimum days ago
        if ($order_date <= $min_date) {
            foreach ($order->get_items() as $item) {
                $product_id = $item->get_product_id();
                $product = wc_get_product($product_id);

                if ($product && !isset($products[$product_id])) {
                    // Check if already has certificate
                    $has_cert = ofst_cert_check_duplicate($user_id, $product_id);

                    if (!$has_cert) {
                        $products[$product_id] = array(
                            'id' => $product_id,
                            'name' => $product->get_name(),
                            'purchased_date' => $order_date
                        );
                    }
                }
            }
        }
    }

    return $products;
}

// Get vendor's products (Dokan)
function ofst_cert_get_vendor_products($vendor_id)
{
    $args = array(
        'post_type' => 'product',
        'author' => $vendor_id,
        'posts_per_page' => -1,
        'post_status' => 'publish'
    );

    $products = array();
    $query = new WP_Query($args);

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $product_id = get_the_ID();
            $products[$product_id] = get_the_title();
        }
        wp_reset_postdata();
    }

    return $products;
}

// Verify Cloudflare Turnstile token
function ofst_cert_verify_turnstile($token)
{
    $secret = ofst_cert_get_setting('turnstile_secret_key');

    if (empty($secret) || empty($token)) {
        return true; // Allow if Turnstile not configured
    }

    $response = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', array(
        'body' => array(
            'secret' => $secret,
            'response' => $token
        )
    ));

    if (is_wp_error($response)) {
        return false;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    return isset($body['success']) && $body['success'] === true;
}

// Sanitize phone number
function ofst_cert_sanitize_phone($phone)
{
    return preg_replace('/[^0-9+\-() ]/', '', $phone);
}

// Log verification attempt
function ofst_cert_log_verification($cert_id, $method, $query, $result)
{
    global $wpdb;
    $table = $wpdb->prefix . 'ofst_cert_verifications';

    $user_id = get_current_user_id();
    $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '';

    $wpdb->insert($table, array(
        'certificate_id' => sanitize_text_field($cert_id),
        'search_method' => sanitize_text_field($method),
        'search_query' => sanitize_text_field($query),
        'verified_by_ip' => $ip,
        'verified_by_user' => $user_id > 0 ? $user_id : null,
        'result' => sanitize_text_field($result),
        'verified_date' => current_time('mysql')
    ));
}

/**
 * =====================================================
 * ENQUEUE STYLES AND SCRIPTS
 * =====================================================
 */
add_action('wp_enqueue_scripts', 'ofst_cert_enqueue_assets');
function ofst_cert_enqueue_assets()
{
    // Enqueue CSS
    wp_enqueue_style(
        'ofst-cert-styles',
        OFST_CERT_PLUGIN_URL . 'assets/css/styles.css',
        array(),
        OFST_CERT_VERSION
    );
}

/**
 * =====================================================
 * LOAD SHORTCODES
 * =====================================================
 */
require_once OFST_CERT_PLUGIN_DIR . 'includes/shortcodes.php';

/**
 * =====================================================
 * LOAD ADMIN DASHBOARD
 * =====================================================
 */
if (is_admin()) {
    require_once OFST_CERT_PLUGIN_DIR . 'includes/admin-dashboard.php';
}

/**
 * =====================================================
 * LOAD EMAIL TEMPLATES
 * =====================================================
 */
require_once OFST_CERT_PLUGIN_DIR . 'includes/email-templates.php';
