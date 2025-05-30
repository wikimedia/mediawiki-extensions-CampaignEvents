<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Event;

use InvalidArgumentException;
use MediaWiki\Extension\CampaignEvents\Event\EventTypesRegistry;
use MediaWikiUnitTestCase;
use ReflectionClass;
use Wikimedia\Message\IMessageFormatterFactory;
use Wikimedia\Message\ITextFormatter;

/**
 * @group Test
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Event\EventTypesRegistry
 * @covers ::__construct
 */
class EventTypesRegistryTest extends MediaWikiUnitTestCase {

	/**
	 * @coversNothing
	 * @dataProvider provideConstants
	 */
	public function testConstantMapsAllEventTypes( string $constName ) {
		$registry = new EventTypesRegistry( $this->createMock( IMessageFormatterFactory::class ) );
		$allTypes = $registry->getAllTypes();

		$registryRefl = new ReflectionClass( EventTypesRegistry::class );
		$actualMap = $registryRefl->getConstant( $constName );

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

		$registry = new EventTypesRegistry( $msgFormatterFactory );

		$this->assertSame(
			$enMsg,
			$registry->getLocalizedEventTypeName( EventTypesRegistry::EVENT_TYPE_EDITING_EVENT, 'en' )
		);
		$this->assertSame(
			$frMsg,
			$registry->getLocalizedEventTypeName( EventTypesRegistry::EVENT_TYPE_EDITING_EVENT, 'fr' )
		);
	}

	/**
	 * @covers ::getLocalizedEventTypeName
	 */
	public function testGetLocalizedEventTypeName__invalid() {
		$registry = new EventTypesRegistry(
			$this->createMock( IMessageFormatterFactory::class )
		);
		$this->expectException( InvalidArgumentException::class );
		$registry->getLocalizedEventTypeName( 'invalid-event-type', 'en' );
	}

	/**
	 * @covers ::getEventTypeDebugName
	 */
	public function testGetEventTypeDebugName() {
		$registry = new EventTypesRegistry(
			$this->createMock( IMessageFormatterFactory::class )
		);
		$this->assertIsString(
			$registry->getEventTypeDebugName( EventTypesRegistry::EVENT_TYPE_CONFERENCE )
		);
	}

	/**
	 * @covers ::getEventTypeDebugName
	 */
	public function testGetEventTypeDebugName__invalid() {
		$registry = new EventTypesRegistry(
			$this->createMock( IMessageFormatterFactory::class )
		);
		$this->expectException( InvalidArgumentException::class );
		$registry->getEventTypeDebugName( 'not_a_type' );
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

		$registry = new EventTypesRegistry( $factory );

		$this->assertSame(
			$enMsg,
			$registry->getLocalizedGroupTypeName( 'contributions', 'en' )
		);
	}

	/**
	 * @covers ::getLocalizedGroupTypeName
	 */
	public function testGetLocalizedGroupTypeName__invalid() {
		$registry = new EventTypesRegistry(
			$this->createMock( IMessageFormatterFactory::class )
		);
		$this->expectException( InvalidArgumentException::class );
		$registry->getLocalizedGroupTypeName( 'invalid_group', 'en' );
	}

	/**
	 * @covers ::getEventTypeGroupsDebugName
	 */
	public function testGetEventTypeGroupsDebugName() {
		$registry = new EventTypesRegistry(
			$this->createMock( IMessageFormatterFactory::class )
		);

		$this->assertSame(
			'community',
			$registry->getEventTypeGroupsDebugName( 'community' )
		);
	}

	/**
	 * @covers ::getEventTypeGroupsDebugName
	 */
	public function testGetEventTypeGroupsDebugName__invalid() {
		$registry = new EventTypesRegistry(
			$this->createMock( IMessageFormatterFactory::class )
		);
		$this->expectException( InvalidArgumentException::class );
		$registry->getEventTypeGroupsDebugName( 'invalid_group' );
	}
}
