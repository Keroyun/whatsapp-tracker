# WhatsApp Tracker

A WordPress plugin for managing WhatsApp contact links, measuring link engagement, and displaying configurable service notices for WhatsApp and telephone links.

**Version:** 3.4.1 · **Author:** [Azhar](https://github.com/Keroyun) · **Website:** [khairulazhar.com](https://khairulazhar.com) · **License:** GPL-2.0-or-later

This is a website contact-link tool. It does not connect to a WhatsApp account, read chats, monitor conversations, or access private messages.

## Features

- Aggregate WhatsApp click analytics, page-level reporting, source attribution and CSV exports.
- Approved-number labels and an on-demand link inventory scanner.
- Administrator-triggered `wa.link` resolution with bounded requests.
- Central WhatsApp routes with primary/backup numbers and same-site fallback URLs.
- Bulk link migration with previews, backups and conflict-aware rollback.
- Targeted WhatsApp/telephone service notices, independently enabled per channel.
- Optional third popup action: another link, a telephone number or a shortcode form.
- Polylang page-language matching for notices, with a Default fallback.
- Administrative audit history, configurable retention and opt-in uninstall cleanup.

## Technical requirements

- A working WordPress installation with its REST API accessible and administrator access for configuration.
- A PHP/WordPress combination supported by your host. The release was exercised with **WordPress 7.1 and PHP 8.3.32** in WordPress Playground; other combinations are not certified by those tests.
- A WordPress-compatible database. Production SQL uses MySQL/MariaDB conventions; the local Playground checks used its SQLite compatibility layer.
- JavaScript enabled in the visitor's browser; working rewrite rules/permalinks for central routes.
- Outbound HTTPS for optional shortlink resolution; loopback access for deep scanning. PHP DOM support improves HTML scanning.
- Optional: Polylang, an installed shortcode-form provider, or a Tawk.to widget for their respective integrations. None is bundled.

No Node.js, npm, build step, WhatsApp API token or Meta application is needed to run the plugin. Node.js is only used for development tests.

## Installation

### From source

```sh
git clone https://github.com/Keroyun/whatsapp-tracker.git
```

Copy the `whatsapp-tracker` directory into `wp-content/plugins/`, then activate **WhatsApp Tracker** in WordPress → Plugins. Do not place WordPress itself or its database/configuration inside this repository.

### From a ZIP

Download the source ZIP from GitHub, extract it and rename its top-level directory to `whatsapp-tracker`. Upload that directory to `wp-content/plugins/`, or re-zip it as `whatsapp-tracker.zip` and use Plugins → Add Plugin → Upload Plugin.

For upgrades, back up your site and replace the existing plugin. Do not uninstall first: uninstall may remove stored data if its delete-data option was enabled. Purge page/CDN caches after replacing files so pages load the new versioned frontend JavaScript and CSS.

## Usage

### Configure analytics and approved destinations

Open **WhatsApp Tracker → Settings**, set tracking and retention preferences, and enter your own approved contact destinations. Labels may share a destination; use source tags for distinct attribution. Telephone events are emitted to an existing `dataLayer` only; they are not stored in the WhatsApp analytics dashboard. Consent/tag configuration is the site owner's responsibility.

### Generate links and central routes

Use **Link Generator** for a direct WhatsApp link. Use **Central Routes** for a reusable route whose destination can later be switched without editing every page:

```text
[whatsapp-route route="support" title="Contact support"]
```

Create the `support` route before using this example. The legacy `[whatsapp-link]` shortcode is also supported. Route message fields currently use `en`, `zh` and `id`; this legacy route feature is separate from the more general notice-language selector.

### Display a service notice

1. Open **Emergency Popups** and add a WhatsApp or telephone notice.
2. Enter your affected destinations and, optionally, page paths or exact links.
3. Use **Match all** to require both the specified page and destination. **Match any** allows either group to trigger; a matching page can therefore affect every supported link on that page.
4. Enter your own title, message and alternative actions, then enable the rule and its channel's visibility checkbox.
5. Test affected and unaffected links on desktop and a physical mobile device.

Telephone calls use `tel:`; WhatsApp uses a WhatsApp URL. Country codes are not guessed: explicitly include each local/international form present on your pages. Short emergency/service numbers are not intercepted. Add `data-awm-emergency-exempt="1"` to a link that must bypass notices.

### Optional third button and forms

Tick **Show a third action button**, set its label and choose a URL/telephone action or a shortcode such as `[wpforms id="123"]` from an installed form provider. The X remains available to close the popup; Back returns from the form to the notice. Closing or returning discards unfinished form input.

Forms render in a same-origin, form-only document. Only the shortcode provider's head/footer/enqueue callbacks and WordPress asset printers run there; unrelated site chat widgets, popups, theme chrome and sticky footers are excluded. This is request-local: the parent website retains its normal widgets. Form styles and scripts, including registered dependencies, are retained without hiding arbitrary iframes or CAPTCHA elements.

The form provider owns validation, storage, email and consent. Test CAPTCHA, confirmation redirects and other provider-specific behavior. Theme styling and separate add-on plugins are not automatically included; developers can explicitly opt in the needed provider directory/file using the `awm_notice_form_provider_roots` filter. A shortcode registered in a theme/custom file retains callbacks from that file only. For a page-dependent form, use its normal page URL instead. Never embed private/admin-only content in this public popup. Provider-emitted content is trusted, so this separation is not a security sandbox and cannot remove a widget intentionally emitted by the form provider itself.

### Languages

Enter popup text in any language. **Default / All languages** supplies fallback content. With Polylang configured, create translated rules and select their page languages. Among matching rules, a current-language rule takes precedence over Default; specificity and priority resolve ties within that scope.

There is no automatic translation or device-language guessing. Without a detected Polylang language, only Default rules apply. WPML is not integrated. Marked notice-editor strings use the `whatsapp-tracker` text domain; language packs are not bundled and legacy admin screens are not fully internationalized.

### Scanning and migration

Run **Link Inventory** on demand. Preview **Bulk Migration** changes before applying them, and keep an independent backup. Migration backups can contain full original content and metadata, including private content; never publish database exports or migration records.

## Caching

- Exclude `whatsapp-tracker/assets/frontend-tracker` from JavaScript delay/defer tools.
- Do not cache `/wp-json/whatsapp-tracker/v1/emergency-rules` or requests containing `awm_notice_form`.
- Never cache form security tokens. Review additional cache rules with your form provider.
- The plugin has LiteSpeed integration hooks, but other CDN/cache products require site-specific configuration.

## Project structure

```text
whatsapp-tracker.php   Plugin bootstrap, metadata and constants
includes/             Rules, routing, database, tracking and security helpers
admin/                WordPress management screens and administrative handlers
assets/               Runtime JavaScript and CSS (no build step)
languages/            Notice-editor translation template
tests/                Synthetic frontend and WordPress PHPUnit tests
uninstall.php         Optional plugin-owned data cleanup
phpunit.xml.dist       Portable PHPUnit configuration
TESTING.md            Verification scope and staging checklist
SECURITY.md           Privacy boundaries and reporting guidance
LICENSE               GNU General Public License, version 2
```

## Security and privacy

- This repository contains source code and synthetic fixtures, not production settings, databases, conversation exports or credentials. Test/example telephone values use fictional-number ranges; invalid values and short service codes exist solely to test validation.
- Installed-site analytics store daily counts, paths, destinations, source labels, link text and first/last activity timestamps. They do not store WhatsApp conversation contents.
- Raw IP/user-agent values are used to derive a keyed rate-limit identifier, not stored in the plugin's analytics tables. Web-server logs and other plugins may still record them.
- Inventory records retain link URLs, which may include prefilled message text. Migration backups retain original values; audit records include administrator IDs and bounded context. Treat your database and exports as potentially sensitive.
- Active notices and configured frontend contact destinations are public display data. Language/page targeting is not authorization or a way to keep a number secret.
- New installations enable tracking by default. Configure it and any required consent before exposing the site to visitors. Default analytics/audit retention is 365 days, with scheduled cleanup dependent on WordPress cron.
- Uninstall preserves data unless the administrator explicitly opts into deletion. Other retention/backup obligations remain with the site owner.
- Never commit `.env`, `wp-config.php`, credentials, database dumps, real contact fixtures, logs or captured site content. `.gitignore` is a safeguard, not a secret scanner.

See [SECURITY.md](SECURITY.md) for more detail. This review is not a penetration-test certification.

## Development and validation

```sh
node tests/frontend.test.cjs
bash tests/smoke.sh
```

The smoke script additionally requires PHP on PATH. To run the PHP unit tests, install a compatible PHPUnit and the WordPress core test library, set `WP_TESTS_DIR`, then run `phpunit -c phpunit.xml.dist` against a disposable test database.

See [TESTING.md](TESTING.md) for completed checks and limitations. Physical devices, live Polylang configuration, other form providers and production hosting require staging validation.

## License and disclaimer

Licensed under **GPL-2.0-or-later**, preserving the plugin's existing license declaration. See [LICENSE](LICENSE).

WhatsApp Tracker is an independent project and is **not affiliated with, endorsed by, or sponsored by WhatsApp or Meta**. WhatsApp and Meta names and marks belong to their respective owners. The plugin controls website links only; it cannot prevent calls or messages made outside the website.
