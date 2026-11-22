import { useHttp, usePage } from '@inertiajs/vue3';
import {
    computed,
    inject,
    type InjectionKey,
    provide,
    reactive,
    type Ref,
    ref,
    shallowRef,
} from 'vue';

import {
    account as composerAccount,
    live as composerLive,
    takenSlots as composerTakenSlots,
} from '@/routes/app/posts/composer';
import type { PinterestBoardsPayload, SharedData } from '@/types';
import type {
    ChannelTikTokCreatorInfo,
    SidebarChannel,
} from '@/types/channel';
import {
    Platform,
    type PlatformConfig,
    type PlatformConfigs,
} from '@/types/platform';
import type { ComposerAccount } from '@/types/post-composition';
import type { PostingSchedule } from '@/types/posting-schedule';

interface ComposerSharedData {
    accounts: Record<
        string,
        {
            timezone: string | null;
            posting_schedule: PostingSchedule | null;
            has_posting_schedule: boolean;
            platform_config: PlatformConfig;
        }
    >;
    signatures: { id: string; name: string; content: string }[];
    labels: { id: string; name: string; color: string }[];
}

interface ComposerLiveData {
    composer?: ComposerSharedData;
    composerKey?: string;
}

interface ComposerAccountData {
    pinterestBoards?: PinterestBoardsPayload;
    tiktokCreatorInfo?: ChannelTikTokCreatorInfo | null;
}

interface ComposerLiveState {
    failed: Readonly<Ref<boolean>>;
    /** Bumped on each retry so per-day reads ask again. */
    attempt: Readonly<Ref<number>>;
    retry: () => void;
    /** Whether a Pinterest or TikTok channel's own data has arrived. */
    accountLoaded: (accountId: string) => boolean;
    /** The instants already held on a channel between two UTC instants. */
    takenSlots: (accountId: string, from: string, to: string) => Promise<string[]>;
}

const composerLiveKey: InjectionKey<ComposerLiveState> =
    Symbol('composerLive');

/**
 * The per-open data: the bundle check, each Pinterest and TikTok channel's
 * boards or creator info, and the taken slots of the day the picker shows.
 * Outside a composer everything counts as loaded and no slot is taken.
 */
export const useComposerLiveState = (): ComposerLiveState =>
    inject(composerLiveKey, {
        failed: ref(false),
        attempt: ref(0),
        retry: () => {},
        accountLoaded: () => true,
        takenSlots: () => Promise.resolve([]),
    });

/**
 * A bundle the live endpoint sent because the page's once prop went stale
 * (the user changed a channel or a signature without a page visit). It holds
 * only while the page still carries the key it replaced.
 */
const refreshedBundle = shallowRef<{
    replacedKey: string;
    key: string;
    bundle: ComposerSharedData;
} | null>(null);

/**
 * The composer's data: the workspace bundle shared once (`composer`), the
 * channels shared on every page, and what is read fresh on each open (taken
 * slots, Pinterest boards, TikTok creator info). The live request also sends
 * the bundle's key, so the user's own out-of-band changes come back with it.
 */
