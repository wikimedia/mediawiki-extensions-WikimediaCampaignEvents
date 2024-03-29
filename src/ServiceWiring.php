<?php

declare( strict_types=1 );

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\WikimediaCampaignEvents\Grants\FluxxClient;
use MediaWiki\Extension\WikimediaCampaignEvents\Grants\GrantIDLookup;
use MediaWiki\Extension\WikimediaCampaignEvents\Grants\GrantsStore;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

// This file is actually covered by WikimediaCampaignEventsServicesTest, but it's not possible to specify a path
// in @covers annotations (https://github.com/sebastianbergmann/phpunit/issues/3794)
// @codeCoverageIgnoreStart
return [
	GrantsStore::SERVICE_NAME => static function ( MediaWikiServices $services ): GrantsStore {
		return new GrantsStore(
			$services->get( CampaignsDatabaseHelper::SERVICE_NAME )
		);
	},
	FluxxClient::SERVICE_NAME => static function ( MediaWikiServices $services ): FluxxClient {
		return new FluxxClient(
			$services->getHttpRequestFactory(),
			new ServiceOptions(
				FluxxClient::CONSTRUCTOR_OPTIONS,
				$services->getMainConfig()
			),
			$services->getMainWANObjectCache(),
			LoggerFactory::getInstance( 'CampaignEvents' ),
		);
	},
	GrantIDLookup::SERVICE_NAME => static function ( MediaWikiServices $services ): GrantIDLookup {
		return new GrantIDLookup(
			$services->get( FluxxClient::SERVICE_NAME ),
			$services->getMainWANObjectCache()
		);
	},
];
// @codeCoverageIgnoreEnd
