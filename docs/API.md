# EverDigitech Admin API

Laravel 13 · Sanctum SPA cookie auth · spatie/laravel-permission · MySQL `everdigitech`.
Base URL (local): `http://localhost:8000`. Everything below is under `/api/admin` unless noted.

## 1. Run it

```bash
cd everdigitechapi
php artisan migrate:fresh --seed     # creates permissions, 5 roles and the first Super Admin
php artisan serve                    # http://localhost:8000
php vendor/bin/pest                  # run the tests
```

On this machine use PHP 8.3 and MySQL on port **3308** (see the memory note about the Laragon `php.ini`).

The first Super Admin comes from `.env`: `SUPER_ADMIN_NAME`, `SUPER_ADMIN_EMAIL`, `SUPER_ADMIN_PASSWORD` (12+ characters).
Local default: `admin@everdigitech.test` with the password in your `.env`.

Emails (password reset, staff invite) are written to `storage/logs/laravel.log` (`MAIL_MAILER=log`). Search for `reset-password?token=`.

## 2. Test in Postman

1. Import `docs/everdigitech-admin-api.postman_collection.json` and `docs/everdigitech-local.postman_environment.json`.
2. Select the **EverDigitech Local** environment and set `password` to your `SUPER_ADMIN_PASSWORD`.
3. Run **Auth → 1. Get CSRF cookie**, then **Auth → 2. Login**.
4. Everything else now works. Run **Roles → List roles** first: it saves `salesRoleId`, `supportRoleId` and `superAdminRoleId` for the Staff requests. **Create role** and **Create staff** save `roleId` and `staffId`.

**Two collections, every route in them.** `everdigitech-admin-api.postman_collection.json` has every `/api/admin` request (Auth, Roles, Staff, Clients, Support tickets) and `everdigitech-client-api.postman_collection.json` has every `/api/client` request (Auth, Account security, Log in as client, Team, Support tickets). A test (`tests/Feature/PostmanCollectionsTest.php`) fails when a route has no request, when a request has no route, or when a request uses a variable nobody declared, so add the Postman request in the same change as a new route.

**Client API:** also import `docs/everdigitech-client-api.postman_collection.json` (it uses the same environment: `clientOrigin`, `clientEmail`, `clientPassword`, `newClientPassword`). Run **Auth → 1. Get CSRF cookie**, then **Register** or **Login**. Emails (verify, reset, confirm new email) are in `storage/logs/laravel.log` with `MAIL_MAILER=log`; copy the values from the link into the collection variables. For "Continue with Google": sign in with Google once on http://localhost:3002/login with DevTools > Network open, copy the `credential` from the `google` request into the collection variable `googleCredential`, then run **Auth → Continue with Google**. For "log in as client": in the admin collection set `accountId` and run **Clients → Log in as client**, then run **Log in as client → Consume impersonation token** in the client collection (the token lasts 60 seconds). Postman shares cookies per host, so the admin and client sign-ins can coexist.

How the cookie login works (the collection does this for you):

- The API starts a session only for requests whose `Origin` is a "stateful" origin (`localhost:3003` or `localhost:3002`). The collection sets `Origin: http://localhost:3003` and `Referer` on every request.
- Every write (POST/PUT/DELETE) needs the header `X-XSRF-TOKEN` with the value of the `XSRF-TOKEN` cookie (URL-decoded). The collection's pre-request script copies it.
- A **419** means the token is stale: run **Get CSRF cookie** again.
- A **401** means you are not signed in (or the session ended): log in again.

Doing it by hand with curl:

```bash
curl -c jar -b jar -H "Origin: http://localhost:3003" -H "Referer: http://localhost:3003/" localhost:8000/sanctum/csrf-cookie
# copy the XSRF-TOKEN cookie value (URL-decoded) into X-XSRF-TOKEN
curl -c jar -b jar -H "Origin: http://localhost:3003" -H "Referer: http://localhost:3003/" -H "Accept: application/json" \
  -H "Content-Type: application/json" -H "X-XSRF-TOKEN: <token>" \
  -d '{"email":"admin@everdigitech.test","password":"<password>","remember":true}' localhost:8000/api/admin/login
```

## 3. Conventions

- **Success:** a single item is `{ "data": {...} }`, a list is `{ "data": [...] }`, a paginated list adds `meta` (`current_page, last_page, per_page, total, from, to`) and `links`.
- **Fields are camelCase.** Ids are strings. Dates are ISO 8601 UTC (`2026-10-03T08:15:00Z`).
- **Errors** always have this shape, for every status, never HTML:

```json
{ "message": "Human readable text", "code": "machine_code", "errors": { "field": ["..."] } }
```

`errors` appears only on 422 validation errors.

| Status | `code` | When |
|---|---|---|
| 401 | `unauthenticated` | Not signed in |
| 403 | `forbidden` | Missing the permission for this route |
| 404 | `not_found` | Unknown or deleted item |
| 405 | `method_not_allowed` | Wrong HTTP method |
| 419 | `csrf_token_mismatch` | Missing or stale `X-XSRF-TOKEN` |
| 422 | `validation_failed` | Invalid body, with `errors` |
| 429 | `too_many_requests` | Rate limit |
| 500 | `server_error` | Never leaks details unless `APP_DEBUG=true` |

Business-rule codes are listed per endpoint below.

## 4. Auth

| Method | Path | Notes |
|---|---|---|
| GET | `/sanctum/csrf-cookie` (no `/api/admin`) | 204. Sets `XSRF-TOKEN` + session cookies. |
| POST | `/api/admin/login` | Body `{email, password, remember}`. Returns `{data: Me}`. |
| GET | `/api/admin/me` | Returns `{data: Me}`. |
| GET | `/api/admin/me/login-history` | Your last 50 sign-ins, 10 per page. |
| POST | `/api/admin/logout` | 204. Signs out the admin guard only; the session (and a client login) is kept. |
| POST | `/api/admin/forgot-password` | Body `{email}`. Same answer whether or not the email exists. 5 per minute. |
| POST | `/api/admin/reset-password` | Body `{token, email, password, password_confirmation}`. |

