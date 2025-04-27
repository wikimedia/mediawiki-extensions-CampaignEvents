<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\MWEntity;

use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use MediaWiki\Page\PageIdentityValue;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy
 */
class MWPageProxyTest extends MediaWikiUnitTestCase {
	public function testSerialization(): void {
		$page = new PageIdentityValue( 2, NS_MAIN, 'Foo', 'bar' );
		$proxy = new MWPageProxy( $page, 'Foo' );

		$unserialized = unserialize( serialize( $proxy ) );

		$this->assertInstanceOf( MWPageProxy::class, $unserialized );
		$this->assertSame( $proxy->getDBkey(), $unserialized->getDBkey() );
		$this->assertSame( $proxy->getNamespace(), $unserialized->getNamespace() );
		$this->assertSame( $proxy->getWikiId(), $unserialized->getWikiId() );
		$this->assertSame( $proxy->getPrefixedText(), $unserialized->getPrefixedText() );
	}
}
