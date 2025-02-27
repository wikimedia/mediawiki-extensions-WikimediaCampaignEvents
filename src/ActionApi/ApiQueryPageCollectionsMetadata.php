<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaCampaignEvents\ActionApi;

use MediaWiki\Api\ApiQuery;
use MediaWiki\Api\ApiQueryBase;
use MediaWiki\Extension\WikimediaCampaignEvents\Hooks\Handlers\PageCollectionHookHandler;
use MediaWiki\Page\PageLookup;
use MediaWiki\Parser\ParserCache;
use MediaWiki\Parser\ParserOptions;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * This action allows users to retrieve page collections metadata
 * from pages including the page-collection marker, via the API.
 *
 * @ingroup API
 */
class ApiQueryPageCollectionsMetadata extends ApiQueryBase {
	private ParserCache $parserCache;

	private PageLookup $pageLookup;

	public function __construct(
		ApiQuery $query,
		string $moduleName,
		ParserCache $parserCache,
		PageLookup $pageLookup
	) {
		parent::__construct( $query, $moduleName );
		$this->parserCache = $parserCache;
		$this->pageLookup = $pageLookup;
	}

	public function execute() {
		$this->run();
	}

	/**
	 * Retrieves metadata for a given page collection, based on the page title.
	 *
	 * @param string $pageTitle The title of the page that includes a page-collection marker
	 * @return array An associative array containing the page collection metadata.
	 */
	public function getPageCollectionMetadata( string $pageTitle ): array {
		$pageRecord = $this->pageLookup->getExistingPageByText( $pageTitle );
		if ( $pageRecord === null ) {
			return [];
		}

		$parserOptions = new ParserOptions( $this->getUser() );
		$output = $this->parserCache->get( $pageRecord, $parserOptions );
		if ( $output === false ) {
			return [];
		}

		$metadata = $output->getExtensionData( PageCollectionHookHandler::PAGE_COLLECTION_EXTENSION_DATA_KEY );
		if ( !$metadata ) {
			return [];
		}

		return \json_decode( $metadata, true );
	}

	private function run() {
		$params = $this->extractRequestParams();

		$pageTitles = array_map(
			static function ( $pageTitle ) {
				return urldecode( $pageTitle );
			},
			$params['titles']
		);
		$result = $this->getResult();

		foreach ( $pageTitles as $pageTitle ) {
			$metadata = $this->getPageCollectionMetadata( $pageTitle );
			$result->addValue( [ 'query', 'page_collections' ], $pageTitle, $metadata );
		}
	}

	/**
	 * Returns the allowed parameters for the API query.
	 *
	 * @return array The array of allowed parameters.
	 */
	public function getAllowedParams() {
		return [
			'titles' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_ISMULTI => true,
				ParamValidator::PARAM_REQUIRED => true,
			]
		];
	}

	/**
	 * Returns usage examples for this module.
	 *
	 * @return array The array of example messages.
	 */
	protected function getExamplesMessages() {
		return [
			'action=query&list=pagecollectionsmetadata&titles=TestCollection'
				=> 'apihelp-query+pagecollectionsmetadata-example-1',
		];
	}
}
