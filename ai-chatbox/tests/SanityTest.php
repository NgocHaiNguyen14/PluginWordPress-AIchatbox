<?php
use PHPUnit\Framework\TestCase;

final class SanityTest extends TestCase
{
    public function test_phpunit_runs(): void
    {
        $this->assertTrue(true);
    }

    public function test_wp_stub_sanitizes_text(): void
    {
        $this->assertSame('hello world', sanitize_text_field('  <b>hello</b>   world  '));
    }
}
