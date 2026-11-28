<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { IconEye } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import { Button } from '@/components/ui/button';
import { destroy as stopImpersonation } from '@/routes/app/impersonation';
import type { SharedData } from '@/types';

const page = usePage<SharedData>();
const impersonating = computed(() => page.props.impersonating);

const stopping = ref(false);

const stop = (): void => {
    router.delete(stopImpersonation.url(), {
        onStart: () => {
            stopping.value = true;
        },
        onFinish: () => {
            stopping.value = false;
        },
    });
};
</script>

<template>
    <div
        v-if="impersonating"
        class="flex shrink-0 flex-wrap items-center justify-center gap-x-3 gap-y-1.5 bg-foreground px-4 py-2 text-sm text-background"
        data-testid="impersonation-banner"
    >
        <span class="flex min-w-0 items-center gap-2">
            <IconEye class="size-4 shrink-0 opacity-70" />
            <span>{{
                $t('impersonation.banner', {
                    name: impersonating.name,
                    email: impersonating.email,
                })
            }}</span>
        </span>
        <Button
            size="sm"
            variant="secondary"
            class="h-7 rounded-full px-3"
            data-testid="impersonation-stop"
            :loading="stopping"
            @click="stop"
        >
            {{ $t('impersonation.stop') }}
        </Button>
    </div>
</template>
