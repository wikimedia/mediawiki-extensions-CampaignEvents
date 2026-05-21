<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Hooks\Handlers;

use MediaWiki\Extension\CampaignEvents\Hooks\Handlers\WorklistPageHandler;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\Hooks\Handlers\WorklistPageHandler
 */
class WorklistPageHandlerTest extends MediaWikiUnitTestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if ( !defined( 'CONTENT_MODEL_WORKLIST' ) ) {
			// The constant is normally defined by ExtensionRegistrationHandler; redefine it here due to T428794.
			define( 'CONTENT_MODEL_WORKLIST', 'worklist' );
		}
	}

	private function newTitle( string $contentModel ): Title {
		$title = $this->createMock( Title::class );
		$title->method( 'getContentModel' )->willReturn( $contentModel );
		return $title;
	}

	private function newUser( bool $isNamed ): User {
		$user = $this->createMock( User::class );
		$user->method( 'isNamed' )->willReturn( $isNamed );
		return $user;
	}

	public function testNonNamedUserEditingWorklistIsDenied(): void {
		$result = null;
		$ret = ( new WorklistPageHandler() )->onGetUserPermissionsErrors(
			$this->newTitle( CONTENT_MODEL_WORKLIST ),
			$this->newUser( false ),
			'edit',
			$result
		);
		$this->assertFalse( $ret );
		$this->assertSame( 'campaignevents-worklist-edit-permission-denied', $result );
	}

	public function testNamedUserEditingWorklistIsAllowed(): void {
		$result = null;
		$ret = ( new WorklistPageHandler() )->onGetUserPermissionsErrors(
			$this->newTitle( CONTENT_MODEL_WORKLIST ),
			$this->newUser( true ),
			'edit',
			$result
		);
		$this->assertTrue( $ret );
		$this->assertNull( $result );
	}

	public function testNonWorklistPageIsIgnored(): void {
		$result = null;
		$ret = ( new WorklistPageHandler() )->onGetUserPermissionsErrors(
			$this->newTitle( CONTENT_MODEL_WIKITEXT ),
			$this->newUser( false ),
			'edit',
			$result
		);
		$this->assertTrue( $ret );
		$this->assertNull( $result );
	}

	public function testNonEditActionIsIgnored(): void {
		$result = null;
		$ret = ( new WorklistPageHandler() )->onGetUserPermissionsErrors(
			$this->newTitle( CONTENT_MODEL_WORKLIST ),
			$this->newUser( false ),
			'read',
			$result
		);
		$this->assertTrue( $ret );
		$this->assertNull( $result );
	}
}
