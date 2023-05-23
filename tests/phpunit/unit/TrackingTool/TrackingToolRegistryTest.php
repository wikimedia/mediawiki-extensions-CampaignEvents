<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\TrackingTool;

use HashConfig;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\TrackingTool\Tool\WikiEduDashboard;
use MediaWiki\Extension\CampaignEvents\TrackingTool\ToolNotFoundException;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolRegistry;
use MediaWiki\Http\HttpRequestFactory;
use MediaWikiUnitTestCase;
use Psr\Container\ContainerInterface;
use Wikimedia\ObjectFactory\ObjectFactory;
use Wikimedia\Services\NoSuchServiceException;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolRegistry
 */
class TrackingToolRegistryTest extends MediaWikiUnitTestCase {
	private const TEST_REGISTRY_ENTRY = [
		'display-name-msg' => 'some-msg-key',
		'base-url' => 'https://example.org',
		'class' => WikiEduDashboard::class,
		'db-id' => 42,
		'user-id' => 'test-user-id',
		'extra' => [
			'secret' => 'foobar',
			'proxy' => null,
		],
		'services' => [
			'HttpRequestFactory',
			CampaignsCentralUserLookup::SERVICE_NAME,
		]
	];
	private const TEST_REGISTRY = [
		'Test tool' => self::TEST_REGISTRY_ENTRY
	];

	private function getObjectFactory(): ObjectFactory {
		$httpReqFactory = $this->createMock( HttpRequestFactory::class );
		$serviceContainer = $this->createMock( ContainerInterface::class );
		$serviceContainer->method( 'has' )->willReturn( false );
		$serviceContainer->method( 'get' )->willReturnCallback(
			function ( $serviceName ) use ( $httpReqFactory ) {
				switch ( $serviceName ) {
					case 'HttpRequestFactory':
						return $httpReqFactory;
					case CampaignsCentralUserLookup::SERVICE_NAME:
						return $this->createMock( CampaignsCentralUserLookup::class );
					default:
						throw new NoSuchServiceException( $serviceName );
				}
			}
		);
		return new ObjectFactory( $serviceContainer );
	}

	/**
	 * @param bool $mockRegistry If true, will replace the internal registry with TEST_REGISTRY
	 * @return TrackingToolRegistry
	 */
	private function getRegistry( bool $mockRegistry = true ): TrackingToolRegistry {
		$options = new ServiceOptions(
			TrackingToolRegistry::CONSTRUCTOR_OPTIONS,
			new HashConfig( [
				'CampaignEventsProgramsAndEventsDashboardInstance' => 'staging',
				'CampaignEventsProgramsAndEventsDashboardAPISecret' => 'foo',
				'CopyUploadProxy' => null,
			] )
		);
		$instance = new TrackingToolRegistry( $this->getObjectFactory(), $options );
		if ( $mockRegistry ) {
			$instance->setRegistryForTesting( self::TEST_REGISTRY );
		}
		return $instance;
	}

	/**
	 * @covers ::getRegistry
	 */
	public function testRegistryConsistency() {
		$registry = $this->getRegistry( false )->getRegistryForTesting();
		$numElements = count( $registry );
		foreach ( $registry as $element ) {
			$this->assertIsArray( $element, 'Registry entries should be arrays' );
			$this->assertArrayEquals(
				[ 'display-name-msg', 'base-url', 'class', 'db-id', 'user-id', 'extra', 'services' ],
				array_keys( $element ),
				false,
				false,
				'Registry entries should have all the predefined keys.'
			);
			$this->assertIsString( $element['display-name-msg'], 'Display name msg key' );
			$this->assertIsString( $element['base-url'], 'URL' );
			$this->assertTrue(
				is_string( $element['class'] ) && class_exists( $element['class'] ),
				'Class should be a valid class name'
			);
			$this->assertIsInt( $element['db-id'], 'DB ID' );
			$this->assertIsString( $element['user-id'], 'User ID' );
			$this->assertIsArray( $element['extra'], 'Extra' );
		}
		$this->assertCount(
			$numElements,
			array_unique( array_column( $registry, 'db-id' ) ),
			'DB IDs should be unique'
		);
		$this->assertCount(
			$numElements,
			array_unique( array_column( $registry, 'user-id' ) ),
			'User IDs should be unique'
		);
		$this->assertCount(
			$numElements,
			array_unique( array_column( $registry, 'display-name-msg' ) ),
			'Display names should be unique'
		);
	}

	/**
	 * @covers ::newFromDBID
	 * @covers ::newFromRegistryEntry
	 */
	public function testNewFromDBID() {
		$dbID = self::TEST_REGISTRY_ENTRY['db-id'];
		$tool = $this->getRegistry()->newFromDBID( $dbID );
		$this->assertInstanceOf( self::TEST_REGISTRY_ENTRY['class'], $tool );
		$this->assertSame( $dbID, $tool->getDBID() );
	}

	/**
	 * @covers ::newFromDBID
	 */
	public function testNewFromDBID__notFound() {
		$nonExistingDBID = 674587164857435;
		$this->expectException( ToolNotFoundException::class );
		$this->getRegistry()->newFromDBID( $nonExistingDBID );
	}

	/**
	 * @covers ::getDataForForm
	 */
	public function testGetDataForForm() {
		$expected = [
			[
				'display-name-msg' => self::TEST_REGISTRY_ENTRY['display-name-msg'],
				'user-id' => self::TEST_REGISTRY_ENTRY['user-id'],
			]
		];
		$this->assertSame( $expected, $this->getRegistry()->getDataForForm() );
	}

	/**
	 * @covers ::newFromUserIdentifier
	 * @covers ::newFromRegistryEntry
	 */
	public function testNewFromUserIdentifier() {
		$userID = self::TEST_REGISTRY_ENTRY['user-id'];
		$tool = $this->getRegistry()->newFromUserIdentifier( $userID );
		$this->assertInstanceOf( self::TEST_REGISTRY_ENTRY['class'], $tool );
	}

	/**
	 * @covers ::newFromUserIdentifier
	 */
	public function testNewFromUserIdentifier__notFound() {
		$nonExistingUserID = 'ariubarevieubfouayvcuyrueygiuayvgrearvaregerg';
		$this->expectException( ToolNotFoundException::class );
		$this->getRegistry()->newFromUserIdentifier( $nonExistingUserID );
	}
}
