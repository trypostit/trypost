<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { IconCircleX } from '@tabler/icons-vue';
import { onMounted, ref } from 'vue';

import PopupLayout from '@/layouts/PopupLayout.vue';
import { channels } from '@/routes/app/workspace';

const props = defineProps<{
    success: boolean;
    message?: string | null;
    platform?: string | null;
    accountId?: string | null;
    created?: boolean;
}>();

const ERROR_CLOSE_DELAY = 1500;

// A window not opened by script can't be closed via window.close() in modern
// browsers, so only promise an auto-close when we actually have an opener.
const canAutoClose = ref(false);

onMounted(() => {
    const opener = window.opener && !window.opener.closed ? window.opener : null;
    canAutoClose.value = opener !== null;

    if (!opener) {
        if (props.success) {
            router.visit(channels(), { replace: true });
        }

        return;
    }

    try {
        opener.postMessage(
            {
                type: 'social-oauth-callback',
                success: props.success,
                message: props.message ?? '',
                platform: props.platform ?? null,
                account_id: props.accountId ?? null,
                created: props.created ?? false,
            },
            window.location.origin,
        );
    } catch {
        // Opener may be cross-origin or already gone; the close still applies.
    }

    if (props.success) {
        window.close();
        return;
    }

    window.setTimeout(() => window.close(), ERROR_CLOSE_DELAY);
});
</script>

<template>
    <div data-testid="popup-callback">
        <PopupLayout
            v-if="!success"
            :title="$t('accounts.popup_callback.title_error')"
        >
            <div
                class="flex flex-col items-center justify-center gap-3 py-16 text-center"
                role="status"
                aria-live="polite"
                data-testid="popup-callback-error"
            >
                <div
                    class="flex h-14 w-14 items-center justify-center rounded-full bg-critical-subtle text-destructive-text"
                >
                    <IconCircleX class="h-7 w-7" aria-hidden="true" />
                </div>
                <p class="text-lg font-medium text-foreground">{{ message }}</p>
                <p class="text-sm text-muted-foreground">{{ canAutoClose ? $t('accounts.popup_callback.closing') : $t('accounts.popup_callback.manual_close') }}</p>
            </div>
        </PopupLayout>
    </div>
</template>
