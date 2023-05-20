<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Organizers;

use InvalidArgumentException;
use MediaWiki\Extension\CampaignEvents\Organizers\RoleFormatter;
use MediaWiki\Extension\CampaignEvents\Organizers\Roles;
use MediaWikiUnitTestCase;
use ReflectionClass;
use Wikimedia\Message\IMessageFormatterFactory;
use Wikimedia\Message\ITextFormatter;
use Wikimedia\TestingAccessWrapper;

/**
 * @group Test
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Organizers\RoleFormatter
 * @covers ::__construct()
 */
class RoleFormatterTest extends MediaWikiUnitTestCase {
	/**
	 * Tests that the given constant maps all defined roles
	 * @coversNothing
	 * @dataProvider provideConstants
	 */
	public function testConstantMapsAllRoles( string $constName ) {
		$allRoles = [];
		$rolesRefl = new ReflectionClass( Roles::class );
		foreach ( $rolesRefl->getConstants() as $name => $val ) {
			if ( str_starts_with( $name, 'ROLE_' ) && $val !== Roles::ROLE_TEST ) {
				$allRoles[] = $val;
			}
		}
		$actualMap = TestingAccessWrapper::constant( RoleFormatter::class, $constName );

		$this->assertArrayEquals( $allRoles, array_keys( $actualMap ) );
	}

	public static function provideConstants(): array {
		return [
			'ROLES_MSG_MAP' => [ 'ROLES_MSG_MAP' ],
			'DEBUG_NAMES_MAP' => [ 'DEBUG_NAMES_MAP' ],
		];
	}

	/**
	 * @covers ::getLocalizedName
	 */
	public function testGetLocalizedName() {
		$enMsg = 'Name in English';
		$enFormatter = $this->createMock( ITextFormatter::class );
		$enFormatter->method( 'format' )->willReturn( $enMsg );
		$frMsg = 'Name in French';
		$frFormatter = $this->createMock( ITextFormatter::class );
		$frFormatter->method( 'format' )->willReturn( $frMsg );
		$msgFormatterFactory = $this->createMock( IMessageFormatterFactory::class );
		$msgFormatterFactory->expects( $this->atLeastOnce() )->method( 'getTextFormatter' )
			->willReturnMap( [
				[ 'en', $enFormatter ],
				[ 'fr', $frFormatter ]
			] );
		$formatter = new RoleFormatter( $msgFormatterFactory );

		// Implicitly asserts that the messages are different, too.
		$this->assertSame( $enMsg, $formatter->getLocalizedName( Roles::ROLE_CREATOR, 'Admin', 'en' ) );
		$this->assertSame( $frMsg, $formatter->getLocalizedName( Roles::ROLE_CREATOR, 'Admin', 'fr' ) );
	}

	/**
	 * @covers ::getLocalizedName
	 */
	public function testGetLocalizedName__invalid() {
		$formatter = new RoleFormatter( $this->createMock( IMessageFormatterFactory::class ) );
		$this->expectException( InvalidArgumentException::class );
		$formatter->getLocalizedName( 'This role definitely does not exist', 'Admin', 'en' );
	}

	/**
	 * @covers ::getDebugName
	 */
	public function testGetDebugName() {
		$formatter = new RoleFormatter( $this->createMock( IMessageFormatterFactory::class ) );
		$this->assertIsString( $formatter->getDebugName( Roles::ROLE_CREATOR ) );
	}

	/**
	 * @covers ::getDebugName
	 */
	public function testGetDebugName__invalid() {
		$formatter = new RoleFormatter( $this->createMock( IMessageFormatterFactory::class ) );
		$this->expectException( InvalidArgumentException::class );
		$formatter->getDebugName( 'This role definitely does not exist' );
	}
}
