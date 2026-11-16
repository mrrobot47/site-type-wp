<?php

use EE\Tests\SiteType\SiteTypeTestCase;

/**
 * docker-compose.yml of WordPress sites: WordPress::dump_docker_compose_yml() → Site_WP_Docker::generate_docker_compose_yml()
 * → templates/docker-compose.mustache, parsed back as YAML.
 */
class DockerComposeTest extends SiteTypeTestCase {

	const SITE_CLASS = 'EE\Site\Type\WordPress';
	const SITE_TYPE  = 'wp';

	/** @var string[] Volumes `docker volume create` made during the test. */
	private $created_volumes = [];

	/** @return array Parsed docker-compose.yml of a site with $data merged over a plain global-db site. */
	private function compose( array $data = [], array $filters = [ 'nohttps' => true ] ) {
		$site            = $this->make_site();
		$site->site_data = $data + [
			'site_type'     => 'wp',
			'site_url'      => 'example.test',
			'site_fs_path'  => WEBROOT . 'example.test',
			'app_sub_type'  => 'wp',
			'cache_host'    => '',
			'db_host'       => 'global-db',
			'site_ssl'      => '',
			'php_version'   => 8.4,
			'alias_domains' => 'example.test',
		];
		$site->dump_docker_compose_yml( $filters );

		return self::yaml( file_get_contents( $site->site_data['site_fs_path'] . '/docker-compose.yml' ) );
	}

	private function track_volume_creation() {
		$this->runner->on(
			'/^docker volume inspect (\S+)$/',
			function ( $command ) {
				return [ '', in_array( substr( $command, strlen( 'docker volume inspect ' ) ), $this->created_volumes, true ) ? 0 : 1 ];
			}
		);
		$this->runner->on(
			'/^docker volume create /',
			function ( $command ) {
				preg_match( '/(\S+)$/', trim( $command ), $m );
				$this->created_volumes[] = $m[1];

				return [ '', 0 ];
			}
		);
	}

	public function test_default_site_runs_php_nginx_mailhog_and_postfix_from_img_versions() {
		$compose = $this->compose();
		$this->assertSame( [ 'php', 'nginx', 'mailhog', 'postfix' ], array_keys( $compose['services'] ) );
		$this->assertSame( self::image( 'easyengine/php8.4' ), $compose['services']['php']['image'] );
		$this->assertSame( self::image( 'easyengine/nginx' ), $compose['services']['nginx']['image'] );
		$this->assertSame( self::image( 'easyengine/mailhog' ), $compose['services']['mailhog']['image'] );
		$this->assertSame( self::image( 'easyengine/postfix' ), $compose['services']['postfix']['image'] );
		foreach ( $compose['services'] as $name => $service ) {
			$this->assertSame( [ 'io.easyengine.site=${VIRTUAL_HOST}' ], $service['labels'], $name );
			$this->assertSame( 'always', $service['restart'], $name );
			$this->assertSame( [ 'services_global-nginx-proxy_1:${VIRTUAL_HOST}' ], $service['external_links'], $name );
			$this->assertArrayNotHasKey( 'sysctls', $service, $name );
		}
		$this->assertSame( [ 'php' ], $compose['services']['nginx']['depends_on'] );
		$this->assertArrayNotHasKey( 'depends_on', $compose['services']['php'] );
		$this->assertSame( '${VIRTUAL_HOST}', $compose['services']['postfix']['hostname'] );
		$this->assertSame( [ '-invite-jim=false' ], $compose['services']['mailhog']['command'] );
	}

	public function php_images() {
		return [
			'float default' => [ 8.4, 'easyengine/php8.4' ],
			'5.6'           => [ '5.6', 'easyengine/php5.6' ],
			'7.0'           => [ '7.0', 'easyengine/php7.0' ],
			'8.5'           => [ '8.5', 'easyengine/php8.5' ],
			'latest'        => [ 'latest', 'easyengine/php8.4' ],
		];
	}

	/**
	 * @dataProvider php_images
	 */
	public function test_php_image_follows_the_php_version( $php_version, $image ) {
		$this->assertSame( self::image( $image ), $this->compose( [ 'php_version' => $php_version ] )['services']['php']['image'] );
	}

