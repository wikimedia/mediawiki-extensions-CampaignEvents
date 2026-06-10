<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Worklist;

use MediaWiki\Content\Content;
use MediaWiki\Content\JsonContentHandler;
use MediaWiki\Content\Renderer\ContentParseParams;
use MediaWiki\Content\ValidationParams;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\ParserOutput;
use StatusValue;

class WorklistContentHandler extends JsonContentHandler {
	protected function getContentClass(): string {
		return WorklistContent::class;
	}

	/**
	 * @param WorklistContent $content
	 * @param ContentParseParams $cpoParams
	 * @param ParserOutput &$parserOutput
	 * @suppress PhanParamSignatureMismatch
	 */
	protected function fillParserOutput(
		Content $content,
		ContentParseParams $cpoParams,
		ParserOutput &$parserOutput
	): void {
		parent::fillParserOutput( $content, $cpoParams, $parserOutput );
		// Add backlinks so they show up in Special:WhatLinksHere etc.
		$localTitles = $content->getLocalLinkTargets();
		// Preload all page IDs to avoid individual lookups
		$lbFactory = MediaWikiServices::getInstance()->getLinkBatchFactory();
		$lbFactory->newLinkBatch( $localTitles )->setCaller( __METHOD__ )->execute();
		foreach ( $localTitles as $target ) {
			$parserOutput->addLink( $target );
		}
		// TODO: Consider changing rendering of the content so that pages are actually rendered as links (and wikis as
		// sections), so it's easier to find redlinks, go to the linked pages, etc.
	}

	/**
	 * @param WorklistContent $content
	 * @param ValidationParams $validationParams
	 * @suppress PhanParamSignatureMismatch
	 */
	public function validateSave( Content $content, ValidationParams $validationParams ): StatusValue {
		$parentStatus = parent::validateSave( $content, $validationParams );
		if ( !$parentStatus->isGood() ) {
			// Return a status with detailed error messages
			return $content->validate();
		}

		return $parentStatus;
	}
}
