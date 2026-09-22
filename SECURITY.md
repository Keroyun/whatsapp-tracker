# Security and privacy

## Reporting

Do not put secrets, private messages, production numbers, database exports or unredacted logs into public issues or pull requests. Contact the maintainer through the contact method published at [khairulazhar.com](https://khairulazhar.com) to arrange a private report. Use GitHub private vulnerability reporting if it is available for this repository.

## Boundaries

- Administrative settings, scans, exports and migrations require WordPress administrative capabilities and use WordPress nonce checks for state-changing requests.
- Public tracking uses origin, payload/content-type and rate-limit checks. These reduce abuse but are not proof that a human clicked a link.
- Public notice configuration is intentionally readable by visitors. Contact destinations and notice actions must not contain secrets or credential-bearing URLs.
- The form endpoint executes an administrator-saved shortcode for an active configured rule; visitors cannot submit arbitrary shortcode source. The provider remains responsible for form security, data processing and consent.
- Shortlink resolution is administrator-triggered and bounded. Third-party chat/form plugins, server logging and downstream analytics have their own privacy behavior.
- Inventory URLs may contain prefilled text. Migration backups may contain private post content and metadata. Keep site databases, backups and exports outside Git.

## Public-source preparation

The initial public tree excludes production captures, local test infrastructure, dependency directories, caches, release ZIPs, media, historical deployment notes and database data. Regression fixtures and UI examples were replaced with fictional-number examples. The only personal attribution intentionally retained is the author name and the author's explicitly requested public website/GitHub links.

Source scanning is not a guarantee that all vulnerabilities have been found. Review every future commit and its history before publishing; ignoring a file does not remove it from an existing commit. If a credential is ever exposed, revoke/rotate it promptly and coordinate removal of the exposed material.
