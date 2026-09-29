'use strict';

const { mount } = require( '@vue/test-utils' );
const { CdxCard } = require( '@wikimedia/codex' );
const WorklistArticleCard = require( '../../../../../resources/ext.campaignEvents.specialPages/eventdetails/components/WorklistArticleCard.vue' );

const LOCAL_WIKI = 'my_wiki';

const article = ( overrides = {} ) => Object.assign( {
	wiki: LOCAL_WIKI,
	title: 'Bears',
	url: '/wiki/Bears',
	wikiName: 'My wiki',
	// Whether the article is on the wiki that answered the request, which the server decides.
	isLocal: true,
	classes: ''
}, overrides );

const mountCard = ( props = {} ) => {
	mw.config = { get: ( key ) => ( key === 'wgDBname' ? LOCAL_WIKI : null ) };
	return mount( WorklistArticleCard, {
		props: Object.assign( { article: article() }, props )
	} );
};

describe( 'WorklistArticleCard', () => {
	const VIEWS = '.ext-campaignevents-worklist-card__views';
	const THUMB = '.cdx-thumbnail';

	it( 'shows a description when the article has one', () => {
		expect( mountCard().find( '.cdx-card__text__description' ).exists() )
			.toBe( false );

		const described = mountCard( { description: 'Large omnivorous mammals' } );
		expect( described.get( '.cdx-card__text__description' ).text() )
			.toBe( 'Large omnivorous mammals' );
	} );

	it( 'keeps the thumbnail slot even without an image', () => {
		// Codex draws its placeholder icon, so the titles still line up down the column.
		const card = mountCard();
		expect( card.find( THUMB ).exists() ).toBe( true );
		expect( card.find( '.cdx-thumbnail__placeholder' ).exists() ).toBe( true );
	} );

	it( 'hands the lead image to the card', () => {
		const image = { url: 'https://example.org/beaver.jpg', width: 200, height: 200 };
		// Asserted on the prop rather than the rendered image: Codex loads it through an Image
		// object, and jsdom never fires the load that would swap out the placeholder.
		expect( mountCard( { image: image } ).getComponent( CdxCard ).props( 'thumbnail' ) )
			.toEqual( image );
	} );

	it( 'hides the view count when the wiki cannot say', () => {
		// The criteria are explicit: no view data means no count, and the card still loads.
		expect( mountCard().find( VIEWS ).exists() ).toBe( false );
	} );

	it.each( [
		[ 40, '40' ],
		[ 999, '999' ],
		[ 1000, '(campaignevents-event-details-worklist-card-views-thousands, 1)' ],
		[ 20437, '(campaignevents-event-details-worklist-card-views-thousands, 20)' ],
		[ 120000, '(campaignevents-event-details-worklist-card-views-thousands, 120)' ],
		[ 999499, '(campaignevents-event-details-worklist-card-views-thousands, 999)' ],
		// Rounds up into the next unit, so it reads as 1M rather than 1000k.
		[ 999500, '(campaignevents-event-details-worklist-card-views-millions, 1)' ],
		[ 999999, '(campaignevents-event-details-worklist-card-views-millions, 1)' ],
		[ 1000000, '(campaignevents-event-details-worklist-card-views-millions, 1)' ],
		[ 2400000, '(campaignevents-event-details-worklist-card-views-millions, 2)' ]
	] )( 'shortens %s past a thousand', ( count, shown ) => {
		const card = mountCard( { views: { count: count } } );
		expect( card.get( VIEWS ).text() ).toContain( shown );
	} );

	it( 'names the count with the icon, since the figure is shown bare', () => {
		// A screen reader would otherwise read "10" with nothing saying what ten counts.
		const icon = mountCard( { views: { count: 10 } } ).get( VIEWS ).get( 'svg' );
		expect( icon.attributes( 'aria-hidden' ) ).toBeUndefined();
	} );

	it( 'puts the icon before the figure, as the design has it', () => {
		const html = mountCard( { views: { count: 10 } } ).get( VIEWS ).html();
		expect( html.indexOf( 'svg' ) ).toBeLessThan( html.indexOf( '10' ) );
	} );

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
			article: article( { wiki: 'otherwiki', isLocal: false, classes: 'external' } )
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
	it( 'names the wiki only for an article from another wiki', () => {
		expect( mountCard().find( '.ext-campaignevents-worklist-card__wiki' ).exists() )
			.toBe( false );

		const foreign = mountCard( {
			article: article( { wiki: 'otherwiki', isLocal: false, wikiName: 'The other wiki' } )
		} );
		expect( foreign.get( '.ext-campaignevents-worklist-card__wiki' ).text() )
			.toBe( 'The other wiki' );
	} );

	it( 'shows when the article was added, once that has been fetched', () => {
		const wrapper = mountCard( {
			added: '14:32, 3 September 2026',
			addedAt: '2026-09-03T14:32:00Z'
		} );

		const time = wrapper.get( 'time.ext-campaignevents-worklist-card__added' );
		expect( time.attributes( 'datetime' ) ).toBe( '2026-09-03T14:32:00Z' );
		expect( time.text() ).toContain( '14:32, 3 September 2026' );
	} );

	it( 'shows no date until one has been fetched', () => {
		expect(
			mountCard().find( '.ext-campaignevents-worklist-card__added' ).exists()
		).toBe( false );
	} );

} );
