<template>
	<cdx-button
		v-show="buttonIsHidden"
		@click="openAddDialog"
	>
		{{ $i18n( 'campaignevents-event-details-contributions-add-dialog-open-button' ).text() }}
	</cdx-button>

	<cdx-dialog
		v-model:open="open"
		class="ext-campaignevents-eventdetails-add-contribution-dialog"
		:title="$i18n( 'campaignevents-event-details-contributions-add-dialog-title', eventName )
			.text()"
		:subtitle="$i18n( 'campaignevents-event-details-contributions-add-dialog-subtitle' ).text()"
		:use-close-button="true"
		:default-action="defaultAction"
		:primary-action="primaryAction"
		@default="open = false"
		@primary="onSubmit"
	>
		<cdx-message v-show="hasMessage" :type="messageType">
			{{ message }}
		</cdx-message>
		<cdx-field>
			<cdx-text-input
				v-model="inputValue"
				input-type="number"
			></cdx-text-input>
			<template #help-text>
				{{ $i18n( 'campaignevents-event-details-contributions-add-dialog-footer' ).text() }}
			</template>
		</cdx-field>
	</cdx-dialog>
</template>

<script>
const { defineComponent, ref } = require( 'vue' );
const { CdxButton, CdxDialog, CdxField, CdxTextInput, CdxMessage } = require( '../../../codex.js' );

module.exports = exports = defineComponent( {
	name: 'AddContributionDialog',
	components: {
		CdxButton,
		CdxDialog,
		CdxField,
		CdxTextInput,
		CdxMessage
	},
	setup() {
		const open = ref( false ),
			buttonIsHidden = mw.config.get( 'wgCampaignEventsCanAddContributions' ),
			eventName = mw.config.get( 'wgCampaignEventsEventName' ),
			hasMessage = ref( false ),
			message = ref( '' ),
			messageType = ref( 'error' ),
			defaultAction = {
				label: mw.msg( 'campaignevents-event-details-contributions-add-dialog-cancel' )
			},
			primaryAction = {
				label: mw.msg( 'campaignevents-event-details-contributions-add-dialog-submit' ),
				actionType: 'progressive'
			},
			inputValue = ref( '' );
		function openAddDialog() {
			open.value = true;
		}
		function onSubmit() {
			const curWikiID = mw.config.get( 'wgDBname' ),
				eventID = mw.config.get( 'wgCampaignEventsEventID' ),
				revId = inputValue.value;
			new mw.Rest().put(
				`/campaignevents/v0/event_registration/${ eventID }/edits/${ curWikiID }/${ revId }`,
				{ token: mw.user.tokens.get( 'csrfToken' ) }
			).then( () => {
				hasMessage.value = true;
				messageType.value = 'success';
				message.value = mw.msg( 'campaignevents-event-details-contributions-add-dialog-success' );
			}, ( err, errObj ) => {
				let errMessage = errObj.xhr.responseText;
				if ( errObj.xhr &&
					errObj.xhr.responseJSON &&
					errObj.xhr.responseJSON.messageTranslations
				) {
					errMessage = errObj.xhr.responseJSON.messageTranslations[
						mw.config.get( 'wgContentLanguage' )
					];
				} else if (
					errObj.xhr &&
					errObj.xhr.responseJSON &&
					errObj.xhr.responseJSON.message ) {
					errMessage = errObj.xhr.responseJSON.message;
				}

				hasMessage.value = true;
				messageType.value = 'error';
				message.value = errMessage;
			} );
		}

		return {
			open,
			eventName,
			defaultAction,
			primaryAction,
			inputValue,
			buttonIsHidden,
			message,
			messageType,
			hasMessage,
			onSubmit,
			openAddDialog
		};
	}
} );
</script>
