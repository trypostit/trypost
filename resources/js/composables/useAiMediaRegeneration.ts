import { useHttp, usePage } from '@inertiajs/vue3';
import { echo } from '@laravel/echo-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onUnmounted, ref } from 'vue';
import { toast } from 'vue-sonner';

import { subscribePrivateChannel } from '@/composables/echo/subscribePrivateChannel';
import { extractErrorMessage } from '@/lib/httpError';
import { regenerateMedia as regeneratePostAiMedia } from '@/routes/app/posts/ai';
import type { MediaItem } from '@/types/media';

export interface RegenerationPayload {
    media: MediaItem;
    targetMediaId: string;
}

type RegenerationStatus = 'idle' | 'starting' | 'processing';

interface RegenerationEvent {
    media: MediaItem | null;
    error?: string | null;
}

interface UseAiMediaRegenerationOptions {
    postId: string;
    getMediaItem: () => MediaItem | null;
    onRegenerated: (payload: RegenerationPayload) => void;
    onCompleted?: () => void;
}

const aiMediaRegenerationChannel = (userId: string, regenerationId: string): string => `user.${userId}.ai-media.${regenerationId}`;

const REGENERATION_TIMEOUT_MS = 180_000;

export const useAiMediaRegeneration = (options: UseAiMediaRegenerationOptions) => {
    const page = usePage();
    const instruction = ref('');
    const errorMessage = ref<string | null>(null);
    const instructionError = ref<string | undefined>(undefined);
    const status = ref<RegenerationStatus>('idle');

    const httpRegenerate = useHttp<{ instruction: string; regeneration_id: string }>({
        instruction: '',
        regeneration_id: '',
    });

    let subscribedChannel: string | null = null;
    let regenerationTimeout: ReturnType<typeof setTimeout> | null = null;
    let unmounted = false;

    const isBusy = computed(() => status.value !== 'idle');
    const isProcessing = computed(() => status.value === 'processing');
    const normalizedInstruction = computed(() => instruction.value.trim());
    const canSubmit = computed(() => normalizedInstruction.value.length > 0 && !isBusy.value);

    const unsubscribe = () => {
        if (subscribedChannel) {
            echo().leave(`private-${subscribedChannel}`);
            subscribedChannel = null;
        }
    };

    const clearRegenerationTimeout = () => {
        if (regenerationTimeout !== null) {
            clearTimeout(regenerationTimeout);
            regenerationTimeout = null;
        }
    };

    const setIdleWithError = (message: string) => {
        errorMessage.value = message;
        status.value = 'idle';
    };

    const resetState = () => {
        instruction.value = '';
        errorMessage.value = null;
        instructionError.value = undefined;
        status.value = 'idle';
        clearRegenerationTimeout();
        unsubscribe();
    };

    const blockDismissWhileBusy = (event: Event) => {
        if (isBusy.value) {
            event.preventDefault();
        }
    };

    const handleRegenerationResult = (event: RegenerationEvent) => {
        clearRegenerationTimeout();

        const mediaItem = options.getMediaItem();
        if (event.error || !event.media || !mediaItem) {
            setIdleWithError(event.error ?? trans('posts.ai.image_regenerate.errors.unavailable'));
            unsubscribe();
            return;
        }

        toast.success(trans('posts.ai.image_regenerate.success'));

        options.onRegenerated({
            media: event.media,
            targetMediaId: mediaItem.id,
        });

        resetState();
        options.onCompleted?.();
    };

    const subscribe = (channel: string): Promise<boolean> => {
        subscribedChannel = channel;
        status.value = 'processing';

        clearRegenerationTimeout();
        regenerationTimeout = setTimeout(() => {
            setIdleWithError(trans('posts.ai.image_regenerate.errors.timeout'));
            unsubscribe();
        }, REGENERATION_TIMEOUT_MS);

        return subscribePrivateChannel(channel, (ch) => {
            ch.listen('.ai.media.regenerated', (event: RegenerationEvent) => handleRegenerationResult(event));
        });
    };

    const submit = async () => {
        const mediaItem = options.getMediaItem();
        const instructionValue = normalizedInstruction.value;

        if (!mediaItem) {
            return;
        }

        if (!instructionValue) {
            errorMessage.value = null;
            instructionError.value = trans('posts.ai.image_regenerate.errors.required');
            return;
        }

        errorMessage.value = null;
        instructionError.value = undefined;
        status.value = 'starting';

        const regenerationId = crypto.randomUUID();
        const channel = aiMediaRegenerationChannel(String(page.props.auth.user.id), regenerationId);

        try {
            const subscribed = await subscribe(channel);

            if (unmounted) {
                unsubscribe();
                return;
            }

            if (!subscribed) {
                throw new Error('Channel subscription failed');
            }

            httpRegenerate.instruction = instructionValue;
            httpRegenerate.regeneration_id = regenerationId;
            await httpRegenerate.post(regeneratePostAiMedia.url({ post: options.postId, mediaId: mediaItem.id }));

            if (httpRegenerate.hasErrors) {
                clearRegenerationTimeout();
                unsubscribe();
                status.value = 'idle';
                instructionError.value = httpRegenerate.errors.instruction ?? trans('posts.ai.image_regenerate.errors.start_failed');
                return;
            }
        } catch (error: unknown) {
            clearRegenerationTimeout();
            unsubscribe();
            setIdleWithError(extractErrorMessage(error) ?? trans('posts.ai.image_regenerate.errors.start_failed'));
        }
    };

    onUnmounted(() => {
        unmounted = true;
        clearRegenerationTimeout();
        unsubscribe();
    });

    return {
        instruction,
        errorMessage,
        instructionError,
        status,
        isBusy,
        isProcessing,
        canSubmit,
        submit,
        resetState,
        blockDismissWhileBusy,
    };
};
