<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use Html;
use Linker;
use Wikimedia\Message\IMessageFormatterFactory;
use Wikimedia\Message\MessageValue;

/**
 * This class generates links to (global) user accounts.
 */
class UserLinker {
	public const SERVICE_NAME = 'CampaignEventsUserLinker';

	public const MODULE_STYLES = [
		// Needed by Linker::userLink
		'mediawiki.interface.helpers.styles',
	];

	/** @var CampaignsCentralUserLookup */
	private $centralUserLookup;
	/** @var IMessageFormatterFactory */
	private $messageFormatterFactory;

	/**
	 * @param CampaignsCentralUserLookup $centralUserLookup
	 * @param IMessageFormatterFactory $messageFormatterFactory
	 */
	public function __construct(
		CampaignsCentralUserLookup $centralUserLookup,
		IMessageFormatterFactory $messageFormatterFactory
	) {
		$this->centralUserLookup = $centralUserLookup;
		$this->messageFormatterFactory = $messageFormatterFactory;
	}

	/**
	 * Generates a link to the given user, if it can be found and is visible, throwing an exception otherwise.
	 *
	 * @param CentralUser $user
	 * @return string HTML
	 * @throws CentralUserNotFoundException
	 * @throws HiddenCentralUserException
	 * @note When using this method, make sure to add self::MODULE_STYLES to the output.
	 */
	public function generateUserLink( CentralUser $user ): string {
		$name = $this->centralUserLookup->getUserName( $user );
		if ( $this->centralUserLookup->existsLocally( $user ) ) {
			// Semi-hack: Linker::userLink does not really need the user ID, so don't bother looking it up. (T308000)
			return Linker::userLink( 1, $name );
		} else {
			// TODO This case should be improved. Perhaps we could at least link to Special:CentralAuth if
			// CA is installed. For now we simply generate a red link.
			return Linker::userLink( 2, $name );
		}
	}

	/**
	 * Like ::generateUserLink, but returns placeholders instead of throwing an exception for users that
	 * cannot be found or are not visible.
	 *
	 * @param CentralUser $user
	 * @param string $langCode Used for localizing placeholders.
	 * @return string HTML
	 *
	 * @note When using this method, make sure to add self::MODULE_STYLES to the output, and to include the
	 * ext.campaignEvents.userlinks.styles.less file as well.
	 * @note This assumes that the given central user exists, or existed in the past. As such, if the account
	 * cannot be found it will consider it as being deleted.
	 */
	public function generateUserLinkWithFallback( CentralUser $user, string $langCode ): string {
		try {
			return $this->generateUserLink( $user );
		} catch ( CentralUserNotFoundException $_ ) {
			$msgFormatter = $this->messageFormatterFactory->getTextFormatter( $langCode );
			return Html::element(
				'span',
				[ 'class' => 'ext-campaignevents-userlink-deleted' ],
				$msgFormatter->format(
					MessageValue::new( 'campaignevents-userlink-deleted-user' )
				)
			);
		} catch ( HiddenCentralUserException $_ ) {
			$msgFormatter = $this->messageFormatterFactory->getTextFormatter( $langCode );
			return Html::element(
				'span',
				[ 'class' => 'ext-campaignevents-userlink-hidden' ],
				$msgFormatter->format(
					MessageValue::new( 'campaignevents-userlink-suppressed-user' )
				)
			);
		}
	}
}
