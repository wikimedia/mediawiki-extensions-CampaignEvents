<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Organizers;

use InvalidArgumentException;
use Wikimedia\Message\IMessageFormatterFactory;
use Wikimedia\Message\MessageValue;

class RoleFormatter {
	public const SERVICE_NAME = 'CampaignEventsRoleFormatter';

	private const ROLES_MSG_MAP = [
		Roles::ROLE_CREATOR => 'campaignevents-role-creator',
		Roles::ROLE_ORGANIZER => 'campaignevents-role-organizer',
	];

	/**
	 * These are used when localization is not wanted or not possible.
	 */
	private const DEBUG_NAMES_MAP = [
		Roles::ROLE_CREATOR => 'creator',
		Roles::ROLE_ORGANIZER => 'organizer',
	];

	private IMessageFormatterFactory $messageFormatterFactory;

	/**
	 * @param IMessageFormatterFactory $messageFormatterFactory
	 */
	public function __construct( IMessageFormatterFactory $messageFormatterFactory ) {
		$this->messageFormatterFactory = $messageFormatterFactory;
	}

	/**
	 * @param string $role A valid Roles::ROLE_* constant
	 * @param string $userName Of the user that the role refers to.
	 * @param string $languageCode
	 * @return string
	 */
	public function getLocalizedName( string $role, string $userName, string $languageCode ): string {
		if ( !isset( self::ROLES_MSG_MAP[$role] ) ) {
			throw new InvalidArgumentException( "Invalid role $role" );
		}
		$formatter = $this->messageFormatterFactory->getTextFormatter( $languageCode );
		return $formatter->format( MessageValue::new( self::ROLES_MSG_MAP[$role] )->params( $userName ) );
	}

	/**
	 * @param string $role A valid Roles::ROLE_* constant
	 * @return string
	 */
	public function getDebugName( string $role ): string {
		if ( !isset( self::DEBUG_NAMES_MAP[$role] ) ) {
			throw new InvalidArgumentException( "Invalid role $role" );
		}
		return self::DEBUG_NAMES_MAP[$role];
	}
}
