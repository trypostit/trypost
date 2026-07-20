import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Unit tests for the global AI generation state.
 *
 * The composable owns module-level state, so every test re-imports it after
 * `vi.resetModules()` to get a clean singleton. The `.ai.creation.completed`
 * listener registered per channel is captured by the Echo mock, which lets a
 * test deliver a completion exactly the way the server does.
 */

const listeners = new Map<
    string,
    (payload: { post_id?: string; error?: string }) => void
>();
const visited: string[] = [];

vi.mock('@laravel/echo-vue', () => ({
    echo: () => ({
        private: (channel: string) => ({
            listen: (
                _event: string,
                callback: (payload: {
                    post_id?: string;
                    error?: string;
                }) => void,
            ) => {
                listeners.set(channel, callback);
            },
        }),
        leave: () => {},
    }),
}));

vi.mock('@inertiajs/vue3', () => ({
    router: { visit: (url: string) => visited.push(url) },
}));

type Composable = typeof import('@/composables/useAiGeneration');

const loadComposable = async (): Promise<
    ReturnType<Composable['useAiGeneration']>
> => {
    vi.resetModules();
    const module =
        (await import('@/composables/useAiGeneration')) as Composable;

    return module.useAiGeneration();
};

/** Delivers the server's completion event for a tracked generation. */
const completeOnServer = (
    channel: string,
    payload: { post_id?: string; error?: string },
): void => {
    listeners.get(channel)?.(payload);
};

describe('useAiGeneration', () => {
    beforeEach(() => {
        listeners.clear();
        visited.length = 0;
        sessionStorage.clear();
        vi.useFakeTimers();
    });

    it('surfaces a completion while another generation is still running', async () => {
        const ai = await loadComposable();

        ai.track({ id: 'a', channel: 'chan-a' });
        ai.track({ id: 'b', channel: 'chan-b' });

        completeOnServer('chan-a', { post_id: 'post-a' });

        // The bar must announce A right away — B still running must not hide it.
        expect(ai.doneGenerations.value.map((g) => g.id)).toEqual(['a']);
        expect(ai.isGenerating.value).toBe(true);
        expect(ai.loadingCount.value).toBe(1);
    });

    it('keeps an unseen completion alive past the done TTL', async () => {
        const ai = await loadComposable();

        ai.track({ id: 'a', channel: 'chan-a' });
        ai.track({ id: 'b', channel: 'chan-b' });
        completeOnServer('chan-a', { post_id: 'post-a' });

        // Well past the 12s TTL: without the seen gate, A would already be gone
        // and its "ready" bar would never have been shown.
        vi.advanceTimersByTime(60_000);
        expect(ai.doneGenerations.value.map((g) => g.id)).toEqual(['a']);

        // Once the bar surfaces it, the TTL runs as usual.
        ai.markSeen('a');
        vi.advanceTimersByTime(12_000);
        expect(ai.doneGenerations.value).toHaveLength(0);
    });

    it('shows a failure alongside a success instead of masking it', async () => {
        const ai = await loadComposable();

        ai.track({ id: 'a', channel: 'chan-a' });
        ai.track({ id: 'b', channel: 'chan-b' });

        completeOnServer('chan-a', { post_id: 'post-a' });
        completeOnServer('chan-b', { error: 'boom' });

        expect(ai.doneGenerations.value.map((g) => g.id)).toEqual(['a']);
        expect(ai.errorGenerations.value.map((g) => g.id)).toEqual(['b']);
        expect(ai.isGenerating.value).toBe(false);
    });

    it('opens completions oldest first, one per click', async () => {
        const ai = await loadComposable();

        ai.track({ id: 'a', channel: 'chan-a', startedAt: 1_000 });
        ai.track({ id: 'b', channel: 'chan-b', startedAt: 2_000 });
        completeOnServer('chan-b', { post_id: 'post-b' });
        completeOnServer('chan-a', { post_id: 'post-a' });

        expect(ai.doneGenerations.value).toHaveLength(2);

        ai.openNextDone();
        expect(visited).toEqual(['/posts/post-a/edit']);
        // B is still there to be opened by the next click.
        expect(ai.doneGenerations.value.map((g) => g.id)).toEqual(['b']);

        ai.openNextDone();
        expect(visited).toEqual(['/posts/post-a/edit', '/posts/post-b/edit']);
        expect(ai.doneGenerations.value).toHaveLength(0);
    });

    it('dismisses every failure at once', async () => {
        const ai = await loadComposable();

        ai.track({ id: 'a', channel: 'chan-a' });
        ai.track({ id: 'b', channel: 'chan-b' });
        completeOnServer('chan-a', { error: 'boom' });
        completeOnServer('chan-b', { error: 'boom' });

        expect(ai.errorGenerations.value).toHaveLength(2);

        ai.dismissErrors();
        expect(ai.errorGenerations.value).toHaveLength(0);
    });
});
