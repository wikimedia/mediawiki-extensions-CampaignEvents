<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\EventContribution;

use InvalidArgumentException;
use JsonException;
use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUserNotFoundException;
use MediaWiki\Extension\CampaignEvents\MWEntity\HiddenCentralUserException;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Parser\ParserOutputLinkTypes;
use MediaWiki\Revision\RevisionAccessException;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStoreFactory;
use MediaWiki\Site\MediaWikiSite;
use MediaWiki\Site\SiteLookup;
use MediaWiki\Title\TitleFormatter;
use MediaWiki\WikiMap\WikiMap;
use RuntimeException;
use Wikimedia\ObjectCache\WANObjectCache;

/**
 * Computes edit metrics for associating edits with events. Note that this can perform expensive operation, and should
 * not be used synchronously.
 */
class EventContributionComputeMetrics {
	public const SERVICE_NAME = 'CampaignEventsEventContributionComputeMetrics';

	public function __construct(
		private readonly RevisionStoreFactory $revisionStoreFactory,
		private readonly TitleFormatter $titleFormatter,
		private readonly CampaignsCentralUserLookup $centralUserLookup,
		private readonly WANObjectCache $wanCache,
		private readonly SiteLookup $siteLookup,
		private readonly HttpRequestFactory $httpRequestFactory,
	) {
	}

	/**
	 * Compute edit metrics for a revision and return a EventContribution object. The caller is responsible of
	 * validating all the IDs provided.
	 *
	 * @param int $revisionID The revision ID to compute metrics for. Must be a valid revision ID.
	 * @param int $eventID The event ID to associate with. Must be a valid event ID.
	 * @param int $userID The user ID who made the edit. Must be a valid user ID and the user must not be deleted.
	 * @param string $fullWikiID The wiki where the edit was made
	 * @return EventContribution Complete contribution object
	 */
	public function computeEventContribution(
		int $revisionID,
		int $eventID,
		int $userID,
		string $fullWikiID
	): EventContribution {
		if ( $fullWikiID === WikiMap::getCurrentWikiId() ) {
			// Normalize to avoid T406777 and similar issues.
			$wiki = WikiAwareEntity::LOCAL;
		} else {
			$wiki = $fullWikiID;
		}

		try {
			$userName = $this->centralUserLookup->getUserName( new CentralUser( $userID ) );
		} catch ( CentralUserNotFoundException | HiddenCentralUserException ) {
			// XXX: Should this be an error instead?
			$userName = null;
		}

		$revisionStore = $this->revisionStoreFactory->getRevisionStore( $wiki );
		$currentRevision = $revisionStore->getRevisionById( $revisionID );
		if ( !$currentRevision ) {
			throw new InvalidArgumentException( "Revision $revisionID not found" );
		}

		$parentRevID = $currentRevision->getParentId( $wiki );
		$parentRevision = $parentRevID !== null ? $revisionStore->getRevisionById( $parentRevID ) : null;

		$currentSize = $currentRevision->getSize();
		$parentSize = $parentRevision ? $parentRevision->getSize() : 0;
		$bytesDelta = $currentSize - $parentSize;

		// Determine edited type
		// 0 = edited, 1 = created
		$editedType = $parentRevision ? 0 : 1;

		$linksDelta = $this->getInternalLinksDelta( $currentRevision, $parentRevision );

		// Get page information
		$page = $currentRevision->getPage();
		$pageID = $page->getId( $wiki );

		$pagePrefixedtext = $this->getPagePrefixedText( $page );

		// Get timestamp directly from RevisionRecord
		$timestamp = $currentRevision->getTimestamp();

		return new EventContribution(
			$eventID,
			$userID,
			$userName,
			$fullWikiID,
			$pagePrefixedtext,
			$pageID,
			$revisionID,
			$editedType,
			$bytesDelta,
			$linksDelta,
			$timestamp,
			$currentRevision->getVisibility() !== 0
		);
	}

