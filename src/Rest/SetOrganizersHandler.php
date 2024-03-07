<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Event\EditEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWAuthorityProxy;
use MediaWiki\ParamValidator\TypeDef\UserDef;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Rest\TokenAwareHandlerTrait;
use MediaWiki\Rest\Validator\BodyValidator;
use MediaWiki\Rest\Validator\JsonBodyValidator;
use MediaWiki\Rest\Validator\UnsupportedContentTypeBodyValidator;
use MediaWiki\Rest\Validator\Validator;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

class SetOrganizersHandler extends SimpleHandler {
	use EventIDParamTrait;
	use FailStatusUtilTrait;
	use TokenAwareHandlerTrait;

	private IEventLookup $eventLookup;
	private EditEventCommand $editEventCommand;
	private CampaignsCentralUserLookup $centralUserLookup;

	/**
	 * @param IEventLookup $eventLookup
	 * @param EditEventCommand $editEventCommand
	 * @param CampaignsCentralUserLookup $centralUserLookup
	 */
	public function __construct(
		IEventLookup $eventLookup,
		EditEventCommand $editEventCommand,
		CampaignsCentralUserLookup $centralUserLookup
	) {
		$this->eventLookup = $eventLookup;
		$this->editEventCommand = $editEventCommand;
		$this->centralUserLookup = $centralUserLookup;
	}

	/**
	 * @inheritDoc
	 */
	public function validate( Validator $restValidator ): void {
		parent::validate( $restValidator );
		$this->validateToken();
		// XXX JsonBodyValidator does not validate parameters, see T305973
		$organizerNames = $this->getValidatedBody()['organizer_usernames'] ?? [];
		foreach ( $organizerNames as $name ) {
			if ( !$this->centralUserLookup->isValidLocalUsername( $name ) ) {
				throw new LocalizedHttpException(
					MessageValue::new( 'paramvalidator-baduser' )->plaintextParams( 'organizer_usernames', $name ),
					400
				);
			}
		}
	}

	/**
	 * @param int $eventID
	 * @return Response
	 */
	protected function run( int $eventID ): Response {
		$event = $this->getRegistrationOrThrow( $this->eventLookup, $eventID );

		$body = $this->getValidatedBody() ?? [];
		$organizers = $body['organizer_usernames'];
		if ( !is_array( $organizers ) || !$organizers ) {
			throw new LocalizedHttpException(
				MessageValue::new( 'campaignevents-rest-set-organizers-empty-list' ),
				400
			);
		}

		$performer = new MWAuthorityProxy( $this->getAuthority() );
		$saveStatus = $this->editEventCommand->doEditIfAllowed( $event, $performer, $organizers );
		// Note that no warnings (e.g., from tracking tools) are expected here
		if ( !$saveStatus->isGood() ) {
			$httptStatus = $saveStatus instanceof PermissionStatus ? 403 : 400;
			$this->exitWithStatus( $saveStatus, $httptStatus );
		}

		return $this->getResponseFactory()->createNoContent();
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
	public function getBodyValidator( $contentType ): BodyValidator {
		if ( $contentType !== 'application/json' ) {
			return new UnsupportedContentTypeBodyValidator( $contentType );
		}

		return new JsonBodyValidator( [
			'organizer_usernames' => [
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_TYPE => 'user',
				UserDef::PARAM_ALLOWED_USER_TYPES => [ 'name' ],
				static::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_ISMULTI => true,
				ParamValidator::PARAM_DEFAULT => [],
			],
		] + $this->getTokenParamDefinition() );
	}

}
