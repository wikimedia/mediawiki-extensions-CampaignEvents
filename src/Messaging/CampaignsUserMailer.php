<?php
declare( strict_types=1 );
namespace MediaWiki\Extension\CampaignEvents\Messaging;

use JobQueueGroup;
use MailAddress;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageURLResolver;
use MediaWiki\Extension\CampaignEvents\Participants\Participant;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Special\SpecialAllEvents;
use MediaWiki\Mail\EmailUserFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\Permissions\Authority;
use MediaWiki\Preferences\MultiUsernameFilter;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\CentralId\CentralIdLookup;
use MediaWiki\User\Options\UserOptionsLookup;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use StatusValue;
use Wikimedia\Message\ITextFormatter;
use Wikimedia\Message\MessageValue;

/**
 * This class uses a lot of MW classes as the core email code is not ideal and there aren't many alternatives.
 * All of this should be refactored in future if possible.
 */
class CampaignsUserMailer {

	public const SERVICE_NAME = 'CampaignEventsUserMailer';
	public const CONSTRUCTOR_OPTIONS = [
		MainConfigNames::PasswordSender,
		MainConfigNames::EnableEmail,
		MainConfigNames::EnableUserEmail,
		MainConfigNames::UserEmailUseReplyTo,
		MainConfigNames::EnableSpecialMute,
	];

	private UserFactory $userFactory;
	private JobQueueGroup $jobQueueGroup;
	private ServiceOptions $options;
	private CentralIdLookup $centralIdLookup;
	private CampaignsCentralUserLookup $centralUserLookup;
	private UserOptionsLookup $userOptionsLookup;
	private ITextFormatter $contLangMsgFormatter;
	private PageURLResolver $pageURLResolver;
	private EmailUserFactory $emailUserFactory;

	/**
	 * @param UserFactory $userFactory
	 * @param JobQueueGroup $jobQueueGroup
	 * @param ServiceOptions $options
	 * @param CentralIdLookup $centralIdLookup
	 * @param CampaignsCentralUserLookup $centralUserLookup
	 * @param UserOptionsLookup $userOptionsLookup
	 * @param ITextFormatter $contLangMsgFormatter
	 * @param PageURLResolver $pageURLResolver
	 * @param EmailUserFactory $emailUserFactory
	 */
	public function __construct(
		UserFactory $userFactory,
		JobQueueGroup $jobQueueGroup,
		ServiceOptions $options,
		CentralIdLookup $centralIdLookup,
		CampaignsCentralUserLookup $centralUserLookup,
		UserOptionsLookup $userOptionsLookup,
		ITextFormatter $contLangMsgFormatter,
		PageURLResolver $pageURLResolver,
		EmailUserFactory $emailUserFactory
	) {
		$this->userFactory = $userFactory;
		$this->jobQueueGroup = $jobQueueGroup;
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->options = $options;
		$this->centralIdLookup = $centralIdLookup;
		$this->centralUserLookup = $centralUserLookup;
		$this->userOptionsLookup = $userOptionsLookup;
		$this->contLangMsgFormatter = $contLangMsgFormatter;
		$this->pageURLResolver = $pageURLResolver;
		$this->emailUserFactory = $emailUserFactory;
	}

