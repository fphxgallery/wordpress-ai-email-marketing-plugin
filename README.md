# AI Email Marketing

An OpenAI-powered email marketing plugin for WordPress. Build and send campaigns, manage subscribers, automate workflows, and track engagement — all from your WordPress admin.

## Features

- **Campaigns** — Create HTML email campaigns with AI-generated subject line, preview text, and body copy (OpenAI GPT-4o); schedule sends with correct WordPress timezone handling; "Scheduled For" column shows next run time with overdue indicator; Send Now button for instant dispatch; recurring campaigns auto-regenerate fresh content using the saved Email Editor template each cycle
- **AI Template Generation** — Select a template from the Email Editor before generating and the AI fills your brand layout instead of producing free-form HTML. Multiple WooCommerce products each get their own section, separated by dividers. System prompts editable in Settings with Reset to Default buttons
- **Visual Email Editor** — Block-based drag-and-drop email builder (heading, text, button, image, divider, spacer) with reusable templates; paste raw HTML directly via HTML tab
- **No Email Wrapper** — Campaign HTML is sent exactly as written. No header or container added. Use `{{unsubscribe_url}}`, `{{site_name}}`, `{{site_url}}` placeholders anywhere in your HTML — they are replaced at send time
- **Subscriber Management** — Mailing lists, CSV import/export, double opt-in, bounce tracking, bulk actions, engagement history
- **Audience Segmentation** — Named segments with filter conditions (status, engagement, date range) for targeted sends
- **Subscribe Forms** — Form builder with GDPR field and custom success messages; embed via shortcode
- **Automation Workflows** — Trigger-based email sequences (subscribe, unsubscribe, post published, campaign sent) with optional delay; zero-delay workflows fire immediately on the triggering request; manual Process Queue Now button
- **Reports** — Per-campaign stats: sent, opens, open rate, clicks, click rate, unsubscribes, failed; per-link click breakdown; resend to non-openers
- **WooCommerce Integration** — Pulls recent products into AI prompt context; per-campaign category/tag filtering

## Merge Tags & Placeholders

Available in campaign HTML and workflow email content:

| Tag | Replaced with |
|---|---|
| `{{first_name}}` | Subscriber first name |
| `{{last_name}}` | Subscriber last name |
| `{{full_name}}` | Subscriber full name |
| `{{email}}` | Subscriber email address |
| `{{site_name}}` | WordPress site name |
| `{{site_url}}` | Site URL |
| `{{unsubscribe_url}}` | One-click unsubscribe link |

## Requirements

