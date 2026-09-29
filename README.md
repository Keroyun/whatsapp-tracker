# WhatsApp Tracker

A reusable WordPress plugin for managing WhatsApp contact links, aggregate click analytics, central routes, link inventory, safe migrations, and configurable WhatsApp/telephone service notices.

**Version:** 1.0.0 · **Author:** [Khairul Azhar](https://khairulazhar.com) · **Plugin page:** [My Plugins & Tools](https://khairulazhar.com/my-plugins-and-tools/) · **License:** GPL-2.0-or-later

This plugin manages website contact links only. It does not connect to a WhatsApp account, read chats, monitor conversations, or access private messages.

## Highlights

- Aggregate WhatsApp click analytics, page reporting, source attribution and CSV export.
- Approved-number governance plus on-demand Quick/Deep inventory scans.
- Optional Daily/Weekly **Recent Changes** scan; rendered-page Deep Scan always remains manual.
- Central WhatsApp routes with primary/backup lines and language-specific messages.
- Dynamic route languages from Polylang, with site-locale fallback when Polylang is absent.
- Safe bulk migration to central routes with preview, backup and conflict-aware rollback.
- Configurable WhatsApp/telephone availability notices with independent channel switches.
- Provider-neutral **Live Chat** action; Tawk.to is available as an optional integration.
- Optional URL/telephone/form action in notices.
- Dedicated `manage_whatsapp_tracker` capability so access can be delegated without full Administrator rights.
- Portable JSON configuration export/import for reuse across client websites.
- Dashboard health summary for unknown links, unresolved shortlinks, backup routes and scan state.
- GitHub release update support via the plugin's Update URI.
- Audit history, configurable retention and opt-in uninstall cleanup.

## Installation

Download or clone this repository into `wp-content/plugins/whatsapp-tracker`, then activate **WhatsApp Tracker**.

For production releases, install the `whatsapp-tracker.zip` asset attached to a GitHub release when available.

## First-time setup

1. Open **WhatsApp Tracker → Settings**.
2. Review tracking and retention.
3. Add the site's approved WhatsApp numbers.
4. Choose a live-chat provider only if the website already loads that provider.
5. Optionally grant plugin access to selected WordPress roles.
6. Run **Link Inventory → Quick Scan** to create the initial inventory checkpoint.
7. Optionally enable Daily or Weekly **Recent Changes** scans.
8. Configure Central Routes or service notices as required.

## Reusing the setup on another client site

Use **WhatsApp Tracker → Tools → Export Configuration**.

The export contains portable configuration such as approved numbers, routes, notice rules, retention, access roles and scan settings. It intentionally excludes:

- click analytics
- inventory scan results
- audit logs
- migration history/backups
- visitor data

On the destination website, use **Tools → Import Configuration**. Imported values are passed through the plugin's normal sanitizers before they are stored.

## Central routes and languages

A central route gives the website a stable URL whose destination number can be changed later without editing every CTA.

Example:

```text
[whatsapp-route route="support" title="Contact support"]
```

When Polylang is installed, route message fields are generated from the site's configured languages. Without Polylang, the WordPress site locale is used as the default language. Older `message_en`, `message_zh` and `message_id` route data is read for backward compatibility and is migrated into the generic message structure when saved.

Country codes are never guessed.

## Contact availability notices

Notices can target WhatsApp, telephone, or both. Rules can match by page path, destination, exact link and language.

The **Rule Name** is administrator-only. Frontend analytics receive a separate public-safe **Analytics Label** instead.

The Live Chat action is provider-neutral. In 1.0.0, Tawk.to can be selected under Settings; the plugin does not inject the Tawk widget itself.

Short emergency/service telephone numbers are never intercepted. Add `data-awm-emergency-exempt="1"` to any link that must always bypass notice handling.

## Scanning

- **Quick Scan:** database/content scan; lightweight and manual.
- **Deep Scan:** rendered-page HTTP scan; deliberately manual.
- **Recent Changes:** only content modified since the inventory checkpoint.
- **Current Page:** rendered scan for one selected page.

Scheduled Recent Changes scanning is off by default. A scheduled run stops and requests manual review when more than 100 changed content items are pending, avoiding an unexpectedly heavy cron task on shared hosting.

## Permissions

The plugin uses the custom capability:

```text
manage_whatsapp_tracker
```

Administrators always retain it. Additional roles can be selected in Settings.

## GitHub updates

The plugin header uses:

```text
Update URI: https://github.com/Keroyun/whatsapp-tracker
```

The updater checks the latest public GitHub release. It prefers a release asset named exactly:

```text
whatsapp-tracker.zip
```

If that asset is unavailable, the GitHub release source archive is used and normalized to the `whatsapp-tracker` plugin directory during installation.

## Security and privacy

- State-changing admin actions use capability and nonce checks.
- Public tracking applies origin, content-type, request-size and rate-limit controls.
- Raw visitor IP/user-agent values are not stored in analytics tables.
- Analytics are aggregated rather than stored as one database row per click.
- Shortlink resolution is administrator-triggered and bounded to supported WhatsApp URLs.
- Import files are size-limited and validated/sanitized before options are updated.
- Deep scanning remains manual.
- Uninstall preserves plugin data unless deletion is explicitly enabled.
- Public notice configuration contains only information required by the browser; internal rule names are not exposed.

Inventory links may contain prefilled message text and migration backups may contain original page/meta values. Treat database backups and exports as potentially sensitive.

See [SECURITY.md](SECURITY.md) for more detail.

## Development checks

```sh
node tests/frontend.test.cjs
bash tests/smoke.sh
```

The smoke suite runs PHP syntax checks, JavaScript syntax checks and frontend regression tests. WordPress PHPUnit tests require the WordPress test library and a disposable test database.

## Project links

- Repository: https://github.com/Keroyun/whatsapp-tracker
- Author: https://khairulazhar.com
- Plugin page: https://khairulazhar.com/my-plugins-and-tools/

## License and trademark notice

Licensed under **GPL-2.0-or-later**.

WhatsApp Tracker is an independent project and is not affiliated with, endorsed by, or sponsored by WhatsApp or Meta. WhatsApp and Meta names and marks belong to their respective owners.
