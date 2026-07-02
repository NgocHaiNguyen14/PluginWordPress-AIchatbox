# AI Chatbox WordPress Plugin Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a self-contained WordPress plugin (`ai-chatbox`) that adds a floating AI chat widget backed by the Anthropic Claude API, with an admin-configurable knowledge base searched via free MySQL `FULLTEXT` keyword search (no embeddings, no second paid API).

**Architecture:** Pure PHP plugin (no PHP framework), vanilla JS frontend widget, WordPress Settings API + custom `wp_options` + two custom DB tables (`wp_aicb_documents`, `wp_aicb_chunks`). All chat happens via `wp_ajax` / `wp_ajax_nopriv` — no page reloads, no persistent history.

**Tech Stack:** PHP 7.4+, WordPress hooks/Settings API, MySQL `FULLTEXT` index, `smalot/pdfparser` (Composer, bundled) for PDF text extraction, PHPUnit 9 for pure-logic unit tests, vanilla JS/CSS for the widget.

## Global Constraints

- Only paid external dependency: Anthropic Claude API, called via `wp_remote_post()`, using the site owner's own API key (never sent to the browser). Model id used in code: `claude-haiku-4-5-20251001` (per spec edit replacing the original Sonnet default).
- Knowledge-base retrieval is MySQL `FULLTEXT` keyword search only — no embeddings, no other paid API, no extra server infrastructure.
- Supported document types: `.txt` and `.pdf` only.
- Settings persist in `wp_options`; documents/chunks persist in custom tables created on activation.
- Conversation state is in-memory in the browser only — no persistence, no cookies, no visitor login.
- No central server / no multi-tenant backend — every install is fully self-contained.
- This project has **no git repository** (confirmed with user) — skip all `git add`/`git commit` steps in tasks below; treat each "Run tests" step as the checkpoint instead.
- Composer is installed locally as `composer.phar` at the project root (`/home/sefas/Documents/Hai/PluginWordPress-AIchatbox/composer.phar`) — no system-wide install, no sudo used.

---

### Task 1: Composer & PHPUnit Scaffolding

**Files:**
- Create: `ai-chatbox/composer.json`
- Create: `ai-chatbox/phpunit.xml`
- Create: `ai-chatbox/tests/wp-stubs.php`
- Create: `ai-chatbox/tests/bootstrap.php`
- Create: `ai-chatbox/tests/SanityTest.php`

**Interfaces:**
- Produces: `tests/bootstrap.php` is the PHPUnit bootstrap every later test file relies on (loads Composer autoload + WP stubs). `tests/wp-stubs.php` provides guarded (`function_exists`) shims for WordPress functions used by pure-logic classes in later tasks: `sanitize_text_field`, `sanitize_textarea_field`, `sanitize_hex_color`, `absint`.

- [ ] **Step 1: Write `composer.json`**

```json
{
    "name": "yourcompany/ai-chatbox",
    "description": "AI-powered customer support chat widget for WordPress, backed by Claude.",
    "require": {
        "php": ">=7.4",
        "smalot/pdfparser": "^2.7"
    },
    "require-dev": {
        "phpunit/phpunit": "^9.6"
    }
}
```

- [ ] **Step 2: Install dependencies**

Run: `php composer.phar install --working-dir=ai-chatbox` (from `/home/sefas/Documents/Hai/PluginWordPress-AIchatbox`)
Expected: `ai-chatbox/vendor/` is created, including `vendor/bin/phpunit` and `vendor/smalot/pdfparser`.

- [ ] **Step 3: Write `phpunit.xml`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="tests/bootstrap.php" colors="true">
    <testsuites>
        <testsuite name="unit">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 4: Write `tests/wp-stubs.php`**

```php
<?php
// Minimal stand-ins for the WordPress functions our pure-logic classes call.
// These are NOT full WordPress - just enough behavior to unit test our own code.

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        $str = wp_check_invalid_utf8_stub($str);
        $str = preg_replace('/<[^>]*>/', '', (string) $str);
        return trim(preg_replace('/[\r\n\t ]+/', ' ', $str));
    }
}

if (!function_exists('wp_check_invalid_utf8_stub')) {
    function wp_check_invalid_utf8_stub($str) {
        return (string) $str;
    }
}

if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($str) {
        return trim((string) $str);
    }
}

if (!function_exists('sanitize_hex_color')) {
    function sanitize_hex_color($color) {
        if ('' === $color) {
            return '';
        }
        return preg_match('/^#[0-9a-fA-F]{3,6}$/', $color) ? $color : '';
    }
}

if (!function_exists('absint')) {
    function absint($maybeint) {
        return abs((int) $maybeint);
    }
}
```

- [ ] **Step 5: Write `tests/bootstrap.php`**

```php
<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/wp-stubs.php';
```

- [ ] **Step 6: Write a sanity test, `tests/SanityTest.php`**

```php
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
```

- [ ] **Step 7: Run the test suite**

Run: `ai-chatbox/vendor/bin/phpunit --configuration ai-chatbox/phpunit.xml`
Expected: `OK (2 tests, 2 assertions)`

---

### Task 2: Database Installer

**Files:**
- Create: `ai-chatbox/includes/class-db-installer.php`
- Test: `ai-chatbox/tests/DbInstallerTest.php`

**Interfaces:**
- Produces: `AICB_DB_Installer::get_documents_table_sql(string $table_name, string $charset_collate): string` and `AICB_DB_Installer::get_chunks_table_sql(string $table_name, string $documents_table, string $charset_collate): string` — pure string builders, no WP/db calls. `AICB_DB_Installer::install(): void` — WP-only glue (calls `dbDelta`), not unit tested, covered by the manual WP verification in Task 10.

- [ ] **Step 1: Write the failing test, `tests/DbInstallerTest.php`**

```php
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
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ai-chatbox/vendor/bin/phpunit --configuration ai-chatbox/phpunit.xml --filter DbInstallerTest`
Expected: FAIL — `Class "AICB_DB_Installer" not found`

- [ ] **Step 3: Write `includes/class-db-installer.php`**