	public function test_php_and_nginx_environment() {
		$services = $this->compose()['services'];
		$this->assertSame(
			[ 'WORDPRESS_DB_HOST', 'WORDPRESS_DB_NAME', 'WORDPRESS_DB_USER', 'WORDPRESS_DB_PASSWORD', 'USER_ID', 'GROUP_ID', 'VIRTUAL_HOST', 'NEWRELIC_APPNAME=${VIRTUAL_HOST}', 'NEWRELIC_LICENSE_KEY' ],
			$services['php']['environment']
		);
		$this->assertSame( [ 'VIRTUAL_HOST', 'VIRTUAL_PATH=/', 'HSTS=off', 'HTTPS_METHOD=nohttps' ], $services['nginx']['environment'] );
		$this->assertSame( [ 'VIRTUAL_HOST', 'VIRTUAL_PATH=/ee-admin/mailhog/', 'VIRTUAL_PORT=8025' ], $services['mailhog']['environment'] );
		$this->assertSame( [ 'RELAY_HOST', 'REPLY_EMAIL' ], $services['postfix']['environment'] );
	}

	public function virtual_hosts() {
		return [
			'aliases'        => [ [ 'alias_domains' => 'example.test,a.test,b.test' ], 'VIRTUAL_HOST=${VIRTUAL_HOST},a.test,b.test', true ],
			'subdom'         => [ [ 'app_sub_type' => 'subdom', 'alias_domains' => 'example.test,*.example.test' ], 'VIRTUAL_HOST=${VIRTUAL_HOST},*.${VIRTUAL_HOST},*.example.test', true ],
			'subdom, no ssl' => [ [ 'app_sub_type' => 'subdom', 'alias_domains' => 'example.test' ], 'VIRTUAL_HOST=${VIRTUAL_HOST},*.${VIRTUAL_HOST}', false ],
			'subdir'         => [ [ 'app_sub_type' => 'subdir' ], 'VIRTUAL_HOST', false ],
		];
	}

	/**
	 * @dataProvider virtual_hosts
	 */
	public function test_virtual_host_lists_aliases_and_sets_the_cert_name( $data, $virtual_host, $cert_name ) {
		$services = $this->compose( $data )['services'];
		$this->assertSame( $virtual_host, $services['nginx']['environment'][0] );
		$this->assertSame( $virtual_host, $services['mailhog']['environment'][0] );
		$this->assertSame( $cert_name, in_array( 'CERT_NAME=${VIRTUAL_HOST}', $services['nginx']['environment'], true ) );
	}

	public function ssl_policies() {
		return [
			'default modern' => [ null, null ],
			'intermediate'   => [ 'Mozilla-Intermediate', 'SSL_POLICY=Mozilla-Intermediate' ],
			'aws'            => [ 'AWS-TLS-1-2-2017-01', 'SSL_POLICY=AWS-TLS-1-2-2017-01' ],
			'invalid'        => [ 'TLS-Anything', null ],
		];
	}

	/**
	 * @dataProvider ssl_policies
	 */
	public function test_ssl_policy_from_config_is_passed_to_the_proxy_when_https_is_on( $policy, $expected ) {
		if ( $policy ) {
			file_put_contents( getenv( 'EE_CONFIG_PATH' ), "ssl-policy: $policy\n" );
		}
		try {
			$env = $this->compose( [ 'site_ssl' => 'le' ], [] )['services']['nginx']['environment'];
		} finally {
			@unlink( getenv( 'EE_CONFIG_PATH' ) );
		}
		$this->assertSame( $expected, current( preg_grep( '/^SSL_POLICY=/', $env ) ) ?: null );
		$this->assertNotContains( 'HTTPS_METHOD=nohttps', $env );
	}

