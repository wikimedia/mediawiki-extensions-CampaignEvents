<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Worklist;

use MediaWiki\Api\ApiMain;
use MediaWiki\Api\ApiUsageException;
use MediaWiki\Context\DerivativeContext;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\CampaignEvents\Utils;
use MediaWiki\Message\Message;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Page\PageReference;
use MediaWiki\Page\WikiPage;
use MediaWiki\Request\DerivativeRequest;
use MediaWiki\Revision\RevisionStoreFactory;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\MalformedTitleException;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFormatter;
use MediaWiki\Title\TitleParser;
use MediaWiki\WikiMap\WikiMap;
use StatusValue;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\IDBAccessObject;

/**
 * Behaviour layer for worklists (T424021).
 *
 * Sits between the REST handlers and the worklist content model (WorklistContent, T423332) and
 * performs the read-modify-write of a worklist wiki page. Callers pass the worklist page directly;
 * resolving which page belongs to a given event is the storage layer's responsibility.
 *
 * The page is saved through the internal edit API rather than a raw PageUpdater, so that all of
 * core's edit logic (permission checks, blocks, edit-conflict detection, AbuseFilter, ...) runs.
 */
class WorklistArticleHelper implements IWorklistArticlesLookup {
	public const SERVICE_NAME = 'CampaignEventsWorklistArticleHelper';

	private const ACTION_ADD = 'add';
	private const ACTION_REMOVE = 'remove';

	public function __construct(
		private readonly RevisionStoreFactory $revisionStoreFactory,
		private readonly TitleFormatter $titleFormatter,
		private readonly TitleParser $titleParser,
		private readonly WorklistSecondaryStore $worklistSecondaryStore,
		private readonly WorklistPagesSecondaryStore $worklistPagesSecondaryStore,
		private readonly WANObjectCache $wanCache,
	) {
	}

