<?php

use EE\Tests\TestCase;

/**
 * Base for auth-command tests: empty nginx-proxy `htpasswd/` and `vhost.d/` dirs under EE_ROOT_DIR, a running proxy,
 * and a fake `htpasswd` behind `docker exec` that writes `user:pass` lines into the bind-mounted htpasswd dir.
 */
abstract class AuthTestCase extends TestCase {

	/** @var string Host side of the proxy's /etc/nginx/htpasswd. */
	protected $htpasswd;

	/** @var string Host side of the proxy's /etc/nginx/vhost.d. */
	protected $vhost;

	/** @var string[] Users for which the fake htpasswd fails. */
	protected $failing_users = [];

	private $path_backup;

	protected function setUp(): void {
		parent::setUp();
		$this->htpasswd = EE_ROOT_DIR . '/services/nginx-proxy/htpasswd';
		$this->vhost    = EE_ROOT_DIR . '/services/nginx-proxy/vhost.d';
		exec( 'rm -rf ' . escapeshellarg( EE_ROOT_DIR . '/services/nginx-proxy' ) . ' ' . escapeshellarg( EE_BACKUP_DIR ) );
		mkdir( $this->htpasswd, 0755, true );
		mkdir( $this->vhost, 0755, true );
		mkdir( EE_BACKUP_DIR, 0700, true );
		$this->failing_users = [];

		$this->runner->on( "/^docker inspect -f '\{\{\.State\.Running\}\}' services_global-nginx-proxy_1$/", "true\n" );
		$this->runner->on( '/^docker inspect --format .*"80\/tcp"/', "80\n" );
		$this->runner->on( '/^docker inspect --format .*"443\/tcp"/', "443\n" );
		$this->runner->on( '/^docker exec services_global-nginx-proxy_1 htpasswd -/', [ $this, 'fake_htpasswd' ] );

		$this->path_backup = getenv( 'PATH' );
	}

	protected function tearDown(): void {
		putenv( 'PATH=' . $this->path_backup );
		parent::tearDown();
	}

	/**
	 * EE_DOCKER::container_status() first runs a real `which docker`; put a dummy docker on PATH so it passes.
	 * The dummy is never run: EE::exec()/launch() go to the fake runner.
	 */
	protected function docker_on_path() {
		$bin = $this->sandbox() . '/bin';
		mkdir( $bin );
		file_put_contents( "$bin/docker", "#!/bin/sh\nexit 0\n" );
		chmod( "$bin/docker", 0755 );
		putenv( "PATH=$bin:" . $this->path_backup );
	}

	/** Runner answer for `docker exec <proxy> htpasswd -b[c] <file> <user> <pass>`. */
	public function fake_htpasswd( $command ) {
		$words = self::shell_words( $command );
		list( , , , , $flags, $file, $user, $pass ) = $words;
		if ( in_array( $user, $this->failing_users, true ) ) {
			return [ '', 1 ];
		}
		$path = $this->htpasswd . '/' . substr( $file, strlen( '/etc/nginx/htpasswd/' ) );
		file_put_contents( $path, "$user:$pass\n", false !== strpos( $flags, 'c' ) ? 0 : FILE_APPEND );

		return [ '', 0 ];
	}

	/** Splits a command built with escapeshellarg() into words (single quotes and backslash escapes only). */
	protected static function shell_words( $command ) {
		$words  = [];
		$word   = null;
		$length = strlen( $command );
		for ( $i = 0; $i < $length; $i++ ) {
			$char = $command[ $i ];
			if ( "'" === $char ) {
				$end   = strpos( $command, "'", $i + 1 );
				$word .= substr( $command, $i + 1, $end - $i - 1 );
				$i     = $end;
			} elseif ( '\\' === $char ) {
				$word .= $command[ ++$i ];
			} elseif ( ' ' === $char ) {
				if ( null !== $word ) {
					$words[] = $word;
					$word    = null;
				}
			} else {
				$word .= $char;
			}
		}
		if ( null !== $word ) {
			$words[] = $word;
		}

		return $words;
	}

	/** Run $fn and turn an unexpected EE::error() into a failure that shows its message. */
	protected function no_ee_error( callable $fn ) {
		try {
			return $fn();
		} catch ( EE\ExitException $e ) {
			$this->fail( 'Unexpected EE::error(): ' . trim( $this->stderr() ) );
		}
	}

	/** @return int Row id. */
	protected function insert_auth( $site_url, $username, $password ) {
		return (int) EE::db()->table( 'auth_users' )->insert( compact( 'site_url', 'username', 'password' ) );
	}

	protected function insert_ips( $site_url, array $ips ) {
		foreach ( $ips as $ip ) {
			EE::db()->table( 'auth_ips' )->insert( compact( 'site_url', 'ip' ) );
		}
	}

	/** @return string|null Content of an htpasswd file, null if it doesn't exist. */
	protected function htpasswd_file( $name ) {
		return is_file( "$this->htpasswd/$name" ) ? file_get_contents( "$this->htpasswd/$name" ) : null;
	}

	/** @return string|null Content of a `<name>_acl` file, null if it doesn't exist. */
	protected function acl_file( $name ) {
		return is_file( "$this->vhost/{$name}_acl" ) ? file_get_contents( "$this->vhost/{$name}_acl" ) : null;
	}

	/** Expected `_acl` content for these IPs. */
	protected static function acl( array $ips ) {
		$content = "satisfy any;\n";
		foreach ( $ips as $ip ) {
			$content .= "allow $ip;\n";
		}

		return $content . 'deny all;';
	}

	/** @return string[] Sorted file names in a dir. */
	protected static function files( $dir ) {
		$files = array_values( array_diff( scandir( $dir ), [ '.', '..' ] ) );
		sort( $files );

		return $files;
	}

	/** @return string[] The proxy reload commands run, in order. */
	protected function reloads() {
		return $this->runner->matching( '/^docker exec services_global-nginx-proxy_1 sh -c "(\/app\/docker-entrypoint|nginx -t|\/usr\/sbin\/nginx -s reload)/' );
	}

	/** @return array[] Rows of $table as [ site_url, <column> ] pairs. */
	protected static function pairs( $table, $column ) {
		return array_map(
			function ( $row ) use ( $column ) {
				return [ $row['site_url'], $row[ $column ] ];
			},
			EE\Tests\TestDB::rows( $table )
		);
	}
}
