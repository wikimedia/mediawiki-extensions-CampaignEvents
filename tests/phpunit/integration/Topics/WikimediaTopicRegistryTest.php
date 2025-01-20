<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Topics;

use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\Topics\WikimediaTopicRegistry;
use MediaWikiIntegrationTestCase;

/**
 * @group Test
 * @covers \MediaWiki\Extension\CampaignEvents\Topics\WikimediaTopicRegistry
 */
class WikimediaTopicRegistryTest extends MediaWikiIntegrationTestCase {
	protected function setUp(): void {
		parent::setUp();
		$this->markTestSkippedIfExtensionNotLoaded( 'WikimediaMessages' );
	}

	public function testServiceWiringChoosesWikimediaImplementation(): void {
		$this->assertInstanceOf(
			WikimediaTopicRegistry::class,
			CampaignEventsServices::getTopicRegistry()
		);
	}

	public function testGetTopicsForSelect(): void {
		$actual = CampaignEventsServices::getTopicRegistry()->getTopicsForSelect();
		$this->assertNotEmpty( $actual );
		$firstGroup = key( $actual );
		$this->assertTrue( wfMessage( $firstGroup )->exists(), 'Group key should be a valid message' );
		$firstTopic = key( $actual[$firstGroup] );
		$this->assertTrue( wfMessage( $firstTopic )->exists(), 'Topic key should be a valid message' );
	}
}
