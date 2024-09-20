<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaCampaignEvents\WikiProject;

use JsonException;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\WikiMap\WikiMap;
use RuntimeException;
use Wikimedia\ObjectCache\BagOStuff;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * This class is used to lookup the available WikiProjects on a given wiki, using the Wikidata Query Service. Only the
 * entity IDs are returned. See {@see WikiProjectFullLookup}, which gets the IDs using this class and then hydrates
 * them using the Wikidata action API.
 */
class WikiProjectIDLookup {
	public const SERVICE_NAME = 'WikimediaCampaignEventsWikiProjectIDLookup';

	private BagOStuff $cache;
	private HttpRequestFactory $httpRequestFactory;

	private ?array $cachedIDs = null;

	public function __construct(
		BagOStuff $cache,
		HttpRequestFactory $httpRequestFactory
	) {
		$this->cache = $cache;
		$this->httpRequestFactory = $httpRequestFactory;
	}

	/**
	 * @return string[]
	 * @throws CannotQueryWikiProjectsException
	 */
	public function getWikiProjectIDs(): array {
		if ( $this->cachedIDs !== null ) {
			return $this->cachedIDs;
		}

		// Note, this needs to be cached per-wiki (the SPARQL query used below is filtered by wiki)
		$cacheKey = $this->cache->makeKey( 'WikimediaCampaignEvents-WikiProjectIDs' );

		// The list is stored in the main stash, which guarantees strong persistence. We assume that regenerating the
		// value can be very expensive and should never happen as part of a webrequest; and that serving stale data is
		// better than not serving anything. Therefore, we use a long TTL to let cached value stick around for a while,
		// but we still regenerate them more frequently to serve fresh data.
		$cachedData = $this->cache->get( $cacheKey );
		if ( $cachedData === false ) {
			// No cached value... We hope this won't ever happen in practice (this code being a POC).
			$list = [];
			$lastUpdate = 0;
		} else {
			$list = $cachedData['list'];
			$lastUpdate = (int)$cachedData['lastUpdate'];
		}

		// Schedule regeneration if the value is older than 1 hour.
		if ( (int)ConvertibleTimestamp::now( TS_UNIX ) - $lastUpdate >= 60 * 60 ) {
			DeferredUpdates::addCallableUpdate( function () use ( $cacheKey ) {
				$this->cache->set(
					$cacheKey,
					[
						'list' => $this->computeWikiProjectIDs(),
						'lastUpdate' => (int)ConvertibleTimestamp::now( TS_UNIX ),
					],
					BagOStuff::TTL_WEEK
				);
			} );
		}

		$this->cachedIDs = $list;
		return $list;
	}

	/**
	 * @return string[]
	 * @throws CannotQueryWikiProjectsException
	 */
	private function computeWikiProjectIDs(): array {
		$sparqlResponse = $this->runSPARQLQuery();
		$entityIDs = [];
		foreach ( $sparqlResponse['results']['bindings'] as $entityInfo ) {
			$entityURI = $entityInfo['item']['value'];
			// Simple str_replace should also work, but this is more robust.
			preg_match( '/Q\d+$/', $entityURI, $match );
			$entityIDs[] = $match[0];
		}
		return $entityIDs;
	}

	/**
	 * @return array
	 * @throws CannotQueryWikiProjectsException
	 */
	private function runSPARQLQuery(): array {
		$curWiki = WikiMap::getWiki( WikiMap::getCurrentWikiId() );
		if ( $curWiki === null ) {
			throw new RuntimeException( 'Current wiki does not exist? Must be a glitch in the Matrix.' );
		}

		$wikiURL = $curWiki->getCanonicalServer() . '/';
		$endpoint = 'https://query.wikidata.org/sparql';
		// Note, we order the results explicitly by QID using numerical sorting. This guarantees that the result set is
		// stable.
		$sparqlQuery = <<<EOT
SELECT ?item WHERE {
    ?item wdt:P31 wd:Q16695773

    FILTER EXISTS {
      ?article schema:about ?item.
      ?article schema:isPartOf <$wikiURL>.
    }
}
ORDER BY xsd:integer( STRAFTER( STR( ?item ), STR( wd:Q ) ) )
LIMIT 500
EOT;

		$params = [
			'query' => $sparqlQuery,
			'format' => 'json',
		];
		$options = [
			'method' => 'GET',
		];

		$url = $endpoint . '?' . http_build_query( $params );
		$req = $this->httpRequestFactory->create( $url, $options, __METHOD__ );

		$status = $req->execute();

		if ( !$status->isGood() ) {
			throw new CannotQueryWikiProjectsException( "Bad status from WDQS: $status" );
		}

		try {
			$parsedResponse = json_decode( $req->getContent(), true, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException $e ) {
			throw new CannotQueryWikiProjectsException( "Invalid JSON from WDQS", 0, $e );
		}

		return $parsedResponse;
	}
}
