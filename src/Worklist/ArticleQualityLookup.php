<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Worklist;

use JsonException;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Site\MediaWikiSite;
use MediaWiki\Site\SiteLookup;
use MediaWiki\WikiMap\WikiMap;
use Psr\Log\LoggerInterface;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Quality scores for articles, from the Wikimedia Foundation's `articlequality` model.
 *
 * The model returns a single score between 0 and 1 for a given revision, and, with extended
 * output, the per-element scores behind it: how well referenced the article is, how many
 * wikilinks and headings it has, whether it carries an infobox, and so on. Both come from one
 * request, so the caller gets the overall figure and the weak spots together.
 *
 * The language-agnostic model is used rather than a per-wiki one because a worklist spans wikis
 * by design. Its score needs no weighting scheme to become a percentage, and it covers wikis
 * that have no trained model of their own.
 *
 * Scores are read for a whole screen of articles at once, so the requests are issued in parallel:
 * the model does not batch, and it fetches the article at predict time rather than looking an
 * answer up, so each one costs a few hundred milliseconds.
 */
class ArticleQualityLookup {
	public const SERVICE_NAME = 'CampaignEventsArticleQualityLookup';

	/** How many articles one call may ask about. A screen of cards, with room to spare. */
	public const MAX_ARTICLES = 30;

	/** Titles the MediaWiki action API accepts in a single query. */
	private const TITLES_PER_QUERY = 50;

	/**
	 * A score cannot change for a fixed revision, so the only cost of an entry expiring is
	 * recomputing an answer that was already correct. Let the cache evict on its own terms.
	 */
	private const TTL_SCORE = WANObjectCache::TTL_INDEFINITE;

	/**
	 * Failures are cached briefly as well. Without this, an article the model cannot score — an
	 * unsupported language, say — costs a request on every page view for as long as it is in a
	 * worklist.
	 */
	private const TTL_FAILURE = WANObjectCache::TTL_MINUTE * 10;

	/** Cached under the failure key when the model could not give a score. */
	private const FAILED = 'failed';

	private const FAILURE_KEY_PREFIX = 'CampaignEvents-articlequality-failed';
	private const SCORE_KEY_PREFIX = 'CampaignEvents-articlequality-score';
	private const MODEL_VERSION_KEY_PREFIX = 'CampaignEvents-articlequality-version';

	/**
	 * How long a model version stays in use after the model last returned it. The version entry
	 * maps each version to that time and is written whenever fresh scores come back, so a
	 * retired model's version, and with it its scores, stops being used this long after its last
	 * response. The entry itself expires after the same time without a write.
	 */
	private const VERSION_TTL = WANObjectCache::TTL_DAY;

	/** Stands in for the version when a response does not say, which is still a version. */
	private const UNKNOWN_VERSION = '';

	public function __construct(
		private readonly HttpRequestFactory $httpRequestFactory,
		private readonly WANObjectCache $wanCache,
		private readonly SiteLookup $siteLookup,
		private readonly LoggerInterface $logger,
		private readonly ?string $endpoint,
		private readonly ?string $hostHeader,
	) {
	}

	/**
	 * Quality for each of the given articles, keyed by prefixed text.
	 *
	 * Articles the model cannot score are left out rather than reported as an error: the card view
	 * shows no chip for them and carries on, and one unscorable article should not cost the reader
	 * the rest of the screen.
	 *
	 * @param string $wiki Wiki ID the articles belong to. Callers must check that it's a valid wiki.
	 * @param list<string> $prefixedTexts At most self::MAX_ARTICLES of them
	 * @return array<string,array{score: float, label: string, elements: array<string,float|bool>}>
	 */
	public function getQualityForArticles( string $wiki, array $prefixedTexts ): array {
		if ( $this->endpoint === null || $this->endpoint === '' ) {
			// No inference service to ask, a blanked setting counting as none rather than as a
			// request to an empty URL. The cards are complete without a score, so this is a wiki
			// that simply shows none rather than an error.
			return [];
		}

		$prefixedTexts = array_slice( array_values( array_unique( $prefixedTexts ) ), 0, self::MAX_ARTICLES );
		if ( !$prefixedTexts ) {
			return [];
		}

		$langCode = $this->getLanguageCode( $wiki );
		if ( $langCode === null ) {
			// Without a language the model cannot be called at all, so there is nothing to report.
			return [];
		}

		$revisionIDs = $this->getCurrentRevisionIDs( $wiki, $prefixedTexts );
		if ( !$revisionIDs ) {
			return [];
		}

		$quality = [];
		foreach ( $this->getQualityForRevisions( $wiki, $langCode, $revisionIDs ) as $title => $result ) {
			$quality[$title] = $result;
		}
		return $quality;
	}

