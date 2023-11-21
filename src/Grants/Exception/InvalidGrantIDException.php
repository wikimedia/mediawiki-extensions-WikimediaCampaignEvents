<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaCampaignEvents\Grants\Exception;

use Exception;

/**
 * Exception thrown when a grant ID cannot be found in Fluxx.
 */
class InvalidGrantIDException extends Exception {

}
