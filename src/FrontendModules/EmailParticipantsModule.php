<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\FrontendModules;

use Language;
use OOUI\ButtonWidget;
use OOUI\FieldLayout;
use OOUI\LabelWidget;
use OOUI\MessageWidget;
use OOUI\MultilineTextInputWidget;
use OOUI\PanelLayout;
use OOUI\Tag;
use OOUI\TextInputWidget;
use Wikimedia\Message\IMessageFormatterFactory;
use Wikimedia\Message\MessageValue;

class EmailParticipantsModule {

	/** @var IMessageFormatterFactory */
	private IMessageFormatterFactory $messageFormatterFactory;

	/**
	 * @param IMessageFormatterFactory $messageFormatterFactory
	 */
	public function __construct(
		IMessageFormatterFactory $messageFormatterFactory
	) {
		$this->messageFormatterFactory = $messageFormatterFactory;
	}

	/**
	 * @param Language $language
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

		$items[] = ( new Tag( 'div' ) )->appendContent(
			$msgFormatter->format(
				MessageValue::new( 'campaignevents-email-participants-label' )
			)
		)->addClasses( [ 'ext-campaignevents-email-participants-info-header' ] );
		$items[] = ( new LabelWidget( [
			'label' => $msgFormatter->format(
				MessageValue::new( 'campaignevents-event-details-email-recipients-label' )
			),
		] ) )->addClasses( [ 'ext-campaignevents-email-label' ] );

		$items[] = new ButtonWidget(
			[
				'framed' => false,
				'flags' => [ 'progressive' ],
				'label' => $msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-email-recipients-link-text' )
				),
				'infusable' => true,
				'classes' => [ 'ext-campaignevents-details-email-recipients-link' ]
			] );

		$items[] = ( new Tag( 'ul' ) )->addClasses( [ 'ext-campaignevents-details-email-recipient-list' ] );

		$items[] = new MessageWidget( [
			'name' => 'ext-campaignevents-details-email-message',
			'infusable' => true,
			'inline' => true,
			'type' => 'warning',
			'classes' => [ 'ext-campaignevents-details-email-notification', 'oo-ui-element-hidden' ]
		] );

		$items[] = new FieldLayout(
			new TextInputWidget(
				[
					'placeholder' => $msgFormatter->format(
						MessageValue::new( 'campaignevents-event-details-email-subject-placeholder' )
					),

					'infusable' => true,
					'classes' => [ 'ext-campaignevents-details-email-subject' ]
				]
			),
			[
				'label' => $msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-email-subject-label' )
				),
				'classes' => [ 'ext-campaignevents-email-label' ],
				'align' => 'top'
			]
		);

		$items[] = new FieldLayout(
			new MultiLineTextInputWidget
			(
				[
					'placeholder' => $msgFormatter->format(
						MessageValue::new( 'campaignevents-event-details-email-message-placeholder' )
					),
					'infusable' => true,
					'classes' => [ 'ext-campaignevents-details-email-message' ],
					'minLength' => 10,
					'maxLength' => 2000
				]
			),
			[
				'label' => $msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-email-message-label' )
				),
				'classes' => [ 'ext-campaignevents-email-label' ],
				'align' => 'top'
			]
		);

		$items[] = new ButtonWidget(
			[
				'label' => $msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-email-recipients-button-text' )
				),
				'classes' => [ 'ext-campaignevents-details-email-button' ],
				'infusable' => true,
				'flags' => [ 'primary', 'progressive' ],
			]
		);

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

}
