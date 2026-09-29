# Changelog

## 1.0.0 — Generic multi-site baseline

This release resets the public product version to 1.0.0 and converts the project from a site-specific deployment into a reusable client plugin while retaining the stable functionality developed in the previous 3.x codebase.

- Remove site-specific production assumptions and use generic configuration.
- Set author to Khairul Azhar, author website to khairulazhar.com and plugin page to My Plugins & Tools.
- Add dedicated `manage_whatsapp_tracker` capability and configurable role access.
- Replace fixed EN/ZH/ID route fields with dynamic language messages and legacy-data compatibility.
- Replace hardcoded Tawk action semantics with provider-neutral Live Chat; Tawk.to remains an optional provider.
- Keep internal notice names private from frontend configuration and introduce public-safe analytics labels.
- Add portable configuration export/import with validation and sanitization.
- Add optional lightweight Daily/Weekly Recent Changes scans while keeping Deep Scan manual.
- Add plugin health reporting to the dashboard.
- Add GitHub release update support and Update URI metadata.
- Correct uninstall cleanup for rate-limit transients and scheduled tasks.
- Remove superseded runtime assets and use cache-safe 1.0.0 filenames.
- Preserve the 3.4.1 form-isolation, popup, analytics, inventory, migration and security improvements.
