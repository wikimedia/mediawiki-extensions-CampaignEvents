'use strict';

const { mount } = require( '@vue/test-utils' );
const WorklistArticleCard = require( '../../../../../resources/ext.campaignEvents.specialPages/eventdetails/components/WorklistArticleCard.vue' );

const LOCAL_WIKI = 'my_wiki';

const article = ( overrides = {} ) => Object.assign( {
	wiki: LOCAL_WIKI,
	title: 'Bears',
	url: '/wiki/Bears',
	classes: ''
}, overrides );

const mountCard = ( props = {} ) => {
	mw.config = { get: ( key ) => ( key === 'wgDBname' ? LOCAL_WIKI : null ) };
	return mount( WorklistArticleCard, {
		props: Object.assign( { article: article() }, props )
	} );
};

describe( 'WorklistArticleCard', () => {
	it( 'links the title to the article', () => {
		const link = mountCard().get( '.cdx-card__text__title a' );
		expect( link.attributes( 'href' ) ).toBe( '/wiki/Bears' );
		expect( link.text() ).toBe( 'Bears' );
	} );

	it( 'applies the link classes the server rendered', () => {
		// A page still to be created comes back with core's red-link class on it.
		const wrapper = mountCard( { article: article( { classes: 'new' } ) } );
		expect(
			wrapper.get( '.cdx-card__text__title a' ).classes()
		).toContain( 'new' );
	} );

	it( 'marks an article on another wiki as an external link', () => {
		const wrapper = mountCard( {
			article: article( { wiki: 'otherwiki', classes: 'external' } )
		} );
		expect(
			wrapper.get( '.cdx-card__text__title a' ).classes()
		).toContain( 'external' );
	} );

	it( 'shows a title with no URL as plain text', () => {
		// The server sends an empty URL for a title the wiki cannot parse; linking it would send
		// the reader back to the page they are on.
		const wrapper = mountCard( { article: article( { url: '', exists: null } ) } );

		expect( wrapper.find( '.cdx-card__text__title a' ).exists() ).toBe( false );
		expect( wrapper.get( '.cdx-card__text__title' ).text() ).toBe( 'Bears' );
	} );

	it( 'shows no remove button unless the user may remove articles', () => {
		expect( mountCard().find( '.ext-campaignevents-worklist-card__remove' ).exists() )
			.toBe( false );
	} );

	it( 'emits the article to remove', async () => {
		const wrapper = mountCard( { canRemove: true } );
		await wrapper.get( '.ext-campaignevents-worklist-card__remove' ).trigger( 'click' );
		expect( wrapper.emitted( 'remove' )[ 0 ] ).toStrictEqual( [ article() ] );
	} );

	it( 'puts the remove button inside the card, beside the title', () => {
		// The card is not a link, so an interactive control can live inside it; it is positioned
		// into the corner against the card's own `position: relative`.
		const wrapper = mountCard( { canRemove: true } );

		expect(
			wrapper.get( '.cdx-card__text__title .ext-campaignevents-worklist-card__remove' ).exists()
		).toBe( true );
		// Nesting a button inside a link would be invalid, so the card must not be one.
		expect( wrapper.get( '.cdx-card' ).element.tagName ).not.toBe( 'A' );
	} );
} );
