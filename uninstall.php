<?php

/**
 * Uninstall handler for Certificate Management System
 * This file is automatically called when the plugin is deleted (not just deactivated)
 */

// Exit if accessed directly or not in uninstall context
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Check if admin wants to delete data on uninstall
$delete_data = get_option('ofst_cert_delete_on_uninstall', 'no');

if ($delete_data === 'yes') {
    global $wpdb;

    // Delete all custom database tables
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}ofst_cert_requests");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}ofst_cert_verifications");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}ofst_cert_settings");

    // Delete plugin options
    delete_option('ofst_cert_db_version');
    delete_option('ofst_cert_delete_on_uninstall');

    // Optional: Delete uploaded certificate files
    // Uncomment if you want to delete uploaded PDFs
    // $upload_dir = wp_upload_dir();
    // $cert_dir = $upload_dir['basedir'] . '/certificates/';
    // if (is_dir($cert_dir)) {
    //     array_map('unlink', glob("$cert_dir/*.*"));
    //     rmdir($cert_dir);
    // }
}

// If $delete_data is 'no', all data remains in database for future use
