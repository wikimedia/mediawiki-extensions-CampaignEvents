<?php
declare( strict_types=1 );
namespace MediaWiki\Extension\CampaignEvents\Messaging;

use JobQueueGroup;
use MailAddress;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\Participants\Participant;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Permissions\Authority;
use MediaWiki\Preferences\MultiUsernameFilter;
use MediaWiki\User\Options\UserOptionsLookup;
use MediaWiki\User\UserFactory;
use RequestContext;
use StatusValue;
use User;

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
		MainConfigNames::UserEmailUseReplyTo
	];

	/** @var UserFactory */
	private UserFactory $userFactory;
	/** @var JobQueueGroup */
	private JobQueueGroup $jobQueueGroup;
	/** @var ServiceOptions */
	private ServiceOptions $options;
	/** @var CampaignsCentralUserLookup */
	private CampaignsCentralUserLookup $centralUserLookup;
	/** @var UserOptionsLookup */
	private UserOptionsLookup $userOptionsLookup;

	/**
	 * @param UserFactory $userFactory
	 * @param JobQueueGroup $jobQueueGroup
	 * @param ServiceOptions $options
	 * @param CampaignsCentralUserLookup $centralUserLookup
	 * @param UserOptionsLookup $userOptionsLookup
	 */
	public function __construct(
		UserFactory $userFactory,
		JobQueueGroup $jobQueueGroup,
		ServiceOptions $options,
		CampaignsCentralUserLookup $centralUserLookup,
		UserOptionsLookup $userOptionsLookup
	) {
		$this->userFactory = $userFactory;
		$this->jobQueueGroup = $jobQueueGroup;
		$this->centralUserLookup = $centralUserLookup;
		$this->userOptionsLookup = $userOptionsLookup;
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->options = $options;
	}

	/**
	 * @param Authority $performer
	 * @param Participant[] $participants
	 * @param string $subject
	 * @param string $message
	 * @return StatusValue
	 */
	public function sendEmail(
		Authority $performer,
		array $participants,
		string $subject,
		string $message
	): StatusValue {
		$centralIdsMap = [];
		foreach ( $participants as $participant ) {
			$centralIdsMap[$participant->getUser()->getCentralID()] = null;
		}

		$recipients = $this->centralUserLookup->getNames( $centralIdsMap );

		$validSend = 0;
		$performerUser = $this->userFactory->newFromAuthority( $performer );
		$validSender = $this->validateSender( $performerUser );
		if ( $validSender !== null ) {
			// TODO: Use appropriate error messages when switching to core's EmailUser
			return StatusValue::newFatal( 'badaccess' );
		}
		$jobs = [];
		foreach ( $recipients as $recipient ) {
			$user = $this->userFactory->newFromName( $recipient );
			if ( $user === null ) {
				continue;
			}
			$validTarget = $this->validateTarget( $user, $performerUser );
			if ( $validTarget === null ) {
				$validSend++;
				$address = MailAddress::newFromUser( $user );
				$performerAddress = MailAddress::newFromUser( $performerUser );
				$jobs[] = $this->createEmailJob( $address, $subject, $message, $performerAddress );
			}
		}
		$this->jobQueueGroup->push( $jobs );

		return StatusValue::newGood( $validSend );
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
			$senderId = MediaWikiServices::getInstance()
				->getCentralIdLookup()
				->centralIdFromLocalUser( $sender );
			if ( $senderId !== 0 && in_array( $senderId, $muteList, true ) ) {
				wfDebug( "User does not allow user emails from this user." );

				return 'nowikiemail';
			}
		}

		return null;
	}

	/**
	 * Check whether a user is allowed to send email
	 * This code was copied from EmailUser::getPermissionsError
	 * @param User $user
	 * @return null|string Null on success, string on error.
	 */
	private function validateSender( User $user ): ?string {
		if ( !$user->canSendEmail() ) {
			return 'badaccess';
		}

		if ( $user->isBlockedFromEmailuser() ) {
			wfDebug( "User is blocked from sending e-mail." );

			return "blockedemailuser";
		}

		if ( $user->pingLimiter( PermissionChecker::SEND_EVENTS_EMAIL_RIGHT ) ) {
			wfDebug( "Ping limiter triggered." );

			return 'actionthrottledtext';
		}
		return null;
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
