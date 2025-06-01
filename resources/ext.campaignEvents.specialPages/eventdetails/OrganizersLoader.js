( function () {
	'use strict';

	function OrganizersLoader() {
		this.ORGANIZERS_BATCH_SIZE = 10;
		this.eventID = mw.config.get( 'wgCampaignEventsEventID' );
		this.lastOrganizerID = mw.config.get( 'wgCampaignEventsLastOrganizerID' );
		/* eslint-disable no-jquery/no-global-selector */
		this.$organizersList = $( '.ext-campaignevents-eventdetails-organizers-list' );
		this.$loadOrganizersLink = $( '.ext-campaignevents-eventdetails-load-organizers-link' );
		/* eslint-enable no-jquery/no-global-selector */
		this.installEventListeners();
	}

	OrganizersLoader.prototype.installEventListeners = function () {
		this.$loadOrganizersLink.on( 'click', this.loadMoreOrganizers.bind( this ) );
	};

	OrganizersLoader.prototype.loadMoreOrganizers = function () {
		const that = this;
		new mw.Rest().get(
			'/campaignevents/v0/event_registration/' + this.eventID + '/organizers',
			// eslint-disable-next-line camelcase
			{ last_organizer_id: this.lastOrganizerID }
		)
			.then(
				( data ) => {
					for ( let i = 0; i < data.length && i < that.ORGANIZERS_BATCH_SIZE; i++ ) {
						const $userLink = $( '<a>' )
							.attr( 'href', data[ i ].user_page.path )
							.attr( 'title', data[ i ].user_page.title )
							.text( data[ i ].user_name )
							// The following classes are used here:
							// * mw-userLink
							// * new
							.attr( 'class', data[ i ].user_page.classes );

						const $listElement = $( '<li>' ).append( $userLink );
						that.$organizersList.append( $listElement );
						that.lastOrganizerID = data[ i ].organizer_id;
					}
					if ( data.length <= that.ORGANIZERS_BATCH_SIZE ) {
						// Assumption: the max number of results returned by the API is
						// larger than our batch size.
						// TODO: Rewrite when the endpoint has actual pagination.
						that.$loadOrganizersLink.hide();
					}
				},
				( _err, errData ) => {
					mw.log.error( errData.xhr.responseText || 'Unknown error' );
				}
			);
	};

	module.exports = new OrganizersLoader();
}() );