	/**
	 * Scores for revisions already known, taken from the cache where possible and requested in
	 * parallel where not.
	 *
	 * @param string $wiki
	 * @param string $langCode
	 * @param array<string,int> $revisionIDs Revision ID keyed by prefixed text
	 * @return array<string,array{score: float, label: string, elements: array<string,float|bool>}>
	 */
	private function getQualityForRevisions( string $wiki, string $langCode, array $revisionIDs ): array {
		$failedKeys = array_map(
			fn ( int $revisionID ): string => $this->failureKey( $wiki, $revisionID ),
			$revisionIDs
		);
		$cachedFailures = $this->wanCache->getMulti( array_values( $failedKeys ) );
		$candidates = array_filter(
			$revisionIDs,
			// A title that looks like a number (e.g. "1984") is an int as an array key.
			static fn ( string|int $title ): bool => !isset( $cachedFailures[$failedKeys[$title]] ),
			ARRAY_FILTER_USE_KEY
		);

		if ( !$candidates ) {
			return [];
		}

		[ 'versions' => $versions, 'scores' => $scores, 'tried' => $triedTitles ] = $this->getModelVersions(
			$wiki,
			$langCode,
			$candidates
		);
		$pendingCandidates = array_diff_key( $candidates, array_flip( $triedTitles ) );

		if ( !$pendingCandidates ) {
			return $scores;
		}

		$cachedScores = $this->getCachedScores( $pendingCandidates, $wiki, $versions );
		$missing = array_diff_key( $pendingCandidates, $cachedScores );

		foreach ( $this->scoreRevisions( $wiki, $langCode, $missing )['scores'] as $title => $score ) {
			$scores[$title] = $score;
		}
		return $scores + $cachedScores;
	}

	/**
	 * Cached scores of the given revisions, from any of the given model versions.
	 *
	 * A revision can have a score under more than one version, while a new model is rolling out
	 * alongside the old one. The version the model returned most recently is preferred, so that
	 * the cards move over to the new model rather than mixing the two at random. The versions are
	 * tried in that order, each only for the revisions not yet found, so with one version in use
	 * this is a single read of the cache.
	 *
	 * @param array<string,int> $candidates Revision ID keyed by prefixed text
	 * @param string $wiki
	 * @param list<string> $versions Versions in use, most recently returned first; none finds nothing
	 * @return array<string,array> Scores keyed by prefixed text, only for revisions that have one
	 */
	private function getCachedScores( array $candidates, string $wiki, array $versions ): array {
		$scores = [];
		foreach ( $versions as $version ) {
			$missing = array_diff_key( $candidates, $scores );
			if ( !$missing ) {
				break;
			}
			// Most recent version first: a title found under it is not looked for under older ones.
			$scores += $this->getCachedScoresForVersion( $missing, $wiki, $version );
		}
		return $scores;
	}

	/**
	 * Cached scores of the given revisions, from one model version.
	 *
	 * @param array<string,int> $candidates Revision ID keyed by prefixed text
	 * @param string $wiki
	 * @param string $version
	 * @return array<string,array> Scores keyed by prefixed text, only for revisions that have one
	 */
	private function getCachedScoresForVersion( array $candidates, string $wiki, string $version ): array {
		$scoreKeys = array_map(
			fn ( int $revisionID ): string => $this->scoreKey( $wiki, $revisionID, $version ),
			$candidates
		);
		// getMulti() answers by cache key, and only for the keys it found.
		$cached = $this->wanCache->getMulti( array_values( $scoreKeys ) );
		return array_filter(
			array_map( static fn ( string $key ): mixed => $cached[$key] ?? null, $scoreKeys ),
			'is_array'
		);
	}

