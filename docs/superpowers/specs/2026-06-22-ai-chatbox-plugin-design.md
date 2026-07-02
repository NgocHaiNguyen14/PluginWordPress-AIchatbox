# AI Chatbox WordPress Plugin — Design Spec

Date: 2026-06-22

## Purpose

A self-contained WordPress plugin that adds an AI-powered customer support
chat widget to any WordPress site. Each site owner installs the plugin on
their own WordPress install and configures it independently — their own
Anthropic Claude API key, assistant persona, quick-reply questions, and
uploaded business documents. There is no central server, no multi-tenant
backend, and no other paid API besides Anthropic's Claude.

## Scope (MVP)

- Single floating chat widget per page.
- Conversation resets on page reload (no persistent history, no login).
- Works on any WordPress theme.
- Only paid dependency: the Anthropic Claude API (`claude-sonnet-4-6`),
  using the site owner's own API key.
- Document-based knowledge base uses **no paid/external API** — retrieval
  is done with MySQL `FULLTEXT` keyword search, which is free and requires
  no extra infrastructure.

### Explicitly out of scope for v1

- Semantic/embedding-based search (noted as a possible future upgrade if
  keyword search proves insufficient — would require running a model on
  the server, adding real infrastructure).
- Multi-tenant / central SaaS dashboard.
- Persistent chat history or visitor identification.
- File types beyond `.txt` and `.pdf`.

## Architecture

Pure WordPress plugin: PHP backend, vanilla JS frontend widget, no
framework. All chat interactions go through `wp_ajax` / `wp_ajax_nopriv`
endpoints — no page reloads. Settings live in `wp_options`; uploaded
documents and their searchable chunks live in two custom tables created
on plugin activation.

### Plugin file structure

```
ai-chatbox/
├── ai-chatbox.php              # main plugin bootstrap, hooks
├── includes/
│   ├── class-admin-settings.php   # Settings page (Settings API)
│   ├── class-ajax-handler.php     # wp_ajax / wp_ajax_nopriv endpoints
│   ├── class-claude-client.php    # wp_remote_post() wrapper for Anthropic API
│   ├── class-knowledge-base.php   # document storage, chunking, search
│   ├── class-document-parser.php  # .txt / .pdf text extraction
│   └── class-db-installer.php     # custom table creation on activation
├── admin/
│   ├── settings-page.php          # renders settings UI
│   └── assets/ (admin.css/js)
├── public/
│   ├── widget.js                  # vanilla JS widget (launcher + popup)
│   └── widget.css                 # scoped styles (prefixed .aicb-*)
└── vendor/
    └── pdfparser (smalot/pdfparser, bundled, no external API call)
```

Once built, the folder is packaged as `ai-chatbox.zip` and installed via
**Plugins → Add New → Upload Plugin** in any WordPress admin (or copied
into `wp-content/plugins/` via FTP). Activation runs the table-creation
step automatically.

## Data Model

### `wp_options` (single-value settings, via WordPress Settings API)

| Option key | Purpose |
|---|---|
| `aicb_anthropic_api_key` | Site owner's Claude API key |
| `aicb_assistant_name` | Display name in widget header |
| `aicb_avatar_url` | Assistant avatar icon |
| `aicb_system_prompt` | Defines persona, tone, knowledge scope |
| `aicb_welcome_message` | First message shown on widget open |
| `aicb_accent_color` | Hex color for widget theming |
| `aicb_widget_position` | `bottom-right` or `bottom-left` |
| `aicb_quick_replies` | Serialized array of `{label, message}` |
| `aicb_max_upload_mb` | Configurable per-file upload size cap |
| `aicb_max_documents` | Configurable cap on number of uploaded documents |

### `wp_aicb_documents`

| Column | Notes |
|---|---|
| `id` | PK |
| `filename` | Original filename |
| `file_type` | `txt` or `pdf` |
| `uploaded_at` | Timestamp |
| `status` | `processed` / `failed` |

