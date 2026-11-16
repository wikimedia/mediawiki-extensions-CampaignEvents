<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Hooks\Handlers;

use Generator;
use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\CampaignEvents\Hooks\Handlers\ReadingListsHandler;
use MediaWiki\Extension\CampaignEvents\MWEntity\GlobalEditCountLookup;
use MediaWiki\Output\OutputPage;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\User;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\Hooks\Handlers\ReadingListsHandler
 */
class ReadingListsHandlerTest extends MediaWikiUnitTestCase {
	private const MODULE = 'ext.campaignEvents.readingLists';

	/**
	 * @dataProvider provideOnSpecialPageBeforeExecute
	 */
	public function testOnSpecialPageBeforeExecute(
		string $pageName,
		bool $flagEnabled,
		bool $isNamed,
		?int $globalEditCount,
		bool $expectsModule
	): void {
		$user = $this->createMock( User::class );
		$user->method( 'isNamed' )->willReturn( $isNamed );

		$output = $this->createMock( OutputPage::class );
		$output->expects( $expectsModule ? $this->once() : $this->never() )
			->method( 'addModules' )
			->with( self::MODULE );

		$special = $this->createMock( SpecialPage::class );
		$special->method( 'getName' )->willReturn( $pageName );
		$special->method( 'getUser' )->willReturn( $user );
		$special->method( 'getOutput' )->willReturn( $output );

		// Null means that the edit count must not be looked up: a cheaper check stops before it.
		if ( $globalEditCount === null ) {
			$editCountLookup = $this->createNoOpMock( GlobalEditCountLookup::class );
		} else {
			$editCountLookup = $this->createMock( GlobalEditCountLookup::class );
			$editCountLookup->method( 'getGlobalEditCount' )->with( $user )->willReturn( $globalEditCount );
		}

		$handler = new ReadingListsHandler(
			new HashConfig( [ 'CampaignEventsEnableReadingListsCardElements' => $flagEnabled ] ),
			$editCountLookup
		);
		$handler->onSpecialPageBeforeExecute( $special, null );
	}

	public static function provideOnSpecialPageBeforeExecute(): Generator {
		$page = ReadingListsHandler::SPECIAL_PAGE_NAME;
		yield 'Another special page' => [ 'Watchlist', true, true, null, false ];
		// getName() returns the canonical name, so the old alias never matches.
		yield 'Old page name' => [ 'ReadingLists', true, true, null, false ];
		yield 'Flag off' => [ $page, false, true, null, false ];
		yield 'Not named (anonymous or temporary user)' => [ $page, true, false, null, false ];
		yield 'No edits' => [ $page, true, true, 0, false ];
		yield 'One edit less than the minimum' => [
			$page, true, true, ReadingListsHandler::MIN_GLOBAL_EDITS - 1, false
		];
		yield 'Exactly the minimum' => [ $page, true, true, ReadingListsHandler::MIN_GLOBAL_EDITS, true ];
		yield 'More than the minimum' => [ $page, true, true, 1000, true ];
	}
}
