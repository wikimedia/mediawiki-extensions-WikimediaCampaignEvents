<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaCampaignEvents\WikiProject;

/**
 * Exception thrown when we're unable to get WikiProject IDs from WDQS.
 */
class CannotQueryWDQSException extends CannotQueryWikiProjectsException {
}
