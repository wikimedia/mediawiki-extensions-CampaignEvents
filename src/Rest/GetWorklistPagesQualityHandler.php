<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Config\Config;
use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Extension\CampaignEvents\Worklist\ArticleQualityLookup;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistPagesLookupFactory;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Page\PageStoreFactory;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * GET endpoint giving the quality of articles in an event's worklist.
 *
 * Gated on CampaignEventsEnableWorklistCardView, like the card view it feeds.
 *
 * Asked for a screenful of articles at a time rather than the whole worklist, because a score is
 * real work: the model fetches the article at predict time and cannot be asked about more than
 * one revision at once. The card view requests the articles it is showing, as it does for their
 * short descriptions.
 *
 * Only articles that are in this event's worklist are answered for. The scores themselves are
 * public, but without that check the endpoint would be an open proxy onto an inference service,
 * and any title on any wiki could be made to cost a model run.
 */
class GetWorklistPagesQualityHandler extends SimpleHandler {
	public function __construct(
		private readonly Config $config,
		private readonly PageStoreFactory $pageStoreFactory,
		private readonly WorklistPagesLookupFactory $worklistPagesLookupFactory,
		private readonly ArticleQualityLookup $articleQualityLookup,
		private readonly WikiLookup $wikiLookup,
	) {
	}

	protected function run( string $worklistWikiString, int $namespace, string $title ): Response {
		if ( !$this->config->get( 'CampaignEventsEnableWorklistCardView' ) ) {
			// Gated with the card view that reads it, so that on a wiki where the feature is off
			// the endpoint looks as though it does not exist. Not localised: the flag is
			// temporary, and the endpoint is unreachable from the UI while it is off.
			throw new HttpException( 'The worklist card view is not enabled on this wiki', 404 );
		}

		// Normalize wiki ID since PageStoreFactory doesn't (T440732)
		$worklistWiki = WikiMap::isCurrentWikiId( $worklistWikiString ) ? WikiAwareEntity::LOCAL : $worklistWikiString;
		$worklistPage = $this->pageStoreFactory->getPageStore( $worklistWiki )
			->getPageByName( $namespace, $title );

		if ( !$worklistPage ) {
			return $this->getResponseFactory()->createJson( [ 'articles' => [] ] );
		}

		$params = $this->getValidatedParams();
		$wiki = $params['wiki'];
		$requested = $params['titles'];

		$inWorklist = $this->filterToWorklist( $worklistPage, $wiki, $requested );
		$quality = $inWorklist
			? $this->articleQualityLookup->getQualityForArticles( $wiki, $inWorklist )
			: [];

		$respVal = [];
		foreach ( $inWorklist as $prefixedText ) {
			// Articles the model could not score are left out rather than sent as nulls: the card
			// shows no chip for them, and the client tells the two cases apart by absence.
			if ( isset( $quality[$prefixedText] ) ) {
				$respVal[] = [
					'wiki' => $wiki,
					'title' => $prefixedText,
					'score' => $quality[$prefixedText]['score'],
					'label' => $quality[$prefixedText]['label'],
					'elements' => $quality[$prefixedText]['elements'],
				];
			}
		}

		return $this->getResponseFactory()->createJson( [ 'articles' => $respVal ] );
	}

	/**
	 * The requested titles that really are in this event's worklist, in the order asked for.
	 *
	 * @param PageIdentity $worklistPage
	 * @param string $wiki
	 * @param list<string> $requested
	 * @return list<string>
	 */
	private function filterToWorklist( PageIdentity $worklistPage, string $wiki, array $requested ): array {
		$worklistLookup = $this->worklistPagesLookupFactory->getLookupForWorklist( $worklistPage );

		// Only the requested titles are looked up, not the whole worklist: a screen of cards asks
		// about a few dozen at most, whatever the size of the list.
		$inWorklist = array_fill_keys(
			$worklistLookup->filterWorklistPages( $wiki, $requested ),
			true
		);

		return array_values( array_filter(
			$requested,
			static fn ( string $title ): bool => isset( $inWorklist[$title] )
		) );
	}

	/** @inheritDoc */
	protected function getResponseBodySchemaFileName( string $method ): ?string {
		return __DIR__ . '/Schema/PageQuality.json';
	}

	/**
	 * @inheritDoc
	 */
	public function getParamSettings(): array {
		return [
			'worklistwiki' => [
				Handler::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => $this->wikiLookup->getAllWikis(),
				ParamValidator::PARAM_REQUIRED => true,
			],
			'namespace' => [
				Handler::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'title' => [
				Handler::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
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
