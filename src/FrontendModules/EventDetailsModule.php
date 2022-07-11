<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\FrontendModules;

use Language;
use Linker;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWUserProxy;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageURLResolver;
use MediaWiki\Extension\CampaignEvents\Special\SpecialEditEventRegistration;
use MediaWiki\Extension\CampaignEvents\Utils;
use MediaWiki\Extension\CampaignEvents\Widget\TextWithIconWidget;
use MediaWiki\Extension\CampaignEvents\Widgets\IconLabelContentWidget;
use OOUI\ButtonWidget;
use OOUI\HtmlSnippet;
use OOUI\IconWidget;
use OOUI\PanelLayout;
use OOUI\Tag;
use SpecialPage;
use Wikimedia\Message\ITextFormatter;
use Wikimedia\Message\MessageValue;

class EventDetailsModule {

	public const MODULE_STYLES = [
		'oojs-ui.styles.icons-location',
		'oojs-ui.styles.icons-interactions',
		'oojs-ui.styles.icons-editing-core',
	];

	/**
	 * @param Language $language
	 * @param ExistingEventRegistration $registration
	 * @param MWUserProxy $userProxy
	 * @param ITextFormatter $msgFormatter
	 * @param bool $isOrganizer
	 * @param bool $isParticipant
	 * @param int $organizersCount
	 * @param PageURLResolver $pageURLResolver
	 * @return PanelLayout
	 */
	public function createContent(
		Language $language,
		ExistingEventRegistration $registration,
		MWUserProxy $userProxy,
		ITextFormatter $msgFormatter,
		bool $isOrganizer,
		bool $isParticipant,
		int $organizersCount,
		PageURLResolver $pageURLResolver
	): PanelLayout {
		$items = [];
		$items[] = ( new Tag( 'span' ) )->appendContent(
			$msgFormatter->format(
				MessageValue::new( 'campaignevents-event-details-label' )
			)
		)->addClasses( [ 'ext-campaignevents-event-details-info-header' ] );

		if ( $isOrganizer ) {
			$items[] = new ButtonWidget( [
				'label' => $msgFormatter->format( MessageValue::new( 'campaignevents-event-details-edit-button' ) ),
				'classes' => [ 'ext-campaignevents-details-edit-button' ],
				'href' => SpecialPage::getTitleFor(
					SpecialEditEventRegistration::PAGE_NAME,
					(string)$registration->getID()
				)->getLocalURL(),
				'icon' => 'edit'
			] );
		}

		$items[] = new TextWithIconWidget( [
			'icon' => 'clock',
			'content' => $msgFormatter->format(
				MessageValue::new( 'campaignevents-event-details-dates' )->params(
					$language->userTimeAndDate( $registration->getStartTimestamp(), $userProxy->getUserIdentity() ),
					$language->userDate( $registration->getStartTimestamp(), $userProxy->getUserIdentity() ),
					$language->userTime( $registration->getStartTimestamp(), $userProxy->getUserIdentity() ),
					$language->userTimeAndDate( $registration->getEndTimestamp(), $userProxy->getUserIdentity() ),
					$language->userDate( $registration->getEndTimestamp(), $userProxy->getUserIdentity() ),
					$language->userTime( $registration->getEndTimestamp(), $userProxy->getUserIdentity() )
				)
			),
			'label' => $msgFormatter->format( MessageValue::new( 'campaignevents-event-details-dates-label' ) ),
			'icon_classes' => [ 'ext-campaignevents-event-details-icons-style' ],
		] );

		$needToRegisterMsg = ( new Tag( 'p' ) )->appendContent(
			$msgFormatter->format(
				MessageValue::new( 'campaignevents-event-details-register-prompt' )
			)
		);

		$items = array_merge(
			$items,
			$this->getLocationContent(
				$registration,
				$msgFormatter,
				$isOrganizer,
				$isParticipant,
				$organizersCount,
				$needToRegisterMsg
			)
		);

		$chatURL = $registration->getChatURL();
		if ( $chatURL ) {
			$items[] = ( new Tag() )->appendContent(
				$msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-chat-link' )
				)
			)->addClasses( [ 'ext-campaignevents-event-details-social-media' ] );

			if ( $isOrganizer || $isParticipant ) {

				$iconLink = ( new IconWidget( [
					'icon' => 'link',
				] ) )->addClasses( [ 'ext-campaignevents-event-details-icons-style' ] );
				$items[] = ( new Tag() )->appendContent(
					$iconLink,
					new HtmlSnippet(
						Linker::makeExternalLink(
							$chatURL,
							$chatURL,
							true,
							'',
							[ 'class' => 'ext-campaignevents-event-details-icon-link' ]
						)
					)
				);
			} else {
				$items[] = $needToRegisterMsg;
			}
		}

