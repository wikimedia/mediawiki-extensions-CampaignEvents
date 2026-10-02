<template>
	<cdx-field>
		<template #label>
			{{ $i18n( 'campaignevents-event-details-worklist-add-dialog-wiki-label' ).text() }}
		</template>
		<!-- A plain select is easier to use, but unusable for a long list such as all wikis
			in a farm, which gets a filterable lookup instead. -->
		<cdx-lookup
			v-if="useLookup"
			v-model:selected="selectedModel"
			v-model:input-value="inputValue"
			class="ext-campaignevents-event-details-worklist-add-dialog-wiki"
			:menu-items="filteredOptions"
			:placeholder="placeholder"
		></cdx-lookup>
		<cdx-select
			v-else
			v-model:selected="selectedModel"
			class="ext-campaignevents-event-details-worklist-add-dialog-wiki"
			:menu-items="menuItems"
		></cdx-select>
	</cdx-field>
</template>

<script>
const { defineComponent, ref, computed } = require( 'vue' );
const { CdxField, CdxLookup, CdxSelect } = require( '../../../codex.js' );

// Above this many wikis, a filterable lookup is used instead of a select.
const MAX_WIKIS_FOR_SELECT = 20;
// Most lookup suggestions shown at once, so a short query over all wikis in a large farm (about a
// thousand) does not render every one of them. Typing more narrows the list.
const MAX_WIKI_SUGGESTIONS = 50;

/**
 * Field for choosing one wiki out of the given options.
 */
module.exports = exports = defineComponent( {
	name: 'WorklistWikiSelector',
	components: {
		CdxField,
		CdxLookup,
		CdxSelect
	},
	props: {
		/**
		 * The wikis to choose from, as { value: wikiID, label: name }.
		 */
		options: {
			type: Array,
			required: true
		},
		/**
		 * ID of the selected wiki, or null when none is (the lookup clears it while the user
		 * types).
		 */
		selected: {
			type: [ String, null ],
			default: null
		}
	},
	emits: [ 'update:selected' ],
	setup( props, { emit } ) {
		const selectedModel = computed( {
			get: () => props.selected,
			set: ( value ) => emit( 'update:selected', value )
		} );
		const useLookup = props.options.length > MAX_WIKIS_FOR_SELECT;
		// Only what the menu shows: Codex renders any other property of an item as an HTML
		// attribute on it.
		const menuItems = props.options.map( ( { value, label } ) => ( { value, label } ) );

		const selectedOption = props.options.find( ( option ) => option.value === props.selected );
		const inputValue = ref( selectedOption ? selectedOption.label : '' );
		const filteredOptions = computed( () => {
			const query = inputValue.value.trim().toLowerCase();
			return menuItems
				.filter( ( option ) => option.label.toLowerCase().includes( query ) ||
					option.value.toLowerCase().includes( query ) )
				.slice( 0, MAX_WIKI_SUGGESTIONS );
		} );
		const placeholder = mw.msg( 'campaignevents-event-details-worklist-add-dialog-wiki-placeholder' );

		return {
			selectedModel,
			useLookup,
			menuItems,
			inputValue,
			filteredOptions,
			placeholder
		};
	}
} );
</script>
