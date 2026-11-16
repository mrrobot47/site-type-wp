<?php

namespace EE\Tests\SiteType;

use EE;
use EE\Tests\TestCase;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * Base for site type tests: builds site command objects, runs `create` through the real synopsis validation and
 * stops it at named points, and keeps a failed create's clean-up from exiting PHPUnit.
 *
 * Stop points, all armed by default: `cleanup` (catch_clean() logged its reason, before delete_site()), `proxy-check`
 * (nginx_proxy_check() asks for the proxy container; lifted by services_running()), `webroot` (create_site_root() runs
 * `whoami`, i.e. after the database step).
 */
abstract class SiteTypeTestCase extends TestCase {

	/** Site type class under test, e.g. EE\Site\Type\WordPress. */
	const SITE_CLASS = '';

	/** Its `site_type` value. */
	const SITE_TYPE = '';

	/** @var FakeGlobalDb|null */
	protected $global_db;

	/** @var string|null The test running now, reported if it exits. */
	private static $running;

	private $file_logger;
	private $path;

	protected function setUp(): void {
		parent::setUp();
		// EE::confirm() without --yes and rollback() exit(), which would end the run silently with status 0.
		if ( null === self::$running ) {
			register_shutdown_function( [ __CLASS__, 'report_exit' ] );
		}
		self::$running = static::class . '::' . $this->getName();
		$this->set_config(
			[
				'skip-tty'       => true,
				'custom-compose' => null,
				'env'            => null,
				'wp-mail'        => null,
				'locale'         => 'en_US',
				'sysctl'         => null,
			]
		);

		// EE_DOCKER::container_status() runs `which docker` with PHP's exec(); a stub on PATH makes it pass anywhere.
		$bin = $this->sandbox() . '/bin';
		mkdir( $bin );
		file_put_contents( "$bin/docker", "#!/bin/sh\nexit 1\n" );
		chmod( "$bin/docker", 0755 );
		$this->path = getenv( 'PATH' );
		putenv( "PATH=$bin:" . $this->path );

		$this->file_logger = EE::get_file_logger();
		$logger            = new Logger( 'phpunit', [ new NullHandler() ] );
		$logger->pushProcessor(
			function ( $record ) {
				// catch_clean() ends in exit(1), which would end the PHPUnit run; stop it once it has logged why.
				if ( 'Initiating clean-up.' === $record['message'] ) {
					throw new StopCreate( 'cleanup' );
				}

				return $record;
			}
		);
		EE::set_file_logger( $logger );

		// init_global_container() otherwise renders the global services compose file first.
		touch( EE_SERVICE_DIR . '/docker-compose.yml' );

		// A create never gets further than asked: past these, it would start containers and run sub-commands.
		$this->stop_at_proxy_check();
		$this->stop_at_webroot();
	}

	protected function tearDown(): void {
		self::$running = '';
		putenv( 'PATH=' . $this->path );
		EE::set_file_logger( $this->file_logger );
		$fs = new Filesystem();
		$fs->remove( glob( WEBROOT . '*' ) );
		$fs->remove( glob( EE_SERVICE_DIR . '/*' ) );
		$this->global_db = null;
		parent::tearDown();
	}

	public static function report_exit() {
		if ( self::$running ) {
			fwrite( STDERR, 'exit() was called during ' . self::$running . "\n" );
			exit( 1 );
		}
	}

	/** @return object A site type command without its constructor (it registers pcntl signal and shutdown handlers). */
	protected function make_site( $class = null ) {
		$class = $class ?: static::SITE_CLASS;
		$site  = ( new \ReflectionClass( $class ) )->newInstanceWithoutConstructor();
		self::set_property( $site, 'fs', new Filesystem() );
		self::set_property( $site, 'level', 0 );
		self::set_property( $site, 'logger', EE::get_file_logger()->withName( 'phpunit' ) );
		$site->site_data = [ 'site_type' => static::SITE_TYPE ];

		return $site;
	}

