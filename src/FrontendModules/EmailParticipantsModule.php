<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\FrontendModules;

use MediaWiki\Language\Language;
use OOUI\ButtonWidget;
use OOUI\CheckboxInputWidget;
use OOUI\FieldLayout;
use OOUI\FieldsetLayout;
use OOUI\MessageWidget;
use OOUI\MultilineTextInputWidget;
use OOUI\PanelLayout;
use OOUI\Tag;
use OOUI\TextInputWidget;
use OOUI\Widget;
use Wikimedia\Message\IMessageFormatterFactory;
use Wikimedia\Message\ITextFormatter;
use Wikimedia\Message\MessageValue;

class EmailParticipantsModule {

	private IMessageFormatterFactory $messageFormatterFactory;

	public function __construct(
		IMessageFormatterFactory $messageFormatterFactory
	) {
		$this->messageFormatterFactory = $messageFormatterFactory;
	}

	/**
	 * @return Tag
	 *
	 * @note Ideally, this wouldn't use MW-specific classes for l10n, but it's hard-ish to avoid and
	 * probably not worth doing.
	 */
	public function createContent(
		Language $language
	): Tag {
		$msgFormatter = $this->messageFormatterFactory->getTextFormatter( $language->getCode() );
		$items = [];

		$items[] = ( new Tag( 'h2' ) )->appendContent(
			$msgFormatter->format(
				MessageValue::new( 'campaignevents-email-participants-label' )
			)
		);
		$items[] = $this->getEmailForm( $msgFormatter );

		$layout = new PanelLayout( [
			'content' => $items,
			'padded' => false,
			'framed' => false,
			'expanded' => false,
		] );

		return ( new Tag( 'div' ) )
			->addClasses( [ 'ext-campaignevents-event-details-email-panel' ] )
			->appendContent( $layout );
	}

	private function getEmailForm( ITextFormatter $msgFormatter ): FieldsetLayout {
		$fields = [];

		$recipientsList = ( new Tag( 'div' ) )
			->addClasses( [ 'ext-campaignevents-eventdetails-email-recipient-list' ] );
		$addRecipientsBtn = new ButtonWidget( [
			'framed' => false,
			'flags' => [ 'progressive' ],
			'label' => $msgFormatter->format(
				MessageValue::new( 'campaignevents-event-details-email-recipients-link-text' )
			),
			'infusable' => true,
			'classes' => [ 'ext-campaignevents-details-email-recipients-link' ]
		] );

		$fields[] = new FieldLayout(
			new Widget( [
				'content' => [ $recipientsList, $addRecipientsBtn ]
			] ),
			[
				'label' => $msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-email-recipients-label' )
				),
				'align' => 'top',
			]
		);

		$fields[] = new FieldLayout(
			new MessageWidget( [
				'type' => 'warning',
				'inline' => true,
			] ),
			[
				'infusable' => true,
				'classes' => [ 'ext-campaignevents-details-email-notification', 'oo-ui-element-hidden' ]
			]
		);

		$fields[] = new FieldLayout(
			new TextInputWidget( [
				'placeholder' => $msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-email-subject-placeholder' )
				),
				'infusable' => true,
				'classes' => [ 'ext-campaignevents-details-email-subject' ]
			] ),
			[
				'label' => $msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-email-subject-label' )
				),
				'align' => 'top',
			]
		);

		$fields[] = new FieldLayout(
			new MultiLineTextInputWidget( [
				'placeholder' => $msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-email-message-placeholder' )
				),
				'infusable' => true,
				'classes' => [ 'ext-campaignevents-details-email-message' ],
				'minLength' => 10,
				'maxLength' => 2000,
				'rows' => 17,
			] ),
			[
				'label' => $msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-email-message-label' )
				),
				'align' => 'top',
			]
		);

		$fields[] = new FieldLayout(
			new CheckboxInputWidget( [
				'infusable' => true,
				'classes' => [ 'ext-campaignevents-details-email-ccme' ],
			] ),
			[
				'align' => 'inline',
				'label' => $msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-email-ccme-label' )
				),
			]
		);

		$fields[] = new FieldLayout(
			new ButtonWidget( [
				'label' => $msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-email-recipients-button-text' )
				),
				'classes' => [ 'ext-campaignevents-details-email-button' ],
				'infusable' => true,
				'flags' => [ 'primary', 'progressive' ],
				'disabled' => true,
			] )
		);

		return new FieldsetLayout( [
			'items' => $fields,
			'classes' => [ 'ext-campaignevents-eventdetails-email-form' ]
		] );
	}

}
