<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaCampaignEvents\Tests\Integration;

use MediaWiki\Extension\WikimediaCampaignEvents\WikimediaCampaignEventsServices;
use MediaWiki\Tests\ExtensionServicesTestBase;

/**
 * @group Test
 * @covers \MediaWiki\Extension\WikimediaCampaignEvents\WikimediaCampaignEventsServices
 */
class WikimediaCampaignEventsServicesTest extends ExtensionServicesTestBase {
	/** @inheritDoc */
	protected static string $className = WikimediaCampaignEventsServices::class;
	/** @inheritDoc */
	protected string $serviceNamePrefix = 'WikimediaCampaignEvents';
}
