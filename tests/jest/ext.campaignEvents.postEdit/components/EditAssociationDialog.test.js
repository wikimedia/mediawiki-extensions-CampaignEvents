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

const mountDialog = ( configOverrides = {} ) => {
	const config = Object.assign( {}, defaultConfig, configOverrides );
	mw.config = {
		get: ( key ) => config[ key ]
	};
	return mount( EditAssociationDialog, { props: { open: true } } );
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

			const associationEvent = wrapper.emitted( 'associate-edit' );
			expect( associationEvent ).toHaveLength( 1 );
			expect( associationEvent[ 0 ] ).toEqual( [ defaultEventID ] );
		} );
	} );

	describe( 'with multiple events', () => {
		let wrapper;
		const chosenEventID = 42,
			firstEventName = 'Test event 10';
		const events = [
			{ id: 10, name: firstEventName },
			{ id: chosenEventID, name: 'Test event 42' },
			{ id: 73, name: 'Test event 73' }
		];

		beforeEach( () => {
			wrapper = mountDialog( {
				wgCampaignEventsEventsForAssociation: events
			} );
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

			const associationEvent = wrapper.emitted( 'associate-edit' );
			expect( associationEvent ).toHaveLength( 1 );
			expect( associationEvent[ 0 ] ).toEqual( [ chosenEventID ] );
		} );
	} );
} );
