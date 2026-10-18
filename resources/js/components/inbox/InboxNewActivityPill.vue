<script setup lang="ts">
import { IconArrowUp } from '@tabler/icons-vue';

import { Button } from '@/components/ui/button';

withDefaults(
    defineProps<{
        label: string;
        testId: string;
        loading?: boolean;
    }>(),
    { loading: false },
);

const emit = defineEmits<{
    show: [];
}>();

const showNew = (): void => {
    emit('show');
};
</script>

<template>
    <div
        class="pointer-events-none sticky top-0 z-10 flex shrink-0 justify-center"
        data-testid="inbox-new-activity"
    >
        <Button
            size="sm"
            class="pointer-events-auto rounded-full px-3 shadow-sm"
            :loading="loading"
            :aria-busy="loading ? 'true' : undefined"
            :data-testid="testId"
            @click="showNew"
        >
            <IconArrowUp aria-hidden="true" />
            {{ label }}
        </Button>
    </div>
</template>
