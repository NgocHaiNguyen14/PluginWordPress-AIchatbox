# Multi-Profile Chatbox Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend the ai-chatbox plugin so one WordPress install can serve multiple distinct chatbot "profiles," each scoped to a URL path prefix, each with its own persona, appearance, quick replies, and knowledge-base documents.

**Architecture:** A new `wp_aicb_profiles` table replaces the per-appearance fields that used to live in the single `aicb_settings` option. `wp_aicb_documents` and `wp_aicb_chunks` both gain a `profile_id` column (denormalized onto chunks too, to keep FULLTEXT search a simple `WHERE profile_id = %d` instead of a join). A pure path-matching resolver picks the right profile for a given URL prefix (longest match wins, default profile is the fallback). The public widget's JS is untouched — the chat AJAX handler resolves the profile server-side from the page's `Referer` header, since the widget already only knows the profile it was given at page-load time.

**Tech Stack:** Same as the existing plugin — PHP 7.4+, WordPress hooks/`$wpdb`, vanilla JS, PHPUnit 9 for pure-logic classes.

## Global Constraints

- Anthropic API key and upload caps (`max_upload_mb`, `max_documents`) remain global, shared across all profiles — not per-profile.
- Exactly one profile is always flagged `is_default`; it cannot be deleted and has no required `path_prefix`.
- Non-default profiles must have a unique, non-empty `path_prefix`.
- Path matching is "starts with," longest-prefix-wins; normalize by stripping trailing slashes before comparing.
- No active/inactive toggle for documents — delete is the only way to stop using one.
- `public/widget.js` and `public/widget.css` must NOT be modified by this plan — the public chat AJAX call already sends no profile information and none is added; the server resolves the profile from `$_SERVER['HTTP_REFERER']` instead.
- No git repository exists in this project — skip all `git add`/`git commit` steps; treat each "run tests" step as the checkpoint instead.
- This plan modifies and extends the existing plugin at `/home/sefas/Documents/Hai/PluginWordPress-AIchatbox/ai-chatbox/`. PHPUnit is already configured (`vendor/bin/phpunit --configuration phpunit.xml`, run from inside `ai-chatbox/`). Composer is available at `/home/sefas/Documents/Hai/PluginWordPress-AIchatbox/composer.phar`.

---

### Task 1: Profiles Table Schema + `profile_id` Columns

**Files:**
- Modify: `ai-chatbox/includes/class-db-installer.php`
- Modify: `ai-chatbox/tests/DbInstallerTest.php`

**Interfaces:**
- Produces: `AICB_DB_Installer::get_profiles_table_sql(string $table_name, string $charset_collate): string` — pure string builder.
- Produces: `get_documents_table_sql()` and `get_chunks_table_sql()` (existing, from the original plan) now also include a `profile_id bigint(20) unsigned NOT NULL` column with a `KEY profile_id (profile_id)` index, in addition to all their existing columns.

- [ ] **Step 1: Write the failing tests — add to `tests/DbInstallerTest.php`**

Add these test methods inside the existing `DbInstallerTest` class (do not remove the existing two tests):

