<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-db-installer.php';

final class DbInstallerTest extends TestCase
{
    public function test_documents_table_sql_contains_expected_columns(): void
    {
        $sql = AICB_DB_Installer::get_documents_table_sql('wp_aicb_documents', 'DEFAULT CHARSET=utf8mb4');

        $this->assertStringContainsString('CREATE TABLE wp_aicb_documents', $sql);
        $this->assertStringContainsString('id bigint(20) unsigned NOT NULL AUTO_INCREMENT', $sql);
        $this->assertStringContainsString('filename varchar(255) NOT NULL', $sql);
        $this->assertStringContainsString("file_type varchar(10) NOT NULL", $sql);
        $this->assertStringContainsString('status varchar(20) NOT NULL', $sql);
        $this->assertStringContainsString('PRIMARY KEY  (id)', $sql);
    }

    public function test_chunks_table_sql_has_fulltext_index(): void
    {
        $sql = AICB_DB_Installer::get_chunks_table_sql('wp_aicb_chunks', 'wp_aicb_documents', 'DEFAULT CHARSET=utf8mb4');

        $this->assertStringContainsString('CREATE TABLE wp_aicb_chunks', $sql);
        $this->assertStringContainsString('document_id bigint(20) unsigned NOT NULL', $sql);
        $this->assertStringContainsString('chunk_text longtext NOT NULL', $sql);
        $this->assertStringContainsString('chunk_index int(11) NOT NULL', $sql);
        $this->assertStringContainsString('FULLTEXT KEY chunk_text_idx (chunk_text)', $sql);
    }

    public function test_profiles_table_sql_contains_expected_columns(): void
    {
        $sql = AICB_DB_Installer::get_profiles_table_sql('wp_aicb_profiles', 'DEFAULT CHARSET=utf8mb4');

        $this->assertStringContainsString('CREATE TABLE wp_aicb_profiles', $sql);
        $this->assertStringContainsString('id bigint(20) unsigned NOT NULL AUTO_INCREMENT', $sql);
        $this->assertStringContainsString('path_prefix varchar(255) NOT NULL', $sql);
        $this->assertStringContainsString('is_default tinyint(1) NOT NULL DEFAULT 0', $sql);
        $this->assertStringContainsString('assistant_name varchar(255) NOT NULL', $sql);
        $this->assertStringContainsString('avatar_url varchar(255) NOT NULL', $sql);
        $this->assertStringContainsString('system_prompt text NOT NULL', $sql);
        $this->assertStringContainsString('welcome_message varchar(255) NOT NULL', $sql);
        $this->assertStringContainsString('accent_color varchar(7) NOT NULL', $sql);
        $this->assertStringContainsString('widget_position varchar(20) NOT NULL', $sql);
        $this->assertStringContainsString('quick_replies longtext NOT NULL', $sql);
        $this->assertStringContainsString('created_at datetime NOT NULL', $sql);
        $this->assertStringContainsString('PRIMARY KEY  (id)', $sql);
    }

    public function test_documents_table_sql_now_includes_profile_id(): void
    {
        $sql = AICB_DB_Installer::get_documents_table_sql('wp_aicb_documents', 'DEFAULT CHARSET=utf8mb4');

        $this->assertStringContainsString('profile_id bigint(20) unsigned NOT NULL', $sql);
        $this->assertStringContainsString('KEY profile_id (profile_id)', $sql);
    }

    public function test_chunks_table_sql_now_includes_profile_id(): void
    {
        $sql = AICB_DB_Installer::get_chunks_table_sql('wp_aicb_chunks', 'wp_aicb_documents', 'DEFAULT CHARSET=utf8mb4');

        $this->assertStringContainsString('profile_id bigint(20) unsigned NOT NULL', $sql);
        $this->assertStringContainsString('KEY profile_id (profile_id)', $sql);
    }
}
