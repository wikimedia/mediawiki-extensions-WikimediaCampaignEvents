<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaCampaignEvents;

use MediaWiki\Extension\WikimediaCampaignEvents\Grants\FluxxClient;
use MediaWiki\Extension\WikimediaCampaignEvents\Grants\GrantIDLookup;
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

	/**
	 * @param ContainerInterface|null $services
	 * @return GrantIDLookup
	 */
	public static function getGrantIDLookup( ContainerInterface $services = null ): GrantIDLookup {
		return ( $services ?? MediaWikiServices::getInstance() )->get( GrantIDLookup::SERVICE_NAME );
	}

	/**
	 * @param ContainerInterface|null $services
	 * @return FluxxClient
	 */
	public static function getFluxxClient( ContainerInterface $services = null ): FluxxClient {
		return ( $services ?? MediaWikiServices::getInstance() )->get( FluxxClient::SERVICE_NAME );
	}
}
