<?php

use EE\Tests\TestDB;

/**
 * Auth_Command: IP list validation, whitelist and auth create/update/delete/list against the DB and the proxy files.
 */
class AuthCommandTest extends AuthTestCase {

	/** @var Auth_Command */
	private $command;

	protected function setUp(): void {
		parent::setUp();
		$this->docker_on_path();
		$this->command = new Auth_Command();
	}

	private function ips( $list ) {
		return self::call_private( $this->command, 'clean_and_validate_ips', $list );
	}

	public function valid_ip_lists() {
		return [
			'single IPv4'          => [ '1.1.1.1', [ '1.1.1.1' ] ],
			'comma and spaces'     => [ '1.1.1.1, 8.8.8.8 ,9.9.9.9', [ '1.1.1.1', '8.8.8.8', '9.9.9.9' ] ],
			'newlines'             => [ "1.1.1.1\n2001:db8::1", [ '1.1.1.1', '2001:db8::1' ] ],
			'IPv6'                 => [ '::1,2001:db8::1,fe80::1', [ '::1', '2001:db8::1', 'fe80::1' ] ],
			'CIDR kept as given'   => [ '10.0.0.0/8,192.0.2.1/32,0.0.0.0/0', [ '10.0.0.0/8', '192.0.2.1/32', '0.0.0.0/0' ] ],
			'IPv6 CIDR'            => [ '2001:db8::/32,::/0,2001:db8::1/128', [ '2001:db8::/32', '::/0', '2001:db8::1/128' ] ],
			'trailing newline'     => [ "1.1.1.1\n", [ '1.1.1.1' ] ],
			'tab separated'        => [ "1.1.1.1\t2.2.2.2", [ '1.1.1.1', '2.2.2.2' ] ],
			'CRLF separated'       => [ "1.1.1.1\r\n2.2.2.2\r\n", [ '1.1.1.1', '2.2.2.2' ] ],
			'repeated entry'       => [ '1.1.1.1,1.1.1.1 2.2.2.2', [ '1.1.1.1', '2.2.2.2' ] ],
		];
	}

	/**
	 * Lists split on commas and any whitespace, and are de-duplicated; each entry is returned as given, prefix included.
	 *
	 * @dataProvider valid_ip_lists
	 */
	public function test_clean_and_validate_ips_accepts( $list, $expected ) {
		$this->assertSame( $expected, $this->ips( $list ) );
	}

	public function invalid_ip_lists() {
		return [
			'word'               => [ 'abc' ],
			'octet out of range' => [ '1.2.3.256' ],
			'three octets'       => [ '1.2.3' ],
			'trailing comma'     => [ '1.1.1.1,' ],
			'leading comma'      => [ ',1.1.1.1' ],
			'only a prefix'      => [ '/24' ],
			'empty prefix'       => [ '1.1.1.1/' ],
			'two prefixes'       => [ '10.0.0.0/8/8' ],
			'hostname'           => [ 'example.com' ],
			'bad address part'   => [ '999.0.0.0/8' ],
			'seven groups and ::' => [ '1:2:3:4:5:6:7::' ],
		];
	}

	/**
	 * Any bad entry rejects the whole list (a trailing separator leaves an empty entry).
	 *
	 * @dataProvider invalid_ip_lists
	 */
	public function test_clean_and_validate_ips_rejects( $list ) {
		$this->assert_ee_error(
			function () use ( $list ) {
				$this->ips( $list );
			},
			'Please check your list do not have any empty or wrong IP addresses.'
		);
	}

	public function invalid_prefixes() {
		return [
			'non-numeric IPv4 prefix' => [ '192.0.2.1/abc' ],
			'IPv4 prefix above 32'    => [ '192.0.2.1/33' ],
			'IPv4 prefix 99'          => [ '10.0.0.0/99' ],
			'negative prefix'         => [ '192.0.2.1/-1' ],
			'dotted prefix'           => [ '192.0.2.1/1.2' ],
			'IPv6 prefix above 128'   => [ '2001:db8::/200' ],
			'IPv6 prefix 129'         => [ '::1/129' ],
		];
	}

	/**
	 * AUTH-13: a bad prefix would be stored and break `nginx -t` for every site.
	 *
	 * @group AUTH-13
	 * @dataProvider invalid_prefixes
	 */
	public function test_clean_and_validate_ips_rejects_an_invalid_prefix( $entry ) {
		$this->assert_ee_error(
			function () use ( $entry ) {
				$this->ips( "1.1.1.1,$entry" );
			}
		);
	}