	public function test_php_nginx_and_proxy_share_the_site_network() {
		$compose  = $this->compose();
		$services = $compose['services'];
		$this->assertSame( [ 'example.test' => [ 'aliases' => [ '${VIRTUAL_HOST}_php' ] ], 'global-backend-network' => null ], $services['php']['networks'] );
		$this->assertSame( [ 'global-frontend-network', 'example.test' ], array_keys( $services['nginx']['networks'] ) );
		$this->assertSame( [ 'example.test', 'global-frontend-network' ], array_keys( $services['mailhog']['networks'] ) );
		$this->assertSame( [ 'example.test' ], array_keys( $services['postfix']['networks'] ) );
		$this->assertSame(
			[
				'example.test'            => [ 'name' => 'example.test', 'labels' => [ 'org.label-schema.vendor=EasyEngine', 'io.easyengine.site=${VIRTUAL_HOST}' ] ],
				'global-frontend-network' => [ 'external' => true, 'name' => 'ee-global-frontend-network' ],
				'global-backend-network'  => [ 'external' => true, 'name' => 'ee-global-backend-network' ],
			],
			$compose['networks']
		);
	}

	public function test_volumes_are_external_and_prefixed_with_the_docker_style_site_name() {
		$compose = $this->compose( [ 'site_url' => 'my-site.example.test', 'site_fs_path' => WEBROOT . 'my-site.example.test', 'alias_domains' => 'my-site.example.test' ] );
		$this->assertSame( [ 'htdocs:/var/www', 'config_php:/usr/local/etc', 'log_php:/var/log/php', 'newrelic_sock:/run/newrelic' ], $compose['services']['php']['volumes'] );
		$this->assertSame( [ 'htdocs:/var/www', 'config_nginx:/usr/local/openresty/nginx/conf', 'log_nginx:/var/log/nginx' ], $compose['services']['nginx']['volumes'] );
		$this->assertSame( [ '/dev/log:/dev/log', 'data_postfix:/var/spool/postfix', 'ssl_postfix:/etc/ssl/postfix', 'config_postfix:/etc/postfix' ], $compose['services']['postfix']['volumes'] );
		$expected = [];
		foreach ( [ 'htdocs', 'config_nginx', 'config_php', 'log_php', 'log_nginx', 'data_postfix', 'ssl_postfix', 'config_postfix' ] as $name ) {
			$expected[ $name ] = [ 'external' => true, 'name' => "mysiteexampletest_$name" ];
		}
		$expected['newrelic_sock'] = [ 'external' => true, 'name' => 'global-newrelic-daemon_newrelic_sock' ];
		$this->assertSame( $expected, $compose['volumes'] );
	}

	public function service_variants() {
		return [
			'global db'               => [ [] ],
			'local db'                => [ [ 'db_host' => 'db' ] ],
			'local db and redis'      => [ [ 'db_host' => 'db', 'cache_host' => 'redis' ] ],
			'global redis, subdom'    => [ [ 'cache_host' => 'global-redis', 'app_sub_type' => 'subdom' ] ],
			'remote db, local redis'  => [ [ 'db_host' => '10.0.0.5', 'cache_host' => 'redis' ] ],
		];
	}

	/**
	 * Every named volume and network a service uses is declared at the top level, so `docker-compose up` accepts the file.
	 *
	 * @dataProvider service_variants
	 */
	public function test_every_volume_and_network_a_service_uses_is_declared( $data ) {
		$compose = $this->compose( $data );
		foreach ( $compose['services'] as $name => $service ) {
			foreach ( isset( $service['volumes'] ) ? $service['volumes'] : [] as $volume ) {
				$source = explode( ':', $volume )[0];
				if ( '/' !== $source[0] ) {
					$this->assertArrayHasKey( $source, $compose['volumes'], "$name mounts $volume" );
				}
			}
			foreach ( array_keys( $service['networks'] ) as $network ) {
				$this->assertArrayHasKey( $network, $compose['networks'], "$name joins $network" );
			}
			foreach ( isset( $service['depends_on'] ) ? $service['depends_on'] : [] as $dependency ) {
				$this->assertArrayHasKey( $dependency, $compose['services'], "$name depends on $dependency" );
			}
		}
	}