	/**
	 * Apply the `create` synopsis like EE's dispatcher does: defaults, `options:` checks, unknown flags.
	 *
	 * @return array [ $args, $assoc_args ] as create() receives them.
	 */
	protected function create_args( array $args, array $assoc_args, $class = null ) {
		$class   = $class ?: static::SITE_CLASS;
		$doc     = ( new \ReflectionMethod( $class, 'create' ) )->getDocComment();
		$parent  = new EE\Dispatcher\CompositeCommand( null, 'site', new EE\DocParser( '' ) );
		$command = new EE\Dispatcher\Subcommand( $parent, 'create', new EE\DocParser( $doc ), '__return_null' );
		list( , $args, $assoc_args ) = self::call_private( $command, 'validate_args', $args, $assoc_args, [] );

		return [ $args, $assoc_args ];
	}

	/** @return array The `create` docblock YAML of one flag, as the dispatcher reads it. */
	protected function create_param_args( $flag, $class = null ) {
		$class   = $class ?: static::SITE_CLASS;
		$doc     = new EE\DocParser( ( new \ReflectionMethod( $class, 'create' ) )->getDocComment() );
		$command = new EE\Dispatcher\Subcommand( new EE\Dispatcher\CompositeCommand( null, 'site', new EE\DocParser( '' ) ), 'create', $doc, '__return_null' );
		// Subcommand::validate_args() re-parses the long description, not the raw docblock.
		$mock = '/**' . PHP_EOL . '* ' . implode( PHP_EOL . '* ', array_merge( [ $command->get_shortdesc(), '' ], explode( "\n", $command->get_longdesc() ) ) ) . PHP_EOL . '*/';

		return ( new EE\DocParser( $mock ) )->get_param_args( $flag );
	}

	/**
	 * Run `ee site create` on $site with validated arguments until a stop point.
	 *
	 * @return string The stop point reached, or 'completed'.
	 */
	protected function run_create( $site, $site_name, array $assoc_args = [] ) {
		// Skips the confirm prompts, where the command has --yes (the php type doesn't).
		if ( false !== strpos( ( new \ReflectionMethod( static::SITE_CLASS, 'create' ) )->getDocComment(), '[--yes]' ) ) {
			$assoc_args += [ 'yes' => true ];
		}
		list( $args, $assoc_args ) = $this->create_args( [ $site_name ], $assoc_args );
		try {
			$site->create( $args, $assoc_args );
		} catch ( StopCreate $e ) {
			return $e->point;
		}

		return 'completed';
	}

	/** Stop the create when nginx_proxy_check() first asks for the proxy container: after option handling, before any container or DB work. */
	protected function stop_at_proxy_check() {
		$this->runner->on(
			'/^docker inspect -f \'\{\{\.State\.Running\}\}\' ' . preg_quote( EE_PROXY_TYPE, '/' ) . '$/',
			function () {
				throw new StopCreate( 'proxy-check' );
			}
		);
	}

	/** Stop the create when create_site_root() runs, i.e. once the database step is done. */
	protected function stop_at_webroot() {
		$this->runner->on(
			'/^whoami$/',
			function () {
				throw new StopCreate( 'webroot' );
			}
		);
	}

	/** Answer like a host where the proxy and the global services run on the configured ports (lifts the proxy-check stop). */
	protected function services_running( array $databases = [], array $users = [] ) {
		$this->runner->on( '/^docker inspect -f \'\{\{\.State\.Running\}\}\' /', "true\n" );
		$this->runner->on( '/^docker inspect --format .*"80\/tcp"/', "80\n" );
		$this->runner->on( '/^docker inspect --format .*"443\/tcp"/', "443\n" );
		$this->global_db = new FakeGlobalDb( $this->runner, $databases, $users );
	}

	/** @return array Parsed YAML. */
	protected static function yaml( $content ) {
		return Yaml::parse( $content );
	}

	/** @return string Image tag from the core img-versions.json. */
	protected static function image( $name ) {
		$versions = \EE\Utils\get_image_versions();

		return $name . ':' . $versions[ $name ];
	}
}
