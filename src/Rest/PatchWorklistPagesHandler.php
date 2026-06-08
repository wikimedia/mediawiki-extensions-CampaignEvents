<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Config\Config;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistArticleHelper;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\ParamValidator\TypeDef\TitleDef;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Rest\TokenAwareHandlerTrait;
use MediaWiki\Rest\Validator\Validator;
use MediaWiki\Title\TitleFactory;
use StatusValue;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * PATCH endpoint that applies a delta (articles to add and/or remove) to a worklist's page list.
 *
 * The worklist is identified by its page title rather than by an event, so the endpoint also works
 * for arbitrary worklists and for events that have more than one worklist. The worklist's host wiki
 * is implicit: the client targets that wiki's API directly (via ForeignApi for a remote worklist).
 * A single PATCH with an add/remove delta replaces the earlier separate PUT (add) and DELETE
 * (remove) endpoints, which is the correct verb since the body is a partial update, not a full
 * representation of the resource.
 */
class PatchWorklistPagesHandler extends SimpleHandler {
	use TokenAwareHandlerTrait;
	use FailStatusUtilTrait;

	public function __construct(
		private readonly WorklistArticleHelper $worklistArticleHelper,
		private readonly TitleFactory $titleFactory,
		private readonly Config $config,
	) {
	}

	/** @inheritDoc */
	public function validate( Validator $restValidator ): void {
		parent::validate( $restValidator );
		$this->validateToken();
	}

	protected function run( LinkTarget $worklistTitle ): Response {
		// When the worklist feature is disabled the endpoint behaves as if it did not exist.
		if ( !$this->config->get( 'CampaignEventsEnableWorklists' ) ) {
			throw new LocalizedHttpException( new MessageValue( 'campaignevents-rest-event-not-found' ), 404 );
		}

		$body = $this->getValidatedBody() ?? [];
		// Articles are grouped by wiki, e.g. { "enwiki": [ "Article One" ], "ptwiki": [ "Artigo" ] }.
		$add = $body['add'] ?? [];
		$remove = $body['remove'] ?? [];

		// Apply the whole delta in one atomic edit (see WorklistArticleHelper::applyDelta).
		$this->applyOrThrow( $this->worklistArticleHelper->applyDelta(
			$this->titleFactory->newFromLinkTarget( $worklistTitle ),
			$add,
			$remove
		) );

		return $this->getResponseFactory()->createNoContent();
	}

	private function applyOrThrow( StatusValue $status ): void {
		if ( !$status->isGood() ) {
			// Authorization failures (PermissionStatus) map to 403; invalid-article failures to 400.
			$httpStatus = $status instanceof PermissionStatus ? 403 : 400;
			$this->exitWithStatus( $status, $httpStatus );
		}
	}

	/** @inheritDoc */
	public function getParamSettings(): array {
		return [
			'title' => [
				static::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'title',
				TitleDef::PARAM_RETURN_OBJECT => true,
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}

	/** @inheritDoc */
	public function getBodyParamSettings(): array {
		return [
			'add' => [
				static::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'array',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_DEFAULT => [],
			],
			'remove' => [
				static::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'array',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_DEFAULT => [],
			],
		] + $this->getTokenParamDefinition();
	}
}
