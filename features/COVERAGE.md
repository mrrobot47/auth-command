# auth-command Behat coverage

Base: `upstream/develop` (`234d740`). The wildcard auth work (auth-command #57, `feat/wildcard-auth`) isn't on develop and isn't covered here.

Features (scenario counts include expanded outline examples):

| File | Scenarios | Area |
|---|---|---|
| `auth-usage.feature` | 34 | synopsis, help, site resolution, dispatcher validation, disabled/unknown sites |
| `auth-site.feature` | 30 | site users: create/update/delete/list, HTTP effect |
| `auth-whitelist.feature` | 33 | site IP whitelists, `_acl` files, HTTP bypass |
| `auth-site-lifecycle.feature` | 6 | site delete/recreate/disable/enable, HTTPS |
| `auth-global.feature` (`@destructive`) | 31 | global users, global whitelist, merge into site files |
| `auth-admin-tools.feature` (`@destructive`) | 19 | admin-tools auth, AUTH-8, `ee admin-tools enable` interplay |
| **Total** | **153** | `@known-bug` 18, `@destructive` 50, `@admin-tools` 2, `@le` 0, `@remote` 0 |

Tags:
- `@destructive`: changes the global/admin-tools users or the global whitelist. The context snapshots `auth_users` (`default`, `default_admin_tools`) and `auth_ips` (`default`) before each such scenario and restores them afterwards with the same passwords, then regenerates htpasswd/`_acl` files and reloads the proxy.
- `@known-bug`: expected behaviour that fails today; the issue ID is in a comment on the scenario. IDs `AUTH-12`…`AUTH-18` are proposals for new entries in issues.md.
- `@admin-tools`: needs network access (admin-tools downloads phpMyAdmin etc.) and a PHP site.
- No `@le` or `@remote`: auth doesn't depend on Let's Encrypt (HTTPS is checked with `--ssl=self`) or backups.

Suggested runs: everything except known bugs `--tags='~@known-bug'`; the known bugs alone `--tags=@known-bug`.

Estimated runtime: about 30 minutes for the whole suite (≈17 site creates, 8 site disable/enable cycles, ≈400 `ee auth` calls including per-scenario resets, one admin-tools install); about 25 minutes with `~@known-bug&&~@admin-tools`.

## Environment and isolation

- Sites: `bh-auth-command-<n>.test` only (usage 13, site 1–2, whitelist 3–4, lifecycle 5–7, global 8–9, admin-tools 10–12). Background steps create a site only when it's missing and re-enable it when disabled, so scenarios share sites but stay independent.
- After every scenario, auth rows of `bh-auth-command-*` sites are removed through `ee auth delete` (falling back to direct cleanup for deleted/disabled sites). After every feature, only `bh-auth-command-*` sites are deleted, plus the files and rows known bugs leave behind (AUTH-11 `_acl`), plus any global `bh-*` user or documentation-range global IP a failed restore left.
- HTTP checks go to the host's public IP (`{PUBLIC_IP}`, from `hostname -I` or `BEHAT_PUBLIC_IP`) with a Host header; `127.0.0.1` checks assert the frontend-subnet bypass. HTTP assertions retry for up to 10 s because nginx reloads are asynchronous.
- Test IPs come from 192.0.2.0/24, 198.51.100.0/24 and 2001:db8::/32, except `{PUBLIC_IP}` for real bypass checks.

## Inventory

Coverage estimate: 54 of 60 inventory items covered (90%). The gaps are items 55–60.

### Commands, arguments and flags

| # | Item | Covered by |
|---|---|---|
| 1 | `ee auth` (usage of all subcommands) | usage: "ee auth without a subcommand…" |
| 2 | `ee help auth <sub>` synopsis for create/update/delete/list | usage: "ee help documents every auth subcommand" |
| 3 | unknown subcommand | usage: "An unknown auth subcommand is rejected" |
| 4 | `create <site> --user --pass` | site: "Create auth with an explicit user and password" |
| 5 | `create <site>` (generated `ee-XXXXXX` user, 18-char pass) | site: "Create auth without flags…" |
| 6 | `create <site> --pass` only / `--user` only | site: "Create auth with only --pass…", "…only --user…" |
| 7 | `create global [--user --pass]` | global: "Global auth protects…", "Global create without flags…" |
| 8 | `create <site> --ip=<list>` (comma/space lists, CIDR, IPv6) | whitelist: "Create a whitelist with one IP", "Create accepts comma and space separated lists…" |
| 9 | `create global --ip` (empty global whitelist / existing) | global: "Global whitelist create works when…empty", "…fails when a global whitelist exists" |
| 10 | `create --ip` + `--user` (ip wins, user ignored) | site: "--ip together with --user on create only creates a whitelist" |
| 11 | `create --ip` without value / `--ip=` empty (falls through to auth) | usage: "A value-less --ip…", "An empty --ip value…" |
| 12 | `update <site> --user --pass` | site: "Update changes the password…", "Update keeps the other users…" |
| 13 | `update <site> --user` (random pass) | site: "Update without --pass generates…" |
| 14 | `update` without `--user` | site: "Update without --user fails" |
| 15 | `update` unknown user (site/global) | site: "Update of an unknown user fails"; global: "Updating an unknown global user fails" |
| 16 | `update global --user [--pass]` | global: "Updating a global password…", "…without --pass generates one" |
| 17 | `update <site> --ip` (adds, skips existing, creates when none) | whitelist: "Update adds new IPs…", "Update is idempotent", "Update on a site without a whitelist creates it" |
| 18 | `update global --ip` | global: "Global whitelist update adds IPs…", "…keeps the existing global IPs", "…skips IPs…" |
| 19 | `delete <site>` (all users) | site: "Delete without --user removes every user…" |
| 20 | `delete <site> --user` | site: "Delete one user keeps the others", "Deleting the last user by name…" |
| 21 | `delete` on site without auth / unknown user / twice | site: "Delete on a site without auth fails", "Delete of an unknown user fails…", "Deleting twice…" |
| 22 | `delete global [--user]` | global: "Deleting one global user…", "Deleting all global users…", "Deleting global auth when there is none fails", "Deleting an unknown global user fails" |
| 23 | `delete <site> --ip=<list>` / not-whitelisted IP skipped | whitelist: "Delete removes only the given IPs", "Deleting an IP that is not whitelisted is skipped" |
| 24 | `delete <site> --ip` (all) | whitelist: "Delete with a bare --ip…", "After removing the whole site whitelist…", "…no whitelist is a no-op", "Delete --ip leaves the auth users alone" |
| 25 | `delete global --ip=<ip>` / `--ip` (all) | global: "Deleting one global IP…", "Deleting the whole global whitelist…" |
| 26 | `list <site>` formats table/csv/yaml/json/count | site: "List shows the site users in every format" |
| 27 | `list <site>` without own users; other sites hidden | site: "List of a site without its own users…", "The site's list does not show other sites' users" |
| 28 | `list global` (Global Auth / Admin Tools Auth labels) | global: "Global list shows the global users"; admin-tools: "Site create initialises…", "Global create absorbs…" |
| 29 | `list <site> --ip` formats; empty list | whitelist: "List --ip shows only the site's own IPs…", "List --ip on a site without a whitelist is empty" |
| 30 | `list global --ip` | global: "Global whitelist update adds IPs…", "Deleting the whole global whitelist…" |
| 31 | `list --format=<invalid>`, unknown params, extra positionals | usage: "The dispatcher rejects unknown parameters…" |

### Site resolution

| # | Item | Covered by |
|---|---|---|
| 32 | unknown site (all subcommands, users and IPs) | usage: "Every subcommand rejects a site that does not exist" |
| 33 | no site name outside a site dir | usage: "Without a site name and outside a site directory…" |
| 34 | site detected from cwd (`auto_site_name`) | usage: "The site name is detected from the current directory" |
| 35 | wrong name inside a site dir (suggestion) | usage: "A wrong site name typed inside a site directory…" |
| 36 | `http://…/` URL form | usage: "A site URL with scheme and trailing slash is accepted" |
| 37 | disabled site (all subcommands) | usage: "Every subcommand refuses a disabled site" |

### Behaviour and side effects

| # | Item | Covered by |
|---|---|---|
| 38 | DB rows in `auth_users`/`auth_ips` for every write | throughout (`the auth_users table …` steps) |
| 39 | per-site htpasswd file contents and password verification | throughout (`the htpasswd file … should accept …`) |
| 40 | `_acl` file contents (global IPs first, `satisfy any`/`deny all`) | whitelist: "Create a whitelist with one IP", "The site _acl file also carries the global whitelist" |
| 41 | global users merged into site files; regeneration on global create/update/delete | global: "Global users are merged…", "A site user created after global auth…", "Deleting all site users keeps the global users…", "Updating a global password regenerates…" |
| 42 | uniqueness rules (same site, global vs site, same name on two sites) | site: "Creating the same user twice…", "The same user name can exist on two different sites…"; global: "A global user name cannot reuse…", "A site user name cannot reuse…" |
| 43 | HTTP: 401 without/wrong creds, 200 with creds, per-site isolation | site: "Create auth with an explicit user…", "Auth on one site does not protect another site" |
| 44 | HTTP: whitelisted IP / CIDR bypass, removal restores prompt | whitelist: "A whitelisted IP bypasses site auth…", "A whitelisted CIDR range…"; global: "A globally whitelisted client…", "A site whitelist only bypasses global auth on that site" |
| 45 | HTTP: frontend subnet (127.0.0.1) bypass for site, site-with-own-acl and global auth; lost after deleting the global whitelist | site: "Requests from the whitelisted frontend subnet…"; whitelist: "A site with its own whitelist still lets the frontend subnet through"; global: "The frontend subnet bypasses global auth", "Deleting the whole global whitelist…" |
| 46 | whitelist alone doesn't restrict access | whitelist: "A whitelist without auth does not restrict access" |
| 47 | HTTPS vhost protected too | lifecycle: "Auth also protects the HTTPS vhost" |
| 48 | proxy reload, `nginx -t` stays green | site/whitelist/global: `STDERR should not contain "nginx config test failed"`; usage: "The global nginx-proxy stays up…" |
| 49 | site delete removes rows and htpasswd (site-command) and `_acl` (AUTH-11) | lifecycle: "Deleting a site removes its auth rows…", `@known-bug` "…removes its whitelist file" |
| 50 | recreate / disable+enable keep or reset auth correctly | lifecycle: "A site recreated with the same name…", "Auth survives disabling…", "The whitelist survives…" |
| 51 | admin-tools auth init on `ee site create` (+ frontend whitelist), kept when present, skipped with global auth | admin-tools: "Site create initialises…", "Site create keeps…", "Site create does not add…" |
| 52 | `ee admin-tools enable` init messages (exists / added) | admin-tools: "ee admin-tools enable reports…", "…creates the admin-tools auth…" |
| 53 | admin-tools auth vs global create/delete (AUTH-8), regen when a site uses admin-tools | admin-tools: "Global create absorbs…", three `@known-bug` AUTH-8 scenarios, `@admin-tools` "Deleting all global auth recreates…" |
| 54 | `/ee-admin/` protected by the admin-tools credentials | admin-tools: `@admin-tools` "Admin-tools pages on a site ask for the admin-tools credentials" (unverified expectation, see suspected bugs) |
| 55 | `verify_htpasswd_is_present()` error when the proxy lacks htpasswd | **not covered**: needs a proxy image without apache2-utils |
| 56 | migrations: `auth_users`/`auth_ips` tables, `AddFrontendIp` container migration | **not covered** as such: tables are exercised by every scenario, but the migration only runs on an upgrade from an older DB (core upgrade suite) |
| 57 | `nginx_proxy_check()` starting a stopped proxy | **not covered**: global-service state, service-command's area |
| 58 | `init_global_admin_tools_auth()` via `ee mailhog enable` | **not covered**: same function as 51/52, belongs to mailhog-command's suite |
| 59 | admin-tools branch of `generate_global_auth_files()` | **not covered**: unreachable from the CLI (see AUTH-19) |
| 60 | `global` in another case (`GLOBAL` is treated as a site name) | **not covered**: trivial |

## Known bugs covered (`@known-bug`)

| ID | Scenario(s) |
|---|---|
| AUTH-8 | admin-tools: "Global create keeps the admin-tools auth separate", "Deleting all global auth keeps the admin-tools auth", "Deleting the only named global user leaves no global auth behind" |
| AUTH-9 | site: "The delete success message names the removed user"; global: "An IP whitelisted globally and on a site appears once…" (the create-when-exists error is asserted as current behaviour in whitelist) |
| AUTH-11 | lifecycle: "Deleting a site removes its whitelist file" |

## Suspected new bugs (from reading the code; proposed IDs)

| ID | Severity | Where | Issue | Scenario |
|---|---|---|---|---|
| AUTH-12 | Medium | `Auth_Command.php` `generate_*_auth_files()`, `auth-utils.php` | User and password are interpolated unquoted into `docker exec … htpasswd -b…`; spaces, `$`, `;`, quotes break it (and `;` runs a shell command). The exit status is ignored, so the DB and htpasswd diverge while "Success" is printed. | site: "Passwords with shell metacharacters work end to end" (3 examples) |
| AUTH-13 | Medium | `clean_and_validate_ips()` | Only the part before `/` is validated; `1.2.3.4/abc`, `/99`, `2001:db8::/200` are stored and written to `_acl`, `nginx -t` fails and every later proxy reload (all sites) is skipped until the entry is removed. | whitelist: "An invalid CIDR prefix is rejected…" (3 examples) |
| AUTH-14 | Low | `create_whitelist()` | A repeated IP in one `--ip` list hits UNIQUE(site_url, ip): uncaught PDOException after the first insert, `_acl` never generated. | whitelist: "A duplicated IP in one create list is stored once" |
| AUTH-15 | Cosmetic | `create/update/delete --ip` | Whitelist changes print no success message at all. | whitelist: "Whitelist commands report success" |
| AUTH-16 | Low | `list()` | Errors "Auth does not exists on global." whenever there is neither global nor admin-tools auth, even for a site with its own users. | global: "A site list works when there is neither global nor admin-tools auth" |
| AUTH-17 | Low | `create_auth()` | The admin-tools row is converted to `default` and saved before `Auth::create()` validates, so a rejected `create global` (name taken by a site user, or `--user=easyengine`) still rewrites the DB and leaves htpasswd/default_admin_tools out of sync. | admin-tools: "A rejected global create leaves the admin-tools auth untouched", "Global create with the admin-tools user name…" |
| AUTH-18 | Low | `update_auth()` + help text | The documented `ee auth update global --user=easyengine` fails: update only looks at `default` rows, so the admin-tools password can't be rotated with ee. | admin-tools: "The admin-tools password can be rotated with ee auth update global" |
| AUTH-19 | Low | `generate_global_auth_files()` L258 | `$global_admin_tools_auth->username` reads a property of an array (PHP warning, empty user/pass passed to htpasswd). Unreachable today because `create global` converts the row first; would bite if both rows coexist (old data). | not covered (unreachable from the CLI) |
| AUTH-20 | Risk (unverified) | nginx-proxy template | The proxy only emits a separate `/ee-admin/` location (with `default_admin_tools`) when the vhost has containers with `VIRTUAL_PATH` (mailhog). Without mailhog, `/ee-admin/` may be protected only by site/global auth, i.e. not at all by default. | admin-tools: `@admin-tools` "Admin-tools pages on a site ask for the admin-tools credentials" |
| AUTH-21 | Cosmetic | help text | `create` examples say the default user is `easyengine`; the code generates `ee-` plus 6 random characters. `delete` synopsis shows `[--ip]` as a flag but accepts `--ip=<list>`. Messages say "does not exists" and "Whitelist is already created on default" (not "global"). | asserted as current behaviour in site/whitelist/global |

## Step definitions

`features/bootstrap/FeatureContext.php` is site-command's context plus a clearly marked "Shared steps and helpers" section. Changes to the copied part: the `I run` pattern is anchored (it matched any step with a double-quoted string), `$commands` is declared (PHP 8.2+ dynamic property), the container-label count check is fixed (it threw on a match), and `@AfterFeature` deletes only `bh-auth-command-*` sites instead of every site.

Note: with Behat 3.8, `--dry-run --strict` always exits 1 because it counts the skipped steps; validation means "no undefined or ambiguous steps" in its output.
