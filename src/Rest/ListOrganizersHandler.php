<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Organizers\RoleFormatter;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;

class ListOrganizersHandler extends SimpleHandler {
	use EventIDParamTrait;

	// TODO: Implement proper pagination (T305389)
	private const RES_LIMIT = 50;

	/** @var IEventLookup */
	private $eventLookup;
	/** @var OrganizersStore */
	private $organizersStore;
	/** @var RoleFormatter */
	private $roleFormatter;

	/**
	 * @param IEventLookup $eventLookup
	 * @param OrganizersStore $organizersStore
	 * @param RoleFormatter $roleFormatter
	 */
	public function __construct(
		IEventLookup $eventLookup,
		OrganizersStore $organizersStore,
		RoleFormatter $roleFormatter
	) {
		$this->eventLookup = $eventLookup;
		$this->organizersStore = $organizersStore;
		$this->roleFormatter = $roleFormatter;
	}

	/**
	 * @param int $eventID
	 * @return Response
	 */
	protected function run( int $eventID ): Response {
		$this->getRegistrationOrThrow( $this->eventLookup, $eventID );

		$organizers = $this->organizersStore->getEventOrganizers( $eventID, self::RES_LIMIT );
		$respVal = [];
		foreach ( $organizers as $organizer ) {
			$respVal[] = [
				'user_id' => $organizer->getUser()->getCentralID(),
				// TODO Should these be localized? It doesn't seem possible right now anyway (T269492)
				'roles' => array_map( [ $this->roleFormatter, 'getDebugName' ], $organizer->getRoles() )
			];
		}
		return $this->getResponseFactory()->createJson( $respVal );
	}

	/**
	 * @inheritDoc
	 */
	public function getParamSettings(): array {
		return $this->getIDParamSetting();
	}
}
