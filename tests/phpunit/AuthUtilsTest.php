<?php

use EE\Model\Site;
use function EE\Auth\Utils\copy_proxy_file;
use function EE\Auth\Utils\generate_site_auth_files;
use function EE\Auth\Utils\generate_site_whitelist;
use function EE\Auth\Utils\get_alias_auth_domains;
use function EE\Auth\Utils\get_auth_domain;
use function EE\Auth\Utils\get_site_auth_domains;
use function EE\Auth\Utils\get_site_whitelist_ips;
use function EE\Auth\Utils\htpasswd_command;
use function EE\Auth\Utils\init_global_admin_tools_auth;
use function EE\Auth\Utils\is_proxy_file_name;
use function EE\Auth\Utils\put_ips_to_file;
use function EE\Auth\Utils\remove_auth_files;
use function EE\Auth\Utils\remove_proxy_file;
use function EE\Auth\Utils\site_auth_files_missing;
use function EE\Auth\Utils\write_htpasswd_file;

/**
 * Auth file helpers in src/auth-utils.php: proxy file names, htpasswd writing and the site htpasswd/ACL files.
 */
class AuthUtilsTest extends AuthTestCase {

	public function test_get_auth_domain_maps_a_wildcard_to_its_file_name() {
		$this->assertSame( '_wildcard.example.com', get_auth_domain( '*.example.com' ) );
		$this->assertSame( 'example.com', get_auth_domain( 'example.com' ) );
		$this->assertSame( 'a*.example.com', get_auth_domain( 'a*.example.com' ) );
	}

	/** AUTH-1/AUTH-4: aliases site-command would reject never become file names; the rest are trimmed and mapped. */
	public function test_get_alias_auth_domains_keeps_only_valid_aliases() {
		$aliases = [ ' a.test ', '*.b.test', '_wildcard.foo.com', 'default', 'DEFAULT_admin_tools', '-a.com', 'a-.com', '../x', 'a/b', '', 'c_d.test' ];
		$this->assertSame( [ 'a.test', '_wildcard.b.test', 'c_d.test' ], get_alias_auth_domains( $aliases ) );
	}

	public function site_auth_domain_cases() {
		return [
			'plain site'            => [ null, null, [], [ 'example.test' ] ],
			'subdir multisite'      => [ 'subdir', null, [], [ 'example.test' ] ],
			'subdom multisite'      => [ 'subdom', null, [], [ 'example.test', '_wildcard.example.test' ] ],
			'aliases and wildcard'  => [ null, 'example.test,www.example.test, *.shop.test', [], [ 'example.test', 'www.example.test', '_wildcard.shop.test' ] ],
			'subdom with own alias' => [ 'subdom', '*.example.test,a.test', [], [ 'example.test', '_wildcard.example.test', 'a.test' ] ],
			'extra aliases'         => [ null, 'a.test', [ 'b.test', 'a.test', '_wildcard.x.test' ], [ 'example.test', 'a.test', 'b.test' ] ],
		];
	}

	/**
	 * The file names of a site: itself, `_wildcard.<site>` for subdom multisites, its valid aliases, without duplicates.
	 *
	 * @dataProvider site_auth_domain_cases
	 */
	public function test_get_site_auth_domains( $sub_type, $aliases, $extra, $expected ) {
		$site = (object) [ 'app_sub_type' => $sub_type, 'alias_domains' => $aliases ];
		$this->assertSame( $expected, get_site_auth_domains( 'example.test', $site, $extra ) );
	}

	public function test_get_site_auth_domains_without_site_data() {
		$this->assertSame( [ 'gone.test', 'x.test' ], get_site_auth_domains( 'gone.test', null, [ 'x.test' ] ) );
	}

	public function proxy_file_names() {
		return [
			[ 'example.com', true ],
			[ '_wildcard.example.com', true ],
			[ '.example.com.tmp', true ],
			[ '', false ],
			[ '.', false ],
			[ '..', false ],
			[ 'a/b', false ],
			[ '../default', false ],
			[ 'a\\b', false ],
			[ "a\0b", false ],
		];
	}

	/**
	 * Only names of entries directly inside a directory are accepted.
	 *
	 * @dataProvider proxy_file_names
	 */
	public function test_is_proxy_file_name( $name, $expected ) {
		$this->assertSame( $expected, is_proxy_file_name( $name ) );
	}

