import { echo } from '@laravel/echo-vue';
import { onUnmounted, ref } from 'vue';

interface TextDeltaEvent {
    delta: string;
}

interface ErrorEvent {
    message?: string;
}

export type AiStreamStatus = 'idle' | 'streaming' | 'completed' | 'failed';

/** How long to wait for the server to confirm the channel subscription before giving up and proceeding anyway. */
const SUBSCRIBE_CONFIRM_TIMEOUT_MS = 5000;

/**
 * Subscribe to a private channel for an in-flight AI generation.
 * Reactive state accumulates `.TextDelta` event deltas and transitions to
 * `completed` on `.StreamEnd` or `failed` on `.Error`.
 */
export const useAiStream = () => {
    const text = ref('');
    const status = ref<AiStreamStatus>('idle');
    const errorMessage = ref<string | null>(null);
    let subscribedName: string | null = null;

    const reset = () => {
        text.value = '';
        status.value = 'idle';
        errorMessage.value = null;
    };

    const unsubscribe = () => {
        if (subscribedName) {
            echo().leave(`private-${subscribedName}`);
        }
        subscribedName = null;
    };

    /**
     * Subscribe to the given channel and resolve once the server has confirmed
     * the subscription — callers should await this before triggering whatever
     * dispatches events onto the channel, otherwise events broadcast before
     * the subscription handshake completes are lost with no replay. Resolves
     * after a timeout regardless, so a stalled websocket doesn't block AI
     * generation entirely.
     */
    const subscribe = (channelName: string): Promise<void> => {
        unsubscribe();
        reset();
        status.value = 'streaming';
        subscribedName = channelName;

        return new Promise((resolve) => {
            let settled = false;
            const settle = () => {
                if (settled) return;
                settled = true;
                resolve();
            };

            setTimeout(settle, SUBSCRIBE_CONFIRM_TIMEOUT_MS);

            echo().private(channelName)
                .subscribed(settle)
                .listen('.text_delta', (e: TextDeltaEvent) => {
                    text.value += e.delta ?? '';
                })
                .listen('.stream_end', () => {
                    status.value = 'completed';
                })
                .listen('.error', (e: ErrorEvent) => {
                    status.value = 'failed';
                    errorMessage.value = e?.message ?? 'AI generation failed';
                });
        });
    };

    onUnmounted(() => unsubscribe());

    return { text, status, errorMessage, subscribe, unsubscribe, reset };
};
