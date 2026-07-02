<?php

class AICB_Ajax_Handler
{
    public static function register(): void
    {
        add_action('wp_ajax_aicb_upload_document', [self::class, 'handle_upload_document']);
        add_action('wp_ajax_aicb_send_message', [self::class, 'handle_send_message']);
        add_action('wp_ajax_nopriv_aicb_send_message', [self::class, 'handle_send_message']);
        add_action('wp_ajax_aicb_list_profiles', [self::class, 'handle_list_profiles']);
        add_action('wp_ajax_aicb_save_profile', [self::class, 'handle_save_profile']);
        add_action('wp_ajax_aicb_delete_profile', [self::class, 'handle_delete_profile']);
        add_action('wp_ajax_aicb_set_default_profile', [self::class, 'handle_set_default_profile']);
        add_action('wp_ajax_aicb_list_documents', [self::class, 'handle_list_documents']);
        add_action('wp_ajax_aicb_delete_document', [self::class, 'handle_delete_document']);
    }

    public static function handle_upload_document(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Not allowed.'], 403);
        }

        check_ajax_referer('aicb_admin_nonce', 'nonce');

        $profile_id = isset($_POST['profile_id']) ? absint($_POST['profile_id']) : 0;
        if ($profile_id < 1) {
            wp_send_json_error(['message' => 'No profile selected.']);
        }

        if (empty($_FILES['file'])) {
            wp_send_json_error(['message' => 'No file uploaded.']);
        }

        global $wpdb;
        $settings = AICB_Admin_Settings::get_settings();
        $documents_table = $wpdb->prefix . 'aicb_documents';
        $chunks_table = $wpdb->prefix . 'aicb_chunks';

