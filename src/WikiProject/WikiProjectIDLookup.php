<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaCampaignEvents\WikiProject;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Sparql\SparqlClient;
use MediaWiki\Sparql\SparqlException;
use Wikimedia\ObjectCache\BagOStuff;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * This class is used to lookup the available WikiProjects on a given wiki, using the Wikidata Query Service. Only the
 * entity IDs are returned. See {@see WikiProjectFullLookup}, which gets the IDs using this class and then hydrates
 * them using the Wikidata action API.
 */
class WikiProjectIDLookup {
	public const SERVICE_NAME = 'WikimediaCampaignEventsWikiProjectIDLookup';

	private string $canonicalServer;
	private BagOStuff $cache;
	private SparqlClient $sparqlClient;

	private ?array $cachedIDs = null;

	public function __construct(
		string $canonicalServer,
		BagOStuff $cache,
		SparqlClient $sparqlClient
	) {
		$this->canonicalServer = $canonicalServer;
		$this->cache = $cache;
		$this->sparqlClient = $sparqlClient;
	}

	/**
	 * @return string[]
	 * @throws CannotQueryWDQSException
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
			$this->updateWikiProjectIDsCache( $cacheKey );
			throw new CannotQueryWDQSException();
		} else {
			$list = $cachedData['list'];
			$lastUpdate = (int)$cachedData['lastUpdate'];
		}

		// Schedule regeneration if the value is older than 1 hour.
		if ( (int)ConvertibleTimestamp::now( TS_UNIX ) - $lastUpdate >= 60 * 60 ) {
			$this->updateWikiProjectIDsCache( $cacheKey );
		}

		$this->cachedIDs = $list;
		return $list;
	}

	/**
	 * @return string[]
	 * @throws CannotQueryWDQSException
	 */
	private function computeWikiProjectIDs(): array {
		$sparqlResult = $this->runSPARQLQuery();
		$entityIDs = [];
		foreach ( $sparqlResult as $entityInfo ) {
			$entityURI = $entityInfo['item']['value'];
			// Simple str_replace should also work, but this is more robust.
			preg_match( '/Q\d+$/', $entityURI, $match );
			$entityIDs[] = $match[0];
		}
		return $entityIDs;
	}

	/**
	 * @return array
	 * @throws CannotQueryWDQSException
	 */
	private function runSPARQLQuery(): array {
		$wikiURL = rtrim( $this->canonicalServer, '/' ) . '/';
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

		try {
			return $this->sparqlClient->query( $sparqlQuery, true );
		} catch ( SparqlException $e ) {
			throw new CannotQueryWDQSException( $e->getMessage() );
		}
	}

	/**
	 * @param string $cacheKey
	 * @return void
	 */
	public function updateWikiProjectIDsCache( string $cacheKey ): void {
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
}
