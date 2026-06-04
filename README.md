# AI Email Marketing

An OpenAI-powered email marketing plugin for WordPress. Build and send campaigns, manage subscribers, automate workflows, and track engagement — all from your WordPress admin.

## Features

- **Campaigns** — Create HTML email campaigns with AI-generated subject line, preview text, and body copy (OpenAI GPT-4o); schedule sends; recurring campaigns auto-regenerate fresh content each cycle
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
