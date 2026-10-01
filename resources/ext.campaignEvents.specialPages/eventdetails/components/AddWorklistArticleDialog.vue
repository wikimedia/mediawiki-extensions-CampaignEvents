<template>
	<cdx-button
		class="ext-campaignevents-event-details-worklist-add-button"
		action="progressive"
		weight="primary"
		@click="open = true"
	>
		<cdx-icon :icon="cdxIconAdd"></cdx-icon>
		{{ $i18n( 'campaignevents-event-details-worklist-add-button-label' ).text() }}
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

			<!-- Picking a result appends it to the textarea below. -->
			<worklist-article-search @choose="appendTitle"></worklist-article-search>
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
const { defineComponent, ref, watch } = require( 'vue' );
const { CdxButton, CdxDialog, CdxField, CdxTextArea, CdxMessage, CdxIcon } = require( '../../../codex.js' );
const { cdxIconAdd } = require( '../../../icons.json' );
const WorklistArticleSearch = require( './WorklistArticleSearch.vue' );

module.exports = exports = defineComponent( {
	name: 'AddWorklistArticleDialog',
	components: {
		CdxButton,
		CdxDialog,
		CdxField,
		CdxTextArea,
		CdxMessage,
		CdxIcon,
		WorklistArticleSearch
	},
	emits: [ 'added' ],
	setup( props, { emit } ) {
		const open = ref( false );
		// One article title per line; the user only enters the title (the wiki is the current one).
		const articlesText = ref( '' );
		const hasMessage = ref( false );
		const message = ref( '' );
		const messageType = ref( 'error' );
		const primaryAction = {
			label: mw.msg( 'campaignevents-event-details-worklist-add-dialog-submit' ),
			actionType: 'progressive'
		};
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

		watch( open, ( isOpen ) => {
			if ( !isOpen ) {
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
			const bcp47Code = mw.language.bcp47( mw.config.get( 'wgContentLanguage' ) );
			const translated = json &&
				json.messageTranslations &&
				json.messageTranslations[ bcp47Code ];
			return mw.msg( 'campaignevents-event-details-worklist-add-dialog-error',
				translated || ( json && json.message ) || '' );
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
				emit( 'added' );
			}, ( err, errObj ) => {
				submitting = false;
				showError( restErrorText( errObj ) );
			} );
		}

		return {
			open,
			articlesText,
			hasMessage,
			message,
			messageType,
			primaryAction,
			appendTitle,
			onSubmit,
			cdxIconAdd
		};
	}
} );
</script>
