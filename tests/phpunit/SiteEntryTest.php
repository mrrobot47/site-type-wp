<?php

use EE\Model\Site;
use EE\Tests\SiteType\SiteTypeTestCase;
use EE\Tests\TestDB;

/**
 * WordPress::create_site_db_entry(): the `sites` row (and VIP site_meta) a finished create stores.
 */
class SiteEntryTest extends SiteTypeTestCase {

	const SITE_CLASS = 'EE\Site\Type\WordPress';
	const SITE_TYPE  = 'wp';

	/** @return array The stored `sites` row. */
	private function store( array $data = [], $cache = false, $skip_install = false ) {
		$site            = $this->make_site();
		$site->site_data = $data + [
			'site_type'              => 'wp',
			'site_url'               => 'example.test',
			'site_fs_path'           => WEBROOT . 'example.test',
			'app_sub_type'           => 'wp',
			'app_admin_url'          => 'Example',
			'app_admin_email'        => 'boss@example.test',
			'app_admin_username'     => 'boss',
			'app_admin_password'     => 'Secret123',
			'alias_domains'          => 'example.test',
			'cache_host'             => $cache ? 'global-redis' : '',
			'proxy_cache'            => 'off',
			'db_host'                => 'global-db',
			'db_port'                => '3306',
			'db_name'                => 'example_test',
			'db_user'                => 'example.test-AbC123',
			'db_password'            => 'dbpass',
			'db_root_password'       => '',
			'site_ssl'               => 'le',
			'site_ssl_wildcard'      => false,
			'php_version'            => 8.4,
			'site_container_fs_path' => '/var/www/htdocs/',
		];
		self::set_property( $site, 'cache_type', $cache );
		self::set_property( $site, 'skip_install', $skip_install );
		self::call_private( $site, 'create_site_db_entry' );
		$rows = TestDB::rows( 'sites' );
		$this->assertCount( 1, $rows );

		return $rows[0];
	}

	public function test_row_holds_the_site_settings() {
		$row      = $this->store();
		$expected = [
			'site_url'               => 'example.test',
			'site_type'              => 'wp',
			'app_sub_type'           => 'wp',
			'app_mail'               => 'postfix',
			'app_admin_username'     => 'boss',
			'app_admin_password'     => 'Secret123',
			'db_name'                => 'example_test',
			'db_user'                => 'example.test-AbC123',
			'db_host'                => 'global-db',
			'db_port'                => '3306',
			'site_ssl'               => 'le',
			'site_ssl_wildcard'      => '0',
			'php_version'            => '8.4',
			'site_container_fs_path' => '/var/www/htdocs',
			'cache_nginx_fullpage'   => '0',
			'proxy_cache'            => 'off',
		];
		$actual   = [];
		foreach ( array_keys( $expected ) as $column ) {
			$actual[ $column ] = (string) $row[ $column ];
		}
		$this->assertSame( $expected, $actual );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $row['created_on'] );
	}

	public function test_cache_flags_and_subdom_wildcard_are_stored_as_integers() {
		$row = $this->store( [ 'app_sub_type' => 'subdom' ], true );
		$this->assertSame( [ '1', '1', '1', '1', 'global-redis', '1' ], array_map( 'strval', [ $row['cache_nginx_browser'], $row['cache_nginx_fullpage'], $row['cache_mysql_query'], $row['cache_app_object'], $row['cache_host'], $row['site_ssl_wildcard'] ] ) );
	}

	public function test_skip_install_stores_no_admin_credentials() {
		$row = $this->store( [], false, true );
		$this->assertNull( $row['app_admin_username'] );
		$this->assertNull( $row['app_admin_password'] );
	}

	public function test_vip_repo_is_kept_in_site_meta() {
		$site            = $this->make_site();
		$site->site_meta = [ 'vip_repo_url' => 'git@github.com:acme/wp-content.git' ];
		$site->site_data = [ 'site_url' => 'vip.test' ];
		self::set_property( $site, 'skip_install', true );
		// The rest of the row is not needed for the meta; fill it like a create does.
		$site->site_data += array_fill_keys( [ 'site_type', 'app_admin_url', 'app_admin_email', 'app_sub_type', 'alias_domains', 'cache_host', 'proxy_cache', 'site_fs_path', 'db_name', 'db_user', 'db_host', 'db_port', 'db_password', 'db_root_password', 'site_ssl', 'site_ssl_wildcard', 'php_version', 'site_container_fs_path' ], '' );
		self::call_private( $site, 'create_site_db_entry' );
		$site_id = Site::find( 'vip.test' )->id;
		$meta    = [];
		foreach ( TestDB::rows( 'site_meta' ) as $row ) {
			$this->assertSame( (string) $site_id, (string) $row['site_id'] );
			$meta[ $row['meta_key'] ] = $row['meta_value'];
		}
		$this->assertSame( [ 'vip_repo_url' => 'git@github.com:acme/wp-content.git', 'is_vip' => '1' ], $meta );
	}

	/**
	 * WPT-3: the default `--proxy-cache` arrives as `false` (the unquoted `off` option) and is stored as an empty string.
	 *
	 * @group known-bug
	 * @group WPT-3
	 */
	public function test_default_proxy_cache_is_stored_as_off() {
		list( , $assoc_args ) = $this->create_args( [ 'example.test' ], [] );
		$row                  = $this->store( [ 'proxy_cache' => \EE\Utils\get_flag_value( $assoc_args, 'proxy-cache' ) ] );
		$this->assertSame( 'off', $row['proxy_cache'] );
	}
}
