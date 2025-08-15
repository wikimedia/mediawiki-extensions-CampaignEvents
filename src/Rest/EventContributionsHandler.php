<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionValidator;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Rest\TokenAwareHandlerTrait;
use MediaWiki\Rest\Validator\Validator;
use Wikimedia\ParamValidator\ParamValidator;

class EventContributionsHandler extends SimpleHandler {
	use TokenAwareHandlerTrait;
	use EventIDParamTrait;

	private EventContributionValidator $validator;
	private WikiLookup $wikiLookup;
	private IEventLookup $eventLookup;

	public function __construct(
		EventContributionValidator $validator,
		WikiLookup $wikiLookup,
		IEventLookup $eventLookup
	) {
		$this->validator = $validator;
		$this->wikiLookup = $wikiLookup;
		$this->eventLookup = $eventLookup;
	}

	public function validate( Validator $restValidator ): void {
		parent::validate( $restValidator );
		$this->validateToken();
	}

	/**
	 * @inheritDoc
	 */
	protected function run( int $eventID, string $wikiID, string $revisionID ): Response {
		$performer = $this->getAuthority();
		$event = $this->getRegistrationOrThrow( $this->eventLookup, $eventID );

		$this->validator->validateAndSchedule( $event, (int)$revisionID, $wikiID, $performer );

		$response = $this->getResponseFactory()->createJson( [] );
		$response->setStatus( 202 );
		return $response;
	}

	/**
	 * @inheritDoc
	 */
	public function getParamSettings(): array {
		return array_merge(
			$this->getIDParamSetting(),
			[
				'wiki' => [
					Handler::PARAM_SOURCE => 'path',
					ParamValidator::PARAM_TYPE => $this->getAllowedWikiIds(),
					ParamValidator::PARAM_REQUIRED => true,
				],
				'revid' => [
					Handler::PARAM_SOURCE => 'path',
					ParamValidator::PARAM_TYPE => 'integer',
					ParamValidator::PARAM_REQUIRED => true,
				],
			]
		);
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public function getBodyParamSettings(): array {
		return $this->getTokenParamDefinition();
	}

	/**
	 * Get the list of allowed wiki IDs from WikiLookup
	 *
	 * @return array<string>
	 */
	private function getAllowedWikiIds(): array {
		return $this->wikiLookup->getAllWikis();
	}

}