	public function test_remove_proxy_file_removes_only_a_regular_file_inside_the_dir() {
		file_put_contents( "$this->htpasswd/a.test", 'x' );
		mkdir( "$this->htpasswd/sub.test" );

		$this->assertFalse( remove_proxy_file( $this->htpasswd, '' ), 'An empty name must not wipe the directory.' );
		$this->assertFalse( remove_proxy_file( $this->htpasswd, 'sub.test' ) );
		$this->assertFalse( remove_proxy_file( $this->htpasswd, 'missing.test' ) );
		$this->assertFalse( remove_proxy_file( "$this->htpasswd/sub.test", '../a.test' ) );
		$this->assertSame( [ 'a.test', 'sub.test' ], self::files( $this->htpasswd ) );

		$this->assertTrue( remove_proxy_file( $this->htpasswd, 'a.test' ) );
		$this->assertSame( [ 'sub.test' ], self::files( $this->htpasswd ) );
	}

	public function test_copy_proxy_file_copies_content_and_mode_and_skips_bad_targets() {
		file_put_contents( "$this->htpasswd/a.test", "u:p\n" );
		chmod( "$this->htpasswd/a.test", 0644 );
		umask( 0077 );
		try {
			copy_proxy_file( $this->htpasswd, 'a.test', [ '_wildcard.a.test', 'a.test', '../escape', '', 'b.test' ] );
		} finally {
			umask( 0022 );
		}

		$this->assertSame( [ '_wildcard.a.test', 'a.test', 'b.test' ], self::files( $this->htpasswd ) );
		foreach ( [ '_wildcard.a.test', 'b.test' ] as $name ) {
			$this->assertSame( "u:p\n", $this->htpasswd_file( $name ) );
			$this->assertSame( 0644, fileperms( "$this->htpasswd/$name" ) & 0777, "$name keeps the source mode, not the umask" );
		}
		$this->assertFileDoesNotExist( dirname( $this->htpasswd ) . '/escape' );
	}

	public function test_remove_auth_files_removes_htpasswd_and_acl_but_never_the_global_files() {
		foreach ( [ 'default', 'default_admin_tools', 'a.test', '_wildcard.a.test' ] as $name ) {
			file_put_contents( "$this->htpasswd/$name", 'x' );
			file_put_contents( "$this->vhost/{$name}_acl", 'x' );
		}

		$this->assertTrue( remove_auth_files( [ 'a.test', '_wildcard.a.test', 'Default', 'default_admin_tools', '' ] ) );
		$this->assertSame( [ 'default', 'default_admin_tools' ], self::files( $this->htpasswd ) );
		$this->assertSame( [ 'default_acl', 'default_admin_tools_acl' ], self::files( $this->vhost ) );
		$this->assertFalse( remove_auth_files( [ 'a.test' ] ), 'Nothing left to remove.' );
	}

	public function test_htpasswd_command_escapes_every_argument() {
		$this->assertSame(
			"docker exec services_global-nginx-proxy_1 htpasswd -bc '/etc/nginx/htpasswd/.a.test.tmp' 'o'\\''neil' 'two words' 2>/dev/null",
			htpasswd_command( 'bc', '.a.test.tmp', "o'neil", 'two words' )
		);
	}

	/** AUTH-12: user and password never reach the shell unquoted. */
	public function test_write_htpasswd_file_is_shell_safe() {
		$this->assert_shell_safe(
			function ( $payload ) {
				write_htpasswd_file( 'a.test', [ (object) [ 'username' => $payload, 'password' => $payload ] ] );
			},
			'/ htpasswd /'
		);
	}

	public function test_write_htpasswd_file_builds_a_temp_file_and_renames_it() {
		file_put_contents( "$this->htpasswd/a.test", "old:old\n" );

		$written = write_htpasswd_file(
			'a.test',
			[
				(object) [ 'username' => 'u1', 'password' => 'p 1' ],
				(object) [ 'username' => 'u2', 'password' => 'a$HOMEb' ],
			]
		);

		$this->assertTrue( $written );
		$commands = $this->runner->matching( '/ htpasswd /' );
		$this->assertCount( 2, $commands );
		$this->assertSame( [ '-bc', '/etc/nginx/htpasswd/.a.test.tmp', 'u1', 'p 1' ], array_slice( self::shell_words( $commands[0] ), 4, 4 ) );
		$this->assertSame( [ '-b', '/etc/nginx/htpasswd/.a.test.tmp', 'u2', 'a$HOMEb' ], array_slice( self::shell_words( $commands[1] ), 4, 4 ) );
		$this->assertSame( "u1:p 1\nu2:a\$HOMEb\n", $this->htpasswd_file( 'a.test' ) );
		$this->assertSame( [ 'a.test' ], self::files( $this->htpasswd ) );
	}

