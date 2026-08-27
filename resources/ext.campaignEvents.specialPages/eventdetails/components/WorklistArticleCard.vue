<template>
	<li class="ext-campaignevents-worklist-card">
		<!-- No `url`: the card is a plain element rather than a link, so the remove control can
			sit inside it and the title can carry the red-link class of its own. -->
		<cdx-card class="ext-campaignevents-worklist-card__card">
			<template #title>
				<!-- The server renders the link, so a page still to be created carries core's
					red-link class and an article on another wiki carries `external`, exactly as in
					the worklist table. A title the wiki cannot parse has no URL to link to, and an
					empty href would send the reader back to the page they are on. -->
				<a
					v-if="article.url"
					:class="article.classes"
					:href="article.url"
				>{{ article.title }}</a>
				<span v-else>{{ article.title }}</span>

				<!-- Positioned into the card's corner against the `position: relative` the Codex
					card already carries. It sits in this slot rather than a later one so that it
					is read straight after the article it removes. -->
				<cdx-button
					v-if="canRemove"
					class="ext-campaignevents-worklist-card__remove"
					action="destructive"
					weight="quiet"
					:aria-label="removeLabel"
					:title="removeLabel"
					@click="$emit( 'remove', article )"
				>
					<cdx-icon :icon="cdxIconTrash"></cdx-icon>
				</cdx-button>
			</template>
		</cdx-card>
	</li>
</template>

<script>
const { defineComponent } = require( 'vue' );
const { CdxButton, CdxCard, CdxIcon } = require( '../../../codex.js' );
const { cdxIconTrash } = require( '../../../icons.json' );

// @vue/component
module.exports = exports = defineComponent( {
	name: 'WorklistArticleCard',
	components: { CdxButton, CdxCard, CdxIcon },
	props: {
		article: {
			type: Object,
			required: true
		},
		canRemove: {
			type: Boolean,
			default: false
		}
	},
	emits: [ 'remove' ],
	setup() {
		return {
			removeLabel: mw.msg( 'campaignevents-worklist-table-remove-button-label' ),
			cdxIconTrash
		};
	}
} );
</script>
