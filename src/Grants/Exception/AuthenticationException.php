<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaCampaignEvents\Grants\Exception;

use Exception;

/**
 * Exception thrown when we cannot authenticate with Fluxx.
 */
class AuthenticationException extends Exception {

}
