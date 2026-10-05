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

	/** Cached in place of a score, which is never a string, when the model could not give one. */
	private const FAILED = 'failed';

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
		$scores = [];
		$missing = [];
		foreach ( $revisionIDs as $title => $revisionID ) {
			$cached = $this->wanCache->get( $this->cacheKey( $wiki, $revisionID ) );
			if ( $cached === self::FAILED ) {
				continue;
			}
			if ( is_array( $cached ) ) {
				$scores[$title] = $cached;
			} else {
				$missing[$title] = $revisionID;
			}
		}
		if ( !$missing ) {
			return $scores;
		}

		// One request per revision: the model rejects a list of them, so there is nothing to batch.
		// Running them together is what keeps a screen of cards to about the cost of one.
		$client = $this->httpRequestFactory->createMultiClient( [ 'reqTimeout' => 10 ] );
		$requests = [];
		foreach ( $missing as $title => $revisionID ) {
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

		foreach ( $client->runMulti( $requests, [], __METHOD__ ) as $title => $request ) {
			$revisionID = $missing[$title];
			// runMulti hands each request back with its answer under 'response'.
			$parsed = $this->parseResponse( $request['response'] ?? [], $wiki, $title );
			$this->wanCache->set(
				$this->cacheKey( $wiki, $revisionID ),
				$parsed ?? self::FAILED,
				$parsed !== null ? self::TTL_SCORE : self::TTL_FAILURE
			);
			if ( $parsed !== null ) {
				$scores[$title] = $parsed;
			}
		}

		return $scores;
	}

	/**
	 * @param array<string|int,mixed> $response The 'response' map MultiHttpClient populates:
	 *   code, reason, headers, body, error, also available under integer keys
	 * @param string $wiki
	 * @param string $title
	 * @return array{score: float, label: string, elements: array<string,float|bool>}|null
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
			'score' => (float)$score,
			'label' => (string)( $parsed['label'] ?? '' ),
			'elements' => $elements,
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

	private function cacheKey( string $wiki, int $revisionID ): string {
		// Keyed on the revision rather than the title, so an edit gets a new score rather than a
		// stale one, and a score once computed is never recomputed for that revision.
		return $this->wanCache->makeGlobalKey( 'CampaignEvents-articlequality', $wiki, $revisionID );
	}
}
