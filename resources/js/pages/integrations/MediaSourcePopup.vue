<script setup lang="ts">
import { IconCircleCheck, IconCircleX } from '@tabler/icons-vue';
import { computed, onMounted, ref } from 'vue';

import PopupLayout from '@/layouts/PopupLayout.vue';
import { MEDIA_SOURCE_POPUP_CHANNEL } from '@/lib/mediaSources/popup';

const props = defineProps<{
    source: string;
    nonce: string | null;
    success: boolean;
    importId: string | null;
    replaces: string | null;
    message: string | null;
    pickerUri?: string | null;
}>();

const CLOSE_DELAY = 1000;

const canAutoClose = ref(false);

const SUCCESS_TEXT: Record<string, string> = {
    canva: 'integrations.media_source_popup.canva_return',
    google_drive: 'integrations.media_source_popup.google_drive_ready',
    google_photos: 'integrations.media_source_popup.google_photos_opening',
};

const successTitle = computed(() =>
    props.source === 'canva'
        ? 'integrations.media_source_popup.title_success'
        : 'integrations.media_source_popup.title_connected',
);

/**
 * A provider's pages may sever `window.opener`, so the result also goes out
 * on a same-origin BroadcastChannel and as a `storage` event; the composer
 * matches it by nonce.
 */
const postToOpener = (payload: Record<string, unknown>): void => {
    try {
        if (window.opener && !window.opener.closed) {
            window.opener.postMessage(payload, window.location.origin);
        }
    } catch {
        return;
    }
};

const report = (payload: Record<string, unknown>): void => {
    postToOpener(payload);

    if ('BroadcastChannel' in window) {
        const channel = new BroadcastChannel(MEDIA_SOURCE_POPUP_CHANNEL);
        channel.postMessage(payload);
        channel.close();
    }

    try {
        window.localStorage.setItem(
            MEDIA_SOURCE_POPUP_CHANNEL,
            JSON.stringify({ ...payload, sent_at: Date.now() }),
        );
        window.localStorage.removeItem(MEDIA_SOURCE_POPUP_CHANNEL);
    } catch {
        return;
    }
};

onMounted(() => {
    canAutoClose.value = props.nonce !== null || Boolean(window.opener);

    report({
        type: 'media-source-popup',
        source: props.source,
        nonce: props.nonce,
        success: props.success,
        import_id: props.importId,
        replaces: props.replaces,
        message: props.message,
    });

    if (props.success && props.pickerUri) {
        window.location.replace(`${props.pickerUri}/autoclose`);

        return;
    }

    if (canAutoClose.value) {
        window.setTimeout(() => window.close(), CLOSE_DELAY);
    }
});
</script>

<template>
    <PopupLayout
        :title="
            success
                ? $t(successTitle)
                : $t('integrations.media_source_popup.title_error')
        "
    >
        <div
            class="flex flex-col items-center justify-center gap-3 py-16 text-center"
            role="status"
            aria-live="polite"
            data-testid="media-source-popup"
            :data-success="success ? 'true' : 'false'"
        >
            <div
                class="flex h-14 w-14 items-center justify-center rounded-full"
                :class="
                    success
                        ? 'bg-success-subtle text-success-text'
                        : 'bg-critical-subtle text-destructive-text'
                "
            >
                <IconCircleCheck v-if="success" class="h-7 w-7" aria-hidden="true" />
                <IconCircleX v-else class="h-7 w-7" aria-hidden="true" />
            </div>
            <p class="text-lg font-medium text-foreground">
                {{ success ? $t(SUCCESS_TEXT[source] ?? '') : message }}
            </p>
            <p v-if="!(success && pickerUri)" class="text-sm text-muted-foreground">
                {{
                    canAutoClose
                        ? $t('integrations.media_source_popup.closing')
                        : $t('integrations.media_source_popup.manual_close')
                }}
            </p>
        </div>
    </PopupLayout>
</template>
