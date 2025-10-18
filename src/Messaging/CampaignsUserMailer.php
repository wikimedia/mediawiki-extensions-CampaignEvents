<?php
declare( strict_types=1 );
namespace MediaWiki\Extension\CampaignEvents\Messaging;

use MailAddress;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageURLResolver;
use MediaWiki\Extension\CampaignEvents\Participants\Participant;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Special\SpecialAllEvents;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\Mail\EmailUserFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\Permissions\Authority;
use MediaWiki\Preferences\MultiUsernameFilter;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\CentralId\CentralIdLookup;
use MediaWiki\User\Options\UserOptionsLookup;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MessageLocalizer;
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

	public function __construct(
		private readonly UserFactory $userFactory,
		private readonly JobQueueGroup $jobQueueGroup,
		private readonly ServiceOptions $options,
		private readonly CentralIdLookup $centralIdLookup,
		private readonly CampaignsCentralUserLookup $centralUserLookup,
		private readonly UserOptionsLookup $userOptionsLookup,
		private readonly ITextFormatter $contLangMsgFormatter,
		private readonly PageURLResolver $pageURLResolver,
		private readonly EmailUserFactory $emailUserFactory,
		private readonly MessageLocalizer $msgLocalizer,
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	/**
	 * @param Authority $performer
	 * @param Participant[] $participants
	 * @param string $subject
	 * @param string $message
	 * @param bool $CCMe Whether to send a copy of the message to $performer
	 * @param ExistingEventRegistration $event
	 */
	public function sendEmail(
		Authority $performer,
		array $participants,
		string $subject,
		string $message,
		bool $CCMe,
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

		$performerAddress = MailAddress::newFromUser( $performerUser );
		$jobs = [];
		$selfEmailJob = null;
		foreach ( $recipients as $recipientName ) {
			$recipientUser = $this->userFactory->newFromName( $recipientName );
			if ( $recipientUser === null ) {
				continue;
			}
			$validTarget = $this->validateTarget( $recipientUser, $performerUser );
			if ( $validTarget !== null ) {
				continue;
			}

			$recipientAddress = MailAddress::newFromUser( $recipientUser );
			$curMessage = $this->getMessageWithFooter( $message, $performerAddress, $recipientAddress, $event );
			// @phan-suppress-next-line SecurityCheck-XSS Gets confused by HTML and text body being passed together
			$curEmailJob = $this->createEmailJob( $recipientAddress, $subject, $curMessage, $performerAddress );

			if ( $recipientUser->equals( $performerUser ) ) {
				// If they're sending themself an email, leave it aside until every other recipient has been processed,
				// so we can decide whether to send it as a copy (if CCMe was chosen and there's at least one other
				// valid recipient) or not.
				$selfEmailJob = $curEmailJob;
			} else {
				$validSend++;
				$jobs[] = $curEmailJob;
			}
		}

		if ( $selfEmailJob && ( $validSend === 0 || !$CCMe ) ) {
			$jobs[] = $selfEmailJob;
			$validSend++;
		} elseif ( $CCMe && $validSend > 0 ) {
			$selfSubject = $this->msgLocalizer->msg( 'campaignevents-email-self-subject' )
				->params( $event->getName() )
				->text();
			$selfMessage = $this->getMessageWithFooter( $message, $performerAddress, $performerAddress, $event );
			// @phan-suppress-next-line SecurityCheck-XSS As above
			$jobs[] = $this->createEmailJob( $performerAddress, $selfSubject, $selfMessage, $performerAddress );
		}

		$this->jobQueueGroup->push( $jobs );

		return StatusValue::newGood( $validSend );
	}

	/**
	 * Add a predefined footer to the email body, similar to EmailUser::sendEmailUnsafe().
	 * @todo It might make sense to move this to the job, for performance. However, it should wait until
	 * T339821 is ready, as that will give us a better holistic view of how to refactor this code.
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