export const useComposerData = () => {
    const page = usePage<SharedData>();
    const http = useHttp<Record<string, never>, ComposerLiveData>({});
    const accountHttp = useHttp<Record<string, never>, ComposerAccountData>(
        {},
    );
    const slotsHttp = useHttp<Record<string, never>, { takenSlots: string[] }>(
        {},
    );
    const pinterestBoards = shallowRef<Record<string, PinterestBoardsPayload>>(
        {},
    );
    const tiktokCreatorInfos = shallowRef<
        Record<string, ChannelTikTokCreatorInfo>
    >({});
    const loadedAccounts = reactive(new Set<string>());
    const failed = ref(false);
    const attempt = ref(0);
    let latestLoad = 0;

    const pageKey = computed(
        () =>
            Object.entries(page.onceProps ?? {}).find(
                ([, once]) => once.prop === 'composer',
            )?.[0] ?? '',
    );

    const refreshed = computed(() =>
        refreshedBundle.value?.replacedKey === pageKey.value
            ? refreshedBundle.value
            : null,
    );

    const composer = computed(
        () =>
            refreshed.value?.bundle ??
            (page.props.composer as ComposerSharedData | undefined) ??
            null,
    );

    const channels = computed(
        () => (page.props.channels as SidebarChannel[] | undefined) ?? [],
    );

    const socialAccounts = computed<ComposerAccount[]>(() =>
        channels.value.map((channel) => {
            const details = composer.value?.accounts[channel.id];

            return {
                ...channel,
                display_name: channel.display_name ?? '',
                timezone: details?.timezone ?? channel.timezone,
                has_posting_schedule: details?.has_posting_schedule ?? false,
                posting_schedule: details?.posting_schedule ?? null,
            };
        }),
    );

    const platformConfigs = computed<PlatformConfigs>(() =>
        Object.fromEntries(
            Object.entries(composer.value?.accounts ?? {}).map(
                ([id, details]) => [id, details.platform_config],
            ),
        ),
    );

    const signatures = computed(() => composer.value?.signatures ?? []);
    const labels = computed(() => composer.value?.labels ?? []);

    const loadBundle = async (loadId: number): Promise<void> => {
        const replacedKey = pageKey.value;
        const result = (await http.get(
            composerLive.url({
                query: { key: refreshed.value?.key ?? replacedKey },
            }),
        )) as ComposerLiveData;
        if (loadId !== latestLoad) return;
        if (result.composer && result.composerKey) {
            refreshedBundle.value = {
                replacedKey,
                key: result.composerKey,
                bundle: result.composer,
            };
        }
    };

    const loadAccount = async (
        loadId: number,
        accountId: string,
    ): Promise<void> => {
        const result = (await accountHttp.get(
            composerAccount.url(accountId),
        )) as ComposerAccountData;
        if (loadId !== latestLoad) return;
        if (result.pinterestBoards) {
            pinterestBoards.value = {
                ...pinterestBoards.value,
                [accountId]: result.pinterestBoards,
            };
        }
        if (result.tiktokCreatorInfo) {
            tiktokCreatorInfos.value = {
                ...tiktokCreatorInfos.value,
                [accountId]: result.tiktokCreatorInfo,
            };
        }
        loadedAccounts.add(accountId);
    };

    const guarded = async (
        loadId: number,
        request: Promise<void>,
    ): Promise<void> => {
        try {
            await request;
        } catch {
            if (loadId === latestLoad) failed.value = true;
        }
    };

    const reset = (): void => {
        ++latestLoad;
        pinterestBoards.value = {};
        tiktokCreatorInfos.value = {};
        loadedAccounts.clear();
        failed.value = false;
    };

    const load = async (): Promise<void> => {
        reset();
        const loadId = latestLoad;
        const liveAccounts = channels.value.filter(
            (channel) =>
                channel.platform === Platform.Pinterest ||
                channel.platform === Platform.TikTok,
        );
        await Promise.all([
            guarded(loadId, loadBundle(loadId)),
            ...liveAccounts.map((channel) =>
                guarded(loadId, loadAccount(loadId, channel.id)),
            ),
        ]);
    };

    const takenSlots = async (
        accountId: string,
        from: string,
        to: string,
    ): Promise<string[]> => {
        try {
            const result = (await slotsHttp.get(
                composerTakenSlots.url(accountId, { query: { from, to } }),
            )) as { takenSlots: string[] };

            return result.takenSlots;
        } catch (exception) {
            failed.value = true;
            throw exception;
        }
    };

    const retry = (): void => {
        attempt.value++;
        void load();
    };

    provide(composerLiveKey, {
        failed,
        attempt,
        retry,
        accountLoaded: (accountId) => loadedAccounts.has(accountId),
        takenSlots,
    });

    return {
        composer,
        socialAccounts,
        platformConfigs,
        signatures,
        labels,
        pinterestBoards,
        tiktokCreatorInfos,
        load,
        reset,
    };
};