	public function test_create_ip_whitelists_a_site_and_writes_its_acl_files() {
		$this->insert_site( [ 'site_url' => 'a.test', 'app_sub_type' => 'subdom', 'alias_domains' => 'www.a.test' ] );
		$this->insert_ips( 'default', [ '10.0.0.0/16' ] );

		$this->command->create( [ 'a.test' ], [ 'ip' => '1.1.1.1,2001:db8::/32' ] );

		$this->assertSame( [ [ 'default', '10.0.0.0/16' ], [ 'a.test', '1.1.1.1' ], [ 'a.test', '2001:db8::/32' ] ], self::pairs( 'auth_ips', 'ip' ) );
		$expected = self::acl( [ '10.0.0.0/16', '1.1.1.1', '2001:db8::/32' ] );
		foreach ( [ 'a.test', '_wildcard.a.test', 'www.a.test' ] as $name ) {
			$this->assertSame( $expected, $this->acl_file( $name ), $name );
		}
		$this->assertCount( 3, $this->reloads() );
		$this->assertCount( 1, $this->runner->matching( "/^docker exec services_global-nginx-proxy_1 sh -c 'command -v htpasswd'$/" ) );
	}

	public function test_create_ip_on_a_site_with_a_whitelist_errors() {
		$this->insert_site( [ 'site_url' => 'a.test' ] );
		$this->insert_ips( 'a.test', [ '1.1.1.1' ] );

		$this->assert_ee_error(
			function () {
				$this->command->create( [ 'a.test' ], [ 'ip' => '2.2.2.2' ] );
			},
			'Whitelist is already created on a.test. To update IPs use `ee auth update` instead'
		);
		$this->assertCount( 1, TestDB::rows( 'auth_ips' ) );
	}

	/** A global IP goes to default_acl and to the ACL files of every site with own entries; others keep using default_acl. */
	public function test_create_global_ip_regenerates_the_sites_with_own_entries() {
		$this->insert_site( [ 'site_url' => 'a.test' ] );
		$this->insert_site( [ 'site_url' => 'b.test' ] );
		$this->insert_ips( 'a.test', [ '2.2.2.2' ] );

		$this->command->create( [ 'global' ], [ 'ip' => '10.0.0.0/16' ] );

		$this->assertSame( [ 'a.test_acl', 'default_acl' ], self::files( $this->vhost ) );
		$this->assertSame( self::acl( [ '10.0.0.0/16' ] ), $this->acl_file( 'default' ) );
		$this->assertSame( self::acl( [ '10.0.0.0/16', '2.2.2.2' ] ), $this->acl_file( 'a.test' ) );
	}

	/**
	 * AUTH-14: a repeated IP in one create list is stored once instead of hitting UNIQUE(site_url, ip).
	 *
	 * @group AUTH-14
	 */
	public function test_create_ip_stores_a_repeated_ip_once() {
		$this->insert_site( [ 'site_url' => 'a.test' ] );

		$this->command->create( [ 'a.test' ], [ 'ip' => '1.2.3.4,1.2.3.4' ] );

		$this->assertSame( [ [ 'a.test', '1.2.3.4' ] ], self::pairs( 'auth_ips', 'ip' ) );
		$this->assertSame( self::acl( [ '1.2.3.4' ] ), $this->acl_file( 'a.test' ) );
	}

	/**
	 * AUTH-15: whitelist commands print no success message.
	 *
	 * @group known-bug
	 * @group AUTH-15
	 */
	public function test_create_ip_reports_success() {
		$this->insert_site( [ 'site_url' => 'a.test' ] );

		$this->command->create( [ 'a.test' ], [ 'ip' => '1.1.1.1' ] );

		$this->assertStringContainsString( 'Success:', $this->stdout() );
	}

	/**
	 * AUTH-9: an IP whitelisted both globally and on the site gets two `allow` lines in the site's ACL file.
	 *
	 * @group known-bug
	 * @group AUTH-9
	 */
	public function test_site_acl_has_no_duplicate_allow_lines() {
		$this->insert_site( [ 'site_url' => 'a.test' ] );
		$this->insert_ips( 'default', [ '1.1.1.1' ] );

		$this->command->create( [ 'a.test' ], [ 'ip' => '1.1.1.1,2.2.2.2' ] );

		$this->assertSame( self::acl( [ '1.1.1.1', '2.2.2.2' ] ), $this->acl_file( 'a.test' ) );
	}

