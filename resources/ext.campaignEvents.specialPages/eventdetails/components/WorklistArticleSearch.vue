<template>
	<cdx-lookup
		v-model:selected="selected"
		v-model:input-value="inputValue"
		class="ext-campaignevents-event-details-worklist-add-dialog-search"
		:menu-items="suggestions"
		:placeholder="placeholder"
		@input="onInput"
		@update:selected="onSelected"
		@keydown="onKeydown"
	>
		<!-- An article that does not exist yet is shown in the red-link colour, as the title
			widget this replaces did, so it is told apart from the articles found. -->
		<template #menu-item="{ menuItem }">
			<span
				:class="{
					'ext-campaignevents-event-details-worklist-add-dialog-search__new-title':
						menuItem.value === newTitle
				}"
			>{{ menuItem.label }}</span>
		</template>
	</cdx-lookup>
</template>

<script>
const { defineComponent, ref, nextTick, onUnmounted } = require( 'vue' );
const { CdxLookup } = require( '../../../codex.js' );
const useTitleSearch = require( '../composables/useTitleSearch.js' );

/**
 * Search for an article title. A title can be picked from the suggestions, or typed in full and
 * entered, including one for an article that does not exist yet. The input is cleared after each
 * pick, so that several articles can be picked one after the other.
 */
module.exports = exports = defineComponent( {
	name: 'WorklistArticleSearch',
	components: {
		CdxLookup
	},
	props: {
		/**
		 * API of the wiki to search; the current wiki when not given.
		 */
		api: {
			type: Object,
			default: null
		}
	},
	emits: [ 'choose' ],
	setup( props, { emit } ) {
		const { suggestions, newTitle, search, clear } = useTitleSearch(
			props.api || new mw.Api()
		);
		const selected = ref( null );
		const inputValue = ref( '' );
		const placeholder = mw.msg( 'campaignevents-event-details-worklist-add-dialog-search-placeholder' );

		// The dialog removes the search when it closes; a search still in flight is not needed.
		onUnmounted( clear );

		/**
		 * @param {string} value
		 */
		function onInput( value ) {
			// The lookup also reports the label of a picked item as input; that is not a query.
			if ( selected.value === null ) {
				search( value );
			}
		}

		// Set when a title is picked, so that an Enter the lookup has already acted on is not
		// acted on a second time.
		let picked = false;

		/**
		 * @param {string} title
		 */
		function choose( title ) {
			picked = true;
			emit( 'choose', title );
			clear();
			// After the lookup has put the picked label into the input, which it does on the
			// same tick.
			nextTick( () => {
				selected.value = null;
				inputValue.value = '';
			} );
		}

		/**
		 * @param {string|null} title
		 */
		function onSelected( title ) {
			if ( title !== null ) {
				choose( title );
			}
		}

		/**
		 * Enter picks what was typed when no suggestion is highlighted, which the lookup leaves
		 * alone, so that a title can be added without waiting for, or matching, a suggestion.
		 *
		 * @param {KeyboardEvent} event
		 */
		function onKeydown( event ) {
			if ( event.key !== 'Enter' || event.isComposing ) {
				return;
			}
			const typed = String( inputValue.value || '' ).trim();
			// The lookup handles the same key after this listener (it merges listeners passed to
			// it ahead of its own), picking the highlighted suggestion if there is one. By the
			// next tick it is known whether it did.
			picked = false;
			nextTick( () => {
				if ( !picked && typed ) {
					choose( typed );
				}
			} );
		}

		return {
			selected,
			inputValue,
			suggestions,
			newTitle,
			placeholder,
			onInput,
			onSelected,
			onKeydown
		};
	}
} );
</script>
