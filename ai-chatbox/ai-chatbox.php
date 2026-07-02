<?php
/**
 * Plugin Name: AI Chatbox
 * Description: AI-powered customer support chat widget backed by the Anthropic Claude API, with a free keyword-searchable document knowledge base.
 * Version: 2.0.0
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

define('AICB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AICB_PLUGIN_URL', plugin_dir_url(__FILE__));
define('AICB_VERSION', '2.0.0');

require_once AICB_PLUGIN_DIR . 'vendor/autoload.php';
require_once AICB_PLUGIN_DIR . 'includes/class-db-installer.php';
require_once AICB_PLUGIN_DIR . 'includes/class-admin-settings.php';
require_once AICB_PLUGIN_DIR . 'includes/class-knowledge-base.php';
require_once AICB_PLUGIN_DIR . 'includes/class-document-parser.php';
require_once AICB_PLUGIN_DIR . 'includes/class-claude-client.php';
require_once AICB_PLUGIN_DIR . 'includes/class-ajax-handler.php';
require_once AICB_PLUGIN_DIR . 'includes/class-profiles.php';
require_once AICB_PLUGIN_DIR . 'includes/class-profile-resolver.php';
require_once AICB_PLUGIN_DIR . 'includes/class-profile-migrator.php';

register_activation_hook(__FILE__, ['AICB_DB_Installer', 'install']);

add_action('admin_menu', ['AICB_Admin_Settings', 'register']);

AICB_Ajax_Handler::register();

add_action('wp_enqueue_scripts', function () {
    global $wpdb;
    $profiles_table = $wpdb->prefix . 'aicb_profiles';
    $profiles = $wpdb->get_results("SELECT * FROM {$profiles_table}", ARRAY_A);
    foreach ($profiles as &$p) {
        $p['quick_replies'] = maybe_unserialize($p['quick_replies']);
        $p['is_default'] = (bool) $p['is_default'];
    }

    $current_path = (string) wp_parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $profile = AICB_Profile_Resolver::resolve($current_path, $profiles);

    wp_enqueue_style('aicb-widget', AICB_PLUGIN_URL . 'public/widget.css', [], AICB_VERSION);
    wp_enqueue_script('aicb-widget', AICB_PLUGIN_URL . 'public/widget.js', [], AICB_VERSION, true);

    wp_localize_script('aicb-widget', 'AICB_CONFIG', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('aicb_public_nonce'),
        'assistantName' => $profile['assistant_name'],
        'avatarUrl' => $profile['avatar_url'],
        'welcomeMessage' => $profile['welcome_message'],
        'accentColor' => $profile['accent_color'],
        'position' => $profile['widget_position'],
        'quickReplies' => $profile['quick_replies'],
    ]);
});
