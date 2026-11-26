# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Release Overview

* **New Features**
  * **Reseller API keys (AB-2708)** - A reseller's API key can be used for shared hosting products. The module finds out the key type from the panel's first answer (the admin API refuses a reseller's key before doing anything) and then uses the reseller API, so the **Hosting Plan** list shows the reseller's own plans and **Test Connection**, **Create**, **Suspend**, **Unsuspend**, **Change Package**, **Change Password**, **Terminate**, the login button and the usage update work on the reseller's own hosting accounts. Reseller hosting products and the admin login button of the server still need an administrator API key; with a reseller's key they stop with a message saying so and nothing is changed. With an administrator API key every command calls the same admin API endpoints as before; only **Change Package** adds one read, `GET /api/hosting-plans` (and a read of the account when the panel's answer has no plan; described below). A key that is neither an administrator nor a reseller key fails **Test Connection** with a message saying so.

* **Bug Fixes**
  * **Hosting plan by name (AB-2742)** - Shared hosting products store the plan name instead of the plan ID of one server, and **Create** and **Change Package** send the name (`hosting_plan`). Plan IDs are local to each panel, so before this a product placed in a server group with a second AdminBolt server failed on that server with `Hosting plan not found` or created the account on whichever plan had that ID there. Now each server uses its own plan with the product's plan name. When a server has no such plan, or two of them, **Create** and **Change Package** stop with the panel's reason (`Hosting plan not found: <name>` or `Hosting plan name is ambiguous, use hosting_plan_id: <name>`) and nothing is created or changed. After **Change Package** the module checks the account's plan from the panel's answer (reading the account again when the answer has no plan) against the plan list (`GET /api/hosting-plans`, which **Test Connection** already uses) and fails when the account is not on a plan of that name. The **Hosting Plan** dropdown lists the administrator's own plans (or the reseller's plans for a reseller API key), marks a name that two plans share, and keeps the product's saved setting selectable, so saving the product page never switches it to another plan. Requires AdminBolt 1.8.2 or newer on every server: an older panel stops **Create** with a message naming the version and nothing is created, and **Change Package** fails with a message after the panel ignored the plan name.

### Upgrade notes

* **Re-save every AdminBolt shared hosting product (AB-2742).** Products saved with module 0.0.5 or older store a plan ID (digits only) in the **Hosting Plan** setting. The module does not send it: the same ID can be another plan on another server, and which server it was read from is not recorded. Until the product is saved again, **Create** and **Change Package** for it stop with `This product's Hosting Plan setting still holds the plan ID <id> saved by AdminBolt module 0.0.5 or older ...` and nothing is created or changed in the panel; services that already exist keep working for every other command. For each product: open **Module Settings**, choose the plan in the **Hosting Plan** dropdown (the old setting is listed first as `Plan ID <id> saved by module 0.0.5 or older`, with the name that ID has on the listed server), and save. A plan chosen with this module is stored as `name:` followed by the URL-encoded plan name (`name:Gold%20Plus` for "Gold Plus"), so a plan named `3` is never taken for a plan ID and WHMCS never stores the setting with HTML entities.
* A product whose 0.0.5 setting points at a reseller's plan cannot keep it: the dropdown with an administrator API key and the panel's name lookup offer the administrator's own plans only. Choose an administrator plan, or use that reseller's API key on the server (AB-2708) to offer the reseller's plans.
* Make sure every AdminBolt server of a product's server group has exactly one plan with the product's plan name (the administrator's plan, or the reseller's plan for a reseller API key), and runs AdminBolt 1.8.2 or newer.

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
