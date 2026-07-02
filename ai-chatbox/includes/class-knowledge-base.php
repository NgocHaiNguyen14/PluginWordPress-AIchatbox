<?php

class AICB_Knowledge_Base
{
    private $wpdb;
    private string $chunks_table;

    public function __construct($wpdb, string $chunks_table)
    {
        $this->wpdb = $wpdb;
        $this->chunks_table = $chunks_table;
    }

    public static function chunk_text(string $text, int $chunk_size = 700, int $overlap = 100): array
    {
        $text = trim(preg_replace('/\s+/', ' ', $text));

        if ('' === $text) {
            return [];
        }

        if (strlen($text) <= $chunk_size) {
            return [$text];
        }

        $chunks = [];
        $step = $chunk_size - $overlap;
        $offset = 0;
        $length = strlen($text);

        while ($offset < $length) {
            $chunk = substr($text, $offset, $chunk_size);
            $chunks[] = $chunk;

            if ($offset + $chunk_size >= $length) {
                break;
            }

            $offset += $step;
        }

        return $chunks;
    }

    public function search_chunks(int $profile_id, string $query_text, int $limit = 5): array
    {
        $sql = $this->wpdb->prepare(
            "SELECT chunk_text FROM {$this->chunks_table}
             WHERE profile_id = %d
             AND MATCH (chunk_text) AGAINST (%s IN NATURAL LANGUAGE MODE)
             LIMIT %d",
            $profile_id,
            $query_text,
            $limit
        );

        $results = $this->wpdb->get_col($sql);

        return is_array($results) ? $results : [];
    }
}