	public function test_local_db_adds_a_mariadb_service_on_the_site_network_only() {
		$compose = $this->compose( [ 'db_host' => 'db' ] );
		$db      = $compose['services']['db'];
		$this->assertSame( self::image( 'easyengine/mariadb' ), $db['image'] );
		$this->assertSame( [ 'MYSQL_ROOT_PASSWORD', 'MYSQL_DATABASE', 'MYSQL_USER', 'MYSQL_PASSWORD' ], array_slice( $db['environment'], 0, 4 ) );
		$this->assertSame( [ 'db_data:/var/lib/mysql', 'db_conf:/etc/mysql', 'db_logs:/var/log/mysql' ], $db['volumes'] );
		$this->assertSame( [ 'example.test' ], array_keys( $db['networks'] ) );
		$this->assertSame( [ 'db' ], $compose['services']['php']['depends_on'] );
		$this->assertSame( [ 'example.test' ], array_keys( $compose['services']['php']['networks'] ) );
		$this->assertArrayNotHasKey( 'global-backend-network', $compose['networks'] );
		$this->assertSame( 'exampletest_db_data', $compose['volumes']['db_data']['name'] );
	}

	/**
	 * MIG-8: the local db gets compose's 10 s stop timeout, so a slow MariaDB shutdown is SIGKILLed.
	 *
	 * @group known-bug
	 * @group MIG-8
	 */
	public function test_local_db_gets_time_to_shut_down_cleanly() {
		$db = $this->compose( [ 'db_host' => 'db' ] )['services']['db'];
		$this->assertArrayHasKey( 'stop_grace_period', $db );
		$this->assertContains( 'MARIADB_AUTO_UPGRADE=1', $db['environment'] );
	}

	public function test_local_redis_adds_a_redis_service_php_depends_on() {
		$compose = $this->compose( [ 'cache_host' => 'redis' ] );
		$this->assertSame( self::image( 'easyengine/redis' ), $compose['services']['redis']['image'] );
		$this->assertSame( [ 'example.test' ], array_keys( $compose['services']['redis']['networks'] ) );
		$this->assertSame( [ 'redis' ], $compose['services']['php']['depends_on'] );
		$this->assertArrayNotHasKey( 'global-backend-network', $compose['services']['nginx']['networks'] );
	}

	public function test_global_redis_puts_nginx_on_the_backend_network() {
		$compose = $this->compose( [ 'cache_host' => 'global-redis', 'db_host' => 'db' ] );
		$this->assertArrayHasKey( 'global-backend-network', $compose['services']['nginx']['networks'] );
		$this->assertArrayHasKey( 'global-backend-network', $compose['services']['php']['networks'] );
		$this->assertArrayNotHasKey( 'redis', $compose['services'] );
	}

	public function test_sysctl_config_tunes_php_nginx_db_and_redis() {
		$this->set_config( [ 'sysctl' => true ] );
		$compose = $this->compose( [ 'db_host' => 'db', 'cache_host' => 'redis' ] );
		foreach ( [ 'php', 'nginx', 'db', 'redis' ] as $name ) {
			$this->assertContains( 'net.core.somaxconn=65536', $compose['services'][ $name ]['sysctls'], $name );
			$this->assertCount( 9, $compose['services'][ $name ]['sysctls'], $name );
		}
		$this->assertArrayNotHasKey( 'sysctls', $compose['services']['mailhog'] );
		$this->assertArrayNotHasKey( 'sysctls', $compose['services']['postfix'] );
	}

	public function test_dump_creates_the_site_volumes_once_with_site_labels() {
		$this->track_volume_creation();
		$this->compose( [ 'db_host' => 'db' ] );
		$this->assertSame(
			[ 'exampletest_htdocs', 'exampletest_config_nginx', 'exampletest_log_nginx', 'exampletest_config_php', 'exampletest_log_php', 'exampletest_data_postfix', 'exampletest_ssl_postfix', 'exampletest_config_postfix', 'exampletest_db_data', 'exampletest_db_conf', 'exampletest_db_logs' ],
			$this->created_volumes
		);
		foreach ( $this->runner->matching( '/^docker volume create /' ) as $command ) {
			$this->assertStringContainsString( '--label "io.easyengine.site=example.test"', $command );
		}
		$this->assertStringContainsString( 'io.easyengine.site=example.test', $this->runner->matching( '/^docker volume ls /' )[0] );
	}

	public function test_dump_keeps_the_volumes_of_a_site_that_has_some() {
		$this->runner->on( '/^docker volume ls /', "exampletest_htdocs\n" );
		$this->track_volume_creation();
		$this->compose();
		$this->assertSame( [], $this->created_volumes );
		$this->assertSame( [], $this->runner->matching( '/^docker volume inspect /' ) );
	}
}
