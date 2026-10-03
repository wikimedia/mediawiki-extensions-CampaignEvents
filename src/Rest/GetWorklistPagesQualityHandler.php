<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Config\Config;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\Worklist\ArticleQualityLookup;
use MediaWiki\Extension\CampaignEvents\Worklist\IWorklistArticlesLookup;
use MediaWiki\Page\PageStoreFactory;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
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
	use EventIDParamTrait;

	public function __construct(
		private readonly Config $config,
		private readonly IEventLookup $eventLookup,
		private readonly IWorklistArticlesLookup $worklistArticlesLookup,
		private readonly PageStoreFactory $pageStoreFactory,
		private readonly ArticleQualityLookup $articleQualityLookup,
	) {
	}

	protected function run( int $eventID ): Response {
		if ( !$this->config->get( 'CampaignEventsEnableWorklistCardView' ) ) {
			// Gated with the card view that reads it, so that on a wiki where the feature is off
			// the endpoint looks as though it does not exist. Not localised: the flag is
			// temporary, and the endpoint is unreachable from the UI while it is off.
			throw new HttpException( 'The worklist card view is not enabled on this wiki', 404 );
		}

		$event = $this->getRegistrationOrThrow( $this->eventLookup, $eventID );
		$params = $this->getValidatedParams();
		$wiki = $params['wiki'];
		$requested = $params['titles'];

		$inWorklist = $this->filterToWorklist( $event, $wiki, $requested );
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
	 * @param ExistingEventRegistration $event
	 * @param string $wiki
	 * @param list<string> $requested
	 * @return list<string>
	 */
	private function filterToWorklist( ExistingEventRegistration $event, string $wiki, array $requested ): array {
		$worklistPage = $this->getWorklistPage( $event );
		if ( $worklistPage === null ) {
			return [];
		}

		// Only the requested titles are looked up, not the whole worklist: a screen of cards asks
		// about a few dozen at most, whatever the size of the list.
		$inWorklist = array_fill_keys(
			$this->worklistArticlesLookup->filterWorklistArticles( $worklistPage, $wiki, $requested ),
			true
		);

		return array_values( array_filter(
			$requested,
			static fn ( string $title ): bool => isset( $inWorklist[$title] )
		) );
	}

	/** The page holding the event's worklist, or null when it has not been created. */
	private function getWorklistPage( ExistingEventRegistration $event ): ?ProperPageIdentity {
		$eventPage = $event->getPage();
		$worklistPageName = $eventPage->getDBkey() . '/Worklist';
		return $this->pageStoreFactory
			->getPageStore( $eventPage->getWikiId() )
			->getPageByName( $eventPage->getNamespace(), $worklistPageName );
	}

	/**
	 * @inheritDoc
	 */
	public function getParamSettings(): array {
		return $this->getIDParamSetting() + [
			'wiki' => [
				static::PARAM_SOURCE => 'query',
				ParamValidator::PARAM_TYPE => 'string',
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
