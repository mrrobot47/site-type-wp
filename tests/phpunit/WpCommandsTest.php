<?php

use EE\Tests\SiteType\SiteTypeTestCase;

/**
 * Commands a WordPress create runs in the site's php container: core download, wp-config, install, cache setup.
 */
class WpCommandsTest extends SiteTypeTestCase {

	const SITE_CLASS = 'EE\Site\Type\WordPress';
	const SITE_TYPE  = 'wp';

	const EXEC = "docker-compose -f docker-compose.yml  exec -T --user='www-data' php ";

	/** @return object A WordPress command for example.test whose site root exists and is the cwd. */
	private function site( array $data = [] ) {
		$site            = $this->make_site();
		$site->site_data = $data + [
			'site_type'              => 'wp',
			'site_url'               => 'example.test',
			'site_fs_path'           => WEBROOT . 'example.test',
			'app_sub_type'           => 'wp',
			'cache_host'             => 'global-redis',
			'db_host'                => 'global-db',
			'db_port'                => '3306',
			'db_name'                => 'example_test',
			'db_user'                => 'example.test-AbC123',
			'db_password'            => 'dbpass',
			'db_root_password'       => '',
			'site_ssl'               => '',
			'site_container_fs_path' => '/var/www/htdocs',
			'app_admin_url'          => 'Example',
			'app_admin_username'     => 'boss',
			'app_admin_password'     => 'Secret123',
			'app_admin_email'        => 'boss@example.test',
		];
		self::set_property( $site, 'locale', 'en_US' );
		if ( ! is_dir( $site->site_data['site_fs_path'] ) ) {
			mkdir( $site->site_data['site_fs_path'] );
		}
		chdir( $site->site_data['site_fs_path'] );

		return $site;
	}

	/** Run wp_download_and_config() with the create arguments the dispatcher would pass. */
	private function download_and_config( $site, array $assoc_args = [] ) {
		list( , $assoc_args ) = $this->create_args( [ 'example.test' ], $assoc_args );
		self::call_private( $site, 'wp_download_and_config', $assoc_args );
	}

	public function core_download_arguments() {
		return [
			'defaults'     => [ [], [ 'version' => '', 'skip-content' => false ] ],
			'version'      => [ [ 'version' => '6.5.2' ], [ 'version' => '6.5.2', 'skip-content' => false ] ],
			'skip content' => [ [ 'skip-content' => true, 'version' => 'nightly' ], [ 'version' => 'nightly', 'skip-content' => true ] ],
		];
	}

	/**
	 * Since WP-1 the script comes from site-command's get_wp_core_download_command(); this pins what site-type-wp passes to it.
	 *
	 * @dataProvider core_download_arguments
	 */
	public function test_core_download_runs_as_www_data_with_the_locale_and_passed_arguments( $assoc_args, $download_args ) {
		$this->download_and_config( $this->site(), $assoc_args );
		$command = \EE\Site\Utils\get_wp_core_download_command( '/var/www/htdocs', [ 'locale' => 'en_US', 'force' => false ] + $download_args );
		$this->assertSame( [ self::EXEC . "bash -c \"$command\"" ], $this->runner->matching( '/core download/' ) );
	}

	/**
	 * WP-1 (fixed in v1.12.0): WP-CLI 2.12 extracts the 7.x tarball with PharData, which truncates long paths; nothing checks the result.
	 *
	 * @group WP-1
	 */
	public function test_core_download_extracts_without_wp_cli_and_verifies_checksums() {
		$this->download_and_config( $this->site() );
		$download = implode( "\n", $this->runner->matching( '/core download/' ) );
		$this->assertStringContainsString( 'core download --no-extract', $download );
		$this->assertStringContainsString( 'core verify-checksums', $download );
	}

	public function test_core_download_is_retried() {
		$this->runner->on( '/core download/', '', 1, '', 2 );
		$this->download_and_config( $this->site() );
		$this->assertCount( 3, $this->runner->matching( '/core download/' ) );
		$this->assertCount( 1, $this->runner->matching( '/wp config create/' ) );
	}

	/**
	 * WPT-18 (fixed in v1.12.0): after five failed downloads the loop ends without its EE::error(), so the create goes on without WordPress core.
	 *
	 * @group WPT-18
	 */
	public function test_core_download_that_keeps_failing_stops_the_create() {
		$this->runner->on( '/core download/', '', 1 );
		try {
			$this->download_and_config( $this->site() );
		} catch ( \Exception $e ) {
			// EE::error() or an exception for catch_clean(): either stops the create here.
		}
		$this->assertSame( [], $this->runner->matching( '/wp config create/' ), 'wp config create ran after the download failed.' );
	}

