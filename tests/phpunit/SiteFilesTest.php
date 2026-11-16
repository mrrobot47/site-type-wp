<?php

use EE\Tests\SiteType\SiteTypeTestCase;

/**
 * Files a WordPress create writes into the site root (WordPress::configure_site_files(), generate_default_conf()):
 * .env, nginx main.conf, php custom.ini, the copied nginx custom confs, and the container commands around them.
 */
class SiteFilesTest extends SiteTypeTestCase {

	const SITE_CLASS = 'EE\Site\Type\WordPress';
	const SITE_TYPE  = 'wp';

	/** @return string Site root after configure_site_files() ran for $data merged over a global-db site. */
	private function configure( array $data = [], $cache = false ) {
		$site            = $this->make_site();
		$site->site_data = $data + [
			'site_type'              => 'wp',
			'site_url'               => 'example.test',
			'site_fs_path'           => WEBROOT . 'example.test',
			'app_sub_type'           => 'wp',
			'cache_host'             => $cache ? 'global-redis' : '',
			'db_host'                => 'global-db',
			'db_port'                => '3306',
			'db_name'                => 'example_test',
			'db_user'                => 'example.test-AbC123',
			'db_password'            => 'dbpass',
			'db_root_password'       => '',
			'site_ssl'               => '',
			'php_version'            => 8.4,
			'alias_domains'          => 'example.test',
			'site_container_fs_path' => '/var/www/htdocs',
		];
		self::set_property( $site, 'cache_type', $cache );
		mkdir( $site->site_data['site_fs_path'] );
		self::call_private( $site, 'configure_site_files' );

		return $site->site_data['site_fs_path'];
	}

	/** @return string[] KEY=value lines of the site's .env. */
	private static function env( $root ) {
		return array_values( array_filter( explode( "\n", file_get_contents( "$root/.env" ) ), 'strlen' ) );
	}

	public function test_env_of_a_global_db_site_points_wordpress_at_host_and_port() {
		$user = posix_getpwuid( posix_geteuid() );
		$this->assertSame(
			[
				'WORDPRESS_DB_HOST=global-db:3306',
				'WORDPRESS_DB_USER=example.test-AbC123',
				'WORDPRESS_DB_NAME=example_test',
				'WORDPRESS_DB_PASSWORD=dbpass',
				'VIRTUAL_HOST=example.test',
				'VIRTUAL_HOST_EMAIL=example@example.test',
				'USER_ID=' . $user['uid'],
				'GROUP_ID=' . $user['gid'],
				'NEWRELIC_LICENSE_KEY=',
				'RELAY_HOST=',
				'REPLY_EMAIL=',
			],
			self::env( $this->configure() )
		);
	}

	public function test_env_of_a_local_db_site_also_configures_mariadb() {
		$env = self::env( $this->configure( [ 'db_host' => 'db', 'db_root_password' => 'rootpw' ] ) );
		$this->assertSame( [ 'MYSQL_ROOT_PASSWORD=rootpw', 'MYSQL_DATABASE=example_test', 'MYSQL_USER=example.test-AbC123', 'MYSQL_PASSWORD=dbpass', 'WORDPRESS_DB_HOST=db' ], array_slice( $env, 0, 5 ) );
	}

	public function test_env_of_a_remote_db_site_uses_its_port() {
		$this->assertContains( 'WORDPRESS_DB_HOST=10.0.0.5:3307', self::env( $this->configure( [ 'db_host' => '10.0.0.5', 'db_port' => '3307' ] ) ) );
	}

	/**
	 * WPT-16: a `$` in --dbpass goes into .env as-is, where docker-compose interpolates it.
	 *
	 * @group known-bug
	 * @group WPT-16
	 */
	public function test_env_keeps_a_dollar_in_the_db_password_literal() {
		$env = self::env( $this->configure( [ 'db_password' => 'pa$word' ] ) );
		$this->assertContains( current( preg_grep( '/^WORDPRESS_DB_PASSWORD=/', $env ) ), [ "WORDPRESS_DB_PASSWORD='pa\$word'", 'WORDPRESS_DB_PASSWORD=pa$$word' ] );
	}

	public function php_inis() {
		return [
			'5.6'     => [ '5.6', 'sendmail_path = /usr/sbin/sendmail -t -i -f no-reply@example.com' ],
			'7.4'     => [ '7.4', 'sendmail_path = /usr/bin/msmtp -t' ],
			'default' => [ 8.4, 'sendmail_path = /usr/bin/msmtp -t' ],
		];
	}

	/**
	 * @dataProvider php_inis
	 */
	public function test_php_ini_sends_mail_the_way_the_php_image_supports( $php_version, $sendmail ) {
		$ini = file_get_contents( $this->configure( [ 'php_version' => $php_version ] ) . '/config/php/php/conf.d/custom.ini' );
		$this->assertStringContainsString( "upload_max_filesize = 100M\npost_max_size = 100M", $ini );
		$this->assertStringContainsString( $sendmail, $ini );
	}