	/**
	 * Calculate the difference in internal links between revisions
	 *
	 * @return int Positive for added links, negative for removed links
	 */
	private function getInternalLinksDelta(
		RevisionRecord $currentRevision,
		?RevisionRecord $parentRevision
	): int {
		$currentLinks = $this->countInternalLinksInRevision( $currentRevision );
		$parentLinks = $parentRevision ? $this->countInternalLinksInRevision( $parentRevision ) : 0;

		return $currentLinks - $parentLinks;
	}

	/**
	 * Count internal links in a revision using ParserOutput metadata
	 */
	private function countInternalLinksInRevision( RevisionRecord $revision ): int {
		// Create parser options for canonical parsing
		$parserOptions = ParserOptions::newFromAnon();
		$parserOptions->setRenderReason( 'EventContributionComputeMetrics' );

		// Get the rendered revision to access ParserOutput
		$services = MediaWikiServices::getInstance();
		$revisionRenderer = $services->getRevisionRenderer();

		try {
			$renderedRevision = $revisionRenderer->getRenderedRevision(
				$revision,
				$parserOptions,
				null,
				[
					'audience' => RevisionRecord::RAW
				]
			);
		} catch ( RevisionAccessException ) {
			// If there's any error in parsing, return 0
			// This could happen if the revision is deleted or inaccessible
			return 0;
		}

		if ( !$renderedRevision ) {
			return 0;
		}

		// Get ParserOutput with metadata only
		$parserOutput = $renderedRevision->getRevisionParserOutput( [ 'generate-html' => false ] );

		$localLinks = $parserOutput->getLinkList( ParserOutputLinkTypes::LOCAL );

		return count( $localLinks );
	}

	/**
	 * Returns the prefixedtext of a potentially-external page. Note that this involves an API request
	 * and is thus expensive.
	 */
	private function getPagePrefixedText( PageIdentity $page ): string {
		$wiki = $page->getWikiId();
		if ( $wiki === WikiAwareEntity::LOCAL ) {
			return $this->titleFormatter->getPrefixedText( $page );
		}

		// We can't use TitleFormatter for external pages (T226667). And there doesn't seem to be an utility in
		// any of the related core classes either (MediaWikiSite, WikiMap, etc.).
		return $this->wanCache->getWithSetCallback(
			$this->wanCache->makeGlobalKey( 'CampaignEvents-prefixedtext', $page->__toString() ),
			WANObjectCache::TTL_WEEK,
			function () use ( $page ): string {
				return $this->queryForeignPagePrefixedText( $page );
			}
		);
	}

	/** Obtains the prefixedtext of a foreign page using the API. */
	private function queryForeignPagePrefixedText( PageIdentity $page ): string {
		$wiki = $page->getWikiId();
		$site = $this->siteLookup->getSite( $wiki );
		if ( !$site instanceof MediaWikiSite ) {
			throw new RuntimeException( 'MediaWiki page is not on a MediaWiki site?!' );
		}
		$apiURL = $site->getFileUrl( 'api.php' );
		// XXX: this could maybe use a REST endpoint, but we can't reliably get the rest.php URL for
		// another wiki (T312568)
		$reqParams = [
			'action' => 'query',
			'pageids' => $page->getId( $wiki ),
			'format' => 'json',
		];
		$url = wfAppendQuery( $apiURL, $reqParams );
		$ret = $this->httpRequestFactory->get( $url, [], __METHOD__ );

		if ( !is_string( $ret ) ) {
			throw new RuntimeException( 'Got no response for page API query' );
		}

		try {
			$parsedResponse = json_decode( $ret, true, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			throw new RuntimeException( "Page API query returned invalid JSON: '$ret'" );
		}

		$respPages = $parsedResponse['query']['pages'] ?? [];
		if ( count( $respPages ) !== 1 ) {
			throw new RuntimeException( "Page wasn't found" );
		}

		return $respPages[0]['title'];
	}
}
