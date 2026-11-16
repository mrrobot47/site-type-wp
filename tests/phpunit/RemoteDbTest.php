<?php

use EE\Tests\SiteType\SiteTypeTestCase;

/**
 * WordPress::maybe_verify_remote_db_connection(): the remote database check that runs in a throwaway mariadb container.
 */
class RemoteDbTest extends SiteTypeTestCase {

	const SITE_CLASS = 'EE\Site\Type\WordPress';
	const SITE_TYPE  = 'wp';

	protected $reset_db = false;

	private function verify( array $data = [], $force = false ) {
		$site            = $this->make_site();
		$site->site_data = $data + [
			'site_url'    => 'example.test',
			'db_host'     => '10.0.0.5',
			'db_port'     => '3307',
			'db_user'     => 'remote',
			'db_password' => 'pw',
			'db_name'     => 'shop_db',
		];
		self::set_property( $site, 'force', $force );
		self::call_private( $site, 'maybe_verify_remote_db_connection' );
	}

	/** @return string Name of the temporary container the check started. */
	private function container() {
		preg_match( '/^docker run --name (\S+) /', $this->runner->commands()[0], $m );

		return $m[1];
	}

	public function local_and_global_hosts() {
		return [ [ 'db' ], [ 'global-db' ] ];
	}

	/**
	 * @dataProvider local_and_global_hosts
	 */
	public function test_local_and_global_databases_are_not_checked( $db_host ) {
		$this->verify( [ 'db_host' => $db_host ] );
		$this->assertSame( [], $this->runner->commands() );
	}

	public function test_missing_remote_database_is_created() {
		$this->runner->on( '/mysqlshow /', '', 1 );
		$this->verify();
		$name = $this->container();
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]{18}$/', $name );
		$this->assertSame(
			[
				"docker exec $name sh -c \"mysql --host='10.0.0.5' --port='3307' --user='remote' --password='pw' --execute='EXIT'\"",
				"docker exec $name bash -c \"mysqlshow --user='remote' --password='pw' --host='10.0.0.5' --port='3307' 'shop\\_db'\"",
				"docker exec $name bash -c \"mysql --host='10.0.0.5' --port='3307' --user='remote' --password='pw' --execute='CREATE DATABASE shop_db;'\"",
				"docker rm -f $name",
			],
			array_slice( $this->runner->commands(), 1 )
		);
		$this->assertMatchesRegularExpression( '/^docker run --name \S+  -e MYSQL_ROOT_PASSWORD=[A-Za-z0-9]{18} -d --restart always ' . preg_quote( self::image( 'easyengine/mariadb' ), '/' ) . '$/', $this->runner->commands()[0] );
	}

	public function test_localhost_is_reached_through_the_container_gateway() {
		$this->runner->on( '/ip route show default/', "172.17.0.1\n" );
		$this->verify( [ 'db_host' => 'localhost' ] );
		$this->assertCount( 1, $this->runner->matching( "/ sh -c \\W*mysql --host=\\W*172\.17\.0\.1\\W* --port=\\W*3307\\W* .*EXIT/" ) );
		$this->assertSame( [], $this->runner->matching( '/localhost/' ) );
	}

	public function test_existing_empty_database_is_used() {
		$this->runner->on( '/USE shop_db; show tables;/', "\n" );
		$this->verify();
		$this->assertSame( [], $this->runner->matching( '/CREATE DATABASE|DROP DATABASE/' ) );
		$this->assertSame( 'docker rm -f ' . $this->container(), end( $this->runner->calls )['command'] );
	}

	public function test_existing_database_with_tables_is_refused_unless_forced() {
		$this->runner->on( '/USE shop_db; show tables;/', "wp_posts\nwp_options\n" );
		try {
			$this->verify();
			$this->fail( 'Expected the check to fail.' );
		} catch ( \Exception $e ) {
			$this->assertStringContainsString( 'Some database tables seem to exist in database shop_db.', $e->getMessage() );
		}
		$this->assertSame( 'docker rm -f ' . $this->container(), end( $this->runner->calls )['command'] );
	}

	public function test_force_resets_an_existing_database() {
		$this->runner->on( '/USE shop_db; show tables;/', '' );
		$this->verify( [], true );
		$sql = [];
		foreach ( $this->runner->matching( '/--execute=/' ) as $command ) {
			preg_match( '/(DROP DATABASE shop_db;|CREATE DATABASE shop_db;|USE shop_db; show tables;|EXIT)/', $command, $m );
			$sql[] = $m[1];
		}
		$this->assertSame( [ 'EXIT', 'DROP DATABASE shop_db;', 'CREATE DATABASE shop_db;', 'USE shop_db; show tables;' ], $sql );
	}

	public function failures() {
		return [
			'no container' => [ '/^docker run /', 'There was a problem creating container to test mysql connection.' ],
			'no connect'   => [ '/--execute=\S*EXIT/', 'Unable to connect to remote db' ],
			'no create'    => [ '/CREATE DATABASE/', 'Could not create database `shop_db` on `10.0.0.5:3307`.' ],
		];
	}

	/**
	 * @dataProvider failures
	 */
	public function test_failures_remove_the_temporary_container_and_throw( $failing, $message ) {
		$this->runner->on( '/mysqlshow /', '', 1 );
		$this->runner->on( $failing, '', 1 );
		try {
			$this->verify();
			$this->fail( 'Expected the check to fail.' );
		} catch ( \Exception $e ) {
			$this->assertStringContainsString( $message, $e->getMessage() );
		}
		$this->assertSame( 'docker rm -f ' . $this->container(), end( $this->runner->calls )['command'] );
	}

	/**
	 * WPT-15: `docker rm -f` without `-v` leaks the temporary container's anonymous /var/lib/mysql volume.
	 *
	 * @group known-bug
	 * @group WPT-15
	 */
	public function test_temporary_container_is_removed_with_its_volumes() {
		$this->runner->on( '/mysqlshow /', '', 1 );
		$this->verify();
		$this->assertMatchesRegularExpression( '/^docker rm (-fv|-vf|-f -v|-v -f) /', end( $this->runner->calls )['command'] );
	}

	public function remote_db_fields() {
		return [ [ 'db_user' ], [ 'db_password' ], [ 'db_name' ] ];
	}

	/**
	 * SEC-1 sinks: remote db credentials are interpolated into double-quoted `sh -c`/`bash -c` scripts on the host.
	 *
	 * @dataProvider remote_db_fields
	 * @group known-bug
	 * @group SEC-1
	 */
	public function test_remote_db_check_is_shell_safe( $field ) {
		$this->runner->on( '/mysqlshow /', '', 1 );
		$this->assert_shell_safe(
			function ( $payload ) use ( $field ) {
				$this->verify( [ $field => $payload ] );
			},
			'/^docker exec /'
		);
	}
}
