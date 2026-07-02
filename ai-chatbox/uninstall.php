<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

delete_option('aicb_settings');

$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}aicb_chunks");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}aicb_documents");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}aicb_profiles");