		$items[] = new ButtonWidget( [
			'flags' => [ 'progressive' ],
			'target' => '_blank',
			'label' => $msgFormatter->format( MessageValue::new( 'campaignevents-event-details-view-event-page' ) ),
			'classes' => [ 'ext-campaignevents-event-details-view-event-page-button' ],
			'href' => $pageURLResolver->getFullUrl( $registration->getPage() )
		] );

		return new PanelLayout( [
			'content' => $items,
			'padded' => true,
			'framed' => true,
			'expanded' => false,
			'classes' => [ 'ext-campaignevents-eventdetails-panel' ],
		] );
	}

	/**
	 * @param ExistingEventRegistration $registration
	 * @param ITextFormatter $msgFormatter
	 * @param bool $isOrganizer
	 * @param bool $isParticipant
	 * @param int $organizersCount
	 * @param Tag $needToRegisterMsg
	 * @return array
	 */
	private function getLocationContent(
		ExistingEventRegistration $registration,
		ITextFormatter $msgFormatter,
		bool $isOrganizer,
		bool $isParticipant,
		int $organizersCount,
		Tag $needToRegisterMsg
	): array {
		$meetingType = $registration->getMeetingType();
		$items = [];
		if (
			$meetingType & ExistingEventRegistration::MEETING_TYPE_IN_PERSON
		) {
			$address = $registration->getMeetingAddress() . "\n" . $registration->getMeetingCountry();
			$items[] = new IconLabelContentWidget( [
				'icon' => 'mapPin',
				'content' => $address,
				'label' => $msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-in-person-event-label' )
				),
				'icon_classes' => [ 'ext-campaignevents-event-details-icons-style' ],
				'content_direction' => Utils::guessStringDirection( $address )
			] );
		}

		if (
			$meetingType & ExistingEventRegistration::MEETING_TYPE_ONLINE
		) {
			$meetingURL = $registration->getMeetingURL();
			$content = $msgFormatter->format(
				MessageValue::new( 'campaignevents-event-details-online-link-not-available' )
					->numParams( $organizersCount )
			);
			if ( $meetingURL ) {
				if ( $isOrganizer || $isParticipant ) {
					$iconLink = ( new IconWidget( [
						'icon' => 'link',
					] ) )->addClasses( [ 'ext-campaignevents-event-details-icons-style' ] );
					$content = ( new Tag() )->appendContent(
						$iconLink,
						new HtmlSnippet(
							Linker::makeExternalLink(
								$meetingURL,
								$meetingURL,
								true,
								'',
								[ 'class' => 'ext-campaignevents-event-details-icon-link' ]
							)
						)
					);
				} else {
					$content = $needToRegisterMsg;
				}
			}

			$items[] = new IconLabelContentWidget( [
				'icon' => $meetingType === ExistingEventRegistration::MEETING_TYPE_ONLINE ? 'mapPin' : '',
				'content' => $content,
				'label' => $msgFormatter->format(
					MessageValue::new( 'campaignevents-event-details-online-label' )
				),
				'icon_classes' => [ 'ext-campaignevents-event-details-icons-style' ],
			] );
		}

		return $items;
	}
}