	public function test_update_ip_adds_new_ips_and_skips_existing_ones() {
		$this->insert_site( [ 'site_url' => 'a.test' ] );
		$this->insert_ips( 'a.test', [ '1.1.1.1' ] );

		$this->command->update( [ 'a.test' ], [ 'ip' => '1.1.1.1,1.1.1.1 3.3.3.3' ] );

		$this->assertSame( [ [ 'a.test', '1.1.1.1' ], [ 'a.test', '3.3.3.3' ] ], self::pairs( 'auth_ips', 'ip' ) );
		$this->assertStringContainsString( '1.1.1.1 has already been whitelisted on a.test. Skipping it.', $this->stdout() );
		$this->assertSame( self::acl( [ '1.1.1.1', '3.3.3.3' ] ), $this->acl_file( 'a.test' ) );
	}

	/**
	 * AUTH-13: stored entries nginx accepts, with a prefix leading zero or a trailing CR, are written normalized, not dropped.
	 *
	 * @group AUTH-13
	 */
	public function test_stored_entries_nginx_accepts_are_normalized() {
		$this->insert_site( [ 'site_url' => 'a.test' ] );
		$this->insert_ips( 'a.test', [ '10.0.0.0/08', "198.51.100.0/24\r" ] );

		$this->command->update( [ 'a.test' ], [ 'ip' => '1.1.1.1' ] );

		$this->assertSame( self::acl( [ '1.1.1.1', '10.0.0.0/8', '198.51.100.0/24' ] ), $this->acl_file( 'a.test' ) );
		$this->assertStringNotContainsString( 'Skipping the invalid whitelist entry', $this->stderr() );
	}

	public function test_update_ip_rejects_an_invalid_list_before_touching_the_db() {
		$this->insert_site( [ 'site_url' => 'a.test' ] );

		$this->assert_ee_error(
			function () {
				$this->command->update( [ 'a.test' ], [ 'ip' => '1.1.1.1,nope' ] );
			},
			'wrong IP addresses'
		);
		$this->assertSame( [], TestDB::rows( 'auth_ips' ) );
		$this->assertSame( [], $this->reloads() );
	}

	public function test_delete_ip_removes_listed_ips_and_skips_unknown_ones() {
		$this->insert_site( [ 'site_url' => 'a.test' ] );
		$this->insert_ips( 'a.test', [ '1.1.1.1', '2.2.2.2' ] );
		$this->insert_ips( 'b.test', [ '1.1.1.1' ] );

		$this->command->delete( [ 'a.test' ], [ 'ip' => '1.1.1.1,9.9.9.9' ] );

		$this->assertSame( [ [ 'a.test', '2.2.2.2' ], [ 'b.test', '1.1.1.1' ] ], self::pairs( 'auth_ips', 'ip' ) );
		$this->assertStringContainsString( '9.9.9.9 has not been whitelisted on a.test. Skipping it.', $this->stdout() );
		$this->assertSame( self::acl( [ '2.2.2.2' ] ), $this->acl_file( 'a.test' ) );
	}

	public function test_delete_ip_without_a_list_removes_the_whole_site_whitelist() {
		$this->insert_site( [ 'site_url' => 'a.test', 'alias_domains' => 'b.test' ] );
		$this->insert_ips( 'a.test', [ '1.1.1.1', '2.2.2.2' ] );
		$this->insert_ips( 'default', [ '10.0.0.0/16' ] );
		file_put_contents( "$this->vhost/a.test_acl", 'x' );
		file_put_contents( "$this->vhost/b.test_acl", 'x' );
		file_put_contents( "$this->vhost/default_acl", 'x' );

		$this->command->delete( [ 'a.test' ], [ 'ip' => true ] );

		$this->assertSame( [ [ 'default', '10.0.0.0/16' ] ], self::pairs( 'auth_ips', 'ip' ) );
		$this->assertSame( [ 'default_acl' ], self::files( $this->vhost ) );
		$this->assertCount( 3, $this->reloads() );
	}

