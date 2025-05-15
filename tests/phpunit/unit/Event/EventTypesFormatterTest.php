<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Event;

use InvalidArgumentException;
use MediaWiki\Extension\CampaignEvents\Event\EventTypesFormatter;
use MediaWiki\Extension\CampaignEvents\Event\EventTypesRegistry;
use MediaWikiUnitTestCase;
use ReflectionClass;
use Wikimedia\Message\IMessageFormatterFactory;
use Wikimedia\Message\ITextFormatter;

/**
 * @group Test
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Event\EventTypesFormatter
 * @covers ::__construct()
 */
class EventTypesFormatterTest extends MediaWikiUnitTestCase {

	/**
	 * @coversNothing
	 * @dataProvider provideConstants
	 */
	public function testConstantMapsAllEventTypes( string $constName ) {
		$registry = new EventTypesRegistry();
		$allTypes = $registry->getAllTypes();

		$formatterRefl = new ReflectionClass( EventTypesFormatter::class );
		$actualMap = $formatterRefl->getConstant( $constName );

		$this->assertEqualsCanonicalizing( $allTypes, array_keys( $actualMap ) );
	}

	public static function provideConstants(): array {
		return [
			[ 'EVENT_TYPE_MSG_MAP' ],
			[ 'DEBUG_NAMES_MAP' ],
		];
	}

	/**
	 * @covers ::getLocalizedEventTypeName
	 */
	public function testGetLocalizedEventTypeName() {
		$enMsg = 'Editing event (EN)';
		$frMsg = 'Événement d\'édition (FR)';

		$enFormatter = $this->createMock( ITextFormatter::class );
		$enFormatter->method( 'format' )->willReturn( $enMsg );

		$frFormatter = $this->createMock( ITextFormatter::class );
		$frFormatter->method( 'format' )->willReturn( $frMsg );

		$msgFormatterFactory = $this->createMock( IMessageFormatterFactory::class );
		$msgFormatterFactory->expects( $this->atLeastOnce() )
			->method( 'getTextFormatter' )
			->willReturnMap( [
				[ 'en', $enFormatter ],
				[ 'fr', $frFormatter ],
			] );

		$formatter = new EventTypesFormatter( $msgFormatterFactory );

		$this->assertSame(
			$enMsg,
			$formatter->getLocalizedEventTypeName( EventTypesRegistry::EVENT_TYPE_EDITING_EVENT, 'en' )
		);
		$this->assertSame(
			$frMsg,
			$formatter->getLocalizedEventTypeName( EventTypesRegistry::EVENT_TYPE_EDITING_EVENT, 'fr' )
		);
	}

	/**
	 * @covers ::getLocalizedEventTypeName
	 */
	public function testGetLocalizedEventTypeName__invalid() {
		$formatter = new EventTypesFormatter(
			$this->createMock( IMessageFormatterFactory::class )
		);
		$this->expectException( InvalidArgumentException::class );
		$formatter->getLocalizedEventTypeName( 'invalid-event-type', 'en' );
	}

	/**
	 * @covers ::getEventTypeDebugName
	 */
	public function testGetEventTypeDebugName() {
		$formatter = new EventTypesFormatter(
			$this->createMock( IMessageFormatterFactory::class )
		);
		$this->assertIsString(
			$formatter->getEventTypeDebugName( EventTypesRegistry::EVENT_TYPE_CONFERENCE )
		);
	}

	/**
	 * @covers ::getEventTypeDebugName
	 */
	public function testGetEventTypeDebugName__invalid() {
		$formatter = new EventTypesFormatter(
			$this->createMock( IMessageFormatterFactory::class )
		);
		$this->expectException( InvalidArgumentException::class );
		$formatter->getEventTypeDebugName( 'not_a_type' );
	}

	/**
	 * @covers ::getLocalizedGroupTypeName
	 */
	public function testGetLocalizedGroupTypeName() {
		$enMsg = 'Contributions (EN)';
		$formatter = $this->createMock( ITextFormatter::class );
		$formatter->method( 'format' )->willReturn( $enMsg );

		$factory = $this->createMock( IMessageFormatterFactory::class );
		$factory->method( 'getTextFormatter' )->willReturn( $formatter );

		$instance = new EventTypesFormatter( $factory );

		$this->assertSame(
			$enMsg,
			$instance->getLocalizedGroupTypeName( 'contributions', 'en' )
		);
	}

	/**
	 * @covers ::getLocalizedGroupTypeName
	 */
	public function testGetLocalizedGroupTypeName__invalid() {
		$formatter = new EventTypesFormatter(
			$this->createMock( IMessageFormatterFactory::class )
		);
		$this->expectException( InvalidArgumentException::class );
		$formatter->getLocalizedGroupTypeName( 'invalid_group', 'en' );
	}

	/**
	 * @covers ::getEventTypeGroupsDebugName
	 */
	public function testGetEventTypeGroupsDebugName() {
		$formatter = new EventTypesFormatter(
			$this->createMock( IMessageFormatterFactory::class )
		);

		$this->assertSame(
			'community',
			$formatter->getEventTypeGroupsDebugName( 'community' )
		);
	}

	/**
	 * @covers ::getEventTypeGroupsDebugName
	 */
	public function testGetEventTypeGroupsDebugName__invalid() {
		$formatter = new EventTypesFormatter(
			$this->createMock( IMessageFormatterFactory::class )
		);
		$this->expectException( InvalidArgumentException::class );
		$formatter->getEventTypeGroupsDebugName( 'invalid_group' );
	}
}
