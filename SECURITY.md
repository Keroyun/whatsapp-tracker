# Security and privacy

## Reporting

Do not publish credentials, private messages, production contact data, database exports or unredacted logs in issues. Use GitHub private vulnerability reporting when available, or contact the maintainer through [khairulazhar.com](https://khairulazhar.com).

## Administrative boundaries

- Management screens use the dedicated `manage_whatsapp_tracker` capability.
- Administrators always retain that capability; selected roles may be granted it without receiving full `manage_options` access.
- State-changing operations use WordPress nonces.
- Portable configuration import is capability-protected, nonce-protected, limited to 1 MB and re-sanitized through production validators.

## Public tracking

- The tracking endpoint validates request origin, content type and payload size and applies a bounded rate limit.
- Raw visitor IP addresses and user agents are used only to derive a keyed rate-limit identifier; they are not stored in analytics tables.
- Click analytics are aggregated.
- Telephone click events are dataLayer-only unless another analytics system records them.

## Public notice configuration

Active notice targeting and contact destinations must be available to the visitor's browser to perform matching. Do not treat them as secrets.

Internal administrator rule names are not included in public notice configuration. A separate sanitized analytics label is exposed for frontend events.

## External integrations

- Tawk.to support is optional and provider-neutral at the rule level; WhatsApp Tracker does not install or inject the Tawk widget.
- Shortcode form providers remain responsible for form validation, storage, consent, CAPTCHA and downstream processing.
- GitHub release checks make bounded HTTPS requests to the public GitHub API and cache the response.

## Scanning and migration

- Quick and Recent Changes scans inspect WordPress content.
- Deep Scan performs bounded loopback HTTP requests and remains manual.
- Scheduled Recent Changes is optional, lightweight, and stops for manual review when more than 100 content changes are pending.
- Inventory URLs may contain prefilled WhatsApp text.
- Migration backups may contain original page content and metadata. Protect database backups accordingly.

## Data removal

Deactivation does not delete plugin data. Uninstall removes plugin capabilities and scheduled tasks. Stored plugin data is deleted only when the administrator explicitly enables the uninstall deletion setting.

Source review and automated tests reduce risk but do not constitute a penetration-test certification.