	public function test_create_auth_on_a_site_stores_the_user_and_writes_every_domain_file() {
		$this->insert_site( [ 'site_url' => 'a.test', 'app_sub_type' => 'subdom' ] );
		$this->insert_auth( 'default', 'g1', 'gp' );

		$this->command->create( [ 'a.test' ], [ 'user' => 'u1', 'pass' => 'p1' ] );

		$this->assertSame( [ [ 'default', 'g1' ], [ 'a.test', 'u1' ] ], self::pairs( 'auth_users', 'username' ) );
		$this->assertSame( [ '_wildcard.a.test', 'a.test' ], self::files( $this->htpasswd ) );
		$this->assertSame( "g1:gp\nu1:p1\n", $this->htpasswd_file( '_wildcard.a.test' ) );
		$this->assertStringContainsString( "Success: Auth successfully updated for `a.test` scope. New values added:\nUser: u1\nPass: p1", $this->stdout() );
		$this->assertCount( 3, $this->reloads() );
	}

	public function test_create_auth_generates_a_user_and_password_when_not_given() {
		$this->insert_site( [ 'site_url' => 'a.test' ] );

		$this->command->create( [ 'a.test' ], [] );

		$row = TestDB::rows( 'auth_users' )[0];
		$this->assertMatchesRegularExpression( '/^ee-[A-Za-z0-9]{6}$/', $row['username'] );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]{18}$/', $row['password'] );
		$this->assertSame( "{$row['username']}:{$row['password']}\n", $this->htpasswd_file( 'a.test' ) );
	}

	public function test_create_auth_rejects_an_existing_user_of_the_site() {
		$this->insert_site( [ 'site_url' => 'a.test' ] );
		$this->insert_auth( 'a.test', 'u1', 'p1' );

		$this->assert_ee_error(
			function () {
				$this->command->create( [ 'a.test' ], [ 'user' => 'u1', 'pass' => 'x' ] );
			},
			'Auth for user u1 already exists for this site.'
		);
		$this->assertCount( 1, TestDB::rows( 'auth_users' ) );
	}

	public function test_create_auth_errors_when_the_proxy_has_no_htpasswd() {
		$this->insert_site( [ 'site_url' => 'a.test' ] );
		$this->runner->on( "/sh -c 'command -v htpasswd'$/", '', 1 );

		$this->assert_ee_error(
			function () {
				$this->command->create( [ 'a.test' ], [ 'user' => 'u1', 'pass' => 'p1' ] );
			},
			'Could not find apache2-utils installed in services_global-nginx-proxy_1.'
		);
		$this->assertSame( [], TestDB::rows( 'auth_users' ) );
	}

	/** Global users go to `default` and into every site file that has own users; `default_admin_tools` is dropped. */
	public function test_create_global_auth_writes_default_and_regenerates_site_files() {
		$this->insert_site( [ 'site_url' => 'a.test', 'alias_domains' => 'b.test' ] );
		$this->insert_auth( 'a.test', 'u1', 'p1' );
		file_put_contents( "$this->htpasswd/default_admin_tools", 'old' );

		$this->command->create( [ 'global' ], [ 'user' => 'g1', 'pass' => 'gp' ] );

		$this->assertSame( [ 'a.test', 'b.test', 'default' ], self::files( $this->htpasswd ) );
		$this->assertSame( "g1:gp\n", $this->htpasswd_file( 'default' ) );
		$this->assertSame( "g1:gp\nu1:p1\n", $this->htpasswd_file( 'b.test' ) );
	}

	/** AUTH-2: when `default` can't be written, `default_admin_tools` keeps protecting the admin tools. */
	public function test_create_global_auth_keeps_admin_tools_file_when_default_fails() {
		file_put_contents( "$this->htpasswd/default_admin_tools", 'old' );
		$this->failing_users = [ 'g1' ];

		$this->command->create( [ 'global' ], [ 'user' => 'g1', 'pass' => 'gp' ] );

		$this->assertSame( [ 'default_admin_tools' ], self::files( $this->htpasswd ) );
		$this->assertStringContainsString( 'Could not write the htpasswd file default', $this->stderr() );
	}

	/** AUTH-19: the admin-tools rows are written as models (username/password read per row). */
	public function test_global_auth_files_with_admin_tools_auth_write_default_admin_tools() {
		$this->insert_auth( 'default_admin_tools', 'easyengine', 'ap' );

		self::call_private( $this->command, 'generate_global_auth_files' );

		$this->assertSame( [ 'default_admin_tools' ], self::files( $this->htpasswd ) );
		$this->assertSame( "easyengine:ap\n", $this->htpasswd_file( 'default_admin_tools' ) );
	}

	/**
	 * AUTH-17: `create global` converts the admin-tools row to `default` before the user name is validated, so a rejected create still changes the DB.
	 *
	 * @group known-bug
	 * @group AUTH-17
	 */
	public function test_rejected_global_create_leaves_admin_tools_auth_untouched() {
		$this->insert_site( [ 'site_url' => 'a.test' ] );
		$this->insert_auth( 'default_admin_tools', 'easyengine', 'ap' );
		$this->insert_auth( 'a.test', 'taken', 'p1' );

		$this->assert_ee_error(
			function () {
				$this->command->create( [ 'global' ], [ 'user' => 'taken', 'pass' => 'x' ] );
			},
			'There already exists an username taken'
		);
		$this->assertSame( [ [ 'default_admin_tools', 'easyengine' ], [ 'a.test', 'taken' ] ], self::pairs( 'auth_users', 'username' ) );
	}

	public function test_update_auth_requires_a_user() {
		$this->insert_site( [ 'site_url' => 'a.test' ] );

		$this->assert_ee_error(
			function () {
				$this->command->update( [ 'a.test' ], [ 'pass' => 'x' ] );
			},
			'Please provide auth user with --user flag'
		);
	}

	public function test_update_auth_errors_for_an_unknown_user() {
		$this->insert_site( [ 'site_url' => 'a.test' ] );

		$this->assert_ee_error(
			function () {
				$this->command->update( [ 'a.test' ], [ 'user' => 'nope' ] );
			},
			'Auth with username: nope does not exists on a.test'
		);
	}

	public function test_update_auth_changes_the_password_and_rewrites_the_files() {
		$this->insert_site( [ 'site_url' => 'a.test', 'alias_domains' => 'b.test' ] );
		$this->insert_auth( 'a.test', 'u1', 'old' );
		$this->insert_auth( 'a.test', 'u2', 'keep' );

		$this->command->update( [ 'a.test' ], [ 'user' => 'u1', 'pass' => 'new' ] );

		$this->assertSame( [ [ 'a.test', 'new' ], [ 'a.test', 'keep' ] ], self::pairs( 'auth_users', 'password' ) );
		$this->assertSame( "u1:new\nu2:keep\n", $this->htpasswd_file( 'b.test' ) );
		$this->assertStringContainsString( "User: u1\nPass: new", $this->stdout() );
	}

	/**
	 * AUTH-18: the documented `ee auth update global --user=easyengine` fails, so the admin-tools password can't be rotated.
	 *
	 * @group known-bug
	 * @group AUTH-18
	 */
	public function test_update_global_rotates_the_admin_tools_password() {
		$this->insert_auth( 'default_admin_tools', 'easyengine', 'old' );

		$this->no_ee_error(
			function () {
				$this->command->update( [ 'global' ], [ 'user' => 'easyengine', 'pass' => 'new' ] );
			}
		);

		$this->assertSame( [ [ 'default_admin_tools', 'new' ] ], self::pairs( 'auth_users', 'password' ) );
		$this->assertSame( "easyengine:new\n", $this->htpasswd_file( 'default_admin_tools' ) );
	}

	public function test_delete_auth_of_one_user_keeps_the_others() {
		$this->insert_site( [ 'site_url' => 'a.test' ] );
		$this->insert_auth( 'a.test', 'u1', 'p1' );
		$this->insert_auth( 'a.test', 'u2', 'p2' );

		$this->command->delete( [ 'a.test' ], [ 'user' => 'u1' ] );

		$this->assertSame( [ [ 'a.test', 'u2' ] ], self::pairs( 'auth_users', 'username' ) );
		$this->assertSame( "u2:p2\n", $this->htpasswd_file( 'a.test' ) );
	}

	public function test_delete_auth_of_all_users_removes_the_site_files() {
		$this->insert_site( [ 'site_url' => 'a.test', 'app_sub_type' => 'subdom' ] );
		$this->insert_auth( 'a.test', 'u1', 'p1' );
		$this->insert_auth( 'default', 'g1', 'gp' );
		file_put_contents( "$this->htpasswd/a.test", 'x' );
		file_put_contents( "$this->htpasswd/_wildcard.a.test", 'x' );
		file_put_contents( "$this->htpasswd/default", 'x' );

		$this->command->delete( [ 'a.test' ], [] );

		$this->assertSame( [ [ 'default', 'g1' ] ], self::pairs( 'auth_users', 'username' ) );
		$this->assertSame( [ 'default' ], self::files( $this->htpasswd ) );
		$this->assertCount( 3, $this->reloads() );
	}

	public function test_delete_global_auth_without_admin_tools_sites_removes_both_global_files() {
		$this->insert_site( [ 'site_url' => 'a.test' ] );
		$this->insert_auth( 'default', 'g1', 'gp' );
		$this->insert_auth( 'a.test', 'u1', 'p1' );
		foreach ( [ 'default', 'default_admin_tools', 'a.test' ] as $name ) {
			file_put_contents( "$this->htpasswd/$name", 'x' );
		}

		$this->command->delete( [ 'global' ], [] );

		$this->assertSame( [ [ 'a.test', 'u1' ] ], self::pairs( 'auth_users', 'username' ) );
		$this->assertSame( [ 'a.test' ], self::files( $this->htpasswd ) );
		$this->assertSame( "u1:p1\n", $this->htpasswd_file( 'a.test' ), 'Site file rewritten without the global user.' );
	}

	public function test_delete_auth_errors_when_the_site_has_none() {
		$this->insert_site( [ 'site_url' => 'a.test' ] );

		$this->assert_ee_error(
			function () {
				$this->command->delete( [ 'a.test' ], [] );
			},
			'Auth does not exists on a.test'
		);
	}

	/**
	 * AUTH-9: the delete success messages are swapped: the one for a single user doesn't name the user.
	 *
	 * @group known-bug
	 * @group AUTH-9
	 */
	public function test_delete_of_one_user_names_the_user() {
		$this->insert_site( [ 'site_url' => 'a.test' ] );
		$this->insert_auth( 'a.test', 'u1', 'p1' );

		$this->command->delete( [ 'a.test' ], [ 'user' => 'u1' ] );

		$this->assertStringContainsString( 'u1', $this->stdout() );
	}

	public function test_list_shows_site_users_before_the_global_ones() {
		$this->insert_site( [ 'site_url' => 'a.test' ] );
		$this->insert_auth( 'default', 'g1', 'gp' );
		$this->insert_auth( 'a.test', 'u1', 'p1' );

		$this->assertSame(
			[
				[ 'site_url' => 'a.test', 'username' => 'u1', 'password' => 'p1' ],
				[ 'site_url' => 'Global Auth', 'username' => 'g1', 'password' => 'gp' ],
			],
			$this->listed( [ 'a.test' ], [] )
		);
	}

	public function test_list_global_labels_admin_tools_auth() {
		$this->insert_auth( 'default_admin_tools', 'easyengine', 'ap' );

		$this->assertSame( [ [ 'site_url' => 'Admin Tools Auth', 'username' => 'easyengine', 'password' => 'ap' ] ], $this->listed( [ 'global' ], [] ) );
	}

	public function test_list_ip_shows_the_site_whitelist() {
		$this->insert_site( [ 'site_url' => 'a.test' ] );
		$this->insert_ips( 'a.test', [ '1.1.1.1', '2001:db8::/32' ] );
		$this->insert_ips( 'default', [ '10.0.0.0/16' ] );

		$this->assertSame( [ [ 'ip' => '1.1.1.1' ], [ 'ip' => '2001:db8::/32' ] ], $this->listed( [ 'a.test' ], [ 'ip' => true ] ) );
	}

	/**
	 * AUTH-16: `ee auth list <site>` errors when there is no global or admin-tools auth, before looking at the site's users.
	 *
	 * @group known-bug
	 * @group AUTH-16
	 */
	public function test_list_of_a_site_works_without_global_auth() {
		$this->insert_site( [ 'site_url' => 'a.test' ] );
		$this->insert_auth( 'a.test', 'u1', 'p1' );

		$listed = $this->no_ee_error(
			function () {
				return $this->listed( [ 'a.test' ], [] );
			}
		);
		$this->assertSame( [ [ 'site_url' => 'a.test', 'username' => 'u1', 'password' => 'p1' ] ], $listed );
	}

	/** @return array[] Rows `list` printed as JSON (echoed, so captured from the output buffer). */
	private function listed( array $args, array $assoc_args ) {
		ob_start();
		try {
			$this->command->list( $args, $assoc_args + [ 'format' => 'json' ] );
		} finally {
			$json = ob_get_clean();
		}

		return json_decode( $json, true );
	}
}
