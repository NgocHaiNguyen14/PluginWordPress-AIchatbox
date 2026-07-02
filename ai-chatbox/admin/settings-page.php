<?php
if (!defined('ABSPATH')) {
    exit;
}
/** @var array $settings */
?>
<div class="wrap aicb-settings-wrap">
    <h1>AI Chatbox Settings</h1>
    <form method="post" action="options.php">
        <?php settings_fields('aicb_settings_group'); ?>
        <h2>Global Settings</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="aicb_api_key">Anthropic API Key</label></th>
                <td><input type="password" id="aicb_api_key" name="aicb_settings[anthropic_api_key]" value="<?php echo esc_attr($settings['anthropic_api_key']); ?>" class="regular-text" autocomplete="off" /></td>
            </tr>
            <tr>
                <th><label for="aicb_max_upload_mb">Max Upload Size (MB)</label></th>
                <td><input type="number" min="1" id="aicb_max_upload_mb" name="aicb_settings[max_upload_mb]" value="<?php echo esc_attr($settings['max_upload_mb']); ?>" class="small-text" /></td>
            </tr>
            <tr>
                <th><label for="aicb_max_documents">Max Documents (per profile)</label></th>
                <td><input type="number" min="1" id="aicb_max_documents" name="aicb_settings[max_documents]" value="<?php echo esc_attr($settings['max_documents']); ?>" class="small-text" /></td>
            </tr>
        </table>
        <?php submit_button('Save Global Settings'); ?>
    </form>

    <h2>Chatbot Profiles</h2>
    <p class="description">Each profile is a separate chatbot persona shown on pages under its path prefix. Exactly one profile is the Default — it answers everywhere no other profile's prefix matches.</p>
    <div id="aicb-profiles-manager" data-nonce="<?php echo esc_attr(wp_create_nonce('aicb_admin_nonce')); ?>"></div>
</div>
