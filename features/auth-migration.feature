@destructive @migration
Feature: Auth files migration on upgrade
  The container migration regenerates every site's htpasswd and ACL files. When a later upgrade step
  fails (here: the registry is unreachable, so the image migration can't pull), the old files must be
  back exactly and the migration must run again on the next attempt.

  Scenario: A failed upgrade restores the auth files and the next attempt writes them
    Given global http auth is not enabled
    And the html site "bh-auth-command-mig.test" exists with flags "--alias-domains='bh-auth-mig-alias.test,*.bh-auth-mig-w.test'"
    And the site "bh-auth-command-mig.test" has no http auth or whitelist
    And I run "bin/ee auth create bh-auth-command-mig.test --user=bh-mu --pass=bh-mp"
    And the command should succeed
    And the auth files of "bh-auth-command-mig.test" are as an older auth-command wrote them
    And the container migration "20260924120000_auth-command_regenerate_site_auth_files.php" is not recorded
    And the EasyEngine option "version" is set to "4.12.0"
    And Docker Hub is unreachable
    When I run "bin/ee cli info"
    Then the command should fail
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/htpasswd/_wildcard.bh-auth-mig-w.test" should not exist
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/htpasswd/bh-auth-mig-alias.test" should not exist
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/htpasswd/bh-auth-command-mig.test" should exist
    And the migrations table should not contain a row with:
      | migration | 20260924120000_auth-command_regenerate_site_auth_files.php |
    And the EasyEngine option "version" should be "4.12.0"
    And a GET request to "http://{PUBLIC_IP}/" with Host "foo.bh-auth-mig-w.test" should return 200
    When Docker Hub is reachable again
    And I run "bin/ee cli info"
    Then the command should succeed
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/htpasswd/_wildcard.bh-auth-mig-w.test" should exist
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/htpasswd/bh-auth-mig-alias.test" should exist
    And the migrations table should contain a row with:
      | migration | 20260924120000_auth-command_regenerate_site_auth_files.php |
    And a GET request to "http://{PUBLIC_IP}/" with Host "foo.bh-auth-mig-w.test" should return 401
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-mig-alias.test" should return 401
    And a GET request to "http://{PUBLIC_IP}/" with Host "foo.bh-auth-mig-w.test" as user "bh-mu" with password "bh-mp" should return 200