```php
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run (from `ai-chatbox/`): `vendor/bin/phpunit --configuration phpunit.xml --filter DbInstallerTest`
Expected: 3 new FAILs (`get_profiles_table_sql` undefined; the other two fail the new `assertStringContainsString` checks since `profile_id` isn't in the SQL yet).

- [ ] **Step 3: Modify `includes/class-db-installer.php`**

Replace the entire file with:

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
            profile_id bigint(20) unsigned NOT NULL,
            filename varchar(255) NOT NULL,
            file_type varchar(10) NOT NULL,
            uploaded_at datetime NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'processed',
            PRIMARY KEY  (id),
            KEY profile_id (profile_id)
        ) {$charset_collate};";
    }

    public static function get_chunks_table_sql(string $table_name, string $documents_table, string $charset_collate): string
    {
        return "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            document_id bigint(20) unsigned NOT NULL,
            profile_id bigint(20) unsigned NOT NULL,
            chunk_text longtext NOT NULL,
            chunk_index int(11) NOT NULL,
            PRIMARY KEY  (id),
            KEY document_id (document_id),
            KEY profile_id (profile_id),
            FULLTEXT KEY chunk_text_idx (chunk_text)
        ) {$charset_collate};";
    }

    public static function get_profiles_table_sql(string $table_name, string $charset_collate): string
    {
        return "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            path_prefix varchar(255) NOT NULL DEFAULT '',
            is_default tinyint(1) NOT NULL DEFAULT 0,
            assistant_name varchar(255) NOT NULL,
            avatar_url varchar(255) NOT NULL DEFAULT '',
            system_prompt text NOT NULL,
            welcome_message varchar(255) NOT NULL,
            accent_color varchar(7) NOT NULL,
            widget_position varchar(20) NOT NULL,
            quick_replies longtext NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id)
        ) {$charset_collate};";
    }

    public static function install(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $documents_table = $wpdb->prefix . 'aicb_documents';
        $chunks_table = $wpdb->prefix . 'aicb_chunks';
        $profiles_table = $wpdb->prefix . 'aicb_profiles';

        dbDelta(self::get_profiles_table_sql($profiles_table, $charset_collate));
        dbDelta(self::get_documents_table_sql($documents_table, $charset_collate));
        dbDelta(self::get_chunks_table_sql($chunks_table, $documents_table, $charset_collate));

        AICB_Profile_Migrator::migrate();
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --configuration phpunit.xml --filter DbInstallerTest`
Expected: still fails at this point — `AICB_Profile_Migrator` doesn't exist yet (Task 5 creates it). This is expected; the `install()` method itself isn't unit tested (it's WP-only glue, same as before), only the three `get_*_table_sql()` pure builders are. Confirm specifically that the three new/modified test methods pass:

Run: `vendor/bin/phpunit --configuration phpunit.xml --filter "DbInstallerTest::test_profiles_table_sql_contains_expected_columns|DbInstallerTest::test_documents_table_sql_now_includes_profile_id|DbInstallerTest::test_chunks_table_sql_now_includes_profile_id"`
Expected: `OK (3 tests, ...)`

---

### Task 2: Profile Sanitization & Defaults (pure logic)

**Files:**
- Create: `ai-chatbox/includes/class-profiles.php`
- Create: `ai-chatbox/tests/ProfilesSanitizeTest.php`

**Interfaces:**
- Produces: `AICB_Profiles::get_defaults(): array` — returns a profile-shaped array with keys: `path_prefix`, `is_default`, `assistant_name`, `avatar_url`, `system_prompt`, `welcome_message`, `accent_color`, `widget_position`, `quick_replies`.
- Produces: `AICB_Profiles::sanitize(array $input): array` — same shape, validated/defaulted, reusing the exact validation rules from `AICB_Admin_Settings::sanitize()` (hex color, position allow-list, quick-reply label+message pairing) but WITHOUT the fields that stay global (`anthropic_api_key`, `max_upload_mb`, `max_documents`).
- Produces: `AICB_Profiles::path_prefix_conflicts(string $path_prefix, array $other_profiles): bool` — pure; `$other_profiles` is a list of profile arrays (each with a `path_prefix` key) to check against. Returns `true` if any other profile already has this exact `path_prefix` (after both sides are trimmed of trailing slashes).

- [ ] **Step 1: Write the failing test, `tests/ProfilesSanitizeTest.php`**

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --configuration phpunit.xml --filter ProfilesSanitizeTest`
Expected: FAIL — `Class "AICB_Profiles" not found`

- [ ] **Step 3: Write `includes/class-profiles.php`**

```php
<?php

class AICB_Profiles
{
    const ALLOWED_POSITIONS = ['bottom-right', 'bottom-left'];

    public static function get_defaults(): array
    {
        return [
            'path_prefix' => '',
            'is_default' => false,
            'assistant_name' => 'Assistant',
            'avatar_url' => '',
            'system_prompt' => 'You are a helpful customer support assistant.',
            'welcome_message' => 'Hi! How can I help you today?',
            'accent_color' => '#4f46e5',
            'widget_position' => 'bottom-right',
            'quick_replies' => [],
        ];
    }

    public static function sanitize(array $input): array
    {
        $defaults = self::get_defaults();

        $path_prefix = isset($input['path_prefix']) ? trim((string) $input['path_prefix']) : $defaults['path_prefix'];
        $path_prefix = rtrim($path_prefix, '/');

        $is_default = !empty($input['is_default']);

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
            'path_prefix' => $path_prefix,
            'is_default' => $is_default,
            'assistant_name' => $assistant_name,
            'avatar_url' => $avatar_url,
            'system_prompt' => $system_prompt,
            'welcome_message' => $welcome_message,
            'accent_color' => $accent_color,
            'widget_position' => $widget_position,
            'quick_replies' => $quick_replies,
        ];
    }

    public static function path_prefix_conflicts(string $path_prefix, array $other_profiles): bool
    {
        $normalized = rtrim($path_prefix, '/');

        foreach ($other_profiles as $profile) {
            $other = rtrim((string) ($profile['path_prefix'] ?? ''), '/');
            if ('' !== $other && $other === $normalized) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --configuration phpunit.xml --filter ProfilesSanitizeTest`
Expected: `OK (7 tests, ...)`

---

### Task 3: Profile Path Resolver

**Files:**
- Create: `ai-chatbox/includes/class-profile-resolver.php`
- Create: `ai-chatbox/tests/ProfileResolverTest.php`

**Interfaces:**
- Consumes: a list of profile arrays, each shaped like `AICB_Profiles::get_defaults()` plus an `id` key and a real `path_prefix`/`is_default`.
- Produces: `AICB_Profile_Resolver::resolve(string $request_path, array $profiles): array` — pure function. Normalizes `$request_path` (strip trailing `/`), finds the profile whose `path_prefix` is the longest "starts with" match of the normalized path among profiles where `path_prefix !== ''`, and returns that profile array. If no match, returns the profile where `is_default === true`. If `$profiles` is empty, returns `AICB_Profiles::get_defaults()`.

- [ ] **Step 1: Write the failing test, `tests/ProfileResolverTest.php`**

```php
<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-profiles.php';
require_once __DIR__ . '/../includes/class-profile-resolver.php';

final class ProfileResolverTest extends TestCase
{
    private function profiles(): array
    {
        return [
            ['id' => 1, 'path_prefix' => '', 'is_default' => true, 'assistant_name' => 'Default Bot'],
            ['id' => 2, 'path_prefix' => '/members', 'is_default' => false, 'assistant_name' => 'Members Bot'],
            ['id' => 3, 'path_prefix' => '/members/harry', 'is_default' => false, 'assistant_name' => 'Harry Bot'],
        ];
    }

    public function test_resolves_longest_matching_prefix(): void
    {
        $result = AICB_Profile_Resolver::resolve('/members/harry/notes', $this->profiles());

        $this->assertSame('Harry Bot', $result['assistant_name']);
    }

    public function test_resolves_shorter_prefix_when_longer_one_does_not_match(): void
    {
        $result = AICB_Profile_Resolver::resolve('/members/james', $this->profiles());

        $this->assertSame('Members Bot', $result['assistant_name']);
    }

    public function test_falls_back_to_default_when_no_prefix_matches(): void
    {
        $result = AICB_Profile_Resolver::resolve('/blog/hello-world', $this->profiles());

        $this->assertSame('Default Bot', $result['assistant_name']);
    }

    public function test_strips_trailing_slash_before_matching(): void
    {
        $result = AICB_Profile_Resolver::resolve('/members/harry/', $this->profiles());

        $this->assertSame('Harry Bot', $result['assistant_name']);
    }

    public function test_empty_profiles_list_returns_pure_defaults(): void
    {
        $result = AICB_Profile_Resolver::resolve('/anything', []);

        $this->assertSame(AICB_Profiles::get_defaults()['assistant_name'], $result['assistant_name']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --configuration phpunit.xml --filter ProfileResolverTest`
Expected: FAIL — `Class "AICB_Profile_Resolver" not found`

- [ ] **Step 3: Write `includes/class-profile-resolver.php`**

```php
<?php

class AICB_Profile_Resolver
{
    public static function resolve(string $request_path, array $profiles): array
    {
        if (empty($profiles)) {
            return AICB_Profiles::get_defaults();
        }

        $normalized_path = rtrim($request_path, '/');

        $best_match = null;
        $best_length = -1;
        $default_profile = null;

        foreach ($profiles as $profile) {
            if (!empty($profile['is_default'])) {
                $default_profile = $profile;
            }

            $prefix = rtrim((string) ($profile['path_prefix'] ?? ''), '/');
            if ('' === $prefix) {
                continue;
            }

            if (0 === strpos($normalized_path, $prefix) && strlen($prefix) > $best_length) {
                $best_match = $profile;
                $best_length = strlen($prefix);
            }
        }

        if (null !== $best_match) {
            return $best_match;
        }

        return $default_profile ?? AICB_Profiles::get_defaults();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --configuration phpunit.xml --filter ProfileResolverTest`
Expected: `OK (5 tests, ...)`

---

### Task 4: Scope FULLTEXT Search to a Profile

**Files:**
- Modify: `ai-chatbox/includes/class-knowledge-base.php`
- Modify: `ai-chatbox/tests/KnowledgeBaseSearchTest.php`

**Interfaces:**
- Produces: `AICB_Knowledge_Base::search_chunks(int $profile_id, string $query_text, int $limit = 5): array` — **signature change** from the existing `search_chunks(string $query_text, int $limit = 5)`. `$profile_id` is now the first parameter. Adds `AND profile_id = %d` to the WHERE clause, denormalized directly on the chunks table (no join). `chunk_text()` (static) is unchanged.
- Consumes: this changes a call site in `class-ajax-handler.php` — Task 8 below updates that call.

- [ ] **Step 1: Update the failing test, `tests/KnowledgeBaseSearchTest.php`** — replace the entire file:

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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --configuration phpunit.xml --filter KnowledgeBaseSearchTest`
Expected: FAIL — current `search_chunks(string $query_text, int $limit = 5)` doesn't accept an int as the first arg in the way the new test expects, and the SQL has no `profile_id = %d`.

- [ ] **Step 3: Modify `includes/class-knowledge-base.php`** — replace the `search_chunks` method (keep `__construct` and `chunk_text` unchanged):

```php
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --configuration phpunit.xml --filter KnowledgeBaseSearchTest`
Expected: `OK (2 tests, ...)`

- [ ] **Step 5: Run the full suite so far** (expect failures in `class-ajax-handler.php`'s old call site are NOT yet run since no test covers it directly — full suite should still report all other tests green)

Run: `vendor/bin/phpunit --configuration phpunit.xml`
Expected: All test files pass. (`class-ajax-handler.php`'s outdated call to the old `search_chunks` signature is fixed in Task 8 — it has no unit tests of its own, so the suite won't catch the mismatch; Task 8's brief explicitly calls this out.)

---

### Task 5: Profile Migration (seed Default profile from old settings)

**Files:**
- Create: `ai-chatbox/includes/class-profile-migrator.php`

**Interfaces:**
- Consumes: `AICB_Admin_Settings::get_settings()` (existing — still returns the old shape including the appearance fields, until Task 6 trims it), `AICB_Profiles::sanitize()` (Task 2).
- Produces: `AICB_Profile_Migrator::migrate(): void` — WordPress-runtime glue (uses `$wpdb`, `get_option`, `update_option`), not unit tested (same category as `AICB_DB_Installer::install()`). Called from `AICB_DB_Installer::install()` (Task 1, already wired in).

This is pure WP glue with no extractable pure logic beyond what Tasks 2-4 already cover, so it has no PHPUnit test of its own — it's verified in Task 13's manual end-to-end pass, same pattern as the original plugin's Tasks 8/9.

- [ ] **Step 1: Write `includes/class-profile-migrator.php`**

```php
<?php

class AICB_Profile_Migrator
{
    public static function migrate(): void
    {
        global $wpdb;

        $profiles_table = $wpdb->prefix . 'aicb_profiles';
        $documents_table = $wpdb->prefix . 'aicb_documents';
        $chunks_table = $wpdb->prefix . 'aicb_chunks';

        $existing_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$profiles_table}");
        if ($existing_count > 0) {
            return;
        }

        $old_settings = AICB_Admin_Settings::get_settings();

        $default_profile = AICB_Profiles::sanitize([
            'path_prefix' => '',
            'is_default' => true,
            'assistant_name' => $old_settings['assistant_name'] ?? 'Assistant',
            'avatar_url' => $old_settings['avatar_url'] ?? '',
            'system_prompt' => $old_settings['system_prompt'] ?? '',
            'welcome_message' => $old_settings['welcome_message'] ?? '',
            'accent_color' => $old_settings['accent_color'] ?? '',
            'widget_position' => $old_settings['widget_position'] ?? 'bottom-right',
            'quick_replies' => $old_settings['quick_replies'] ?? [],
        ]);

        $wpdb->insert($profiles_table, [
            'path_prefix' => $default_profile['path_prefix'],
            'is_default' => 1,
            'assistant_name' => $default_profile['assistant_name'],
            'avatar_url' => $default_profile['avatar_url'],
            'system_prompt' => $default_profile['system_prompt'],
            'welcome_message' => $default_profile['welcome_message'],
            'accent_color' => $default_profile['accent_color'],
            'widget_position' => $default_profile['widget_position'],
            'quick_replies' => maybe_serialize($default_profile['quick_replies']),
            'created_at' => current_time('mysql'),
        ]);
        $default_profile_id = $wpdb->insert_id;

        $wpdb->query($wpdb->prepare(
            "UPDATE {$documents_table} SET profile_id = %d WHERE profile_id = 0 OR profile_id IS NULL",
            $default_profile_id
        ));
        $wpdb->query($wpdb->prepare(
            "UPDATE {$chunks_table} SET profile_id = %d WHERE profile_id = 0 OR profile_id IS NULL",
            $default_profile_id
        ));

        $trimmed_settings = [
            'anthropic_api_key' => $old_settings['anthropic_api_key'] ?? '',
            'max_upload_mb' => $old_settings['max_upload_mb'] ?? 5,
            'max_documents' => $old_settings['max_documents'] ?? 20,
        ];
        update_option(AICB_Admin_Settings::OPTION_NAME, $trimmed_settings);
    }
}
```

- [ ] **Step 2: Confirm no PHP syntax errors**

Run: `php -l includes/class-profile-migrator.php`
Expected: `No syntax errors detected`

---

### Task 6: Trim Global Settings to API Key + Upload Caps

**Files:**
- Modify: `ai-chatbox/includes/class-admin-settings.php`
- Modify: `ai-chatbox/tests/SettingsSanitizeTest.php`

**Interfaces:**
- Produces: `AICB_Admin_Settings::get_defaults(): array` now returns ONLY `['anthropic_api_key' => '', 'max_upload_mb' => 5, 'max_documents' => 20]`.
- Produces: `AICB_Admin_Settings::sanitize(array $input): array` now only validates those three fields (drops all the appearance-field validation, which moved to `AICB_Profiles::sanitize()` in Task 2).
- `get_settings()`, `register()`, `render_page()`, `enqueue_assets()` keep their existing signatures — `render_page()` still passes `$settings` to the template, but Task 10 changes what that template renders.

- [ ] **Step 1: Replace the failing test, `tests/SettingsSanitizeTest.php`** — replace the entire file:

```php
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
        $this->assertSame(20, $result['max_documents']);
    }

    public function test_sanitize_keeps_valid_caps(): void
    {
        $result = AICB_Admin_Settings::sanitize(['max_upload_mb' => '8', 'max_documents' => '15']);

        $this->assertSame(8, $result['max_upload_mb']);
        $this->assertSame(15, $result['max_documents']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --configuration phpunit.xml --filter SettingsSanitizeTest`
Expected: FAIL — current `get_defaults()` returns 10 keys, not 3.

- [ ] **Step 3: Modify `includes/class-admin-settings.php`** — replace `get_defaults()` and `sanitize()` (keep `OPTION_NAME`, `get_settings()`, `register()`, `render_page()`, `enqueue_assets()` unchanged; delete `ALLOWED_POSITIONS`, it's unused here now):

```php
    public static function get_defaults(): array
    {
        return [
            'anthropic_api_key' => '',
            'max_upload_mb' => 5,
            'max_documents' => 20,
        ];
    }

    public static function sanitize(array $input): array
    {
        $defaults = self::get_defaults();

        $api_key = isset($input['anthropic_api_key']) ? trim((string) $input['anthropic_api_key']) : $defaults['anthropic_api_key'];

        $max_upload_mb = isset($input['max_upload_mb']) ? absint($input['max_upload_mb']) : $defaults['max_upload_mb'];
        if ($max_upload_mb < 1) {
            $max_upload_mb = $defaults['max_upload_mb'];
        }

        $max_documents = isset($input['max_documents']) ? absint($input['max_documents']) : $defaults['max_documents'];
        if ($max_documents < 1) {
            $max_documents = $defaults['max_documents'];
        }

        return [
            'anthropic_api_key' => $api_key,
            'max_upload_mb' => $max_upload_mb,
            'max_documents' => $max_documents,
        ];
    }
```

Also delete the `const ALLOWED_POSITIONS = ['bottom-right', 'bottom-left'];` line near the top of the class (it's no longer used in this file — `AICB_Profiles` has its own copy).

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --configuration phpunit.xml --filter SettingsSanitizeTest`
Expected: `OK (4 tests, ...)`

- [ ] **Step 5: Run the full suite**

Run: `vendor/bin/phpunit --configuration phpunit.xml`
Expected: All tests across all files pass (Sanity, DbInstaller, SettingsSanitize, Chunker, KnowledgeBaseSearch, DocumentParser, ClaudeClient, ProfilesSanitize, ProfileResolver).

---

### Task 7: Profile CRUD AJAX Handlers

**Files:**
- Modify: `ai-chatbox/includes/class-ajax-handler.php`

**Interfaces:**
- Consumes: `AICB_Profiles::sanitize()`, `AICB_Profiles::path_prefix_conflicts()` (Task 2).
- Produces: four new admin-only AJAX actions registered in `register()`: `aicb_list_profiles`, `aicb_save_profile`, `aicb_delete_profile`, `aicb_set_default_profile`. Each follows the existing admin-action pattern (`current_user_can('manage_options')` + `check_ajax_referer('aicb_admin_nonce', 'nonce')`).
- This task has no PHPUnit tests of its own — it's WP-runtime glue (`$_POST`, `$wpdb`, `wp_send_json_*`), same category as the existing `handle_upload_document()`/`handle_send_message()`. Verified manually in Task 13.

- [ ] **Step 1: Add to `register()` in `includes/class-ajax-handler.php`** — add these four lines inside the existing method, alongside the existing three `add_action` calls:

```php
        add_action('wp_ajax_aicb_list_profiles', [self::class, 'handle_list_profiles']);
        add_action('wp_ajax_aicb_save_profile', [self::class, 'handle_save_profile']);
        add_action('wp_ajax_aicb_delete_profile', [self::class, 'handle_delete_profile']);
        add_action('wp_ajax_aicb_set_default_profile', [self::class, 'handle_set_default_profile']);
```

- [ ] **Step 2: Add these four methods to the `AICB_Ajax_Handler` class** (anywhere after `register()`):

```php
    public static function handle_list_profiles(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Not allowed.'], 403);
        }
        check_ajax_referer('aicb_admin_nonce', 'nonce');

        global $wpdb;
        $profiles_table = $wpdb->prefix . 'aicb_profiles';
        $rows = $wpdb->get_results("SELECT * FROM {$profiles_table} ORDER BY is_default DESC, id ASC", ARRAY_A);

        foreach ($rows as &$row) {
            $row['quick_replies'] = maybe_unserialize($row['quick_replies']);
            $row['is_default'] = (bool) $row['is_default'];
        }

        wp_send_json_success(['profiles' => $rows]);
    }

    public static function handle_save_profile(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Not allowed.'], 403);
        }
        check_ajax_referer('aicb_admin_nonce', 'nonce');

        global $wpdb;
        $profiles_table = $wpdb->prefix . 'aicb_profiles';

        $profile_id = isset($_POST['profile_id']) ? absint($_POST['profile_id']) : 0;
        $raw = isset($_POST['profile']) && is_array($_POST['profile']) ? wp_unslash($_POST['profile']) : [];
        $data = AICB_Profiles::sanitize($raw);

        if (!$data['is_default'] && '' === $data['path_prefix']) {
            wp_send_json_error(['message' => 'Path prefix is required for non-default profiles.']);
        }

        $others = $wpdb->get_results(
            $profile_id > 0
                ? $wpdb->prepare("SELECT id, path_prefix FROM {$profiles_table} WHERE id != %d", $profile_id)
                : "SELECT id, path_prefix FROM {$profiles_table}",
            ARRAY_A
        );

        if (!$data['is_default'] && AICB_Profiles::path_prefix_conflicts($data['path_prefix'], $others)) {
            wp_send_json_error(['message' => 'Another profile already uses this path prefix.']);
        }

        $row = [
            'path_prefix' => $data['path_prefix'],
            'is_default' => $data['is_default'] ? 1 : 0,
            'assistant_name' => $data['assistant_name'],
            'avatar_url' => $data['avatar_url'],
            'system_prompt' => $data['system_prompt'],
            'welcome_message' => $data['welcome_message'],
            'accent_color' => $data['accent_color'],
            'widget_position' => $data['widget_position'],
            'quick_replies' => maybe_serialize($data['quick_replies']),
        ];

        if ($data['is_default']) {
            $wpdb->query($profile_id > 0
                ? $wpdb->prepare("UPDATE {$profiles_table} SET is_default = 0 WHERE id != %d", $profile_id)
                : "UPDATE {$profiles_table} SET is_default = 0"
            );
        }

        if ($profile_id > 0) {
            $wpdb->update($profiles_table, $row, ['id' => $profile_id]);
        } else {
            $row['created_at'] = current_time('mysql');
            $wpdb->insert($profiles_table, $row);
            $profile_id = $wpdb->insert_id;
        }

        wp_send_json_success(['profile_id' => $profile_id]);
    }

    public static function handle_delete_profile(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Not allowed.'], 403);
        }
        check_ajax_referer('aicb_admin_nonce', 'nonce');

        global $wpdb;
        $profiles_table = $wpdb->prefix . 'aicb_profiles';
        $documents_table = $wpdb->prefix . 'aicb_documents';
        $chunks_table = $wpdb->prefix . 'aicb_chunks';

        $profile_id = isset($_POST['profile_id']) ? absint($_POST['profile_id']) : 0;
        $profile = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$profiles_table} WHERE id = %d", $profile_id), ARRAY_A);

        if (!$profile) {
            wp_send_json_error(['message' => 'Profile not found.']);
        }

        if ((bool) $profile['is_default']) {
            wp_send_json_error(['message' => 'The default profile cannot be deleted.']);
        }

        $doc_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$documents_table} WHERE profile_id = %d", $profile_id));
        if (!empty($doc_ids)) {
            $placeholders = implode(',', array_fill(0, count($doc_ids), '%d'));
            $wpdb->query($wpdb->prepare("DELETE FROM {$chunks_table} WHERE document_id IN ({$placeholders})", $doc_ids));
            $wpdb->query($wpdb->prepare("DELETE FROM {$documents_table} WHERE id IN ({$placeholders})", $doc_ids));
        }

        $wpdb->delete($profiles_table, ['id' => $profile_id]);

        wp_send_json_success();
    }

    public static function handle_set_default_profile(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Not allowed.'], 403);
        }
        check_ajax_referer('aicb_admin_nonce', 'nonce');

        global $wpdb;
        $profiles_table = $wpdb->prefix . 'aicb_profiles';
        $profile_id = isset($_POST['profile_id']) ? absint($_POST['profile_id']) : 0;

        $wpdb->query("UPDATE {$profiles_table} SET is_default = 0");
        $wpdb->update($profiles_table, ['is_default' => 1], ['id' => $profile_id]);

        wp_send_json_success();
    }
```

- [ ] **Step 3: Confirm no PHP syntax errors**

Run: `php -l includes/class-ajax-handler.php`
Expected: `No syntax errors detected`

---

### Task 8: Document List/Delete + Profile-Scoped Upload + Referer-Based Chat Resolution

**Files:**
- Modify: `ai-chatbox/includes/class-ajax-handler.php`

**Interfaces:**
- Consumes: `AICB_Knowledge_Base::search_chunks(int $profile_id, string $query_text, int $limit)` (Task 4's new signature), `AICB_Profile_Resolver::resolve(string $request_path, array $profiles)` (Task 3).
- Produces: two new admin AJAX actions `aicb_list_documents`, `aicb_delete_document`. Modifies `handle_upload_document()` to require a `profile_id` POST field. Modifies `handle_send_message()` to resolve the profile from `$_SERVER['HTTP_REFERER']` instead of reading global appearance settings (which no longer exist after Task 6).

**Important — this is the one place the plan deviates from "the public widget JS never changes":** the widget's chat request carries no profile information, by design (see Global Constraints). `handle_send_message()` must therefore determine which profile's persona/documents to use from the page that issued the request. It does this via the `Referer` HTTP header, which same-origin `fetch()` calls send by default. If the header is missing or unparseable, it falls back to the default profile — graceful degradation, not an error.

- [ ] **Step 1: Add to `register()`** — add these two lines alongside the others added in Task 7:

```php
        add_action('wp_ajax_aicb_list_documents', [self::class, 'handle_list_documents']);
        add_action('wp_ajax_aicb_delete_document', [self::class, 'handle_delete_document']);
```

- [ ] **Step 2: Replace `handle_upload_document()` entirely** with:

```php
    public static function handle_upload_document(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Not allowed.'], 403);
        }

        check_ajax_referer('aicb_admin_nonce', 'nonce');

        $profile_id = isset($_POST['profile_id']) ? absint($_POST['profile_id']) : 0;
        if ($profile_id < 1) {
            wp_send_json_error(['message' => 'No profile selected.']);
        }

        if (empty($_FILES['file'])) {
            wp_send_json_error(['message' => 'No file uploaded.']);
        }

        global $wpdb;
        $settings = AICB_Admin_Settings::get_settings();
        $documents_table = $wpdb->prefix . 'aicb_documents';
        $chunks_table = $wpdb->prefix . 'aicb_chunks';

        $existing_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$documents_table} WHERE profile_id = %d",
            $profile_id
        ));
        if ($existing_count >= $settings['max_documents']) {
            wp_send_json_error(['message' => 'Document limit reached for this profile.']);
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
            'profile_id' => $profile_id,
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
                'profile_id' => $profile_id,
                'chunk_text' => $chunk,
                'chunk_index' => $index,
            ]);
        }

        wp_send_json_success(['document_id' => $document_id, 'chunks' => count($chunks)]);
    }
