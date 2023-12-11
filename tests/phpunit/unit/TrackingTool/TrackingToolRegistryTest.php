<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\TrackingTool;

use Generator;
use MediaWiki\Config\HashConfig;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\TrackingTool\Tool\WikiEduDashboard;
use MediaWiki\Extension\CampaignEvents\TrackingTool\ToolNotFoundException;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolRegistry;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\MainConfigNames;
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
			ParticipantsStore::SERVICE_NAME,
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
					case ParticipantsStore::SERVICE_NAME:
						return $this->createMock( ParticipantsStore::class );
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
	private function getRegistry( bool $mockRegistry = false ): TrackingToolRegistry {
		$options = new ServiceOptions(
			TrackingToolRegistry::CONSTRUCTOR_OPTIONS,
			new HashConfig( [
				'CampaignEventsProgramsAndEventsDashboardInstance' => 'staging',
				'CampaignEventsProgramsAndEventsDashboardAPISecret' => 'foo',
				MainConfigNames::CopyUploadProxy => false,
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
		$registry = $this->getRegistry()->getRegistryForTesting();
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
	 * @dataProvider provideDBIDs
	 */
	public function testNewFromDBID( int $dbID, string $expectedClass ) {
		$tool = $this->getRegistry()->newFromDBID( $dbID );
		$this->assertInstanceOf( $expectedClass, $tool );
		$this->assertSame( $dbID, $tool->getDBID() );
	}

	public function provideDBIDs(): Generator {
		$internalRegistry = $this->getRegistry()->getRegistryForTesting();
		foreach ( $internalRegistry as $toolName => $data ) {
			yield $toolName => [ $data['db-id'], $data['class'] ];
		}
	}

	/**
	 * @covers ::newFromDBID
	 */
	public function testNewFromDBID__notFound() {
		$nonExistingDBID = 674587164857435;
		$this->expectException( ToolNotFoundException::class );
		$this->getRegistry( true )->newFromDBID( $nonExistingDBID );
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
		$this->assertSame( $expected, $this->getRegistry( true )->getDataForForm() );
	}

	/**
	 * @covers ::newFromUserIdentifier
	 * @covers ::newFromRegistryEntry
	 * @dataProvider provideUserIdentifiers
	 */
	public function testNewFromUserIdentifier( string $userID, string $expectedClass ) {
		$tool = $this->getRegistry()->newFromUserIdentifier( $userID );
		$this->assertInstanceOf( $expectedClass, $tool );
	}

	public function provideUserIdentifiers(): Generator {
		$internalRegistry = $this->getRegistry()->getRegistryForTesting();
		foreach ( $internalRegistry as $toolName => $data ) {
			yield $toolName => [ $data['user-id'], $data['class'] ];
		}
	}

	/**
	 * @covers ::newFromUserIdentifier
	 */
	public function testNewFromUserIdentifier__notFound() {
		$nonExistingUserID = 'ariubarevieubfouayvcuyrueygiuayvgrearvaregerg';
		$this->expectException( ToolNotFoundException::class );
		$this->getRegistry( true )->newFromUserIdentifier( $nonExistingUserID );
	}

	/**
	 * @covers ::getUserInfo
	 */
	public function testGetUserInfo() {
		$this->assertSame(
			[
				'user-id' => 'wikimedia-pe-dashboard',
				'display-name-msg' => 'campaignevents-tracking-tool-p&e-dashboard-name',
				'tool-event-url' => 'https://dashboard-testing.wikiedu.org/courses/foo',
			],
			$this->getRegistry()->getUserInfo( 1, 'foo' )
		);
	}

	/**
	 * @covers ::getUserInfo
	 */
	public function testGetUserInfo__notFound() {
		$nonExistingDBID = 674587164857435;
		$this->expectException( ToolNotFoundException::class );
		$this->getRegistry( true )->getUserInfo( $nonExistingDBID, 'foo' );
	}

	/**
	 * @covers ::getToolEventIDFromURL
	 */
	public function testGetToolEventIDFromURL() {
		$toolEventID = 'Institution/Coursename';
		$this->assertSame(
			$toolEventID,
			$this->getRegistry()->getToolEventIDFromURL(
				'wikimedia-pe-dashboard',
				"https://dashboard-testing.wikiedu.org/courses/$toolEventID"
			)
		);
	}

	/**
	 * @covers ::getToolEventIDFromURL
	 */
	public function testGetToolEventIDFromURL__notFound() {
		$nonExistingUserID = "Ceci n'est pas un user-id";
		$this->expectException( ToolNotFoundException::class );
		$this->getRegistry( true )->getToolEventIDFromURL( $nonExistingUserID, 'foo' );
	}
}
