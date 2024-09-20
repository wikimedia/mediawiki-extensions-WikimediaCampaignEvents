<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaCampaignEvents\WikiProject;

use JsonException;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\WikiMap\WikiMap;
use WANObjectCache;

/**
 * This is the main lookup service for WikiProject data, intended to be used with an interface that supports pagination.
 * Given a number of results and a starting entity to enumerate from, it queries Wikidata to get additional information
 * about WikiProjects (such as labels and descriptions).
 */
class WikiProjectFullLookup {
	public const SERVICE_NAME = 'WikimediaCampaignEventsWikiProjectFullLookup';

	private WikiProjectIDLookup $wikiProjectIDLookup;
	private WANObjectCache $cache;
	private HttpRequestFactory $httpRequestFactory;

	public function __construct(
		WikiProjectIDLookup $wikiProjectIDLookup,
		WANObjectCache $cache,
		HttpRequestFactory $httpRequestFactory
	) {
		$this->wikiProjectIDLookup = $wikiProjectIDLookup;
		$this->cache = $cache;
		$this->httpRequestFactory = $httpRequestFactory;
	}

	/**
	 * @param string $languageCode
	 * @param int $limit
	 * @param string|null $lastEntity When paginating results, this is the ID of the last entity on the previous page
	 * @return array[]
	 * @phan-return array<string,array{label:string,description:string,sitelink:string}>
	 * @throws CannotQueryWikiProjectsException
	 */
	public function getWikiProjects( string $languageCode, int $limit, string $lastEntity = null ): array {
		$allIDs = $this->wikiProjectIDLookup->getWikiProjectIDs();
		if ( $lastEntity !== null ) {
			$lastPos = array_search( $lastEntity, $allIDs, true );
			$sliceStart = $lastPos !== false ? $lastPos + 1 : 0;
		} else {
			$sliceStart = 0;
		}
		$wantedIDs = array_slice( $allIDs, $sliceStart, $limit );
		return $this->getDataForEntities( $wantedIDs, $languageCode );
	}

	/**
	 * @return bool Whether any WikiProjects exist on the current wiki.
	 * @throws CannotQueryWikiProjectsException
	 */
	public function hasWikiProjects(): bool {
		return $this->wikiProjectIDLookup->getWikiProjectIDs() !== [];
	}

	/**
	 * @param array $entityIDs
	 * @param string $languageCode
	 * @return array[]
	 * @phan-return array<string,array{label:string,description:string,sitelink:string}>
	 * @throws CannotQueryWikiProjectsException
	 */
	private function getDataForEntities( array $entityIDs, string $languageCode ): array {
		$entitiesHash = sha1( implode( ',', $entityIDs ) );
		return $this->cache->getWithSetCallback(
			// Can be cached globally, since entity IDs are unique.
			$this->cache->makeGlobalKey( 'WikimediaCampaignEvents-WikiProjects', $languageCode, $entitiesHash ),
			WANObjectCache::TTL_HOUR,
			fn () => $this->computeDataForEntities( $entityIDs, $languageCode )
		);
	}

	/**
	 * @param string[] $entityIDs
	 * @param string $languageCode
	 * @return array[]
	 * @phan-return array<string,array{label:string,description:string,sitelink:string}>
	 * @throws CannotQueryWikiProjectsException
	 */
	private function computeDataForEntities( array $entityIDs, string $languageCode ): array {
		$entities = $this->queryWikidataAPI( $entityIDs, $languageCode );
		$wikiProjects = [];
		foreach ( $entities as $id => $entity ) {
			if ( !isset( $entity['labels'][$languageCode] ) ) {
				// No label available, skip.
				continue;
			}
			$siteLink = $this->buildEntitySiteLink( $entity );
			if ( $siteLink ) {
				$wikiProjects[$id] = [
					'label' => $entity['labels'][$languageCode]['value'],
					'description' => $entity['descriptions'][$languageCode]['value'] ?? '',
					'sitelink' => $siteLink,
				];
			}
		}
		return $wikiProjects;
	}

	/**
	 * @param string[] $entityIDs
	 * @param string $languageCode
	 * @return array[]
	 * @phan-return array<string,array{labels:array,descriptions:array}>
	 * @throws CannotQueryWikiProjectsException
	 */
	private function queryWikidataAPI( array $entityIDs, string $languageCode ): array {
		$batches = array_chunk( $entityIDs, 50 );
		$entityIDs = [];
		foreach ( $batches as $batch ) {
			$batchResponse = $this->queryWikidataAPIBatch( $batch, $languageCode );
			$entityIDs = array_merge( $entityIDs, $batchResponse['entities'] );
		}
		return $entityIDs;
	}

	/**
	 * @param string[] $entityIDs
	 * @param string $languageCode
	 * @return array
	 * @throws CannotQueryWikiProjectsException
	 */
	private function queryWikidataAPIBatch( array $entityIDs, string $languageCode ): array {
		// 'claims' to be added for more data.
		$props = [ 'labels', 'descriptions', 'sitelinks/urls' ];
		$params = [
			'action' => 'wbgetentities',
			'format' => 'json',
			'ids' => implode( '|', $entityIDs ),
			'props' => implode( '|', $props ),
			'languages' => $languageCode,
			'languagefallback' => true,
			'formatversion' => 2,
		];
		$url = 'https://www.wikidata.org/w/api.php' . '?' . http_build_query( $params );
		$options = [
			'method' => 'GET'
		];
		$req = $this->httpRequestFactory->create( $url, $options, __METHOD__ );

		$status = $req->execute();
		if ( !$status->isGood() ) {
			throw new CannotQueryWikiProjectsException( "Bad status from WD API: $status" );
		}

		try {
			$parsedResponse = json_decode( $req->getContent(), true, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException $e ) {
			throw new CannotQueryWikiProjectsException( "Invalid JSON from WD API", 0, $e );
		}

		return $parsedResponse;
	}

	/**
	 * @param array $entity
	 * @return string|null
	 */
	private function buildEntitySiteLink( array $entity ): ?string {
		$siteId = WikiMap::getCurrentWikiId();
		return array_key_exists( $siteId, $entity['sitelinks'] ) ? $entity['sitelinks'][$siteId]['url'] : null;
	}
}
