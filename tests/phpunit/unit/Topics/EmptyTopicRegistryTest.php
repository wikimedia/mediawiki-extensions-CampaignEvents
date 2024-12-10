<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Topics;

use MediaWiki\Extension\CampaignEvents\Topics\EmptyTopicRegistry;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\Topics\EmptyTopicRegistry
 */
class EmptyTopicRegistryTest extends MediaWikiUnitTestCase {

	public function testGetAllTopicsReturnsEmpty() {
		$registry = new EmptyTopicRegistry();
		$result = $registry->getAllTopics();
		$this->assertSame( [], $result );
	}

	public function testGetTopicsForSelectReturnsEmpty() {
		$registry = new EmptyTopicRegistry();
		$result = $registry->getTopicsForSelect();
		$this->assertSame( [], $result );
	}

	public function testGetLocalisedTopicNamesReturnsEmpty() {
		$registry = new EmptyTopicRegistry();
		$result = $registry->getTopicMessages( [] );
		$this->assertSame( [], $result );
	}
}
