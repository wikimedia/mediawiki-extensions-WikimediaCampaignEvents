<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaCampaignEvents;

use MediaWiki\Extension\WikimediaCampaignEvents\Grants\GrantsStore;
use MediaWiki\MediaWikiServices;
use Psr\Container\ContainerInterface;

/**
 * Global service locator for WikimediaCampaignEventsServices services. Should only be used where DI is not possible.
 */
class WikimediaCampaignEventsServices {
	/**
	 * @param ContainerInterface|null $services
	 * @return GrantsStore
	 */
	public static function getGrantsStore( ContainerInterface $services = null ): GrantsStore {
		return ( $services ?? MediaWikiServices::getInstance() )->get( GrantsStore::SERVICE_NAME );
	}
}