	/** AUTH-2/AUTH-12: a failed htpasswd run keeps the old file, removes the partial temp file and warns. */
	public function test_write_htpasswd_file_keeps_the_old_file_on_failure() {
		file_put_contents( "$this->htpasswd/a.test", "old:old\n" );
		$this->failing_users = [ 'u2' ];

		$written = write_htpasswd_file(
			'a.test',
			[
				(object) [ 'username' => 'u1', 'password' => 'p1' ],
				(object) [ 'username' => 'u2', 'password' => 'p2' ],
				(object) [ 'username' => 'u3', 'password' => 'p3' ],
			]
		);

		$this->assertFalse( $written );
		$this->assertCount( 2, $this->runner->matching( '/ htpasswd /' ), 'Stops at the first failure.' );
		$this->assertSame( "old:old\n", $this->htpasswd_file( 'a.test' ) );
		$this->assertSame( [ 'a.test' ], self::files( $this->htpasswd ) );
		$this->assertStringContainsString( 'Could not write the htpasswd file a.test, so it was left unchanged.', $this->stderr() );
	}

	public function test_write_htpasswd_file_without_entries_runs_nothing() {
		$this->assertTrue( write_htpasswd_file( 'a.test', [] ) );
		$this->assertSame( [], $this->runner->commands() );
	}

	public function test_generate_site_auth_files_writes_global_then_site_users_to_every_domain() {
		$this->insert_site( [ 'site_url' => 'a.test', 'app_sub_type' => 'subdom', 'alias_domains' => 'a.test,*.b.test,c.test' ] );
		$this->insert_auth( 'default', 'g1', 'gp' );
		$this->insert_auth( 'a.test', 's1', 'sp' );
		$this->insert_auth( 'other.test', 'o1', 'op' );

		generate_site_auth_files( 'a.test', Site::find( 'a.test' ) );

		$this->assertSame( [ '_wildcard.a.test', '_wildcard.b.test', 'a.test', 'c.test' ], self::files( $this->htpasswd ) );
		foreach ( self::files( $this->htpasswd ) as $name ) {
			$this->assertSame( "g1:gp\ns1:sp\n", $this->htpasswd_file( $name ), $name );
		}
		$this->assertCount( 2, $this->runner->matching( '/ htpasswd /' ), 'htpasswd runs for the site file only; the others are copies.' );
	}

	/** AUTH-22 (won't fix, by decision): without own entries every htpasswd file of the site is removed, hand-made ones included. */
	public function test_generate_site_auth_files_without_site_users_removes_the_files() {
		$this->insert_site( [ 'site_url' => 'a.test', 'app_sub_type' => 'subdom', 'alias_domains' => 'x.test' ] );
		$this->insert_auth( 'default', 'g1', 'gp' );
		foreach ( [ 'default', 'a.test', '_wildcard.a.test', 'x.test', 'unrelated.test' ] as $name ) {
			file_put_contents( "$this->htpasswd/$name", 'x' );
		}

		generate_site_auth_files( 'a.test', Site::find( 'a.test' ) );

		$this->assertSame( [ 'default', 'unrelated.test' ], self::files( $this->htpasswd ) );
		$this->assertSame( [], $this->runner->commands() );
	}

	/** When htpasswd can't run (e.g. the proxy is down), the existing site file is still spread to new domains. */
	public function test_generate_site_auth_files_copies_the_existing_file_when_the_write_fails() {
		$this->insert_site( [ 'site_url' => 'a.test', 'alias_domains' => 'new.test' ] );
		$this->insert_auth( 'a.test', 's1', 'sp' );
		file_put_contents( "$this->htpasswd/a.test", "s1:old\n" );
		$this->failing_users = [ 's1' ];

		generate_site_auth_files( 'a.test', Site::find( 'a.test' ) );

		$this->assertSame( "s1:old\n", $this->htpasswd_file( 'new.test' ) );
		$this->assertSame( "s1:old\n", $this->htpasswd_file( 'a.test' ) );
	}

	public function test_generate_site_auth_files_includes_aliases_about_to_be_added() {
		$this->insert_site( [ 'site_url' => 'a.test' ] );
		$this->insert_auth( 'a.test', 's1', 'sp' );

		generate_site_auth_files( 'a.test', Site::find( 'a.test' ), [ '*.new.test', 'default' ] );

		$this->assertSame( [ '_wildcard.new.test', 'a.test' ], self::files( $this->htpasswd ) );
	}

