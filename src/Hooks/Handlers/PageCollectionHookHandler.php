<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaCampaignEvents\Hooks\Handlers;

use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\Parser\Parser;

class PageCollectionHookHandler implements ParserFirstCallInitHook {
	public const PAGE_COLLECTION_EXTENSION_DATA_KEY = 'PageCollection';

	/**
	 * Bind the parsePageCollection function to the page-collection magic word
	 * @param Parser $parser
	 */
	public function onParserFirstCallInit( $parser ) {
		$parser->setHook( "page-collection", [ $this, "parsePageCollection" ] );
	}

	/**
	 * This method parses the custom <page-collection> HTML marker, extracts
	 * the page collection metadata that are defined inside its attributes and stores them
	 * as extension data, to be used by the "pagecollectionsmetadata" Action API module.
	 *
	 * It also adds a tracking category to the page that includes the marker, so that
	 * we can easily find/query such pages.
	 *
	 * Finally, it returns an empty string, as we don't want to render anything inside
	 * the page, for this marker.
	 *
	 * Example HTML marker definition:
	 * <page-collection
	 *   name='My Page Collection'
	 *   description='This is a page collection'
	 *   end-date='2024-10-20'
	 * ></page-collection>
	 *
	 * @param string|null $label
	 * @param array $args
	 * @param Parser $parser
	 * @return string
	 */
	public function parsePageCollection( ?string $label, array $args, Parser $parser ): string {
		$parserOutput = $parser->getOutput();

		$isTranslation = $parserOutput->getPageProperty( 'translate-is-translation' ) !== null;
		if ( $isTranslation ) {
			return "";
		}

		// add a tracking category to the page that includes the marker, so that we can easily
		// find/query such pages
		$parser->addTrackingCategory( "page-collection-tracking-category" );

		// Get the named parameters and merge with defaults.
		$defaultOptions = [
			"lang" => $parser->getContentLanguage()->getCode(),
			"name" => "",
			"description" => "",
			"end-date" => "",
		];
		$pageCollectionDefinition = array_merge( $defaultOptions, $args );

		$parserOutput->setExtensionData(
			self::PAGE_COLLECTION_EXTENSION_DATA_KEY,
			json_encode( $pageCollectionDefinition )
		);

		// For now, we don't want to display anything inside the page, for these HTML markers.
		return "";
	}
}
