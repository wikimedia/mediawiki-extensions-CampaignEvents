<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\FrontendModules;

use Language;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWUserProxy;
use MediaWiki\Extension\CampaignEvents\Participants\Participant;
use OOUI\ButtonWidget;
use OOUI\CheckboxInputWidget;
use OOUI\FieldLayout;
use OOUI\IconWidget;
use OOUI\PanelLayout;
use OOUI\SearchInputWidget;
use OOUI\Tag;
use Wikimedia\Message\ITextFormatter;
use Wikimedia\Message\MessageValue;

class EventDetailsParticipantsModule {
	public const MODULE_STYLES = [
		'oojs-ui.styles.icons-moderation',
		'oojs-ui.styles.icons-user'
	];

	/**
	 * @param Language $language
	 * @param MWUserProxy $userProxy
	 * @param Participant[] $participants
	 * @param int $totalParticipants
	 * @param ITextFormatter $msgFormatter
	 * @param bool $canRemoveParticipants
	 * @param CampaignsCentralUserLookup $centralUserLookup
	 * @return PanelLayout
	 */
	public function createContent(
		Language $language,
		MWUserProxy $userProxy,
		array $participants,
		int $totalParticipants,
		ITextFormatter $msgFormatter,
		bool $canRemoveParticipants,
		CampaignsCentralUserLookup $centralUserLookup
	): PanelLayout {
		$items = [];
		$items[] = ( new Tag() )->appendContent(
			$msgFormatter->format(
				MessageValue::new( 'campaignevents-event-details-header-participants' )
					->numParams( $totalParticipants )
			)
		)->addClasses( [ 'ext-campaignevents-details-participants-header' ] );

		$noParticipantsIcon = new IconWidget( [
			'icon' => 'userGroup',
		] );

		$hideClass = [];
		if ( $totalParticipants > 0 ) {
			$hideClass = [ 'ext-campaignevents-details-hide-element' ];
		}
		$items[] = ( new Tag() )->appendContent(
			( new Tag() )->appendContent( $noParticipantsIcon )
				->addClasses( [ 'ext-campaignevents-event-details-no-participants-icon' ] ),
			( new Tag() )->appendContent(
				$msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-no-participants-state' )
				)
			)->addClasses( [ 'ext-campaignevents-details-no-participants-description' ] )
		)->addClasses(
			array_merge( [ 'ext-campaignevents-details-no-participants-state' ], $hideClass )
		);

		if ( $participants ) {
			$items[] = ( new Tag() )->appendContent(
				( new SearchInputWidget( [
					'placeholder' => $msgFormatter->format(
						MessageValue::new( 'campaignevents-event-details-search-participants-placeholder' )
					)
				] ) )->addClasses( [ 'ext-campaignevents-details-participants-search' ] )
			)->addClasses( [ 'ext-campaignevents-details-participants-search-div' ] );
		}

		if ( $canRemoveParticipants && $participants ) {
			$selectAllCheckBox = ( new CheckboxInputWidget( [
					'name' => 'event-details-select-all-participants',
					'infusable' => true,
					'id' => 'event-details-select-all-participant-checkbox',
				] )
			);

			$removeButton = ( new ButtonWidget( [
				'infusable' => true,
				'framed' => false,
				'flags' => [
					'destructive'
				],
				'icon' => 'trash',
				'label' => $msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-remove-participant-remove-btn' )
				),
				'id' => 'ext-campaignevents-event-details-remove-participant-button',
				'classes' => [ 'ext-campaignevents-event-details-remove-participant-button' ],
			] ) );

			$items[] = ( new Tag() )->appendContent(
				new FieldLayout(
					$selectAllCheckBox,
					[
						'label' => $msgFormatter->format(
								MessageValue::new( 'campaignevents-event-details-select-all' )
						),
						'align' => 'inline',
						'classes' => [ 'ext-campaignevents-event-details-select-all-participant-checkbox' ],
					]
				),
				$removeButton
			)->addClasses( [ 'ext-campaignevents-details-select-all-users-div' ] );
		}

		$usersDivContent = ( new Tag( 'div' ) )
				->addClasses( [ 'ext-campaignevents-details-users-container' ] );

		$usersDivRows = ( new Tag( 'div' ) )
				->addClasses( [ 'ext-campaignevents-details-users-rows-container' ] );
		foreach ( $participants as $participant ) {
			$elements = [];
			if ( $canRemoveParticipants ) {
				$elements[] = ( new CheckboxInputWidget( [
					'name' => 'event-details-participants-checkboxes',
					'infusable' => true,
					'value' => $centralUserLookup->getCentralID( $participant->getUser() ),
					'classes' => [ 'ext-campaignevents-event-details-participants-checkboxes' ],
				] ) );
			}
			$elements[] = ( new Tag( 'span' ) )
				->appendContent( $participant->getUser()->getName() )
				->addClasses( [ 'ext-campaignevents-details-participant-username' ] );

			$elements[] = ( new Tag( 'span' ) )->appendContent(
				$language->userTimeAndDate(
					$participant->getRegisteredAt(),
					$userProxy->getUserIdentity()
				)
			)->addClasses( [ 'ext-campaignevents-details-participant-registered-at' ] );

			$userRow = ( new Tag() )
				->appendContent( ...$elements )
				->addClasses( [ 'ext-campaignevents-details-user-div' ] );

			$usersDivRows->appendContent( $userRow );
		}

		$usersDivContent->appendContent( $usersDivRows );

		$items[] = $usersDivContent;

		return new PanelLayout( [
			'content' => $items,
			'padded' => true,
			'framed' => true,
			'expanded' => false,
			'classes' => [ 'ext-campaignevents-event-details-participants-panel' ],
		] );
	}
}
