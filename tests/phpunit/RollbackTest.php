<?php

use EE\Tests\SiteType\SiteTypeTestCase;

/**
 * What a failed WordPress create would drop on the global database (catch_clean() → delete_site() with
 * get_rollback_db_data()), against a fake global MariaDB that holds another site's database and user.
 */
class RollbackTest extends SiteTypeTestCase {

	const SITE_CLASS = 'EE\Site\Type\WordPress';
	const SITE_TYPE  = 'wp';

	protected function setUp(): void {
		parent::setUp();
		// other.test was created with --dbname=a_b_test, the default name of a-b.test.
		$this->insert_site(
			[
				'site_url' => 'other.test',
				'site_type' => 'wp',
				'db_host'   => 'global-db',
				'db_name'   => 'a_b_test',
				'db_user'   => 'other-user',
			]
		);
		$this->services_running( [ 'a_b_test' ], [ 'other-user' ] );
	}

	/** @return array [ stop point or 'refused', rollback db data ] */
	private function create( array $assoc_args = [], $site_name = 'a-b.test' ) {
		$site = $this->make_site();
		try {
			$point = $this->run_create( $site, $site_name, $assoc_args );
		} catch ( EE\ExitException $e ) {
			$point = 'refused';
		}

		return [ $point, self::call_private( $site, 'get_rollback_db_data' ) ];
	}

	public function test_a_fresh_database_and_user_are_created_and_would_be_rolled_back() {
		list( $point, $db_data ) = $this->create( [ 'dbuser' => 'cd-user', 'dbpass' => 'pw1' ], 'c-d.test' );

		$this->assertSame( 'webroot', $point );
		$this->assertSame( [ 'a_b_test', 'c_d_test' ], array_keys( $this->global_db->databases ) );
		$this->assertSame( [ 'other-user', 'cd-user' ], array_keys( $this->global_db->users ) );
		$this->assertCount( 1, $this->global_db->statements_matching( '/^CREATE USER ["\']cd-user["\']@["\']%["\'] IDENTIFIED BY ["\']pw1["\']$/' ) );
		// `_` is a wildcard in a GRANT's database name, so site-command v3.8.1 escapes it.
		$this->assertCount( 1, $this->global_db->statements_matching( '/^' . preg_quote( "GRANT ALL PRIVILEGES ON `c\\_d\\_test`.* TO 'cd-user'@'%'", '/' ) . '$/' ) );
		$this->assertSame( [ 'db_host' => 'global-db', 'db_user' => 'cd-user', 'db_name' => 'c_d_test' ], $db_data );
	}

	public function test_a_local_db_site_never_rolls_back_the_global_db() {
		list( $point, $db_data ) = $this->create( [ 'local-db' => true ], 'c-d.test' );
		$this->assertSame( 'webroot', $point );
		$this->assertSame( [], $db_data );
		$this->assertSame( [], $this->global_db->statements_matching( '/^(CREATE|DROP) /' ) );
	}

	/**
	 * WPT-1 (fixed in v1.12.0): `CREATE DATABASE a_b_test` fails because other.test owns it, and the rollback then drops other.test's database.
	 *
	 * @group WPT-1
	 */
	public function test_taken_default_db_name_is_not_rolled_back() {
		list( , $db_data ) = $this->create();
		$this->assertArrayHasKey( 'a_b_test', $this->global_db->databases );
		$this->assertNotSame( 'a_b_test', isset( $db_data['db_name'] ) ? $db_data['db_name'] : null, 'The rollback would drop other.test\'s database.' );
	}

	/**
	 * WPT-1 (fixed in v1.12.0): `--dbuser=<another site's user>` makes CREATE USER fail, and the rollback then drops that user.
	 *
	 * @group WPT-1
	 */
	public function test_taken_db_user_is_not_rolled_back() {
		list( , $db_data ) = $this->create( [ 'dbuser' => 'other-user', 'dbname' => 'fresh_db' ] );
		$this->assertNotSame( 'other-user', isset( $db_data['db_user'] ) ? $db_data['db_user'] : null, 'The rollback would drop other.test\'s user.' );
	}

	/**
	 * WPT-1 trigger 3 (fixed in v1.12.0): a failed `--ssl=custom` check rolls back before any DB work, still naming the default database.
	 *
	 * @group WPT-1
	 */
	public function test_rollback_before_the_database_step_drops_nothing() {
		list( $point, $db_data ) = $this->create( [ 'ssl' => 'custom', 'ssl-key' => '/nonexistent.key', 'ssl-crt' => '/nonexistent.crt' ] );
		$this->assertSame( 'cleanup', $point );
		$this->assertSame( [], $this->global_db->statements_matching( '/^CREATE /' ) );
		$this->assertSame( [], $db_data );
	}
}
