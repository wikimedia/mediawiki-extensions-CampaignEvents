'use strict';

const { mount } = require( '@vue/test-utils' );
const { ViewCount } = require( 'ext.campaignEvents.cardElements' );

const COUNT = '.ext-campaignevents-view-count';

const mountCount = ( count ) => mount( ViewCount, { props: { count } } );

describe( 'ViewCount', () => {
	it.each( [
		[ 0, '0' ],
		[ 40, '40' ],
		[ 999, '999' ],
		[ 1000, '(campaignevents-card-views-thousands, 1)' ],
		[ 20437, '(campaignevents-card-views-thousands, 20)' ],
		[ 120000, '(campaignevents-card-views-thousands, 120)' ],
		[ 999499, '(campaignevents-card-views-thousands, 999)' ],
		// Rounds up to the next unit, so it shows as 1M, not 1000k.
		[ 999500, '(campaignevents-card-views-millions, 1)' ],
		[ 999600, '(campaignevents-card-views-millions, 1)' ],
		[ 999999, '(campaignevents-card-views-millions, 1)' ],
		[ 1000000, '(campaignevents-card-views-millions, 1)' ],
		[ 2400000, '(campaignevents-card-views-millions, 2)' ]
	] )( 'shows %s as %s', ( count, shown ) => {
		// The text starts with the label of the icon.
		expect( mountCount( count ).get( COUNT ).text() )
			.toBe( '(campaignevents-card-views-label)' + shown );
	} );

	it( 'shows the figure in the digits of the reader', () => {
		mw.language.convertNumber.mockClear();
		mountCount( 20437 );
		expect( mw.language.convertNumber ).toHaveBeenCalledWith( 20 );
	} );

	it( 'shows nothing when there is no count', () => {
		// The card gives no count while the count loads, when the wiki has no count, or when
		// the request fails.
		expect( mountCount( null ).html() ).not.toContain( COUNT.slice( 1 ) );
		expect( mount( ViewCount ).html() ).not.toContain( COUNT.slice( 1 ) );
	} );

	it( 'shows the count when it arrives', async () => {
		const wrapper = mountCount( null );
		await wrapper.setProps( { count: 40 } );
		expect( wrapper.get( COUNT ).text() ).toContain( '40' );
	} );

	it( 'names the count with the icon, because the figure has no words', () => {
		// Without a label, a screen reader reads "10" and nothing tells what it counts.
		const icon = mountCount( 10 ).get( 'svg' );
		expect( icon.attributes( 'aria-hidden' ) ).toBeUndefined();
	} );

	it( 'puts the icon before the figure, as in the design', () => {
		const html = mountCount( 10 ).get( COUNT ).html();
		expect( html.indexOf( 'svg' ) ).toBeLessThan( html.indexOf( '10' ) );
	} );

	it( 'has one root element', () => {
		expect( mountCount( 10 ).element.classList.contains( COUNT.slice( 1 ) ) ).toBe( true );
	} );
} );
