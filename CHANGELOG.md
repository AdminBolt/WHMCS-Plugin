# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