	/**
	 * The versions of the model currently in use, most recently returned first, learned from the
	 * model when the cache knows none.
	 *
	 * A version is only ever reported in a response, so when none is cached one of the
	 * revisions is scored first to find out; without a version none of their cached scores can
	 * be found, so they all need scoring anyway. If that fails, perhaps for that article alone,
	 * the rest are scored together, as they would have been anyway, and any of them can give the
	 * version. So learning it costs at most two rounds of requests, and nothing is asked about
	 * twice: the caller is told what was scored, and what was tried.
	 *
	 * @param string $wiki
	 * @param string $langCode
	 * @param non-empty-array<string,int> $candidates Revisions with no "failed" cached score, keyed
	 *   by prefixed text
	 * @return array{versions: list<string>, scores: array<string,array>, tried: list<string>} The
	 *   versions, none if no request succeeded; the scores fetched while learning them; and the
	 *   titles asked about
	 */
	private function getModelVersions( string $wiki, string $langCode, array $candidates ): array {
		$live = $this->liveVersions( $this->wanCache->get( $this->modelVersionKey() ) );
		if ( $live ) {
			// Cast back: a version that looks like a number ("1") was stored as an int key.
			$versions = array_map( 'strval', array_keys( $live ) );
			return [ 'versions' => $versions, 'scores' => [], 'tried' => [] ];
		}

		$probeTitle = array_key_first( $candidates );
		$tried = [ $probeTitle ];
		$result = $this->scoreRevisions( $wiki, $langCode, [ $probeTitle => $candidates[$probeTitle] ] );
		if ( !$result['scores'] ) {
			$rest = $candidates;
			unset( $rest[$probeTitle] );
			$tried = array_merge( $tried, array_keys( $rest ) );
			$result = $this->scoreRevisions( $wiki, $langCode, $rest );
		}

		// scoreRevisions() has already recorded the versions it saw.
		return [
			'versions' => $result['versions'],
			'scores' => $result['scores'],
			'tried' => $tried,
		];
	}

	/**
	 * Ask the model about the given revisions, and cache what it says.
	 *
	 * @param string $wiki
	 * @param string $langCode
	 * @param array<string,int> $revisionIDs Revision ID keyed by prefixed text
	 * @return array{scores: array<string,array>, versions: list<string>} The scores of the
	 *   revisions that were scored, keyed by prefixed text, and the model versions that scored them
	 */
	private function scoreRevisions( string $wiki, string $langCode, array $revisionIDs ): array {
		if ( !$revisionIDs ) {
			return [ 'scores' => [], 'versions' => [] ];
		}

		// One request per revision: the model rejects a list of them, so there is nothing to batch.
		// Running them together is what keeps a screen of cards to about the cost of one.
		$client = $this->httpRequestFactory->createMultiClient( [ 'reqTimeout' => 10 ] );
		$requests = [];
		foreach ( $revisionIDs as $title => $revisionID ) {
			$headers = [
				'Content-Type' => 'application/json',
				'User-Agent' => $this->httpRequestFactory->getUserAgent(),
			];
			if ( $this->hostHeader !== null && $this->hostHeader !== '' ) {
				// Some inference services route on this and will not answer without it. An empty
				// one would misroute rather than be ignored, so it counts as none.
				$headers['Host'] = $this->hostHeader;
			}
			$requests[$title] = [
				'method' => 'POST',
				'url' => $this->endpoint,
				'headers' => $headers,
				'body' => json_encode( [
					'rev_id' => $revisionID,
					'lang' => $langCode,
					// The model wants the string, not a JSON boolean; a boolean makes it reply 500.
					'extended_output' => 'true',
				] ),
			];
		}

		$scored = [];
		$versions = [];
		foreach ( $client->runMulti( $requests, [], __METHOD__ ) as $title => $request ) {
			$revisionID = $revisionIDs[$title];
			// runMulti hands each request back with its answer under 'response'.
			$parsed = $this->parseResponse( $request['response'] ?? [], $wiki, $title );

			if ( $parsed !== null ) {
				$cacheKey = $this->scoreKey( $wiki, $revisionID, $parsed['version'] );
				$this->wanCache->set( $cacheKey, $parsed['quality'], self::TTL_SCORE );
				$scored[$title] = $parsed['quality'];
				$versions[] = $parsed['version'];
			} else {
				$failedKey = $this->failureKey( $wiki, $revisionID );
				$this->wanCache->set( $failedKey, self::FAILED, self::TTL_FAILURE );
			}
		}

		// Usually every response reports the same version, so it is recorded once.
		$versions = array_values( array_unique( $versions ) );
		if ( $versions ) {
			$this->recordVersions( $versions );
		}

		return [ 'scores' => $scored, 'versions' => $versions ];
	}