	/**
	 * Applies a delta (articles to add and to remove) to the worklist page in a single edit.
	 *
	 * Reading, applying and saving happen once for the whole delta, so the page is updated
	 * atomically and a request that both adds and removes creates a single revision.
	 *
	 * @param PageReference $worklistPage The worklist page to edit. Must be a local page, and the caller is responsible
	 *  for making sure that is the case.
	 * @param array<string,list<string>> $toAdd Articles to add, as wiki ID => list of prefixed titles
	 * @param array<string,list<string>> $toRemove Articles to remove, as wiki ID => list of prefixed titles
	 *
	 * @return StatusValue Good on success; a fatal StatusValue otherwise
	 */
	public function applyDelta(
		PageReference $worklistPage,
		array $toAdd,
		array $toRemove
	): StatusValue {
		$worklistPage->assertWiki( PageReference::LOCAL );
		$currentData = $this->fetchRawWorklistContent( $worklistPage, IDBAccessObject::READ_LATEST );
		if ( $currentData === null ) {
			// Never overwrite an existing page that is not a worklist: this helper only edits worklist
			// content, so treat any other content model as an error rather than clobbering it.
			return StatusValue::newFatal( 'campaignevents-worklist-page-not-worklist' );
		}

		$toAddCanonicalizationStatus = $this->canonicalizeTitles( $toAdd );
		if ( !$toAddCanonicalizationStatus->isGood() ) {
			return $toAddCanonicalizationStatus;
		}
		$toAddCanonical = $toAddCanonicalizationStatus->getValue();
		$toRemoveCanonicalizationStatus = $this->canonicalizeTitles( $toRemove );
		if ( !$toRemoveCanonicalizationStatus->isGood() ) {
			return $toRemoveCanonicalizationStatus;
		}
		$toRemoveCanonical = $toRemoveCanonicalizationStatus->getValue();

		// Additions are applied before removals, so if the same title appears in both it ends up
		// removed.
		$newData = $this->applyChanges( $currentData, $toAddCanonical, self::ACTION_ADD );
		$newData = $this->applyChanges( $newData, $toRemoveCanonical, self::ACTION_REMOVE );

		// Skip the save if there is nothing to change. This also covers removing from a worklist
		// page that does not exist yet: the data stays empty, so no page is created.
		if ( $newData === $currentData ) {
			return StatusValue::newGood();
		}

		// Cast to object so an empty worklist serialises as "{}" (an object), which the content
		// model requires; non-empty maps already serialise as objects keyed by wiki.
		$text = json_encode(
			(object)$newData,
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		return $this->saveViaEditApi( $worklistPage, $text );
	}

	/**
	 * Given a list of titles by wiki, filters out wikis with an empty list, and canonicalizes titles on the local wiki.
	 * @param array<string,string[]> $titlesByWiki
	 * @return StatusValue<array<string,string[]>>
	 */
	private function canonicalizeTitles( array $titlesByWiki ): StatusValue {
		$ret = [];
		$curWiki = WikiMap::getCurrentWikiId();
		foreach ( $titlesByWiki as $wiki => $titles ) {
			if ( $wiki === $curWiki ) {
				// Titles can only be parsed reliably in the context of the current wiki.
				$canonicalizedTitles = [];
				foreach ( $titles as $title ) {
					try {
						$parsedTitle = $this->titleParser->parseTitle( $title );
					} catch ( MalformedTitleException ) {
						// Fail immediately, without waiting for the analogous validation in WorklistContent.
						return StatusValue::newFatal(
							'campaignevents-worklist-content-invalid-title',
							$wiki,
							Message::plaintextParam( $title )
						);
					}
					$canonicalizedTitles[] = $this->titleFormatter->getPrefixedText( $parsedTitle );
				}
			} else {
				$canonicalizedTitles = $titles;
			}
			if ( $canonicalizedTitles ) {
				$ret[$wiki] = $canonicalizedTitles;
			}
		}
		return StatusValue::newGood( $ret );
	}

	/**
	 * Saves the given text to the worklist page through the internal edit API.
	 *
	 * Reusing the edit API (rather than a raw PageUpdater) ensures core applies edit-conflict
	 * detection, AbuseFilter, blocks and other checks that are hard to reproduce by hand.
	 *
	 * @return StatusValue Good on success; the edit API's own error status otherwise
	 */
	private function saveViaEditApi( PageReference $worklistPage, string $text ): StatusValue {
		$context = new DerivativeContext( RequestContext::getMain() );
		$params = [
			'action' => 'edit',
			'title' => $this->titleFormatter->getPrefixedText( $worklistPage ),
			'text' => $text,
			'contentmodel' => CONTENT_MODEL_WORKLIST,
			'summary' => '',
			'token' => $context->getUser()->getEditToken(),
			'errorformat' => 'html',
		];
		$context->setRequest( new DerivativeRequest( $context->getRequest(), $params, true ) );
		$api = new ApiMain( $context, true );
		try {
			$api->execute();
		} catch ( ApiUsageException $e ) {
			return $e->getStatusValue();
		}

		// Clear caches manually: it's not done for us due to the APi indirection, and we don't want stale
		// data around after an edit. It can cause issues such as T437520.
		// @codeCoverageIgnoreStart
		if ( $worklistPage instanceof WikiPage ) {
			$worklistPage->clear();
		} elseif ( $worklistPage instanceof Title ) {
			$worklistPage->resetArticleID( false );
		}
		// @codeCoverageIgnoreEnd

		return StatusValue::newGood();
	}

	/**
	 * @param array<string,list<string>> $data Current worklist content (wiki ID => titles)
	 * @param array<string,list<string>> $articlesByWiki Changes to apply (wiki ID => titles)
	 * @param string $action
	 *
	 * @return array<string,list<string>> Updated content
	 */
	private function applyChanges( array $data, array $articlesByWiki, string $action ): array {
		foreach ( $articlesByWiki as $wiki => $titles ) {
			$current = $data[$wiki] ?? [];
			if ( $action === self::ACTION_ADD ) {
				$data[$wiki] = array_values( array_unique( array_merge( $current, $titles ) ) );
			} else {
				$current = array_values( array_diff( $current, $titles ) );
				// The content model rejects empty arrays, so drop the wiki key when no pages remain.
				if ( $current ) {
					$data[$wiki] = $current;
				} else {
					unset( $data[$wiki] );
				}
			}
		}

		return $data;
	}

	/**
	 * @inheritDoc
	 */
	public function getWorklistArticles(
		PageIdentity $page,
		int $limit,
		int $offset,
		string $direction,
		string $sort
	): array {
		// Read the mirror table rather than the page: it is the only place the whole list can be
		// had in one query, and the endpoint asking for it is keyed by event. This moves to the
		// page itself, which is the source of truth, once the endpoint is keyed by the page.
		$worklistID = $this->getWorklistID( $page );
		if ( $worklistID === null ) {
			return [];
		}

		return $this->worklistPagesSecondaryStore->getPagesForWorklist(
			$worklistID,
			$limit,
			$offset,
			$direction,
			$sort
		);
	}

	/**
	 * @inheritDoc
	 */
	public function filterWorklistArticles( PageIdentity $page, string $wiki, array $prefixedTexts ): array {
		// Checked against the mirror table rather than the page, so that a handful of titles can be
		// looked up without reading the whole list. An article added moments ago may not be there
		// yet, until the job copying it over has run.
		$worklistID = $this->getWorklistID( $page );
		if ( $worklistID === null ) {
			return [];
		}

		return $this->worklistPagesSecondaryStore->filterPagesInWorklist( $worklistID, $wiki, $prefixedTexts );
	}

	/**
	 * The ID of the worklist held by the given page, or null if it has none.
	 *
	 * Found by page ID rather than by title, because a title is formatted with the local namespace
	 * names and the page may belong to another wiki.
	 */
	private function getWorklistID( PageIdentity $page ): ?int {
		$wikiID = $page->getWikiId();
		return $this->worklistSecondaryStore->getWorklistIDFromPage(
			Utils::getWikiIDString( $wikiID ),
			$page->getId( $wikiID )
		);
	}

	/**
	 * Returns the content of a worklist page, cached.
	 *
	 * @return array<string,string[]>|null Null iff the page exists but it isn't a worklist.
	 */
	public function getRawWorklistContentCached( PageReference $page ): ?array {
		return $this->wanCache->buildGetWithSetCallback()
			->rawKey( $this->makeContentCacheKey( $page ) )
			->keepForADay()
			->callback(
				/** @return array<string,string[]>|null */
				fn (): ?array => $this->fetchRawWorklistContent( $page )
			)
			->fetch();
	}

	private function makeContentCacheKey( PageReference $page ): string {
		// TODO: Switch to CacheKeyHelper when T439632 is fixed.
		$pageKey = 'ns' . $page->getNamespace() .
			'@id@' . Utils::getWikiIDString( $page->getWikiId() ) .
			':' . $page->getDBkey();
		return $this->wanCache->makeGlobalKey(
			'CampaignEvents-WorklistContent',
			$pageKey,
		);
	}

	public function invalidateWorklistContentCache( PageReference $page ): void {
		$this->wanCache->delete( $this->makeContentCacheKey( $page ) );
	}

	/** @return array<string,string[]>|null */
	private function fetchRawWorklistContent( PageReference $page, int $flags = IDBAccessObject::READ_NORMAL ): ?array {
		$revisionStore = $this->revisionStoreFactory->getRevisionStore( $page->getWikiId() );
		$latestRevision = $revisionStore->getRevisionByTitle( $page, 0, $flags );

		if ( !$latestRevision ) {
			// Page doesn't exist.
			return [];
		}
		$currentContent = $latestRevision->getContent( SlotRecord::MAIN );
		if ( !$currentContent instanceof WorklistContent ) {
			return null;
		}
		return wfObjectToArray( $currentContent->getData()->getValue() );
	}
}