### `wp_aicb_chunks`

| Column | Notes |
|---|---|
| `id` | PK |
| `document_id` | FK to `wp_aicb_documents` |
| `chunk_text` | Chunk content; has a `FULLTEXT` index for keyword search |
| `chunk_index` | Order within source document |

## Document Upload & Knowledge Base Flow

1. Admin uploads a `.txt` or `.pdf` file from the Settings page → AJAX
   POST to an admin-only handler (capability-checked, nonce-protected).
2. `class-document-parser.php` extracts plain text. PDFs are parsed with
   the bundled `smalot/pdfparser` library — no external API call.
3. `class-knowledge-base.php` splits extracted text into fixed-size
   chunks (~700 characters, ~100-character overlap) and inserts rows
   into `wp_aicb_chunks`.
4. Upload is rejected with an inline admin error if it exceeds
   `aicb_max_upload_mb`, exceeds `aicb_max_documents`, is an unsupported
   file type, or fails to parse (e.g. corrupt PDF).

## Chat Request Flow

1. Visitor sends a message → JS `fetch()` POST to
   `wp_ajax_nopriv_aicb_send_message`.
2. Handler runs a MySQL `FULLTEXT MATCH...AGAINST` query over
   `wp_aicb_chunks` using the visitor's message text, retrieving the
   top-N matching chunks (no embeddings, no external call — pure SQL).
3. Handler assembles the Claude API request body:
   `system_prompt` + retrieved chunks (as injected context) +
   in-memory conversation history (resent each call, not stored
   server-side) + the new user message.
4. `class-claude-client.php` sends the request via `wp_remote_post()` to
   Anthropic's Messages API (`claude-Haiku`) using the site owner's
   stored API key.
5. The response text is returned as JSON and appended to the widget's
   message thread.
6. If the `FULLTEXT` query returns zero matching chunks, the request
   still proceeds with just the system prompt and message — Claude
   answers from persona instructions alone.

## Widget UI

- **Launcher**: fixed-position circular button (position per setting),
  shows the assistant avatar. An unread badge increments on new bot
  replies while the popup is closed, and clears on open.
- **Popup**: ~360×500px card. Header: avatar + assistant name + close
  button. Body: scrollable message thread, user messages right-aligned,
  bot messages left-aligned.
- **Quick replies**: pill-shaped buttons (from `aicb_quick_replies`)
  shown above the input on first open. Clicking one sends its preset
  message exactly as if typed.
- **Input bar**: text field + send button; Enter key also sends. Input
  is disabled with a "typing…" indicator while awaiting a response.
- **State**: held entirely in widget JS memory as an array of
  `{role, content}`; lost on page reload (matches MVP scope — no
  persistence, no cookies, no visitor login).

## Error Handling

- **Missing/invalid Claude API key**: AJAX handler returns a generic
  "chat temporarily unavailable" message to the widget; the real error
  is logged server-side via `error_log()` for the site admin to debug.
- **Claude API errors** (4xx/5xx, timeout, rate limit): checked via
  `is_wp_error()` and HTTP status on the `wp_remote_post()` response;
  widget shows a generic fallback message and re-enables input so the
  visitor can retry.
- **Document upload errors**: oversized file, unsupported type, or
  parse failure shown inline in the admin Settings page with a specific
  reason.
- **Zero search matches**: not treated as an error — request proceeds
  without extra context (see Chat Request Flow, step 6).

## Security Notes

- The Claude API key is never sent to the browser; all Claude calls
  happen server-side via `wp_remote_post()`.
- Admin AJAX actions (settings save, document upload) are nonce-protected
  and capability-checked (`manage_options`).
- Public AJAX action (`aicb_send_message`) is registered as both
  `wp_ajax_aicb_send_message` and `wp_ajax_nopriv_aicb_send_message` since
  visitors are not logged in; input is sanitized before use in the
  `FULLTEXT` query and before inclusion in the Claude request.
