# Multi-Profile Chatbox & Document Management — Design Spec

Date: 2026-06-22

## Purpose

Extend the existing single-configuration `ai-chatbox` WordPress plugin so one
WordPress install can serve **multiple distinct chatbot personas**, each
scoped to a URL path prefix on the same site — e.g. the main site
`https://m23t.io.vn/` gets a default bot, while
`https://m23t.io.vn/members/harry/` and `https://m23t.io.vn/members/james/`
each get their own fully customized bot (own persona, own knowledge base,
own appearance). This also adds a proper document management UI (list +
delete, not just upload) so the site owner can see exactly which files feed
each bot's knowledge base.

This is **not** a return to the earlier-rejected multi-tenant SaaS idea:
everything still runs inside one self-contained plugin on one WordPress
install, configured by one admin. "Profiles" are a feature of that single
install, not separate accounts or separate servers.

## Scope

- Multiple "profiles," each with: a path prefix, assistant name, avatar,
  system prompt, welcome message, accent color, widget position, quick
  replies, and its own set of knowledge-base documents.
- Exactly one profile is flagged as the **Default** profile — it has no
  path prefix requirement and handles any request that doesn't match a
  more specific profile's prefix.
- The Anthropic API key and the upload caps (`max_upload_mb`,
  `max_documents`) remain **shared globally** across all profiles (per
  user decision — simpler key management, caps don't need to vary).
- Document manager UI: upload (existing), **list** (new — filename,
  upload date, chunk count), **delete** (new — cascades to that
  document's chunks). No active/inactive toggle (per user decision —
  deleting is the only way to stop using a document).
- Supports roughly 5-10 profiles; admin UI is a simple list, not a
  paginated/searchable one (per user decision on expected scale).

### Explicitly out of scope

- Per-profile Anthropic API keys (shared key only, per user decision).
- Per-profile upload caps (global caps only).
- Document active/inactive toggling without deletion.
- Any change to the public widget's JS/CSS — `widget.js`/`widget.css`
  need zero changes, since they already just render whatever config
  they're given; only *which* config gets localized changes.
- Anything beyond path-prefix matching (no per-page metaboxes, no
  separate plugin instances/sites).

## Data Model Changes

### New table: `wp_aicb_profiles`

| Column | Notes |
|---|---|
| `id` | PK |
| `path_prefix` | varchar; unique among non-default profiles; empty/ignored for the Default profile |
| `is_default` | tinyint(1); exactly one row has this set to 1, enforced at save time |
| `assistant_name` | varchar |
| `avatar_url` | varchar |
| `system_prompt` | text |
| `welcome_message` | varchar |
| `accent_color` | varchar(7) |
| `widget_position` | varchar(20) — `bottom-right` / `bottom-left` |
| `quick_replies` | longtext — serialized array of `{label, message}` |
| `created_at` | datetime |

### Modified tables

- `wp_aicb_documents` gains `profile_id bigint(20) unsigned NOT NULL` (FK
  to `wp_aicb_profiles.id`).
- `wp_aicb_chunks` is unchanged directly, but its `document_id` FK now
  transitively scopes every chunk to a profile via its document.

### `wp_options`

- `aicb_settings` is trimmed to only the fields that remain global:
  `anthropic_api_key`, `max_upload_mb`, `max_documents`. The
  per-appearance fields that used to live here (`assistant_name`,
  `avatar_url`, `system_prompt`, `welcome_message`, `accent_color`,
  `widget_position`, `quick_replies`) move to `wp_aicb_profiles` rows —
  the existing single configuration becomes the seed data for the
  Default profile on upgrade (handled in the DB installer/migration:
  if `wp_aicb_profiles` is empty on activation, create a Default row
  from the current `aicb_settings` values, then strip those fields out
  of the option).

## Request Routing (Frontend)

On `wp_enqueue_scripts`, the plugin reads the current request path,
normalizes it (strip trailing slash), and finds the profile whose
`path_prefix` is the **longest** prefix-match of the current path. If no
non-default profile matches, the Default profile is used. The matched
profile's fields are localized into `AICB_CONFIG` exactly as today —
`widget.js` requires no changes.

Overlapping prefixes (e.g. `/members/` and `/members/harry/` both
configured) are resolved deterministically by longest-match — not treated
as a conflict requiring a warning.

## Admin UI

The Settings → AI Chatbox page restructures into:

1. **Global section** (top): Anthropic API key, max upload size, max
   documents — shared across all profiles.
2. **Profile list**: each profile shown with its name and path prefix
   (or "Default" label), with **Select**, **Add Profile**, and **Delete**
   controls. The Default profile has no Delete control.
3. **Selected profile's form** (below the list): the existing
   per-appearance fields (name, avatar, prompt, welcome message, color,
   position, quick replies) for whichever profile is currently selected,
   plus that profile's **document manager**:
   - Upload control (existing, from Task 12) — now sends the selected
     `profile_id` alongside the file.
   - **Document list** (new): filename, upload date, chunk count, and a
     **Delete** button per row.

## Validation & Edge Cases

- Saving a non-default profile with an empty `path_prefix` → rejected
  with an inline error ("Path prefix is required for non-default
  profiles").
- Saving a non-default profile whose `path_prefix` duplicates another
  profile's → rejected with an inline error.
- Setting a profile's `is_default` flag automatically clears it from
  whichever profile previously held it (enforced server-side in the same
  request, not just in the UI), so exactly one Default always exists.
- Deleting a profile cascades: deletes its documents and their chunks
  (same pattern as plugin `uninstall.php`). The Default profile cannot be
  deleted (UI hides the control; backend also rejects the request
  defensively).
- Deleting a document removes its row and all chunk rows referencing it.
- A profile with zero documents behaves exactly like today's
  zero-search-match case: the chat request proceeds with just that
  profile's system prompt, no retrieved context.
- Quick replies, hex color, widget position validation rules are
  unchanged — they just run once per profile instead of once globally.

## Migration / Upgrade Path

On plugin activation (or a version-bump check), if `wp_aicb_profiles` has
no rows, the installer creates one Default profile seeded from the
current `aicb_settings` values (so existing installs upgrading from the
single-profile version keep their current bot working, now as the
Default profile), and any existing `wp_aicb_documents` rows are assigned
to that new Default profile's `id`.

## Example Configuration (your stated use case)

| Profile | Path prefix | Notes |
|---|---|---|
| Default | (none — fallback) | Main M23T site bot; quick reply "What does M23T work?" |
| Harry | `/members/harry/` | Persona about Hai Ngoc NGUYEN; quick reply "Tell me about Hai Ngoc NGUYEN (Harry)"; own uploaded documents |
| James | `/members/james/` | Persona about Chi Thanh LE; quick reply "Tell me about Chi Thanh LE (James)"; own uploaded documents |
