<?php

use EE\Tests\SiteType\SiteTypeTestCase;

/**
 * WordPress::create(): what the create derives from its arguments before any container or database work.
 */
class CreateOptionsTest extends SiteTypeTestCase {

	const SITE_CLASS = 'EE\Site\Type\WordPress';
	const SITE_TYPE  = 'wp';

	/** @return array site_data once the create reaches nginx_proxy_check(). */
	private function site_data_at_proxy_check( $site_name, array $assoc_args = [] ) {
		$site = $this->make_site();
		$this->assertSame( 'proxy-check', $this->run_create( $site, $site_name, $assoc_args ) );

		return $site->site_data;
	}

	public function default_db_names() {
		return [
			'dots'            => [ 'example.com', 'example_com' ],
			'dashes and dots' => [ 'my-site.example.test', 'my_site_example_test' ],
			'upper case'      => [ 'Example.COM', 'example_com' ],
			'trailing slash'  => [ 'example.com/', 'example_com' ],
		];
	}

	/**
	 * @dataProvider default_db_names
	 */
	public function test_default_db_name_replaces_dots_and_dashes( $site_name, $db_name ) {
		$data = $this->site_data_at_proxy_check( $site_name );
		$this->assertSame( $db_name, $data['db_name'] );
		$this->assertSame( 'global-db', $data['db_host'] );
		$this->assertSame( '3306', $data['db_port'] );
		$this->assertSame( '', $data['db_root_password'] );
	}

	/**
	 * PHPT-2 (same derivation, WPT-1; fixed in v1.12.0): a site name longer than 64 characters gives a default database name MySQL rejects.
	 *
	 * @group WPT-1
	 * @group PHPT-2
	 */
	public function test_default_db_name_fits_the_mysql_identifier_limit() {
		$site_name = str_repeat( 'a', 60 ) . '.example.test';
		$data      = $this->site_data_at_proxy_check( $site_name );
		$this->assertLessThanOrEqual( 64, strlen( $data['db_name'] ), $data['db_name'] );
	}

