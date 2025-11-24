<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Rest\TokenAwareHandlerTrait;
use MediaWiki\Rest\Validator\Validator;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

class DeleteEventContributionHandler extends SimpleHandler {
	use TokenAwareHandlerTrait;

	private EventContributionStore $store;
	private PermissionChecker $permissionChecker;
	private IEventLookup $eventLookup;

	public function __construct(
		EventContributionStore $store,
		PermissionChecker $permissionChecker,
		IEventLookup $eventLookup,
	) {
		$this->store = $store;
		$this->permissionChecker = $permissionChecker;
		$this->eventLookup = $eventLookup;
	}

	/** @inheritDoc */
	public function validate( Validator $restValidator ): void {
		parent::validate( $restValidator );
		$this->validateToken();
	}

	public function run( int $id ): Response {
		$contrib = $this->store->getByID( $id );
		if ( $contrib === null ) {
			throw new LocalizedHttpException( MessageValue::new( 'campaignevents-rest-contribution-not-found' ), 404 );
		}

		$event = $this->eventLookup->getEventByID( $contrib->getEventID() );
		$authority = $this->getAuthority();

		if ( !$this->permissionChecker->userCanDeleteContribution( $authority, $event, $contrib->getUserID() ) ) {
			throw new LocalizedHttpException(
				MessageValue::new( 'campaignevents-rest-delete-contribution-permission-denied' ),
				403
			);
		}

		$this->store->deleteByID( $id );
		return $this->getResponseFactory()->createNoContent();
	}

	/** @inheritDoc */
	public function getParamSettings(): array {
		return [
			'id' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}

	/** @inheritDoc */
	public function getBodyParamSettings(): array {
		return $this->getTokenParamDefinition();
	}
}
