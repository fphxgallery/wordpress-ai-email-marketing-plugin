# AI Email Marketing

An OpenAI-powered email marketing plugin for WordPress. Build and send campaigns, manage subscribers, automate workflows, and track engagement — all from your WordPress admin.

## Features

- **Campaigns** — Create HTML email campaigns with AI-generated copy (OpenAI GPT-4o), preheader/preview text, schedule sends, and track opens/clicks
- **Visual Email Editor** — Block-based drag-and-drop email builder (heading, text, button, image, divider, spacer) with reusable templates
- **Subscriber Management** — Mailing lists, CSV import/export, double opt-in, bounce tracking, bulk actions, engagement history
- **Audience Segmentation** — Named segments with filter conditions (status, engagement, date range) for targeted sends
- **Subscribe Forms** — Drag-and-drop form builder with GDPR field and custom success messages; embed via shortcode
- **Automation Workflows** — Trigger-based email sequences (subscribe, unsubscribe, post published, campaign sent) with optional delay
- **Reports** — Per-campaign stats: sent, opens, open rate, clicks, click rate, failed; resend to non-openers
- **WooCommerce Integration** — Pulls recent products into AI prompt context for product-focused campaigns

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
| Settings | OpenAI config, sending defaults, double opt-in, bounce threshold, maintenance |

## License

GPL-2.0-or-later
