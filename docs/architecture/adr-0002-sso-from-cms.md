# ADR-0002 — Staff sign in once, in kmu-cms (SSO)

Status: Accepted (17 Sep 2026). Implemented in step 6 (18 Sep 2026).

## Decision

A staff member who is already signed in to kmu-cms clicks **Assessment / Exams** and lands in
this app already signed in. There is no second login screen, and no local password login at all
(see ADR-0004). Logging out in either app logs out of both.

## Flow

1. kmu-cms route `admin/kmuexam/launch` (requires a CMS session) builds a short-lived signed ticket:
   `{ sub: staff_id, aud: "kmu-assess", iat, exp: iat + 60s, jti: 32 random bytes, redirect: "/path" }`,
   signed with HMAC-SHA256 using a shared secret (at least 32 random bytes, from environment
   config on both servers, never committed).
2. kmu-cms returns an auto-submitting HTML form that **POSTs** the ticket to
   `POST /sso/cms` on this app. A POST keeps the ticket out of URLs, server logs, browser history
   and `Referer` headers.
3. This app verifies, in order, and rejects on the first failure (generic 403, reason logged):
    - signature with `hash_equals` (constant time);
    - `aud` is `kmu-assess`; `exp` not passed, `iat` not in the future (30 s clock skew);
    - `jti` never used before (stored in `sso_consumed_tickets`, unique, purged after expiry) — a
      copied ticket cannot be replayed;
    - staff row re-read from kmu-cms through the read-only view: exists and is active (the ticket
      is trusted for the ID only);
    - the local user linked by `cms_staff_id` (created on first visit, name/email synced from CMS)
      is active.
4. `Auth::login`, session ID regenerated, `last_login_at` set, audit entry written.
5. Redirect only to a relative path inside this app (allow-list, no `//` or scheme), so the
   ticket cannot be used as an open redirect.

## Security notes

- `POST /sso/cms` is the only route excluded from CSRF verification; the signature, expiry and
  one-time `jti` replace the CSRF token. It is rate-limited per IP.
- SSO proves identity only. What the user may do comes from permissions and scopes (step 7).
- Privileged roles (super admin, QBank admin, approvers, exam controller) must also pass this
  app's own MFA after SSO, because kmu-cms has no MFA.
- Logging out of kmu-cms does not end this app's session; the 30-minute idle timeout limits the
  gap. A logout link back to kmu-cms is provided.
- Candidates never use this flow; they sign in to the exam client with candidate number + exam PIN.

## Tests required in step 6

Valid ticket signs in; tampered payload, wrong secret, expired, future-dated, wrong audience,
replayed `jti`, inactive CMS staff, inactive local user and external redirect are each rejected;
a GET to the endpoint is not allowed; the session ID changes on login.

## Implementation (step 6)

| Part                                | Where                                                                                                                                          |
| ----------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| Launch link "Question Bank & Exams" | kmu-cms sidebar → `admin/kmuexam/launch` (`application/controllers/admin/Kmuexam.php`)                                                         |
| Ticket issuer                       | kmu-cms `application/libraries/Kmu_sso_ticket.php`; settings in `application/config/kmu_assessment.php` (not in git; see `.example.php`)       |
| Entry point                         | `POST /sso/cms` (`routes/sso.php`), CSRF-exempt, `throttle:cms-sso` (20/min per IP)                                                            |
| Verification                        | `App\Domain\Identity\Sso\CmsTicketVerifier`                                                                                                    |
| Sign-in                             | `App\Domain\Identity\Actions\SignInFromCms` (burns jti first, re-reads CMS staff, links or creates the user, refuses email takeover)           |
| Used ticket ids                     | `sso_consumed_tickets`, purged daily by `sso:purge-tickets`                                                                                    |
| CMS data access                     | `v_cms_*` views (`App\Support\Cms\CmsViews`), read on the `cms` connection by a SELECT-only user: `php artisan cms:reader-sql \| mysql -uroot` |
| Settings                            | `.env`: `KMU_CMS_URL`, `KMU_CMS_SSO_SECRET` (same value as the CMS), `CMS_SOURCE_DATABASE`, `CMS_DB_USERNAME`, `CMS_DB_PASSWORD`               |

Tests: `tests/Feature/Identity/CmsSsoTest.php` (every refusal case above), `tests/Integration/CmsReaderAccessTest.php`
(the reader account can read the views and nothing else), and the browser test `tests/sso/sso_e2e.mjs` in kmu-cms.
