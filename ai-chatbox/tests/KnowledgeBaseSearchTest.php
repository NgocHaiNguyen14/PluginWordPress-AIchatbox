<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-knowledge-base.php';

final class FakeWpdbForSearch
{
    public array $preparedCalls = [];
    public array $resultsToReturn = [];

    public function prepare(string $query, ...$args): string
    {
        $this->preparedCalls[] = ['query' => $query, 'args' => $args];
        foreach ($args as $arg) {
            $query = preg_replace('/%[sd]/', is_int($arg) ? (string) $arg : "'" . $arg . "'", $query, 1);
        }
        return $query;
    }

    public function get_col(string $query): array
    {
        return $this->resultsToReturn;
    }
}

final class KnowledgeBaseSearchTest extends TestCase
{
    public function test_search_chunks_scopes_to_profile_and_uses_fulltext(): void
    {
        $wpdb = new FakeWpdbForSearch();
        $wpdb->resultsToReturn = ['Harry leads our sales team.'];

        $kb = new AICB_Knowledge_Base($wpdb, 'wp_aicb_chunks');
        $results = $kb->search_chunks(3, 'Who manages sales?', 5);

        $this->assertSame(['Harry leads our sales team.'], $results);
        $this->assertCount(1, $wpdb->preparedCalls);
        $this->assertStringContainsString('MATCH (chunk_text) AGAINST (%s', $wpdb->preparedCalls[0]['query']);
        $this->assertStringContainsString('profile_id = %d', $wpdb->preparedCalls[0]['query']);
        $this->assertStringContainsString('LIMIT %d', $wpdb->preparedCalls[0]['query']);
        $this->assertSame([3, 'Who manages sales?', 5], $wpdb->preparedCalls[0]['args']);
    }

    public function test_search_chunks_returns_empty_array_when_no_matches(): void
    {
        $wpdb = new FakeWpdbForSearch();
        $wpdb->resultsToReturn = [];

        $kb = new AICB_Knowledge_Base($wpdb, 'wp_aicb_chunks');
        $results = $kb->search_chunks(3, 'completely unrelated query', 5);

        $this->assertSame([], $results);
    }
}
