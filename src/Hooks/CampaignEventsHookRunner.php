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

	private HookContainer $hookContainer;

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
	public function onCampaignEventsRegistrationFormLoad( array &$formFields, ?int $eventID ): void {
		$this->hookContainer->run(
			'CampaignEventsRegistrationFormLoad',
			[ &$formFields, $eventID ],
			[ 'abortable' => false ]
		);
	}

	/**
	 * @inheritDoc
	 */
	public function onCampaignEventsRegistrationFormSubmit(
		array $data,
		int $eventID
	): void {
		$this->hookContainer->run(
			'CampaignEventsRegistrationFormSubmit',
			[ $data, $eventID ],
			[ 'abortable' => false ]
		);
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
	): void {
		$this->hookContainer->run(
			'CampaignEventsGetEventDetails',
			[ $infoColumn, $organizersColumn, $eventID, $isOrganizer, $outputPage ],
			[ 'abortable' => false ]
		);
	}
}
