<template>
	<event-discovery-dialog
		v-model:open="isOpen"
		@default="isOpen = false"
	></event-discovery-dialog>
</template>

<script>
const { defineComponent, ref } = require( 'vue' );
const EventDiscoveryDialog = require( './EventDiscoveryDialog.vue' );

module.exports = exports = defineComponent( {
	name: 'EventDiscoveryApp',
	components: { EventDiscoveryDialog },
	setup() {
		const isOpen = ref( true );
		return { isOpen };
	}
} );
</script>
