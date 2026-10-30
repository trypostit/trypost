<script setup lang="ts">
import { computed, useSlots } from 'vue';

/**
 * A translated sentence with markup in it, without v-html: the lang string
 * holds plain text and `:placeholders`, and each placeholder with a slot of
 * its name is rendered from that slot, so the markup (bold, italic, a link)
 * lives in the template and every value is escaped by Vue. The same idea as
 * vue-i18n's `<i18n-t>`, for laravel-vue-i18n's `:placeholder` syntax.
 *
 * <TransSlots :message="$t('calendar.reschedule.custom_time.description')">
 *     <template #from><strong>{{ fromTime }}</strong></template>
 * </TransSlots>
 */
const props = defineProps<{
    message: string;
}>();

const slots = useSlots();

const segments = computed(() =>
    props.message
        .split(/(:[A-Za-z_]+)/)
        .filter((segment) => segment !== '')
        .map((segment) => {
            const name = segment.slice(1);

            return segment.startsWith(':') && slots[name]
                ? { slot: name, text: '' }
                : { slot: null, text: segment };
        }),
);
</script>

<template>
    <span>
        <template v-for="(segment, index) in segments" :key="index">
            <slot v-if="segment.slot" :name="segment.slot" />
            <template v-else>{{ segment.text }}</template>
        </template>
    </span>
</template>
