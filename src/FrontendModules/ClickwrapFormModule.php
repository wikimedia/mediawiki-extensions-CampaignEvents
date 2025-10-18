<?php

declare( strict_types=1 );
namespace MediaWiki\Extension\CampaignEvents\FrontendModules;

use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Language\Language;
use MediaWiki\Permissions\Authority;
use OOUI\HtmlSnippet;
use Wikimedia\Message\IMessageFormatterFactory;
use Wikimedia\Message\MessageValue;

class ClickwrapFormModule {
	private Authority $authority;

	public function __construct(
		private readonly ExistingEventRegistration $event,
		private readonly OrganizersStore $organizersStore,
		private readonly IMessageFormatterFactory $messageFormatterFactory,
		private readonly Language $language,
		private readonly CampaignsCentralUserLookup $centralUserLookup,
	) {
	}

	/**
	 * @return array{isSubmitted:bool,content:string}
	 */
	public function createContent( IContextSource $context, string $action ): array {
		$msgFormatter = $this->messageFormatterFactory->getTextFormatter( $this->language->getCode() );
		$formIntro = Html::element(
			'p',
			[],
			$msgFormatter->format( MessageValue::new( 'campaignevents-edit-field-clickwrap-checkbox-pretext' ) )
		);
		$form = $this->createForm( $context )
			->setAction( $action )
			->setSubmitCallback( [ $this, 'processInput' ] )
			->suppressDefaultSubmit()
			->setPreHtml( $formIntro )
			->prepareForm();
		$isFormSubmitted = $form->tryAuthorizedSubmit();

		return [
			'isSubmitted' => $isFormSubmitted,
			'content' => new HtmlSnippet( $form->getHTML( $isFormSubmitted ) )
		];
	}

	private function createForm( IContextSource $context ): HTMLForm {
		$this->authority = $context->getAuthority();
		$formDescriptor = [
			'Acceptance' => [
				'label-message' => 'campaignevents-edit-field-clickwrap-checkbox-label',
				'type' => 'check'
			],
			'Submit' => [
				'buttonlabel-message' => 'campaignevents-edit-field-clickwrap-form-continue',
				'disable-if' => [ '!==', 'Acceptance', '1' ],
				'type' => 'submit'
			]
		];

		return HTMLForm::factory( 'ooui', $formDescriptor, $context );
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public function processInput( array $data ): bool {
		if ( $data['Acceptance'] ) {
			$centralUser = $this->centralUserLookup->newFromAuthority( $this->authority );
			$this->organizersStore->updateClickwrapAcceptance( $this->event->getID(), $centralUser );
			return true;
		} else {
			return false;
		}
	}

}
