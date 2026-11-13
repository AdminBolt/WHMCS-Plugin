# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

**Upgrade note:** before upgrading, list the reseller services whose Reseller ID field is empty (Clients > Products/Services, reseller products, custom field Reseller ID) and enter the panel's reseller ID in each. From this version reseller commands no longer look the reseller up, so on such a service every command fails, including the automatic suspension of overdue services. Shared hosting services with an empty Hosting Account ID are found only by an exact domain and username match; fill the field for any service whose panel account has a different domain or username. The username of every shared and reseller service in WHMCS must also equal the username of its account or reseller in the panel: otherwise Change Package, Suspend, Unsuspend, Terminate, the login button and Change Password refuse and change nothing, which also stops the automatic suspension of overdue services. Such services are easy to spot: the refusal in WHMCS and in the Module Log names both usernames, for example: Hosting account ID 40 belongs to the panel account "u1234abcd", but this service's username is "oldshop"); correct the username in WHMCS or the ID field, then run the command again.

### Added

- Reseller products have module settings for maximum accounts, disk and bandwidth limits and the CloudLinux LVE limits, applied when the reseller is created and on upgrade or downgrade (a limit the panel refuses fails the command with the panel's reason); the client area and the admin area have a login button that opens the reseller panel (AB-2540).

### Changed

- A reseller limit setting must be a whole number or empty. Empty settings are not sent; any other value (for example "10240MB", or "on" kept from a shared product's settings) stops Create and Change Package before the panel is called, with a message naming the setting. After switching a product's type to reseller, save the product and check the settings, because values from the previous type are reused by position.
- Panel errors now carry the panel's reason for every command, shared hosting included: the error reads "API error: <panel message>" (the panel's `message` field was not read before), or "API error (HTTP <code>)" when the panel gives no reason, instead of a bare "API error".

### Fixed

- The daily usage update no longer stops with a PHP fatal error (`UsageUpdate` method signature conflict with `AbstractAction`); an error on one service is logged in the Module Log and the remaining services are still updated (AB-2537).
- Terminate on a reseller product now deletes every hosting account of the reseller through the regular account deletion before deleting the reseller. It stops at the first account that cannot be deleted, keeps the reseller and names that account in the error and the Module Log, so Terminate can be run again (AB-2539).
- Suspend and Unsuspend on a reseller product now call the panel (`POST /api/resellers/{id}/suspend` with the WHMCS suspend reason, `POST /api/resellers/{id}/unsuspend`) and report the panel error in WHMCS and the Module Log instead of always returning success. Requires AdminBolt panel 1.9.6 or newer; older panels have no reseller suspension and the command fails with the panel's error (AB-2538).

### Security

- Reseller commands (Change Package, login, Suspend, Unsuspend, Terminate, Change Password) act only on the reseller ID stored in the service's Reseller ID field. Versions 0.0.2 and 0.0.3 filled an empty Reseller ID by searching all resellers in the panel for the service's username or the client's e-mail address, so a service whose reseller was never created (for example because the username was taken) could act on another customer's reseller: open its panel, change its limits, suspend it or terminate it with all its accounts. If you ran reseller commands on services with an empty Reseller ID, check the Module Log for calls to `/api/resellers/{id}` with an unexpected ID. A service without a stored reseller ID now fails with a clear message and the panel is not called. Create stores the ID from the panel's create response; when it cannot, the message says the reseller exists and must not be created again. Change Package, Suspend, Unsuspend, Terminate, the login button and Change Password also refuse when the panel reseller's username does not match the service username, so a mistyped Reseller ID cannot reach another reseller. Shared hosting services keep the recovery by domain or username.
- Shared hosting services with an empty Hosting Account ID no longer take the first panel account whose domain or username matches, which could be another customer's account (for example after Create failed because the username was taken). The ID is recovered only when exactly one panel account has both the service's domain and username; otherwise the command fails and nothing is changed or saved. Change Package, Suspend, Unsuspend, Terminate, the login button and Change Password of a shared account also refuse when the panel account's username differs from the service username, so a mistyped Hosting Account ID cannot open, change or delete another customer's account.
- Secrets are masked in the Module Log. Earlier versions logged the server's API key and API secret (in the module parameters of a failed command and in the request headers of every API call), the access hash, account passwords and the single-use panel login link. Now the parameters serverusername (API key), serverpassword (API secret), serveraccesshash and password, the X-API-Key and X-API-Secret header values, "password" fields in request and response bodies, the sso_url, token and sso_token fields and login links in "url" fields are replaced by asterisks; objects among the module parameters are not printed; values of these secrets that appear elsewhere in an entry are masked when they are at least 4 characters long. If your Module Log was ever shared, exported or kept by a third party, create a new API key in the panel, update the server in WHMCS and delete the old key.

## [0.0.3] - 2026-09-14

### Fixed

- `UsageUpdate::getHostingAccountId()` declared an `?int` return type but returned the raw `tblcustomfieldsvalues.value` string, causing `TypeError: Return value must be of type ?int, string returned` during the WHMCS `UpdateServerUsage` cron under PHP 8.2 whenever the custom field was empty or non-numeric. The value is now cast to `int` only when numeric, otherwise `null` is returned, so the cron completes successfully.

## [0.0.2] - 2026-06-11

### Fixed

- `CreateAccount` (shared) stored the hosting account ID from the wrong response key, leaving `hostingAccountId` empty and breaking every later action (SSO, suspend, change package, terminate). Now reads the ID from the root of the response and tolerates legacy wrapped shapes (`hostingAccount`, `reseller`, `data`).
- Phone number was built with `'+' . phonecc + phonenumber`. PHP's `+` operator coerces strings to numbers and drops the leading `+`, producing wrong values. Replaced with proper string concatenation in a `formatPhone()` helper.
- `ChangePassword` for resellers only sent `password`, which fails validation against the `Reseller` schema's required fields. Now fetches the existing reseller and re-sends `name`, `username`, `email` along with the new password.
- `ConfigOptions::getServer()` accessed `$_POST['servergroup']` directly, raising an undefined-index warning and querying with `null` when no group was selected.
- `ConfigOptions::createCustomField()` had the same unsafe `$_POST['id']` access.
- Hosting plans dropdown stayed empty when the response shape was wrapped or paginated. Parsing is now tolerant of bare arrays, `{hostingPlans: [...]}`, `{hosting_plans: [...]}`, Laravel paginators (`{data: [...], meta, links}`), nested wraps, and single-object responses. Unrecognized shapes are logged to the Module Log.

### Added

- Self-healing ID resolution in `AbstractAction`:
  - `getHostingAccountId()` falls back to `GET /api/hosting-accounts` and matches by `domain` or `username` when the custom field is empty.
  - `getResellerId()` falls back to `GET /api/resellers` and matches by `username` or `email`.
  - Recovered IDs are written back to `tblcustomfieldsvalues`, so subsequent calls no longer need the lookup.
- Clear exception messages when an ID cannot be resolved (e.g. `Hosting Account ID is not set on this service and no AdminBolt account was found (domain='…', username='…').`), shown in WHMCS UI and Module Log instead of `/api/hosting-accounts//generate-sso-token` 404s.
- `ConfigOptions::getServer()` now falls back to any active AdminBolt server when no server group has been selected yet, so the Hosting Plan dropdown populates on a fresh product page.
- `ConfigOptions::getServer()` filters by `tblservers.type = 'AdminBolt'` and `disabled = 0` to avoid picking the wrong server when groups mix providers.

### Changed

- `APIVersion` bumped from `0.0.1` to `0.0.2`.
- All provisioning actions (`ChangePackage`, `ChangePassword`, `SuspendAccount`, `UnsuspendAccount`, `TerminateAccount`, `ServiceSingleSignOn`) now obtain account/reseller IDs via the resolver instead of reading the custom field directly.
- Custom field writes are centralized in `AbstractAction::saveCustomFieldValue()` and also update the in-memory `$params['customfields']`, so later code in the same request sees the new value.

## [0.0.1]

- Initial release.
