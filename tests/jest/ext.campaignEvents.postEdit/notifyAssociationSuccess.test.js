'use strict';

const notifyAssociationSuccess = require( '../../../resources/ext.campaignEvents.postEdit/notifyAssociationSuccess.js' );

describe( 'notifyAssociationSuccess', () => {
	it( 'shows a success notification linking to the event contributions', () => {
		mw.message = jest.fn( ( key, ...params ) => ( { key, params } ) );
		mw.util = { getUrl: jest.fn( ( page, query ) => `/wiki/${ page }?${ Object.keys( query )[ 0 ] }` ) };
		mw.notify = jest.fn();

		notifyAssociationSuccess( 7, 'Test event' );

		expect( mw.util.getUrl ).toHaveBeenCalledWith(
			'Special:EventDetails/7',
			{ tab: 'ContributionsPanel' }
		);
		expect( mw.notify ).toHaveBeenCalledWith(
			expect.objectContaining( {
				key: 'campaignevents-postedit-success-text',
				params: [ 'Test event', '/wiki/Special:EventDetails/7?tab' ]
			} ),
			expect.objectContaining( { type: 'success', autoHideSeconds: 'long' } )
		);
	} );
} );
