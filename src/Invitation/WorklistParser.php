<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Invitation;

use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Message\Message;
use MediaWiki\Page\PageStoreFactory;
use MediaWiki\WikiMap\WikiMap;
use StatusValue;
use Wikimedia\Assert\Assert;

class WorklistParser {
	public const SERVICE_NAME = 'CampaignEventsWorklistParser';

	public const ARTICLES_LIMIT = 300;

	public function __construct(
		private readonly PageStoreFactory $pageStoreFactory,
	) {
	}

	/**
	 * Converts a list of raw page titles into a Worklist object, validating them in the process.
	 *
	 * @param array<string,string[]> $pageNamesByWiki $pageNamesByWiki Wiki IDs should always be string, and not use
	 * WikiAwareEntity::LOCAL to avoid fun autocasting issues where PHP turns `false` into `0` when used as array key.
	 * @return StatusValue If good, the value is a Worklist object.
	 */
	public function parseWorklist( array $pageNamesByWiki ): StatusValue {
		Assert::parameterKeyType( 'string', $pageNamesByWiki, '$pageNamesByWiki' );
		$totalPageCount = array_sum( array_map( 'count', $pageNamesByWiki ) );
		if ( $totalPageCount === 0 ) {
			return StatusValue::newFatal( 'campaignevents-worklist-error-empty' );
		}
		if ( $totalPageCount > self::ARTICLES_LIMIT ) {
			return StatusValue::newFatal(
				'campaignevents-worklist-error-too-large',
				Message::numParam( $totalPageCount ),
				Message::numParam( self::ARTICLES_LIMIT )
			);
		}

		$curWikiID = WikiMap::getCurrentWikiId();
		$pagesByWiki = [];
		$invalidTitles = [];
		$nonexistentPages = [];
		$nonMainspacePages = [];
		foreach ( $pageNamesByWiki as $wikiID => $pageNames ) {
			$pageStore = $this->pageStoreFactory->getPageStore(
				$wikiID !== $curWikiID ? $wikiID : WikiAwareEntity::LOCAL
			);
			foreach ( $pageNames as $pageName ) {
				// Note: If $pageName happens to contain a namespace identifier, or really anything that cannot be
				// parsed in the context of the current wiki, this method won't behave correctly due to T353916. There
				// doesn't seem to be much that we can do about it.
				// FIXME: Batching!
				$page = $pageStore->getPageByText( $pageName );
				if ( !$page ) {
					$invalidTitles[] = $pageName;
				} elseif ( !$page->exists() ) {
					$nonexistentPages[] = $pageName;
				} elseif ( $page->getNamespace() !== NS_MAIN ) {
					$nonMainspacePages[] = $pageName;
				} else {
					$pagesByWiki[$wikiID] ??= [];
					$pagesByWiki[$wikiID][] = $page;
				}
			}
		}

		$ret = StatusValue::newGood();
		// NOTE: The messages below need to be wrapped in Message objects due to T368821.
		if ( $invalidTitles ) {
			$ret->fatal( new Message(
				'campaignevents-worklist-error-invalid-titles',
				[
					Message::numParam( count( $invalidTitles ) ),
					self::pagesToBulletList( $invalidTitles )
				]
			) );
		}
		if ( $nonexistentPages ) {
			$ret->fatal( new Message(
				'campaignevents-worklist-error-nonexistent-titles',
				[
					Message::numParam( count( $nonexistentPages ) ),
					self::pagesToBulletList( $nonexistentPages )
				]
			) );
		}
		if ( $nonMainspacePages ) {
			$ret->fatal( new Message(
				'campaignevents-worklist-error-titles-not-mainspace',
				[
					Message::numParam( count( $nonMainspacePages ) ),
					self::pagesToBulletList( $nonMainspacePages )
				]
			) );
		}

		if ( $ret->isGood() ) {
			$ret->setResult( true, new Worklist( $pagesByWiki ) );
		}

		return $ret;
	}

	/**
	 * Given a list of page titles, return a bullet list with those titles.
	 * @param string[] $pageTitles
	 */
	private static function pagesToBulletList( array $pageTitles ): string {
		// Don't call wfEscapeWikiText in unit tests since it uses global state.
		$pageEscaper = defined( 'MW_PHPUNIT_TEST' )
			? static fn ( string $x ): string => $x
			: 'wfEscapeWikiText';
		return "<ul>\n<li>" .
			implode( "</li>\n<li>", array_map( $pageEscaper, $pageTitles ) ) .
			"</li>\n</ul>";
	}
}
