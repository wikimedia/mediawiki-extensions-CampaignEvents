<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Event\EditEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\EventFactory;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsUser;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Rest\Response;
use StatusValue;

class UpdateEventRegistrationHandler extends AbstractEditEventRegistrationHandler {
	use EventIDParamTrait;

	/** @var IEventLookup */
	private $eventLookup;

	/**
	 * @param EventFactory $eventFactory
	 * @param PermissionChecker $permissionChecker
	 * @param EditEventCommand $editEventCommand
	 * @param IEventLookup $eventLookup
	 */
	public function __construct(
		EventFactory $eventFactory,
		PermissionChecker $permissionChecker,
		EditEventCommand $editEventCommand,
		IEventLookup $eventLookup
	) {
		parent::__construct( $eventFactory, $permissionChecker, $editEventCommand );
		$this->eventLookup = $eventLookup;
	}

	/**
	 * @inheritDoc
	 */
	public function getParamSettings(): array {
		return $this->getIDParamSetting();
	}

	/**
	 * @inheritDoc
	 */
	protected function getSuccessResponse( StatusValue $saveStatus ): Response {
		return $this->getResponseFactory()->createNoContent();
	}

	/**
	 * @inheritDoc
	 */
	protected function getEventID(): int {
		$id = $this->getValidatedParams()['id'];
		$this->getRegistrationOrThrow( $this->eventLookup, $id );
		return $id;
	}

	/**
	 * @inheritDoc
	 */
	protected function checkPermissions( ICampaignsUser $user ): void {
		// TODO Determine if we need to do something here
	}
}
