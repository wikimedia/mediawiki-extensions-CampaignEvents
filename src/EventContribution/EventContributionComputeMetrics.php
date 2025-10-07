<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\EventContribution;

use InvalidArgumentException;
use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUserNotFoundException;
use MediaWiki\Extension\CampaignEvents\MWEntity\HiddenCentralUserException;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Parser\ParserOutputLinkTypes;
use MediaWiki\Revision\RevisionAccessException;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStoreFactory;
use MediaWiki\Title\TitleFormatter;
use MediaWiki\WikiMap\WikiMap;

/**
 * Computes edit metrics for associating edits with events
 */
class EventContributionComputeMetrics {
	public const SERVICE_NAME = 'CampaignEventsEventContributionComputeMetrics';

	/** @var RevisionStoreFactory */
	private RevisionStoreFactory $revisionStoreFactory;
	/** @var TitleFormatter */
	private TitleFormatter $titleFormatter;
	private CampaignsCentralUserLookup $centralUserLookup;

	public function __construct(
		RevisionStoreFactory $revisionStoreFactory,
		TitleFormatter $titleFormatter,
		CampaignsCentralUserLookup $centralUserLookup
	) {
		$this->revisionStoreFactory = $revisionStoreFactory;
		$this->titleFormatter = $titleFormatter;
		$this->centralUserLookup = $centralUserLookup;
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

		$parentRevision = $revisionStore->getRevisionById(
			$currentRevision->getParentId( $wiki )
		);

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

		// TDB - Check how can we get the prefixed text cross-wiki
		$pagePrefixedtext = $this->titleFormatter->getPrefixedText( $page );

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
}