	/**
	 * Note that the model has just returned the given versions.
	 *
	 * Each is stamped with the current time, including versions already known, so that a version
	 * the model keeps returning stays current. The entry is read again just before it is written,
	 * rather than reusing what was read before the requests went out, so that another request's
	 * update made in the meantime is less likely to be lost.
	 *
	 * Not atomic: two requests finishing together can each write over the other, and a version
	 * may be dropped. That only costs its scores being asked for again, which puts it back.
	 *
	 * @param non-empty-list<string> $versions
	 */
	private function recordVersions( array $versions ): void {
		// Versions no longer in use are dropped while the entry is being written anyway.
		$entry = $this->liveVersions( $this->wanCache->get( $this->modelVersionKey() ) );
		$now = ConvertibleTimestamp::time();
		foreach ( $versions as $version ) {
			$entry[$version] = $now;
		}
		$this->wanCache->set( $this->modelVersionKey(), $entry, self::VERSION_TTL );
	}

	/**
	 * The versions in a cached version entry that the model returned within VERSION_TTL, most
	 * recently returned first.
	 *
	 * On a read the pruning stays in memory: only recordVersions() writes the entry, when fresh
	 * scores come back, and it prunes as it does so.
	 *
	 * @param mixed $entry The cached entry: each version mapped to when the model last returned it
	 * @return array<string|int,int> Keyed by version, which PHP makes an int when it looks like one
	 */
	private function liveVersions( mixed $entry ): array {
		if ( !is_array( $entry ) ) {
			return [];
		}
		$cutoff = ConvertibleTimestamp::time() - self::VERSION_TTL;
		$live = array_filter(
			$entry,
			static fn ( mixed $lastReturned ): bool => is_int( $lastReturned ) && $lastReturned > $cutoff
		);
		arsort( $live );
		return $live;
	}

