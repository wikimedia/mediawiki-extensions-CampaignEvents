<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Worklist;

use MediaWiki\Api\ApiMain;
use MediaWiki\Api\ApiUsageException;
use MediaWiki\Context\DerivativeContext;
use MediaWiki\Context\RequestContext;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Request\DerivativeRequest;
use MediaWiki\Title\TitleFormatter;
use StatusValue;

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
class WorklistArticleHelper {
	public const SERVICE_NAME = 'CampaignEventsWorklistArticleHelper';

	private const ACTION_ADD = 'add';
	private const ACTION_REMOVE = 'remove';

	public function __construct(
		private readonly WikiPageFactory $wikiPageFactory,
		private readonly TitleFormatter $titleFormatter,
	) {
	}

	/**
	 * Applies a delta (articles to add and to remove) to the worklist page in a single edit.
	 *
	 * Reading, applying and saving happen once for the whole delta, so the page is updated
	 * atomically and a request that both adds and removes creates a single revision.
	 *
	 * @param PageIdentity $worklistPage The worklist page to edit
	 * @param array<string,list<string>> $toAdd Articles to add, as wiki ID => list of prefixed titles
	 * @param array<string,list<string>> $toRemove Articles to remove, as wiki ID => list of prefixed titles
	 * @return StatusValue Good on success; a fatal StatusValue otherwise
	 */
	public function applyDelta(
		PageIdentity $worklistPage,
		array $toAdd,
		array $toRemove
	): StatusValue {
		$wikiPage = $this->wikiPageFactory->newFromTitle( $worklistPage );
		$currentContent = $wikiPage->getContent();
		// Never overwrite an existing page that is not a worklist: this helper only edits worklist
		// content, so treat any other content model as an error rather than clobbering it.
		if ( $currentContent !== null && !( $currentContent instanceof WorklistContent ) ) {
			return StatusValue::newFatal( 'campaignevents-worklist-page-not-worklist' );
		}
		$currentData = [];
		if ( $currentContent instanceof WorklistContent ) {
			$data = $currentContent->getData()->getValue();
			if ( is_object( $data ) ) {
				$currentData = wfObjectToArray( $data );
			}
		}

		// Additions are applied before removals, so if the same title appears in both it ends up
		// removed.
		$newData = $this->applyChanges( $currentData, $toAdd, self::ACTION_ADD );
		$newData = $this->applyChanges( $newData, $toRemove, self::ACTION_REMOVE );

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
	 * Saves the given text to the worklist page through the internal edit API.
	 *
	 * Reusing the edit API (rather than a raw PageUpdater) ensures core applies edit-conflict
	 * detection, AbuseFilter, blocks and other checks that are hard to reproduce by hand.
	 *
	 * @return StatusValue Good on success; the edit API's own error status otherwise
	 */
	private function saveViaEditApi( PageIdentity $worklistPage, string $text ): StatusValue {
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
		return StatusValue::newGood();
	}

	/**
	 * @param array<string,list<string>> $data Current worklist content (wiki ID => titles)
	 * @param array<string,list<string>> $articlesByWiki Changes to apply (wiki ID => titles)
	 * @param string $action
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
}
