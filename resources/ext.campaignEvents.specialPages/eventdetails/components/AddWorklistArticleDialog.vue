<template>
	<cdx-button
		class="ext-campaignevents-event-details-worklist-add-button"
		:aria-label="$i18n( 'campaignevents-event-details-worklist-add-button-label' ).text()"
		action="progressive"
		weight="primary"
		@click="open = true"
	>
		<cdx-icon :icon="cdxIconAdd"></cdx-icon>
	</cdx-button>

	<cdx-dialog
		v-model:open="open"
		class="ext-campaignevents-event-details-worklist-add-dialog"
		:title="$i18n( 'campaignevents-event-details-worklist-add-dialog-title' ).text()"
		:use-close-button="true"
		:primary-action="primaryAction"
		@primary="onSubmit"
	>
		<cdx-field>
			<template #label>
				{{
					$i18n( 'campaignevents-event-details-worklist-add-dialog-article-label' ).text()
				}}
			</template>

			<!-- OOUI title-search input (Codex has none); selecting a result appends to the
				textarea below. -->
			<div
				ref="searchContainer"
				class="ext-campaignevents-event-details-worklist-add-dialog-search"
			></div>
		</cdx-field>
		<cdx-field>
			<template #help-text>
				{{
					$i18n( 'campaignevents-event-details-worklist-add-dialog-article-help' ).text()
				}}
			</template>
			<cdx-text-area
				v-model="articlesText"
				class="ext-campaignevents-event-details-worklist-add-dialog-textarea"
				:placeholder="
					$i18n( 'campaignevents-event-details-worklist-add-dialog-placeholder' ).text()
				"
			></cdx-text-area>
		</cdx-field>
	</cdx-dialog>
</template>

<script>
const { defineComponent, ref, watch, nextTick } = require( 'vue' );
const { CdxButton, CdxDialog, CdxField, CdxTextArea, CdxIcon } = require( '../../../codex.js' );
const { cdxIconAdd } = require( '../../../icons.json' );

module.exports = exports = defineComponent( {
	name: 'AddWorklistArticleDialog',
	components: {
		CdxButton,
		CdxDialog,
		CdxField,
		CdxTextArea,
		CdxIcon
	},
	setup() {
		const open = ref( false );
		// One article title per line; the user only enters the title (the wiki is the current one).
		const articlesText = ref( '' );
		const searchContainer = ref( null );
		const primaryAction = {
			label: mw.msg( 'campaignevents-event-details-worklist-add-dialog-submit' ),
			actionType: 'progressive'
		};
		const searchPlaceholder = mw.msg( 'campaignevents-event-details-worklist-add-dialog-search-placeholder' );

		let searchWidget = null;

		/**
		 * Append a title to the textarea list, one per line and de-duplicated.
		 *
		 * @param {string} value
		 */
		function appendTitle( value ) {
			const title = ( value || '' ).trim();
			if ( !title ) {
				return;
			}
			const lines = articlesText.value.split( '\n' )
				.map( ( line ) => line.trim() )
				.filter( ( line ) => line !== '' );
			if ( !lines.includes( title ) ) {
				lines.push( title );
			}
			articlesText.value = lines.join( '\n' );
		}

		/**
		 * Create the OOUI title-search widget on first use and (re)attach it to the dialog, which
		 * is only rendered in the DOM while open.
		 */
		function mountSearchWidget() {
			if ( !searchWidget ) {
				searchWidget = new mw.widgets.TitleInputWidget( {
					placeholder: searchPlaceholder,
					namespace: 0,
					// Allow searching for and adding pages that do not exist yet (shown as red
					// links in the menu), so participants can queue articles to create.
					addQueryInput: true,
					showMissing: true,
					validateTitle: false
				} );
				// Append the chosen title to the textarea and clear the search. The menu's public
				// 'choose' event fires both when a suggestion is picked and, because addQueryInput
				// is set, when the free-typed query row is chosen (click or Enter), so no separate
				// 'enter' handler is needed.
				searchWidget.lookupMenu.on( 'choose', ( item ) => {
					appendTitle( item.getData() );
					searchWidget.setValue( '' );
				} );
			}
			// The container ref lives inside CdxDialog, which teleports (and transitions) its
			// contents into the DOM only while open. This runs on the nextTick after `open`
			// becomes true, but the dialog's content isn't guaranteed to be rendered by then,
			// so guard against the ref not yet being set to avoid appending to null.
			if ( searchContainer.value ) {
				searchWidget.setValue( '' );
				searchContainer.value.appendChild( searchWidget.$element[ 0 ] );
			}
		}

		watch( open, ( isOpen ) => {
			if ( isOpen ) {
				nextTick( mountSearchWidget );
			}
		} );

		function onSubmit() {
			// TODO: saving (REST PUT) and the success toast are implemented in a follow-up branch.
			// For now the dialog just closes.
			open.value = false;
		}

		return {
			open,
			articlesText,
			searchContainer,
			primaryAction,
			onSubmit,
			cdxIconAdd
		};
	}
} );
</script>
