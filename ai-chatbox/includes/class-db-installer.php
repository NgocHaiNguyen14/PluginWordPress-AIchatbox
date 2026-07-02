<?php
if (!defined('ABSPATH')) {
    // Allow this file to be required directly in unit tests, outside WordPress.
}

class AICB_DB_Installer
{
    public static function get_documents_table_sql(string $table_name, string $charset_collate): string
    {
        return "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            profile_id bigint(20) unsigned NOT NULL,
            filename varchar(255) NOT NULL,
            file_type varchar(10) NOT NULL,
            uploaded_at datetime NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'processed',
            PRIMARY KEY  (id),
            KEY profile_id (profile_id)
        ) {$charset_collate};";
    }

    public static function get_chunks_table_sql(string $table_name, string $documents_table, string $charset_collate): string
    {
        return "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            document_id bigint(20) unsigned NOT NULL,
            profile_id bigint(20) unsigned NOT NULL,
            chunk_text longtext NOT NULL,
            chunk_index int(11) NOT NULL,
            PRIMARY KEY  (id),
            KEY document_id (document_id),
            KEY profile_id (profile_id),
            FULLTEXT KEY chunk_text_idx (chunk_text)
        ) {$charset_collate};";
    }

    public static function get_profiles_table_sql(string $table_name, string $charset_collate): string
    {
        return "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            path_prefix varchar(255) NOT NULL DEFAULT '',
            is_default tinyint(1) NOT NULL DEFAULT 0,
            assistant_name varchar(255) NOT NULL,
            avatar_url varchar(255) NOT NULL DEFAULT '',
            system_prompt text NOT NULL,
            welcome_message varchar(255) NOT NULL,
            accent_color varchar(7) NOT NULL,
            widget_position varchar(20) NOT NULL,
            quick_replies longtext NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id)
        ) {$charset_collate};";
    }

    public static function install(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $documents_table = $wpdb->prefix . 'aicb_documents';
        $chunks_table = $wpdb->prefix . 'aicb_chunks';
        $profiles_table = $wpdb->prefix . 'aicb_profiles';

        dbDelta(self::get_profiles_table_sql($profiles_table, $charset_collate));
        dbDelta(self::get_documents_table_sql($documents_table, $charset_collate));
        dbDelta(self::get_chunks_table_sql($chunks_table, $documents_table, $charset_collate));

        AICB_Profile_Migrator::migrate();
    }
}
