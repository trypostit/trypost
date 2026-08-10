import { echo } from '@laravel/echo-vue';
import { trans } from 'laravel-vue-i18n';
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

/** Matches PostAiGenerateController/StreamPostContent's channel format — grep `ai-gen.` if it changes. */
export const aiGenerationChannel = (userId: string, generationId: string): string => `user.${userId}.ai-gen.${generationId}`;

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
     * Await this before dispatching whatever broadcasts onto the channel —
     * events sent before the subscribe handshake completes are lost with no
     * replay. Resolves `true` once confirmed (or on timeout — ambiguous, so
     * we proceed optimistically), `false` only on a definitive subscribe error.
     */
    const subscribe = (channelName: string): Promise<boolean> => {
        unsubscribe();
        reset();
        status.value = 'streaming';
        subscribedName = channelName;

        return new Promise((resolve) => {
            // resolve() no-ops after the first call, so whichever fires first wins.
            setTimeout(() => resolve(true), SUBSCRIBE_CONFIRM_TIMEOUT_MS);

            echo().private(channelName)
                .subscribed(() => resolve(true))
                .error(() => resolve(false))
                .listen('.text_delta', (e: TextDeltaEvent) => {
                    text.value += e.delta ?? '';
                })
                .listen('.stream_end', () => {
                    status.value = 'completed';
                })
                .listen('.error', (e: ErrorEvent) => {
                    status.value = 'failed';
                    errorMessage.value = e?.message ?? trans('posts.ai.generate.errors.generation_failed');
                });
        });
    };

    onUnmounted(() => unsubscribe());

    return { text, status, errorMessage, subscribe, unsubscribe, reset };
};
