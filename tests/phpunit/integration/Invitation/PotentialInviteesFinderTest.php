<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Invitation;

use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\Tests\Integration\InvitationListTestHelperTrait;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;

/**
 * @group Test
 * @group Database
 * @covers \MediaWiki\Extension\CampaignEvents\Invitation\PotentialInviteesFinder
 */
class PotentialInviteesFinderTest extends MediaWikiIntegrationTestCase {
	use InvitationListTestHelperTrait;

	public function addDBDataOnce(): void {
		$this->insertWorklistData();
	}

	public function testGenerate() {
		$pageNamesByWiki = [
			WikiMap::getCurrentWikiId() => [
				'Page 1',
				'Page 2',
				'Page 3',
			]
		];
		$debugLogs = '';
		$debugLogCollector = static function ( string $msg ) use ( &$debugLogs ): void {
			$debugLogs .= $msg . "\n";
		};
		$finder = CampaignEventsServices::getPotentialInviteesFinder();
		$finder->setDebugLogger( $debugLogCollector );
		$invitationList = $finder->generate( $pageNamesByWiki );

		$this->assertArrayEquals(
			[
				'User1',
				'User2',
				'User3'
			],
			array_keys( $invitationList )
		);

		$outSections = preg_split( '/(?=^==[^=].+==$)/m', $debugLogs, -1, PREG_SPLIT_NO_EMPTY );
		$this->assertCount( 3, $outSections );
		[ $progressText, $contributionsSection, $scoresDebugSection ] = $outSections;

		$curWikiID = WikiMap::getCurrentWikiId();
		$this->assertSame(
			"Running $curWikiID batch #1.1 of 1 from pageID=1",
			rtrim( $progressText )
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
