<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Maintenance;

use MediaWiki\Extension\CampaignEvents\Maintenance\GenerateInvitationList;
use MediaWiki\Extension\CampaignEvents\Tests\Integration\InvitationListTestHelperTrait;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use MediaWiki\WikiMap\WikiMap;

/**
 * @group Test
 * @group Database
 * @covers \MediaWiki\Extension\CampaignEvents\Maintenance\GenerateInvitationList
 */
class GenerateInvitationListTest extends MaintenanceBaseTestCase {
	use InvitationListTestHelperTrait;

	/**
	 * @inheritDoc
	 */
	protected function getMaintenanceClass(): string {
		return GenerateInvitationList::class;
	}

	public function addDBDataOnce(): void {
		$this->insertWorklistData();
	}

	public function testExecute() {
		$listFilePath = $this->getNewTempFile();
		$list = implode(
			"\n",
			[
				':Page 1',
				':Page 2',
				':Page 3',
			]
		);
		file_put_contents( $listFilePath, $list );
		$this->maintenance->setOption( 'listfile', $listFilePath );
		$this->maintenance->execute();

		$output = $this->getActualOutputForAssertion();
		$outSections = preg_split( '/(?=^==[^=].+==$)/m', $output, -1, PREG_SPLIT_NO_EMPTY );
		$this->assertCount( 4, $outSections );
		[ $articlesSection, $contributionsSection, $scoresDebugSection ] = $outSections;

		$curWikiID = WikiMap::getCurrentWikiId();
		$progressInfo = "Running $curWikiID batch #1.1 of 1 from pageID=1";
		$this->assertStringContainsString( $progressInfo, $articlesSection );
		$articlesSection = str_replace( $progressInfo, '', $articlesSection );
		$this->assertSame(
			"==Articles==\n===$curWikiID===\nPage 1\nPage 2\nPage 3",
			trim( $articlesSection )
		);
		$expectedContribs = "==Contributions==\n" .
			"User1 - [0:Page_1]@$curWikiID - 132\n" .
			"User1 - [0:Page_3]@$curWikiID - 9000\n" .
			"User2 - [0:Page_2]@$curWikiID - 5\n" .
			"User3 - [0:Page_3]@$curWikiID - 30000";
		$this->assertSame( $expectedContribs, trim( $contributionsSection ) );
		$this->assertStringContainsString( 'User User1 edit count 561,', $scoresDebugSection );
		$this->assertStringContainsString( 'User User2 edit count 1105,', $scoresDebugSection );
		$this->assertStringContainsString( 'User User3 edit count 1729,', $scoresDebugSection );
	}
}
