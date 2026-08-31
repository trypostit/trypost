<script setup lang="ts">
import { IconCircleCheck, IconCircleX } from '@tabler/icons-vue';
import { onMounted, ref } from 'vue';

import PopupLayout from '@/layouts/PopupLayout.vue';

const props = defineProps<{
    success: boolean;
    message: string;
    platform?: string | null;
}>();

const CLOSE_DELAY = 1500;

// A window not opened by script can't be closed via window.close() in modern
// browsers, so only promise an auto-close when we actually have an opener.
const canAutoClose = ref(false);

onMounted(() => {
    const opener = window.opener && !window.opener.closed ? window.opener : null;
    canAutoClose.value = opener !== null;

    if (opener) {
        try {
            opener.postMessage(
                {
                    type: 'social-oauth-callback',
                    success: props.success,
                    message: props.message,
                    platform: props.platform ?? null,
                },
                window.location.origin,
            );
        } catch {
            // Opener may be cross-origin or already gone; the timed close still applies.
        }

        window.setTimeout(() => window.close(), CLOSE_DELAY);
    }
});
</script>

<template>
    <PopupLayout :title="success ? $t('accounts.popup_callback.title_success') : $t('accounts.popup_callback.title_error')">
        <div class="flex flex-col items-center justify-center gap-3 py-16 text-center" role="status" aria-live="polite">
            <div
                class="flex h-14 w-14 items-center justify-center rounded-full"
                :class="
                    success
                        ? 'bg-green-100 text-green-600 dark:bg-green-900 dark:text-green-400'
                        : 'bg-red-100 text-red-600 dark:bg-red-900 dark:text-red-400'
                "
            >
                <IconCircleCheck v-if="success" class="h-7 w-7" aria-hidden="true" />
                <IconCircleX v-else class="h-7 w-7" aria-hidden="true" />
            </div>
            <p class="text-lg font-medium text-foreground">{{ message }}</p>
            <p class="text-sm text-muted-foreground">{{ canAutoClose ? $t('accounts.popup_callback.closing') : $t('accounts.popup_callback.manual_close') }}</p>
        </div>
    </PopupLayout>
</template>
