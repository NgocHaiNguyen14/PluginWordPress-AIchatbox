<?php

class AICB_Claude_Client
{
    private string $api_key;
    /** @var callable */
    private $transport;

    const MODEL = 'claude-haiku-4-5-20251001';
    const API_URL = 'https://api.anthropic.com/v1/messages';

    public function __construct(string $api_key, callable $transport = null)
    {
        $this->api_key = $api_key;
        $this->transport = $transport ?? [$this, 'default_transport'];
    }

    private function default_transport(string $url, array $args)
    {
        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            return ['is_wp_error' => true, 'message' => $response->get_error_message()];
        }

        return [
            'response' => ['code' => wp_remote_retrieve_response_code($response)],
            'body' => wp_remote_retrieve_body($response),
        ];
    }

    public function build_request_body(string $system_prompt, array $context_chunks, array $history, string $user_message): array
    {
        $system = $system_prompt;
        $system .= "\n\nReply in plain text only. Do not use Markdown formatting or symbols such as #, *, **, _, `, ~, or [text](url) links.";

        if (!empty($context_chunks)) {
            $system .= "\n\nUse the following business information to answer, if relevant:\n";
            $system .= implode("\n---\n", $context_chunks);
        }

        $messages = [];
        foreach ($history as $turn) {
            $messages[] = ['role' => $turn['role'], 'content' => $turn['content']];
        }
        $messages[] = ['role' => 'user', 'content' => $user_message];

        return [
            'model' => self::MODEL,
            'max_tokens' => 1024,
            'system' => $system,
            'messages' => $messages,
        ];
    }

    public function send_message(string $system_prompt, array $context_chunks, array $history, string $user_message): array
    {
        $body = $this->build_request_body($system_prompt, $context_chunks, $history, $user_message);

        $args = [
            'headers' => [
                'x-api-key' => $this->api_key,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ],
            'body' => json_encode($body),
            'timeout' => 30,
        ];

        $raw = call_user_func($this->transport, self::API_URL, $args);

        if (!empty($raw['is_wp_error'])) {
            return ['ok' => false, 'text' => '', 'error' => $raw['message']];
        }

        $code = $raw['response']['code'] ?? 0;
        $decoded = json_decode($raw['body'] ?? '', true);

        if ($code !== 200) {
            $message = $decoded['error']['message'] ?? 'Unknown error from Claude API';
            return ['ok' => false, 'text' => '', 'error' => $message];
        }

        $text = $decoded['content'][0]['text'] ?? '';

        return ['ok' => true, 'text' => $text, 'error' => null];
    }
}
