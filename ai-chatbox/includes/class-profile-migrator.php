<?php

class AICB_Profile_Migrator
{
    public static function migrate(): void
    {
        global $wpdb;

        $profiles_table = $wpdb->prefix . 'aicb_profiles';
        $documents_table = $wpdb->prefix . 'aicb_documents';
        $chunks_table = $wpdb->prefix . 'aicb_chunks';

        $existing_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$profiles_table}");
        if ($existing_count > 0) {
            return;
        }

        $old_settings = AICB_Admin_Settings::get_settings();

        $default_profile = AICB_Profiles::sanitize([
            'path_prefix' => '',
            'is_default' => true,
            'assistant_name' => $old_settings['assistant_name'] ?? 'Assistant',
            'avatar_url' => $old_settings['avatar_url'] ?? '',
            'system_prompt' => $old_settings['system_prompt'] ?? '',
            'welcome_message' => $old_settings['welcome_message'] ?? '',
            'accent_color' => $old_settings['accent_color'] ?? '',
            'widget_position' => $old_settings['widget_position'] ?? 'bottom-right',
            'quick_replies' => $old_settings['quick_replies'] ?? [],
        ]);

        $wpdb->insert($profiles_table, [
            'path_prefix' => $default_profile['path_prefix'],
            'is_default' => 1,
            'assistant_name' => $default_profile['assistant_name'],
            'avatar_url' => $default_profile['avatar_url'],
            'system_prompt' => $default_profile['system_prompt'],
            'welcome_message' => $default_profile['welcome_message'],
            'accent_color' => $default_profile['accent_color'],
            'widget_position' => $default_profile['widget_position'],
            'quick_replies' => maybe_serialize($default_profile['quick_replies']),
            'created_at' => current_time('mysql'),
        ]);
        $default_profile_id = $wpdb->insert_id;

        $wpdb->query($wpdb->prepare(
            "UPDATE {$documents_table} SET profile_id = %d WHERE profile_id = 0 OR profile_id IS NULL",
            $default_profile_id
        ));
        $wpdb->query($wpdb->prepare(
            "UPDATE {$chunks_table} SET profile_id = %d WHERE profile_id = 0 OR profile_id IS NULL",
            $default_profile_id
        ));

        $trimmed_settings = [
            'anthropic_api_key' => $old_settings['anthropic_api_key'] ?? '',
            'max_upload_mb' => $old_settings['max_upload_mb'] ?? 5,
            'max_documents' => $old_settings['max_documents'] ?? 20,
        ];
        update_option(AICB_Admin_Settings::OPTION_NAME, $trimmed_settings);
    }
}