```

- [ ] **Step 3: Add `handle_list_documents()` and `handle_delete_document()`** anywhere after `handle_upload_document()`:

```php
    public static function handle_list_documents(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Not allowed.'], 403);
        }
        check_ajax_referer('aicb_admin_nonce', 'nonce');

        $profile_id = isset($_POST['profile_id']) ? absint($_POST['profile_id']) : 0;

        global $wpdb;
        $documents_table = $wpdb->prefix . 'aicb_documents';
        $chunks_table = $wpdb->prefix . 'aicb_chunks';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT d.id, d.filename, d.file_type, d.uploaded_at,
                    (SELECT COUNT(*) FROM {$chunks_table} c WHERE c.document_id = d.id) AS chunk_count
             FROM {$documents_table} d
             WHERE d.profile_id = %d
             ORDER BY d.uploaded_at DESC",
            $profile_id
        ), ARRAY_A);

        wp_send_json_success(['documents' => $rows]);
    }

    public static function handle_delete_document(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Not allowed.'], 403);
        }
        check_ajax_referer('aicb_admin_nonce', 'nonce');

        $document_id = isset($_POST['document_id']) ? absint($_POST['document_id']) : 0;
        if ($document_id < 1) {
            wp_send_json_error(['message' => 'No document specified.']);
        }

        global $wpdb;
        $documents_table = $wpdb->prefix . 'aicb_documents';
        $chunks_table = $wpdb->prefix . 'aicb_chunks';

        $wpdb->delete($chunks_table, ['document_id' => $document_id]);
        $wpdb->delete($documents_table, ['id' => $document_id]);

        wp_send_json_success();
    }
