'use strict';

const { mount } = require( '@vue/test-utils' );
const WorklistWikiSelector = require( '../../../../../resources/ext.campaignEvents.specialPages/eventdetails/components/WorklistWikiSelector.vue' );

const wikiOption = ( wiki ) => ( { value: wiki, label: wiki + ' name' } );
const wikiOptions = ( count ) => Array.from( { length: count }, ( _, i ) => wikiOption( 'wiki' + i ) );

const mountSelector = ( options, selected = options[ 0 ].value ) => mount( WorklistWikiSelector, {
	props: { options, selected }
} );

describe( 'WorklistWikiSelector', () => {
	it( 'uses a select for a few wikis', () => {
		const options = wikiOptions( 3 );
		const wrapper = mountSelector( options, 'wiki1' );
		const select = wrapper.getComponent( { name: 'CdxSelect' } );
		expect( select.props( 'menuItems' ) ).toEqual( options );
		expect( select.props( 'selected' ) ).toBe( 'wiki1' );
		expect( wrapper.findComponent( { name: 'CdxLookup' } ).exists() ).toBe( false );
	} );

	it( 'emits the wiki chosen in the select', async () => {
		const wrapper = mountSelector( wikiOptions( 3 ) );
		await wrapper.getComponent( { name: 'CdxSelect' } ).vm.$emit( 'update:selected', 'wiki2' );
		expect( wrapper.emitted( 'update:selected' ) ).toEqual( [ [ 'wiki2' ] ] );
	} );

	describe( 'with many wikis', () => {
		it( 'uses a lookup starting with the label of the selected wiki', () => {
			const wrapper = mountSelector( wikiOptions( 30 ), 'wiki3' );
			expect( wrapper.findComponent( { name: 'CdxSelect' } ).exists() ).toBe( false );
			const lookup = wrapper.getComponent( { name: 'CdxLookup' } );
			expect( lookup.props( 'selected' ) ).toBe( 'wiki3' );
			expect( lookup.props( 'inputValue' ) ).toBe( 'wiki3 name' );
		} );

		it( 'filters the suggestions by name or ID', async () => {
			const wrapper = mountSelector( wikiOptions( 30 ) );
			await wrapper.find( 'input' ).setValue( 'wiki2' );
			const suggested = wrapper.getComponent( { name: 'CdxLookup' } ).props( 'menuItems' )
				.map( ( option ) => option.value );
			expect( suggested ).toEqual( [
				'wiki2', 'wiki20', 'wiki21', 'wiki22', 'wiki23', 'wiki24', 'wiki25', 'wiki26',
				'wiki27', 'wiki28', 'wiki29'
			] );
		} );

		it( 'limits the number of suggestions', async () => {
			const wrapper = mountSelector( wikiOptions( 1000 ) );
			await wrapper.find( 'input' ).setValue( '' );
			expect( wrapper.getComponent( { name: 'CdxLookup' } ).props( 'menuItems' ) ).toHaveLength( 50 );
		} );

		it( 'clears the selection once the user types over it', async () => {
			const wrapper = mountSelector( wikiOptions( 30 ) );
			await wrapper.find( 'input' ).setValue( 'wiki1' );
			expect( wrapper.emitted( 'update:selected' ) ).toEqual( [ [ null ] ] );
		} );
	} );

	it( 'only passes the value and label of each wiki to the menu', () => {
		const options = [
			{ value: 'awiki', label: 'A', apiUrl: 'https://a.example/w/api.php' },
			{ value: 'bwiki', label: 'B', apiUrl: null }
		];
		const wrapper = mountSelector( options );
		expect( wrapper.getComponent( { name: 'CdxSelect' } ).props( 'menuItems' ) ).toEqual( [
			{ value: 'awiki', label: 'A' },
			{ value: 'bwiki', label: 'B' }
		] );
	} );
} );
