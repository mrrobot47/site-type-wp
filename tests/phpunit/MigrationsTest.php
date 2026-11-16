<?php

use EE\Model\Option;
use EE\Tests\TestCase;

/**
 * site-type-wp container migrations (migrations/container/).
 */
class MigrationsTest extends TestCase {

	/** @var string[] cwd of each command run, in order. */
	private $cwds = [];

	public function migration_files() {
		$files = [];
		foreach ( glob( dirname( __DIR__, 2 ) . '/migrations/*/*.php' ) as $path ) {
			$files[ basename( $path ) ] = [ $path ];
		}

		return $files;
	}

	/**
	 * REL-7 guard: Executor derives the class to run from the file name; it must be the class the file declares.
	 *
	 * @dataProvider migration_files
	 */
	public function test_migration_class_name_matches_the_file_name( $path ) {
		preg_match( '/^class (\w+) extends/m', file_get_contents( $path ), $m );
		$this->assertSame( '\EE\Migration\\' . $m[1], self::call_private( 'EE\Migration\Executor', 'get_migration_class_name', basename( $path ) ) );
	}

	private function require_migration( $name ) {
		require_once current( glob( dirname( __DIR__, 2 ) . "/migrations/container/*_site-type-wp_$name.php" ) );
	}

	private function record_cwd( $pattern ) {
		$this->runner->on(
			$pattern,
			function () {
				$this->cwds[] = getcwd();

				return [ '', 0 ];
			}
		);
	}

	/** @return string Site root of a new sandbox site. */
	private function add_site( $url, $type, $sub_type ) {
		$root = $this->sandbox();
		$this->insert_site( [ 'site_url' => $url, 'site_type' => $type, 'app_sub_type' => $sub_type, 'site_fs_path' => $root ] );

		return $root;
	}

	public function test_mu_cookie_domain_is_disabled_in_multisites_only() {
		$this->require_migration( 'set_mu_cookie_domain' );
		Option::set( 'version', '4.0.0' );
		$this->add_site( 'single.test', 'wp', 'wp' );
		$subdir = $this->add_site( 'subdir.test', 'wp', 'subdir' );
		$subdom = $this->add_site( 'subdom.test', 'wp', 'subdom' );
		$this->add_site( 'php.test', 'php', 'php' );
		$this->record_cwd( '/COOKIE_DOMAIN/' );

		( new EE\Migration\SetMuCookieDomain() )->up();

		$this->assertSame( array_fill( 0, 2, 'docker-compose exec php bash -c "wp config set COOKIE_DOMAIN false --type=constant --raw"' ), $this->runner->commands() );
		$this->assertSame( [ realpath( $subdir ), realpath( $subdom ) ], $this->cwds );
	}

	public function test_mu_cookie_domain_is_skipped_on_a_fresh_install() {
		$this->require_migration( 'set_mu_cookie_domain' );
		$this->add_site( 'subdir.test', 'wp', 'subdir' );
		( new EE\Migration\SetMuCookieDomain() )->up();
		$this->assertSame( [], $this->runner->commands() );
	}

	public function test_newrelic_key_migration_is_skipped_without_sites_or_on_a_fresh_install() {
		$this->require_migration( 'update_env_file_with_newrelic_key' );
		Option::set( 'version', '4.0.0' );
		( new EE\Migration\UpdateEnvFileWithNewrelicKey() )->up();

		Option::set( 'version', '' );
		$root = $this->add_site( 'wp.test', 'wp', 'wp' );
		file_put_contents( "$root/.env", "VIRTUAL_HOST=wp.test\n" );
		( new EE\Migration\UpdateEnvFileWithNewrelicKey() )->up();

		$this->assertSame( [], $this->runner->commands() );
		$this->assertSame( "VIRTUAL_HOST=wp.test\n", file_get_contents( "$root/.env" ) );
	}

	public function test_newrelic_key_migration_leaves_html_sites_alone() {
		$this->require_migration( 'update_env_file_with_newrelic_key' );
		Option::set( 'version', '4.0.0' );
		$root = $this->add_site( 'html.test', 'html', 'html' );
		file_put_contents( "$root/.env", "VIRTUAL_HOST=html.test\n" );
		( new EE\Migration\UpdateEnvFileWithNewrelicKey() )->up();
		$this->assertSame( [], $this->runner->commands() );
		$this->assertSame( "VIRTUAL_HOST=html.test\n", file_get_contents( "$root/.env" ) );
	}
}
