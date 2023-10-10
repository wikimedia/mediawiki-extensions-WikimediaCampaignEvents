<?php

declare( strict_types=1 );

use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\WikimediaCampaignEvents\Grants\GrantsStore;
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
];
// @codeCoverageIgnoreEnd
