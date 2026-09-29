# WhatsApp Tracker 1.0.0 testing

## Automated checks

The repository CI runs:

```sh
bash tests/smoke.sh
```

The smoke suite performs PHP syntax checks, JavaScript syntax checks and the Node frontend regression suite.

The frontend suite covers contact parsing, notice targeting, language precedence, mobile interception, popup visibility, alternative actions, embedded form behaviour, public-safe analytics labels and generic live-chat/Tawk compatibility.

WordPress PHPUnit tests remain available through `phpunit.xml.dist` when the WordPress core test library is configured.

## Required staging checks

Before replacing an existing client installation:

1. Back up the database and plugin directory.
2. Upgrade without uninstalling so existing options and analytics remain intact.
3. Confirm legacy managed-route messages still appear and route correctly.
4. Purge page/CDN caches and verify `frontend-tracker-1.0.0.js` and `frontend-1.0.0.css` load.
5. Test affected and unaffected WhatsApp/telephone links on physical iOS and Android devices.
6. If Polylang is used, verify every configured language and fallback behaviour.
7. If Live Chat is used, select its provider under Settings and verify the widget plus fallback URL.
8. Test real shortcode forms, validation, CAPTCHA, confirmation/redirect and mail delivery.
9. Run a Quick Scan, then verify Recent Changes and Current Page scanning.
10. Export configuration from staging and import into a disposable WordPress site before using the workflow for multiple clients.
11. Confirm any delegated role can access/save WhatsApp Tracker settings but does not gain unrelated WordPress administrator privileges.
12. Publish a GitHub release with a `whatsapp-tracker.zip` asset and verify WordPress update discovery on staging.

No automated suite replaces client-specific staging, CDN/cache validation or physical-device testing.