```

- [ ] **Step 4: Replace `handle_send_message()` entirely** with:

```php
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

        global $wpdb;
        $profiles_table = $wpdb->prefix . 'aicb_profiles';
        $profiles = $wpdb->get_results("SELECT * FROM {$profiles_table}", ARRAY_A);
        foreach ($profiles as &$p) {
            $p['quick_replies'] = maybe_unserialize($p['quick_replies']);
            $p['is_default'] = (bool) $p['is_default'];
        }

        $referer = isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '';
        $path = (string) (wp_parse_url($referer, PHP_URL_PATH) ?? '/');
        $profile = AICB_Profile_Resolver::resolve($path, $profiles);

        $settings = AICB_Admin_Settings::get_settings();

        if ('' === $settings['anthropic_api_key']) {
            error_log('AICB: no Anthropic API key configured.');
            wp_send_json_error(['message' => 'Chat is temporarily unavailable.']);
        }

        $chunks_table = $wpdb->prefix . 'aicb_chunks';
        $kb = new AICB_Knowledge_Base($wpdb, $chunks_table);
        $context_chunks = isset($profile['id'])
            ? $kb->search_chunks((int) $profile['id'], $user_message, 5)
            : [];

        $client = new AICB_Claude_Client($settings['anthropic_api_key']);
        $result = $client->send_message($profile['system_prompt'], $context_chunks, $history, $user_message);

        if (!$result['ok']) {
            error_log('AICB Claude API error: ' . $result['error']);
            wp_send_json_error(['message' => 'Chat is temporarily unavailable.']);
        }

        wp_send_json_success(['reply' => $result['text']]);
    }
