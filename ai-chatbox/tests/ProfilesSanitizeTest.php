<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-profiles.php';

final class ProfilesSanitizeTest extends TestCase
{
    public function test_get_defaults_shape(): void
    {
        $defaults = AICB_Profiles::get_defaults();

        $this->assertSame('', $defaults['path_prefix']);
        $this->assertFalse($defaults['is_default']);
        $this->assertSame('Assistant', $defaults['assistant_name']);
        $this->assertSame('bottom-right', $defaults['widget_position']);
        $this->assertSame('#4f46e5', $defaults['accent_color']);
        $this->assertSame([], $defaults['quick_replies']);
    }

    public function test_sanitize_trims_path_prefix_trailing_slash(): void
    {
        $result = AICB_Profiles::sanitize(['path_prefix' => '/members/harry/']);

        $this->assertSame('/members/harry', $result['path_prefix']);
    }

    public function test_sanitize_rejects_invalid_widget_position(): void
    {
        $result = AICB_Profiles::sanitize(['widget_position' => 'top-center']);

        $this->assertSame('bottom-right', $result['widget_position']);
    }

    public function test_sanitize_rejects_invalid_hex_color(): void
    {
        $result = AICB_Profiles::sanitize(['accent_color' => 'not-a-color']);

        $this->assertSame('#4f46e5', $result['accent_color']);
    }

    public function test_sanitize_filters_quick_replies_to_label_and_message_pairs(): void
    {
        $result = AICB_Profiles::sanitize([
            'quick_replies' => [
                ['label' => 'Tell me about Harry', 'message' => 'Tell me about Hai Ngoc NGUYEN (Harry)'],
                ['label' => '', 'message' => 'dropped, no label'],
            ],
        ]);

        $this->assertCount(1, $result['quick_replies']);
        $this->assertSame('Tell me about Harry', $result['quick_replies'][0]['label']);
    }

    public function test_sanitize_casts_is_default_to_bool(): void
    {
        $result = AICB_Profiles::sanitize(['is_default' => '1']);
        $this->assertTrue($result['is_default']);

        $result2 = AICB_Profiles::sanitize(['is_default' => 0]);
        $this->assertFalse($result2['is_default']);
    }

    public function test_path_prefix_conflicts_detects_duplicate(): void
    {
        $others = [
            ['id' => 1, 'path_prefix' => '/members/harry'],
            ['id' => 2, 'path_prefix' => '/members/james'],
        ];

        $this->assertTrue(AICB_Profiles::path_prefix_conflicts('/members/harry/', $others));
        $this->assertFalse(AICB_Profiles::path_prefix_conflicts('/members/anna', $others));
    }
}
