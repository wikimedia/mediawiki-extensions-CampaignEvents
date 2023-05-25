<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\Messaging\CampaignsUserMailer;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUserNotFoundException;
use MediaWiki\Extension\CampaignEvents\MWEntity\HiddenCentralUserException;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWAuthorityProxy;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\User\UserFactory;
use RequestContext;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

class ListParticipantsHandler extends SimpleHandler {
	use EventIDParamTrait;
	use UserLinkTrait;

	// TODO: Implement proper pagination (T305389)
	private const RES_LIMIT = 20;

	/** @var PermissionChecker */
	private PermissionChecker $permissionChecker;
	/** @var IEventLookup */
	private $eventLookup;
	/** @var ParticipantsStore */
	private $participantsStore;
	/** @var CampaignsCentralUserLookup */
	private CampaignsCentralUserLookup $centralUserLookup;
	/** @var UserLinker */
	private UserLinker $userLinker;
	/** @var UserFactory */
	private UserFactory $userFactory;
	/** @var CampaignsUserMailer */
	private CampaignsUserMailer $campaignsUserMailer;

	/**
	 * @param PermissionChecker $permissionChecker
	 * @param IEventLookup $eventLookup
	 * @param ParticipantsStore $participantsStore
	 * @param CampaignsCentralUserLookup $centralUserLookup
	 * @param UserLinker $userLinker
	 * @param UserFactory $userFactory
	 * @param CampaignsUserMailer $campaignsUserMailer
	 */
	public function __construct(
		PermissionChecker $permissionChecker,
		IEventLookup $eventLookup,
		ParticipantsStore $participantsStore,
		CampaignsCentralUserLookup $centralUserLookup,
		UserLinker $userLinker,
		UserFactory $userFactory,
		CampaignsUserMailer $campaignsUserMailer
	) {
		$this->permissionChecker = $permissionChecker;
		$this->eventLookup = $eventLookup;
		$this->participantsStore = $participantsStore;
		$this->centralUserLookup = $centralUserLookup;
		$this->userLinker = $userLinker;
		$this->userFactory = $userFactory;
		$this->campaignsUserMailer = $campaignsUserMailer;
	}

	/**
	 * @param int $eventID
	 * @return Response
	 */
	protected function run( int $eventID ): Response {
		$this->getRegistrationOrThrow( $this->eventLookup, $eventID );

		$params = $this->getValidatedParams();
		$usernameFilter = $params['username_filter'];
		if ( $usernameFilter === '' ) {
			throw new LocalizedHttpException(
				new MessageValue( 'campaignevents-rest-list-participants-empty-filter' ),
				400
			);
		}

		$includePrivate = $params['include_private'];
		$authority = $this->getAuthority();
		if (
			$includePrivate &&
			!$this->permissionChecker->userCanViewPrivateParticipants( new MWAuthorityProxy( $authority ), $eventID )
		) {
			throw new LocalizedHttpException(
				new MessageValue( 'campaignevents-rest-list-participants-cannot-see-private' ),
				403
			);
		}

		$participants = $this->participantsStore->getEventParticipants(
			$eventID,
			self::RES_LIMIT,
			$params['last_participant_id'],
			$usernameFilter,
			null,
			$includePrivate,
			$params['exclude_users']
		);

		// TODO: remove global when T269492 is resolved

		$language = RequestContext::getMain()->getLanguage();
		$respVal = [];
		$performer = $this->userFactory->newFromUserIdentity(
			$authority->getUser()
		);
		foreach ( $participants as $participant ) {
			$centralUser = $participant->getUser();
			$curData = [
				'participant_id' => $participant->getParticipantID(),
				'user_id' => $centralUser->getCentralID(),
				'user_registered_at' => wfTimestamp( TS_MW, $participant->getRegisteredAt() ),
				'user_registered_at_formatted' => $language->userTimeAndDate(
					$participant->getRegisteredAt(),
					$authority->getUser()
				),
				'private' => $participant->isPrivateRegistration(),
			];

			try {
				$userName = $this->centralUserLookup->getUserName( $centralUser );
				$user = $this->userFactory->newFromName( $userName );
				$curData['user_name'] = $userName;
				$curData['user_page'] = $this->getUserPagePath( $this->userLinker,  $centralUser );
				$curData['user_is_valid_recipient'] =
					$user !== null && $this->campaignsUserMailer->validateTarget( $user, $performer ) === null;

			} catch ( CentralUserNotFoundException $_ ) {
				$curData['not_found'] = true;
			} catch ( HiddenCentralUserException $_ ) {
				$curData['hidden'] = true;
			}
			$respVal[] = $curData;
		}
		return $this->getResponseFactory()->createJson( $respVal );
	}

	/**
	 * @inheritDoc
	 */
	public function getParamSettings(): array {
		return array_merge(
			$this->getIDParamSetting(),
			[
				'include_private' => [
					static::PARAM_SOURCE => 'query',
					ParamValidator::PARAM_REQUIRED => true,
					ParamValidator::PARAM_TYPE => 'boolean'
				],
				'last_participant_id' => [
					static::PARAM_SOURCE => 'query',
					ParamValidator::PARAM_TYPE => 'integer'
				],
				'username_filter' => [
					static::PARAM_SOURCE => 'query',
					ParamValidator::PARAM_TYPE => 'string'
				],
				'exclude_users' => [
					static::PARAM_SOURCE => 'query',
					ParamValidator::PARAM_TYPE => 'integer',
					ParamValidator::PARAM_ISMULTI => true
				],
			]
		);
	}
}