```

- [ ] **Step 5: Confirm no PHP syntax errors and run the full suite**

Run: `php -l includes/class-ajax-handler.php`
Expected: `No syntax errors detected`

Run: `vendor/bin/phpunit --configuration phpunit.xml`
Expected: All tests still pass (this file has no PHPUnit tests of its own; this confirms no other file broke).

---

### Task 9: Wire New Classes into `ai-chatbox.php`

**Files:**
- Modify: `ai-chatbox/ai-chatbox.php`

**Interfaces:**
- Consumes: `AICB_Profiles`, `AICB_Profile_Resolver`, `AICB_Profile_Migrator` (Tasks 2, 3, 5).
- Produces: the `wp_enqueue_scripts` callback now resolves the current page's profile via `AICB_Profile_Resolver::resolve()` using `$_SERVER['REQUEST_URI']`, and localizes `AICB_CONFIG` from that profile instead of the old global settings.

- [ ] **Step 1: Add three new `require_once` lines** after the existing six, in `ai-chatbox.php`:

```php
require_once AICB_PLUGIN_DIR . 'includes/class-profiles.php';
require_once AICB_PLUGIN_DIR . 'includes/class-profile-resolver.php';
require_once AICB_PLUGIN_DIR . 'includes/class-profile-migrator.php';
```

- [ ] **Step 2: Replace the `wp_enqueue_scripts` callback entirely** with:

```php
add_action('wp_enqueue_scripts', function () {
    global $wpdb;
    $profiles_table = $wpdb->prefix . 'aicb_profiles';
    $profiles = $wpdb->get_results("SELECT * FROM {$profiles_table}", ARRAY_A);
    foreach ($profiles as &$p) {
        $p['quick_replies'] = maybe_unserialize($p['quick_replies']);
        $p['is_default'] = (bool) $p['is_default'];
    }

    $current_path = (string) wp_parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $profile = AICB_Profile_Resolver::resolve($current_path, $profiles);

    wp_enqueue_style('aicb-widget', AICB_PLUGIN_URL . 'public/widget.css', [], AICB_VERSION);
    wp_enqueue_script('aicb-widget', AICB_PLUGIN_URL . 'public/widget.js', [], AICB_VERSION, true);

    wp_localize_script('aicb-widget', 'AICB_CONFIG', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('aicb_public_nonce'),
        'assistantName' => $profile['assistant_name'],
        'avatarUrl' => $profile['avatar_url'],
        'welcomeMessage' => $profile['welcome_message'],
        'accentColor' => $profile['accent_color'],
        'position' => $profile['widget_position'],
        'quickReplies' => $profile['quick_replies'],
    ]);
});
```

- [ ] **Step 3: Confirm no PHP syntax errors and run the full suite**

Run: `php -l ai-chatbox.php`
Expected: `No syntax errors detected`

Run: `vendor/bin/phpunit --configuration phpunit.xml`
Expected: All tests still pass.

---

### Task 10: Restructure the Settings Page Template

**Files:**
- Modify: `ai-chatbox/admin/settings-page.php`

**Interfaces:**
- Consumes: `$settings` (still passed in by `AICB_Admin_Settings::render_page()`, now just the 3 global fields).
- Produces: a `#aicb-profiles-manager` mount element with a `data-nonce` attribute, which Task 11's rewritten `admin.js` populates entirely via JS (profile list, profile form, document manager) — same pattern Task 12 of the original plan already established for documents.

