# Changelog

## 3.4.1 — Form-only popup document

- Exclude unrelated site head/footer/enqueue callbacks and early site asset queues from the embedded form request, preventing duplicate chat widgets and sticky footers.
- Preserve the detected shortcode provider's callbacks, WordPress asset printers and registered dependencies for form submissions and CAPTCHA integrations.
- Add explicit provider-root integration filter and neutral form-document styling. Parent-page widgets remain unchanged.
- Raise the notice overlay above typical high-z-index site widgets; update cache-safe frontend asset filenames.
- Add request-local hook/asset isolation integration checks. No saved-rule or database migration is required.

## 3.4.0 — Initial public release

- Configurable WhatsApp and telephone notices with independent visibility controls.
- Optional third URL/telephone action or embedded shortcode form, with Back and X close.
- Polylang notice-language matching and Default fallback, without automatic translation.
- Version-specific frontend asset filenames and preserved mobile link interception.
- Existing central routes, inventory, aggregate analytics, migration and audit features.
- Public-source preparation: fictional contact fixtures, portable documentation, credential/data exclusions and the existing GPL-2.0-or-later license.

Production-specific deployment history is intentionally not included in this public source repository.