	public function test_public_dir_gets_a_wp_cli_yml_and_the_wp_config_one_level_up() {
		$site = $this->site( [ 'site_container_fs_path' => '/var/www/htdocs/current' ] );
		$this->download_and_config( $site );
		$this->assertSame( 'path: current/', trim( file_get_contents( WEBROOT . 'example.test/app/htdocs/wp-cli.yml' ) ) );
		$commands = $this->runner->commands();
		$this->assertContains( "docker-compose -f docker-compose.yml  exec -T --user='root' php mkdir -p current/", $commands );
		$this->assertContains( "docker-compose -f docker-compose.yml  exec -T --user='root' php chown -R www-data: /var/www/", $commands );
		$this->assertContains( self::EXEC . 'mv /var/www/htdocs/current/wp-config.php /var/www/htdocs/wp-config.php', $commands );
	}

	public function test_wp_config_create_gets_the_db_credentials_and_config_arguments() {
		$this->download_and_config( $this->site(), [ 'dbprefix' => 'shop_', 'skip-check' => true, 'version' => '6.5' ] );
		$config = $this->runner->matching( '/wp config create/' );
		$this->assertCount( 1, $config );
		$this->assertStringStartsWith( self::EXEC . "wp config create --dbuser='example.test-AbC123' --dbname='example_test' --dbpass='dbpass' --dbhost='global-db:3306'  --dbprefix=shop_ --skip-check=1 --dbcharset=utf8mb4 --extra-php=\"", $config[0] );
		$this->assertStringContainsString( "\\\$_SERVER['HTTPS'] = 'on';", $config[0] );
		$this->assertStringContainsString( "define( 'WP_DEBUG_LOG', false );", $config[0] );
		$this->assertStringNotContainsString( 'COOKIE_DOMAIN', $config[0] );
		$this->assertContains( self::EXEC . 'mv /var/www/htdocs/wp-config.php /var/www/wp-config.php', $this->runner->commands() );
	}

	public function test_multisite_wp_config_disables_the_cookie_domain() {
		$this->download_and_config( $this->site( [ 'app_sub_type' => 'subdom' ] ) );
		$this->assertStringContainsString( "define( 'COOKIE_DOMAIN', false );", $this->runner->matching( '/wp config create/' )[0] );
	}

	public function test_local_db_is_polled_until_it_accepts_root() {
		$this->runner->on( "/mysql --user='root' --password='rootpw' --host='db' -e exit/", '', 1, '', 2 );
		$this->download_and_config( $this->site( [ 'db_host' => 'db', 'db_root_password' => 'rootpw' ] ) );
		$this->assertCount( 3, $this->runner->matching( "/^docker-compose -f docker-compose.yml  exec -T --user='root' php mysql --user='root' --password='rootpw' --host='db' -e exit$/" ) );
	}

	public function test_failed_wp_config_create_rolls_the_create_back() {
		$this->runner->on( '/wp config create/', '', 1 );
		try {
			$this->download_and_config( $this->site() );
			$this->fail( 'Expected a rollback.' );
		} catch ( EE\Tests\SiteType\StopCreate $e ) {
			$this->assertSame( 'cleanup', $e->point );
		}
		$this->assertStringContainsString( "Couldn't connect to global-db:3306 or there was issue in `wp config create`.", $this->stderr() );
	}

	/**
	 * SEC-1 sink: the db password is put inside single quotes of the `wp config create` command line.
	 *
	 * @group known-bug
	 * @group SEC-1
	 */
	public function test_wp_config_create_is_shell_safe() {
		$this->assert_shell_safe(
			function ( $payload ) {
				$this->download_and_config( $this->site( [ 'db_password' => $payload ] ) );
			},
			'/wp config create/'
		);
	}

	public function test_install_runs_core_install_then_sets_permalinks() {
		self::call_private( $this->site(), 'install_wp' );
		$this->assertSame(
			[
				self::EXEC . "wp core install --url='http://example.test' --title='Example' --admin_user='boss' --admin_password='Secret123' --admin_email='boss@example.test' ",
				self::EXEC . 'wp rewrite structure "/%year%/%monthnum%/%day%/%postname%/" --hard',
			],
			$this->runner->commands()
		);
		$this->assertStringContainsString( 'http://example.test has been created successfully!', $this->stdout() );
	}

	public function test_install_of_an_ssl_subdomain_multisite() {
		$this->set_config( [ 'env' => 'staging' ] );
		self::call_private( $this->site( [ 'app_sub_type' => 'subdom', 'site_ssl' => 'le' ] ), 'install_wp' );
		$commands = $this->runner->commands();
		$this->assertStringStartsWith( self::EXEC . "wp core multisite-install --url='https://example.test' ", $commands[0] );
		$this->assertStringEndsWith( ' --subdomains', $commands[0] );
		$this->assertSame( self::EXEC . "wp config set --type=constant WP_ENVIRONMENT_TYPE  'staging' ", $commands[2] );
	}

