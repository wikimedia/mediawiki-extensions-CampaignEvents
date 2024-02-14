<?php

declare( strict_types=1 );
namespace MediaWiki\Extension\CampaignEvents\FrontendModules;

use HTMLForm;
use IContextSource;
use Language;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWAuthorityProxy;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Permissions\Authority;
use OOUI\HtmlSnippet;
use OOUI\Tag;
use Wikimedia\Message\IMessageFormatterFactory;
use Wikimedia\Message\MessageValue;

class ClickwrapFormModule {
	private ExistingEventRegistration $event;
	private OrganizersStore $organiserStore;
	private IMessageFormatterFactory $messageFormatterFactory;
	private Language $language;
	private CampaignsCentralUserLookup $centralUserLookup;
	private Authority $authority;

	public function __construct(
		ExistingEventRegistration $event,
		OrganizersStore $organizersStore,
		IMessageFormatterFactory $messageFormatterFactory,
		Language $language,
		CampaignsCentralUserLookup $centralUserLookup
	) {
		$this->event = $event;
		$this->organiserStore = $organizersStore;
		$this->messageFormatterFactory = $messageFormatterFactory;
		$this->language = $language;
		$this->centralUserLookup = $centralUserLookup;
	}

	/**
	 * @param IContextSource $context
	 * @return array
	 * @phan-return array{isSubmitted:bool,content:string}
	 */
	public function createContent( IContextSource $context, string $action ): array {
		$msgFormatter = $this->messageFormatterFactory->getTextFormatter( $this->language->getCode() );
		$container = new Tag();
		$label = new Tag( 'p' );
		$label->appendContent(
			$msgFormatter->format(
				MessageValue::new( 'campaignevents-edit-field-clickwrap-checkbox-pretext' )
			)
		);
		$container->appendContent( $label );
		$form = $this->createForm( $context )
			->setAction( $action )
			->setSubmitCallback( [ $this, 'processInput' ] )
			->suppressDefaultSubmit()
			->setPreHtml( $container )
			->prepareForm();
		$isFormSubmitted = $form->tryAuthorizedSubmit();

		return [
			'isSubmitted' => $isFormSubmitted,
			'content' => new HtmlSnippet( $form->getHTML( $isFormSubmitted ) )
		];
	}

	/**
	 * @param IContextSource $context
	 * @return HTMLForm
	 */
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
	 * @param array $data
	 * @return bool
	 */
	public function processInput( array $data ): bool {
		if ( $data['Acceptance'] ) {
			$centralUser = $this->centralUserLookup->newFromAuthority( new MWAuthorityProxy( $this->authority ) );
			$this->organiserStore->updateClickwrapAcceptance( $this->event->getID(), $centralUser );
			return true;
		} else {
			return false;
		}
	}

}
