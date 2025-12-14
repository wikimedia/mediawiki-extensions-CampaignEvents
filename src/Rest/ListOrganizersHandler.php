<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUserNotFoundException;
use MediaWiki\Extension\CampaignEvents\MWEntity\HiddenCentralUserException;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Organizers\RoleFormatter;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use Wikimedia\ParamValidator\ParamValidator;

class ListOrganizersHandler extends SimpleHandler {
	use EventIDParamTrait;

	// TODO: Implement proper pagination (T305389)
	private const RES_LIMIT = 11;

	public function __construct(
		private readonly IEventLookup $eventLookup,
		private readonly OrganizersStore $organizersStore,
		private readonly RoleFormatter $roleFormatter,
		private readonly CampaignsCentralUserLookup $centralUserLookup,
		private readonly UserLinker $userLinker,
	) {
	}

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
			try {
				$userName = $this->centralUserLookup->getUserName( $user );
			} catch ( CentralUserNotFoundException | HiddenCentralUserException ) {
				continue;
			}
			$respVal[] = [
				'organizer_id' => $organizer->getOrganizerID(),
				'user_id' => $user->getCentralID(),
				'user_name' => $userName,
				// TODO Should these be localized? It doesn't seem possible right now anyway (T269492)
				'roles' => array_map( [ $this->roleFormatter, 'getDebugName' ], $organizer->getRoles() ),
				'user_page' => $this->userLinker->getUserPagePath( $user ),
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
