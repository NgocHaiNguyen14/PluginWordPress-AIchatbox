<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-admin-settings.php';

final class SettingsSanitizeTest extends TestCase
{
    public function test_get_defaults_is_global_fields_only(): void
    {
        $defaults = AICB_Admin_Settings::get_defaults();

        $this->assertSame(['anthropic_api_key', 'max_upload_mb', 'max_documents'], array_keys($defaults));
        $this->assertSame('', $defaults['anthropic_api_key']);
        $this->assertSame(5, $defaults['max_upload_mb']);
        $this->assertSame(20, $defaults['max_documents']);
    }

    public function test_sanitize_keeps_api_key_as_trimmed_string(): void
    {
        $result = AICB_Admin_Settings::sanitize(['anthropic_api_key' => '  sk-ant-abc123  ']);

        $this->assertSame('sk-ant-abc123', $result['anthropic_api_key']);
    }

    public function test_sanitize_clamps_caps_to_default_when_invalid(): void
    {
        $result = AICB_Admin_Settings::sanitize(['max_upload_mb' => '0', 'max_documents' => '-5']);

        $this->assertSame(5, $result['max_upload_mb']);
        $this->assertSame(5, $result['max_documents']);
    }

    public function test_sanitize_keeps_valid_caps(): void
    {
        $result = AICB_Admin_Settings::sanitize(['max_upload_mb' => '8', 'max_documents' => '15']);

        $this->assertSame(8, $result['max_upload_mb']);
        $this->assertSame(15, $result['max_documents']);
    }
}
