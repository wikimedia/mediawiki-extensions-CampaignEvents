<template>
	<cdx-dialog
		:open="open"
		:title="$i18n( 'campaignevents-eventdiscovery-dialog-title' ).text()"
		:use-close-button="true"
		:default-action="defaultAction"
		:primary-action="primaryAction"
		@update:open="$emit( 'update:open', $event )"
		@default="$emit( 'default' )"
		@primary="onVisitEvent"
	>
		<p>{{ description }}</p>
		<div v-if="isMultiple" class="ext-campaignevents-eventdiscovery-events">
			<cdx-card
				v-for="event in events"
				:key="event.id"
				:url="getEventUrl( event )"
				target="_blank"
				rel="noopener noreferrer"
				@click="onEventCardClick( event )"
			>
				<template #title>
					{{ event.name }}
				</template>
			</cdx-card>
		</div>
		<template #footer-text>
			<span v-i18n-html="footerMessageHTML"></span>
		</template>
	</cdx-dialog>
</template>

<script>
const { defineComponent, watch } = require( 'vue' );
const { CdxDialog, CdxCard } = require( './../codex.js' );
const worklistEventDiscoveryTracking = require( 'ext.campaignEvents.worklistEventDiscoveryTracking' );

module.exports = exports = defineComponent( {
	name: 'EventDiscoveryDialog',
	components: { CdxDialog, CdxCard },
	props: {
		open: {
			type: Boolean,
			required: true
		}
	},
	emits: [ 'default', 'update:open' ],
	setup( props, { emit } ) {
		const events = mw.config.get( 'wgCampaignEventsDiscoveryEvents' );
		const isMultiple = events.length > 1;

		const description = isMultiple ?
			mw.message(
				'campaignevents-eventdiscovery-dialog-description-multiple',
				mw.language.convertNumber( events.length )
			).text() :
			mw.message( 'campaignevents-eventdiscovery-dialog-description', events[ 0 ].name ).text();

		const defaultAction = {
			label: mw.message( 'campaignevents-eventdiscovery-dialog-skip-button' ).text()
		};

		const primaryAction = isMultiple ? null : {
			label: mw.message( 'campaignevents-eventdiscovery-dialog-visit-button' ).text(),
			actionType: 'progressive'
		};

		// Wikitext link so only the word "preferences" is a link (per design), rendered via
		// v-i18n-html. $1 is the event-discovery section of the preferences page.
		const footerMessageHTML = mw.message(
			'campaignevents-eventdiscovery-dialog-hide-in-preferences',
			'Special:Preferences#mw-prefsection-personal-campaignevents-event-discovery'
		);

		/**
		 * @param {Object} event
		 * @return {string}
		 */
		function getEventUrl( event ) {
			return worklistEventDiscoveryTracking.appendOriginToUrl( event.url );
		}

		function recordModalImpression() {
			const eventIDs = events.map( ( event ) => event.id );
			worklistEventDiscoveryTracking.recordPromotionModalInteraction(
				'impression',
				{
					eventIds: eventIDs
				}
			);
		}

		watch( () => props.open, ( isOpen ) => {
			if ( isOpen ) {
				recordModalImpression();
			}
		}, { immediate: true } );

		/**
		 * @param {Object} event
		 */
		function recordClick( event ) {
			worklistEventDiscoveryTracking.recordPromotionModalInteraction(
				'click',
				{
					eventId: event.id
				}
			);
		}

		function onVisitEvent() {
			const event = events[ 0 ];
			recordClick( event );
			window.open( getEventUrl( event ), '_blank', 'noopener,noreferrer' );
			emit( 'default' );
		}

		/**
		 * @param {Object} event
		 */
		function onEventCardClick( event ) {
			recordClick( event );
			emit( 'default' );
		}

		return {
			events,
			isMultiple,
			description,
			defaultAction,
			primaryAction,
			footerMessageHTML,
			getEventUrl,
			onVisitEvent,
			onEventCardClick
		};
	}
} );
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';

.ext-campaignevents-eventdiscovery-events {
	display: flex;
	flex-direction: column;
	gap: @spacing-50;
}
</style>
