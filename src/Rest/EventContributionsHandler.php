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
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

class EventContributionsHandler extends SimpleHandler {
	use TokenAwareHandlerTrait;
	use EventIDParamTrait;

	public function __construct(
		private readonly EventContributionValidator $validator,
		private readonly WikiLookup $wikiLookup,
		private readonly IEventLookup $eventLookup,
	) {
	}

	public function validate( Validator $restValidator ): void {
		parent::validate( $restValidator );
		$this->validateToken();
	}

	protected function run( int $eventID, string $wikiID, string $revisionID ): Response {
		$performer = $this->getAuthority();
		$event = $this->getRegistrationOrThrow( $this->eventLookup, $eventID );

		$modified = $this->validator->validateAndSchedule( $event, (int)$revisionID, $wikiID, $performer );

		$response = $this->getResponseFactory()->createJson( [ 'modified' => $modified ] );
		$response->setStatus( 202 );
		return $response;
	}

	/** @inheritDoc */
	protected function getResponseBodySchemaFileName( string $method ): ?string {
		return __DIR__ . '/Schema/ModifiedResult.json';
	}

	/**
	 * @inheritDoc
	 * @return array<string, mixed>
	 */
	protected function generateResponseSpec( string $method ): array {
		return [
			'202' => [
				'description' => 'Contribution association job accepted.',
				'content' => [
					'application/json' => [
						'schema' => $this->getResponseBodySchema( $method ) ?? [],
					],
				],
			],
			'default' => [ '$ref' => '#/components/responses/GenericErrorResponse' ],
		];
	}

	public function getParamSettings(): array {
		return array_merge(
			$this->getIDParamSetting(),
			[
				'wiki' => [
					Handler::PARAM_SOURCE => 'path',
					ParamValidator::PARAM_TYPE => $this->getAllowedWikiIds(),
					ParamValidator::PARAM_REQUIRED => true,
					Handler::PARAM_DESCRIPTION => new MessageValue( 'campaignevents-rest-param-desc-wiki' ),
				],
				'revid' => [
					Handler::PARAM_SOURCE => 'path',
					ParamValidator::PARAM_TYPE => 'integer',
					ParamValidator::PARAM_REQUIRED => true,
					Handler::PARAM_DESCRIPTION => new MessageValue( 'campaignevents-rest-param-desc-revid' ),
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
