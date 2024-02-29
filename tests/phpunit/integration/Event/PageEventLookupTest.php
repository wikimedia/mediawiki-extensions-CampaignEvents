<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Event;

use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\Event\PageEventLookup;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use MediaWiki\Page\PageIdentityValue;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\Event\PageEventLookup
 * @todo Make this a unit test once it's possible to use namespace constants (T310375)
 */
class PageEventLookupTest extends MediaWikiIntegrationTestCase {
	private function getLookup( IEventLookup $eventLookup = null ): PageEventLookup {
		return new PageEventLookup(
			$eventLookup ?? $this->createMock( IEventLookup::class ),
			$this->createMock( CampaignsPageFactory::class )
		);
	}

	public function testGetRegistrationForLocalPage__notInEventNamespace() {
		$page = new PageIdentityValue( 42, NS_PROJECT, 'Some_title', WikiAwareEntity::LOCAL );
		$eventLookup = $this->createNoOpMock( IEventLookup::class );
		$this->assertNull( $this->getLookup( $eventLookup )->getRegistrationForLocalPage( $page ) );
	}

	public function testGetRegistrationForPage__notInEventNamespace() {
		$page = new PageIdentityValue( 42, NS_PROJECT, 'Some_title', WikiAwareEntity::LOCAL );
		$pageProxy = new MWPageProxy( $page, 'DoesNotMatter' );
		$eventLookup = $this->createNoOpMock( IEventLookup::class );
		$this->assertNull( $this->getLookup( $eventLookup )->getRegistrationForPage( $pageProxy ) );
	}
}