	/**
	 * @param Authority $performer
	 * @param Participant[] $participants
	 * @param string $subject
	 * @param string $message
	 * @param ExistingEventRegistration $event
	 * @return StatusValue
	 */
	public function sendEmail(
		Authority $performer,
		array $participants,
		string $subject,
		string $message,
		ExistingEventRegistration $event
	): StatusValue {
		$centralIdsMap = [];
		foreach ( $participants as $participant ) {
			$centralIdsMap[$participant->getUser()->getCentralID()] = null;
		}

		$recipients = $this->centralUserLookup->getNames( $centralIdsMap );

		$validSend = 0;
		$performerUser = $this->userFactory->newFromAuthority( $performer );
		$status = $this->validateSender( $performerUser );
		if ( !$status->isGood() ) {
			return $status;
		}
		$jobs = [];
		foreach ( $recipients as $recipientName ) {
			$recipientUser = $this->userFactory->newFromName( $recipientName );
			if ( $recipientUser === null ) {
				continue;
			}
			$validTarget = $this->validateTarget( $recipientUser, $performerUser );
			if ( $validTarget === null ) {
				$validSend++;
				$recipientAddress = MailAddress::newFromUser( $recipientUser );
				$performerAddress = MailAddress::newFromUser( $performerUser );
				$curMessage = $this->getMessageWithFooter( $message, $performerAddress, $recipientAddress, $event );
				// @phan-suppress-next-line SecurityCheck-XSS Gets confused by HTML and text body being passed together
				$jobs[] = $this->createEmailJob( $recipientAddress, $subject, $curMessage, $performerAddress );
			}
		}
		$this->jobQueueGroup->push( $jobs );

		return StatusValue::newGood( $validSend );
	}

	/**
	 * Add a predefined footer to the email body, similar to EmailUser::sendEmailUnsafe().
	 * @todo It might make sense to move this to the job, for performance. However, it should wait until
	 * T339821 is ready, as that will give us a better holistic view of how to refactor this code.
	 *
	 * @param string $body
	 * @param MailAddress $from
	 * @param MailAddress $to
	 * @param ExistingEventRegistration $event
	 * @return string
	 */
	private function getMessageWithFooter(
		string $body,
		MailAddress $from,
		MailAddress $to,
		ExistingEventRegistration $event
	): string {
		$body = rtrim( $body ) . "\n\n-- \n";
		$eventPageURL = $this->pageURLResolver->getCanonicalUrl( $event->getPage() );
		$body .= $this->contLangMsgFormatter->format(
			MessageValue::new( 'campaignevents-email-footer', [ $from->name, $to->name, $eventPageURL ] )
		);
		if ( $this->options->get( MainConfigNames::EnableSpecialMute ) ) {
			$body .= "\n" . $this->contLangMsgFormatter->format(
				MessageValue::new(
					'specialmute-email-footer',
					[
						SpecialPage::getTitleFor( 'Mute', $from->name )->getCanonicalURL(),
						$from->name
					]
				)
			);
		}
		if ( defined( 'MW_PHPUNIT_TEST' ) ) {
			$collaborationListLink = '(Collaboration list link)';
		} else {
			$collaborationListLink = "\n\n" . $this->contLangMsgFormatter->format(
					MessageValue::new(
						'campaignevents-email-footer-collaboration-list-link',
						[
							SpecialPage::getTitleFor( SpecialAllEvents::PAGE_NAME )->getCanonicalURL()
						]
					)
				);
		}
		$body .= $collaborationListLink;

		return $body;
	}

	/**
	 * @param MailAddress $to
	 * @param string $subject
	 * @param string $message
	 * @param MailAddress $from
	 * @return EmailUsersJob
	 */
	private function createEmailJob(
		MailAddress $to,
		string $subject,
		string $message,
		MailAddress $from
	): EmailUsersJob {
		[ $mailFrom, $replyTo ] = $this->getFromAndReplyTo( $from );

		// TODO: This could be improved by making MailAddress JSON-serializable, see T346406
		$toComponents = [ $to->address, $to->name, $to->realName ];
		$fromComponents = [ $mailFrom->address, $mailFrom->name, $mailFrom->realName ];
		$replyToComponents = $replyTo ? [ $replyTo->address, $replyTo->name, $replyTo->realName ] : null;

		$params = [
			'to' => $toComponents,
			'from' => $fromComponents,
			'subject' => $subject,
			'message' => $message,
			'replyTo' => $replyToComponents
		];

		return new EmailUsersJob(
			'sendCampaignEmail',
			$params
		);
	}

