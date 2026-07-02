<?php

class AICB_Admin_Settings
{
    const OPTION_NAME = 'aicb_settings';

    public static function get_defaults(): array
    {
        return [
            'anthropic_api_key' => '',
            'max_upload_mb' => 5,
            'max_documents' => 20,
        ];
    }

    public static function sanitize(array $input): array
    {
        $defaults = self::get_defaults();

        $api_key = isset($input['anthropic_api_key']) ? trim((string) $input['anthropic_api_key']) : $defaults['anthropic_api_key'];

        $max_upload_mb = isset($input['max_upload_mb']) ? absint($input['max_upload_mb']) : $defaults['max_upload_mb'];
        if ($max_upload_mb < 1) {
            $max_upload_mb = $defaults['max_upload_mb'];
        }

        $max_documents = isset($input['max_documents']) ? absint($input['max_documents']) : $defaults['max_documents'];
        if ($max_documents < 1) {
            $max_documents = $defaults['max_documents'];
        }

        return [
            'anthropic_api_key' => $api_key,
            'max_upload_mb' => $max_upload_mb,
            'max_documents' => $max_documents,
        ];
    }

    public static function get_settings(): array
    {
        $stored = get_option(self::OPTION_NAME, []);
        return wp_parse_args(is_array($stored) ? $stored : [], self::get_defaults());
    }

    public static function register(): void
    {
        register_setting('aicb_settings_group', self::OPTION_NAME, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitize'],
            'default' => self::get_defaults(),
        ]);

        add_options_page(
            'AI Chatbox Settings',
            'AI Chatbox',
            'manage_options',
            'aicb-settings',
            [self::class, 'render_page']
        );

        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
    }

    public static function render_page(): void
    {
        $settings = self::get_settings();
        require __DIR__ . '/../admin/settings-page.php';
    }

    public static function enqueue_assets(string $hook_suffix): void
    {
        if ('settings_page_aicb-settings' !== $hook_suffix) {
            return;
        }

        wp_enqueue_script(
            'aicb-admin',
            AICB_PLUGIN_URL . 'admin/assets/admin.js',
            [],
            AICB_VERSION,
            true
        );
    }
}
