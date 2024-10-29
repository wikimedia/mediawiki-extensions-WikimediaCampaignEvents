<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaCampaignEvents\ActionApi;

use MediaWiki\Api\ApiQuery;
use MediaWiki\Api\ApiQueryBase;
use MediaWiki\Content\TextContent;
use MediaWiki\Extension\WikimediaCampaignEvents\Hooks\Handlers\PageCollectionHookHandler;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\TitleFactory;
use ParserFactory;
use ParserOptions;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * This action allows users to retrieve page collections metadata
 * from pages including the page-collection marker, via the API.
 *
 * @ingroup API
 */
class ApiQueryPageCollectionsMetadata extends ApiQueryBase {
	private ParserFactory $parserFactory;

	private WikiPageFactory $wikiPageFactory;

	private TitleFactory $titleFactory;

	public function __construct(
		ApiQuery $query,
		string $moduleName,
		ParserFactory $parserFactory,
		WikiPageFactory $wikiPageFactory,
		TitleFactory $titleFactory
	) {
		parent::__construct( $query, $moduleName );
		$this->parserFactory = $parserFactory;
		$this->wikiPageFactory = $wikiPageFactory;
		$this->titleFactory = $titleFactory;
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
		$parser = $this->parserFactory->getInstance();

		$parserOptions = new ParserOptions( $this->getUser() );
		$titleObj = $this->titleFactory->newFromText( $pageTitle );
		if ( !$titleObj ) {
			return [];
		}
		$wikiPage = $this->wikiPageFactory->newFromTitle( $titleObj );
		$content = $wikiPage->getContent( RevisionRecord::RAW );

		if ( $content instanceof TextContent ) {
			$output = $parser->parse( $content->getText(), $titleObj, $parserOptions );
			$metadata  = $output->getExtensionData( PageCollectionHookHandler::PAGE_COLLECTION_EXTENSION_DATA_KEY );
		} else {
			return [];
		}

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
