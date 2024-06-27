<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Invitation;

use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Page\PageIdentity;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\Assert\Assert;

/**
 * Value object that represents a worklist, i.e. a list of articles for which we can generate invitation lists.
 */
class Worklist {
	/**
	 * @var PageIdentity[][]
	 * @phan-var non-empty-array<string,non-empty-list<PageIdentity>>
	 */
	private array $pagesByWiki;

	/**
	 * @param array<string,PageIdentity[]> $pagesByWiki Must have been validated by WorklistParser.
	 * @phan-param non-empty-array<string,non-empty-list<PageIdentity>> $pagesByWiki
	 */
	public function __construct( array $pagesByWiki ) {
		Assert::parameterElementType( 'array', $pagesByWiki, '$pagesByWiki' );
		Assert::parameterKeyType( 'string', $pagesByWiki, '$pagesByWiki' );
		$curWikiID = WikiMap::getCurrentWikiId();
		foreach ( $pagesByWiki as $wiki => $pages ) {
			Assert::precondition( $pages !== [], 'Pages must not be empty' );
			foreach ( $pages as $page ) {
				Assert::precondition( $page instanceof PageIdentity, 'Pages must be PageIdentity objects' );
				if ( $page->getWikiId() === WikiAwareEntity::LOCAL ) {
					Assert::precondition( $wiki === $curWikiID, 'Page wiki ID should match array key' );
				} else {
					Assert::precondition( $page->getWikiId() === $wiki, 'Page wiki ID should match array key' );
				}
				Assert::precondition( $page->exists(), 'Pages must exist' );
				Assert::precondition( $page->getNamespace() === NS_MAIN, 'Pages must be in the mainspace' );
			}
		}
		$this->pagesByWiki = $pagesByWiki;
	}

	/**
	 * @return array<string,PageIdentity[]>
	 * @phan-return non-empty-array<string,non-empty-list<PageIdentity>>
	 */
	public function getPagesByWiki(): array {
		return $this->pagesByWiki;
	}
}
