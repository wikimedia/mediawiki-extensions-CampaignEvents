<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Worklist;

use MediaWiki\Content\JsonContent;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Message\Message;
use MediaWiki\Title\MalformedTitleException;
use MediaWiki\Title\Title;
use MediaWiki\WikiMap\WikiMap;
use StatusValue;

class WorklistContent extends JsonContent {
	/** Cached validation result, to avoid recomputation */
	private ?StatusValue $validationStatus = null;

	/** @inheritDoc */
	public function __construct( $text ) {
		parent::__construct( $text, CONTENT_MODEL_WORKLIST );
	}

	public function isValid(): bool {
		if ( !parent::isValid() ) {
			return false;
		}
		return $this->validate()->isGood();
	}

	/**
	 * Validates the content, returning a StatusValue object with detailed error messages.
	 * This is cached to avoid unnecessary recomputation.
	 */
	public function validate(): StatusValue {
		if ( !$this->validationStatus ) {
			if ( !parent::isValid() ) {
				$this->validationStatus = $this->getData();
			} else {
				$data = $this->getData()->getValue();
				$this->validationStatus = $this->validateWorklistData( $data );
			}
		}
		return $this->validationStatus;
	}

	private function validateWorklistData( mixed $data ): StatusValue {
		if ( !is_object( $data ) ) {
			return StatusValue::newFatal( 'campaignevents-worklist-content-not-object' );
		}

		$worklist = get_object_vars( $data );

		// XXX: DI not easily possible for Content classes.
		$validWikis = CampaignEventsServices::getWikiLookup()->getAllWikis();
		$ret = StatusValue::newGood();
		foreach ( $worklist as $wikiID => $wikiPages ) {
			if ( !in_array( $wikiID, $validWikis, true ) ) {
				$ret->fatal( 'campaignevents-worklist-content-nonexistent-wiki', Message::plaintextParam( $wikiID ) );
				continue;
			}

			if ( !is_array( $wikiPages ) ) {
				$ret->fatal(
					'campaignevents-worklist-content-wiki-value-not-array',
					Message::plaintextParam( var_export( $wikiPages, true ) )
				);
				continue;
			}

			if ( !$wikiPages ) {
				$ret->fatal( 'campaignevents-worklist-content-wiki-empty', Message::plaintextParam( $wikiID ) );
				continue;
			}

			$titlesSeen = [];
			foreach ( $wikiPages as $pageElement ) {
				if ( !is_string( $pageElement ) ) {
					$ret->fatal( 'campaignevents-worklist-content-title-not-string', var_export( $pageElement, true ) );
					continue;
				}

				if ( isset( $titlesSeen[$pageElement] ) ) {
					$ret->fatal( 'campaignevents-worklist-content-duplicated-title', $wikiID, $pageElement );
					continue;
				}

				try {
					// XXX: This will always validate the title in the context of the local wiki. But we can't do much
					// better, not even if we include the interwiki prefix... Related: T353916
					$title = Title::newFromTextThrow( $pageElement );
					if ( $title->isExternal() ) {
						$ret->fatal( 'campaignevents-worklist-content-title-with-interwiki', $pageElement );
					}
					if ( $title->hasFragment() ) {
						$ret->fatal( 'campaignevents-worklist-content-title-with-fragment', $pageElement );
					}
					// Make sure titles use the canonical form, to avoid sneaky duplicates and any formatting issues.
					$canonicalPrefixedText = $title->getPrefixedText();
					if ( $canonicalPrefixedText !== $pageElement ) {
						$ret->fatal(
							'campaignevents-worklist-content-title-non-canonical',
							$pageElement,
							$canonicalPrefixedText
						);
					}
				} catch ( MalformedTitleException ) {
					$ret->fatal(
						'campaignevents-worklist-content-invalid-title',
						$wikiID,
						Message::plaintextParam( $pageElement )
					);
					continue;
				}

				$titlesSeen[$pageElement] = true;
			}
		}

		return $ret;
	}

	/**
	 * Returns a list of link targets for pages in the local wiki.
	 *
	 * @return LinkTarget[]
	 */
	public function getLocalLinkTargets(): array {
		if ( !$this->isValid() ) {
			return [];
		}

		$worklist = $this->getData()->getValue();
		$curWikiID = WikiMap::getCurrentWikiId();
		$pages = [];
		foreach ( $worklist->$curWikiID ?? [] as $titleString ) {
			try {
				$pages[] = Title::newFromTextThrow( $titleString );
			} catch ( MalformedTitleException ) {
				// Should never happen
			}
		}
		return $pages;
	}

	/**
	 * Computes lists of removed and added pages between the two contents, in the same shape used by the content itself.
	 * The "before content" is optional so this can be used for page creations. The "after content" is mandatory, as it
	 * should be possible to handle page deletions more easily by just deleting everything, without needing a delta.
	 *
	 * @return array{removed:array<string,string[]>,added:array<string,string[]>}
	 */
	public static function computeDelta( ?self $before, self $after ): array {
		$mapBefore = wfObjectToArray( $before?->getData()->getValue() ?? [] );
		$mapAfter = wfObjectToArray( $after->getData()->getValue() );

		$removed = $added = [];
		foreach ( $mapAfter as $wiki => $pages ) {
			$curAdded = array_values( array_diff( $pages, $mapBefore[$wiki] ?? [] ) );
			if ( $curAdded ) {
				$added[$wiki] = $curAdded;
			}
			$curRemoved = array_values( array_diff( $mapBefore[$wiki] ?? [], $pages ) );
			if ( $curRemoved ) {
				$removed[$wiki] = $curRemoved;
			}
		}
		$removedWikisWithPages = array_diff_key( $mapBefore, $mapAfter );
		$removed = array_merge( $removed, $removedWikisWithPages );

		return [ 'removed' => $removed, 'added' => $added ];
	}
}