```php
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
            filename varchar(255) NOT NULL,
            file_type varchar(10) NOT NULL,
            uploaded_at datetime NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'processed',
            PRIMARY KEY  (id)
        ) {$charset_collate};";
    }

    public static function get_chunks_table_sql(string $table_name, string $documents_table, string $charset_collate): string
    {
        return "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            document_id bigint(20) unsigned NOT NULL,
            chunk_text longtext NOT NULL,
            chunk_index int(11) NOT NULL,
            PRIMARY KEY  (id),
            KEY document_id (document_id),
            FULLTEXT KEY chunk_text_idx (chunk_text)
        ) {$charset_collate};";
    }

    public static function install(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $documents_table = $wpdb->prefix . 'aicb_documents';
        $chunks_table = $wpdb->prefix . 'aicb_chunks';

        dbDelta(self::get_documents_table_sql($documents_table, $charset_collate));
        dbDelta(self::get_chunks_table_sql($chunks_table, $documents_table, $charset_collate));
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `ai-chatbox/vendor/bin/phpunit --configuration ai-chatbox/phpunit.xml --filter DbInstallerTest`
Expected: `OK (2 tests, 9 assertions)`

---

### Task 3: Settings Sanitizer and Admin Settings Page

**Files:**
- Create: `ai-chatbox/includes/class-admin-settings.php`
- Create: `ai-chatbox/admin/settings-page.php`
- Test: `ai-chatbox/tests/SettingsSanitizeTest.php`

**Interfaces:**
- Consumes: `tests/wp-stubs.php` from Task 1 (`sanitize_text_field`, `sanitize_textarea_field`, `sanitize_hex_color`, `absint`).
- Produces: `AICB_Admin_Settings::sanitize(array $input): array` — pure function, returns a sanitized associative array with keys: `anthropic_api_key`, `assistant_name`, `avatar_url`, `system_prompt`, `welcome_message`, `accent_color`, `widget_position`, `quick_replies` (array of `['label' => string, 'message' => string]`), `max_upload_mb` (int), `max_documents` (int). Later tasks (Ajax handler, widget enqueue) read these same option keys from `wp_options` under the option name `aicb_settings` (a single serialized array, simpler than one `wp_option` row per field).
- Produces: `AICB_Admin_Settings::get_defaults(): array` — same shape, used when no settings saved yet.
- Produces: `AICB_Admin_Settings::register(): void` — WP-only glue (Settings API registration), not unit tested.

- [ ] **Step 1: Write the failing test, `tests/SettingsSanitizeTest.php`**

```php
<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-admin-settings.php';

final class SettingsSanitizeTest extends TestCase
{
    public function test_sanitize_fills_in_defaults_for_missing_fields(): void
    {
        $result = AICB_Admin_Settings::sanitize([]);

        $this->assertSame('', $result['anthropic_api_key']);
        $this->assertSame('Assistant', $result['assistant_name']);
        $this->assertSame('bottom-right', $result['widget_position']);
        $this->assertSame(20, $result['max_documents']);
        $this->assertSame(5, $result['max_upload_mb']);
        $this->assertSame([], $result['quick_replies']);
    }

    public function test_sanitize_trims_and_keeps_valid_fields(): void
    {
        $result = AICB_Admin_Settings::sanitize([
            'anthropic_api_key' => '  sk-ant-abc123  ',
            'assistant_name' => 'Harry Bot',
            'widget_position' => 'bottom-left',
            'accent_color' => '#3366ff',
            'max_documents' => '15',
            'max_upload_mb' => '8',
        ]);

        $this->assertSame('sk-ant-abc123', $result['anthropic_api_key']);
        $this->assertSame('Harry Bot', $result['assistant_name']);
        $this->assertSame('bottom-left', $result['widget_position']);
        $this->assertSame('#3366ff', $result['accent_color']);
        $this->assertSame(15, $result['max_documents']);
        $this->assertSame(8, $result['max_upload_mb']);
    }

    public function test_sanitize_rejects_invalid_widget_position(): void
    {
        $result = AICB_Admin_Settings::sanitize(['widget_position' => 'top-center']);

        $this->assertSame('bottom-right', $result['widget_position']);
    }

    public function test_sanitize_rejects_invalid_hex_color(): void
    {
        $result = AICB_Admin_Settings::sanitize(['accent_color' => 'not-a-color']);

        $this->assertSame('#4f46e5', $result['accent_color']);
    }