- [ ] **Step 1: Replace the entire file** with:

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
        <h2>Global Settings</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="aicb_api_key">Anthropic API Key</label></th>
                <td><input type="password" id="aicb_api_key" name="aicb_settings[anthropic_api_key]" value="<?php echo esc_attr($settings['anthropic_api_key']); ?>" class="regular-text" autocomplete="off" /></td>
            </tr>
            <tr>
                <th><label for="aicb_max_upload_mb">Max Upload Size (MB)</label></th>
                <td><input type="number" min="1" id="aicb_max_upload_mb" name="aicb_settings[max_upload_mb]" value="<?php echo esc_attr($settings['max_upload_mb']); ?>" class="small-text" /></td>
            </tr>
            <tr>
                <th><label for="aicb_max_documents">Max Documents (per profile)</label></th>
                <td><input type="number" min="1" id="aicb_max_documents" name="aicb_settings[max_documents]" value="<?php echo esc_attr($settings['max_documents']); ?>" class="small-text" /></td>
            </tr>
        </table>
        <?php submit_button('Save Global Settings'); ?>
    </form>

    <h2>Chatbot Profiles</h2>
    <p class="description">Each profile is a separate chatbot persona shown on pages under its path prefix. Exactly one profile is the Default — it answers everywhere no other profile's prefix matches.</p>
    <div id="aicb-profiles-manager" data-nonce="<?php echo esc_attr(wp_create_nonce('aicb_admin_nonce')); ?>"></div>
</div>
```

- [ ] **Step 2: Confirm no PHP syntax errors**

Run: `php -l admin/settings-page.php`
Expected: `No syntax errors detected`

---

### Task 11: Profile Management + Document Manager Admin JS

**Files:**
- Modify: `ai-chatbox/admin/assets/admin.js`

**Interfaces:**
- Consumes: `#aicb-profiles-manager` div + its `data-nonce` (Task 10); AJAX actions `aicb_list_profiles`, `aicb_save_profile`, `aicb_delete_profile`, `aicb_set_default_profile`, `aicb_list_documents`, `aicb_delete_document`, `aicb_upload_document` (Tasks 7-8); WordPress's global `ajaxurl`.
- Produces: no exported interface — this is the page's only script, replacing the old document-only admin.js entirely (the old `#aicb-documents-manager` div no longer exists after Task 10, so this file's old code referencing it would be dead anyway).

This task has no PHPUnit coverage — pure frontend admin JS, same category as Task 10/11 of the original plan. Verified manually in Task 13.

- [ ] **Step 1: Replace the entire file** with:

