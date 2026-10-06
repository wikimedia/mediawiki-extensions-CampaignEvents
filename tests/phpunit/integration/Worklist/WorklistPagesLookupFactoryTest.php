<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Worklist;

use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\Worklist\DatabaseBasedWorklistPagesLookup;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Page\PageIdentityValue;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\Worklist\WorklistPagesLookupFactory
 * @group Database
 */
class WorklistPagesLookupFactoryTest extends MediaWikiIntegrationTestCase {
	public function testGetLookupForWorklist() {
		$factory = CampaignEventsServices::getWorklistPagesLookupFactory();
		$page = new PageIdentityValue( 42, NS_MAIN, 'Test worklist', PageIdentity::LOCAL );
		$lookup = $factory->getLookupForWorklist( $page );
		$this->assertInstanceOf( DatabaseBasedWorklistPagesLookup::class, $lookup );
	}
}
