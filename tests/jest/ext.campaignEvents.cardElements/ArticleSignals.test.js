'use strict';

const { mount } = require( '@vue/test-utils' );
const { ArticleSignals } = require( 'ext.campaignEvents.cardElements' );

const SIGNALS = '.ext-campaignevents-article-signals';

const mountSignals = ( signals ) => mount( ArticleSignals, { props: { signals } } );

describe( 'ArticleSignals', () => {
	it( 'shows nothing when there are no signals', () => {
		// A card with nothing to report must not leave an empty line.
		expect( mountSignals( [] ).find( SIGNALS ).exists() ).toBe( false );
		expect( mount( ArticleSignals ).find( SIGNALS ).exists() ).toBe( false );
	} );

	it( 'shows a single signal', () => {
		expect( mountSignals( [ 'Needs images' ] ).get( SIGNALS ).text() ).toBe( 'Needs images' );
	} );

	it( 'shows only the most important signal, with no count of the others', () => {
		// T443285: the reader cannot open a list of the others, so the card does not count them.
		const signals = mountSignals( [ 'Needs more citations', 'Needs images', 'Needs an infobox' ] );
		expect( signals.get( SIGNALS ).text() ).toBe( 'Needs more citations' );
	} );

	it( 'shows the signals when they arrive', async () => {
		const wrapper = mountSignals( [] );
		await wrapper.setProps( { signals: [ 'Needs images' ] } );
		expect( wrapper.get( SIGNALS ).text() ).toBe( 'Needs images' );
	} );

	it( 'hides the lightbulb from screen readers', () => {
		// It tells the same as the words, so a screen reader would say everything twice.
		const signals = mountSignals( [ 'Needs images' ] );
		expect( signals.get( 'svg' ).attributes( 'aria-hidden' ) ).toBe( 'true' );
	} );

	it( 'lets the text run in its own direction', () => {
		// A signal is in the language of the reader, which can run the other way from the card.
		expect( mountSignals( [ 'Needs images' ] ).get( SIGNALS ).attributes( 'dir' ) )
			.toBe( 'auto' );
	} );

	it( 'has one root element', () => {
		expect( mountSignals( [ 'Needs images' ] ).element.classList.contains( SIGNALS.slice( 1 ) ) )
			.toBe( true );
	} );
} );