        $existing_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$documents_table} WHERE profile_id = %d",
            $profile_id
        ));
        if ($existing_count >= $settings['max_documents']) {
            wp_send_json_error(['message' => 'Document limit reached for this profile.']);
        }

        $file = $_FILES['file'];
        $size_mb = $file['size'] / (1024 * 1024);
        if ($size_mb > $settings['max_upload_mb']) {
            wp_send_json_error(['message' => 'File exceeds the configured size limit.']);
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['txt', 'pdf'], true)) {
            wp_send_json_error(['message' => 'Only .txt and .pdf files are supported.']);
        }

        try {
            $text = AICB_Document_Parser::extract_text($file['tmp_name'], $ext);
        } catch (\Throwable $e) {
            error_log('AICB document parse error: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Could not read this file. It may be corrupt.']);
            return;
        }

        $wpdb->insert($documents_table, [
            'profile_id' => $profile_id,
            'filename' => sanitize_file_name($file['name']),
            'file_type' => $ext,
            'uploaded_at' => current_time('mysql'),
            'status' => 'processed',
        ]);
        $document_id = $wpdb->insert_id;

        $chunks = AICB_Knowledge_Base::chunk_text($text);
        foreach ($chunks as $index => $chunk) {
            $wpdb->insert($chunks_table, [
                'document_id' => $document_id,
                'profile_id' => $profile_id,
                'chunk_text' => $chunk,
                'chunk_index' => $index,
            ]);
        }

        wp_send_json_success(['document_id' => $document_id, 'chunks' => count($chunks)]);
    }

    public static function handle_list_documents(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Not allowed.'], 403);
        }
        check_ajax_referer('aicb_admin_nonce', 'nonce');

        $profile_id = isset($_POST['profile_id']) ? absint($_POST['profile_id']) : 0;

        global $wpdb;
        $documents_table = $wpdb->prefix . 'aicb_documents';
        $chunks_table = $wpdb->prefix . 'aicb_chunks';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT d.id, d.filename, d.file_type, d.uploaded_at,
                    (SELECT COUNT(*) FROM {$chunks_table} c WHERE c.document_id = d.id) AS chunk_count
             FROM {$documents_table} d
             WHERE d.profile_id = %d
             ORDER BY d.uploaded_at DESC",
            $profile_id
        ), ARRAY_A);

        wp_send_json_success(['documents' => $rows]);
    }

    public static function handle_delete_document(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Not allowed.'], 403);
        }
        check_ajax_referer('aicb_admin_nonce', 'nonce');

        $document_id = isset($_POST['document_id']) ? absint($_POST['document_id']) : 0;
        if ($document_id < 1) {
            wp_send_json_error(['message' => 'No document specified.']);
        }

        global $wpdb;
        $documents_table = $wpdb->prefix . 'aicb_documents';
        $chunks_table = $wpdb->prefix . 'aicb_chunks';

        $wpdb->delete($chunks_table, ['document_id' => $document_id]);
        $wpdb->delete($documents_table, ['id' => $document_id]);

        wp_send_json_success();
    }

    public static function handle_send_message(): void
    {
        check_ajax_referer('aicb_public_nonce', 'nonce');

        $user_message = isset($_POST['message']) ? sanitize_text_field(wp_unslash($_POST['message'])) : '';
        if ('' === $user_message) {
            wp_send_json_error(['message' => 'Empty message.']);
        }

        $history = [];
        if (isset($_POST['history']) && is_array($_POST['history'])) {
            foreach ($_POST['history'] as $turn) {
                if (isset($turn['role'], $turn['content']) && in_array($turn['role'], ['user', 'assistant'], true)) {
                    $history[] = [
                        'role' => $turn['role'],
                        'content' => sanitize_text_field(wp_unslash($turn['content'])),
                    ];
                }
            }
        }

        global $wpdb;
        $profiles_table = $wpdb->prefix . 'aicb_profiles';
        $profiles = $wpdb->get_results("SELECT * FROM {$profiles_table}", ARRAY_A);
        foreach ($profiles as &$p) {
            $p['quick_replies'] = maybe_unserialize($p['quick_replies']);
            $p['is_default'] = (bool) $p['is_default'];
        }

        $source_url = isset($_POST['page_url']) ? (string) wp_unslash($_POST['page_url']) : '';
        if ('' === $source_url) {
            $source_url = isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '';
        }
        $path = (string) (wp_parse_url($source_url, PHP_URL_PATH) ?? '/');
        $profile = AICB_Profile_Resolver::resolve($path, $profiles);

        $settings = AICB_Admin_Settings::get_settings();

        if ('' === $settings['anthropic_api_key']) {
            error_log('AICB: no Anthropic API key configured.');
            wp_send_json_error(['message' => 'Chat is temporarily unavailable.']);
        }

        $chunks_table = $wpdb->prefix . 'aicb_chunks';
        $kb = new AICB_Knowledge_Base($wpdb, $chunks_table);
        $context_chunks = isset($profile['id'])
            ? $kb->search_chunks((int) $profile['id'], $user_message, 5)
            : [];

        $client = new AICB_Claude_Client($settings['anthropic_api_key']);
        $result = $client->send_message($profile['system_prompt'], $context_chunks, $history, $user_message);

        if (!$result['ok']) {
            error_log('AICB Claude API error: ' . $result['error']);
            wp_send_json_error(['message' => 'Chat is temporarily unavailable.']);
        }

        wp_send_json_success(['reply' => $result['text']]);
    }

    public static function handle_list_profiles(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Not allowed.'], 403);
        }
        check_ajax_referer('aicb_admin_nonce', 'nonce');

        global $wpdb;
        $profiles_table = $wpdb->prefix . 'aicb_profiles';
        $rows = $wpdb->get_results("SELECT * FROM {$profiles_table} ORDER BY is_default DESC, id ASC", ARRAY_A);

        foreach ($rows as &$row) {
            $row['quick_replies'] = maybe_unserialize($row['quick_replies']);
            $row['is_default'] = (bool) $row['is_default'];
        }

        wp_send_json_success(['profiles' => $rows]);
    }

    public static function handle_save_profile(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Not allowed.'], 403);
        }
        check_ajax_referer('aicb_admin_nonce', 'nonce');

        global $wpdb;
        $profiles_table = $wpdb->prefix . 'aicb_profiles';

        $profile_id = isset($_POST['profile_id']) ? absint($_POST['profile_id']) : 0;
        $raw = isset($_POST['profile']) && is_array($_POST['profile']) ? wp_unslash($_POST['profile']) : [];
        $data = AICB_Profiles::sanitize($raw);

        if (!$data['is_default'] && '' === $data['path_prefix']) {
            wp_send_json_error(['message' => 'Path prefix is required for non-default profiles.']);
        }

        $others = $wpdb->get_results(
            $profile_id > 0
                ? $wpdb->prepare("SELECT id, path_prefix FROM {$profiles_table} WHERE id != %d", $profile_id)
                : "SELECT id, path_prefix FROM {$profiles_table}",
            ARRAY_A
        );

        if (!$data['is_default'] && AICB_Profiles::path_prefix_conflicts($data['path_prefix'], $others)) {
            wp_send_json_error(['message' => 'Another profile already uses this path prefix.']);
        }

        $row = [
            'path_prefix' => $data['path_prefix'],
            'is_default' => $data['is_default'] ? 1 : 0,
            'assistant_name' => $data['assistant_name'],
            'avatar_url' => $data['avatar_url'],
            'system_prompt' => $data['system_prompt'],
            'welcome_message' => $data['welcome_message'],
            'accent_color' => $data['accent_color'],
            'widget_position' => $data['widget_position'],
            'quick_replies' => maybe_serialize($data['quick_replies']),
        ];

        if ($data['is_default']) {
            $wpdb->query($profile_id > 0
                ? $wpdb->prepare("UPDATE {$profiles_table} SET is_default = 0 WHERE id != %d", $profile_id)
                : "UPDATE {$profiles_table} SET is_default = 0"
            );
        }

        if ($profile_id > 0) {
            $wpdb->update($profiles_table, $row, ['id' => $profile_id]);
        } else {
            $row['created_at'] = current_time('mysql');
            $wpdb->insert($profiles_table, $row);
            $profile_id = $wpdb->insert_id;
        }

        wp_send_json_success(['profile_id' => $profile_id]);
    }

    public static function handle_delete_profile(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Not allowed.'], 403);
        }
        check_ajax_referer('aicb_admin_nonce', 'nonce');

        global $wpdb;
        $profiles_table = $wpdb->prefix . 'aicb_profiles';
        $documents_table = $wpdb->prefix . 'aicb_documents';
        $chunks_table = $wpdb->prefix . 'aicb_chunks';

        $profile_id = isset($_POST['profile_id']) ? absint($_POST['profile_id']) : 0;
        $profile = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$profiles_table} WHERE id = %d", $profile_id), ARRAY_A);

        if (!$profile) {
            wp_send_json_error(['message' => 'Profile not found.']);
        }

        if ((bool) $profile['is_default']) {
            wp_send_json_error(['message' => 'The default profile cannot be deleted.']);
        }

        $doc_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$documents_table} WHERE profile_id = %d", $profile_id));
        if (!empty($doc_ids)) {
            $placeholders = implode(',', array_fill(0, count($doc_ids), '%d'));
            $wpdb->query($wpdb->prepare("DELETE FROM {$chunks_table} WHERE document_id IN ({$placeholders})", $doc_ids));
            $wpdb->query($wpdb->prepare("DELETE FROM {$documents_table} WHERE id IN ({$placeholders})", $doc_ids));
        }

        $wpdb->delete($profiles_table, ['id' => $profile_id]);

        wp_send_json_success();
    }

    public static function handle_set_default_profile(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Not allowed.'], 403);
        }
        check_ajax_referer('aicb_admin_nonce', 'nonce');

        global $wpdb;
        $profiles_table = $wpdb->prefix . 'aicb_profiles';
        $profile_id = isset($_POST['profile_id']) ? absint($_POST['profile_id']) : 0;

        $wpdb->query("UPDATE {$profiles_table} SET is_default = 0");
        $wpdb->update($profiles_table, ['is_default' => 1], ['id' => $profile_id]);

        wp_send_json_success();
    }
}