	public function test_default_db_user_is_the_site_name_with_a_random_suffix() {
		$data = $this->site_data_at_proxy_check( 'a-b.test' );
		$this->assertMatchesRegularExpression( '/^a-b\.test-[A-Za-z0-9]{6}$/', $data['db_user'] );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]{18}$/', $data['db_password'] );
	}

	public function test_default_db_user_cuts_long_site_names_to_53_characters() {
		$site_name = str_repeat( 'b', 40 ) . '.' . str_repeat( 'c', 30 ) . '.test';
		$data      = $this->site_data_at_proxy_check( $site_name );
		$this->assertMatchesRegularExpression( '/^b{40}\.c{12}-[A-Za-z0-9]{6}$/', $data['db_user'] );
	}

	public function test_explicit_db_credentials_are_kept() {
		$data = $this->site_data_at_proxy_check( 'example.test', [ 'dbname' => 'shop', 'dbuser' => 'shopper', 'dbpass' => 'secret' ] );
		$this->assertSame( [ 'shop', 'shopper', 'secret' ], [ $data['db_name'], $data['db_user'], $data['db_password'] ] );
	}

	public function test_local_db_gets_its_own_root_password_and_skips_the_global_db() {
		$this->services_running();
		$site = $this->make_site();
		$this->assertSame( 'webroot', $this->run_create( $site, 'example.test', [ 'local-db' => true ] ) );
		$this->assertSame( 'db', $site->site_data['db_host'] );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]{18}$/', $site->site_data['db_root_password'] );
		$this->assertSame( [], $this->global_db->statements );
	}

	public function test_remote_db_host_and_port_are_split() {
		$this->services_running();
		$site = $this->make_site();
		$this->run_create( $site, 'example.test', [ 'dbhost' => '10.0.0.5:3307', 'dbuser' => 'u', 'dbpass' => 'p' ] );
		$this->assertSame( [ '10.0.0.5', '3307' ], [ $site->site_data['db_host'], $site->site_data['db_port'] ] );
		$this->assertSame( [], $this->global_db->statements );
	}

	public function test_remote_db_host_requires_user_and_password() {
		$this->services_running();
		$this->assert_ee_error(
			function () {
				$this->run_create( $this->make_site(), 'example.test', [ 'dbhost' => 'db.example.com', 'dbuser' => 'u' ] );
			},
			'`--dbuser` and `--dbpass` are required for remote db host.'
		);
	}

	public function supported_php_versions() {
		return [
			'default' => [ [], '8.4' ],
			'7.4'     => [ [ 'php' => '7.4' ], '7.4' ],
			'8.0'     => [ [ 'php' => '8.0' ], '8.0' ],
			'5.6'     => [ [ 'php' => '5.6' ], '5.6' ],
			'latest'  => [ [ 'php' => 'latest' ], 'latest' ],
		];
	}

	/**
	 * @dataProvider supported_php_versions
	 */
	public function test_supported_php_versions_are_kept( $assoc_args, $expected ) {
		$data = $this->site_data_at_proxy_check( 'example.test', $assoc_args );
		$this->assertSame( $expected, (string) $data['php_version'] );
	}

	public function test_php_versions_outside_the_list_fail_validation() {
		$this->assert_ee_error(
			function () {
				$this->create_args( [ 'example.test' ], [ 'php' => '9.1' ] );
			},
			"Invalid value specified for 'php'"
		);
	}

	public function loosely_matching_php_versions() {
		return [
			'8'    => [ '8' ],
			'8.10' => [ '8.10' ],
			'7'    => [ '7' ],
			'5.60' => [ '5.60' ],
			'08.4' => [ '08.4' ],
		];
	}

	/**
	 * WPT-4/WPT-11: values that equal a listed version only as numbers pass the loose checks and reach the compose file as-is.
	 *
	 * @dataProvider loosely_matching_php_versions
	 * @group known-bug
	 * @group WPT-4
	 * @group WPT-11
	 */
	public function test_php_version_is_one_of_the_supported_versions( $php ) {
		$data = $this->site_data_at_proxy_check( 'example.test', [ 'php' => $php ] );
		$this->assertContains( (string) $data['php_version'], [ '5.6', '7.0', '7.2', '7.3', '7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5', 'latest' ] );
	}

	public function test_proxy_cache_on_turns_on_the_global_redis_cache() {
		$data = $this->site_data_at_proxy_check( 'example.test', [ 'proxy-cache' => 'on' ] );
		$this->assertSame( 'on', $data['proxy_cache'] );
		$this->assertSame( 'global-redis', $data['cache_host'] );
	}

	public function cache_flags() {
		return [
			'no cache'          => [ [], '' ],
			'global redis'      => [ [ 'cache' => true ], 'global-redis' ],
			'local redis'       => [ [ 'cache' => true, 'with-local-redis' => true ], 'redis' ],
			'proxy cache local' => [ [ 'proxy-cache' => 'on', 'with-local-redis' => true ], 'redis' ],
		];
	}

	/**
	 * @dataProvider cache_flags
	 */
	public function test_cache_host_follows_the_cache_flags( $assoc_args, $cache_host ) {
		$this->assertSame( $cache_host, $this->site_data_at_proxy_check( 'example.test', $assoc_args )['cache_host'] );
	}

	/**
	 * WPT-6: `--with-local-redis` alone is ignored, although its help says it enables the cache with a local redis.
	 *
	 * @group known-bug
	 * @group WPT-6
	 */
	public function test_with_local_redis_alone_enables_the_local_redis_cache() {
		$this->assertSame( 'redis', $this->site_data_at_proxy_check( 'example.test', [ 'with-local-redis' => true ] )['cache_host'] );
	}

	/**
	 * WPT-3: the `on`/`off` options are loaded as booleans, so any value passes the `options:` check.
	 *
	 * @group known-bug
	 * @group WPT-3
	 */
	public function test_proxy_cache_options_are_the_strings_on_and_off() {
		$this->assertSame( [ 'default' => 'off', 'options' => [ 'on', 'off' ] ], $this->create_param_args( 'proxy-cache' ) );
	}

	/**
	 * WPT-3: an invalid `--proxy-cache` value is accepted and stored.
	 *
	 * @group known-bug
	 * @group WPT-3
	 */
	public function test_proxy_cache_rejects_other_values() {
		$this->assert_ee_error(
			function () {
				$this->create_args( [ 'example.test' ], [ 'proxy-cache' => 'maybe' ] );
			},
			"Invalid value specified for 'proxy-cache'"
		);
	}

	public function test_php_options_keep_every_listed_version() {
		$php = $this->create_param_args( 'php' );
		$this->assertSame( [ '5.6', '7.0', '7.2', '7.3', '7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5', 'latest' ], array_map( 'strval', $php['options'] ) );
		$this->assertSame( '8.4', (string) $php['default'] );
	}

	public function public_dirs() {
		return [
			'none'             => [ [], '/var/www/htdocs' ],
			'relative'         => [ [ 'public-dir' => 'current' ], '/var/www/htdocs/current' ],
			'nested'           => [ [ 'public-dir' => 'app/public/' ], '/var/www/htdocs/app/public' ],
			'full path'        => [ [ 'public-dir' => '/var/www/htdocs/web' ], '/var/www/htdocs/web' ],
		];
	}

	/**
	 * @dataProvider public_dirs
	 */
	public function test_public_dir_becomes_the_container_document_root( $assoc_args, $path ) {
		$this->assertSame( $path, $this->site_data_at_proxy_check( 'example.test', $assoc_args )['site_container_fs_path'] );
	}

	/**
	 * WPT-6: `[--public-dir]` is declared as a flag, so a bare `--public-dir` (true) becomes the document root `htdocs/1`.
	 *
	 * @group known-bug
	 * @group WPT-6
	 */
	public function test_bare_public_dir_does_not_become_a_directory_named_1() {
		$this->assertNotSame( '/var/www/htdocs/1', $this->site_data_at_proxy_check( 'example.test', [ 'public-dir' => true ] )['site_container_fs_path'] );
	}

	public function test_multisite_subdom_serves_the_wildcard_and_needs_wildcard_ssl() {
		$data = $this->site_data_at_proxy_check( 'example.test', [ 'mu' => 'subdom', 'alias-domains' => 'a.test, b.test ,' ] );
		$this->assertSame( 'subdom', $data['app_sub_type'] );
		$this->assertTrue( $data['site_ssl_wildcard'] );
		$this->assertSame( 'example.test,*.example.test,a.test,b.test', $data['alias_domains'] );
	}

	public function test_single_site_aliases_follow_the_site_name() {
		$data = $this->site_data_at_proxy_check( 'Example.test', [ 'alias-domains' => 'www.example.test' ] );
		$this->assertSame( 'wp', $data['app_sub_type'] );
		$this->assertFalse( $data['site_ssl_wildcard'] );
		$this->assertSame( 'example.test,www.example.test', $data['alias_domains'] );
	}

	public function test_unknown_multisite_type_is_refused() {
		$this->assert_ee_error(
			function () {
				$this->run_create( $this->make_site(), 'example.test', [ 'mu' => 'subfolder' ] );
			},
			'Unrecognized multi-site parameter: subfolder.'
		);
	}

	public function test_existing_site_is_refused() {
		$this->insert_site( [ 'site_url' => 'example.test', 'site_type' => 'wp' ] );
		$this->assert_ee_error(
			function () {
				$this->run_create( $this->make_site(), 'example.test' );
			},
			'Site example.test already exists.'
		);
	}

	public function test_alias_of_another_site_is_refused() {
		$this->insert_site( [ 'site_url' => 'other.test', 'site_type' => 'wp', 'alias_domains' => 'other.test,shop.test' ] );
		$this->assert_ee_error(
			function () {
				$this->run_create( $this->make_site(), 'example.test', [ 'alias-domains' => 'shop.test' ] );
			},
			'Site shop.test already exists as an alias domain for site: other.test.'
		);
	}

	/**
	 * PHPT-7 (same in WordPress.php): another site's name is accepted as an alias domain, so two sites claim the same VIRTUAL_HOST.
	 *
	 * @group known-bug
	 * @group PHPT-7
	 */
	public function test_existing_site_name_is_refused_as_an_alias() {
		$this->insert_site( [ 'site_url' => 'other.test', 'site_type' => 'wp', 'alias_domains' => 'other.test' ] );
		$this->assert_ee_error(
			function () {
				$this->run_create( $this->make_site(), 'example.test', [ 'alias-domains' => 'other.test' ] );
			},
			'other.test'
		);
	}

	public function test_subdom_multisite_is_refused_when_its_wildcard_is_another_sites_alias() {
		$this->insert_site( [ 'site_url' => 'other.test', 'site_type' => 'wp', 'alias_domains' => 'other.test,*.example.test' ] );
		$this->assert_ee_error(
			function () {
				$this->run_create( $this->make_site(), 'example.test', [ 'mu' => 'subdom' ] );
			},
			'*.example.test is already an alias domain of site other.test.'
		);
	}

	/**
	 * PHPT-4 (same helper for wp; fixed in v1.12.0): `ab.test` gets the volumes `abtest_*` of the existing site `a-b.test`.
	 *
	 * @group PHPT-4
	 */
	public function test_site_whose_volume_prefix_is_taken_is_refused() {
		$this->insert_site( [ 'site_url' => 'a-b.test', 'site_type' => 'wp' ] );
		$this->assert_ee_error(
			function () {
				$this->run_create( $this->make_site(), 'ab.test' );
			},
			'a-b.test'
		);
	}

	public function bad_admin_passwords() {
		return [
			'short'      => [ 'abc123', 'Password too short! Must be at least 8 characters long.' ],
			'no number'  => [ 'abcdefghij', 'Password must include at least one number!' ],
			'no letter'  => [ '1234567890', 'Password must include at least one letter!' ],
		];
	}

	/**
	 * @dataProvider bad_admin_passwords
	 */
	public function test_weak_admin_passwords_are_refused( $password, $message ) {
		$this->assert_ee_error(
			function () use ( $password ) {
				$this->run_create( $this->make_site(), 'example.test', [ 'admin-pass' => $password ] );
			},
			$message
		);
	}

	public function test_admin_credentials_defaults_and_insecure_override() {
		$data = $this->site_data_at_proxy_check( 'example.test' );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]{18}$/', $data['app_admin_password'] );
		$this->assertStringStartsWith( 'wp-user-', $data['app_admin_username'] );
		$this->assertSame( 'example.test', $data['app_admin_url'] );

		$data = $this->site_data_at_proxy_check( 'example.test', [ 'admin-pass' => 'abc', 'allow-insecure-pass' => true, 'admin-user' => 'boss', 'title' => 'Shop' ] );
		$this->assertSame( [ 'abc', 'boss', 'Shop' ], [ $data['app_admin_password'], $data['app_admin_username'], $data['app_admin_url'] ] );
	}

	public function ssl_flags() {
		return [
			'none'      => [ [], '' ],
			'bare flag' => [ [ 'ssl' => true ], 'le' ],
			'self'      => [ [ 'ssl' => 'self' ], 'self' ],
			'inherit'   => [ [ 'ssl' => 'inherit' ], 'inherit' ],
		];
	}

	/**
	 * @dataProvider ssl_flags
	 */
	public function test_ssl_flag_value( $assoc_args, $ssl ) {
		$this->assertSame( $ssl, $this->site_data_at_proxy_check( 'example.test', $assoc_args )['site_ssl'] );
	}

	/**
	 * WPT-13: `[--ssl]` is declared as a flag, so its `options:` list is never checked and `--ssl=bogus` starts a full create.
	 *
	 * @group known-bug
	 * @group WPT-13
	 */
	public function test_unknown_ssl_value_fails_validation() {
		$this->assert_ee_error(
			function () {
				$this->create_args( [ 'example.test' ], [ 'ssl' => 'bogus' ] );
			},
			"Invalid value specified for 'ssl'"
		);
	}

	public function test_custom_ssl_needs_existing_key_and_cert() {
		$this->assertSame( 'cleanup', $this->run_create( $this->make_site(), 'example.test', [ 'ssl' => 'custom', 'ssl-key' => '/nonexistent.key', 'ssl-crt' => '/nonexistent.crt' ] ) );
		$this->assertStringContainsString( 'ssl-key OR ssl-crt path does not exist', $this->stderr() );
	}

	public function test_inherit_ssl_is_refused_for_subdom_multisite() {
		$this->services_running();
		$this->assert_ee_error(
			function () {
				$this->run_create( $this->make_site(), 'example.test', [ 'ssl' => 'inherit', 'mu' => 'subdom' ] );
			},
			'--wildcard or --mu=subdom flag can not be passed together with --ssl=inherit flag.'
		);
	}

	/**
	 * WPT-8 (fixed in v1.12.0): the inherit + subdom refusal comes after the site's database and user were created, and nothing drops them.
	 *
	 * @group WPT-8
	 */
	public function test_inherit_ssl_with_subdom_is_refused_before_the_database_is_created() {
		$this->services_running();
		try {
			$this->run_create( $this->make_site(), 'example.test', [ 'ssl' => 'inherit', 'mu' => 'subdom' ] );
		} catch ( EE\ExitException $e ) {
			// Refused, as expected; what matters is what ran first.
		}
		$this->assertSame( [], $this->global_db->statements_matching( '/^CREATE /' ) );
	}

	public function test_admin_email_defaults_to_the_site_or_the_wp_mail_config() {
		$this->services_running();
		$site = $this->make_site();
		$this->run_create( $site, 'example.test' );
		$this->assertSame( 'admin@example.test', $site->site_data['app_admin_email'] );

		$this->set_config( [ 'wp-mail' => 'Ops@Example.COM' ] );
		$site = $this->make_site();
		$this->run_create( $site, 'other.test' );
		$this->assertSame( 'ops@example.com', $site->site_data['app_admin_email'] );
	}
}
