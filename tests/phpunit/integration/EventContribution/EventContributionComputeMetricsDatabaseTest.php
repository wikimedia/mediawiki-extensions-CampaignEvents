<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\EventContribution;

use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContribution;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group Test
 * @group Database
 * @covers \MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionComputeMetrics
 */
class EventContributionComputeMetricsDatabaseTest extends MediaWikiIntegrationTestCase {
	public function testComputeEventContribution() {
		$eventID = 123;
		$userID = 456;
		$userName = 'Some username';
		$wikiID = WikiMap::getCurrentWikiId();
		$timestamp = '20250929150000';
		ConvertibleTimestamp::setFakeTime( $timestamp );

		// XXX: CentralAuth wouldn't give us a central user by default (T407288), so rather than handling both the CA
		// and non-CA cases, just mock the service.
		$centralUserLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$centralUserLookup->method( 'getUserName' )->willReturn( $userName );
		$this->setService( CampaignsCentralUserLookup::SERVICE_NAME, $centralUserLookup );

		$page = $this->getNonexistingTestPage();
		$firstText = 'Some random text, then [[internal link]], also to [[Help:Namespace|another namespace]]. Also ' .
			'[[:w:Main Page|interwiki links]] and [https://example.org an external link]. Also emojis: 🤦🏼‍♂️🏴‍☠️';
		$firstBytesDelta = strlen( $firstText );
		$firstLinksDelta = 3;
		$this->editPage( $page, $firstText );
		$firstRevID = $page->getLatest();

		$computeMetrics = CampaignEventsServices::getEventContributionComputeMetrics();
		$firstContrib = $computeMetrics->computeEventContribution( $firstRevID, $eventID, $userID, $wikiID );
		$this->assertEquals(
			new EventContribution(
				$eventID,
				$userID,
				$userName,
				$wikiID,
				$page->getTitle()->getPrefixedText(),
				$page->getId(),
				$firstRevID,
				EventContribution::EDIT_FLAG_PAGE_CREATION,
				$firstBytesDelta,
				$firstLinksDelta,
				$timestamp,
				false
			),
			$firstContrib,
			'Check page creation'
		);

		$newText = 'Remove almost [[everything]].';
		$secondBytesDelta = strlen( $newText ) - $firstBytesDelta;
		$secondLinksDelta = 1 - $firstLinksDelta;
		$this->editPage( $page, $newText );
		$secondRevID = $page->getLatest();
		$secondContrib = $computeMetrics->computeEventContribution( $secondRevID, $eventID, $userID, $wikiID );
		$this->assertEquals(
			new EventContribution(
				$eventID,
				$userID,
				$userName,
				$wikiID,
				$page->getTitle()->getPrefixedText(),
				$page->getId(),
				$secondRevID,
				0,
				$secondBytesDelta,
				$secondLinksDelta,
				$timestamp,
				false
			),
			$secondContrib,
			'Check second edit'
		);
	}
}
