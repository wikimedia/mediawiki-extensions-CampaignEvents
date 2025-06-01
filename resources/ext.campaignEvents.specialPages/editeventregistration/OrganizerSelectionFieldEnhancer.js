( function () {
	'use strict';

	function OrganizerSelectionFieldEnhancer() {
		this.isEventCreator = mw.config.get( 'wgCampaignEventsIsEventCreator' );
		this.eventCreatorUsername = mw.config.get( 'wgCampaignEventsEventCreatorUsername' );
		this.eventID = mw.config.get( 'wgCampaignEventsEventID' );
		this.knownInvalidOrganizers = mw.config.get( 'wgCampaignEventsInvalidOrganizers' );
		this.api = new mw.Api();
	}

	OrganizerSelectionFieldEnhancer.prototype.init = function ( $fieldElement ) {
		this.usersMultiselectFieldLayout = mw.htmlform.FieldLayout.static.infuse( $fieldElement );
		this.organizersField = this.usersMultiselectFieldLayout.fieldWidget;
		this.setInvalidOrganizers();

		this.organizersField.on( 'change', this.setInvalidOrganizers.bind( this ) );
		if ( ( !this.isEventCreator || !this.eventID ) && this.eventCreatorUsername !== null ) {
			const organizerItem = this.organizersField
				.findItemFromData( this.eventCreatorUsername );
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
		const organizers = this.organizersField.getValue();
		if ( !organizers.length ) {
			this.usersMultiselectFieldLayout.setErrors( [
				mw.msg( 'campaignevents-edit-no-organizers' )
			] );
			return;
		}

		this.api.abort();
		this.api.get( {
			action: 'query',
			list: 'users',
			ususers: organizers,
			usprop: 'rights'
		} )
			.then(
				this.setInvalidOrganizersFromResponse.bind( this ),
				( _err, errData ) => {
					if ( errData.xhr.status === 0 && errData.textStatus === 'abort' ) {
						// Aborted due to updated input, ignore.
						return;
					}
					mw.log.error( errData.xhr.responseText || 'Unknown error' );
				}
			);
	};

	OrganizerSelectionFieldEnhancer.prototype.setInvalidOrganizersFromResponse = function ( resp ) {
		const that = this;
		const invalidOrganizers = [];
		resp.query.users.forEach( ( user ) => {
			// Note: the backend will perform a more thorough check than just user rights. A user
			// might be/ considered valid here, but invalid in the backend (e.g., if they're
			// blocked). We try to mitigate this by having the server generate a list of known
			// invalid organizers when validating the field, then using that list here as an
			// extra source of information. This is also necessary to handle the case where
			// server and frontend disagree (see T327470#8742742).
			if (
				!user.rights.includes( 'campaignevents-organize-events' ) ||
				that.knownInvalidOrganizers.includes( user.name )
			) {
				invalidOrganizers.push( user.name );
			}
		} );

		this.usersMultiselectFieldLayout.setErrors( [] );
		if ( invalidOrganizers.length ) {
			this.organizersField.items.forEach( ( item ) => {
				if ( invalidOrganizers.includes( item.data ) ) {
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