	public function test_get_site_whitelist_ips_merges_global_ips_only_for_sites_with_own_entries() {
		$this->insert_ips( 'default', [ '10.0.0.0/16', '1.1.1.1' ] );
		$this->insert_ips( 'a.test', [ '2.2.2.2' ] );

		// Global IPs first; within a scope SQLite returns them in UNIQUE(site_url, ip) index order.
		$this->assertSame( [ '1.1.1.1', '10.0.0.0/16', '2.2.2.2' ], get_site_whitelist_ips( 'a.test' ) );
		$this->assertSame( [], get_site_whitelist_ips( 'b.test' ), 'Sites without own entries use default_acl.' );
		$this->assertSame( [ '1.1.1.1', '10.0.0.0/16' ], get_site_whitelist_ips( 'default' ) );
	}

	public function test_generate_site_whitelist_writes_an_acl_file_per_domain() {
		$this->insert_site( [ 'site_url' => 'a.test', 'app_sub_type' => 'subdom', 'alias_domains' => '*.b.test' ] );
		$this->insert_ips( 'default', [ '10.0.0.0/16' ] );
		$this->insert_ips( 'a.test', [ '2001:db8::/32', '2.2.2.2' ] );

		generate_site_whitelist( 'a.test', Site::find( 'a.test' ) );

		$this->assertSame( [ '_wildcard.a.test_acl', '_wildcard.b.test_acl', 'a.test_acl' ], self::files( $this->vhost ) );
		$this->assertSame( "satisfy any;\nallow 10.0.0.0/16;\nallow 2.2.2.2;\nallow 2001:db8::/32;\ndeny all;", $this->acl_file( '_wildcard.b.test' ) );
		$this->assertSame( $this->acl_file( 'a.test' ), $this->acl_file( '_wildcard.a.test' ) );
	}

	public function test_generate_site_whitelist_without_site_ips_removes_the_acl_files() {
		$this->insert_site( [ 'site_url' => 'a.test', 'app_sub_type' => 'subdom' ] );
		$this->insert_ips( 'default', [ '10.0.0.0/16' ] );
		foreach ( [ 'default', 'a.test', '_wildcard.a.test' ] as $name ) {
			file_put_contents( "$this->vhost/{$name}_acl", 'x' );
		}
		file_put_contents( "$this->vhost/a.test", 'custom vhost config' );

		generate_site_whitelist( 'a.test', Site::find( 'a.test' ) );

		$this->assertSame( [ 'a.test', 'default_acl' ], self::files( $this->vhost ) );
	}

	public function test_put_ips_to_file_skips_an_empty_list() {
		put_ips_to_file( "$this->vhost/a.test_acl", [] );
		$this->assertFileDoesNotExist( "$this->vhost/a.test_acl" );

		put_ips_to_file( "$this->vhost/a.test_acl", [ '1.1.1.1' ] );
		$this->assertSame( self::acl( [ '1.1.1.1' ] ), $this->acl_file( 'a.test' ) );
	}

	public function test_site_auth_files_missing_checks_the_files_the_site_entries_call_for() {
		$this->insert_auth( 'a.test', 's1', 'sp' );
		file_put_contents( "$this->htpasswd/x.test", 'x' );

		$this->assertFalse( site_auth_files_missing( 'a.test', [ 'x.test' ] ) );
		$this->assertTrue( site_auth_files_missing( 'a.test', [ 'x.test', 'y.test' ] ) );
		$this->assertFalse( site_auth_files_missing( 'b.test', [ 'y.test' ] ), 'No entries, so no files are needed.' );

		$this->insert_ips( 'a.test', [ '1.1.1.1' ] );
		$this->assertTrue( site_auth_files_missing( 'a.test', [ 'x.test' ] ), 'The ACL file is missing now.' );
		file_put_contents( "$this->vhost/x.test_acl", 'x' );
		$this->assertFalse( site_auth_files_missing( 'a.test', [ 'x.test' ] ) );
	}

	public function test_init_global_admin_tools_auth_is_a_no_op_when_global_auth_exists() {
		$this->insert_auth( 'default', 'g1', 'gp' );

		init_global_admin_tools_auth();

		$this->assertSame( [], $this->runner->commands() );
		$this->assertCount( 1, EE\Tests\TestDB::rows( 'auth_users' ) );
		$this->assertStringContainsString( 'Global auth exists on admin-tools.', $this->stdout() );
	}
}
