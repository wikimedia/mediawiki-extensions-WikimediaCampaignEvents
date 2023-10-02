<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaCampaignEvents\Tests\Integration;

use MediaWiki\Tests\ExtensionJsonTestBase;

/**
 * @group Test
 * @coversNothing
 */
class WikimediaCampaignEventsExtensionJsonTest extends ExtensionJsonTestBase {
	/** @inheritDoc */
	protected string $extensionJsonPath = __DIR__ . '/../../../extension.json';
}