	public function test_install_skips_the_environment_type_outside_wordpress_values() {
		$this->set_config( [ 'env' => 'develop' ] );
		self::call_private( $this->site( [ 'app_sub_type' => 'subdir' ] ), 'install_wp' );
		$this->assertStringEndsWith( "--admin_email='boss@example.test' ", $this->runner->commands()[0] );
		$this->assertStringContainsString( 'wp core multisite-install ', $this->runner->commands()[0] );
		$this->assertCount( 2, $this->runner->commands() );
	}

	public function test_failed_install_throws_for_the_rollback() {
		$this->runner->on( '/wp core install/', '', 1 );
		$this->expectExceptionMessage( 'WordPress install failed. Please check logs.' );
		self::call_private( $this->site(), 'install_wp' );
	}

	/**
	 * WPT-5: title, admin user, e-mail and password are put inside single quotes, so an apostrophe breaks out of them.
	 *
	 * @group known-bug
	 * @group WPT-5
	 */
	public function test_install_is_shell_safe() {
		$this->assert_shell_safe(
			function ( $payload ) {
				self::call_private( $this->site( [ 'app_admin_url' => $payload, 'app_admin_username' => $payload ] ), 'install_wp' );
			},
			'/wp core install/'
		);
	}

	public function test_object_cache_points_wp_redis_at_the_cache_host() {
		self::call_private( $this->site( [ 'cache_host' => 'redis' ] ), 'enable_object_cache' );
		$this->assertSame(
			[
				self::EXEC . "wp config set --type=variable redis_server \"array('host'=> 'redis','port'=> 6379,)\" --raw",
				self::EXEC . 'wp plugin install wp-redis --activate',
				self::EXEC . 'wp redis enable',
			],
			$this->runner->commands()
		);
	}

	public function test_page_cache_prefixes_match_the_nginx_keys_and_site_clean() {
		$site = $this->site();
		self::call_private( $site, 'enable_page_cache' );
		$commands = $this->runner->commands();
		$this->assertSame( self::EXEC . 'wp plugin install nginx-helper --activate', $commands[0] );
		$this->assertContains( self::EXEC . 'wp config set RT_WP_NGINX_HELPER_REDIS_HOSTNAME global-redis --add=true --type=constant', $commands );
		$this->assertCount( 1, preg_grep( "/^docker-compose .* wp config set RT_WP_NGINX_HELPER_REDIS_PREFIX '?example\.test_page:'? --add=true --type=constant$/", $commands ) );
		$this->assertCount( 1, preg_grep( "/^docker-compose .* wp config set WP_CACHE_KEY_SALT '?example\.test_obj:'? --add=true --type=constant$/", $commands ) );
		$this->assertContains( self::EXEC . 'wp config set WP_REDIS_MAXTTL 14400 --add=true --type=constant', $commands );

		// nginx stores pages under the prefix nginx-helper purges, and `ee site clean` deletes `<site>_page*`.
		$conf = self::call_private( $site, 'generate_default_conf', 'wp', true, 'example.test' );
		$this->assertStringContainsString( 'set $key "example.test_page:http', $conf );
	}

	public function test_page_cache_options_are_valid_json_for_nginx_helper() {
		self::call_private( $this->site(), 'enable_page_cache' );
		$options = $this->runner->matching( '/rt_wp_nginx_helper_options/' );
		$this->assertCount( 1, $options );
		$this->assertStringStartsWith( self::EXEC . "wp option update rt_wp_nginx_helper_options '", $options[0] );
		preg_match( "/rt_wp_nginx_helper_options '(.*)' --format=json$/s", $options[0], $m );
		$json = json_decode( $m[1], true );
		$this->assertSame( [ 'enable_redis', 'get_request', 'global-redis', '6379', 'example.test_page:' ], [ $json['cache_method'], $json['purge_method'], $json['redis_hostname'], $json['redis_port'], $json['redis_prefix'] ] );
		$this->assertSame( 1, $json['enable_purge'] );
	}

	public function test_page_cache_options_of_a_multisite_go_to_the_network() {
		self::call_private( $this->site( [ 'app_sub_type' => 'subdir' ] ), 'enable_page_cache' );
		$this->assertCount( 1, $this->runner->matching( "/php wp network meta update 1 rt_wp_nginx_helper_options '/" ) );
	}

	/**
	 * SEC-1 sink: the site name reaches the page cache commands unquoted.
	 *
	 * @group known-bug
	 * @group SEC-1
	 */
	public function test_page_cache_setup_is_shell_safe() {
		$this->assert_shell_safe(
			function ( $payload ) {
				self::call_private( $this->site( [ 'site_url' => $payload ] ), 'enable_page_cache' );
			},
			'/REDIS_PREFIX|CACHE_KEY_SALT|rt_wp_nginx_helper_options/'
		);
	}
}