	/**
	 * @param array<string|int,mixed> $response The 'response' map MultiHttpClient populates:
	 *   code, reason, headers, body, error, also available under integer keys
	 * @param string $wiki
	 * @param string $title
	 * @return array{version: string, quality: array{score: float, label: string, elements: array}}|null
	 */
	private function parseResponse( array $response, string $wiki, string $title ): ?array {
		$code = $response['code'] ?? 0;
		$body = $response['body'] ?? '';
		$error = $response['error'] ?? '';
		if ( $code !== 200 ) {
			$this->logger->info(
				'articlequality returned {code} for {title} on {wiki}: {error}',
				[ 'code' => $code, 'title' => $title, 'wiki' => $wiki, 'error' => $error ]
			);
			return null;
		}

		try {
			$parsed = json_decode( $body, true, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			$this->logger->warning(
				'articlequality returned invalid JSON for {title} on {wiki}',
				[ 'title' => $title, 'wiki' => $wiki ]
			);
			return null;
		}

		$score = $parsed['score'] ?? null;
		$elements = $parsed['features']['normalized'] ?? null;
		if ( !is_numeric( $score ) || !is_array( $elements ) ) {
			return null;
		}

		return [
			'version' => (string)( $parsed['model_version'] ?? self::UNKNOWN_VERSION ),
			'quality' => [
				'score' => (float)$score,
				'label' => (string)( $parsed['label'] ?? '' ),
				'elements' => $elements,
			],
		];
	}

	/**
	 * Current revision ID of each article, keyed by the prefixed text that was asked for.
	 *
	 * Read from the wiki holding the article, because that is where its revisions are. Titles are
	 * resolved through the action API rather than a Title object: a foreign wiki's namespaces are
	 * its own, so its prefixed text cannot be parsed here (T226667).
	 *
	 * @param string $wiki
	 * @param list<string> $prefixedTexts
	 * @return array<string,int>
	 */
	private function getCurrentRevisionIDs( string $wiki, array $prefixedTexts ): array {
		$apiUrl = $this->getApiUrl( $wiki );
		if ( $apiUrl === null ) {
			return [];
		}

		$revisionIDs = [];
		foreach ( array_chunk( $prefixedTexts, self::TITLES_PER_QUERY ) as $chunk ) {
			$url = wfAppendQuery( $apiUrl, [
				'action' => 'query',
				'prop' => 'revisions',
				'rvprop' => 'ids',
				'titles' => implode( '|', $chunk ),
				'format' => 'json',
				'formatversion' => '2',
			] );
			$response = $this->httpRequestFactory->get( $url, [], __METHOD__ );
			if ( !is_string( $response ) ) {
				$this->logger->info( 'No response from the revisions query on {wiki}', [ 'wiki' => $wiki ] );
				continue;
			}

			try {
				$parsed = json_decode( $response, true, 512, JSON_THROW_ON_ERROR );
			} catch ( JsonException ) {
				$this->logger->warning( 'Invalid JSON from the revisions query on {wiki}', [ 'wiki' => $wiki ] );
				continue;
			}

			// The API normalises what it was given, so map its titles back to what was asked for.
			$asked = [];
			foreach ( $chunk as $prefixedText ) {
				$asked[$this->normalizeTitle( $prefixedText )] = $prefixedText;
			}
			foreach ( $parsed['query']['normalized'] ?? [] as $normalization ) {
				$from = $this->normalizeTitle( $normalization['from'] ?? '' );
				if ( isset( $asked[$from] ) ) {
					$asked[$this->normalizeTitle( $normalization['to'] ?? '' )] = $asked[$from];
				}
			}

			foreach ( $parsed['query']['pages'] ?? [] as $page ) {
				$revisionID = $page['revisions'][0]['revid'] ?? null;
				$requested = $asked[$this->normalizeTitle( $page['title'] ?? '' )] ?? null;
				// A page still to be created has no revision, and so no quality to report.
				if ( is_int( $revisionID ) && $requested !== null ) {
					$revisionIDs[$requested] = $revisionID;
				}
			}
		}

		return $revisionIDs;
	}

	/** Underscores and spaces are the same character to MediaWiki; to array keys they are not. */
	private function normalizeTitle( string $title ): string {
		return strtr( $title, '_', ' ' );
	}

	private function getApiUrl( string $wiki ): ?string {
		if ( WikiMap::isCurrentWikiId( $wiki ) ) {
			return wfScript( 'api' );
		}
		$site = $this->siteLookup->getSite( $wiki );
		return $site instanceof MediaWikiSite ? $site->getFileUrl( 'api.php' ) : null;
	}

	private function getLanguageCode( string $wiki ): ?string {
		$site = $this->siteLookup->getSite( $wiki );
		$langCode = $site ? $site->getLanguageCode() : null;
		return is_string( $langCode ) && $langCode !== '' ? $langCode : null;
	}

	private function failureKey( string $wiki, int $revisionID ): string {
		// No version here: a failed request may not say which model it reached, and a failure is
		// only kept for minutes anyway.
		return $this->wanCache->makeGlobalKey( self::FAILURE_KEY_PREFIX, $wiki, $revisionID );
	}

	private function scoreKey( string $wiki, int $revisionID, string $model ): string {
		// Keyed on the revision rather than the title, so an edit gets a new score rather than a
		// stale one, and on the model version, so a new model does not serve the old one's scores.
		return $this->wanCache->makeGlobalKey( self::SCORE_KEY_PREFIX, $wiki, $revisionID, $model );
	}

	private function modelVersionKey(): string {
		// One for every wiki, as there is one language-agnostic model behind all of them.
		return $this->wanCache->makeGlobalKey( self::MODEL_VERSION_KEY_PREFIX );
	}
}
