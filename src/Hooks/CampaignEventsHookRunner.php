<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Hooks;

use MediaWiki\HookContainer\HookContainer;
use MediaWiki\Output\OutputPage;
use OOUI\Tag;

class CampaignEventsHookRunner implements
	CampaignEventsGetPolicyMessageForRegistrationHook,
	CampaignEventsGetPolicyMessageForRegistrationFormHook,
	CampaignEventsRegistrationFormLoadHook,
	CampaignEventsRegistrationFormSubmitHook,
	CampaignEventsGetEventDetailsHook
{
	public const SERVICE_NAME = 'CampaignEventsHookRunner';

	/** @var HookContainer */
	private $hookContainer;

	/**
	 * @param HookContainer $hookContainer
	 */
	public function __construct( HookContainer $hookContainer ) {
		$this->hookContainer = $hookContainer;
	}

	/**
	 * @inheritDoc
	 */
	public function onCampaignEventsGetPolicyMessageForRegistration( ?string &$message ) {
		return $this->hookContainer->run(
			'CampaignEventsGetPolicyMessageForRegistration',
			[ &$message ]
		);
	}

	/**
	 * @inheritDoc
	 */
	public function onCampaignEventsGetPolicyMessageForRegistrationForm( ?string &$message ) {
		return $this->hookContainer->run(
			'CampaignEventsGetPolicyMessageForRegistrationForm',
			[ &$message ]
		);
	}

	/**
	 * @inheritDoc
	 */
	public function onCampaignEventsRegistrationFormLoad( array &$formFields, ?int $eventID ) {
		return $this->hookContainer->run(
			'CampaignEventsRegistrationFormLoad',
			[ &$formFields, $eventID ]
		);
	}

	/**
	 * @inheritDoc
	 */
	public function onCampaignEventsRegistrationFormSubmit(
		array $data,
		int $eventID ): bool {
		return $this->hookContainer->run(
			'CampaignEventsRegistrationFormSubmit',
			[ $data, $eventID ] );
	}

	/**
	 * @inheritDoc
	 */
	public function onCampaignEventsGetEventDetails(
		Tag $infoColumn,
		Tag $organizersColumn,
		int $eventID,
		bool $isOrganizer,
		OutputPage $outputPage
	): bool {
		return $this->hookContainer->run(
			'CampaignEventsGetEventDetails',
			[ $infoColumn, $organizersColumn, $eventID, $isOrganizer, $outputPage ]
		);
	}
}
