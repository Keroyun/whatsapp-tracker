# WhatsApp Tracker 3.4.0 testing

## Completed for this release

- 37 Node regression checks using the actual release JavaScript: telephone and WhatsApp targeting, language-specific precedence and target-sensitive Default fallback, third action hidden by default, third telephone action, embedded form/Back, cross-origin form rejection, mobile event cancellation, master checkboxes and analytics suppression.
- 13 checks in WordPress 7.1 / PHP 8.3.32 Playground: Unicode preservation, sanitization, generic number handling, saved-rule retention, safe public config, metadata and PHP syntax.
- Browser on WordPress: affected telephone link opens notice; unrelated telephone retains tel:; WhatsApp offers its separate third action.
- Actual WPForms Lite shortcode renders in popup, accepts a local test submission, and displays its AJAX confirmation. Notifications were disabled for that disposable test form.
- Browser at desktop and 390x844: form fits; Back restores notice; X closes. Admin third-action checkbox saved off and the frontend hides the third button while retaining X.
- Anonymous HTTP: active form endpoint 200 with no-store/SAMEORIGIN; unknown rule 404.

## Reproducible fast checks

Run `node tests/frontend.test.cjs`. Run `tests/smoke.sh` where native PHP and Node are installed for PHP lint, JS syntax and the frontend suite. The inline marker check permits only the existing external script-tag attribute rewrite; third-party shortcode output is outside that check.

The PHP files under tests use the WordPress core test library and PHPUnit; set WP_TESTS_DIR and run `phpunit -c phpunit.xml.dist`. That complete PHPUnit suite was not run in this environment.

## Required site-specific staging checks

1. Upgrade without uninstalling and verify existing rules, routes and analytics.
2. Purge page/CDN caches. Check the page uses frontend-tracker-3.4.0.js and frontend-3.4.0.css.
3. On physical iOS and Android, test affected and unaffected telephone/WhatsApp links. Test both popup master switches.
4. With actual Polylang configured, verify each language-specific rule and Default fallback after page-language switching. Language matching has regression coverage; a full Polylang installation was not exercised here.
5. Test your real shortcode provider, CAPTCHA, validation, privacy consent, confirmation/redirect, mail delivery and any payments in that provider's test mode. Basic WPForms Lite success does not certify all forms.
6. Verify forms are not cached. Test closing and Back with unsent input, keyboard navigation, and screen-reader interaction.
7. Run existing scanner/migration workflows on disposable staging data; this release does not re-certify every legacy admin workflow.

No physical-device or production deployment tests were performed for 3.4.0.
