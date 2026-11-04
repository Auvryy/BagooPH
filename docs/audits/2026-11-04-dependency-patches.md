# Compatible dependency security updates — November 4, 2026

Branch: `fix/final-essential-accessibility-2026-11-04`.

Fresh audits confirmed the earlier findings. Only four existing packages were
updated, within their existing compatible constraints. `composer.json` and
`package.json` were not changed; no dependency was added or removed in the locks.

| Package | Before | After | Maintainer evidence |
|---|---|---|---|
| Symfony MIME | 8.1.7 | 8.1.10 | [Header-parameter advisory](https://symfony.com/blog/cve-2026-45070-email-header-injection-via-non-token-characters-in-mime-parameter-names) |
| follow-redirects | 1.16.0 | 1.16.2 | [Cross-realm sensitive-header advisory](https://github.com/follow-redirects/follow-redirects/security/advisories/GHSA-mrwp-f3q8-3g9p) |
| qs | 6.16.0 | 6.17.1 | [Stringify advisory](https://github.com/ljharb/qs/security/advisories/GHSA-6pq8-jhm2-2vcw), [release notes](https://github.com/ljharb/qs/blob/v6.17.1/CHANGELOG.md) |
| Vite | 6.4.3 | 6.4.5 | [Dev-server advisory](https://github.com/vitejs/vite/security/advisories/GHSA-rq7h-c2jc-7f22) |

Symfony stayed on its 8.1 patch line. Vite stayed on major version 6, compatible
with the installed Laravel Vite plugin. The qs update is a compatible minor
within Inertia's existing `^6.15.0` constraint, including the fixed stringify
behaviour and later parsing/encoding corrections.

## Before and after

| Audit | Before | After |
|---|---|---|
| `composer audit --locked --format=json` | One medium advisory, Symfony MIME | Zero advisories; exit 0 |
| `npm audit --json` | Three affected packages: two high, one moderate | Zero reported vulnerabilities; exit 0 |

These are the feeds' findings for this dependency tree at the time checked.
They do not establish an exploitable Bagoo request path or prove the application
has no security defects. Redirect findings concern Node HTTP behaviour; browser
Axios uses a different adapter. Vite findings include development-server/editor
conditions, not proof that the production static website was exploitable.

## Verification using the installed packages

- Actual updated MIME code serialized a synthetic email plus attachment. Invalid
  parameter names containing CR/LF or spaces were rejected in two negative checks.
  No SMTP transport or real email was used.
- Two loopback-only redirect checks used normal and cross-realm sensitive-header
  arrays. The synthetic protected header was stripped on the cross-origin redirect;
  an unrelated header remained. Both temporary servers were closed.
- Updated qs preserved a normal Unicode/array query round trip and did not throw
  for the advisory's non-callable `toString` array element.
- The combined final branch passed 119 frontend tests, 51 isolated authentication/
  KYC backend tests with 261 assertions, and the production TypeScript/Vite build.
  The PHP selection includes OTP, verification, reset, secret-mail protections,
  KYC approval, evidence/token invalidation and stable decision retries.

The existing manifests stayed byte-for-byte unchanged. Composer used
`--minimal-changes --no-scripts`; migration/setup/publishing hooks were not run.
The host npm attempt hit existing generated-file permissions, then the normal
application container completed the three-package update. Final checks used the
updated installed packages, not just modified lockfiles.

Updates used:

```sh
docker compose exec -T app composer update 'symfony/mime:8.1.*' \
  --with-dependencies --minimal-changes --no-scripts --no-interaction
docker compose exec -T app npm update follow-redirects qs vite \
  --ignore-scripts --no-audit --no-fund
```

Audits and smoke artifacts are retained under ignored
`storage/app/qa-essential-accessibility-2026-11-04/`. No browser/window, live
database reset, deployment, real mail or external financial operation occurred.
Deployed SMTP/proxy behaviour and the full project regression suite were not
verified by these focused checks. The dependency updates are source/lock changes
for review; deployment still needs the normal installation of the locked packages.
