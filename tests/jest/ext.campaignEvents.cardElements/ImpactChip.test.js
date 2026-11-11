'use strict';

const { mount } = require( '@vue/test-utils' );
const { ImpactChip } = require( 'ext.campaignEvents.cardElements' );

const CHIP = '.ext-campaignevents-impact-chip';

const mountChip = ( band ) => mount( ImpactChip, { props: { band } } );

describe( 'ImpactChip', () => {
	it.each( [ [ 'high' ], [ 'medium' ], [ 'low' ] ] )( 'names the %s band', ( band ) => {
		expect( mountChip( band ).get( CHIP ).text() )
			.toBe( '(campaignevents-card-impact-' + band + ')' );
	} );

	it( 'draws every band the same way', () => {
		// The words show the difference. A colour or a weight would rank one article above
		// another, on a page that lists work to do.
		const classesFor = ( band ) => mountChip( band ).get( CHIP ).classes().sort();
		expect( classesFor( 'high' ) ).toEqual( classesFor( 'low' ) );
		expect( classesFor( 'medium' ) ).toEqual( classesFor( 'low' ) );
	} );

	it( 'shows nothing when there is no band', () => {
		// The card gives no band while the band loads, when there is no score, or when the
		// request fails.
		expect( mountChip( null ).html() ).not.toContain( 'cdx-info-chip' );
		expect( mount( ImpactChip ).html() ).not.toContain( 'cdx-info-chip' );
	} );

	it( 'shows the band when it arrives', async () => {
		const wrapper = mountChip( null );
		await wrapper.setProps( { band: 'medium' } );
		expect( wrapper.get( CHIP ).text() )
			.toBe( '(campaignevents-card-impact-medium)' );
	} );

	it( 'shows nothing for a band it does not know', () => {
		expect( mountChip( 'huge' ).find( CHIP ).exists() ).toBe( false );
	} );
} );