	/**
	 * Validate target User
	 * This code was copied from core EmailUser::validateTarget
	 * @param User $target Target user
	 * @param User $sender User sending the email
	 * @return null|string Null on success, string on error.
	 */
	public function validateTarget( User $target, User $sender ): ?string {
		if ( !$target->getId() ) {
			wfDebug( "Target is invalid user." );

			return 'notarget';
		}

		if ( !$target->isEmailConfirmed() ) {
			wfDebug( "User has no valid email." );

			return 'noemail';
		}

		if ( !$target->canReceiveEmail() ) {
			wfDebug( "User does not allow user emails." );

			return 'nowikiemail';
		}

		if ( !$this->userOptionsLookup->getOption(
				$target,
				'email-allow-new-users'
			) && $sender->isNewbie()
		) {
			wfDebug( "User does not allow user emails from new users." );

			return 'nowikiemail';
		}

		$muteList = $this->userOptionsLookup->getOption(
			$target,
			'email-blacklist',
			''
		);
		if ( $muteList ) {
			$muteList = MultiUsernameFilter::splitIds( $muteList );
			$senderId = $this->centralIdLookup->centralIdFromLocalUser( $sender );
			if ( $senderId !== 0 && in_array( $senderId, $muteList, true ) ) {
				wfDebug( "User does not allow user emails from this user." );

				return 'nowikiemail';
			}
		}

		return null;
	}

	/**
	 * Check whether a user is allowed to send email
	 * @param User $user
	 * @return StatusValue status indicating whether the user can send an email or not
	 */
	private function validateSender( User $user ): StatusValue {
		$status = $this->emailUserFactory
			->newEmailUser( $user )
			->canSend();
		if ( !$status->isGood() ) {
			return $status;
		}

		if ( $user->pingLimiter( PermissionChecker::SEND_EVENTS_EMAIL_RIGHT ) ) {
			wfDebug( "Ping limiter triggered." );

			return StatusValue::newFatal( 'actionthrottledtext' );
		}

		return StatusValue::newGood();
	}

	/**
	 * @param MailAddress $fromAddress
	 * @return array<MailAddress|null>
	 * @phan-return array{0:MailAddress,1:?MailAddress}
	 */
	private function getFromAndReplyTo( MailAddress $fromAddress ): array {
		if ( $this->options->get( MainConfigNames::UserEmailUseReplyTo ) ) {
			/**
			 * Put the generic wiki autogenerated address in the From:
			 * header and reserve the user for Reply-To.
			 *
			 * This is a bit ugly, but will serve to differentiate
			 * wiki-borne mails from direct mails and protects against
			 * SPF and bounce problems with some mailers (see below).
			 */
			if ( defined( 'MW_PHPUNIT_TEST' ) ) {
				$emailSenderName = '(emailsender)';
			} else {
				$emailSenderName = RequestContext::getMain()->msg( 'emailsender' )->inContentLanguage()->text();
			}
			$mailFrom = new MailAddress(
				$this->options->get( MainConfigNames::PasswordSender ),
				$emailSenderName
			);
			$replyTo = $fromAddress;
		} else {
			/**
			 * Put the sending user's e-mail address in the From: header.
			 *
			 * This is clean-looking and convenient, but has issues.
			 * One is that it doesn't as clearly differentiate the wiki mail
			 * from "directly" sent mails.
			 *
			 * Another is that some mailers (like sSMTP) will use the From
			 * address as the envelope sender as well. For open sites this
			 * can cause mails to be flunked for SPF violations (since the
			 * wiki server isn't an authorized sender for various users'
			 * domains) as well as creating a privacy issue as bounces
			 * containing the recipient's e-mail address may get sent to
			 * the sending user.
			 */
			$mailFrom = $fromAddress;
			$replyTo = null;
		}
		return [ $mailFrom, $replyTo ];
	}
}
