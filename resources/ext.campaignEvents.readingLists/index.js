/**
 * CampaignEvents features on Special:Saved: an impact chip above the title of each card.
 *
 * ReadingListsHandler adds this module to the special page. All the code of the features is in
 * CampaignEvents. The ReadingLists extension has no code for them.
 *
 * ReadingLists shows its cards with a Vue app, after the page loads, and gives no event when
 * the cards are there. Thus this module watches the DOM of the page. It uses only selectors from
 * core and from the documented markup of the Codex Card, not the classes of ReadingLists. A
 * change to them is a breaking change in a Codex release, not a silent change in ReadingLists.
 *
 * Only pages of this wiki get signals. The card of a page on another wiki gets nothing, because
 * the browser cannot get the wiki ID of another host.
 */
const { createMwApp, h, ref, shallowRef, Teleport } = require( 'vue' );
const { ImpactChip, useQuality } = require( 'ext.campaignEvents.cardElements' );

/** The content of the page, from core. The module only loads on Special:Saved. */
const ROOT = '#mw-content-text';

/** A card: a Codex card with a URL is an `<a>` element. */
const CARD = 'a.cdx-card[href]';

/** The title of the card, in the Codex card. The chip goes above it. */
const CARD_TITLE = '.cdx-card__text__title';

/** The element that this module adds to a card, for the chip. */
const CHIP_HOST_CLASS = 'ext-campaignevents-readinglists-impact';

/** The REST path of the quality endpoint for any pages on one wiki. */
const QUALITY_PATH = '/campaignevents/v0/pages/quality';

/** Time to wait after the last DOM change, in ms, so that one render gives one update. */
const SETTLE_TIME = 100;

/**
 * The prefixed title of a page on this wiki, from the URL of the page.
 *
 * ReadingLists links each card to the canonical URL of the page. A page on another wiki has a
 * URL on another host, so this gives null for it.
 *
 * @param {string} href
 * @return {string|null} Null for a page on another wiki, or a URL that is not a page
 */
function localTitleFromUrl( href ) {
	const url = new URL( href, location.href );
	const hosts = [ location.host, new URL( mw.config.get( 'wgCanonicalServer' ), location.href ).host ];
	if ( !hosts.includes( url.host ) ) {
		return null;
	}
	let text = url.searchParams.get( 'title' );
	if ( !text ) {
		const prefix = mw.config.get( 'wgArticlePath' ).replace( '$1', '' );
		if ( !url.pathname.startsWith( prefix ) ) {
			return null;
		}
		try {
			text = decodeURIComponent( url.pathname.slice( prefix.length ) );
		} catch ( e ) {
			// A malformed escape sequence. Skip this card, not all of them.
			return null;
		}
	}
	const title = mw.Title.newFromText( text );
	return title ? title.getPrefixedText() : null;
}

/**
 * The cards on screen that are pages of this wiki, in DOM order.
 *
 * @param {HTMLElement} root
 * @return {Array<{link: HTMLElement, article: {wiki: string, title: string}}>}
 */
function readCards( root ) {
	const wiki = mw.config.get( 'wgWikiID' );
	const found = [];
	Array.from( root.querySelectorAll( CARD ) ).forEach( ( link ) => {
		const title = localTitleFromUrl( link.getAttribute( 'href' ) );
		if ( title ) {
			found.push( { link, article: { wiki, title } } );
		}
	} );
	return found;
}

/**
 * Keeps an impact chip above the title of each card that is a page of this wiki.
 *
 * There is one Vue app. It is mounted on an element that is never added to the page, and it
 * teleports a chip into an element that this module adds to each card. When a card goes away,
 * its teleport goes away from the list, and Vue removes its chip.
 *
 * @param {HTMLElement} root
 */
function init( root ) {
	const articles = ref( [] );
	const { qualityFor } = useQuality( QUALITY_PATH, articles );

	/**
	 * The cards that have a chip, in DOM order. Each card has the fields key, link, host and
	 * article.
	 */
	const cards = shallowRef( [] );

	/**
	 * The element of this module in each card, keyed on the card link.
	 *
	 * @type {WeakMap<HTMLElement, {key: number, host: HTMLElement}>}
	 */
	const hosts = new WeakMap();
	let nextKey = 0;

	// The render function reads qualityFor(), so a chip shows when its band arrives.
	// ImpactChip shows nothing when there is no band.
	createMwApp( {
		render: () => cards.value.map( ( card ) => h(
			Teleport,
			{ key: card.key, to: card.host },
			[ h( ImpactChip, {
				class: CHIP_HOST_CLASS + '__chip',
				band: qualityFor( card.article ).band
			} ) ]
		) )
	} ).mount( document.createElement( 'div' ) );

	/**
	 * The element of this module in the card, above the title. Makes it when necessary.
	 *
	 * @param {HTMLElement} link
	 * @return {{key: number, host: HTMLElement}|null} Null when the card has no title
	 */
	function hostFor( link ) {
		const title = link.querySelector( CARD_TITLE );
		if ( !title ) {
			// Not the card that this module knows. Add nothing.
			return null;
		}
		let entry = hosts.get( link );
		if ( !entry || !link.contains( entry.host ) ) {
			const host = document.createElement( 'span' );
			host.className = CHIP_HOST_CLASS;
			entry = { key: nextKey++, host };
			hosts.set( link, entry );
		}
		// ReadingLists can render the text of the card again. Put the element above the title
		// again. Change the DOM only when necessary, because each change calls update() again.
		if ( entry.host.nextSibling !== title ) {
			title.parentNode.insertBefore( entry.host, title );
		}
		return entry;
	}

	/**
	 * @param {Array<{key: number, article: Object}>} list
	 * @return {string} The same for two lists with the same cards and pages, in the same order
	 */
	const signature = ( list ) => list.map( ( card ) => card.key + '|' + card.article.title ).join( '\n' );

	function update() {
		const next = [];
		readCards( root ).forEach( ( { link, article } ) => {
			const entry = hostFor( link );
			if ( entry ) {
				next.push( { key: entry.key, link, host: entry.host, article } );
			}
		} );

		// Remove the element of a card that no longer gets a chip: for example, the card now
		// shows a page on another wiki.
		const links = new Set( next.map( ( card ) => card.link ) );
		cards.value.forEach( ( card ) => {
			if ( !links.has( card.link ) ) {
				card.host.remove();
				hosts.delete( card.link );
			}
		} );

		// Change the refs only when the cards change: the app also changes the DOM for other
		// things, for example a dialog or a menu.
		if ( signature( next ) !== signature( cards.value ) ) {
			cards.value = next;
		}
		const found = next.map( ( card ) => card.article );
		const titles = ( list ) => list.map( ( article ) => article.title ).join( '\n' );
		if ( found.length && titles( found ) !== titles( articles.value ) ) {
			articles.value = found;
		}
	}

	// ReadingLists adds the cards after its API requests. It also changes them when the reader
	// goes to another list, because the router changes the view without a page load. A card
	// can also show another page, with a new link. The chips also change the DOM, but then the
	// cards are the same, and update() changes nothing.
	new MutationObserver( mw.util.debounce( update, SETTLE_TIME ) ).observe( root, {
		childList: true,
		subtree: true,
		attributes: true,
		attributeFilter: [ 'href' ]
	} );
	update();
}

const contentRoot = document.querySelector( ROOT );
if ( contentRoot ) {
	init( contentRoot );
}

module.exports = { init };
