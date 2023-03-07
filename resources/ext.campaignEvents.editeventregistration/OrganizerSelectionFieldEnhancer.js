( function () {
	'use strict';

	function OrganizerSelectionFieldEnhancer() {
		this.isEventCreator = mw.config.get( 'wgCampaignEventsIsEventCreator' );
		this.eventCreatorUsername = mw.config.get( 'wgCampaignEventsEventCreatorUsername' );
		this.eventID = mw.config.get( 'wgCampaignEventsEventID' );
	}

	OrganizerSelectionFieldEnhancer.prototype.init = function ( $fieldElement ) {
		this.usersMultiselectFieldLayout = mw.htmlform.FieldLayout.static.infuse( $fieldElement );
		this.organizersField = this.usersMultiselectFieldLayout.fieldWidget;
		this.setInvalidOrganizers();

		this.organizersField.on( 'change', this.setInvalidOrganizers.bind( this ) );
		if ( !this.isEventCreator || !this.eventID ) {
			var organizerItem = this.organizersField.findItemFromData( this.eventCreatorUsername );
			if ( organizerItem ) {
				// XXX The card can still be removed by using the menu (i.e., highlight the entry
				// and hit enter), see T331421.
				organizerItem.setDisabled( true );
				organizerItem.$element.attr(
					'title',
					mw.msg( 'campaignevents-edit-field-organizers-creator-title' )
				);
			}
		}
	};

	OrganizerSelectionFieldEnhancer.prototype.setInvalidOrganizers = function () {
		var organizers = this.organizersField.getValue();
		if ( !organizers.length ) {
			this.usersMultiselectFieldLayout.setErrors( [
				mw.msg( 'campaignevents-edit-no-organizers' )
			] );
			return;
		}

		this.organizersField.api.abort();
		this.organizersField.api.get( {
			action: 'query',
			list: 'users',
			ususers: organizers,
			usprop: 'rights'
		} )
			.done( this.setInvalidOrganizersFromResponse.bind( this ) )
			.fail(
				function ( _err, errData ) {
					if ( errData.xhr.status === 0 && errData.textStatus === 'abort' ) {
						// Aborted due to updated input, ignore.
						return;
					}
					mw.log.error( errData.xhr.responseText || 'Unknown error' );
				}
			);
	};

	OrganizerSelectionFieldEnhancer.prototype.setInvalidOrganizersFromResponse = function ( resp ) {
		var invalidOrganizers = [];
		resp.query.users.forEach( function ( user ) {
			// Note: the backend will perform a more thorough check than this. A user might be
			// considered valid here, but invalid in the backend (e.g., if they're blocked).
			if ( user.rights.indexOf( 'campaignevents-organize-events' ) < 0 ) {
				invalidOrganizers.push( user.name );
			}
		} );

		this.usersMultiselectFieldLayout.setErrors( [] );
		if ( invalidOrganizers.length ) {
			this.organizersField.items.forEach( function ( item ) {
				if ( invalidOrganizers.indexOf( item.data ) > -1 ) {
					// TODO This is a bit hacky because the widget is not configured
					// to display invalid tags. Ideally we would use toggleValid, but
					// then invalid cards would be lost.
					item.setFlags( { invalid: true } );
					item.$element.attr(
						'title',
						mw.msg(
							'campaignevents-edit-field-organizers-user-not-allowed',
							item.data
						)
					);
				}
			} );

			this.usersMultiselectFieldLayout.setErrors( [
				mw.msg(
					'campaignevents-edit-organizers-not-allowed',
					mw.language.convertNumber( invalidOrganizers.length ),
					mw.language.listToText( invalidOrganizers )
				)
			] );
		}
	};

	module.exports = new OrganizerSelectionFieldEnhancer();
}() );