`Me`:

```json
{ "data": {
  "user": { "id": "1", "name": "Super Admin", "email": "admin@everdigitech.test" },
  "role": { "id": "1", "name": "Super Admin", "isSuperAdmin": true },
  "permissions": ["clients.view", "clients.create", "..."]
} }
```

A Super Admin gets all 54 permission names. `role` is `null` for an admin with no role.

Login errors:

| Status | `code` | Meaning |
|---|---|---|
| 422 | `invalid_credentials` | Wrong email or password (same answer for both) |
| 429 | `account_locked` | 5 failures per email + IP lock for 15 minutes. Body also has `retry_after_minutes`; header `Retry-After`. |
| 403 | `account_suspended` | Right password, suspended account |

Reset errors: 422 `invalid_reset_token` (wrong, used or expired token, or unknown email), 422 `validation_failed` for password rules.
Password rules: at least 12 characters, upper and lower case, a number, not in a known data breach.

## 5. Roles and permissions

Permissions are named `module.action` (for example `clients.view`, `invoices.refund`) and defined in `config/permissions.php`:
13 modules, 54 permissions. Every module's other permissions need its `view`; the API adds a missing `view` for you.

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/permissions` | `roles.view` | `{data: [{key, label, description, actions[], special: {action,label}\|null}]}` |
| GET | `/roles` | `roles.view` | `{data: [Role]}` |
| GET | `/roles/{id}` | `roles.view` | `{data: Role}` |
| POST | `/roles` | `roles.create` | Body `{name, description}`. 201. A new role has no permissions. |
| PUT | `/roles/{id}` | `roles.edit` | Body `{name, description, permissions[]}`. An empty list revokes everything. |
| DELETE | `/roles/{id}` | `roles.delete` | 204 |

`Role`: `{ id, name, description, locked, staffCount, permissions[] }` (permissions in catalog order).

Rules and codes:

- 403 `role_locked`: the Super Admin role can't be edited or deleted, by anyone.
- 422 `role_in_use`: a role that still has staff can't be deleted.
- 422 `validation_failed`: duplicate name (case-insensitive), unknown permission name (error on `permissions.N`).
- 403 `super_admin_only`: only a Super Admin can add `staff.*` or `roles.*` permissions to a role.
- Create, update and delete are written to the activity log with before/after permissions.

Default roles: **Super Admin** (locked, all 54), **Admin** (45, everything except staff, roles and `settings.edit`),
**Billing Manager** (15), **Support Staff** (9), **Sales** (7).

## 6. Staff

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/staff` | `staff.view` | 15 per page, sorted by name. Query: `page`, `role` (role id), `status` (`active`\|`invited`\|`suspended`), `search` (name or email). `meta` also has `counts` and `stats` (below). |
| GET | `/staff/roles` | `staff.view` | The roles someone can be given: `{data: [{id, name, description, isSuperAdmin}]}`. Needs only `staff.view` (unlike `/roles`). |
| GET | `/staff/{id}` | `staff.view` | |
| POST | `/staff` | `staff.create` | Body `{name, email, phone, role}` (`role` = role id, `phone` optional). 201. Emails a "set your password" link valid for 48 hours. |
| PUT | `/staff/{id}` | `staff.edit` | Body `{name, phone, role, status}`. The email can't be changed. |
| DELETE | `/staff/{id}` | `staff.delete` | Soft delete. 204. |
| POST | `/staff/{id}/resend-invite` | `staff.create` | Sends a new link and voids the old one. 10 per minute. |

`Staff`:

```json
{ "id": "2", "name": "Test Person", "email": "test.person@example.com", "phone": "+8801711223344",
  "avatarUrl": null, "status": "invited",
  "role": { "id": "5", "name": "Sales", "isSuperAdmin": false },
  "invitedAt": "2026-10-03T05:21:26Z", "lastLoginAt": null, "lastLoginIp": null, "lastLoginDevice": null,
  "suspendedBy": null, "twoFactorEnabled": false, "createdAt": "2026-10-03T05:21:26Z" }
```

