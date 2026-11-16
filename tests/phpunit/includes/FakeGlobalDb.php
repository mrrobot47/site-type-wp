<?php

namespace EE\Tests\SiteType;

use EE\Tests\FakeProcessRunner;

/**
 * Plays the global MariaDB container for the SQL that site-command sends to it.
 *
 * SQL reaches the container as a file (`docker cp <file> services_global-db_1:…`, then `docker exec … sh …`), so the
 * copied file is read when it is copied and its statements are applied when the exec runs, stopping at the first
 * failing one like `mysql -e` does. Both the `db_exec` shell script form and a plain SQL file are understood.
 */
class FakeGlobalDb {

	/** @var array<string,bool> Databases on the server. */
	public $databases = [];

	/** @var array<string,bool> Users on the server (host `%`). */
	public $users = [];

	/** @var string[] Every statement run, in order, including the one that failed. */
	public $statements = [];

	private $pending = '';

	public function __construct( FakeProcessRunner $runner, array $databases = [], array $users = [] ) {
		$this->databases = array_fill_keys( $databases, true );
		$this->users     = array_fill_keys( $users, true );

		$runner->on(
			"/^docker cp ('[^']*'|\S+) " . GLOBAL_DB_CONTAINER . ':/',
			function ( $command ) {
				preg_match( "/^docker cp ('([^']*)'|(\S+)) /", $command, $m );
				$this->pending = file_get_contents( '' !== $m[2] ? $m[2] : $m[3] );

				return [ '', 0 ];
			}
		);
		$runner->on(
			'/^docker exec ' . GLOBAL_DB_CONTAINER . ' /',
			function () {
				return $this->run( $this->pending );
			}
		);
	}

	/** @return string[] Statements matching $pattern. */
	public function statements_matching( $pattern ) {
		return array_values( preg_grep( $pattern, $this->statements ) );
	}

	/** @return array [ stdout, exit code, stderr ] like the mysql client. */
	private function run( $script ) {
		$sql = $script;
		if ( preg_match( '/^mysql .*? -e([\'"])(.*)\1\s*$/s', trim( $script ), $m ) ) {
			$sql = $m[2];
		}
		$stdout = '';
		foreach ( array_filter( array_map( 'trim', explode( ';', $sql ) ), 'strlen' ) as $statement ) {
			$this->statements[] = $statement;
			list( $out, $error ) = $this->apply( $statement );
			if ( $error ) {
				return [ $stdout, 1, $error ];
			}
			$stdout .= $out;
		}

		return [ $stdout, 0, '' ];
	}

	/** @return array [ output, error ] */
	private function apply( $statement ) {
		if ( preg_match( '/^(exit|FLUSH PRIVILEGES|GRANT ALL PRIVILEGES ON .+)$/i', $statement ) ) {
			return [ '', '' ];
		}
		if ( preg_match( '/^CREATE USER ["\']([^"\']+)["\']@["\']%["\']/', $statement, $m ) ) {
			return $this->add( $this->users, $m[1], "ERROR 1396 (HY000): Operation CREATE USER failed for '{$m[1]}'@'%'" );
		}
		if ( preg_match( '/^CREATE DATABASE `([^`]+)`$/', $statement, $m ) ) {
			return $this->add( $this->databases, $m[1], "ERROR 1007 (HY000): Can't create database '{$m[1]}'; database exists" );
		}
		if ( preg_match( '/^DROP DATABASE `([^`]+)`$/', $statement, $m ) ) {
			return $this->remove( $this->databases, $m[1], "ERROR 1008 (HY000): Can't drop database '{$m[1]}'; database doesn't exist" );
		}
		if ( preg_match( '/^DROP USER ["\']([^"\']+)["\']@["\']%["\']$/', $statement, $m ) ) {
			return $this->remove( $this->users, $m[1], "ERROR 1396 (HY000): Operation DROP USER failed for '{$m[1]}'@'%'" );
		}
		if ( preg_match( "/^SELECT 1 FROM information_schema\.SCHEMATA WHERE SCHEMA_NAME = '([^']+)'$/", $statement, $m ) ) {
			return [ isset( $this->databases[ $m[1] ] ) ? "1\n" : '', '' ];
		}
		if ( preg_match( "/^SELECT 1 FROM mysql\.user WHERE User = '([^']+)' LIMIT 1$/", $statement, $m ) ) {
			return [ isset( $this->users[ $m[1] ] ) ? "1\n" : '', '' ];
		}
		throw new \LogicException( "FakeGlobalDb does not understand: $statement" );
	}

	private function add( array &$set, $name, $error ) {
		if ( isset( $set[ $name ] ) ) {
			return [ '', $error ];
		}
		$set[ $name ] = true;

		return [ '', '' ];
	}

	private function remove( array &$set, $name, $error ) {
		if ( ! isset( $set[ $name ] ) ) {
			return [ '', $error ];
		}
		unset( $set[ $name ] );

		return [ '', '' ];
	}
}
