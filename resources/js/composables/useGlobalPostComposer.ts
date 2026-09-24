import { shallowRef } from 'vue';

export interface PostComposerRequest {
    id: number;
    date: string | null;
    assistant: boolean;
}

let nextRequestId = 0;

export const postComposerRequest = shallowRef<PostComposerRequest | null>(null);

export const openPostComposer = (
    options: { date?: string | null; assistant?: boolean } = {},
): void => {
    if (typeof window === 'undefined') return;

    postComposerRequest.value = {
        id: ++nextRequestId,
        date: options.date ?? null,
        assistant: options.assistant ?? false,
    };
};

export const closePostComposer = (): void => {
    postComposerRequest.value = null;
};
