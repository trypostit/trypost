<script setup lang="ts">
import { IconArrowRight } from '@tabler/icons-vue';

import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

defineProps<{
    disabled: boolean;
    testid: string;
    label?: string;
    processing?: boolean;
}>();

const emit = defineEmits<{
    (event: 'continue'): void;
}>();

const proceed = (): void => {
    emit('continue');
};
</script>

<template>
    <Button
        type="button"
        size="lg"
        class="w-full"
        :disabled="disabled"
        :data-testid="testid"
        @click="proceed"
    >
        {{ label ?? $t('welcome.continue') }}
        <Spinner v-if="processing" />
        <IconArrowRight v-else class="size-4 rtl:rotate-180" />
    </Button>
</template>
