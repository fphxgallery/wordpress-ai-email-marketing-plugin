# AI Email Marketing

An OpenAI-powered email marketing plugin for WordPress. Build and send campaigns, manage subscribers, automate workflows, and track engagement — all from your WordPress admin.

## Features

- **Campaigns** — Create HTML email campaigns with AI-generated subject line, preview text, and body copy (OpenAI GPT-4o); schedule sends; recurring campaigns auto-regenerate fresh content each cycle. Select a template from the Email Editor before generating to have the AI fill your brand layout instead of producing free-form HTML — additional WooCommerce products automatically get their own duplicated product section.
- **Visual Email Editor** — Block-based drag-and-drop email builder (heading, text, button, image, divider, spacer) with reusable templates
- **Subscriber Management** — Mailing lists, CSV import/export, double opt-in, bounce tracking, bulk actions, engagement history
- **Audience Segmentation** — Named segments with filter conditions (status, engagement, date range) for targeted sends
- **Subscribe Forms** — Form builder with GDPR field and custom success messages; embed via shortcode
- **Automation Workflows** — Trigger-based email sequences (subscribe, unsubscribe, post published, campaign sent) with optional delay
- **Reports** — Per-campaign stats: sent, opens, open rate, clicks, click rate, unsubscribes, failed; per-link click breakdown; resend to non-openers
- **WooCommerce Integration** — Pulls recent products into AI prompt context for product-focused campaigns

## Changelog

- **1.1.0** — AI generation can now follow an Email Editor template: select a template from the "Load from Email Editor" dropdown before clicking Generate and the AI fills your brand layout instead of producing free-form HTML. Multiple WooCommerce products each get their own duplicated product section. Max output tokens is now configurable in Settings (default 2500; template mode uses at least 4000). Character counters on subject/preview text, auto-save draft, regenerate subject+preview only, unsubscribe rate column and per-link click breakdown on Reports.
- **1.0.2** — Major feature release: Visual Email Editor with reusable block templates, audience segmentation, subscribe form builder (GDPR + custom success messages), double opt-in, bounce tracking, expanded automation workflows, and resend-to-non-openers. **Reliability fix:** campaigns, email templates, forms, subscribers, lists, and segments now save correctly on SQLite when content contains single quotes/apostrophes (AI-generated email copy, names like O'Brien) by routing inserts/updates through raw PDO binding, matching the existing workflow path.
- **1.0.1** — Add Info page (first-time setup guide) under Settings. AI generation now produces subject line and preview text in addition to email body. Recurring campaigns auto-regenerate subject, preview text, and body from the saved prompt at each send cycle. Plugin author updated to @fPHXGallery.
- **1.0.0** — Initial release.

## Requirements

- WordPress 6.0+
- PHP 8.1+
- SQLite (via [WordPress SQLite Database Integration](https://wordpress.org/plugins/sqlite-database-integration/)) or MySQL/MariaDB
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
| Workflows | Trigger-based automation rules |
| Reports | Campaign performance stats |
| Logs | Filterable event log |
| Email Editor | Visual block-based email template builder |
| Settings | OpenAI config (model, max output tokens, system prompt), sending defaults, double opt-in, bounce threshold, maintenance |
| Info | First-time setup guide |

## License

GPL-2.0-or-later
