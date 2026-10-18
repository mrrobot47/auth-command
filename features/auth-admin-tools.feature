@destructive
Feature: Admin-tools auth and its interplay with global auth
  The admin-tools auth is a `default_admin_tools` row (user `easyengine`) plus
  htpasswd/default_admin_tools, created by init_global_admin_tools_auth() on `ee site create`,
  `ee admin-tools enable` and `ee mailhog enable` when no global auth exists. `ee auth … global`
  treats it specially. The context restores the global and admin-tools state after each scenario.

  Background:
    Given the html site "bh-auth-command-12.test" exists
    And the site "bh-auth-command-12.test" has no http auth or whitelist

  Scenario: Site create initialises the admin-tools auth and the frontend whitelist
    Given there is no global http auth
    And there is no admin-tools http auth
    And the site "bh-auth-command-10.test" does not exist
    When I run "bin/ee site create bh-auth-command-10.test --type=html"
    Then the command should succeed
    And the auth_users table should have 1 rows with:
      | site_url | default_admin_tools |
      | username | easyengine          |
    And the htpasswd file "default_admin_tools" should contain user "easyengine"
    And the auth_ips table should contain a row with:
      | site_url | default              |
      | ip       | {FRONTEND_SUBNET_IP} |
    When I run "bin/ee auth list global --format=json"
    Then the command should succeed
    And the JSON output should contain items:
      | site_url         | username   |
      | Admin Tools Auth | easyengine |
    When I save the "password" of the JSON item where "username" is "easyengine" as "ADMIN_PASS"
    Then the htpasswd file "default_admin_tools" should accept user "easyengine" with password "{ADMIN_PASS}"

  Scenario: Site create keeps an existing admin-tools auth
    Given there is no global http auth
    And the admin-tools http auth exists
    And the site "bh-auth-command-10.test" does not exist
    When I run "bin/ee auth list global --format=json"
    And I save the "password" of the JSON item where "username" is "easyengine" as "ADMIN_PASS"
    And I run "bin/ee site create bh-auth-command-10.test --type=html"
    Then the command should succeed
    And the auth_users table should have 1 rows with:
      | site_url | default_admin_tools |
    And the htpasswd file "default_admin_tools" should accept user "easyengine" with password "{ADMIN_PASS}"

  Scenario: Site create does not add an admin-tools auth when global auth exists
    Given there is no global http auth
    And there is no admin-tools http auth
    And the site "bh-auth-command-10.test" does not exist
    And I run "bin/ee auth create global --user=bh-g1 --pass=bh-g1-pass"
    When I run "bin/ee site create bh-auth-command-10.test --type=html"
    Then the command should succeed
    And the auth_users table should not contain a row with:
      | site_url | default_admin_tools |
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/htpasswd/default_admin_tools" should not exist

  Scenario: The admin-tools auth does not protect the site root
    Given there is no global http auth
    And the admin-tools http auth exists
    Then a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-12.test" should return 200

  Scenario: A site list includes the admin-tools auth
    Given there is no global http auth
    And the admin-tools http auth exists
    And I run "bin/ee auth create bh-auth-command-12.test --user=bh-s1 --pass=bh-s1-pass"
    When I run "bin/ee auth list bh-auth-command-12.test --format=json"
    Then the command should succeed
    And the JSON output should contain items:
      | site_url                | username   |
      | bh-auth-command-12.test | bh-s1      |
      | Admin Tools Auth        | easyengine |

  Scenario: The admin-tools user is not merged into site htpasswd files
    Given there is no global http auth
    And the admin-tools http auth exists
    When I run "bin/ee auth create bh-auth-command-12.test --user=bh-s1 --pass=bh-s1-pass"
    Then the command should succeed
    And the htpasswd file "bh-auth-command-12.test" should not contain user "easyengine"

  Scenario: A site user may be called like the admin-tools user
    Given there is no global http auth
    And the admin-tools http auth exists
    When I run "bin/ee auth create bh-auth-command-12.test --user=easyengine --pass=bh-site-pass"
    Then the command should succeed
    And the htpasswd file "bh-auth-command-12.test" should accept user "easyengine" with password "bh-site-pass"

  Scenario: Global delete does not touch the admin-tools auth when there is no global auth
    Given there is no global http auth
    And the admin-tools http auth exists
    When I run "bin/ee auth delete global"
    Then the command should fail
    And STDERR should contain:
      """
      Auth does not exists on global
      """
    And the auth_users table should have 1 rows with:
      | site_url | default_admin_tools |

  Scenario: ee admin-tools enable reports an existing admin-tools auth
    Given there is no global http auth
    And the admin-tools http auth exists
    When I run "bin/ee admin-tools enable bh-auth-command-12.test"
    Then the command should fail
    And STDOUT should contain:
      """
      Global auth exists on admin-tools. Use `ee auth list global` to view credentials.
      """
    And STDERR should contain:
      """
      does not support admin-tools
      """

  Scenario: ee admin-tools enable creates the admin-tools auth when there is none
    Given there is no global http auth
    And there is no admin-tools http auth
    When I run "bin/ee admin-tools enable bh-auth-command-12.test"
    Then STDOUT should contain:
      """
      Global admin-tools auth added. Use `ee auth list global` to view credentials.
      """
    And the auth_users table should have 1 rows with:
      | site_url | default_admin_tools |
      | username | easyengine          |
    And the htpasswd file "default_admin_tools" should contain user "easyengine"

  Scenario: Global create absorbs the admin-tools user into global auth (current behaviour)
    # Documents AUTH-8 as it behaves today; the @known-bug scenarios below state the expected behaviour.
    Given there is no global http auth
    And the admin-tools http auth exists
    When I run "bin/ee auth create global --user=bh-g1 --pass=bh-g1-pass"
    Then the command should succeed
    And the auth_users table should contain a row with:
      | site_url | default    |
      | username | easyengine |
    And the htpasswd file "default" should contain user "easyengine"
    And the htpasswd file "default" should accept user "bh-g1" with password "bh-g1-pass"
    When I run "bin/ee auth list global --format=json"
    Then the JSON output should contain items:
      | site_url    | username   |
      | Global Auth | easyengine |
      | Global Auth | bh-g1      |

  @known-bug
  Scenario: Global create keeps the admin-tools auth separate
    # AUTH-8: create_auth() rewrites the default_admin_tools row to `default` and removes
    # htpasswd/default_admin_tools.
    Given there is no global http auth
    And the admin-tools http auth exists
    When I run "bin/ee auth create global --user=bh-g1 --pass=bh-g1-pass"
    Then the command should succeed
    And the auth_users table should have 1 rows with:
      | site_url | default_admin_tools |
      | username | easyengine          |
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/htpasswd/default_admin_tools" should exist

  @known-bug
  Scenario: Deleting all global auth keeps the admin-tools auth
    # AUTH-8: `ee auth delete global` deletes the absorbed admin-tools row too, and with no site
    # using admin-tools or mailhog nothing recreates it, so admin-tools auth is gone.
    Given no site has admin-tools or mailhog enabled
    And there is no global http auth
    And the admin-tools http auth exists
    And I run "bin/ee auth create global --user=bh-g1 --pass=bh-g1-pass"
    When I run "bin/ee auth delete global"
    Then the command should succeed
    And the auth_users table should have 1 rows with:
      | site_url | default_admin_tools |
    And the htpasswd file "default_admin_tools" should contain user "easyengine"

  @known-bug
  Scenario: Deleting the only named global user leaves no global auth behind
    # AUTH-8: the absorbed `easyengine` row stays under `default`, so every site keeps a global
    # auth prompt after the user deleted the global user they created.
    Given there is no global http auth
    And the admin-tools http auth exists
    And I run "bin/ee auth create global --user=bh-g1 --pass=bh-g1-pass"
    When I run "bin/ee auth delete global --user=bh-g1"
    Then the command should succeed
    And the auth_users table should not contain a row with:
      | site_url | default |
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-12.test" should return 200

  @known-bug
  Scenario: A rejected global create leaves the admin-tools auth untouched
    # Suspected new bug (proposed AUTH-17): create_auth() converts the admin-tools row to `default`
    # and saves it BEFORE Auth::create() validates the user name, so a failing create still
    # rewrites the DB (and leaves htpasswd/default_admin_tools out of sync with it).
    Given there is no global http auth
    And the admin-tools http auth exists
    And I run "bin/ee auth create bh-auth-command-12.test --user=bh-dupe --pass=bh-dupe-pass"
    When I run "bin/ee auth create global --user=bh-dupe --pass=bh-x"
    Then the command should fail
    And STDERR should contain:
      """
      There already exists an username bh-dupe on some site or globally
      """
    And the auth_users table should have 1 rows with:
      | site_url | default_admin_tools |
      | username | easyengine          |
    And the auth_users table should not contain a row with:
      | site_url | default |

  @known-bug
  Scenario: Global create with the admin-tools user name is rejected without side effects
    # Suspected new bug (proposed AUTH-17, same root cause): the admin-tools row is converted to
    # `default` first, so validation then rejects `easyengine` as a duplicate of itself.
    Given there is no global http auth
    And the admin-tools http auth exists
    When I run "bin/ee auth create global --user=easyengine --pass=bh-x"
    Then the command should fail
    And the auth_users table should have 1 rows with:
      | site_url | default_admin_tools |
    And the auth_users table should not contain a row with:
      | site_url | default |

  @known-bug
  Scenario: The admin-tools password can be rotated with ee auth update global
    # Suspected new bug (proposed AUTH-18): the help example `ee auth update global --user=easyengine`
    # fails with "Auth with username: easyengine does not exists on global" because update only
    # looks at `default` rows, so the admin-tools password cannot be changed with ee.
    Given there is no global http auth
    And the admin-tools http auth exists
    When I run "bin/ee auth update global --user=easyengine --pass=bh-new-admin"
    Then the command should succeed
    And the htpasswd file "default_admin_tools" should accept user "easyengine" with password "bh-new-admin"

  @admin-tools
  Scenario: Deleting all global auth recreates the admin-tools auth while a site uses admin-tools
    # regen_admin_tools_auth(): only runs when some site has admin-tools or mailhog enabled.
    Given the php site "bh-auth-command-11.test" exists
    And there is no global http auth
    And the admin-tools http auth exists
    And I run "bin/ee admin-tools enable bh-auth-command-11.test --force"
    And the command should succeed
    And I run "bin/ee auth create global --user=bh-g1 --pass=bh-g1-pass"
    When I run "bin/ee auth delete global"
    Then the command should succeed
    And STDOUT should contain:
      """
      Creating new auth for admin-tools only.
      """
    And the auth_users table should have 1 rows with:
      | site_url | default_admin_tools |
      | username | easyengine          |
    And the auth_users table should not contain a row with:
      | site_url | default |
    And the htpasswd file "default_admin_tools" should contain user "easyengine"
    When I run "bin/ee admin-tools disable bh-auth-command-11.test"
    Then the command should succeed

  @admin-tools
  Scenario: Admin-tools pages on a site ask for the admin-tools credentials
    # Needs network access (admin-tools downloads phpMyAdmin and friends). Unverified expectation:
    # the proxy template only emits a separate /ee-admin/ location when the vhost has containers
    # with VIRTUAL_PATH (mailhog), so without mailhog this may expose that /ee-admin/ is unprotected.
    Given the php site "bh-auth-command-11.test" exists
    And there is no global http auth
    And the admin-tools http auth exists
    When I run "bin/ee admin-tools enable bh-auth-command-11.test --force"
    Then the command should succeed
    When I run "bin/ee auth list global --format=json"
    And I save the "password" of the JSON item where "username" is "easyengine" as "ADMIN_PASS"
    Then a GET request to "http://{PUBLIC_IP}/ee-admin/" with Host "bh-auth-command-11.test" should return 401
    And a GET request to "http://{PUBLIC_IP}/ee-admin/" with Host "bh-auth-command-11.test" as user "easyengine" with password "{ADMIN_PASS}" should return 200
    And a GET request to "http://{PUBLIC_IP}/ee-admin/" with Host "bh-auth-command-11.test" as user "easyengine" with password "wrong" should return 401
    When I run "bin/ee admin-tools disable bh-auth-command-11.test"
    Then the command should succeed
