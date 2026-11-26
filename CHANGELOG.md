# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Sell AdminBolt plugin features as configurable options. An option named `plugin:<plugin id>|<name>` (for example `plugin:matomo|Website statistics`) switches that plugin on for the service's account when it is bought and off when it is not, on create and on every package or option change, through `PUT /api/hosting-accounts/{id}/feature-overrides`. Products without such options send nothing new. See "Selling Plugin Features" in the README.

## [0.0.5] - 2026-11-20

### Release Overview

* **Bug Fixes**
  * **Reseller termination in the background (AB-2619)** - **Terminate** on a reseller product returns in a few seconds, however many hosting accounts the reseller has, instead of running until the WHMCS time limit. The module asks the panel once (`POST /api/resellers/{id}/terminate`); the panel blocks the reseller at once (no sign-in, no API keys, no new accounts) and deletes its hosting accounts, its plans and the reseller in a background job shown on the panel's **Background Jobs** page. The **Module Log** says the termination was queued, or already in progress when **Terminate** is run again meanwhile, and how many accounts are still to be deleted. An account the panel cannot delete keeps the reseller: the panel notifies its administrators and shows the reason on **Background Jobs**; running **Terminate** again continues with the accounts that are left and writes the failure and its reason to the **Module Log**. WHMCS marks the service Terminated as soon as the termination is queued, so a later failure is seen in the panel first; accounts deleted with unfinished cleanup steps are also reported to the panel's administrators. Panel calls now have time limits (15 seconds to connect, 240 seconds in all, 150 seconds for the terminate call), so a panel that does not answer fails the command with an error instead of holding it until the WHMCS time limit. Requires AdminBolt 1.9.7 or newer for the background termination; with an older panel the module deletes the accounts one by one as before.

## [0.0.4] - 2026-11-18

### Release Overview

* **New Features**
  * **Reseller products (AB-2540)** - Reseller products have module settings for the maximum number of accounts, disk and bandwidth limits and the CloudLinux LVE limits, applied when the reseller is created and on upgrade or downgrade. Each setting is a whole number or empty, and a limit the panel refuses stops the command with the panel's reason. After switching a product's type to reseller, save the product and check these settings. The client area and the admin area have a login button that opens the reseller panel. Reseller commands act on the reseller stored in the service's **Reseller ID** field, and a shared hosting service with an empty **Hosting Account ID** is matched to the one panel account that has both its domain and its username. **Change Package**, **Suspend**, **Unsuspend**, **Terminate**, the login button and **Change Password** go ahead when the panel username equals the service username, and otherwise stop with a message naming both. Before upgrading, enter the panel's reseller ID in every reseller service whose **Reseller ID** is empty, and make sure the username of every shared and reseller service equals its username in the panel, so these commands and the automatic suspension of overdue services keep working. The **Module Log** masks API keys and secrets, passwords and panel login links. After upgrading, create a new API key in the panel for each WHMCS server, update the server in WHMCS and delete the old key.
  * **Reseller suspension (AB-2538)** - **Suspend** and **Unsuspend** on a reseller product suspend and unsuspend the reseller in the panel together with its hosting accounts, pass the WHMCS suspend reason and report the panel's answer in WHMCS and the **Module Log**. Requires AdminBolt 1.9.6 or newer.

* **Bug Fixes**
  * **Daily usage update (AB-2537)** - The daily usage update goes through every service, and an error on one service is written to the **Module Log** while the others are still updated. Every command that fails shows the panel's reason: `API error: <panel message>`, or `API error (HTTP <code>)` when the panel gives none.
  * **Reseller termination (AB-2539)** - **Terminate** on a reseller product deletes every hosting account of the reseller, then the reseller. When an account cannot be deleted, the reseller stays and the error and the **Module Log** name that account, so **Terminate** can be run again.
  * **Usage update (AB-2585)** - The daily usage update asks the panel only about Active and Suspended services. Terminated, Cancelled, Pending and Fraud services have no panel account and no longer write `API error: Not found` to the **Module Log** on every run.
  * **Second reseller for a client (AB-2585)** - The panel keeps one reseller per e-mail address and **Create** sends the client's e-mail, so a client can have one reseller per AdminBolt server. **Create** of a second one now says so, names the e-mail and that nothing was created, instead of only `API error (HTTP 422): email: The email has already been taken.`

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
