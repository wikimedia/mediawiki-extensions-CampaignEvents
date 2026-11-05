<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Config\Config;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Extension\CampaignEvents\Worklist\ArticleQualityLookup;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\User\UserFactory;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * GET endpoint giving the quality of any articles on one wiki.
 *
 * It feeds the editing signals on the articles in the reading lists of a user. The response is
 * the same as the one of GetWorklistPagesQualityHandler.
 *
 * The feature is experimental (T444493). Thus the endpoint does not check that the articles are
 * in a reading list of the user, and each title asked for can cost a model run. These limits
 * apply instead:
 * - The endpoint is gated on CampaignEventsEnableReadingListsCardElements. When the flag is off,
 *   the endpoint returns 404.
 * - Only a named user can call the endpoint. Anonymous users and temporary users get a 401
 *   response.
 * - Each title adds one to the RATE_LIMIT_ACTION rate limit. A caller that is over the limit gets
 *   a 429 response, and the model is not called.
 */
class GetPagesQualityHandler extends SimpleHandler {
	/** The key of the rate limit in $wgRateLimits. Each title asked for adds one. */
	public const RATE_LIMIT_ACTION = 'campaignevents-pages-quality';

	public function __construct(
		private readonly Config $config,
		private readonly ArticleQualityLookup $articleQualityLookup,
		private readonly WikiLookup $wikiLookup,
		private readonly UserFactory $userFactory,
	) {
	}

	protected function run(): Response {
		if ( !$this->config->get( 'CampaignEventsEnableReadingListsCardElements' ) ) {
			// When the flag is off, the endpoint looks as though it does not exist. Not localised:
			// the flag is temporary, as for GetWorklistPagesQualityHandler.
			throw new HttpException( 'The reading lists card elements are not enabled on this wiki', 404 );
		}

		$authority = $this->getAuthority();
		if ( !$authority->isNamed() ) {
			// Only named users can make the model run. Anonymous users and temporary users cannot.
			throw new LocalizedHttpException( MessageValue::new( 'rest-permission-denied-anon' ), 401 );
		}

		$params = $this->getValidatedParams();
		$wiki = $params['wiki'];
		$titles = $params['titles'];

		// Each title can cost a model run, thus count each title. The lookup removes duplicates,
		// thus duplicates do not count.
		$user = $this->userFactory->newFromAuthority( $authority );
		if ( $user->pingLimiter( self::RATE_LIMIT_ACTION, count( array_unique( $titles ) ) ) ) {
			throw new LocalizedHttpException(
				MessageValue::new( 'rest-rate-limit-exceeded', [ self::RATE_LIMIT_ACTION ] ),
				429
			);
		}

		$respVal = $this->articleQualityLookup->getQualityListForArticles( $wiki, $titles );

		return $this->getResponseFactory()->createJson( [ 'articles' => $respVal ] );
	}

	protected function getResponseBodySchemaFileName( string $method ): ?string {
		return __DIR__ . '/Schema/PageQuality.json';
	}

	public function getParamSettings(): array {
		return [
			'wiki' => [
				static::PARAM_SOURCE => 'query',
				ParamValidator::PARAM_TYPE => $this->wikiLookup->getAllWikis(),
				ParamValidator::PARAM_REQUIRED => true,
			],
			'titles' => [
				static::PARAM_SOURCE => 'query',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_ISMULTI => true,
				ParamValidator::PARAM_ISMULTI_LIMIT1 => ArticleQualityLookup::MAX_ARTICLES,
			],
		];
	}
}