```javascript
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.getElementById('aicb-profiles-manager');
        if (!root) {
            return;
        }

        var nonce = root.dataset.nonce;
        var selectedProfileId = null;
        var allProfiles = [];

        var listEl = document.createElement('div');
        var formEl = document.createElement('div');
        var docsEl = document.createElement('div');
        root.appendChild(listEl);
        root.appendChild(formEl);
        root.appendChild(docsEl);

        function post(action, fields) {
            var body = new URLSearchParams();
            body.append('action', action);
            body.append('nonce', nonce);
            Object.keys(fields || {}).forEach(function (key) {
                body.append(key, fields[key]);
            });
            return fetch(ajaxurl, { method: 'POST', body: body }).then(function (r) { return r.json(); });
        }

        function loadProfiles() {
            return post('aicb_list_profiles').then(function (json) {
                allProfiles = (json.success && json.data.profiles) || [];
                renderList();
            });
        }

        function renderList() {
            listEl.innerHTML = '';
            var heading = document.createElement('h3');
            heading.textContent = 'Profiles';
            listEl.appendChild(heading);

            allProfiles.forEach(function (profile) {
                var row = document.createElement('p');

                var label = document.createElement('strong');
                label.textContent = profile.is_default ? (profile.assistant_name + ' (Default)') : (profile.assistant_name + ' — ' + profile.path_prefix);
                row.appendChild(label);
                row.appendChild(document.createTextNode(' '));

                var selectBtn = document.createElement('button');
                selectBtn.type = 'button';
                selectBtn.className = 'button';
                selectBtn.textContent = 'Edit';
                selectBtn.addEventListener('click', function () { selectProfile(profile.id); });
                row.appendChild(selectBtn);
                row.appendChild(document.createTextNode(' '));

                if (!profile.is_default) {
                    var defaultBtn = document.createElement('button');
                    defaultBtn.type = 'button';
                    defaultBtn.className = 'button';
                    defaultBtn.textContent = 'Make Default';
                    defaultBtn.addEventListener('click', function () {
                        post('aicb_set_default_profile', { profile_id: profile.id }).then(loadProfiles);
                    });
                    row.appendChild(defaultBtn);
                    row.appendChild(document.createTextNode(' '));

                    var deleteBtn = document.createElement('button');
                    deleteBtn.type = 'button';
                    deleteBtn.className = 'button';
                    deleteBtn.textContent = 'Delete';
                    deleteBtn.addEventListener('click', function () {
                        post('aicb_delete_profile', { profile_id: profile.id }).then(function () {
                            if (selectedProfileId === profile.id) {
                                selectedProfileId = null;
                                formEl.innerHTML = '';
                                docsEl.innerHTML = '';
                            }
                            loadProfiles();
                        });
                    });
                    row.appendChild(deleteBtn);
                }

                listEl.appendChild(row);
            });

            var addBtn = document.createElement('button');
            addBtn.type = 'button';
            addBtn.className = 'button button-primary';
            addBtn.textContent = 'Add Profile';
            addBtn.addEventListener('click', function () { selectProfile(0); });
            listEl.appendChild(addBtn);
        }

        function blankProfile() {
            return {
                id: 0,
                path_prefix: '',
                is_default: false,
                assistant_name: 'Assistant',
                avatar_url: '',
                system_prompt: 'You are a helpful customer support assistant.',
                welcome_message: 'Hi! How can I help you today?',
                accent_color: '#4f46e5',
                widget_position: 'bottom-right',
                quick_replies: [],
            };
        }

        function selectProfile(id) {
            selectedProfileId = id;
            var profile = id === 0 ? blankProfile() : allProfiles.filter(function (p) { return p.id === id; })[0];
            renderForm(profile);
            if (id === 0) {
                docsEl.innerHTML = '<p>Save this profile first to manage its documents.</p>';
            } else {
                renderDocumentManager(id);
            }
        }

        function renderForm(profile) {
            formEl.innerHTML = '';
            var heading = document.createElement('h3');
            heading.textContent = profile.id ? 'Edit Profile' : 'New Profile';
            formEl.appendChild(heading);

            var fields = {};

            function textField(labelText, key, value) {
                var p = document.createElement('p');
                var label = document.createElement('label');
                label.textContent = labelText + ' ';
                var input = document.createElement('input');
                input.type = 'text';
                input.value = value || '';
                fields[key] = input;
                label.appendChild(input);
                p.appendChild(label);
                formEl.appendChild(p);
            }

            textField('Path Prefix (e.g. /members/harry)', 'path_prefix', profile.path_prefix);
            textField('Assistant Name', 'assistant_name', profile.assistant_name);
            textField('Avatar URL', 'avatar_url', profile.avatar_url);
            textField('Welcome Message', 'welcome_message', profile.welcome_message);
            textField('Accent Color', 'accent_color', profile.accent_color);

            var promptP = document.createElement('p');
            var promptLabel = document.createElement('label');
            promptLabel.textContent = 'System Prompt ';
            var promptArea = document.createElement('textarea');
            promptArea.rows = 5;
            promptArea.value = profile.system_prompt || '';
            fields.system_prompt = promptArea;
            promptLabel.appendChild(promptArea);
            promptP.appendChild(promptLabel);
            formEl.appendChild(promptP);

            var posP = document.createElement('p');
            var posLabel = document.createElement('label');
            posLabel.textContent = 'Widget Position ';
            var posSelect = document.createElement('select');
            ['bottom-right', 'bottom-left'].forEach(function (val) {
                var opt = document.createElement('option');
                opt.value = val;
                opt.textContent = val;
                if (val === profile.widget_position) opt.selected = true;
                posSelect.appendChild(opt);
            });
            fields.widget_position = posSelect;
            posLabel.appendChild(posSelect);
            posP.appendChild(posLabel);
            formEl.appendChild(posP);

            var defaultP = document.createElement('p');
            var defaultLabel = document.createElement('label');
            var defaultCheckbox = document.createElement('input');
            defaultCheckbox.type = 'checkbox';
            defaultCheckbox.checked = !!profile.is_default;
            fields.is_default = defaultCheckbox;
            defaultLabel.appendChild(defaultCheckbox);
            defaultLabel.appendChild(document.createTextNode(' Default profile'));
            defaultP.appendChild(defaultLabel);
            formEl.appendChild(defaultP);

            var qrHeading = document.createElement('p');
            qrHeading.innerHTML = '<strong>Quick Replies</strong>';
            formEl.appendChild(qrHeading);
            var qrList = document.createElement('div');
            formEl.appendChild(qrList);
            var quickReplyRows = [];

            function addQuickReplyRow(label, message) {
                var row = document.createElement('p');
                var labelInput = document.createElement('input');
                labelInput.type = 'text';
                labelInput.placeholder = 'Button label';
                labelInput.value = label || '';
                var messageInput = document.createElement('input');
                messageInput.type = 'text';
                messageInput.placeholder = 'Message to send';
                messageInput.value = message || '';
                row.appendChild(labelInput);
                row.appendChild(messageInput);
                qrList.appendChild(row);
                quickReplyRows.push({ label: labelInput, message: messageInput });
            }

            (profile.quick_replies || []).forEach(function (qr) { addQuickReplyRow(qr.label, qr.message); });

            var addQrBtn = document.createElement('button');
            addQrBtn.type = 'button';
            addQrBtn.className = 'button';
            addQrBtn.textContent = 'Add Quick Reply';
            addQrBtn.addEventListener('click', function () { addQuickReplyRow('', ''); });
            formEl.appendChild(addQrBtn);

            var saveBtn = document.createElement('button');
            saveBtn.type = 'button';
            saveBtn.className = 'button button-primary';
            saveBtn.textContent = 'Save Profile';
            saveBtn.style.display = 'block';
            saveBtn.addEventListener('click', function () {
                var payload = {
                    'profile_id': profile.id,
                    'profile[path_prefix]': fields.path_prefix.value,
                    'profile[assistant_name]': fields.assistant_name.value,
                    'profile[avatar_url]': fields.avatar_url.value,
                    'profile[system_prompt]': fields.system_prompt.value,
                    'profile[welcome_message]': fields.welcome_message.value,
                    'profile[accent_color]': fields.accent_color.value,
                    'profile[widget_position]': fields.widget_position.value,
                    'profile[is_default]': fields.is_default.checked ? '1' : '0',
                };

                var body = new URLSearchParams();
                body.append('action', 'aicb_save_profile');
                body.append('nonce', nonce);
                Object.keys(payload).forEach(function (key) { body.append(key, payload[key]); });

                quickReplyRows.forEach(function (row, i) {
                    if (row.label.value && row.message.value) {
                        body.append('profile[quick_replies][' + i + '][label]', row.label.value);
                        body.append('profile[quick_replies][' + i + '][message]', row.message.value);
                    }
                });

                fetch(ajaxurl, { method: 'POST', body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (json) {
                        if (json.success) {
                            loadProfiles().then(function () {
                                selectProfile(json.data.profile_id);
                            });
                        } else {
                            alert((json.data && json.data.message) || 'Could not save profile.');
                        }
                    });
            });
            formEl.appendChild(saveBtn);
        }

        function renderDocumentManager(profileId) {
            docsEl.innerHTML = '';
            var heading = document.createElement('h4');
            heading.textContent = 'Knowledge Base Documents';
            docsEl.appendChild(heading);

            var listContainer = document.createElement('div');
            docsEl.appendChild(listContainer);

            function refreshDocs() {
                post('aicb_list_documents', { profile_id: profileId }).then(function (json) {
                    var docs = (json.success && json.data.documents) || [];
                    listContainer.innerHTML = '';
                    if (!docs.length) {
                        listContainer.innerHTML = '<p>No documents uploaded for this profile yet.</p>';
                        return;
                    }
                    docs.forEach(function (doc) {
                        var row = document.createElement('p');
                        row.textContent = doc.filename + ' (' + doc.chunk_count + ' chunk(s), uploaded ' + doc.uploaded_at + ') ';
                        var delBtn = document.createElement('button');
                        delBtn.type = 'button';
                        delBtn.className = 'button';
                        delBtn.textContent = 'Delete';
                        delBtn.addEventListener('click', function () {
                            post('aicb_delete_document', { document_id: doc.id }).then(refreshDocs);
                        });
                        row.appendChild(delBtn);
                        listContainer.appendChild(row);
                    });
                });
            }

            var fileInput = document.createElement('input');
            fileInput.type = 'file';
            fileInput.accept = '.txt,.pdf';

            var uploadButton = document.createElement('button');
            uploadButton.type = 'button';
            uploadButton.className = 'button';
            uploadButton.textContent = 'Upload Document';

            var statusEl = document.createElement('p');

            docsEl.appendChild(fileInput);
            docsEl.appendChild(uploadButton);
            docsEl.appendChild(statusEl);

            uploadButton.addEventListener('click', function () {
                if (!fileInput.files.length) {
                    statusEl.textContent = 'Please choose a file first.';
                    return;
                }

                var formData = new FormData();
                formData.append('action', 'aicb_upload_document');
                formData.append('nonce', nonce);
                formData.append('profile_id', profileId);
                formData.append('file', fileInput.files[0]);

                statusEl.textContent = 'Uploading...';
                uploadButton.disabled = true;

                fetch(ajaxurl, { method: 'POST', body: formData })
                    .then(function (res) { return res.json(); })
                    .then(function (json) {
                        uploadButton.disabled = false;
                        if (json.success) {
                            statusEl.textContent = 'Uploaded "' + fileInput.files[0].name + '" (' + json.data.chunks + ' chunk(s) indexed).';
                            fileInput.value = '';
                            refreshDocs();
                        } else {
                            statusEl.textContent = 'Error: ' + (json.data && json.data.message ? json.data.message : 'Upload failed.');
                        }
                    })
                    .catch(function () {
                        uploadButton.disabled = false;
                        statusEl.textContent = 'Upload failed. Please try again.';
                    });
            });

            refreshDocs();
        }

        loadProfiles();
    });
})();
```

