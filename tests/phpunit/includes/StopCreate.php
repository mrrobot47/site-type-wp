<?php

namespace EE\Tests\SiteType;

/**
 * Thrown by a test hook to halt a site create at a known point.
 *
 * An \Error, so the create's own `catch ( \Exception $e )` blocks don't turn it into a rollback.
 */
class StopCreate extends \Error {

	/** @var string Name of the hook that stopped the create. */
	public $point;

	public function __construct( $point ) {
		parent::__construct( "Create stopped at: $point" );
		$this->point = $point;
	}
}
