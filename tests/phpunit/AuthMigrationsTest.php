<?php

use EE\Migration\AddFrontendIp;
use EE\Migration\RegenerateSiteAuthFiles;
use EE\Model\Option;

require_once dirname( __DIR__, 2 ) . '/migrations/container/20210830165250_auth-command_add_frontend_ip.php';
require_once dirname( __DIR__, 2 ) . '/migrations/container/20260924120000_auth-command_regenerate_site_auth_files.php';

/**
 * auth-command container migrations: the site auth file regeneration and the frontend subnet whitelist.
 */
class AuthMigrationsTest extends AuthTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->docker_on_path();
	}

	/** An upgrade (not a fresh install): the DB has a version. */
	private function upgrading() {
		Option::set( 'version', '4.12.0' );
	}

	/** nginx-proxy runs the template with (true) or without (false) the acl block that scopes `_wildcard.X` files. */
	private function proxy_template( $new ) {
		$this->runner->on( '/grep -c .*define "acl".* \/app\/nginx\.tmpl$/', $new ? "2\n" : "0\n", $new ? 0 : 1 );
	}

	private function subdom_site_with_auth() {
		$this->insert_site( [ 'site_url' => 'a.test', 'app_sub_type' => 'subdom' ] );
		$this->insert_auth( 'a.test', 'u1', 'p1' );
		$this->insert_ips( 'a.test', [ '1.1.1.1' ] );
		file_put_contents( "$this->htpasswd/a.test", "u1:p1\n" );
		file_put_contents( "$this->vhost/a.test_acl", self::acl( [ '1.1.1.1' ] ) );
	}

	/** @return string[] name => content of every file in the proxy htpasswd and vhost.d dirs. */
	private function proxy_files() {
		$files = [];
		foreach ( [ $this->htpasswd, $this->vhost ] as $dir ) {
			foreach ( self::files( $dir ) as $name ) {
				$files[ basename( $dir ) . "/$name" ] = file_get_contents( "$dir/$name" );
			}
		}

		return $files;
	}

	public function test_regenerate_is_skipped_on_a_fresh_install() {
		$this->insert_site( [ 'site_url' => 'a.test' ] );
		file_put_contents( "$this->vhost/a.test_acl", 'stale' );

		( new RegenerateSiteAuthFiles() )->up();

		$this->assertSame( [ 'a.test_acl' ], self::files( $this->vhost ) );
		$this->assertSame( [], $this->runner->commands() );
	}

	public function test_regenerate_is_skipped_without_sites() {
		$this->upgrading();

		( new RegenerateSiteAuthFiles() )->up();

		$this->assertSame( [], $this->runner->commands() );
	}

	/**
	 * Every site gets the files its rows call for (subdomains and aliases included), stale files of sites without rows
	 * go (AUTH-10: a site `_acl` holding only global IPs), and the proxy reloads once.
	 */
	public function test_regenerate_writes_every_site_file_and_reloads_once() {
		$this->upgrading();
		$this->proxy_template( true );
		$this->insert_site( [ 'site_url' => 'a.test', 'app_sub_type' => 'subdom', 'alias_domains' => 'www.a.test' ] );
		$this->insert_site( [ 'site_url' => 'b.test' ] );
		$this->insert_auth( 'default', 'g1', 'gp' );
		$this->insert_auth( 'a.test', 'u1', 'p1' );
		$this->insert_ips( 'default', [ '10.0.0.0/16' ] );
		$this->insert_ips( 'a.test', [ '1.1.1.1' ] );
		file_put_contents( "$this->vhost/b.test_acl", self::acl( [ '10.0.0.0/16' ] ) );
		file_put_contents( "$this->htpasswd/b.test", "g1:gp\n" );
		file_put_contents( "$this->htpasswd/default", "g1:gp\n" );

		( new RegenerateSiteAuthFiles() )->up();

		$this->assertSame( [ '_wildcard.a.test', 'a.test', 'default', 'www.a.test' ], self::files( $this->htpasswd ) );
		$this->assertSame( "g1:gp\nu1:p1\n", $this->htpasswd_file( '_wildcard.a.test' ) );
		$this->assertSame( [ '_wildcard.a.test_acl', 'a.test_acl', 'www.a.test_acl' ], self::files( $this->vhost ) );
		$this->assertSame( self::acl( [ '10.0.0.0/16', '1.1.1.1' ] ), $this->acl_file( 'www.a.test' ) );
		$this->assertCount( 3, $this->reloads() );
	}

	public function test_regenerate_warns_and_continues_when_a_site_fails() {
		$this->upgrading();
		$this->proxy_template( true );
		$this->insert_site( [ 'site_url' => 'a.test' ] );
		$this->insert_site( [ 'site_url' => 'b.test' ] );
		$this->insert_ips( 'a.test', [ '1.1.1.1' ] );
		$this->insert_ips( 'b.test', [ '2.2.2.2' ] );
		mkdir( "$this->vhost/a.test_acl" );

		( new RegenerateSiteAuthFiles() )->up();

		$this->assertStringContainsString( 'Could not regenerate the auth files of a.test:', $this->stderr() );
		$this->assertSame( self::acl( [ '2.2.2.2' ] ), $this->acl_file( 'b.test' ) );
		$this->assertCount( 3, $this->reloads() );
	}

	/**
	 * AUTH-6 (fixed in v1.3.1): on the old nginx-proxy template `_wildcard.X` files apply to every sibling site, so the migration must not
	 * put them in the proxy dirs until the new image runs.
	 *
	 * @group AUTH-6
	 */
	public function test_regenerate_keeps_wildcard_files_out_of_the_old_proxy() {
		$this->upgrading();
		$this->proxy_template( false );
		$this->subdom_site_with_auth();

		( new RegenerateSiteAuthFiles() )->up();

		$this->assertSame( [ 'a.test' ], self::files( $this->htpasswd ) );
		$this->assertSame( [ 'a.test_acl' ], self::files( $this->vhost ) );
	}

	/**
	 * AUTH-6 (fixed in v1.3.1): a failed upgrade reverts the migration, which must restore the proxy auth files exactly (down() is empty).
	 *
	 * @group AUTH-6
	 */
	public function test_regenerate_down_restores_the_previous_files() {
		$this->upgrading();
		$this->proxy_template( true );
		$this->subdom_site_with_auth();
		$this->insert_site( [ 'site_url' => 'b.test' ] );
		file_put_contents( "$this->htpasswd/b.test", "hand:made\n" );
		$before = $this->proxy_files();

		$migration = new RegenerateSiteAuthFiles();
		$migration->up();
		$this->assertNotSame( $before, $this->proxy_files(), 'up() changed the files.' );
		$migration->down();

		$this->assertSame( $before, $this->proxy_files() );
	}

	public function test_add_frontend_ip_is_skipped_on_a_fresh_install() {
		( new AddFrontendIp() )->up();

		$this->assertSame( [], $this->runner->commands() );
		$this->assertSame( [], EE\Tests\TestDB::rows( 'auth_ips' ) );
	}

	/** The frontend subnet is whitelisted globally, so requests from the proxy network bypass auth. */
	public function test_add_frontend_ip_whitelists_the_frontend_subnet_globally() {
		$this->upgrading();
		Option::set( 'frontend_subnet_ip', '10.1.0.0/16' );
		Option::set( 'backend_subnet_ip', '10.2.0.0/16' );
		$this->insert_ips( 'default', [ '1.1.1.1' ] );

		( new AddFrontendIp() )->up();

		$this->assertSame( [ [ 'default', '1.1.1.1' ], [ 'default', '10.1.0.0/16' ] ], self::pairs( 'auth_ips', 'ip' ) );
		$this->assertSame( self::acl( [ '1.1.1.1', '10.1.0.0/16' ] ), $this->acl_file( 'default' ) );
		$this->assertCount( 3, $this->reloads() );
	}
}
