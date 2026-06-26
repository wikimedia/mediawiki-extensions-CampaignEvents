<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use Exception;
use MediaWiki\Language\LanguageFactory;
use MediaWiki\Language\LanguageNameUtils;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Helper endpoint to format date strings on the client side with all the features / formats supported by
 * {@see Language::sprintfDate}.
 *
 * @internal For use by this extension only.
 */
class GetFormattedTimeHandler extends SimpleHandler {
	public function __construct(
		private readonly LanguageFactory $languageFactory,
		private readonly LanguageNameUtils $languageNameUtils,
	) {
	}

	protected function run( string $languageCode, string $startTS, string $endTS ): Response {
		$language = $this->languageFactory->getLanguage( $languageCode );
		$user = $this->getAuthority()->getUser();
		// Time correction is applied in JavaScript.
		$options = [ 'timecorrection' => false ];

		try {
			return $this->getResponseFactory()->createJson( [
				'startTime' => $language->userTime( $startTS, $user, $options ),
				'startDate' => $language->userDate( $startTS, $user, $options ),
				'startDateTime' => $language->userTimeAndDate( $startTS, $user, $options ),
				'endTime' => $language->userTime( $endTS, $user, $options ),
				'endDate' => $language->userDate( $endTS, $user, $options ),
				'endDateTime' => $language->userTimeAndDate( $endTS, $user, $options ),
			] );
		} catch ( Exception $e ) {
			// Probably an invalid timestamp. The Language::user* methods don't give us a narrow exception class
			// to catch, so just catch everything. No need to localise errors as the module is internal, and errors
			// are just ignored on the client side.
			throw new HttpException(
				"Invalid input timestamp ($startTS or $endTS): {$e->getMessage()}",
				400
			);
		}
	}

	/** @inheritDoc */
	protected function getResponseBodySchemaFileName( string $method ): ?string {
		return __DIR__ . '/Schema/FormattedTime.json';
	}

	public function getParamSettings(): array {
		return [
			'languageCode' => [
				Handler::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => array_keys( $this->languageNameUtils->getLanguageNames() ),
				ParamValidator::PARAM_REQUIRED => true,
				Handler::PARAM_DESCRIPTION => new MessageValue( 'campaignevents-rest-param-desc-language-code' ),
			],
			'start' => [
				Handler::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
				Handler::PARAM_DESCRIPTION => new MessageValue( 'campaignevents-rest-param-desc-start-timestamp' ),
			],
			'end' => [
				Handler::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
				Handler::PARAM_DESCRIPTION => new MessageValue( 'campaignevents-rest-param-desc-end-timestamp' ),
			],
		];
	}
}
