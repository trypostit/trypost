<script setup lang="ts">
import { computed } from 'vue';

import { useBelowBreakpoint } from '@/composables/useBreakpoint';

const props = withDefaults(defineProps<{ onPhones?: boolean }>(), {
    onPhones: false,
});

const compact = useBelowBreakpoint('md');
const inline = computed(() => compact.value && !props.onPhones);
</script>

<template>
    <Teleport defer to="#app-header-actions" :disabled="inline">
        <slot />
    </Teleport>
</template>
