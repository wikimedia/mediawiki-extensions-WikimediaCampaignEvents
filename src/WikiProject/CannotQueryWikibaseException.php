<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaCampaignEvents\WikiProject;

/**
 * Exception thrown when we're unable to get information about WikiProjects from the Wikidata action API.
 */
class CannotQueryWikibaseException extends CannotQueryWikiProjectsException {
}
