<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Organizers\RoleFormatter;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use Wikimedia\ParamValidator\ParamValidator;

class ListOrganizersHandler extends SimpleHandler {
	use EventIDParamTrait;
	use UserLinkTrait;

	// TODO: Implement proper pagination (T305389)
	private const RES_LIMIT = 10;

	/** @var IEventLookup */
	private $eventLookup;
	/** @var OrganizersStore */
	private $organizersStore;
	/** @var RoleFormatter */
	private $roleFormatter;
	/** @var CampaignsCentralUserLookup */
	private $centralUserLookup;
	/** @var UserLinker */
	private $userLinker;

	/**
	 * @param IEventLookup $eventLookup
	 * @param OrganizersStore $organizersStore
	 * @param RoleFormatter $roleFormatter
	 * @param CampaignsCentralUserLookup $centralUserLookup
	 * @param UserLinker $userLinker
	 */
	public function __construct(
		IEventLookup $eventLookup,
		OrganizersStore $organizersStore,
		RoleFormatter $roleFormatter,
		CampaignsCentralUserLookup $centralUserLookup,
		UserLinker $userLinker
	) {
		$this->eventLookup = $eventLookup;
		$this->organizersStore = $organizersStore;
		$this->roleFormatter = $roleFormatter;
		$this->centralUserLookup = $centralUserLookup;
		$this->userLinker = $userLinker;
	}

	/**
	 * @param int $eventID
	 * @return Response
	 */
	protected function run( int $eventID ): Response {
		$this->getRegistrationOrThrow( $this->eventLookup, $eventID );

		$params = $this->getValidatedParams();
		$organizers = $this->organizersStore->getEventOrganizers(
			$eventID,
			self::RES_LIMIT,
			$params['last_organizer_id']
		);
		$respVal = [];
		foreach ( $organizers as $organizer ) {
			$user = $organizer->getUser();
			if ( !$this->centralUserLookup->existsAndIsVisible( $user ) ) {
				continue;
			}
			$respVal[] = [
				'organizer_id' => $organizer->getOrganizerID(),
				'user_id' => $user->getCentralID(),
				// TODO Should these be localized? It doesn't seem possible right now anyway (T269492)
				'roles' => array_map( [ $this->roleFormatter, 'getDebugName' ], $organizer->getRoles() ),
				'user_page' => $this->getUserPagePath( $this->userLinker,  $user ),
			];
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
				'last_organizer_id' => [
					static::PARAM_SOURCE => 'query',
					ParamValidator::PARAM_TYPE => 'integer'
				],
			]
		);
	}
}