- `status` is `active`, `invited` (an active account whose owner hasn't set a password yet) or `suspended` (wins over invited). A PUT can only set `active` or `suspended`; setting a password through the invite link clears the invite.
- `lastLoginDevice` is "Chrome on Windows" from the latest successful sign-in. There is no location: the API has no GeoIP lookup, so the page shows the IP.
- `suspendedBy` is the name of the admin who suspended them, from the activity log.

List `meta` (besides the paginator fields):

```json
"counts": { "all": 15, "active": 13, "invited": 1, "suspended": 1 },
"stats":  { "total": 15, "active": 13, "invited": 1, "suspended": 1, "twoFactorOn": 5, "twoFactorOf": 13, "roles": 5, "activeSuperAdmins": 1 }
```

`counts` follow the `role` filter and the search but not the status tab (for the tab numbers). `stats` is everyone, whatever the filters (for the four cards).
`twoFactorOn` of `twoFactorOf` counts signed-up active staff only; `activeSuperAdmins` at 1 means the last Super Admin is protected.

Rules and codes:

- 422 `cannot_suspend_self`, 422 `cannot_delete_self`: nobody can suspend or delete themselves.
- 422 `last_super_admin`: the last active Super Admin can't be deleted, suspended or moved to another role.
- 403 `super_admin_only`: only a Super Admin can assign the Super Admin role, or change/delete/re-invite a Super Admin account.
- 422 `already_active` (resend invite): the person has already signed in; use "Forgot password".
- 502 `mail_failed` (create staff, resend invite): the invite email couldn't be sent. Creating saves nothing in that case, so the same request can be repeated. Forgot-password never returns this: it answers as usual and logs the error.
- Suspending, deleting or changing the role of an admin **deletes their rows in the `sessions` table** at once and rotates their remember-me token.
- Every create, update, suspend/activate, delete and resend is written to `activity_logs`.
- A deleted account keeps its email reserved, so creating a new account with it gives a 422 on `email`.

The invite flow: create staff → open `storage/logs/laravel.log` → copy the `token` from the `reset-password?token=...&email=...&invite=1` link →
`POST /reset-password` with that token, the email and a new password → the person can log in.

## 7. Clients

A **client** is a `client_accounts` row: it owns the services, domains and invoices. People sign in as `client_users` and reach an account
through `account_members` (role `owner` or `member`, per-member `permissions`, and `hosting_scope` / `domain_scope` = `all` or `selected`;
the selected services and domains are listed in `account_member_resources`). One person can belong to several accounts.

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/clients` | `clients.view` | Paginated list, 15 per page. |

Query (all optional; `all` and empty values mean no filter):

| Param | Values |
|---|---|
| `page` | 1, 2, ... A page past the end returns the last page. |
| `status` | `active`, `inactive`, `closed`, `all` |
| `group` | `regular`, `reseller`, `vip`, `all` |
| `search` | Part of the name, company or owner email (case-insensitive). |
| `sort` | `name`, `joined`, `balance`, `services`, `domains`; prefix `-` for descending. Default `-joined`. |

Response: `{ data: [Client], meta: { current_page, last_page, per_page, total, from, to, counts: { all, active, inactive, closed } } }`

```json
{ "id": "20", "name": "Rafiq Uddin", "email": "rafiq.uddin@swapnostudio.example", "company": "Swapno Studio",
  "services": 5, "domains": 8, "balanceDue": 0, "overdue": false, "group": "regular", "status": "active", "joinedOn": "2026-09-19" }
```

- `email` is the account owner's email. `company` is `null` when there is none. `joinedOn` is a date in Bangladesh time (`Asia/Dhaka`).
- `meta.counts` is for the status tabs: it follows `group` and `search` but **not** `status`, so the other tabs keep their numbers.
- **Temporary:** `services`, `domains`, `balanceDue` and `overdue` come from placeholder `tmp_*` columns until the services, domains and invoices tables exist (marked with TODO in the code).
- Local demo data: `php artisan migrate:fresh --seed` adds 60 Bangladeshi clients (45 active, 9 inactive, 6 closed) when `APP_ENV=local`. Demo users' password: `Demo-Password-123`.

## 8. Client authentication (`/api/client`)

For the client panel (http://localhost:3002). Same cookie rules as the admin: call `GET /sanctum/csrf-cookie` first, send the `X-XSRF-TOKEN` header on every write, and send `Origin: http://localhost:3002`.
The `client` guard is separate from `admin`, so a staff member and a customer can be signed in in the same browser. Field names here are **snake_case**.

| Method | Path | Notes |
|---|---|---|
| POST | `/register` | Body `{name, email, phone, password, password_confirmation, company?, accept_terms, captcha_token}`. 201 with the same body as `/me`, and the person is signed in. 5 per IP per hour. |
| POST | `/login` | Body `{email, password, remember}`. Returns the `/me` body. |
| POST | `/logout` | 204. Signs out the client guard only; the session is kept (an admin may be using it). |
| GET | `/me` | The signed-in client (below). |
| PUT | `/current-account` | Body `{account_id}`. Remembers which account the panel opens on. 403 `account_access_denied` if the person is not an active member of it. |
| POST | `/email/verify` | Body `{id, hash, expires, signature}` from the emailed link. No sign-in needed. |
| POST | `/email/verification-notification` | Sign-in needed. Sends the link again: 1 a minute, 6 an hour (429 after that). |

`/me` (and register, login, current-account):

```json
{ "data": {
  "user": { "id": "74", "name": "Rahim Uddin", "email": "rahim@uddintraders.com", "phone": "+8801711223344",
            "email_verified": false, "phone_verified": false, "avatar_url": null },
  "accounts": [ { "id": "61", "name": "Uddin Traders", "role": "owner", "permissions": ["*"], "is_owner": true },
                { "id": "9", "name": "Karim Traders", "role": "member", "permissions": ["invoices.view"], "is_owner": false } ],
  "current_account_id": "61",
  "impersonation": null
} }
```

- `permissions` is `["*"]` for an owner (everything), and the stored list for a member. Only **active** memberships of accounts that still exist are listed.
- `impersonation` is `{ "admin_name": "..." }` while a staff member is signed in as this client, otherwise `null`.
- `current_account_id`: the last-used account if it is still usable, otherwise the one the person owns, otherwise `null`.

Register rules: the email must be valid, unused (deleted people count) and not a throwaway service (`config/disposable_domains.php`; subdomains count too). The phone must be a Bangladeshi mobile in any of `01XXXXXXXXX`, `8801XXXXXXXXX` or `+8801XXXXXXXXX` and is stored as `+8801XXXXXXXXX`. The password needs 10+ characters, upper and lower case, a number, and is checked against known breaches (staff need 12). `accept_terms` must be true. `captcha_token` is a Cloudflare Turnstile token, checked unless `TURNSTILE_SECRET` is empty. The user, their account (named after the company, or the person) and the owner membership are created in one transaction; the verification email is queued.

Verification link: `{CLIENT_APP_URL}/verify-email?id=..&hash=..&expires=..&signature=..` (valid 60 minutes, changes with the email address). Codes: 422 `invalid_verification_link` (edited or for an old address), 422 `verification_link_expired`. Verifying twice answers success.

Login: same rules as the admin (generic 422 `invalid_credentials`; 5 failures per email + IP lock for 15 minutes, 429 `account_locked` with `retry_after_minutes`; 403 `account_suspended`). Every attempt goes to `client_login_histories`. The first sign-in from a browser + system the person has not used before queues a "New sign-in to your EverDigitech account" email (time, device, IP; the place is "Unknown location" until a GeoIP lookup is bound to `App\Services\GeoIp\GeoIpLookup`).

Middleware for the routes that come next (all in `bootstrap/app.php`):

| Alias | What it does |
|---|---|
| `auth:client`, `client.active` | A signed-in client; a suspended one is signed out (403 `account_suspended`). |
| `client.verified` | 403 `email_not_verified` until the email is verified. For ordering, payments and tickets. |
| `account.context` | Picks the account: the `X-Account-Id` header, else the last-used one, else the one they own; it must be an **active** membership, else 403 `account_access_denied`. Then use `$request->currentAccount()` and `$request->currentMembership()`. |
| `account.permission:invoices.view` | The owner passes; a member needs the permission(s) (403 `account_permission_denied`). |
| `account.owner` | Owner only (403 `account_owner_only`). |

Order on a route: `auth:client`, `client.active`, `account.context`, then `account.permission` or `account.owner`, and `client.verified` where needed.

### Account security (`/api/client`)

Fields are snake_case. Errors use the usual `{message, code, errors?}` shape. Routes marked 🔒 need a signed-in client.

| Method and path | What it does |
|---|---|
| `POST /forgot-password` `{email}` | Always 200 "If that email belongs to an account, we have sent a password reset link." Limit: 3 per email per hour (unknown emails count too) and 10 a minute per IP, then 429 `too_many_requests`. The link is `{CLIENT_APP_URL}/reset-password?token=..&email=..`, valid 60 minutes, separate token table from staff. |
| `POST /reset-password` `{token, email, password, password_confirmation}` | Sets the password, signs out **all** sessions, clears failed attempts and the lock, emails "password was changed". Does not sign in. Bad/expired/used token or unknown email: 422 `invalid_reset_token`. |
| 🔒 `PUT /password` `{current_password, password, password_confirmation}` | Wrong current password: 422 on `current_password`. Signs out the other devices (this one stays), emails the same notice. Answer has `signed_out`. 5 tries a minute. |
| 🔒 `PUT /profile` `{name, phone, avatar?, remove_avatar?}` | Returns the `/me` shape. A new phone number clears `phone_verified`. Avatar: jpg/png/webp, max 2 MB, max 8000 px, stored as 256x256 on the public disk. For a file send multipart: `POST /profile` with `_method=PUT`. |
| 🔒 `POST /email/change` `{new_email, password}` | Stores a pending change and mails a signed link (60 min) to the **new** address. The current email stays active. 3 an hour. A new request replaces the old one. |
| `POST /email/change/confirm` `{id, hash, expires, signature}` | From the link; needs no sign-in. Switches the email, marks it verified, tells the **old** address. 422 `invalid_email_change_link`, `email_change_link_expired` or `email_already_taken`. |
| 🔒 `GET /sessions` | `data[]`: `id, device, browser, platform, ip_address, last_active_at, is_current`. This device first, then newest. Only devices active within the session lifetime. |
| 🔒 `DELETE /sessions/others` `{password}` | Signs out every other device. Answer: `message` and `signed_out`. 5 tries a minute. |
| 🔒 `GET /login-history` | Last 90 days, newest first, 20 per page: `id, ip_address, browser, platform, successful, failure_reason, impersonated, created_at`. |

Notes:
- Devices come from the `client_sessions` table, written by the `client.session` middleware (at most once a minute per browser) and at sign-in and registration.
- Signing out another device destroys its whole Laravel session. If staff are signed in to the admin panel in that same browser, they are signed out too.
- Sessions that last made a request before this feature existed have no row until their next request.
- All emails are queued; run `php artisan queue:work`. Locally `MAIL_MAILER=log` writes them to `storage/logs/laravel.log`.

### Continue with Google (`/api/client`)

`POST /api/client/google` `{credential}`: signs in, or signs up, with a Google account. `credential` is the ID token that Google Identity Services gives the browser. Needs `GOOGLE_CLIENT_ID` in `.env` (the same id as `NEXT_PUBLIC_GOOGLE_CLIENT_ID` in the client panel; add the panel origin under "Authorized JavaScript origins" in Google Cloud Console). 10 a minute.

- The API asks Google (`oauth2.googleapis.com/tokeninfo`) to check the token, then requires: audience = our client id, issuer = Google, not expired, email verified by Google.
- Known Google id: signs in (answer 200, the `/me` shape). Known email without a Google link: the Google account is linked, the password stays. If that email was never confirmed here, the password is replaced and all sessions are signed out first, so nobody keeps access to an address they only typed into the sign-up form.
- Unknown: a client and an owned account are created (answer 201), email already verified, no phone, random unknown password ("Forgot password" lets the person set one).
- Suspended: 403 `account_suspended`. Deleted: 403 `account_closed`. Email linked to another Google account: 409 `google_account_mismatch`. Bad token: 422 `invalid_google_token`. Google unreachable: 502 `google_unreachable` (PHP needs a working `curl.cainfo`). No client id configured: 503 `google_not_configured`.
- A new-device email is sent for an unknown browser, as with a password sign-in. The login history and device list work the same.

### Products and pricing (the catalog)

What the company sells. A **group** is a section (Web Hosting, VPS Servers, Domain Registration...) with a `type` (`hosting, server, domain, sms, software, service`) that later steps use to decide how an order is set up. A **product** is one plan in a group, with a price for each **billing cycle** it is sold with: `monthly, quarterly, semiannually, annually, biennially, triennially, one_time`. A price is for the whole cycle (the yearly price is the price of the year).

**Money is always a whole number of paisa** (100 paisa = 1 taka): `89900` is ৳899. Never a float.

**Tax.** `TAX_RATE` (percent, default 15), `TAX_LABEL` (default VAT) and `PRICES_INCLUDE_TAX` (default `false`) in `.env`. With `false` the prices entered in the catalog are before tax and tax is added on top; with `true` they already contain it. The server always works out `{net, tax, total}` (`App\Services\Pricing`); pages never do tax sums. Rounding is half a paisa up, and `net + tax = total` always.

**Admin** (`/api/admin`, camelCase). `products.view` reads, `products.create` adds, `products.edit` changes, `products.delete` removes. Super Admin and Admin have all four; Sales can only read. Screens: `/products` and `/products/groups`.

| Method and path | What it does |
|---|---|
| `GET /product-groups` | `data[]`: `id, name, slug, type, typeLabel, description, sortOrder, isActive, productsCount`. All, hidden too, in order. |
| `POST /product-groups` `{name, type, description?, sortOrder?, isActive?}` | 201. The slug is made from the name; a rename keeps it. |
| `PUT /product-groups/{id}` | Change any of them. |
| `DELETE /product-groups/{id}` | Only an empty group: 422 `group_in_use` (deleted products count: old orders point at them). |
| `GET /products?group=<id>&status=all\|active\|inactive&search=&page=` | 20 per page. `data[]`: `id, groupId, groupName, name, slug, tagline, description, features[], setupFee, isActive, isFeatured, sortOrder, prices[] {cycle, label, months, amount}` (shortest cycle first). `meta.counts` = `all, active, inactive`, following group and search but not status. |
| `GET /products/options` | `groups`, `cycles` (`value, label, months`), `types`, `currency`, `tax` for the product form. |
| `GET /products/{id}` | One product. 404 when deleted. |
| `POST /products` `{groupId, name, tagline?, description?, features?[], setupFee?, isActive?, isFeatured?, sortOrder?, prices?[{cycle, amount}]}` | 201. A product that is on needs at least one price (422 on `prices`). The name is unique in its group. Amounts are whole paisa, at least 1, one price per cycle. Up to 20 feature lines of 120 characters. |
| `PUT /products/{id}` | Send only what changes. **`prices`, when sent, replaces the prices**: cycles left out are removed. |
| `DELETE /products/{id}` | Soft delete: leaves the catalog, stays in the database for old orders. Its name and slug are not reused by mistake (the slug stays taken). |

**Public** `GET /api/catalog` (no sign-in, 60 a minute, optional `?group=<slug>`): `data` = `currency`, `tax {label, rate, inclusive}` and `groups[]` with `products[]` (`id, name, slug, tagline, description, features[], featured, setupFee {net, tax, total}, prices[] {cycle, label, months, net, tax, total}`). Hidden groups, products that are off or deleted, products with no price, and groups with nothing to show are left out. This is what the website and the order form read.

Seed the sections once with `php artisan db:seed --class=ProductGroupSeeder` (groups only, no prices). Activity log: `product_group.created/updated/deleted`, `product.created/updated/deleted`.

### Support tickets

A ticket belongs to an **account** and is a thread: the first message is the first reply. Status: `open` (waiting for support), `answered` (support replied), `closed`. A client reply opens it again. The number is `TKT-` plus the id, six digits (`TKT-000123`). Departments are rows in `ticket_departments` (seed: `php artisan db:seed --class=TicketDepartmentSeeder`).

Files: up to 3 per message, 5 MB each, types `jpg, jpeg, png, webp, pdf, txt, log, zip`. They are stored on the **private** disk under a random name and are only served by the endpoints below, as a download (`Content-Disposition: attachment`, `application/octet-stream`, `nosniff`), after a permission check. Send files as multipart (`attachments[]`). Messages are plain text, up to 10,000 characters: show them as text, never as HTML.

**Client** (`/api/client`, current account, snake_case). `tickets.view` reads, `tickets.create` opens, replies and closes (and needs a confirmed email to open). Opening, replying and closing are refused with 403 `not_allowed_while_impersonating` while staff are signed in as the client. Another account's ticket is 404.

| Method and path | What it does |
|---|---|
| 🔒 `GET /tickets?status=all\|open\|answered\|closed&page=` | 15 per page, latest activity first. `meta.counts` has `all, open, answered, closed` and `unread`. Each row has `unread`: support replied and this person has not opened the ticket since (each person of the account has their own place; staff signed in as the client do not change it). |
| 🔒 `GET /ticket-departments` | `data[]`: `id, name, description` of the active departments. |
| 🔒 `POST /tickets` `{subject, department_id, priority?, message, attachments[]?}` | 201 with the thread. Priority `low`, `medium` (default), `high`. 10 an hour. |
| 🔒 `GET /tickets/feed?after=<cursor>` | Polled every 20 seconds by the client dashboard. Without `after`: `{cursor, unread, events: []}`, the starting point. With it: `events[]` (`replyId, ticketId, number, subject, staffName` (first name), `createdAt`) for support replies of this account since the cursor, oldest first, 20 at most, never staff notes or message text. `unread` is the number of tickets with a support reply this person has not opened. 422 for a bad cursor. 120 a minute. |
| 🔒 `GET /tickets/{id}` | Opening it marks the support replies as read for this person. The thread: `replies[]` (`id, author_type, author_name, body, created_at, attachments[]`). Staff are shown by first name. Internal notes are never included. |
| 🔒 `POST /tickets/{id}/replies` `{message, attachments[]?}` | Adds a reply and opens the ticket again. 30 an hour. |
| 🔒 `POST /tickets/{id}/close` | Closes it (closing twice changes nothing). |
| 🔒 `GET /tickets/{id}/attachments/{attachment}` | Downloads a file of the thread (never one of a staff note). |

**Staff** (`/api/admin`, camelCase, answers wrapped in `data`). `tickets.view` reads, `tickets.edit` replies and changes, `tickets.assign` assigns. Super Admin and Admin have all of them.

| Method and path | What it does |
|---|---|
| `GET /tickets?status=&priority=&department=&assigned=me\|unassigned\|<staff id>&search=&sort=newest\|oldest&page=` | 20 per page. Row: `id, number, subject, status, priority, department, client{accountId, accountName, openedBy{name,email}}, assignedTo, lastReplyBy, lastReplyAt, createdAt, closedAt`. `meta.counts` follows every filter except the status. `search` matches the subject, or the number (`TKT-000123`, `123`). |
| `GET /tickets/options` | `departments[]` and `assignees[]` (staff who finished sign-up) for the filters and the assign box. |
| `GET /tickets/{id}` | The row plus `replies[]` (`id, authorType, authorName, body, isInternal, createdAt, attachments[]`), staff notes included. |
| `POST /tickets/{id}/replies` `{message, internal?, attachments[]?}` | A reply: status becomes `answered` and the client gets a queued email. With `internal: true` it is a note: nobody is told and the status stays. |
| `PUT /tickets/{id}` `{status?, priority?, departmentId?}` | Change them. `closed` sets `closedAt`; another status clears it. |
| `POST /tickets/{id}/assign` `{adminId: id \| null}` | Assigns to an active staff member, or takes it back. |
| `GET /tickets/{id}/attachments/{attachment}` | Downloads any file of the thread. |

**Saved replies (staff).** Answers staff give again and again, shared by everyone who replies to tickets. All routes need `tickets.edit`. The body may contain `{client_name}`, `{ticket_number}` and `{staff_name}`: the admin panel fills them in when a reply is inserted into a ticket ("Insert a saved reply" above the reply box). Page: `/tickets/replies`.

| Method and path | What it does |
|---|---|
| `GET /canned-replies` | `data[]`: `id, title, body, createdBy, updatedAt`, by title. |
| `POST /canned-replies` `{title, body}` | 201. The title must be unique (100 characters), the body up to 10,000. |
| `PUT /canned-replies/{id}` `{title?, body?}` | Change one. |
| `DELETE /canned-replies/{id}` | Delete one. Replies already sent are not changed. |

**Live updates and email for staff.**

| What | How |
|---|---|
| `GET /api/admin/tickets/feed?after=<cursor>` | Polled every 20 seconds by the admin panel (only while the tab is visible). Without `after`: `{cursor, waiting, events: []}`, the starting point. With it: `events[]` (`replyId, ticketId, number, subject, kind` = `new_ticket` or `client_reply`, `priority, accountName, authorName, createdAt`), oldest first, 20 at most, and the new `cursor`. `waiting` is the number of tickets with status `open` (the badge on the menu). 422 for a bad cursor. 120 a minute. |
| Email to the support mailbox | Every new ticket and every client reply is emailed (queued) to `SUPPORT_EMAIL` (default `support@everdigitech.com`), with a link to the ticket in the admin panel. Forward that mailbox to the staff in cPanel (Email > Forwarders). Staff replies and internal notes do not send it. |
| Email to the client | A staff reply is sent **from** `SUPPORT_EMAIL` with `Reply-To` the same address. The SMTP login must be allowed to send as that address. |

**Departments (staff).** `tickets.view` reads, `tickets.edit` adds, changes, switches on or off and orders, `tickets.delete` deletes. Clients only see departments that are on (`isActive`), in `sortOrder`.

| Method and path | What it does |
|---|---|
| `GET /ticket-departments` | `data[]`: `id, name, description, isActive, sortOrder, ticketsCount`. All of them, off ones too. |
| `POST /ticket-departments` `{name, description?, isActive?}` | 201. Goes to the end of the list. The name must be unique (letter case ignored). |
| `PUT /ticket-departments/{id}` `{name?, description?, isActive?}` | 422 `last_active_department` when it would switch off the last one that is on. |
| `DELETE /ticket-departments/{id}` | Only a department no ticket uses: 422 `department_in_use` otherwise (switch it off instead). 422 `last_active_department` for the last one that is on. |
| `POST /ticket-departments/reorder` `{ids: [every id in the new order]}` | Answers the list. Every department must appear exactly once. |

Activity log: `ticket.opened`, `ticket.client_replied`, `ticket.staff_replied`, `ticket.note_added`, `ticket.closed`, `ticket.updated`, `ticket.assigned`, `ticket_department.created`, `.updated`, `.deleted`, `.reordered`. Not built yet: canned replies, email replies, and an email to staff when a client writes.

### Team (`/api/client`)

The people of an account. The owner invites people by email and chooses what each may do. The account is the current one (header `X-Account-Id`, else the last used, else the one the person owns). Everything is for the **owner** (403 `account_owner_only` for members), except accepting and leaving. The mutations are refused with 403 `not_allowed_while_impersonating` while staff are signed in as the client.

| Method and path | What it does |
|---|---|
| 🔒 `GET /team` | `data`: `members` (owner first; `id, name, email, avatar_url, role, status, permissions, joined_at, is_you`), open `invitations` (`id, email, permissions, invited_by, created_at, expires_at`), `permissions` (the groups and keys the owner can give) and `limits` (`max_people` 10 counting open invitations, `invitation_days` 7). |
| 🔒 `POST /team/invitations` `{email, permissions[]}` | Needs a confirmed email of the owner. Answers 201 with the invitation and sends a queued email to that address: `{CLIENT_APP_URL}/accept-invite?token=..` (64 characters, stored hashed, 7 days, one use). A new invitation to the same address replaces the old one. Errors: 422 `already_member`, `team_full`, `too_many_invitations`; validation for yourself, throwaway addresses and unknown permissions. 20 an hour. |
| 🔒 `POST /team/invitations/{id}/resend` | A new link and 7 more days; the old link stops working. |
| 🔒 `DELETE /team/invitations/{id}` | Cancels it. |
| 🔒 `PUT /team/members/{id}` `{permissions?, status?}` | Change permissions (at least one), or `status` `active` / `suspended`. A suspended member loses the account at once. 403 `owner_cannot_be_changed` for the owner. |
| 🔒 `DELETE /team/members/{id}` | Removes the member; access ends at once. |
| 🔒 `POST /team/leave` | A member leaves the current account (403 `owner_cannot_leave`). Answers the `/me` shape. |
| `GET /team/invitation?token=` | Public. Shows `account_name`, `invited_by`, `email_hint` (masked, `nu***@x.com`), `expires_at`. 422 `invalid_invitation` for an unknown, used or expired token. |
| 🔒 `POST /team/invitations/accept` `{token}` | The signed-in person joins. Their email must be the invited one (403 `invitation_email_mismatch`, the address masked) and confirmed (403 `email_not_verified`). Answers the `/me` shape, with the new account as `current_account_id`. |

Permissions a member can have (`app/Support/AccountPermissionCatalog.php`): `orders.view`, `orders.create`, `invoices.view`, `invoices.pay`, `hosting.view`, `hosting.manage`, `domains.view`, `domains.manage`, `tickets.view`, `tickets.create`. The owner has all of them. Routes check them with the `account.permission:<key>` middleware. Managing the team, billing details and closing the account are owner-only (`account.owner`).

Activity log: `team.invitation_sent`, `team.invitation_accepted`, `team.invitation_cancelled`, `team.member_updated`, `team.member_removed`, `team.member_left`.

**Tax (VAT) setting.** `GET /api/admin/settings/tax` (`settings.view`) and `PUT /api/admin/settings/tax` `{label, rate, inclusive}` (`settings.edit`; rate is a percent 0 to 100, at most 2 decimals). Saved in the `settings` table; until someone saves it, `TAX_LABEL`, `TAX_RATE` and `PRICES_INCLUDE_TAX` from `.env` apply. It changes what the catalog and later invoices work out, not the prices entered. Logged as `settings.tax_updated`.

**Product page details.** A product also has `featureGroups` (`[{title, items[]}]`, up to 8 sections such as Primary, Server, Security, Support, shown under the plan on the pricing page) and each price an optional `compareAmount` (paisa): the regular price shown struck through. `prices.*.compareAmount` must be higher than `amount`. `GET /api/catalog` returns `featureGroups` and, per price, `compare` as `{net, tax, total}` (or `null`).

### Client detail (admin)

`GET /api/admin/clients/{id}` (`clients.view`; `id` is the account id the list shows): `name, company, group, status, notes, creditBalance, joinedOn`, `owner` (`name, email, phone, emailVerified, phoneVerified, twoFactorOn, suspended, lastLoginAt`), `members[]` (owner first: `id, userId, name, email, role, status, joinedAt, lastLoginAt`), `stats` (`services, balanceDue, overdue` are still the placeholder columns; `domains, domainsEndingSoon, tickets, ticketsOpen` are real), `recentTickets` (5), `recentDomains` (5, ending soonest first), `recentLogins` (the last 10 sign-ins of everyone on the team, `impersonated` = by staff). `PUT /api/admin/clients/{id}` (`clients.edit`) `{name?, company?, group?, status?, notes?}`: only the fields sent change; logged as `client.updated`. 404 `not_found`.

### Domains

**Public (website).** No sign-in.

| Method and path | What it does |
|---|---|
| `GET /api/domains/extensions` | `{tax, extensions: [{extension, tag, featured, register, renew, transfer}]}`; each price is `{net, tax, total}` or `null` (not set: show "Ask for price"). Only extensions that are switched on. |
| `GET /api/domains/search?domains=a.com,a.net` | Up to 10 names. `results: [{domain, available, extension, price, renewal}]`. Only extensions we sell are checked; 422 `validation_failed` for an empty list, a bad name or no extension we sell. 30 a minute. 503 `registrar_not_configured` (no `DYNADOT_API_KEY`), 502 `registrar_unavailable` (Dynadot unreachable, key wrong, or this server's IP is not allowed in Dynadot's API settings). Availability is never guessed. |

The registrar sits behind the `App\Services\Registrar\Registrar` interface (Dynadot today). Dynadot answers one domain per request and one request at a time per account, so the domains are checked one after the other, retried when it is busy, and each answer is kept for two minutes. The key stays on the server.

**Admin** (`domains.view` reads, `domains.create` adds a domain, `domains.edit` changes domains and extension prices, `domains.delete` removes).

| Method and path | What it does |
|---|---|
| `GET /api/admin/domain-extensions` | Paginated (50). Params: `page`, `status` (`on`/`off`/`no_price`/`popular`), `search`. `meta.counts` has the tab counts. Each row: `id, extension, tag, registerPrice, renewPrice, transferPrice` (typed, paisa, `null` = none), `effectiveRegisterPrice/RenewPrice/TransferPrice` (typed, else worked out by the pricing rule), `priceSource` (`manual`/`auto`/`null`), `costRegister/Renew/Transfer` (US cents from Dynadot), `usage`, `restrictions`, `isFeatured, isActive, sortOrder`. |
| `POST /api/admin/domain-extensions/sync` | domains.edit, 6/min. Pulls Dynadot's whole price list (about 800 extensions). New ones are added switched off; existing ones only get cost/usage/restrictions refreshed. Returns `{added, updated, total}`. `.com.bd` is not in Dynadot's list: add it by hand. |
| `POST /api/admin/domain-extensions/activate-priced` | domains.edit. Switches on every extension that has a price (typed or worked out) and no restriction. Returns `{activated}`. |
| `GET` / `PUT /api/admin/domain-pricing` | Pricing rule `{usdRate, marginPercent, roundTo}`: price = cost x usdRate x (1 + margin/100), rounded UP to a multiple of `roundTo` taka. A typed price wins. `usdRate` is `null` until set. |
| `POST /api/admin/domain-extensions` | `{extension, tag?, registerPrice?, renewPrice?, transferPrice?, isFeatured?, isActive?, sortOrder?}`. The dot is added when missing. |
| `PUT /api/admin/domain-extensions/{id}` | Only the fields sent change; a price sent as `null` is cleared. |
| `DELETE /api/admin/domain-extensions/{id}` | |
| `GET /api/admin/domains?status=&search=&page=` | `status`: `all, active, pending, expired, cancelled, expiring` (active and ends within 30 days). `meta.counts` has every one. 15 a page, ending soonest first. |
| `GET /api/admin/domains/{id}` | With `daysLeft` and `events` (the history). |
| `POST /api/admin/domains` | A domain bought elsewhere or by hand: `{accountId, name, registrar?, status?, registeredAt?, expiresAt?, autoRenew?, locked?, whoisPrivacy?, nameservers?[], notes?}`. |
| `PUT /api/admin/domains/{id}` / `DELETE` | Only the fields sent change; every change is written to the history. Delete only removes it from the list. |

Not built yet (needs orders and payments): registering, renewing and transferring through the website, the client's own domain list, expiry reminders. Seed the starting extensions once: `php artisan db:seed --class=DomainExtensionSeeder`.

### Two-factor authentication (`/api/client`)

An authenticator app (TOTP: 6 digits, 30 seconds). A code is accepted one step before or after now, and never twice.

| Method and path | What it does |
|---|---|
| 🔒 `GET /two-factor` | `{enabled, recovery_codes_left}`. `/me` also has `user.two_factor_enabled`. |
| 🔒 `POST /two-factor/setup` `{password}` | A new secret: `{secret, otpauth_url}` (show it as a QR code). It is not on yet. 422 `two_factor_already_on`. |
| 🔒 `POST /two-factor/confirm` `{code}` | The code from the app turns it on and answers `{recovery_codes: [8 codes]}`, shown once (only SHA-256 hashes are kept). Emails "turned on". 422 `invalid_two_factor_code`, `two_factor_not_started`. |
| 🔒 `POST /two-factor/recovery-codes` `{password}` | New codes; the old ones stop working. 422 `two_factor_not_on`. |
| 🔒 `DELETE /two-factor` `{password, code}` | Turns it off. `code` is one from the app or a recovery code. Emails "turned off". |
| `POST /two-factor-challenge` `{code}` or `{recovery_code}` | Second step of a sign-in. |

Sign-in with it on: `POST /login` with the right password answers **202** `{two_factor_required: true}` and nobody is signed in. In the same session, `POST /two-factor-challenge` within 5 minutes signs in and answers the `/me` shape (a recovery code works once). 5 wrong codes, or 5 minutes, end the attempt (422 `two_factor_expired`: start again from the password). Wrong codes are written to the login history as `two_factor_failed`. The routes that change it are blocked while staff are signed in as the client (`not_allowed_while_impersonating`). "Continue with Google" does not ask for the code: Google has already checked the person.

### Log in as client (impersonation)

| Method and path | What it does |
|---|---|
| `POST /api/admin/clients/{account}/impersonate` `{client_user_id?}` | Needs `clients.login_as` (Super Admin and Admin have it). Signs in as the account owner, or as the given active member. Answer: `{ "url": "{CLIENT_APP_URL}/impersonate?token=..." }`. The token is 64 random characters, stored only as a SHA-256 hash, valid 60 seconds, one use. 422 `account_closed`, `not_a_member`, `client_suspended`; 404 for an unknown account. |
| `POST /api/client/impersonate/consume` `{token}` | Public. Trades the token for a client session and answers with the `/me` shape. 422 `invalid_impersonation_token` (unknown, used or expired) or `account_closed`. 10 a minute. |
| 🔒 `POST /api/client/impersonate/exit` | Signs out the client guard only; a staff sign-in in the same browser stays. 403 `not_impersonating` for a normal client. |

- The session holds `impersonated_by_admin_id` and `impersonation_started_at` and ends after **30 minutes**: the next request answers 401 `impersonation_expired`.
- `GET /me` has `impersonation: { admin_name, started_at, expires_at }` (null for a normal sign-in), for the banner.
- The login history row has `impersonated_by_admin_id` (`impersonated: true` in `GET /login-history`). No new-device email is sent, `last_login_*` is not touched, and the session is not listed under the devices of the client.
- Route middleware `client.not_impersonating` answers 403 `not_allowed_while_impersonating`. It is on: change password, request email change, sign out other devices. Put it on every future route for 2FA, payment methods, closing the account, and inviting or removing team members. Changing the avatar and profile is allowed.
- Activity log (`activity_logs`): `client.impersonation_started` and `client.impersonation_ended` (actor: the admin, subject: the client user, properties: `admin_id`, `admin_name`, `client_user_id`, `client_email`, `account_id`, and `reason` = `exit`, `logout` or `expired` on the end), with the IP.
- Sanctum's `AuthenticateSession` is replaced by `App\Http\Middleware\AuthenticateGuardSessions` (`config/sanctum.php`): it checks each guard against its own user. The original compared the admin password hash with the client one, which signed out a browser that had both panels open.

## 9. Health

`GET /api/health` → `{ "status": "ok" }` (no `data` wrapper, no login needed).

## 10. Frontend status

- Connected and checked in a real browser: sign-in/out, `/me`, the permission-filtered sidebar, Roles (load, save, create, delete) and Clients (tabs, search, filters, paging).
- The admin's login page maps the API's `account_locked` (429) to its "locked" banner and `account_suspended` (403) to its "suspended" banner.
- Still mocked in the admin (`NEXT_PUBLIC_USE_MOCK=dashboard`): the dashboard, because `GET /api/admin/dashboard/summary` isn't built yet.
- The Staff page (`/staff`) is built on this API: stat cards, status tabs with counts, role filter, search, add/edit dialog, resend invite, suspend/reactivate/remove with confirmations. Types: `everdigitechadmin/types/staff.ts`.
- The password pages are built: `/forgot-password` (asks for the email, always answers "Check your email") and `/reset-password?token=...&email=...` (set a new password; with `&invite=1` it reads "Set your password" for a new staff member). A used or expired link shows "This link doesn't work".
- Not in the admin yet: the Settings page the two-factor card links to.
- The data-breach check on new passwords calls haveibeenpwned.com. If PHP can't reach it (for example a wrong `curl.cainfo` path in `php.ini`, as with the stock Laragon ini on a non-C: drive) Laravel lets the password through instead of failing, so check that `curl.cainfo` points to a real `cacert.pem` before relying on it.

## 11. Local demo accounts

`php artisan migrate:fresh --seed` with `APP_ENV=local` also creates one admin per default role (password `Demo-Password-123`):
`staff.admin@`, `staff.billing@`, `staff.support@` and `staff.sales@everdigitech.test`.
It also adds ten more people for the Staff page (`rakib@`, `tasnim@`, `shafiq@`, `mitu@`, `jahid@`, `sumaiya@`, `nadia@`, `arman@`, `imran@` (suspended) and `rezaul@` (invited)`everdigitech.test`), some with two-factor on and a recent sign-in.
