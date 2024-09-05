<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaCampaignEvents\WikiProject;

use Exception;

/**
 * Exception thrown when we're unable to get information about WikiProjects (e.g., IDs from WDQS or details from the
 * Wikidata action API).
 */
class CannotQueryWikiProjectsException extends Exception {
}