	public function test_nginx_conf_serves_all_domains_and_forwards_php_to_the_php_service_alias() {
		$root    = $this->configure( [ 'alias_domains' => 'example.test,www.example.test', 'site_container_fs_path' => '/var/www/htdocs/current' ] );
		$conf    = file_get_contents( "$root/config/nginx/conf.d/main.conf" );
		$compose = self::yaml( file_get_contents( "$root/docker-compose.yml" ) );

		$this->assertStringContainsString( "\tserver_name example.test www.example.test;\n", $conf );
		$this->assertStringContainsString( "\troot /var/www/htdocs/current;\n", $conf );
		// nginx reaches php by the network alias the compose file gives it.
		$this->assertStringContainsString( "upstream php {\n\tserver example.test_php:9000;\n}", $conf );
		$this->assertSame( [ '${VIRTUAL_HOST}_php' ], $compose['services']['php']['networks']['example.test']['aliases'] );
		$this->assertStringContainsString( '# PHP NGINX CONFIGURATION', $conf );
		$this->assertStringNotContainsString( 'upstream redis', $conf );
		$this->assertStringNotContainsString( '# WPSUBDIRECTORY NGINX CONFIGURATION', $conf );
		$this->assertStringContainsString( 'location = /wp-login.php {', $conf );
		$this->assertStringContainsString( 'include /usr/local/openresty/nginx/conf/custom/*.conf;', $conf );
	}

	public function test_nginx_conf_with_cache_reads_pages_from_redis_under_the_site_page_prefix() {
		$conf = file_get_contents( $this->configure( [], true ) . '/config/nginx/conf.d/main.conf' );
		$this->assertStringContainsString( "upstream redis {\n\tserver global-redis:6379;", $conf );
		$this->assertStringContainsString( 'set $key "example.test_page:http$request_method$host$request_uri";', $conf );
		$this->assertStringContainsString( 'set $key "example.test_page:https$request_method$host$request_uri";', $conf );
		$this->assertStringNotContainsString( '# PHP NGINX CONFIGURATION', $conf );
	}

	public function test_nginx_conf_of_a_subdir_multisite_rewrites_subsite_paths() {
		$conf = file_get_contents( $this->configure( [ 'app_sub_type' => 'subdir' ] ) . '/config/nginx/conf.d/main.conf' );
		$this->assertStringContainsString( "# WPSUBDIRECTORY NGINX CONFIGURATION", $conf );
		$this->assertStringContainsString( 'rewrite ^(/[^/]+)?(/wp-.*) $2 last;', $conf );
	}

	public function test_custom_nginx_confs_are_copied_and_the_default_html_removed() {
		$root = $this->configure();
		$this->assertFileEquals( SITE_WP_TEMPLATE_ROOT . '/config/nginx/user.conf.mustache', "$root/config/nginx/custom/user.conf" );
		$this->assertFileEquals( SITE_WP_TEMPLATE_ROOT . '/config/nginx/admin-tools.conf.mustache', "$root/config/nginx/custom/admin-tools.conf" );
		$this->assertStringContainsString( 'location ^~ /ee-admin/ {', file_get_contents( "$root/config/nginx/custom/admin-tools.conf" ) );
		$this->assertFileDoesNotExist( "$root/app/html" );
	}

	public function test_containers_are_started_in_order_around_the_postfix_cert() {
		$this->configure();
		$compose  = 'docker-compose -f docker-compose.yml ';
		$commands = array_values( array_filter( $this->runner->commands(), function ( $command ) {
			return 0 !== strpos( $command, 'docker volume ' ) && 0 !== strpos( $command, 'docker info ' );
		} ) );
		// Between them only the postfix cert commands run (set_postfix_files(), site-command).
		$this->assertSame( "$compose up -d nginx postfix", $commands[0] );
		$this->assertMatchesRegularExpression( '#^openssl req -new -x509 -nodes -days 365 -subj \W*/CN=smtp\.example\.test\W* -out .*/example\.test/services/postfix/ssl/server\.crt\W* -keyout #', $commands[1] );
		$this->assertSame( "$compose restart nginx php", end( $commands ) );
		$this->assertSame( [ 0, count( $commands ) - 1 ], array_keys( preg_grep( '/^(openssl|chmod) /', $commands, PREG_GREP_INVERT ) ) );
	}

	public function test_failed_container_start_rolls_the_create_back() {
		$this->runner->on( '/ up -d nginx postfix$/', '', 1 );
		try {
			$this->configure();
			$this->fail( 'Expected the create to roll back.' );
		} catch ( EE\Tests\SiteType\StopCreate $e ) {
			$this->assertSame( 'cleanup', $e->point );
		}
		$this->assertStringContainsString( 'There was some error in docker-compose up.', $this->stderr() );
	}
}
