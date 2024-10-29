<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Messaging;

use Generator;
use JobQueueGroup;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Messaging\CampaignsUserMailer;
use MediaWiki\Extension\CampaignEvents\Messaging\EmailUsersJob;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageURLResolver;
use MediaWiki\Extension\CampaignEvents\Participants\Participant;
use MediaWiki\Mail\EmailUser;
use MediaWiki\Mail\EmailUserFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;
use MediaWiki\User\CentralId\CentralIdLookup;
use MediaWiki\User\Options\UserOptionsLookup;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWikiUnitTestCase;
use StatusValue;
use Wikimedia\Message\ITextFormatter;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Messaging\CampaignsUserMailer
 */
class CampaignsUserMailerTest extends MediaWikiUnitTestCase {
	use MockAuthorityTrait;

	/**
	 * @param UserFactory|null $userFactory
	 * @param JobQueueGroup|null $jobQueueGroup
	 * @param array $optionsOverrides
	 * @param CampaignsCentralUserLookup|null $centralUserLookup
	 * @param UserOptionsLookup|null $userOptionsLookup
	 * @param EmailUserFactory|null $emailUserFactory
	 * @return CampaignsUserMailer
	 */
	private function getCampaignsUserMailer(
		?UserFactory $userFactory = null,
		?JobQueueGroup $jobQueueGroup = null,
		array $optionsOverrides = [],
		?CampaignsCentralUserLookup $centralUserLookup = null,
		?UserOptionsLookup $userOptionsLookup = null,
		?EmailUserFactory $emailUserFactory = null
	): CampaignsUserMailer {
		$serviceOptions = new ServiceOptions(
			CampaignsUserMailer::CONSTRUCTOR_OPTIONS,
			$optionsOverrides + [
				MainConfigNames::PasswordSender => 'passwordsender@example.org',
				MainConfigNames::EnableEmail => true,
				MainConfigNames::EnableUserEmail => true,
				MainConfigNames::UserEmailUseReplyTo => false,
				MainConfigNames::EnableSpecialMute => false,
			]
		);
		return new CampaignsUserMailer(
			$userFactory ?? $this->createMock( UserFactory::class ),
			$jobQueueGroup ?? $this->createMock( JobQueueGroup::class ),
			$serviceOptions,
			$this->createMock( CentralIdLookup::class ),
			$centralUserLookup ?? $this->createMock( CampaignsCentralUserLookup::class ),
			$userOptionsLookup ?? $this->createMock( UserOptionsLookup::class ),
			$this->createMock( ITextFormatter::class ),
			$this->createMock( PageURLResolver::class ),
			$emailUserFactory ?? $this->createMock( EmailUserFactory::class ),
		);
	}

	/**
	 * @param User $target
	 * @param string|null $expected
	 * @covers ::validateTarget
	 * @dataProvider provideValidateTarget
	 */
	public function testValidateTarget(
		User $target,
		string $expected
	) {
		$performer = $this->createMock( User::class );
		$this->assertSame(
			$expected,
			$this->getCampaignsUserMailer()->validateTarget( $target, $performer )
		);
	}

	public function provideValidateTarget(): Generator {
		$target1 = $this->createMock( User::class );
		$target2 = $this->createMock( User::class );
		$target3 = $this->createMock( User::class );

		yield 'no id' => [
			$target1,
			"notarget"
		];
		$target2->method( "getId" )->willReturn( 1 );
		yield 'email not confirmed' => [
			$target2,
			"noemail"
		];
		$target3->method( "getId" )->willReturn( 1 );
		$target3->method( "isEmailConfirmed" )->willReturn( true );
		yield 'no email' => [
			$target3,
			"nowikiemail"
		];
	}

	/**
	 * @covers ::sendEmail
	 * @covers ::createEmailJob
	 * @dataProvider provideTestSendEmail
	 */
	public function testSendEmail( bool $useReplyTo ) {
		$jobQueueGroupSpy = $this->createMock( JobQueueGroup::class );
		$jobQueueGroupSpy->expects( $this->once() )
			->method( 'push' )
			->willReturnCallback( function ( array $jobs ) {
				$this->assertCount( 1, $jobs );
				$this->assertInstanceOf( EmailUsersJob::class, $jobs[0] );
			} );

		$recipientCentralID = 42;
		$recipientName = 'The Recipient';
		$recipients = [ new Participant(
			new CentralUser( $recipientCentralID ),
			'20200101000000',
			100,
			false,
			[],
			null,
			null
		) ];
		$centralUserLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$centralUserLookup->method( 'getNames' )
			->with( [ $recipientCentralID => null ] )
			->willReturn( [ $recipientCentralID => $recipientName ] );

		$performer = $this->mockRegisteredUltimateAuthority();
		$performerUser = $this->createMock( User::class );
		$performerUser->method( 'pingLimiter' )->willReturn( false );
		$performerUser->method( 'getEmail' )->willReturn( 'sender@example.org' );

		$recipientUser = $this->createMock( User::class );
		$recipientUser->method( 'getId' )->willReturn( 1000 );
		$recipientUser->method( 'isEmailConfirmed' )->willReturn( true );
		$recipientUser->method( 'canReceiveEmail' )->willReturn( true );
		$recipientUser->method( 'getEmail' )->willReturn( 'recipient@example.org' );

		$userFactory = $this->createMock( UserFactory::class );
		$userFactory->method( 'newFromAuthority' )->with( $performer )->willReturn( $performerUser );
		$userFactory->method( 'newFromName' )->with( $recipientName )->willReturn( $recipientUser );

		$emailUser = $this->createMock( EmailUser::class );
		$emailUser->method( 'canSend' )->willReturn( StatusValue::newGood() );
		$emailUserFactory = $this->createMock( EmailUserFactory::class );
		$emailUserFactory->method( 'newEmailUser' )->with( $performerUser )->willReturn( $emailUser );

		$userMailer = $this->getCampaignsUserMailer(
			$userFactory,
			$jobQueueGroupSpy,
			[ MainConfigNames::UserEmailUseReplyTo => $useReplyTo ],
			$centralUserLookup,
			null,
			$emailUserFactory,
		);
		$status = $userMailer->sendEmail(
			$performer,
			$recipients,
			'Some subject',
			'Some message',
			$this->createMock( ExistingEventRegistration::class )
		);
		$this->assertStatusGood( $status );
		$this->assertStatusValue( 1, $status );
	}

	public function provideTestSendEmail(): array {
		return [
			'With Reply-To' => [ true ],
			'Without Reply-To' => [ false ],
		];
	}
}