    public function test_sanitize_filters_quick_replies_to_label_and_message_pairs(): void
    {
        $result = AICB_Admin_Settings::sanitize([
            'quick_replies' => [
                ['label' => 'Who is Harry?', 'message' => 'Who is Harry?'],
                ['label' => '', 'message' => 'should be dropped, no label'],
                ['label' => 'Pricing', 'message' => ''],
            ],
        ]);

        $this->assertCount(1, $result['quick_replies']);
        $this->assertSame('Who is Harry?', $result['quick_replies'][0]['label']);
        $this->assertSame('Who is Harry?', $result['quick_replies'][0]['message']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ai-chatbox/vendor/bin/phpunit --configuration ai-chatbox/phpunit.xml --filter SettingsSanitizeTest`
Expected: FAIL — `Class "AICB_Admin_Settings" not found`

- [ ] **Step 3: Write `includes/class-admin-settings.php`**

```php
<?php

class AICB_Admin_Settings
{
    const OPTION_NAME = 'aicb_settings';
    const ALLOWED_POSITIONS = ['bottom-right', 'bottom-left'];

    public static function get_defaults(): array
    {
        return [
            'anthropic_api_key' => '',
            'assistant_name' => 'Assistant',
            'avatar_url' => '',
            'system_prompt' => 'You are a helpful customer support assistant.',
            'welcome_message' => 'Hi! How can I help you today?',
            'accent_color' => '#4f46e5',
            'widget_position' => 'bottom-right',
            'quick_replies' => [],
            'max_upload_mb' => 5,
            'max_documents' => 20,
        ];
    }

    public static function sanitize(array $input): array
    {
        $defaults = self::get_defaults();

        $api_key = isset($input['anthropic_api_key']) ? trim((string) $input['anthropic_api_key']) : $defaults['anthropic_api_key'];

        $assistant_name = isset($input['assistant_name'])
            ? sanitize_text_field($input['assistant_name'])
            : $defaults['assistant_name'];
        if ('' === $assistant_name) {
            $assistant_name = $defaults['assistant_name'];
        }

        $avatar_url = isset($input['avatar_url']) ? trim((string) $input['avatar_url']) : $defaults['avatar_url'];

        $system_prompt = isset($input['system_prompt'])
            ? sanitize_textarea_field($input['system_prompt'])
            : $defaults['system_prompt'];
        if ('' === $system_prompt) {
            $system_prompt = $defaults['system_prompt'];
        }

        $welcome_message = isset($input['welcome_message'])
            ? sanitize_text_field($input['welcome_message'])
            : $defaults['welcome_message'];
        if ('' === $welcome_message) {
            $welcome_message = $defaults['welcome_message'];
        }

        $accent_color = isset($input['accent_color']) ? sanitize_hex_color($input['accent_color']) : '';
        if ('' === $accent_color) {
            $accent_color = $defaults['accent_color'];
        }

        $widget_position = isset($input['widget_position']) ? (string) $input['widget_position'] : $defaults['widget_position'];
        if (!in_array($widget_position, self::ALLOWED_POSITIONS, true)) {
            $widget_position = $defaults['widget_position'];
        }

        $max_upload_mb = isset($input['max_upload_mb']) ? absint($input['max_upload_mb']) : $defaults['max_upload_mb'];
        if ($max_upload_mb < 1) {
            $max_upload_mb = $defaults['max_upload_mb'];
        }

        $max_documents = isset($input['max_documents']) ? absint($input['max_documents']) : $defaults['max_documents'];
        if ($max_documents < 1) {
            $max_documents = $defaults['max_documents'];
        }

        $quick_replies = [];
        if (isset($input['quick_replies']) && is_array($input['quick_replies'])) {
            foreach ($input['quick_replies'] as $reply) {
                $label = isset($reply['label']) ? sanitize_text_field($reply['label']) : '';
                $message = isset($reply['message']) ? sanitize_text_field($reply['message']) : '';
                if ('' === $label || '' === $message) {
                    continue;
                }
                $quick_replies[] = ['label' => $label, 'message' => $message];
            }
        }

        return [
            'anthropic_api_key' => $api_key,
            'assistant_name' => $assistant_name,
            'avatar_url' => $avatar_url,
            'system_prompt' => $system_prompt,
            'welcome_message' => $welcome_message,
            'accent_color' => $accent_color,
            'widget_position' => $widget_position,
            'quick_replies' => $quick_replies,
            'max_upload_mb' => $max_upload_mb,
            'max_documents' => $max_documents,
        ];
    }

    public static function get_settings(): array
    {
        $stored = get_option(self::OPTION_NAME, []);
        return wp_parse_args(is_array($stored) ? $stored : [], self::get_defaults());
    }

    public static function register(): void
    {
        register_setting('aicb_settings_group', self::OPTION_NAME, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitize'],
            'default' => self::get_defaults(),
        ]);

        add_options_page(
            'AI Chatbox Settings',
            'AI Chatbox',
            'manage_options',
            'aicb-settings',
            [self::class, 'render_page']
        );
    }

    public static function render_page(): void
    {
        $settings = self::get_settings();
        require __DIR__ . '/../admin/settings-page.php';
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `ai-chatbox/vendor/bin/phpunit --configuration ai-chatbox/phpunit.xml --filter SettingsSanitizeTest`
Expected: `OK (5 tests, 15 assertions)`

- [ ] **Step 5: Write the settings page template, `admin/settings-page.php`**

```php
<?php
if (!defined('ABSPATH')) {
    exit;
}
/** @var array $settings */
?>
<div class="wrap aicb-settings-wrap">
    <h1>AI Chatbox Settings</h1>
    <form method="post" action="options.php">
        <?php settings_fields('aicb_settings_group'); ?>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="aicb_api_key">Anthropic API Key</label></th>
                <td><input type="password" id="aicb_api_key" name="aicb_settings[anthropic_api_key]" value="<?php echo esc_attr($settings['anthropic_api_key']); ?>" class="regular-text" autocomplete="off" /></td>
            </tr>
            <tr>
                <th><label for="aicb_assistant_name">Assistant Name</label></th>
                <td><input type="text" id="aicb_assistant_name" name="aicb_settings[assistant_name]" value="<?php echo esc_attr($settings['assistant_name']); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th><label for="aicb_avatar_url">Avatar URL</label></th>
                <td><input type="text" id="aicb_avatar_url" name="aicb_settings[avatar_url]" value="<?php echo esc_attr($settings['avatar_url']); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th><label for="aicb_system_prompt">System Prompt</label></th>
                <td><textarea id="aicb_system_prompt" name="aicb_settings[system_prompt]" rows="6" class="large-text"><?php echo esc_textarea($settings['system_prompt']); ?></textarea></td>
            </tr>
            <tr>
                <th><label for="aicb_welcome_message">Welcome Message</label></th>
                <td><input type="text" id="aicb_welcome_message" name="aicb_settings[welcome_message]" value="<?php echo esc_attr($settings['welcome_message']); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th><label for="aicb_accent_color">Accent Color</label></th>
                <td><input type="text" id="aicb_accent_color" name="aicb_settings[accent_color]" value="<?php echo esc_attr($settings['accent_color']); ?>" class="aicb-color-picker" /></td>
            </tr>
            <tr>
                <th><label for="aicb_widget_position">Widget Position</label></th>
                <td>
                    <select id="aicb_widget_position" name="aicb_settings[widget_position]">
                        <option value="bottom-right" <?php selected($settings['widget_position'], 'bottom-right'); ?>>Bottom Right</option>
                        <option value="bottom-left" <?php selected($settings['widget_position'], 'bottom-left'); ?>>Bottom Left</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="aicb_max_upload_mb">Max Upload Size (MB)</label></th>
                <td><input type="number" min="1" id="aicb_max_upload_mb" name="aicb_settings[max_upload_mb]" value="<?php echo esc_attr($settings['max_upload_mb']); ?>" class="small-text" /></td>
            </tr>
            <tr>
                <th><label for="aicb_max_documents">Max Documents</label></th>
                <td><input type="number" min="1" id="aicb_max_documents" name="aicb_settings[max_documents]" value="<?php echo esc_attr($settings['max_documents']); ?>" class="small-text" /></td>
            </tr>
            <tr>
                <th>Quick Replies</th>
                <td>
                    <div id="aicb-quick-replies-list">
                        <?php foreach ($settings['quick_replies'] as $i => $reply) : ?>
                        <p>
                            <input type="text" placeholder="Button label" name="aicb_settings[quick_replies][<?php echo (int) $i; ?>][label]" value="<?php echo esc_attr($reply['label']); ?>" />
                            <input type="text" placeholder="Message to send" name="aicb_settings[quick_replies][<?php echo (int) $i; ?>][message]" value="<?php echo esc_attr($reply['message']); ?>" />
                        </p>
                        <?php endforeach; ?>
                    </div>
                    <p class="description">Saving with an empty row removes it. Add more rows by editing and re-saving with new indexes, or use the admin JS "Add row" button.</p>
                </td>
            </tr>
        </table>
        <h2>Knowledge Base Documents</h2>
        <div id="aicb-documents-manager" data-nonce="<?php echo esc_attr(wp_create_nonce('aicb_admin_nonce')); ?>"></div>
        <?php submit_button(); ?>
    </form>
</div>
```

- [ ] **Step 6: Confirm no PHP syntax errors**

Run: `php -l ai-chatbox/admin/settings-page.php && php -l ai-chatbox/includes/class-admin-settings.php`
Expected: `No syntax errors detected` for both files.

---

### Task 4: Text Chunker

**Files:**
- Create: `ai-chatbox/includes/class-knowledge-base.php`
- Test: `ai-chatbox/tests/ChunkerTest.php`

**Interfaces:**
- Produces: `AICB_Knowledge_Base::chunk_text(string $text, int $chunk_size = 700, int $overlap = 100): array` — pure function, returns a list of chunk strings. Later Task 5 adds the search/query methods to this same class.

- [ ] **Step 1: Write the failing test, `tests/ChunkerTest.php`**

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ai-chatbox/vendor/bin/phpunit --configuration ai-chatbox/phpunit.xml --filter ChunkerTest`
Expected: FAIL — `Class "AICB_Knowledge_Base" not found`

- [ ] **Step 3: Write `includes/class-knowledge-base.php`**

```php
<?php

class AICB_Knowledge_Base
{
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
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `ai-chatbox/vendor/bin/phpunit --configuration ai-chatbox/phpunit.xml --filter ChunkerTest`
Expected: `OK (3 tests, 7 assertions)`

---

### Task 5: FULLTEXT Search Query Builder

**Files:**
- Modify: `ai-chatbox/includes/class-knowledge-base.php`
- Test: `ai-chatbox/tests/KnowledgeBaseSearchTest.php`

**Interfaces:**
- Consumes: a `$wpdb`-shaped object passed into the constructor — only the two methods used (`prepare(string $query, ...$args): string` and `get_col(string $query): array`) need to exist; tests supply a fake.
- Produces: `AICB_Knowledge_Base::__construct($wpdb, string $chunks_table)`; `->search_chunks(string $query_text, int $limit = 5): array` — returns a list of matching `chunk_text` strings (empty array on no match).

- [ ] **Step 1: Write the failing test, `tests/KnowledgeBaseSearchTest.php`**

```php
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
        // Mimic wpdb::prepare just enough for assertions: substitute %s naively.
        foreach ($args as $arg) {
            $query = preg_replace('/%s/', "'" . $arg . "'", $query, 1);
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
    public function test_search_chunks_uses_fulltext_match_against(): void
    {
        $wpdb = new FakeWpdbForSearch();
        $wpdb->resultsToReturn = ['Harry leads our sales team.'];

        $kb = new AICB_Knowledge_Base($wpdb, 'wp_aicb_chunks');
        $results = $kb->search_chunks('Who manages sales?', 5);

        $this->assertSame(['Harry leads our sales team.'], $results);
        $this->assertCount(1, $wpdb->preparedCalls);
        $this->assertStringContainsString('MATCH (chunk_text) AGAINST (%s', $wpdb->preparedCalls[0]['query']);
        $this->assertStringContainsString('LIMIT %d', $wpdb->preparedCalls[0]['query']);
        $this->assertSame(['Who manages sales?', 5], $wpdb->preparedCalls[0]['args']);
    }

    public function test_search_chunks_returns_empty_array_when_no_matches(): void
    {
        $wpdb = new FakeWpdbForSearch();
        $wpdb->resultsToReturn = [];

        $kb = new AICB_Knowledge_Base($wpdb, 'wp_aicb_chunks');
        $results = $kb->search_chunks('completely unrelated query', 5);

        $this->assertSame([], $results);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ai-chatbox/vendor/bin/phpunit --configuration ai-chatbox/phpunit.xml --filter KnowledgeBaseSearchTest`
Expected: FAIL — constructor `AICB_Knowledge_Base::__construct()` does not exist (current class only has a static method).

- [ ] **Step 3: Modify `includes/class-knowledge-base.php`** to add the constructor and search method, keeping `chunk_text` as-is

```php
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

    public function search_chunks(string $query_text, int $limit = 5): array
    {
        $sql = $this->wpdb->prepare(
            "SELECT chunk_text FROM {$this->chunks_table}
             WHERE MATCH (chunk_text) AGAINST (%s IN NATURAL LANGUAGE MODE)
             LIMIT %d",
            $query_text,
            $limit
        );

        $results = $this->wpdb->get_col($sql);

        return is_array($results) ? $results : [];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `ai-chatbox/vendor/bin/phpunit --configuration ai-chatbox/phpunit.xml --filter KnowledgeBaseSearchTest`
Expected: `OK (2 tests, 6 assertions)`

- [ ] **Step 5: Run the full suite so far**

Run: `ai-chatbox/vendor/bin/phpunit --configuration ai-chatbox/phpunit.xml`
Expected: All tests pass (Sanity, DbInstaller, SettingsSanitize, Chunker, KnowledgeBaseSearch).

---

### Task 6: Document Parser (.txt and .pdf)

**Files:**
- Create: `ai-chatbox/includes/class-document-parser.php`
- Create: `ai-chatbox/tests/fixtures/sample.txt`
- Create: `ai-chatbox/tests/fixtures/sample.pdf`
- Test: `ai-chatbox/tests/DocumentParserTest.php`

**Interfaces:**
- Produces: `AICB_Document_Parser::extract_text(string $file_path, string $file_type): string` — `$file_type` is `'txt'` or `'pdf'`; throws `InvalidArgumentException` for any other type.

- [ ] **Step 1: Create the fixture text file, `tests/fixtures/sample.txt`**

```
Harry is the head of our sales team. He has worked at the company for eight years
and specializes in enterprise accounts. For pricing questions, contact sales@example.com.
```

- [ ] **Step 2: Generate the fixture PDF**

Run this PHP script once to produce a real, parseable PDF fixture (uses the same `smalot/pdfparser` package's sibling library is not needed for writing PDFs, so generate via a minimal raw PDF, then verify it parses):

```bash
php -r '
$content = "Harry is the head of our sales team. He specializes in enterprise accounts.";
$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n".
"2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n".
"3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]/Resources<</Font<</F1 5 0 R>>>>/Contents 4 0 R>>endobj\n".
"4 0 obj<</Length 100>>stream\nBT /F1 12 Tf 50 700 Td (".$content.") Tj ET\nendstream endobj\n".
"5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\n".
"trailer<</Root 1 0 R>>\n";
file_put_contents("ai-chatbox/tests/fixtures/sample.pdf", $pdf);
'
```

Expected: `ai-chatbox/tests/fixtures/sample.pdf` is created (a minimal valid PDF with the sentence as a text-drawing operator).

- [ ] **Step 3: Write the failing test, `tests/DocumentParserTest.php`**

```php
<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-document-parser.php';

final class DocumentParserTest extends TestCase
{
    public function test_extract_text_from_txt_file(): void
    {
        $path = __DIR__ . '/fixtures/sample.txt';

        $text = AICB_Document_Parser::extract_text($path, 'txt');

        $this->assertStringContainsString('Harry is the head of our sales team.', $text);
    }

    public function test_extract_text_from_pdf_file(): void
    {
        $path = __DIR__ . '/fixtures/sample.pdf';

        $text = AICB_Document_Parser::extract_text($path, 'pdf');

        $this->assertStringContainsString('Harry', $text);
    }

    public function test_extract_text_rejects_unsupported_type(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AICB_Document_Parser::extract_text(__DIR__ . '/fixtures/sample.txt', 'docx');
    }
}
```

- [ ] **Step 4: Run test to verify it fails**

Run: `ai-chatbox/vendor/bin/phpunit --configuration ai-chatbox/phpunit.xml --filter DocumentParserTest`
Expected: FAIL — `Class "AICB_Document_Parser" not found`

- [ ] **Step 5: Write `includes/class-document-parser.php`**

```php
<?php

class AICB_Document_Parser
{
    public static function extract_text(string $file_path, string $file_type): string
    {
        switch ($file_type) {
            case 'txt':
                return self::extract_txt($file_path);
            case 'pdf':
                return self::extract_pdf($file_path);
            default:
                throw new InvalidArgumentException("Unsupported file type: {$file_type}");
        }
    }

    private static function extract_txt(string $file_path): string
    {
        $contents = file_get_contents($file_path);
        return false === $contents ? '' : $contents;
    }

    private static function extract_pdf(string $file_path): string
    {
        $parser = new \Smalot\PdfParser\Parser();
        $pdf = $parser->parseFile($file_path);
        return $pdf->getText();
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `ai-chatbox/vendor/bin/phpunit --configuration ai-chatbox/phpunit.xml --filter DocumentParserTest`
Expected: `OK (3 tests, 3 assertions)`. If the PDF test fails because the minimal hand-built PDF doesn't parse cleanly, regenerate the fixture using `tcpdf` or any PDF writer available, or replace step 2 with copying any existing small real-world PDF containing the word "Harry" into `tests/fixtures/sample.pdf` — the test only requires that the extracted text contain "Harry".

---

### Task 7: Claude Request Payload Builder and API Client

**Files:**
- Create: `ai-chatbox/includes/class-claude-client.php`
- Test: `ai-chatbox/tests/ClaudeClientTest.php`

**Interfaces:**
- Produces: `AICB_Claude_Client::build_request_body(string $system_prompt, array $context_chunks, array $history, string $user_message): array` — pure, returns the array to JSON-encode as the Anthropic Messages API request body. `$history` is a list of `['role' => 'user'|'assistant', 'content' => string]`.
- Produces: `AICB_Claude_Client::__construct(string $api_key, callable $transport = null)` — `$transport` defaults to a closure calling real `wp_remote_post()`; tests inject a fake.
- Produces: `->send_message(string $system_prompt, array $context_chunks, array $history, string $user_message): array` — returns `['ok' => bool, 'text' => string, 'error' => string|null]`.

- [ ] **Step 1: Write the failing test, `tests/ClaudeClientTest.php`**

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ai-chatbox/vendor/bin/phpunit --configuration ai-chatbox/phpunit.xml --filter ClaudeClientTest`
Expected: FAIL — `Class "AICB_Claude_Client" not found`

- [ ] **Step 3: Write `includes/class-claude-client.php`**

```php
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `ai-chatbox/vendor/bin/phpunit --configuration ai-chatbox/phpunit.xml --filter ClaudeClientTest`
Expected: `OK (5 tests, 14 assertions)`

- [ ] **Step 5: Run the full suite**

Run: `ai-chatbox/vendor/bin/phpunit --configuration ai-chatbox/phpunit.xml`
Expected: All tests across all files pass.

---

### Task 8: AJAX Handlers (Admin Upload + Public Chat)

**Files:**
- Create: `ai-chatbox/includes/class-ajax-handler.php`

**Interfaces:**
- Consumes: `AICB_Admin_Settings::get_settings()` (Task 3), `AICB_Knowledge_Base` (Tasks 4-5), `AICB_Document_Parser::extract_text()` (Task 6), `AICB_Claude_Client` (Task 7).
- Produces: `AICB_Ajax_Handler::register(): void` — hooks `wp_ajax_aicb_upload_document`, `wp_ajax_aicb_send_message`, `wp_ajax_nopriv_aicb_send_message`. This task is WordPress-runtime glue (uses `$_POST`, `$_FILES`, nonces, `wp_send_json_*`) and is verified manually against a real WordPress install in Task 10, not via PHPUnit — there is no pure logic left to extract that isn't already covered by Tasks 2-7.

- [ ] **Step 1: Write `includes/class-ajax-handler.php`**

```php
<?php

class AICB_Ajax_Handler
{
    public static function register(): void
    {
        add_action('wp_ajax_aicb_upload_document', [self::class, 'handle_upload_document']);
        add_action('wp_ajax_aicb_send_message', [self::class, 'handle_send_message']);
        add_action('wp_ajax_nopriv_aicb_send_message', [self::class, 'handle_send_message']);
    }

    public static function handle_upload_document(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Not allowed.'], 403);
        }

        check_ajax_referer('aicb_admin_nonce', 'nonce');

        if (empty($_FILES['file'])) {
            wp_send_json_error(['message' => 'No file uploaded.']);
        }

        global $wpdb;
        $settings = AICB_Admin_Settings::get_settings();
        $documents_table = $wpdb->prefix . 'aicb_documents';
        $chunks_table = $wpdb->prefix . 'aicb_chunks';

        $existing_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$documents_table}");
        if ($existing_count >= $settings['max_documents']) {
            wp_send_json_error(['message' => 'Document limit reached for this site.']);
        }

        $file = $_FILES['file'];
        $size_mb = $file['size'] / (1024 * 1024);
        if ($size_mb > $settings['max_upload_mb']) {
            wp_send_json_error(['message' => 'File exceeds the configured size limit.']);
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['txt', 'pdf'], true)) {
            wp_send_json_error(['message' => 'Only .txt and .pdf files are supported.']);
        }

        try {
            $text = AICB_Document_Parser::extract_text($file['tmp_name'], $ext);
        } catch (\Throwable $e) {
            error_log('AICB document parse error: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Could not read this file. It may be corrupt.']);
            return;
        }

        $wpdb->insert($documents_table, [
            'filename' => sanitize_file_name($file['name']),
            'file_type' => $ext,
            'uploaded_at' => current_time('mysql'),
            'status' => 'processed',
        ]);
        $document_id = $wpdb->insert_id;

        $chunks = AICB_Knowledge_Base::chunk_text($text);
        foreach ($chunks as $index => $chunk) {
            $wpdb->insert($chunks_table, [
                'document_id' => $document_id,
                'chunk_text' => $chunk,
                'chunk_index' => $index,
            ]);
        }

        wp_send_json_success(['document_id' => $document_id, 'chunks' => count($chunks)]);
    }

    public static function handle_send_message(): void
    {
        check_ajax_referer('aicb_public_nonce', 'nonce');

        $user_message = isset($_POST['message']) ? sanitize_text_field(wp_unslash($_POST['message'])) : '';
        if ('' === $user_message) {
            wp_send_json_error(['message' => 'Empty message.']);
        }

        $history = [];
        if (isset($_POST['history']) && is_array($_POST['history'])) {
            foreach ($_POST['history'] as $turn) {
                if (isset($turn['role'], $turn['content']) && in_array($turn['role'], ['user', 'assistant'], true)) {
                    $history[] = [
                        'role' => $turn['role'],
                        'content' => sanitize_text_field(wp_unslash($turn['content'])),
                    ];
                }
            }
        }

        $settings = AICB_Admin_Settings::get_settings();

        if ('' === $settings['anthropic_api_key']) {
            error_log('AICB: no Anthropic API key configured.');
            wp_send_json_error(['message' => 'Chat is temporarily unavailable.']);
        }

        global $wpdb;
        $chunks_table = $wpdb->prefix . 'aicb_chunks';
        $kb = new AICB_Knowledge_Base($wpdb, $chunks_table);
        $context_chunks = $kb->search_chunks($user_message, 5);

        $client = new AICB_Claude_Client($settings['anthropic_api_key']);
        $result = $client->send_message($settings['system_prompt'], $context_chunks, $history, $user_message);

        if (!$result['ok']) {
            error_log('AICB Claude API error: ' . $result['error']);
            wp_send_json_error(['message' => 'Chat is temporarily unavailable.']);
        }

        wp_send_json_success(['reply' => $result['text']]);
    }
}
```

- [ ] **Step 2: Confirm no PHP syntax errors**

Run: `php -l ai-chatbox/includes/class-ajax-handler.php`
Expected: `No syntax errors detected in ai-chatbox/includes/class-ajax-handler.php`

- [ ] **Step 3: Run the full automated suite once more to confirm nothing else broke**

Run: `ai-chatbox/vendor/bin/phpunit --configuration ai-chatbox/phpunit.xml`
Expected: All prior tests still pass (this file has no unit tests of its own — it's pure WP glue, covered by Task 10's manual checklist).

---

### Task 9: Plugin Bootstrap and Activation Wiring

**Files:**
- Create: `ai-chatbox/ai-chatbox.php`
- Create: `ai-chatbox/uninstall.php`

**Interfaces:**
- Consumes: `AICB_DB_Installer::install()` (Task 2), `AICB_Admin_Settings::register()` (Task 3), `AICB_Ajax_Handler::register()` (Task 8).
- Produces: the plugin's main entry point WordPress loads on activation; enqueues the widget assets built in Task 10.

- [ ] **Step 1: Write `ai-chatbox.php`**

```php
<?php
/**
 * Plugin Name: AI Chatbox
 * Description: AI-powered customer support chat widget backed by the Anthropic Claude API, with a free keyword-searchable document knowledge base.
 * Version: 1.0.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('AICB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AICB_PLUGIN_URL', plugin_dir_url(__FILE__));
define('AICB_VERSION', '1.0.0');

require_once AICB_PLUGIN_DIR . 'vendor/autoload.php';
require_once AICB_PLUGIN_DIR . 'includes/class-db-installer.php';
require_once AICB_PLUGIN_DIR . 'includes/class-admin-settings.php';
require_once AICB_PLUGIN_DIR . 'includes/class-knowledge-base.php';
require_once AICB_PLUGIN_DIR . 'includes/class-document-parser.php';
require_once AICB_PLUGIN_DIR . 'includes/class-claude-client.php';
require_once AICB_PLUGIN_DIR . 'includes/class-ajax-handler.php';

register_activation_hook(__FILE__, ['AICB_DB_Installer', 'install']);

add_action('admin_menu', ['AICB_Admin_Settings', 'register']);
add_action('admin_init', function () {
    // Settings API requires register_setting() to also run on admin_init in
    // some WP versions' option-page flow; AICB_Admin_Settings::register()
    // already calls register_setting(), safe to call once more defensively.
});

AICB_Ajax_Handler::register();

add_action('wp_enqueue_scripts', function () {
    $settings = AICB_Admin_Settings::get_settings();

    wp_enqueue_style('aicb-widget', AICB_PLUGIN_URL . 'public/widget.css', [], AICB_VERSION);
    wp_enqueue_script('aicb-widget', AICB_PLUGIN_URL . 'public/widget.js', [], AICB_VERSION, true);

    wp_localize_script('aicb-widget', 'AICB_CONFIG', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('aicb_public_nonce'),
        'assistantName' => $settings['assistant_name'],
        'avatarUrl' => $settings['avatar_url'],
        'welcomeMessage' => $settings['welcome_message'],
        'accentColor' => $settings['accent_color'],
        'position' => $settings['widget_position'],
        'quickReplies' => $settings['quick_replies'],
    ]);
});
```

- [ ] **Step 2: Write `uninstall.php`**

```php
<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

delete_option('aicb_settings');

$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}aicb_chunks");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}aicb_documents");
```

- [ ] **Step 3: Confirm no PHP syntax errors**

Run: `php -l ai-chatbox/ai-chatbox.php && php -l ai-chatbox/uninstall.php`
Expected: `No syntax errors detected` for both files.

- [ ] **Step 4: Run the full automated suite to confirm nothing broke**

Run: `ai-chatbox/vendor/bin/phpunit --configuration ai-chatbox/phpunit.xml`
Expected: All tests still pass (this task adds no new unit tests — it's the WP bootstrap wiring everything together).

---

### Task 10: Frontend Widget (JS/CSS)

**Files:**
- Create: `ai-chatbox/public/widget.js`
- Create: `ai-chatbox/public/widget.css`

**Interfaces:**
- Consumes: the `AICB_CONFIG` global object localized by Task 9 (`ajaxUrl`, `nonce`, `assistantName`, `avatarUrl`, `welcomeMessage`, `accentColor`, `position`, `quickReplies`).
- Produces: the floating launcher button + popup chat UI described in the spec. No PHPUnit coverage (pure frontend) — verified manually in Task 11's end-to-end checklist using a real browser against a real WordPress install.

- [ ] **Step 1: Write `public/widget.css`**

```css
.aicb-launcher {
    position: fixed;
    bottom: 24px;
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: var(--aicb-accent, #4f46e5);
    color: #fff;
    border: none;
    cursor: pointer;
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    z-index: 999998;
    display: flex;
    align-items: center;
    justify-content: center;
}
.aicb-launcher.aicb-pos-right { right: 24px; }
.aicb-launcher.aicb-pos-left { left: 24px; }
.aicb-launcher img { width: 28px; height: 28px; border-radius: 50%; }
.aicb-badge {
    position: absolute;
    top: -4px;
    right: -4px;
    background: #ef4444;
    color: #fff;
    border-radius: 999px;
    font-size: 11px;
    min-width: 18px;
    height: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 4px;
}
.aicb-popup {
    position: fixed;
    bottom: 92px;
    width: 360px;
    height: 500px;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 8px 30px rgba(0,0,0,0.25);
    display: none;
    flex-direction: column;
    overflow: hidden;
    z-index: 999999;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
}
.aicb-popup.aicb-pos-right { right: 24px; }
.aicb-popup.aicb-pos-left { left: 24px; }
.aicb-popup.aicb-open { display: flex; }
.aicb-header {
    background: var(--aicb-accent, #4f46e5);
    color: #fff;
    padding: 12px 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.aicb-header img { width: 28px; height: 28px; border-radius: 50%; }
.aicb-header .aicb-name { font-weight: 600; flex: 1; }
.aicb-header .aicb-close { background: none; border: none; color: #fff; font-size: 18px; cursor: pointer; }
.aicb-messages {
    flex: 1;
    overflow-y: auto;
    padding: 12px 16px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.aicb-msg { max-width: 80%; padding: 8px 12px; border-radius: 12px; font-size: 14px; line-height: 1.4; }
.aicb-msg.aicb-user { align-self: flex-end; background: var(--aicb-accent, #4f46e5); color: #fff; }
.aicb-msg.aicb-bot { align-self: flex-start; background: #f1f1f4; color: #1a1a1a; }
.aicb-quick-replies { display: flex; flex-wrap: wrap; gap: 6px; padding: 0 16px 8px; }
.aicb-quick-reply {
    border: 1px solid var(--aicb-accent, #4f46e5);
    color: var(--aicb-accent, #4f46e5);
    background: #fff;
    border-radius: 999px;
    padding: 6px 12px;
    font-size: 13px;
    cursor: pointer;
}
.aicb-input-bar { display: flex; border-top: 1px solid #eee; padding: 8px; gap: 8px; }
.aicb-input-bar input {
    flex: 1;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 8px 10px;
    font-size: 14px;
}
.aicb-input-bar button {
    background: var(--aicb-accent, #4f46e5);
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: 8px 14px;
    cursor: pointer;
}
.aicb-input-bar input:disabled, .aicb-input-bar button:disabled { opacity: 0.6; cursor: not-allowed; }
```

- [ ] **Step 2: Write `public/widget.js`**

```javascript
(function () {
    'use strict';

    var config = window.AICB_CONFIG || {};
    var history = [];
    var unread = 0;
    var hasOpenedOnce = false;

    function el(tag, className, attrs) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (attrs) {
            Object.keys(attrs).forEach(function (key) {
                node.setAttribute(key, attrs[key]);
            });
        }
        return node;
    }

    function buildLauncher(position) {
        var launcher = el('button', 'aicb-launcher aicb-pos-' + (position === 'bottom-left' ? 'left' : 'right'));
        if (config.avatarUrl) {
            var img = el('img');
            img.src = config.avatarUrl;
            launcher.appendChild(img);
        } else {
            launcher.textContent = '💬';
        }
        var badge = el('span', 'aicb-badge');
        badge.style.display = 'none';
        launcher.appendChild(badge);
        return { launcher: launcher, badge: badge };
    }

    function buildPopup(position) {
        var popup = el('div', 'aicb-popup aicb-pos-' + (position === 'bottom-left' ? 'left' : 'right'));

        var header = el('div', 'aicb-header');
        if (config.avatarUrl) {
            var img = el('img');
            img.src = config.avatarUrl;
            header.appendChild(img);
        }
        var name = el('span', 'aicb-name');
        name.textContent = config.assistantName || 'Assistant';
        header.appendChild(name);
        var closeBtn = el('button', 'aicb-close');
        closeBtn.textContent = '✕';
        header.appendChild(closeBtn);
        popup.appendChild(header);

        var messages = el('div', 'aicb-messages');
        popup.appendChild(messages);

        var quickReplies = el('div', 'aicb-quick-replies');
        (config.quickReplies || []).forEach(function (reply) {
            var btn = el('button', 'aicb-quick-reply');
            btn.textContent = reply.label;
            btn.addEventListener('click', function () {
                sendMessage(reply.message);
            });
            quickReplies.appendChild(btn);
        });
        popup.appendChild(quickReplies);

        var inputBar = el('div', 'aicb-input-bar');
        var input = el('input', '', { type: 'text', placeholder: 'Type a message...' });
        var sendBtn = el('button');
        sendBtn.textContent = 'Send';
        inputBar.appendChild(input);
        inputBar.appendChild(sendBtn);
        popup.appendChild(inputBar);

        function trySend() {
            var text = input.value.trim();
            if (!text) return;
            input.value = '';
            sendMessage(text);
        }

        sendBtn.addEventListener('click', trySend);
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') trySend();
        });

        return { popup: popup, messages: messages, closeBtn: closeBtn, input: input, sendBtn: sendBtn };
    }

    function appendMessage(messagesEl, role, text) {
        var msg = el('div', 'aicb-msg ' + (role === 'user' ? 'aicb-user' : 'aicb-bot'));
        msg.textContent = text;
        messagesEl.appendChild(msg);
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    var refs;

    function setInputDisabled(disabled) {
        refs.input.disabled = disabled;
        refs.sendBtn.disabled = disabled;
        refs.sendBtn.textContent = disabled ? '...' : 'Send';
    }

    function sendMessage(text) {
        appendMessage(refs.messages, 'user', text);
        history.push({ role: 'user', content: text });
        setInputDisabled(true);

        var body = new URLSearchParams();
        body.append('action', 'aicb_send_message');
        body.append('nonce', config.nonce);
        body.append('message', text);
        history.forEach(function (turn, i) {
            body.append('history[' + i + '][role]', turn.role);
            body.append('history[' + i + '][content]', turn.content);
        });

        fetch(config.ajaxUrl, {
            method: 'POST',
            body: body,
        })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                setInputDisabled(false);
                var reply = (json.success && json.data && json.data.reply) || 'Sorry, something went wrong. Please try again.';
                appendMessage(refs.messages, 'assistant', reply);
                history.push({ role: 'assistant', content: reply });
                if (!isOpen()) {
                    unread += 1;
                    updateBadge();
                }
            })
            .catch(function () {
                setInputDisabled(false);
                appendMessage(refs.messages, 'assistant', 'Sorry, something went wrong. Please try again.');
            });
    }

    var launcherRefs, popupRefs;

    function isOpen() {
        return popupRefs.popup.classList.contains('aicb-open');
    }

    function updateBadge() {
        launcherRefs.badge.style.display = unread > 0 ? 'flex' : 'none';
        launcherRefs.badge.textContent = String(unread);
    }

    function openPopup() {
        popupRefs.popup.classList.add('aicb-open');
        unread = 0;
        updateBadge();
        if (!hasOpenedOnce && config.welcomeMessage) {
            appendMessage(popupRefs.messages, 'assistant', config.welcomeMessage);
            history.push({ role: 'assistant', content: config.welcomeMessage });
        }
        hasOpenedOnce = true;
    }

    function closePopup() {
        popupRefs.popup.classList.remove('aicb-open');
    }

    function init() {
        document.documentElement.style.setProperty('--aicb-accent', config.accentColor || '#4f46e5');

        launcherRefs = buildLauncher(config.position);
        popupRefs = buildPopup(config.position);
        refs = popupRefs;

        document.body.appendChild(launcherRefs.launcher);
        document.body.appendChild(popupRefs.popup);

        launcherRefs.launcher.addEventListener('click', function () {
            isOpen() ? closePopup() : openPopup();
        });
        popupRefs.closeBtn.addEventListener('click', closePopup);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
```

- [ ] **Step 3: Confirm no JS syntax errors**

Run: `node --check ai-chatbox/public/widget.js`
Expected: no output (exit code 0). If Node isn't available, skip this check and rely on the manual browser verification in Task 11.

---

### Task 11: Packaging and End-to-End Manual Verification

**Files:**
- Modify: `ai-chatbox/composer.json` (production install step only, no file changes)
- Create: `ai-chatbox.zip` (build artifact, not source)

**Interfaces:**
- Consumes: every file from Tasks 1-10.
- Produces: a real, installable WordPress plugin zip, plus a manual verification checklist confirming the spec's behaviors actually work end-to-end (this is the integration coverage that pure PHPUnit can't reach: WP hooks, AJAX wiring, real MySQL `FULLTEXT` search, real browser widget behavior).

- [ ] **Step 1: Reinstall Composer dependencies without dev packages**

Run: `php composer.phar install --working-dir=ai-chatbox --no-dev --optimize-autoloader`
Expected: `ai-chatbox/vendor/` now contains only `smalot/pdfparser` and its dependencies (no `phpunit`).

- [ ] **Step 2: Remove test-only files from the shippable plugin before zipping**

Run: `rm -rf ai-chatbox/tests ai-chatbox/phpunit.xml`
Expected: the `ai-chatbox/` directory no longer contains test files (they're not needed on a production WordPress site). If you want to re-run the test suite later, re-run `php composer.phar install --working-dir=ai-chatbox` (with dev deps) and recreate `phpunit.xml`/`tests/` from this plan's earlier tasks first — do this in a scratch copy, not the zipped one.

- [ ] **Step 3: Build the plugin zip**

Run (from the project root):
```bash
cd /home/sefas/Documents/Hai/PluginWordPress-AIchatbox && zip -r ai-chatbox.zip ai-chatbox -x "*.DS_Store"
```
Expected: `ai-chatbox.zip` is created at the project root.

- [ ] **Step 4: Manual verification checklist on a real WordPress site**

This cannot be automated without a live WordPress + MySQL environment; perform these steps by hand on a test WordPress install (local via e.g. `wp-env`/Local/XAMPP, or a staging site):

1. Go to **Plugins → Add New → Upload Plugin**, upload `ai-chatbox.zip`, click **Activate**.
   Expected: no activation errors; **Settings → AI Chatbox** menu item appears.
2. Check the database: `wp_aicb_documents` and `wp_aicb_chunks` tables exist (e.g. via phpMyAdmin or `wp db query "SHOW TABLES LIKE '%aicb%'"`).
3. Open **Settings → AI Chatbox**, enter a real Anthropic API key, set assistant name, a system prompt, a welcome message, an accent color, and two quick replies (e.g. "Who is Harry?" / "Who is Harry?"). Save.
   Expected: settings persist after page reload.
4. Upload a `.txt` file containing a line like "Harry is the head of our sales team." via the document upload control.
   Expected: success response; a row appears in `wp_aicb_documents` and at least one row in `wp_aicb_chunks`.
5. Visit the public site homepage.
   Expected: the floating launcher button appears in the configured corner with the configured accent color.
6. Click the launcher.
   Expected: popup opens, shows the welcome message, shows the configured quick-reply pills.
7. Click the "Who is Harry?" quick reply.
   Expected: the message appears as a user bubble, then (after a short delay) a Claude-generated reply appears mentioning Harry leads/heads sales — confirming the `FULLTEXT` search found the uploaded chunk and Claude used it as context.
8. Type an unrelated question with no matching document content and send it.
   Expected: Claude still replies (using persona/system prompt alone), no error shown.
9. Close the popup, reopen it.
   Expected: prior conversation messages are still visible (in-memory for the page session) until the page is reloaded; after a full page reload, the conversation resets to just the welcome message (per MVP scope — no persistence).
10. Temporarily clear the API key in settings and resend a message.
    Expected: widget shows the generic "Chat is temporarily unavailable" message instead of a raw error; check the PHP error log to confirm the real cause was logged server-side.
11. Deactivate and delete the plugin from **Plugins**.
    Expected: `uninstall.php` runs — `wp_aicb_documents`, `wp_aicb_chunks` tables and the `aicb_settings` option are removed.

- [ ] **Step 5: Record the outcome**

If all 11 manual checks pass, the plugin meets the spec end-to-end. If any check fails, return to the relevant task above (e.g. a failed quick-reply context match points back to Task 5's search query; a failed upload points back to Task 8's `handle_upload_document`), fix, and re-run the affected automated tests plus this checklist.

---

### Task 12: Admin Document Upload UI (added post-Task-11 — closing a real gap found during manual verification)

**Why this task exists:** Task 11's real end-to-end test against a live WordPress install found that `admin/settings-page.php` (Task 3) renders an empty `<div id="aicb-documents-manager" data-nonce="...">` with no file input, no upload button, and no JS to drive the already-working `aicb_upload_document` AJAX endpoint (Task 8, verified working via direct AJAX call). No task in the original 11-task plan covered building this control, so site owners had no way to actually upload a document through the dashboard. This task closes that gap.

**Files:**
- Create: `ai-chatbox/admin/assets/admin.js`
- Modify: `ai-chatbox/includes/class-admin-settings.php` (add an `admin_enqueue_scripts` hook scoped to only the plugin's own settings page)

**Interfaces:**
- Consumes: the existing `#aicb-documents-manager` div and its `data-nonce` attribute (already rendered by `admin/settings-page.php`, Task 3); the existing `aicb_upload_document` AJAX action (Task 8, accepts `action`, `nonce`, `file` as multipart form fields, returns `{success: true, data: {document_id, chunks}}` or `{success: false, data: {message}}`); WordPress's built-in global `ajaxurl` JS variable, automatically defined on every `wp-admin` page — no need to localize it separately.
- Produces: `AICB_Admin_Settings::enqueue_assets(string $hook_suffix): void` — registered via `admin_enqueue_scripts`, enqueues `admin/assets/admin.js` only when `$hook_suffix === 'settings_page_aicb-settings'` (the hook suffix WordPress assigns to a page added via `add_options_page`).

- [ ] **Step 1: Write `admin/assets/admin.js`**

```javascript
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var container = document.getElementById('aicb-documents-manager');
        if (!container) {
            return;
        }

        var nonce = container.dataset.nonce;

        var fileInput = document.createElement('input');
        fileInput.type = 'file';
        fileInput.accept = '.txt,.pdf';
        fileInput.id = 'aicb-document-file';

        var uploadButton = document.createElement('button');
        uploadButton.type = 'button';
        uploadButton.className = 'button';
        uploadButton.textContent = 'Upload Document';

        var statusEl = document.createElement('p');
        statusEl.id = 'aicb-upload-status';

        container.appendChild(fileInput);
        container.appendChild(uploadButton);
        container.appendChild(statusEl);

        uploadButton.addEventListener('click', function () {
            if (!fileInput.files.length) {
                statusEl.textContent = 'Please choose a file first.';
                return;
            }

            var formData = new FormData();
            formData.append('action', 'aicb_upload_document');
            formData.append('nonce', nonce);
            formData.append('file', fileInput.files[0]);

            statusEl.textContent = 'Uploading...';
            uploadButton.disabled = true;

            fetch(ajaxurl, {
                method: 'POST',
                body: formData,
            })
                .then(function (res) { return res.json(); })
                .then(function (json) {
                    uploadButton.disabled = false;
                    if (json.success) {
                        statusEl.textContent = 'Uploaded "' + fileInput.files[0].name + '" (' + json.data.chunks + ' chunk(s) indexed).';
                        fileInput.value = '';
                    } else {
                        statusEl.textContent = 'Error: ' + (json.data && json.data.message ? json.data.message : 'Upload failed.');
                    }
                })
                .catch(function () {
                    uploadButton.disabled = false;
                    statusEl.textContent = 'Upload failed. Please try again.';
                });
        });
    });
})();
```

- [ ] **Step 2: Modify `includes/class-admin-settings.php`** — add the enqueue method and hook it in `register()`

In `register()`, add this line alongside the existing `add_action('admin_menu', ...)` wiring (the brief assumes `register()` already exists from Task 3 — only add the one line plus the new method below it):

```php
add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
```

Add this new method to the class:

```php
public static function enqueue_assets(string $hook_suffix): void
{
    if ('settings_page_aicb-settings' !== $hook_suffix) {
        return;
    }

    wp_enqueue_script(
        'aicb-admin',
        AICB_PLUGIN_URL . 'admin/assets/admin.js',
        [],
        AICB_VERSION,
        true
    );
}
```

- [ ] **Step 3: Confirm no PHP/JS syntax errors**

Run: `php -l ai-chatbox/includes/class-admin-settings.php` — expect `No syntax errors detected`.
If `node` is available, run `node --check ai-chatbox/admin/assets/admin.js` — expect no output. If `node` is unavailable, manually verify balanced braces/parens by reading the file back.

- [ ] **Step 4: Run the full PHPUnit suite to confirm no regression**

Run: `vendor/bin/phpunit --configuration phpunit.xml` from `ai-chatbox/` — expect `OK (22 tests, 69 assertions)`, unchanged (this task adds no new PHP unit-testable logic; it's WP-runtime admin glue, same category as Tasks 8 and 9).

- [ ] **Step 5: Manual re-verification (performed by the controller, not this task's implementer)**

After this task is implemented and reviewed, the controller re-loads the real local WordPress test site's Settings → AI Chatbox page in a browser, confirms a file input and "Upload Document" button now render inside the "Knowledge Base Documents" section, uploads a `.txt` file through the actual UI (not a direct curl call), and confirms the status message reports success and the chunk count.
