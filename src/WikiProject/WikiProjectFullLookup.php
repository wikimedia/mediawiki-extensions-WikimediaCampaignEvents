<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaCampaignEvents\WikiProject;

use InvalidArgumentException;
use JsonException;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\ObjectCache\WANObjectCache;

/**
 * This is the main lookup service for WikiProject data, intended to be used with an interface that supports pagination.
 * Given a number of results and a starting entity to enumerate from, it queries Wikidata to get additional information
 * about WikiProjects (such as labels and descriptions).
 */
class WikiProjectFullLookup {
	public const SERVICE_NAME = 'WikimediaCampaignEventsWikiProjectFullLookup';

	public const DIR_FORWARDS = 1;
	public const DIR_BACKWARDS = 2;

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
	 * @param string|null $lastEntity When paginating results, this is the ID of the last entity shown to the user
	 * (i.e., the last row when going forwards, and the first row when going backwards).
	 * @param int $direction In which direction to scan, self::DIR_FORWARDS or self::DIR_BACKWARDS
	 * @return array<array|null> An array of arrays with information about the requested WikiProjects. Elements might be
	 * null if there is no sitelink for the current wiki (might happen when the sitelink is removed after the WDQS query
	 * was last run). QIDs are used as array keys, even for null elements.
	 * @phan-return array<string,array{label:string,description:string,sitelink:string}|null>
	 * @throws CannotQueryWDQSException
	 * @throws CannotQueryWikibaseException
	 */
	public function getWikiProjects(
		string $languageCode,
		int $limit,
		string $lastEntity = null,
		int $direction = self::DIR_FORWARDS
	): array {
		$allIDs = $this->wikiProjectIDLookup->getWikiProjectIDs();
		$lastPos = false;
		if ( $lastEntity !== null ) {
			$lastPos = array_search( $lastEntity, $allIDs, true );
		}

		if ( $lastPos !== false ) {
			if ( $direction === self::DIR_FORWARDS ) {
				$wantedIDs = array_slice( $allIDs, $lastPos + 1, $limit );
			} elseif ( $lastPos > $limit ) {
				$wantedIDs = array_slice( $allIDs, $lastPos - $limit, $limit );
			} else {
				$wantedIDs = array_slice( $allIDs, 0, $lastPos );
			}
		} else {
			$offset = $direction === self::DIR_FORWARDS ? 0 : -$limit;
			$wantedIDs = array_slice( $allIDs, $offset, $limit );
		}
		return $this->getDataForEntities( $wantedIDs, $languageCode );
	}

	/**
	 * @return bool Whether any WikiProjects exist on the current wiki.
	 * @throws CannotQueryWDQSException
	 */
	public function hasWikiProjects(): bool {
		return $this->wikiProjectIDLookup->getWikiProjectIDs() !== [];
	}

	/**
	 * @param string $lastID Entity ID to check. The caller must verify that this is a valid ID, or an exception
	 * will be thrown.
	 * @param int $direction self::DIR_FORWARDS or self::DIR_BACKWARDS
	 * @return bool Whether any WikiProjects exist after the specified offset in the given direction (for pagination).
	 */
	public function hasWikiProjectsAfter( string $lastID, int $direction ): bool {
		$allIDs = $this->wikiProjectIDLookup->getWikiProjectIDs();
		$offsetKey = array_search( $lastID, $allIDs, true );
		if ( $offsetKey === false ) {
			throw new InvalidArgumentException( "Entity $lastID not found." );
		}
		return $direction === self::DIR_FORWARDS
			? $offsetKey < array_key_last( $allIDs )
			: $offsetKey > 0;
	}

	/**
	 * @param string $entityID
	 * @return bool Whether the given ID corresponds to a known entity.
	 * @throws CannotQueryWikiProjectsException
	 */
	public function isKnownEntity( string $entityID ): bool {
		return in_array( $entityID, $this->wikiProjectIDLookup->getWikiProjectIDs(), true );
	}

	/**
	 * @param array $entityIDs
	 * @param string $languageCode
	 * @return array<array|null>
	 * @phan-return array<string,array{label:string,description:string,sitelink:string}|null>
	 * @throws CannotQueryWikibaseException
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
	 * @return array<array|null>
	 * @phan-return array<string,array{label:string,description:string,sitelink:string}|null>
	 * @throws CannotQueryWikibaseException
	 */
	private function computeDataForEntities( array $entityIDs, string $languageCode ): array {
		$entities = $this->queryWikidataAPI( $entityIDs, $languageCode );
		$wikiProjects = [];
		foreach ( $entities as $id => $entity ) {
			$siteLink = $this->buildEntitySiteLink( $entity );
			$wikiProjectData = null;
			if ( $siteLink ) {
				$wikiProjectData = [
					'label' => $entity['labels'][$languageCode]['value'] ?? '',
					'description' => $entity['descriptions'][$languageCode]['value'] ?? '',
					'sitelink' => $siteLink,
				];
			}
			$wikiProjects[$id] = $wikiProjectData;
		}
		return $wikiProjects;
	}

	/**
	 * @param string[] $entityIDs
	 * @param string $languageCode
	 * @return array[]
	 * @phan-return array<string,array{labels:array,descriptions:array}>
	 * @throws CannotQueryWikibaseException
	 */
	private function queryWikidataAPI( array $entityIDs, string $languageCode ): array {
		$batches = array_chunk( $entityIDs, 50 );
		$entities = [];
		foreach ( $batches as $batch ) {
			$batchResponse = $this->queryWikidataAPIBatch( $batch, $languageCode );
			$entities = array_merge( $entities, $batchResponse['entities'] );
		}
		return $entities;
	}

	/**
	 * @param string[] $entityIDs
	 * @param string $languageCode
	 * @return array
	 * @throws CannotQueryWikibaseException
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
			throw new CannotQueryWikibaseException( "Bad status from WD API: $status" );
		}

		try {
			$parsedResponse = json_decode( $req->getContent(), true, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException $e ) {
			throw new CannotQueryWikibaseException( "Invalid JSON from WD API", 0, $e );
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

	public static function invertDirection( int $direction ): int {
		return $direction === self::DIR_FORWARDS ? self::DIR_BACKWARDS : self::DIR_FORWARDS;
	}
}
