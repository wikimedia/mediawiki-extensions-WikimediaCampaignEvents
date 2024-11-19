<?php

declare( strict_types=1 );

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\WikimediaCampaignEvents\Grants\FluxxClient;
use MediaWiki\Extension\WikimediaCampaignEvents\Grants\GrantIDLookup;
use MediaWiki\Extension\WikimediaCampaignEvents\Grants\GrantsStore;
use MediaWiki\Extension\WikimediaCampaignEvents\WikiProject\WikiProjectFullLookup;
use MediaWiki\Extension\WikimediaCampaignEvents\WikiProject\WikiProjectIDLookup;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Sparql\SparqlClient;

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
	WikiProjectIDLookup::SERVICE_NAME => static function ( MediaWikiServices $services ): WikiProjectIDLookup {
		$cfg = $services->getMainConfig();
		$canonicalServer = $cfg->get( MainConfigNames::CanonicalServer );
		$sparqlEndpoint = $cfg->get( 'WikimediaCampaignEventsSparqlEndpoint' );
		return new WikiProjectIDLookup(
			$canonicalServer,
			$services->getMainObjectStash(),
			new SparqlClient( $sparqlEndpoint, $services->getHttpRequestFactory() )
		);
	},
	WikiProjectFullLookup::SERVICE_NAME => static function ( MediaWikiServices $services ): WikiProjectFullLookup {
		return new WikiProjectFullLookup(
			$services->get( WikiProjectIDLookup::SERVICE_NAME ),
			$services->getMainWANObjectCache(),
			$services->getHttpRequestFactory()
		);
	},
];
// @codeCoverageIgnoreEnd