- [ ] **Step 2: Verify no JS syntax errors**

If `node` is available: `node --check admin/assets/admin.js` — expect no output. If unavailable (it was not available during the original plugin's build), manually read the file back and confirm balanced braces/parens/brackets.

---

### Task 12: Update Uninstall Cleanup

**Files:**
- Modify: `ai-chatbox/uninstall.php`

**Interfaces:**
- Produces: uninstall now also drops `wp_aicb_profiles`.

- [ ] **Step 1: Replace the entire file** with:

```php
<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

delete_option('aicb_settings');

$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}aicb_chunks");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}aicb_documents");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}aicb_profiles");
```

- [ ] **Step 2: Confirm no PHP syntax errors and run the full suite one final time**

Run: `php -l uninstall.php`
Expected: `No syntax errors detected`

Run: `vendor/bin/phpunit --configuration phpunit.xml`
Expected: All tests pass across every test file (Sanity, DbInstaller, SettingsSanitize, Chunker, KnowledgeBaseSearch, DocumentParser, ClaudeClient, ProfilesSanitize, ProfileResolver).

---

### Task 13: Packaging and Manual End-to-End Verification

**Files:** none (build + manual verification only).

This follows the same pattern as the original plugin's Task 11: a fresh local WordPress + MariaDB install, a real browser session via gstack `/browse`, and (if the user provides one again) a real Claude API call. Perform these checks by hand against a real install — this cannot be automated without a live WordPress+MySQL environment:

1. Build a fresh local WordPress test site (new database, new install) and install the plugin zip (rebuild it from the modified `ai-chatbox/` source, excluding `tests/`/`phpunit.xml`/cache files, same process as before).
2. Activate the plugin. Confirm via direct MySQL: `wp_aicb_profiles` table exists, and contains exactly one row with `is_default = 1` seeded from whatever the (empty, fresh-install) global settings were — confirms the migration path runs cleanly even with no prior data.
3. In Settings → AI Chatbox: set the global API key. Add a profile with `path_prefix = /members/harry`, name "Harry Bot", a system prompt about Hai Ngoc NGUYEN, and a quick reply "Tell me about Hai Ngoc NGUYEN (Harry)". Save — confirm it appears in the profile list.
4. Add a second profile with `path_prefix = /members/james` similarly, persona about Chi Thanh LE.
5. Try saving a profile with a `path_prefix` that duplicates an existing one — confirm the inline error appears and nothing is saved.
6. Upload a `.txt` document to Harry's profile via its document manager. Confirm it appears in that profile's document list with the right chunk count, and does NOT appear in James's profile's document list.
7. Delete that document via the list's Delete button — confirm it disappears from the list and its rows are gone from `wp_aicb_documents`/`wp_aicb_chunks` via direct MySQL check.
8. Create a WordPress page at a URL whose path is `/members/harry/` (or use `Permalinks` settings to make this work, or simply verify the resolver logic this way: visit any URL on the test site and check, via browser dev tools or by reading the page source, that `AICB_CONFIG.assistantName` matches the profile whose `path_prefix` is the longest match for that URL — e.g. the homepage `/` should localize the Default profile's name, not Harry's or James's).
9. With a real Anthropic API key (ask the user for one again, same handling as before — never logged, cleared after the test): send a chat message from a page resolving to Harry's profile, asking the Harry quick-reply question. Confirm the reply reflects Harry's system prompt/documents, not James's or the Default's.
10. Send the same kind of message from a page resolving to the Default profile (e.g. the homepage) — confirm it does NOT see Harry's or James's documents (search a term that only exists in Harry's uploaded doc; the Default profile's reply should not reference it).
11. Set James's profile as Default via "Make Default," confirm Harry's profile correctly loses its own default flag (only one `is_default = 1` row in the table after this).
12. Try deleting the current Default profile — confirm it's rejected (no Delete button shown in the UI for the default row; if tested directly via the AJAX endpoint, confirm the server-side rejection too).
13. Deactivate and delete the plugin — confirm via MySQL that `wp_aicb_profiles`, `wp_aicb_documents`, `wp_aicb_chunks` are all dropped and `aicb_settings` option is removed.
14. Clean up: stop the test WordPress server, drop the test database, delete any temp files containing the test API key (same hygiene discipline as the original plugin build — do not leave secrets on disk).

Record the actual outcome of each check in a log file (same pattern as `docs/superpowers/plans/2026-06-22-ai-chatbox-execution-log.md`), including anything that failed and how it was fixed, before considering this plan complete.
