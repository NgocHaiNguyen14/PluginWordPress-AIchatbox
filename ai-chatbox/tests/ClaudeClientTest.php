<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-claude-client.php';

final class ClaudeClientTest extends TestCase
{
    public function test_build_request_body_includes_context_in_system_prompt(): void
    {
        $client = new AICB_Claude_Client('sk-ant-test');

        $body = $client->build_request_body(
            'You are a helpful assistant.',
            ['Harry leads our sales team.', 'Pricing starts at $10/mo.'],
            [['role' => 'user', 'content' => 'hi'], ['role' => 'assistant', 'content' => 'hello!']],
            'Who is Harry?'
        );

        $this->assertSame('claude-haiku-4-5-20251001', $body['model']);
        $this->assertStringContainsString('You are a helpful assistant.', $body['system']);
        $this->assertStringContainsString('Harry leads our sales team.', $body['system']);
        $this->assertStringContainsString('Pricing starts at $10/mo.', $body['system']);
        $this->assertCount(3, $body['messages']);
        $this->assertSame('user', $body['messages'][2]['role']);
        $this->assertSame('Who is Harry?', $body['messages'][2]['content']);
    }

    public function test_build_request_body_without_context_omits_context_block(): void
    {
        $client = new AICB_Claude_Client('sk-ant-test');

        $body = $client->build_request_body('Persona only.', [], [], 'Hello');

        $this->assertSame('Persona only.', $body['system']);
    }

    public function test_send_message_returns_text_on_success(): void
    {
        $transport = function (string $url, array $args) {
            return [
                'response' => ['code' => 200],
                'body' => json_encode(['content' => [['type' => 'text', 'text' => 'Harry leads sales.']]]),
            ];
        };

        $client = new AICB_Claude_Client('sk-ant-test', $transport);
        $result = $client->send_message('persona', [], [], 'Who is Harry?');

        $this->assertTrue($result['ok']);
        $this->assertSame('Harry leads sales.', $result['text']);
        $this->assertNull($result['error']);
    }

    public function test_send_message_returns_error_on_non_200(): void
    {
        $transport = function (string $url, array $args) {
            return [
                'response' => ['code' => 401],
                'body' => json_encode(['error' => ['message' => 'invalid api key']]),
            ];
        };

        $client = new AICB_Claude_Client('bad-key', $transport);
        $result = $client->send_message('persona', [], [], 'Hi');

        $this->assertFalse($result['ok']);
        $this->assertSame('', $result['text']);
        $this->assertStringContainsString('invalid api key', $result['error']);
    }

    public function test_send_message_returns_error_when_transport_returns_wp_error_shape(): void
    {
        $transport = function (string $url, array $args) {
            return ['is_wp_error' => true, 'message' => 'cURL timeout'];
        };

        $client = new AICB_Claude_Client('sk-ant-test', $transport);
        $result = $client->send_message('persona', [], [], 'Hi');

        $this->assertFalse($result['ok']);
        $this->assertSame('cURL timeout', $result['error']);
    }
}
