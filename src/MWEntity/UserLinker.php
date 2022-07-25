<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use Linker;

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

	/**
	 * @param CampaignsCentralUserLookup $centralUserLookup
	 */
	public function __construct( CampaignsCentralUserLookup $centralUserLookup ) {
		$this->centralUserLookup = $centralUserLookup;
	}

	/**
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
}
