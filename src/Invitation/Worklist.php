<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Invitation;

use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Page\PageIdentityValue;
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
	 * @param array<string,PageIdentity[]> $pagesByWiki Must have been validated by WorklistParser if it comes from
	 * the user.
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
				// No existence and namespace check, they might fail for stored worklists.
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

	/**
	 * Converts this object into an array of native types, suitable for JSON serialization.
	 * @return array
	 */
	public function toPlainArray(): array {
		$ret = [];
		foreach ( $this->pagesByWiki as $wiki => $pages ) {
			$ret[$wiki] = [];
			foreach ( $pages as $page ) {
				$ret[$wiki][] = [
					$page->getId( $page->getWikiId() ),
					$page->getNamespace(),
					$page->getDBkey(),
					$page->getWikiId()
				];
			}
		}
		return $ret;
	}

	/**
	 * Creates a new instance from the given array (created through {@see self::toPlainArray}
	 * @param array $array
	 * @phan-param array<string|false,array<array{0:int,1:int,2:string,3:string|false}>> $array
	 * @return Worklist
	 */
	public static function fromPlainArray( array $array ): Worklist {
		$pagesByWiki = [];
		foreach ( $array as $wiki => $wikiPageData ) {
			$pagesByWiki[$wiki] = [];
			foreach ( $wikiPageData as $pageData ) {
				$pagesByWiki[$wiki][] = new PageIdentityValue( ...$pageData );
			}
		}
		return new self( $pagesByWiki );
	}
}
