<?php

use EE\Model\Auth;
use EE\Model\Whitelist;
use EE\Tests\TestDB;

/**
 * EE\Model\Auth validation and the Auth/Whitelist scope queries.
 */
class AuthModelTest extends AuthTestCase {

	public function missing_fields() {
		return [
			[ [ 'username' => 'u', 'password' => 'p' ] ],
			[ [ 'site_url' => 'a.test', 'password' => 'p' ] ],
			[ [ 'site_url' => 'a.test', 'username' => 'u', 'password' => '' ] ],
		];
	}

	/** @dataProvider missing_fields */
	public function test_create_requires_site_user_and_password( $columns ) {
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'site_url, username and password should be provided' );
		Auth::create( $columns );
	}

	/** A global user name can't be one any site (or global) uses; the admin-tools user doesn't count. */
	public function test_global_user_must_be_unique_across_sites() {
		$this->insert_auth( 'a.test', 'taken', 'p' );
		$this->insert_auth( 'default_admin_tools', 'easyengine', 'p' );

		$this->assert_ee_error(
			function () {
				Auth::create( [ 'site_url' => 'default', 'username' => 'taken', 'password' => 'x' ] );
			},
			'There already exists an username taken on some site or globally. You cannot use this username'
		);

		Auth::create( [ 'site_url' => 'default', 'username' => 'easyengine', 'password' => 'x' ] );
		$this->assertCount( 3, TestDB::rows( 'auth_users' ) );
	}

	public function test_site_user_must_not_clash_with_global_or_same_site_users() {
		$this->insert_auth( 'default', 'g1', 'p' );
		$this->insert_auth( 'a.test', 'u1', 'p' );

		foreach ( [ 'g1', 'u1' ] as $user ) {
			$this->assert_ee_error(
				function () use ( $user ) {
					Auth::create( [ 'site_url' => 'a.test', 'username' => $user, 'password' => 'x' ] );
				},
				"There already exists an username $user on this site or globally."
			);
		}

		Auth::create( [ 'site_url' => 'b.test', 'username' => 'u1', 'password' => 'x' ] );
		$this->assertSame( [ [ 'default', 'g1' ], [ 'a.test', 'u1' ], [ 'b.test', 'u1' ] ], self::pairs( 'auth_users', 'username' ) );
	}

	public function test_scope_queries() {
		$this->insert_auth( 'default', 'g1', 'p' );
		$this->insert_auth( 'default_admin_tools', 'easyengine', 'p' );
		$this->insert_auth( 'a.test', 'u1', 'p' );
		$this->insert_ips( 'default', [ '1.1.1.1' ] );
		$this->insert_ips( 'a.test', [ '2.2.2.2' ] );

		$global = Auth::get_global_auths();
		$this->assertCount( 1, $global );
		$this->assertSame( 'g1', $global[0]->username );
		$this->assertSame( 'easyengine', Auth::get_global_admin_tools_auth()[0]->username );
		$this->assertSame( '1.1.1.1', Whitelist::get_global_ips()[0]->ip );
		$this->assertTrue( Whitelist::has_ips( 'a.test' ) );
		$this->assertFalse( Whitelist::has_ips( 'b.test' ) );

		TestDB::pdo()->exec( "DELETE FROM auth_users WHERE site_url = 'default_admin_tools'" );
		$this->assertSame( [], Auth::get_global_admin_tools_auth() );
	}
}
