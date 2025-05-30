<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Event;

use InvalidArgumentException;
use MediaWiki\Extension\CampaignEvents\Event\EventTypesRegistry;
use MediaWikiUnitTestCase;
use Wikimedia\Message\IMessageFormatterFactory;
use Wikimedia\Message\ITextFormatter;

/**
 * @group Test
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Event\EventTypesRegistry
 * @covers ::__construct
 */
class EventTypesRegistryTest extends MediaWikiUnitTestCase {

	public function testRegistryIsWellFormed() {
		$seenGroupNames = [];
		$seenGroupMessages = [];
		$seenTypeNames = [];
		$seenTypeMessages = [];
		$seenTypeDBValues = [];
		foreach ( EventTypesRegistry::EVENT_TYPES as $group ) {
			$this->assertArrayHasKey( 'group', $group );
			$this->assertIsString( $group['group'], 'Group name' );
			$seenGroupNames[] = $group['group'];

			$this->assertArrayHasKey( 'msgKey', $group );
			$this->assertIsString( $group['msgKey'], 'Group message key' );
			$seenGroupMessages[] = $group['msgKey'];

			$this->assertArrayHasKey( 'types', $group );
			$this->assertIsArray( $group['types'], 'Group types' );

			foreach ( $group['types'] as $type ) {
				$this->assertIsArray( $type, 'Individual type' );

				$this->assertArrayHasKey( 'type', $type );
				$this->assertIsString( $type['type'], 'Type name' );
				$seenTypeNames[] = $type['type'];

				$this->assertArrayHasKey( 'msgKey', $type );
				$this->assertIsString( $type['msgKey'], 'Type message key' );
				$seenTypeMessages[] = $type['msgKey'];

				$this->assertArrayHasKey( 'dbValue', $type );
				$this->assertIsInt( $type['dbValue'], 'Type DB value' );
				$seenTypeDBValues[] = $type['dbValue'];
			}
		}

		$this->assertSame( array_unique( $seenGroupNames ), $seenGroupNames, 'Group names should be unique' );
		$this->assertSame( array_unique( $seenGroupMessages ), $seenGroupMessages, 'Group messages should be unique' );
		$this->assertSame( array_unique( $seenTypeNames ), $seenTypeNames, 'Type names should be unique' );
		$this->assertSame( array_unique( $seenTypeMessages ), $seenTypeMessages, 'Type messages should be unique' );
		$this->assertSame( array_unique( $seenTypeDBValues ), $seenTypeDBValues, 'Type DB values should be unique' );
	}

	/** @covers ::getAllTypes */
	public function testGetAllTypes() {
		$registry = new EventTypesRegistry( $this->createMock( IMessageFormatterFactory::class ) );
		$expectedTypes = [
			EventTypesRegistry::EVENT_TYPE_OTHER,
			'editing-event', 'media-upload-event', 'backlog-drive', 'contest', 'workshop',
			'training', 'meetup', 'hackathon', 'conference',
		];
		$actualTypes = $registry->getAllTypes();
		sort( $expectedTypes );
		sort( $actualTypes );
		$this->assertSame( $expectedTypes, $actualTypes );
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
			$registry->getLocalizedEventTypeName( 'editing-event', 'en' )
		);
		$this->assertSame(
			$frMsg,
			$registry->getLocalizedEventTypeName( 'editing-event', 'fr' )
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
	 * @dataProvider provideGetEventTypesFromDBVal
	 * @covers ::getEventTypesFromDBVal
	 */
	public function testGetEventTypesFromDBVal( string $dbVal, array $expected ) {
		$this->assertSame( $expected, EventTypesRegistry::getEventTypesFromDBVal( $dbVal ) );
	}

	public static function provideGetEventTypesFromDBVal(): array {
		return [
			'Other' => [ '0', [ 'other' ] ],
			'Single type' => [ (string)( 1 << 3 ), [ 'contest' ] ],
			'Combination of types' => [ (string)( 1 << 3 | 1 << 6 ), [ 'contest', 'meetup' ] ],
		];
	}

	/**
	 * @dataProvider provideEventTypesToDBVal
	 * @covers ::eventTypesToDBVal
	 */
	public function testEventTypesToDBVal( array $types, int $expected ) {
		$this->assertSame( $expected, EventTypesRegistry::eventTypesToDBVal( $types ) );
	}

	public static function provideEventTypesToDBVal(): array {
		return [
			'Other' => [ [ 'other' ], 0 ],
			'Single type' => [ [ 'contest' ], 1 << 3 ],
			'Combination of types' => [ [ 'contest', 'meetup' ], 1 << 3 | 1 << 6 ],
		];
	}
}
