import { router } from '@inertiajs/vue3';
import { echo } from '@laravel/echo-vue';
import { computed, ref } from 'vue';

import { edit as editPostRoute } from '@/routes/app/posts';

/**
 * Global state for in-flight AI post generations.
 *
 * Generation runs on the backend (the `StreamPostCreation` job) and broadcasts
 * `.ai.creation.completed` on the private `user.{id}.ai-creation.{creationId}`
 * channel. This composable is the SOLE OWNER of that channel subscription, so
 * the "AI is generating…" notice persists across navigation (module-level state,
 * SPA) and survives a hard reload via `sessionStorage`.
 */

export interface AiGeneration {
    /** creationId — identifies the generation. */
    id: string;
    /** Full private channel name (without the `private-` prefix). */
    channel: string;
    imageCount: number;
    /** epoch ms — used by the safety timeout and by hydration. */
    startedAt: number;
    status: 'loading' | 'done' | 'error';
    postId?: string;
    error?: string;
}

const STORAGE_KEY = 'ai-generations';
/** After this, if nothing arrived, the bar disappears on its own (avoids getting stuck). */
const MAX_LIFETIME_MS = 6 * 60 * 1000;
/** How long the "done"/"error" state stays visible before disappearing. */
const DONE_TTL_MS = 12 * 1000;

const generations = ref<AiGeneration[]>([]);
const subscribed = new Set<string>();
const timers = new Map<string, ReturnType<typeof setTimeout>>();
let hydrated = false;

const persist = (): void => {
    try {
        sessionStorage.setItem(STORAGE_KEY, JSON.stringify(generations.value));
    } catch {
        /* sessionStorage unavailable — carry on without persisting */
    }
};

const clearTimer = (id: string): void => {
    const timer = timers.get(id);
    if (timer) {
        clearTimeout(timer);
        timers.delete(id);
    }
};

const leaveChannel = (gen: AiGeneration): void => {
    if (subscribed.has(gen.id)) {
        echo().leave(`private-${gen.channel}`);
        subscribed.delete(gen.id);
    }
};

const remove = (id: string): void => {
    const gen = generations.value.find((g) => g.id === id);
    if (gen) {
        leaveChannel(gen);
    }
    clearTimer(id);
    generations.value = generations.value.filter((g) => g.id !== id);
    persist();
};

const complete = (id: string, postId?: string, error?: string): void => {
    const gen = generations.value.find((g) => g.id === id);
    if (!gen) {
        return;
    }
    leaveChannel(gen);
    clearTimer(id);
    if (error || !postId) {
        gen.status = 'error';
        gen.error = error ?? '';
    } else {
        gen.status = 'done';
        gen.postId = postId;
    }
    persist();
    // The terminal state is transient: it disappears on its own after the TTL.
    timers.set(id, setTimeout(() => remove(id), DONE_TTL_MS));
};

const subscribe = (gen: AiGeneration): void => {
    if (subscribed.has(gen.id)) {
        return;
    }
    subscribed.add(gen.id);
    echo()
        .private(gen.channel)
        .listen('.ai.creation.completed', (e: { post_id?: string; error?: string }) => {
            complete(gen.id, e.post_id, e.error);
        });
    // Safety: if the event never arrives, don't leave the bar stuck forever.
    // Rebased on the real startedAt (hydrated generations already spent part of the time).
    const remaining = Math.max(0, MAX_LIFETIME_MS - (Date.now() - gen.startedAt));
    timers.set(gen.id, setTimeout(() => remove(gen.id), remaining));
};

const track = (input: { id: string; channel: string; imageCount?: number; startedAt?: number }): void => {
    if (generations.value.some((g) => g.id === input.id)) {
        return;
    }
    const gen: AiGeneration = {
        id: input.id,
        channel: input.channel,
        imageCount: input.imageCount ?? 0,
        startedAt: input.startedAt ?? Date.now(),
        status: 'loading',
    };
    generations.value = [...generations.value, gen];
    subscribe(gen);
    persist();
};

/** Re-subscribe to still-running generations after a hard reload. */
const hydrate = (): void => {
    if (hydrated) {
        return;
    }
    hydrated = true;
    let stored: AiGeneration[] = [];
    try {
        stored = JSON.parse(sessionStorage.getItem(STORAGE_KEY) ?? '[]') as AiGeneration[];
    } catch {
        stored = [];
    }
    const now = Date.now();
    stored.forEach((g) => {
        if (g.status === 'loading' && now - g.startedAt < MAX_LIFETIME_MS) {
            track({ id: g.id, channel: g.channel, imageCount: g.imageCount, startedAt: g.startedAt });
        }
    });
    persist();
};

const openPost = (gen: AiGeneration): void => {
    if (gen.postId) {
        router.visit(editPostRoute(gen.postId).url);
    }
    remove(gen.id);
};

const loadingCount = computed(() => generations.value.filter((g) => g.status === 'loading').length);
const isGenerating = computed(() => loadingCount.value > 0);
const doneGeneration = computed(() => generations.value.find((g) => g.status === 'done') ?? null);
const errorGeneration = computed(() => generations.value.find((g) => g.status === 'error') ?? null);

const find = (id: string) => computed(() => generations.value.find((g) => g.id === id) ?? null);

export const useAiGeneration = () => ({
    generations,
    isGenerating,
    loadingCount,
    doneGeneration,
    errorGeneration,
    track,
    remove,
    dismiss: remove,
    openPost,
    hydrate,
    find,
});
