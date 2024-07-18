( function () {
	'use strict';

	function OrganizersLoader() {
		this.ORGANIZERS_BATCH_SIZE = 10;
		this.eventID = mw.config.get( 'wgCampaignEventsEventID' );
		this.lastOrganizerID = mw.config.get( 'wgCampaignEventsLastOrganizerID' );
		/* eslint-disable no-jquery/no-global-selector */
		this.$organizersList = $( '.ext-campaignevents-event-details-organizers-list' );
		this.$loadOrganizersLink = $( '.ext-campaignevents-event-details-load-organizers-link' );
		/* eslint-enable no-jquery/no-global-selector */
		this.installEventListeners();
	}

	OrganizersLoader.prototype.installEventListeners = function () {
		this.$loadOrganizersLink.on( 'click', this.loadMoreOrganizers.bind( this ) );
	};

	OrganizersLoader.prototype.loadMoreOrganizers = function () {
		var that = this;
		new mw.Rest().get(
			'/campaignevents/v0/event_registration/' + this.eventID + '/organizers',
			// eslint-disable-next-line camelcase
			{ last_organizer_id: this.lastOrganizerID }
		)
			.done( function ( data ) {
				for ( var i = 0; i < data.length && i < that.ORGANIZERS_BATCH_SIZE; i++ ) {
					var $userLink = $( '<a>' )
						.attr( 'href', data[ i ].user_page.path )
						.attr( 'title', data[ i ].user_page.title )
						.text( data[ i ].user_name )
						// The following classes are used here:
						// * mw-userLink
						// * new
						.attr( 'class', data[ i ].user_page.classes );

					var $listElement = $( '<li>' ).append( $userLink );
					that.$organizersList.append( $listElement );
					that.lastOrganizerID = data[ i ].organizer_id;
				}
				if ( data.length <= that.ORGANIZERS_BATCH_SIZE ) {
					// Assumption: the max number of results returned by the API is
					// larger than our batch size.
					// TODO: Rewrite when the endpoint has actual pagination.
					that.$loadOrganizersLink.hide();
				}
			} )
			.fail( function ( _err, errData ) {
				mw.log.error( errData.xhr.responseText || 'Unknown error' );
			} );
	};

	module.exports = new OrganizersLoader();
}() );