- WordPress 6.0+
- PHP 8.1+
- MySQL 5.7+ or MariaDB (or SQLite via [WordPress SQLite Database Integration](https://wordpress.org/plugins/sqlite-database-integration/))
- OpenAI API key (for AI content generation)

## Installation

1. Upload the `ai-email-marketing` folder to `/wp-content/plugins/`
2. Activate the plugin in **Plugins → Installed Plugins**
3. Go to **Email Marketing → Settings** and enter your OpenAI API key
4. Create a mailing list under **Audience**, then add or import subscribers
5. Create and send your first campaign under **Campaigns**

> **SQLite users:** Tables are created via a setup script rather than the activation hook. Run `setup-aiem-*.py` scripts in the plugin root after activation to populate the schema cache.

## Shortcodes

```
[aiem_subscribe list_id="1"]
[aiem_subscribe form_id="1"]
```

## Admin Pages

| Page | Description |
|---|---|
| Dashboard | Stats overview, subscriber growth chart, recent activity |
| Campaigns | Create, edit, schedule, duplicate, delete campaigns |
| Audience | Mailing lists, subscriber drill-down, segments |
| Forms | Subscribe form builder |
| Workflows | Trigger-based automation rules; Process Queue Now button |
| Reports | Campaign performance stats, per-link click breakdown |
| Logs | Filterable event log |
| Email Editor | Visual block-based email template builder |
| Settings | OpenAI config, system prompts (editable + Reset to Default), sending defaults, double opt-in, bounce threshold, maintenance |
| Info | First-time setup guide |

## Changelog

- **1.3.5** — Sending throttle and retry. New "Max emails per hour" setting (rolling 60-minute window across campaigns); delay between batches now allows up to 3600 seconds; batches may run up to 50 seconds (kept under PHP's time limit). Temporary refusals from the mail server (4xx, rate limit, quota, connection or login errors), or three non-bounce failures in a row, put the email back in the queue and pause the campaign with a back-off of 15 minutes doubling to 4 hours, instead of marking the rest of the list failed. Settings shows a live pace estimate based on measured seconds per email, and any campaign that is waiting on the mail server.
- **1.3.4** — Fix: "+ New Form" (and other header buttons) ignored clicks because a WordPress core :active style moved the button while pressed; the plugin style now wins in every state. Creating a form now opens the new form's edit page instead of reloading a blank "New Form".
- **1.3.3** — OpenAI model update. Settings now offers GPT-6.1 Sol (new default), GPT-6.1 Terra, GPT-6 Luna and GPT-6 Astra; GPT-4o and GPT-4o mini remain, labeled as retiring January 14, 2027; the already-retired GPT-4 Turbo and GPT-3.5 Turbo are removed. Current models are called with `max_completion_tokens` and low reasoning effort, with room for reasoning added on top of Max Output Tokens. If a saved model has been retired, requests fall back to a replacement and log it; if the API rejects a parameter for a model, the request adapts and retries. Subject-only regeneration and Rewrite with AI now share the same request path and error handling.
- **1.3.2** — New dashboard: Last 7/30/90 day presets; KPI cards (emails sent, open rate, click rate, new subscribers, unsubscribes) with change vs the previous period; a daily multi-series chart with hover readout, legend toggles, keyboard support and a table view; subscribers by source and by list; an audience donut with growth figures. Fixes: list detail header showed "0 subscribers"; settings, list, form and segment saves no longer add backslashes before quotes (existing prompts are repaired once); recurring campaigns keep their planned time of day instead of drifting later; campaign stats are kept in a "Previous Sends" history when a campaign recurs or is re-sent; the unsubscribe stat counts only unsubscribes made from that campaign's link. Maintenance: logs and finished workflow queue items older than 90 days are removed daily (configurable, 0 keeps all); deactivating clears scheduled jobs; deleting the plugin removes data only if "Delete all data when the plugin is deleted" is ticked.
- **1.3.1** — Admin restyle: every plugin page now matches the email editor. Bar-style page headers with the main action on the right and back links on edit pages; white cards for panels, settings sections and two-column pages; framed tables with quiet headers and row hover; smaller badges; left-aligned stat cards with icons; sticky Save bar on Settings and sticky action bar on Campaign edit; filter toolbars on Logs and Reports. Removes unused CSS from the old block editor. No behavior changes.
- **1.3.0** — New email template editor. Three-pane layout: block library (adds Columns, Product, Social and Footer blocks), a live canvas that shows the email as it will send with type-in-place editing, and a panel for block properties, email-wide style (backgrounds, width, font, colors, Light/Dark presets) and pre-send checks. Drag-and-drop, move/duplicate/delete, undo/redo, bold/italic/link formatting, merge-tag chips, media library picker, per-block "Rewrite with AI", and Send test from the editor. Pre-send checks flag missing unsubscribe links, empty links, missing alt text, `http://` images, unknown merge tags and size near Gmail's 102 KB clip. Hand-written HTML templates get a code editor with live preview. Template list is now a gallery with thumbnails, usage counts, Duplicate and starter layouts. Block templates export a full table-based HTML document.
- **1.2.5** — Deliverability and reliability hardening. Fix `{{unsubscribe_url}}` links being wrapped in click tracking while still a placeholder (dead unsubscribe links since 1.2.2). Auto-append an unsubscribe footer when a campaign or workflow email has none; workflow emails get a signed per-subscriber unsubscribe link. Add `List-Unsubscribe` / one-click `List-Unsubscribe-Post` headers. Unsubscribe links now show a confirmation button (GET) and only unsubscribe on POST, so mail scanners can't unsubscribe people. Only permanent recipient rejections count as bounces — SMTP outages no longer suppress subscribers; successful sends reset the bounce count. Unsubscribed/bounced addresses can re-subscribe via an emailed confirmation. Atomic claims on campaigns, sends and workflow queue items prevent double sends; interrupted sends are recovered by the 15-minute cron instead of sticking in "sending". AI generation uses JSON mode and rejects truncated or malformed output; recurring AI campaigns skip an occurrence rather than send stale or broken content. Click-tracking links are HMAC-signed (closes open redirect) and handle `&amp;` and URL-safe base64 correctly. Fix plugin header version mismatch.
- **1.2.4** — Fix scheduled campaign display showing wrong time when WordPress timezone differs from PHP server timezone. Campaign list "Scheduled For" column and edit form date picker now correctly parse `scheduled_at` (stored in WP local time) using `wp_timezone()` instead of PHP's `strtotime()`. Add `cron_check` and `cron_batch` log events so cron activity is visible in the Logs tab; add Cron Check and Cron Batch entries to the log event filter dropdown.
- **1.2.3** — Campaign scheduling fixes: timezone bug in schedule_campaign (was using PHP server timezone instead of WordPress timezone); Send Now button on Campaigns list triggers immediate send; recurring AI campaigns now regenerate using the saved Email Editor template; template association persisted on campaign save; "Scheduled For" column on Campaigns list with overdue indicator.
- **1.2.2** — Remove email wrapper. Campaign HTML sent as-is — no header or container added. Tracking pixel, merge tags, link rewriting, and placeholder replacement applied directly to campaign HTML.
- **1.2.1** — Multi-product template layout: everything before the first `<hr>` is the product block; everything after the last `<hr>` is the footer. Additional products inserted between `<hr>` dividers.
- **1.2.0** — Fix template HTML silently stripped on save (`wp_kses_post()` was removing inline CSS and empty `<img src="">`). Replaced with `wp_unslash()` on all admin HTML save paths.
- **1.1.9** — Reset to Default buttons for System Prompt and Template System Prompt in Settings.
- **1.1.8** — Fix backslash in subject/preview (WordPress magic quotes — add `wp_unslash()` to all save paths). Fix AI stripping styled button anchor attributes.
- **1.1.7** — Template System Prompt editable in Settings → OpenAI. Pre-fills with current default.
- **1.1.6** — Prevent AI from stripping template styles. Now copies all tags and style attributes verbatim.
- **1.1.5** — Fix image blocks with no URL dropped from template HTML. Improve AI placeholder replacement prompt.
- **1.1.4** — Zero-delay workflows fire immediately on the triggering request. No more waiting for cron.
- **1.1.3** — Fix cron schedule registration order. Add Process Queue Now button to Workflows page.
- **1.1.2** — Fix workflow queue timezone mismatch (`date()` vs `current_time('mysql')`). Use `gmdate()` throughout.
- **1.1.1** — Fix activation error on MySQL < 8.0.13: TEXT/LONGTEXT columns cannot have DEFAULT values in CREATE TABLE.
- **1.1.0** — AI template-aware generation, configurable max output tokens, character counters, auto-save draft, regenerate subject+preview only, unsubscribe rate and per-link click breakdown on Reports.
- **1.0.2** — Visual Email Editor, audience segmentation, form builder, double opt-in, bounce tracking, resend to non-openers. SQLite single-quote reliability fix.
- **1.0.1** — Info page, AI generates subject+preview text, recurring campaign AI regeneration.
- **1.0.0** — Initial release.

## License

GPL-2.0-or-later
