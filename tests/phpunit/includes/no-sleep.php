<?php
// Unqualified sleep() calls in these namespaces resolve here before the global function, so DB health and retry loops don't wait.

namespace EE\Site\Utils;

function sleep( $seconds ) {
	return 0;
}

namespace EE\Site\Type;

function sleep( $seconds ) {
	return 0;
}
