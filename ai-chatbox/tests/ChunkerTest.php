<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-knowledge-base.php';

final class ChunkerTest extends TestCase
{
    public function test_short_text_returns_single_chunk(): void
    {
        $chunks = AICB_Knowledge_Base::chunk_text('Harry leads our sales team.', 700, 100);

        $this->assertCount(1, $chunks);
        $this->assertSame('Harry leads our sales team.', $chunks[0]);
    }

    public function test_long_text_is_split_into_overlapping_chunks(): void
    {
        $text = str_repeat('A', 1000) . str_repeat('B', 1000);

        $chunks = AICB_Knowledge_Base::chunk_text($text, 700, 100);

        $this->assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(700, strlen($chunk));
        }
        // Overlap: the tail of chunk 1 should reappear at the head of chunk 2.
        $tail_of_first = substr($chunks[0], -100);
        $this->assertStringContainsString($tail_of_first, $text);
        $this->assertSame($tail_of_first, substr($chunks[1], 0, 100));
    }

    public function test_empty_text_returns_empty_array(): void
    {
        $this->assertSame([], AICB_Knowledge_Base::chunk_text('   '));
    }
}
