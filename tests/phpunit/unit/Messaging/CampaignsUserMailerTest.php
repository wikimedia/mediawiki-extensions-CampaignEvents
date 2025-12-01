<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Messaging;

use Generator;
use MailAddress;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Messaging\CampaignsUserMailer;
use MediaWiki\Extension\CampaignEvents\Messaging\EmailUsersJob;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageURLResolver;
use MediaWiki\Extension\CampaignEvents\Participants\Participant;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\Mail\EmailUser;
use MediaWiki\Mail\EmailUserFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\Tests\Unit\FakeQqxMessageLocalizer;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;
use MediaWiki\User\CentralId\CentralIdLookup;
use MediaWiki\User\Options\UserOptionsLookup;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWikiUnitTestCase;
use StatusValue;
use Wikimedia\Message\ITextFormatter;
use Wikimedia\TestingAccessWrapper;

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
			new FakeQqxMessageLocalizer()
		);
	}

	private function getValidSender(): User {
		$sender = $this->createMock( User::class );
		$sender->method( 'getId' )->willReturn( 99 );
		$sender->method( 'getName' )->willReturn( 'The Sender' );
		$sender->method( 'isEmailConfirmed' )->willReturn( true );
		$sender->method( 'canReceiveEmail' )->willReturn( true );
		$sender->method( 'pingLimiter' )->willReturn( false );
		$sender->method( 'getEmail' )->willReturn( 'sender@example.org' );
		$sender->method( 'equals' )
			->willReturnCallback( static fn ( User $other ) => $sender->getName() === $other->getName() );
		return $sender;
	}

	private function getValidRecipient(): User {
		$recipient = $this->createMock( User::class );
		$recipient->method( 'getId' )->willReturn( 1000 );
		$recipient->method( 'getName' )->willReturn( 'The Recipient' );
		$recipient->method( 'isEmailConfirmed' )->willReturn( true );
		$recipient->method( 'canReceiveEmail' )->willReturn( true );
		$recipient->method( 'getEmail' )->willReturn( 'recipient@example.org' );
		$recipient->method( 'equals' )
			->willReturnCallback( static fn ( User $other ) => $recipient->getName() === $other->getName() );
		return $recipient;
	}

	private function getEmailUserFactoryForValidSender( User $sender ): EmailUserFactory {
		$emailUser = $this->createMock( EmailUser::class );
		$emailUser->method( 'canSend' )->willReturn( StatusValue::newGood() );
		$emailUserFactory = $this->createMock( EmailUserFactory::class );
		$emailUserFactory->method( 'newEmailUser' )->with( $sender )->willReturn( $emailUser );
		return $emailUserFactory;
	}

	private function getParticipantWithCentralID( int $centralID ): Participant {
		return new Participant(
			new CentralUser( $centralID ),
			'20200101000000',
			100,
			false,
			[],
			null,
			null,
			false,
		);
	}

	/**
	 * @covers ::validateTarget
	 * @dataProvider provideValidateTarget
	 */
	public function testValidateTarget(
		int $userID,
		bool $isEmailConfirmed,
		string $email,
		string $expected
	) {
		$performer = $this->createMock( User::class );

		$target = $this->createMock( User::class );
		$target->method( 'getId' )->willReturn( $userID );
		$target->method( 'isEmailConfirmed' )->willReturn( $isEmailConfirmed );
		$target->method( 'getEmail' )->willReturn( $email );

		$this->assertSame(
			$expected,
			$this->getCampaignsUserMailer()->validateTarget( $target, $performer )
		);
	}

	public static function provideValidateTarget(): Generator {
		yield 'Anon' => [
			0,
			false,
			'',
			"notarget"
		];
		yield 'Email not confirmed' => [
			1,
			false,
			'',
			"noemail"
		];
		yield 'No email' => [
			2,
			true,
			'',
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

		$recipientUser = $this->getValidRecipient();
		$recipientCentralID = 42;
		$recipientName = $recipientUser->getName();
		$recipients = [ $this->getParticipantWithCentralID( $recipientCentralID ) ];
		$centralUserLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$centralUserLookup->method( 'getNames' )
			->with( [ $recipientCentralID => null ] )
			->willReturn( [ $recipientCentralID => $recipientName ] );

		$performer = $this->mockRegisteredUltimateAuthority();
		$performerUser = $this->getValidSender();

		$userFactory = $this->createMock( UserFactory::class );
		$userFactory->method( 'newFromAuthority' )->with( $performer )->willReturn( $performerUser );
		$userFactory->method( 'newFromName' )->with( $recipientName )->willReturn( $recipientUser );

		$userMailer = $this->getCampaignsUserMailer(
			$userFactory,
			$jobQueueGroupSpy,
			[ MainConfigNames::UserEmailUseReplyTo => $useReplyTo ],
			$centralUserLookup,
			null,
			$this->getEmailUserFactoryForValidSender( $performerUser ),
		);
		$status = $userMailer->sendEmail(
			$performer,
			$recipients,
			'Some subject',
			'Some message',
			false,
			$this->createMock( ExistingEventRegistration::class )
		);
		$this->assertStatusGood( $status );
		$this->assertStatusValue( 1, $status );
	}

	public static function provideTestSendEmail(): array {
		return [
			'With Reply-To' => [ true ],
			'Without Reply-To' => [ false ],
		];
	}

	/**
	 * @covers ::sendEmail
	 * @dataProvider provideSendEmail__ccme
	 */
	public function testSendEmail__ccme(
		bool $organizerInRecipients,
		bool $hasOtherRecipients,
		bool $CCme,
		bool $expectsMessage,
		bool $expectedMessageIsCopy
	) {
		$emailSubject = 'Email subject for ' . __METHOD__;

		$performer = $this->mockRegisteredUltimateAuthority();
		$performerUser = $this->getValidSender();
		$performerAddress = MailAddress::newFromUser( $performerUser );
		$otherUser = $this->getValidRecipient();

		$recipients = [];
		$expectedIDToNameMap = [];
		if ( $organizerInRecipients ) {
			$performerCentralID = 42;
			$expectedIDToNameMap[$performerCentralID] = $performerUser->getName();
			$recipients[] = $this->getParticipantWithCentralID( $performerCentralID );
		}
		if ( $hasOtherRecipients ) {
			$otherCentralID = 1000;
			$expectedIDToNameMap[$otherCentralID] = $otherUser->getName();
			$recipients[] = $this->getParticipantWithCentralID( $otherCentralID );
		}

		$centralUserLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$centralUserLookup->method( 'getNames' )
			->with( array_fill_keys( array_keys( $expectedIDToNameMap ), null ) )
			->willReturn( $expectedIDToNameMap );

		$userFactory = $this->createMock( UserFactory::class );
		$userFactory->method( 'newFromAuthority' )->with( $performer )->willReturn( $performerUser );
		$userFactory->method( 'newFromName' )->willReturnMap( [
			[ $performerUser->getName(), UserFactory::RIGOR_VALID, $performerUser ],
			[ $otherUser->getName(), UserFactory::RIGOR_VALID, $otherUser ],
		] );

		$jobChecker = function ( array $jobs ) use (
			$emailSubject, $performerAddress, $hasOtherRecipients, $expectsMessage, $expectedMessageIsCopy
		) {
			$expectedJobCount = (int)$hasOtherRecipients + (int)$expectsMessage;
			$this->assertCount( $expectedJobCount, $jobs, 'Number of enqueued jobs should match' );
			foreach ( $jobs as $job ) {
				$this->assertInstanceOf( EmailUsersJob::class, $job );
				$jobAccessWrapper = TestingAccessWrapper::newFromObject( $job );
				/** @var MailAddress $mailTo */
				$mailTo = $jobAccessWrapper->to;
				if ( !$mailTo->equals( $performerAddress ) ) {
					continue;
				}

				$subject = $jobAccessWrapper->subject;
				if ( $expectedMessageIsCopy ) {
					$this->assertStringContainsString( 'campaignevents-email-self-subject', $subject );
				} else {
					$this->assertSame( $emailSubject, $subject );
				}
			}
		};
		$jobQueueGroupSpy = $this->createMock( JobQueueGroup::class );
		$jobQueueGroupSpy->expects( $this->once() )
			->method( 'push' )
			->willReturnCallback( $jobChecker );

		$userMailer = $this->getCampaignsUserMailer(
			$userFactory,
			$jobQueueGroupSpy,
			[],
			$centralUserLookup,
			null,
			$this->getEmailUserFactoryForValidSender( $performerUser )
		);
		$status = $userMailer->sendEmail(
			$performer,
			$recipients,
			$emailSubject,
			'Some message',
			$CCme,
			$this->createMock( ExistingEventRegistration::class )
		);
		$this->assertStatusGood( $status );
		$expectedMessageCount = (int)$hasOtherRecipients + (int)( $expectsMessage && !$expectedMessageIsCopy );
		$this->assertStatusValue( $expectedMessageCount, $status );
	}

	public static function provideSendEmail__ccme(): Generator {
		yield 'Organizer in recipients, has other recipients, do not CC me' => [ true, true, false, true, false ];
		yield 'Organizer in recipients, has other recipients, CC me' => [ true, true, true, true, true ];

		yield 'Organizer in recipients, no other recipients, do not CC me' => [ true, false, false, true, false ];
		yield 'Organizer in recipients, no other recipients, CC me' => [ true, false, true, true, false ];

		yield 'Organizer not in recipients, has other recipients, do not CC me' => [ false, true, false, false, false ];
		yield 'Organizer not in recipients, has other recipients, CC me' => [ false, true, true, true, true ];

		yield 'Organizer not in recipients, no other recipients, do not CC me' => [ false, false, false, false, false ];
		yield 'Organizer not in recipients, no other recipients, CC me' => [ false, false, true, false, false ];
	}
}
