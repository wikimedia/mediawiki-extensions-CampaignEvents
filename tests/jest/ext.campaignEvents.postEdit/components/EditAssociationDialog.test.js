'use strict';

const { mount } = require( '@vue/test-utils' );
const EditAssociationDialog = require( '../../../../resources/ext.campaignEvents.postEdit/components/EditAssociationDialog.vue' );

const defaultEventID = 1234,
	defaultEventTitle = 'Test event 1234';
const defaultConfig = {
	wgCampaignEventsEventsForAssociation: [
		{ id: defaultEventID, name: defaultEventTitle }
	]
};

/**
 * Minimal v-i18n-html for Jest: production passes mw.Message; tests mock mw.message().parse().
 */
const i18nHtmlDirective = {
	mounted( el, binding ) {
		const value = binding.value;
		if ( value && typeof value.parse === 'function' ) {
			el.innerHTML = value.parse();
		}
	},
	updated( el, binding ) {
		const value = binding.value;
		if ( value && typeof value.parse === 'function' ) {
			el.innerHTML = value.parse();
		} else {
			el.innerHTML = '';
		}
	}
};

const mountDialog = ( configOverrides = {} ) => {
	const config = Object.assign( {}, defaultConfig, configOverrides );
	mw.config = {
		get: ( key ) => config[ key ]
	};
	mw.message = jest.fn( ( key, ...params ) => ( {
		parse: jest.fn( () => params.length > 0 ? `(${ key }, ${ params.join( ', ' ) })` : `(${ key })` ),
		text: jest.fn( () => `(${ key })` )
	} ) );
	return mount( EditAssociationDialog, {
		props: { open: true },
		global: {
			directives: {
				'i18n-html': i18nHtmlDirective
			}
		}
	} );
};

describe( 'EditAssociationDialog', () => {
	it( 'exists', () => {
		const wrapper = mountDialog();
		expect( wrapper.exists() ).toBe( true );
	} );
	it( 'throws when there are no events', () => {
		expect( () => {
			mountDialog( { wgCampaignEventsEventsForAssociation: [] } );
		} ).toThrow( 'Dialog should not be created when there are no events' );
	} );

	describe( 'with a single event', () => {
		it( 'has the correct title and introductory paragraph', () => {
			const wrapper = mountDialog();
			expect( wrapper.find( '.cdx-dialog__header__title' ).html() )
				.toContain( `(campaignevents-postedit-dialog-title-single, ${ defaultEventTitle })` );
			expect( wrapper.find( '.cdx-dialog__body' ).html() )
				.toContain( `(campaignevents-postedit-dialog-intro-single, ${ defaultEventTitle })` );
		} );
		it( 'does not contain the event selector', () => {
			const wrapper = mountDialog();
			expect( () => wrapper.getComponent( { name: 'CdxSelect' } ) ).toThrowError();
		} );
		it( 'sets the event as selected by default', () => {
			const wrapper = mountDialog(),
				cdxDialog = wrapper.getComponent( { name: 'CdxDialog' } );
			cdxDialog.vm.$emit( 'primary' );

			const associationEvents = wrapper.emitted( 'associate-edit' );
			expect( associationEvents ).toHaveLength( 1 );
			expect( associationEvents[ 0 ] ).toEqual( [ defaultEventID, defaultEventTitle ] );
		} );
		it( 'shows the event preferences footer link', () => {
			const wrapper = mountDialog();
			const footer = wrapper.find( '.cdx-dialog__footer' );
			expect( footer.html() ).toContain(
				'campaignevents-postedit-dialog-hide-associate-edit-dialog-in-event-preferences'
			);
			expect( footer.html() ).toContain( `Special:RegisterForEvent/${ defaultEventID }` );
		} );
	} );

	describe( 'with multiple events', () => {
		let wrapper;
		const chosenEventID = 42,
			chosenEventName = 'Test event 42',
			firstEventName = 'Test event 10';
		const events = [
			{ id: 10, name: firstEventName },
			{ id: chosenEventID, name: chosenEventName },
			{ id: 73, name: 'Test event 73' }
		];

		beforeEach( () => {
			wrapper = mountDialog( {
				wgCampaignEventsEventsForAssociation: events
			} );
		} );

		it( 'shows the before-select footer message until an event is selected', () => {
			expect( wrapper.vm.selectedEvent ).toBe( null );
			const footer = wrapper.find( '.cdx-dialog__footer' );
			expect( footer.html() ).toContain(
				'campaignevents-postedit-dialog-hide-associate-edit-dialog-before-select'
			);
		} );

		it( 'updates the footer after selecting an event', async () => {
			const selector = wrapper.getComponent( { name: 'CdxSelect' } );
			selector.vm.$emit( 'update:selected', chosenEventID );
			await wrapper.vm.$nextTick();

			const footer = wrapper.find( '.cdx-dialog__footer' );
			expect( footer.html() ).toContain(
				'campaignevents-postedit-dialog-hide-associate-edit-dialog-in-event-preferences'
			);
			expect( footer.html() ).toContain( `Special:RegisterForEvent/${ chosenEventID }` );
		} );

		it( 'updates the footer message when changing the selected event', async () => {
			const selector = wrapper.getComponent( { name: 'CdxSelect' } );
			selector.vm.$emit( 'update:selected', chosenEventID );
			await wrapper.vm.$nextTick();

			const footerAfterFirst = wrapper.find( '.cdx-dialog__footer' );
			expect( footerAfterFirst.html() ).toContain( `Special:RegisterForEvent/${ chosenEventID }` );

			selector.vm.$emit( 'update:selected', 73 );
			await wrapper.vm.$nextTick();

			const footerAfterSecond = wrapper.find( '.cdx-dialog__footer' );
			expect( footerAfterSecond.html() ).toContain( 'Special:RegisterForEvent/73' );
		} );

		it( 'has the correct title and introductory paragraph', () => {
			expect( wrapper.find( '.cdx-dialog__header__title' ).html() )
				.toContain( '(campaignevents-postedit-dialog-title-multiple)' );
			expect( wrapper.find( '.cdx-dialog__body' ).html() )
				.toContain( '(campaignevents-postedit-dialog-intro-multiple)' );
		} );
		it( 'contains the event selector', () => {
			const selector = wrapper.getComponent( { name: 'CdxSelect' } );

			expect( selector.exists() ).toBe( true );
			const firstOption = selector.get( '.cdx-menu-item__text__label' );
			expect( firstOption.html() ).toContain( firstEventName );
		} );
		it( 'lets users choose an event', () => {
			const cdxDialog = wrapper.getComponent( { name: 'CdxDialog' } ),
				selector = wrapper.getComponent( { name: 'CdxSelect' } );

			selector.vm.$emit( 'update:selected', chosenEventID );
			cdxDialog.vm.$emit( 'primary' );

			const associationEvents = wrapper.emitted( 'associate-edit' );
			expect( associationEvents ).toHaveLength( 1 );
			expect( associationEvents[ 0 ] ).toEqual( [ chosenEventID, chosenEventName ] );
		} );
	} );
} );
