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

		<cdx-message
			v-if="hasMessage"
			class="ext-campaignevents-event-details-worklist-add-dialog-message"
			:type="messageType"
		>
			{{ message }}
		</cdx-message>
	</cdx-dialog>
</template>

<script>
const { defineComponent, ref, watch, nextTick } = require( 'vue' );
const { CdxButton, CdxDialog, CdxField, CdxTextArea, CdxMessage, CdxIcon } = require( '../../../codex.js' );
const { cdxIconAdd } = require( '../../../icons.json' );

module.exports = exports = defineComponent( {
	name: 'AddWorklistArticleDialog',
	components: {
		CdxButton,
		CdxDialog,
		CdxField,
		CdxTextArea,
		CdxMessage,
		CdxIcon
	},
	setup() {
		const open = ref( false );
		// One article title per line; the user only enters the title (the wiki is the current one).
		const articlesText = ref( '' );
		const searchContainer = ref( null );
		const hasMessage = ref( false );
		const message = ref( '' );
		const messageType = ref( 'error' );
		const primaryAction = {
			label: mw.msg( 'campaignevents-event-details-worklist-add-dialog-submit' ),
			actionType: 'progressive'
		};
		const searchPlaceholder = mw.msg( 'campaignevents-event-details-worklist-add-dialog-search-placeholder' );

		let searchWidget = null;
		let submitting = false;

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
			const lines = getArticleTitles();
			if ( !lines.includes( title ) ) {
				lines.push( title );
			}
			articlesText.value = lines.join( '\n' );
		}

		/**
		 * @return {string[]} The trimmed, non-empty article titles entered in the textarea.
		 */
		function getArticleTitles() {
			return articlesText.value.split( '\n' )
				.map( ( line ) => line.trim() )
				.filter( ( line ) => line !== '' );
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
			} else {
				hasMessage.value = false;
			}
		} );

		/**
		 * @param {string} text
		 */
		function showError( text ) {
			messageType.value = 'error';
			message.value = text;
			hasMessage.value = true;
		}

		/**
		 * Extract a human-readable error from a failed mw.Rest() request.
		 *
		 * @param {Object} errObj
		 * @return {string}
		 */
		function restErrorText( errObj ) {
			const json = errObj && errObj.xhr && errObj.xhr.responseJSON;
			return mw.msg( 'campaignevents-event-details-worklist-add-dialog-error',
				json.messageTranslations[ mw.config.get( 'wgContentLanguage' ) ] ||
				json.message );
		}

		/**
		 * Save the given titles (all on the current wiki) to the worklist.
		 *
		 * @param {string[]} titles
		 * @return {jQuery.Promise}
		 */
		function saveArticles( titles ) {
			const worklistPage = mw.config.get( 'wgCampaignEventsWorklistPagePrefixedText' );
			const wiki = mw.config.get( 'wgDBname' );
			// The worklist pages endpoint takes a delta, so this is a PATCH. mw.Rest has no
			// patch() helper, so call ajax() with the PATCH verb directly.
			// The worklist page may be on another wiki; when it is, the server passes that
			// wiki's rest.php URL and we target it via mw.ForeignRest (else local mw.Rest).
			const foreignRestUrl = mw.config.get( 'wgCampaignEventsWorklistWikiRestUrl' );
			const api = foreignRestUrl ? new mw.ForeignRest( foreignRestUrl ) : new mw.Rest();
			return api.ajax(
				'/campaignevents/v0/worklist/' + encodeURIComponent( worklistPage ) + '/pages',
				{
					type: 'PATCH',
					headers: { 'content-type': 'application/json' },
					data: JSON.stringify( {
						add: { [ wiki ]: titles },
						token: mw.user.tokens.get( 'csrfToken' )
					} )
				}
			);
		}

		function onSubmit() {
			hasMessage.value = false;
			if ( submitting ) {
				return;
			}
			const titles = getArticleTitles();
			if ( !titles.length ) {
				return;
			}
			submitting = true;

			// Non-existent pages are allowed on purpose (participants may create them during the
			// event), so the titles are saved without an existence check.
			saveArticles( titles ).then( () => {
				mw.notify(
					mw.msg( 'campaignevents-event-details-worklist-add-dialog-success', mw.language.convertNumber( titles.length ) ),
					{ type: 'success' }
				);
				submitting = false;
				articlesText.value = '';
				open.value = false;
			}, ( err, errObj ) => {
				submitting = false;
				showError( restErrorText( errObj ) );
			} );
		}

		return {
			open,
			articlesText,
			searchContainer,
			hasMessage,
			message,
			messageType,
			primaryAction,
			onSubmit,
			cdxIconAdd
		};
	}
} );
</script>
