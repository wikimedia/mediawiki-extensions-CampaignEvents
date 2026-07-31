<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\EventDiscovery\DiscoverableEventsLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\ParamValidator\TypeDef\TitleDef;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Title\TitleFormatter;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Returns the events whose worklist contains the given page and for which the current user should be
 * shown the event discovery dialog, recording the once-per-user-and-event suppression as it does so.
 *
 * This is the in-place client-edit (e.g. VisualEditor) counterpart to what PostEditHandler does
 * server-side on a full page reload: such edits never trigger BeforePageDisplay, so the
 * ext.campaignEvents.postEdit module calls this endpoint from the post-edit hook instead. The shared
 * selection/promotion logic lives in DiscoverableEventsLookup.
 */
class ListDiscoverableEventsForPageHandler extends SimpleHandler {
	public function __construct(
		private readonly CampaignsCentralUserLookup $centralUserLookup,
		private readonly TitleFormatter $titleFormatter,
		private readonly DiscoverableEventsLookup $discoverableEventsLookup,
	) {
	}

	/** @phan-return list<array{id:int,name:string,url:string}> */
	public function run(): array {
		$authority = $this->getAuthority();
		try {
			$centralUser = $this->centralUserLookup->newFromAuthority( $authority );
		} catch ( UserNotGlobalException ) {
			return [];
		}

		$title = $this->getValidatedParams()['page'];
		return $this->discoverableEventsLookup->getAndRecordPromotableEvents(
			$authority,
			$centralUser,
			$this->titleFormatter->getPrefixedText( $title ),
			WikiMap::getCurrentWikiId(),
			50
		);
	}

	public function getParamSettings(): array {
		return [
			'page' => [
				static::PARAM_SOURCE => 'query',
				ParamValidator::PARAM_TYPE => 'title',
				ParamValidator::PARAM_REQUIRED => true,
				TitleDef::PARAM_RETURN_OBJECT => true,
				TitleDef::PARAM_MUST_EXIST => true,
			],
		];
	}
}
