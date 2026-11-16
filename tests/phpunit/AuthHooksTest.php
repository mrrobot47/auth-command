<?php

use EE\Tests\TestDB;

/**
 * Site hooks in src/helper/hooks.php: site cleanup and the alias domain changes.
 */
class AuthHooksTest extends AuthTestCase {

	public function hook_registrations() {
		return [
			[ 'site_cleanup', 'cleanup_auth_and_whitelist' ],
			[ 'site_alias_domains_before_update', 'add_auth_before_alias_domains_update' ],
			[ 'site_alias_domains_updated', 'update_auth_on_alias_domains_change' ],
			[ 'site_alias_domains_update_failed', 'remove_auth_on_alias_domains_update_failure' ],
		];
	}

	/**
	 * AUTH-11: the hooks file is autoloaded, so site-command's hooks reach these callbacks.
	 *
	 * @dataProvider hook_registrations
	 */
	public function test_hook_is_registered( $hook, $callback ) {
		$hooks = self::get_static( 'EE', 'hooks' );
		$this->assertContains( $callback, isset( $hooks[ $hook ] ) ? $hooks[ $hook ] : [] );
	}

	public function test_cleanup_removes_the_site_rows_and_files_and_reloads() {
		$this->insert_site( [ 'site_url' => 'a.test', 'app_sub_type' => 'subdom', 'alias_domains' => '*.b.test' ] );
		$this->insert_auth( 'a.test', 'u1', 'p1' );
		$this->insert_auth( 'default', 'g1', 'gp' );
		$this->insert_ips( 'a.test', [ '1.1.1.1' ] );
		$this->insert_ips( 'c.test', [ '1.1.1.1' ] );
		foreach ( [ 'a.test', '_wildcard.a.test', '_wildcard.b.test', 'c.test', 'default' ] as $name ) {
			file_put_contents( "$this->htpasswd/$name", 'x' );
			file_put_contents( "$this->vhost/{$name}_acl", 'x' );
		}

		cleanup_auth_and_whitelist( 'a.test' );

		$this->assertSame( [ [ 'default', 'g1' ] ], self::pairs( 'auth_users', 'username' ) );
		$this->assertSame( [ [ 'c.test', '1.1.1.1' ] ], self::pairs( 'auth_ips', 'ip' ) );
		$this->assertSame( [ 'c.test', 'default' ], self::files( $this->htpasswd ) );
		$this->assertSame( [ 'c.test_acl', 'default_acl' ], self::files( $this->vhost ) );
		$this->assertCount( 3, $this->reloads() );
	}

	/** Files left by older versions are removed even without rows. */
	public function test_cleanup_removes_leftover_files_of_a_site_without_rows() {
		$this->insert_site( [ 'site_url' => 'a.test' ] );
		file_put_contents( "$this->vhost/a.test_acl", 'x' );

		cleanup_auth_and_whitelist( 'a.test' );

		$this->assertSame( [], self::files( $this->vhost ) );
		$this->assertCount( 3, $this->reloads() );
	}

	public function test_cleanup_does_nothing_for_a_clean_or_unknown_site() {
		$this->insert_site( [ 'site_url' => 'a.test' ] );
		$this->insert_auth( 'gone.test', 'u1', 'p1' );

		cleanup_auth_and_whitelist( 'a.test' );
		cleanup_auth_and_whitelist( 'gone.test' );

		$this->assertCount( 1, TestDB::rows( 'auth_users' ), 'Rows of a site not in the DB are left alone.' );
		$this->assertSame( [], $this->runner->commands() );
	}

	public function test_before_alias_update_writes_files_for_the_new_aliases_without_reloading() {
		$this->insert_site( [ 'site_url' => 'a.test', 'alias_domains' => 'old.test' ] );
		$this->insert_auth( 'a.test', 'u1', 'p1' );
		$this->insert_ips( 'a.test', [ '1.1.1.1' ] );

		add_auth_before_alias_domains_update( 'a.test', [ '*.new.test', '_wildcard.evil.test' ] );

		$this->assertSame( [ '_wildcard.new.test', 'a.test', 'old.test' ], self::files( $this->htpasswd ) );
		$this->assertSame( [ '_wildcard.new.test_acl', 'a.test_acl', 'old.test_acl' ], self::files( $this->vhost ) );
		$this->assertSame( [], $this->reloads() );
	}

	public function test_before_alias_update_does_nothing_without_new_aliases() {
		$this->insert_site( [ 'site_url' => 'a.test' ] );
		$this->insert_auth( 'a.test', 'u1', 'p1' );

		add_auth_before_alias_domains_update( 'a.test', [] );
		add_auth_before_alias_domains_update( 'gone.test', [ 'x.test' ] );

		$this->assertSame( [], $this->runner->commands() );
	}

	/** Removed aliases lose their files, except ones the site still uses (its own `_wildcard.<site>`). */
	public function test_alias_change_removes_files_of_removed_aliases_but_keeps_used_ones() {
		$this->insert_site( [ 'site_url' => 'a.test', 'app_sub_type' => 'subdom', 'alias_domains' => 'kept.test' ] );
		$this->insert_auth( 'a.test', 'u1', 'p1' );
		foreach ( [ 'a.test', '_wildcard.a.test', 'kept.test', 'gone.test' ] as $name ) {
			file_put_contents( "$this->htpasswd/$name", "u1:p1\n" );
		}

		update_auth_on_alias_domains_change( 'a.test', [], [ 'gone.test', '*.a.test', 'default' ] );

		$this->assertSame( [ '_wildcard.a.test', 'a.test', 'kept.test' ], self::files( $this->htpasswd ) );
		$this->assertCount( 3, $this->reloads() );
		$this->assertSame( [], $this->runner->matching( '/ htpasswd /' ), 'Nothing was missing, so nothing is rewritten.' );
	}

	/** Added aliases whose files are missing (the before-hook failed) get them, then the proxy reloads. */
	public function test_alias_change_writes_missing_files_of_added_aliases() {
		$this->insert_site( [ 'site_url' => 'a.test', 'alias_domains' => 'new.test' ] );
		$this->insert_auth( 'a.test', 'u1', 'p1' );

		update_auth_on_alias_domains_change( 'a.test', [ 'new.test' ], [] );

		$this->assertSame( "u1:p1\n", $this->htpasswd_file( 'new.test' ) );
		$this->assertCount( 3, $this->reloads() );
	}

	public function test_alias_change_without_file_changes_does_not_reload() {
		$this->insert_site( [ 'site_url' => 'a.test', 'alias_domains' => 'new.test' ] );

		update_auth_on_alias_domains_change( 'a.test', [ 'new.test' ], [ 'gone.test' ] );

		$this->assertSame( [], $this->runner->commands() );
	}

	public function test_alias_update_failure_removes_only_files_the_site_does_not_use() {
		$this->insert_site( [ 'site_url' => 'a.test', 'alias_domains' => 'old.test' ] );
		foreach ( [ 'a.test', 'old.test', 'new.test', '_wildcard.w.test' ] as $name ) {
			file_put_contents( "$this->htpasswd/$name", 'x' );
			file_put_contents( "$this->vhost/{$name}_acl", 'x' );
		}

		remove_auth_on_alias_domains_update_failure( 'a.test', [ 'new.test', 'old.test', '*.w.test' ] );

		$this->assertSame( [ 'a.test', 'old.test' ], self::files( $this->htpasswd ) );
		$this->assertSame( [ 'a.test_acl', 'old.test_acl' ], self::files( $this->vhost ) );
		$this->assertCount( 3, $this->reloads() );
	}
}
