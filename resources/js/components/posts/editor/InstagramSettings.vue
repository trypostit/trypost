<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import {
    IconAt,
    IconInfoCircle,
    IconMusic,
    IconPlayerPauseFilled,
    IconPlayerPlayFilled,
    IconTrash,
    IconUserPlus,
    IconVolume,
    IconX,
} from '@tabler/icons-vue';
import { computed, onUnmounted, ref, watch } from 'vue';

import {
    index as audioIndex,
    show as audioShow,
} from '@/actions/App/Http/Controllers/App/InstagramAudioController';
import InputError from '@/components/InputError.vue';
import SettingsRow from '@/components/posts/editor/SettingsRow.vue';
import SettingsSection from '@/components/posts/editor/SettingsSection.vue';
import TrialReelIllustration from '@/components/posts/editor/TrialReelIllustration.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverAnchor,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Skeleton } from '@/components/ui/skeleton';
import { Switch } from '@/components/ui/switch';
import { useReelSoundtrack } from '@/composables/composer/useReelSoundtrack';
import { useMetaErrors } from '@/composables/useMetaErrors';
import { instagramProfileUrl } from '@/lib/instagram';
import { rangeFill } from '@/lib/rangeFill';
import { ContentType } from '@/types/content-type';
import type { InstagramAudio } from '@/types/instagram';
import { Platform } from '@/types/platform';

type VolumeKey = 'video_volume' | 'audio_volume';

const VOLUME_CONTROLS: { key: VolumeKey; labelKey: string }[] = [
    { key: 'video_volume', labelKey: 'posts.form.instagram.music.video_volume' },
    { key: 'audio_volume', labelKey: 'posts.form.instagram.music.audio_volume' },
];

const MAX_COLLABORATORS = 3;

const SOUND_WAVE_BARS = [
    { height: '40%', delay: '0ms' },
    { height: '80%', delay: '-300ms' },
    { height: '100%', delay: '-600ms' },
    { height: '70%', delay: '-150ms' },
    { height: '50%', delay: '-450ms' },
];
const COLLABORATORS_NOTICE_KEY = 'trypost:instagram:collaborators-notice-seen';
const SEARCH_DEBOUNCE_MS = 300;
const HOVER_PREVIEW_DELAY_MS = 150;
const TOO_MANY_REQUESTS = 429;

const TRIAL_OPTIONS = [
    { value: '', labelKey: 'posts.form.instagram.trial_reel.off' },
    { value: 'SS_PERFORMANCE', labelKey: 'posts.form.instagram.trial_reel.auto' },
    { value: 'MANUAL', labelKey: 'posts.form.instagram.trial_reel.manual' },
];

const props = withDefaults(
    defineProps<{
        accountId: string;
        platform: string;
        contentType: string;
        platformIndex: number;
        meta?: Record<string, any>;
        disabled?: boolean;
    }>(),
    { meta: () => ({}), disabled: false },
);

const emit = defineEmits<{
    'update:meta': [value: Record<string, any>];
    'link-facebook': [];
}>();

const updateMeta = (patch: Record<string, any>): void =>
    emit('update:meta', { ...props.meta, ...patch });

const readFlag = (key: string): boolean => {
    try {
        return localStorage.getItem(key) === '1';
    } catch {
        return false;
    }
};

const writeFlag = (key: string): void => {
    try {
        localStorage.setItem(key, '1');
    } catch {
        return;
    }
};

const metaError = useMetaErrors(() => props.platformIndex);
const audioError = metaError('audio');
const collaboratorsError = metaError('collaborators');
const collaboratorErrors = Array.from(
    { length: MAX_COLLABORATORS },
    (_, index) => metaError(`collaborators.${index}`),
);
const collaboratorError = computed(
    () =>
        collaboratorsError.value ??
        collaboratorErrors.find((error) => error.value)?.value,
);
const trialError = metaError('trial_graduation');

const isReel = computed(() => props.contentType === ContentType.InstagramReel);
const takesCollaborators = computed(
    () =>
        props.contentType === ContentType.InstagramReel ||
        props.contentType === ContentType.InstagramFeed,
);
const musicNeedsFacebook = computed(
    () => props.platform !== Platform.InstagramFacebook,
);

const shareToFeed = computed({
    get: (): boolean => props.meta.share_to_feed ?? true,
    set: (value: boolean) => updateMeta({ share_to_feed: value }),
});

const selectedAudio = computed(
    () => (props.meta.audio as InstagramAudio | null | undefined) ?? null,
);

const musicOpen = ref(false);
const musicQuery = ref('');
const tracks = ref<InstagramAudio[]>([]);
const tracksLoading = ref(false);
const tracksFailed = ref(false);
const tracksLimited = ref(false);

const isRateLimited = (error: unknown): boolean =>
    (error as { response?: { status?: number } } | null)?.response?.status ===
    TOO_MANY_REQUESTS;
const audioHttp = useHttp<Record<string, never>, InstagramAudio[]>();

let latestSearch = 0;

const failSearch = (limited: boolean): void => {
    tracks.value = [];
    tracksFailed.value = true;
    tracksLimited.value = limited;
};

const loadTracks = async (): Promise<void> => {
    const search = ++latestSearch;
    tracksLoading.value = true;
    tracksFailed.value = false;
    tracksLimited.value = false;

    try {
        const query = musicQuery.value.trim();
        const found = await audioHttp.get(
            audioIndex.url(props.accountId, {
                query: query === '' ? {} : { q: query },
            }),
        );
        if (search !== latestSearch) return;

        if (Array.isArray(found)) {
            tracks.value = found;
        } else {
            failSearch(false);
        }
    } catch (error) {
        if (search !== latestSearch) return;
        failSearch(isRateLimited(error));
    } finally {
        if (search === latestSearch) tracksLoading.value = false;
    }
};

let searchTimer: ReturnType<typeof setTimeout> | undefined;
watch(musicQuery, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(loadTracks, SEARCH_DEBOUNCE_MS);
});

const soundtrack = useReelSoundtrack();

const brokenUrls = ref(new Set<string>());
const isUsable = (url: string | null | undefined): url is string =>
    Boolean(url) && !brokenUrls.value.has(url as string);
const markBroken = (url: string | null | undefined): void => {
    if (url) brokenUrls.value = new Set([...brokenUrls.value, url]);
};

const audioDetailsHttp = useHttp<Record<string, never>, InstagramAudio>();
const refreshedAudioIds = new Set<string>();

const refreshSelectedAudio = async (): Promise<boolean> => {
    const audio = selectedAudio.value;
    if (!audio || musicNeedsFacebook.value || refreshedAudioIds.has(audio.id)) {
        return false;
    }
    refreshedAudioIds.add(audio.id);

    try {
        const fresh = await audioDetailsHttp.get(
            audioShow.url({ account: props.accountId, audioId: audio.id }),
        );
        const current = selectedAudio.value;

        if (!fresh || current?.id !== audio.id) {
            return false;
        }

        updateMeta({
            audio: {
                ...current,
                title: current.title || fresh.title,
                artist: current.artist || fresh.artist,
                cover_url: fresh.cover_url || current.cover_url,
                preview_url: fresh.preview_url || current.preview_url,
            },
        });

        return true;
    } catch {
        return false;
    }
};

const missesTrackDetails = (audio: InstagramAudio | null): boolean =>
    Boolean(audio?.id) &&
    (!audio?.title || !audio.cover_url || !audio.preview_url);

watch(
    isReel,
    (reel) => {
        if (reel && missesTrackDetails(selectedAudio.value)) {
            void refreshSelectedAudio();
        }
    },
    { immediate: true },
);

const onSelectedLinkBroken = (url: string | null | undefined): void => {
    void refreshSelectedAudio().then((refreshed) => {
        if (!refreshed) markBroken(url);
    });
};
const playingId = soundtrack.playingAudioId;

let hoverTimer: ReturnType<typeof setTimeout> | undefined;

const stopPreview = (): void => {
    clearTimeout(hoverTimer);
    soundtrack.stop();
};

const togglePreview = (track: InstagramAudio): void => {
    if (playingId.value === track.id) {
        soundtrack.stop();

        return;
    }

    if (!isUsable(track.preview_url)) {
        soundtrack.stop();

        return;
    }

    soundtrack.play(
        track.id,
        track.preview_url,
        track.audio_volume ?? 100,
        () =>
            track.id === selectedAudio.value?.id
                ? onSelectedLinkBroken(track.preview_url)
                : markBroken(track.preview_url),
    );
};

const startHoverPreview = (track: InstagramAudio): void => {
    clearTimeout(hoverTimer);
    if (!isUsable(track.preview_url) || playingId.value === track.id) return;
    hoverTimer = setTimeout(
        () =>
            soundtrack.play(track.id, track.preview_url as string, 100, () =>
                markBroken(track.preview_url),
            ),
        HOVER_PREVIEW_DELAY_MS,
    );
};

const stopHoverPreview = (track: InstagramAudio): void => {
    clearTimeout(hoverTimer);
    if (playingId.value === track.id) soundtrack.stop();
};

const onMusicOpenChange = (open: boolean): void => {
    musicOpen.value = open;

    if (!open) {
        stopPreview();

        return;
    }

    if (!musicNeedsFacebook.value && tracks.value.length === 0) {
        void loadTracks();
    }
};

const closeMusic = (): void => {
    onMusicOpenChange(false);
};

const formatDuration = (milliseconds: number): string => {
    const seconds = Math.round(milliseconds / 1000);

    return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
};

const trackDetails = (track: InstagramAudio): string =>
    [
        track.duration_ms ? formatDuration(track.duration_ms) : null,
        track.artist,
    ]
        .filter(Boolean)
        .join(' · ');

const selectTrack = (track: InstagramAudio): void => {
    updateMeta({
        audio: {
            id: track.id,
            title: track.title,
            artist: track.artist,
            cover_url: track.cover_url,
            preview_url: track.preview_url,
        },
    });
    closeMusic();
};

const volumeOf = (key: VolumeKey): number => selectedAudio.value?.[key] ?? 100;

const changeVolume = (key: VolumeKey, event: Event): void => {
    const volume = Number((event.target as HTMLInputElement).value);
    updateMeta({ audio: { ...selectedAudio.value, [key]: volume } });

    if (key === 'audio_volume' && playingId.value === selectedAudio.value?.id) {
        soundtrack.setVolume(volume);
    }
};

const removeAudio = (): void => {
    soundtrack.stop();
    updateMeta({ audio: null });
};

const linkFacebook = (): void => {
    closeMusic();
    emit('link-facebook');
};

const collaborators = computed<string[]>(() =>
    Array.isArray(props.meta.collaborators) ? props.meta.collaborators : [],
);
const collaboratorDraft = ref('');
const collaboratorNoticeOpen = ref(false);

const addCollaborator = (): void => {
    const username = collaboratorDraft.value.trim().replace(/^@+/, '');
    collaboratorDraft.value = '';

    if (
        username === '' ||
        collaborators.value.length >= MAX_COLLABORATORS ||
        collaborators.value.some(
            (existing) => existing.toLowerCase() === username.toLowerCase(),
        )
    ) {
        return;
    }

    updateMeta({
        collaborators: [...collaborators.value, username],
        trial_graduation: null,
    });

    if (!readFlag(COLLABORATORS_NOTICE_KEY)) {
        collaboratorNoticeOpen.value = true;
    }
};

const onCollaboratorKeydown = (event: KeyboardEvent): void => {
    if (event.key === 'Enter' || event.key === ',' || event.key === ' ') {
        event.preventDefault();
        addCollaborator();
    }
};

const removeCollaborator = (username: string): void => {
    updateMeta({
        collaborators: collaborators.value.filter(
            (existing) => existing !== username,
        ),
    });
};

const dismissCollaboratorNotice = (): void => {
    writeFlag(COLLABORATORS_NOTICE_KEY);
    collaboratorNoticeOpen.value = false;
};

const trialGraduation = computed(
    () => (props.meta.trial_graduation as string | null | undefined) ?? '',
);
const TRIAL_HINTS: Record<string, string> = {
    SS_PERFORMANCE: 'posts.form.instagram.trial_reel.auto_hint',
    MANUAL: 'posts.form.instagram.trial_reel.manual_hint',
};
const trialHint = computed(() => TRIAL_HINTS[trialGraduation.value] ?? null);
const pendingTrial = ref<string | null>(null);
const trialCheckOpen = computed(() => pendingTrial.value !== null);

const trialOptionLabels = new Map<string, HTMLElement>();
const rememberTrialOption = (value: string, element: unknown): void => {
    if (element instanceof HTMLElement) {
        trialOptionLabels.set(value, element);
    }
};
const trialAnchor = computed(() =>
    pendingTrial.value === null
        ? undefined
        : trialOptionLabels.get(pendingTrial.value),
);

const selectTrial = (value: string): void => {
    if (value !== '' && trialGraduation.value === '') {
        pendingTrial.value = value;

        return;
    }

    updateMeta({ trial_graduation: value === '' ? null : value });
};

const cancelTrialCheck = (): void => {
    pendingTrial.value = null;
};

const confirmTrialCheck = (): void => {
    const value = pendingTrial.value;
    pendingTrial.value = null;
    updateMeta({ trial_graduation: value });
};

watch(
    () => props.accountId,
    () => {
        tracks.value = [];
        musicQuery.value = '';
        refreshedAudioIds.clear();
    },
);

const ownsPlayingAudio = (): boolean =>
    playingId.value !== null &&
    (playingId.value === selectedAudio.value?.id ||
        tracks.value.some((track) => track.id === playingId.value));

onUnmounted(() => {
    clearTimeout(searchTimer);
    clearTimeout(hoverTimer);

    if (ownsPlayingAudio()) {
        soundtrack.stop();
    }
});
</script>

<template>
    <SettingsSection v-if="takesCollaborators">
        <SettingsRow v-if="isReel" :label="$t('posts.form.instagram.music.label')">
            <template #label>
                <span data-single-line>{{
                    $t('posts.form.instagram.music.label')
                }}</span>
            </template>
            <div class="flex min-h-8 items-center">
                <div
                    v-if="selectedAudio"
                    class="flex w-full min-w-0 items-center gap-3 rounded-md border p-2"
                    data-testid="instagram-music-selected"
                >
                    <button
                        type="button"
                        class="relative size-12 shrink-0 overflow-hidden rounded-md bg-muted disabled:cursor-default"
                        data-testid="instagram-music-selected-preview"
                        :disabled="!isUsable(selectedAudio.preview_url)"
                        :aria-label="
                            playingId === selectedAudio.id
                                ? $t('posts.form.instagram.music.pause')
                                : $t('posts.form.instagram.music.play')
                        "
                        @click="togglePreview(selectedAudio)"
                    >
                        <img
                            v-if="isUsable(selectedAudio.cover_url)"
                            :src="selectedAudio.cover_url"
                            alt=""
                            class="absolute inset-0 size-full object-cover"
                            data-testid="instagram-music-selected-cover"
                            @error="onSelectedLinkBroken(selectedAudio.cover_url)"
                        />
                        <IconMusic
                            v-else
                            class="absolute inset-0 m-auto size-5 text-muted-foreground"
                        />
                        <span
                            v-if="isUsable(selectedAudio.preview_url)"
                            class="absolute inset-0 m-auto flex size-7 items-center justify-center rounded-full bg-success text-white"
                        >
                            <IconPlayerPauseFilled
                                v-if="playingId === selectedAudio.id"
                                class="size-3.5"
                            />
                            <IconPlayerPlayFilled v-else class="size-3.5" />
                        </span>
                    </button>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm text-foreground">{{
                            selectedAudio.title
                        }}</span>
                        <span
                            v-if="selectedAudio.artist"
                            class="block truncate text-sm text-muted-foreground"
                            >{{ selectedAudio.artist }}</span
                        >
                    </span>
                    <Popover>
                        <PopoverTrigger as-child>
                            <Button
                                type="button"
                                variant="outline"
                                size="icon-sm"
                                data-testid="instagram-music-volume"
                                :aria-label="$t('posts.form.instagram.music.volume')"
                                :disabled="disabled"
                            >
                                <IconVolume class="size-4" />
                            </Button>
                        </PopoverTrigger>
                        <PopoverContent
                            align="end"
                            class="w-64 space-y-4"
                            data-testid="instagram-music-volume-panel"
                        >
                            <label
                                v-for="control in VOLUME_CONTROLS"
                                :key="control.key"
                                class="block space-y-1 text-sm"
                            >
                                <span class="flex justify-between">
                                    <span data-single-line>{{
                                        $t(control.labelKey)
                                    }}</span>
                                    <span class="text-muted-foreground"
                                        >{{ volumeOf(control.key) }}%</span
                                    >
                                </span>
                                <input
                                    type="range"
                                    min="0"
                                    max="100"
                                    step="1"
                                    class="editor-range w-full"
                                    :data-testid="`instagram-music-${control.key}`"
                                    :style="rangeFill(volumeOf(control.key), 0, 100)"
                                    :value="volumeOf(control.key)"
                                    @input="changeVolume(control.key, $event)"
                                />
                            </label>
                        </PopoverContent>
                    </Popover>
                    <Button
                        type="button"
                        variant="outline"
                        size="icon-sm"
                        data-testid="instagram-music-remove"
                        :aria-label="$t('posts.form.instagram.music.remove')"
                        :disabled="disabled"
                        @click="removeAudio"
                    >
                        <IconTrash class="size-4" />
                    </Button>
                </div>
                <Popover
                    v-else
                    :open="musicOpen"
                    @update:open="onMusicOpenChange"
                >
                    <PopoverTrigger as-child>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            data-testid="instagram-music-add"
                            :disabled="disabled"
                        >
                            <span data-single-line>{{
                                $t('posts.form.instagram.music.add')
                            }}</span>
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent
                        v-if="musicNeedsFacebook"
                        side="top"
                        class="w-80 space-y-3"
                        data-testid="instagram-music-link-facebook"
                    >
                        <p class="text-sm font-semibold">
                            {{ $t('posts.form.instagram.music.link_title') }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ $t('posts.form.instagram.music.link_body') }}
                        </p>
                        <div class="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                data-testid="instagram-music-maybe-later"
                                @click="closeMusic"
                            >
                                <span data-single-line>{{
                                    $t('posts.form.instagram.music.maybe_later')
                                }}</span>
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                data-testid="instagram-music-save-and-link"
                                @click="linkFacebook"
                            >
                                <span data-single-line>{{
                                    $t('posts.form.instagram.music.save_and_link')
                                }}</span>
                            </Button>
                        </div>
                    </PopoverContent>
                    <PopoverContent
                        v-else
                        side="top"
                        align="start"
                        class="w-96 p-0"
                        data-testid="instagram-music-picker"
                    >
                        <div class="p-2">
                            <Input
                                v-model="musicQuery"
                                type="text"
                                autocomplete="off"
                                data-testid="instagram-music-search"
                                :placeholder="
                                    $t('posts.form.instagram.music.search_placeholder')
                                "
                            />
                        </div>
                        <div class="max-h-80 overflow-y-auto px-2 pb-2">
                            <template v-if="tracksLoading">
                                <div
                                    v-for="index in 5"
                                    :key="index"
                                    class="flex items-center gap-3 p-2"
                                    data-testid="instagram-music-skeleton"
                                >
                                    <Skeleton class="size-12 shrink-0 rounded-md" />
                                    <div class="flex-1 space-y-2">
                                        <Skeleton class="h-3.5 w-3/4" />
                                        <Skeleton class="h-3 w-1/2" />
                                    </div>
                                </div>
                            </template>
                            <p
                                v-else-if="tracksFailed"
                                class="px-2 py-4 text-sm text-destructive"
                                data-testid="instagram-music-failed"
                            >
                                {{
                                    $t(
                                        tracksLimited
                                            ? 'posts.form.instagram.music.too_many_searches'
                                            : 'posts.form.instagram.music.search_failed',
                                    )
                                }}
                            </p>
                            <p
                                v-else-if="tracks.length === 0"
                                class="px-2 py-4 text-sm text-muted-foreground"
                            >
                                {{ $t('posts.form.instagram.music.empty') }}
                            </p>
                            <button
                                v-for="track in tracks"
                                v-else
                                :key="track.id"
                                type="button"
                                class="flex w-full items-center gap-3 rounded-md p-2 text-left hover:bg-muted focus-visible:bg-muted focus-visible:outline-none"
                                :data-testid="`instagram-music-track-${track.id}`"
                                @mouseenter="startHoverPreview(track)"
                                @mouseleave="stopHoverPreview(track)"
                                @click="selectTrack(track)"
                            >
                                <span
                                    class="relative size-12 shrink-0 overflow-hidden rounded-md bg-muted"
                                >
                                    <img
                                        v-if="isUsable(track.cover_url)"
                                        :src="track.cover_url"
                                        alt=""
                                        class="absolute inset-0 size-full object-cover"
                                        @error="markBroken(track.cover_url)"
                                    />
                                    <IconMusic
                                        v-else
                                        class="absolute inset-0 m-auto size-5 text-muted-foreground"
                                    />
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="flex items-center gap-1.5">
                                        <span
                                            v-if="playingId === track.id"
                                            class="motion-sound-wave flex h-3 shrink-0 items-center gap-0.5"
                                            aria-hidden="true"
                                            data-testid="instagram-music-playing"
                                        >
                                            <span
                                                v-for="bar in SOUND_WAVE_BARS"
                                                :key="bar.delay"
                                                class="w-0.5 rounded-full bg-success"
                                                :style="{
                                                    height: bar.height,
                                                    animationDelay: bar.delay,
                                                }"
                                            />
                                        </span>
                                        <span class="truncate text-sm text-foreground">{{
                                            track.title
                                        }}</span>
                                    </span>
                                    <span
                                        class="block truncate text-sm text-muted-foreground"
                                        >{{ trackDetails(track) }}</span
                                    >
                                </span>
                            </button>
                        </div>
                        <p
                            class="flex items-center gap-1.5 border-t px-3 py-2 text-xs text-muted-foreground"
                        >
                            <IconInfoCircle class="size-3.5 shrink-0" />
                            {{ $t('posts.form.instagram.music.limited') }}
                        </p>
                    </PopoverContent>
                </Popover>
            </div>
            <p
                v-if="selectedAudio"
                class="text-xs text-muted-foreground"
                data-testid="instagram-music-part-hint"
            >
                {{ $t('posts.form.instagram.music.part_hint') }}
            </p>
            <InputError :message="audioError" />
        </SettingsRow>

        <SettingsRow
            :label="$t('posts.form.instagram.collaborators.label')"
            :align-top="collaborators.length > 0"
        >
            <template #label>
                <span data-single-line>{{
                    $t('posts.form.instagram.collaborators.label')
                }}</span>
            </template>
            <p
                v-if="isReel && trialGraduation !== ''"
                class="flex min-h-8 items-center text-sm text-muted-foreground"
                data-testid="instagram-collaborators-unavailable"
            >
                {{ $t('posts.form.instagram.collaborators.no_trial') }}
            </p>
            <Popover v-else :open="collaboratorNoticeOpen">
                <PopoverAnchor as-child>
                    <div
                        class="flex flex-col gap-2"
                        data-testid="instagram-collaborators"
                    >
                        <div
                            v-if="collaborators.length > 0"
                            class="flex flex-wrap items-center gap-2"
                        >
                            <span
                                v-for="username in collaborators"
                                :key="username"
                                class="inline-flex items-center gap-1 rounded-md bg-muted px-2 py-1 text-sm"
                            >
                                <a
                                    :href="instagramProfileUrl(username)"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="cursor-alias underline-offset-2 hover:underline"
                                    :data-testid="`instagram-collaborator-${username}`"
                                    >@{{ username }}</a
                                >
                                <button
                                    type="button"
                                    class="rounded text-muted-foreground hover:text-foreground"
                                    :data-testid="`instagram-collaborator-remove-${username}`"
                                    :aria-label="
                                        $t('posts.form.instagram.collaborators.remove', {
                                            username,
                                        })
                                    "
                                    :disabled="disabled"
                                    @click="removeCollaborator(username)"
                                >
                                    <IconX class="size-3.5" />
                                </button>
                            </span>
                        </div>
                        <div
                            v-if="collaborators.length < MAX_COLLABORATORS"
                            class="flex items-center gap-2"
                        >
                            <div class="relative min-w-0 flex-1">
                                <IconAt
                                    class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                                />
                                <Input
                                    v-model="collaboratorDraft"
                                    type="text"
                                    class="pl-8"
                                    autocomplete="off"
                                    data-testid="instagram-collaborator-input"
                                    :placeholder="
                                        $t(
                                            'posts.form.instagram.collaborators.placeholder',
                                        )
                                    "
                                    :aria-label="
                                        $t('posts.form.instagram.collaborators.add')
                                    "
                                    :disabled="disabled"
                                    @keydown="onCollaboratorKeydown"
                                    @blur="addCollaborator"
                                />
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                data-testid="instagram-collaborator-add"
                                :aria-label="
                                    $t('posts.form.instagram.collaborators.add')
                                "
                                :disabled="disabled"
                                @click="addCollaborator"
                            >
                                <IconUserPlus class="size-4" />
                            </Button>
                        </div>
                    </div>
                </PopoverAnchor>
                <PopoverContent
                    side="bottom"
                    align="start"
                    class="w-80 space-y-3"
                    data-testid="instagram-collaborators-notice"
                    @open-auto-focus.prevent
                    @escape-key-down="dismissCollaboratorNotice"
                    @pointer-down-outside="dismissCollaboratorNotice"
                >
                    <p class="text-sm">
                        {{ $t('posts.form.instagram.collaborators.notice') }}
                    </p>
                    <div class="flex justify-end">
                        <Button
                            type="button"
                            size="sm"
                            data-testid="instagram-collaborators-notice-dismiss"
                            @click="dismissCollaboratorNotice"
                        >
                            <span data-single-line>{{
                                $t('posts.form.instagram.collaborators.got_it')
                            }}</span>
                        </Button>
                    </div>
                </PopoverContent>
            </Popover>
            <InputError :message="collaboratorError" />
        </SettingsRow>

        <SettingsRow
            v-if="isReel"
            :label="$t('posts.form.instagram.trial_reel.label')"
            :align-top="Boolean(trialHint) && collaborators.length === 0"
        >
            <template #label>
                <span data-single-line>{{
                    $t('posts.form.instagram.trial_reel.label')
                }}</span>
            </template>
            <p
                v-if="collaborators.length > 0"
                class="flex min-h-8 items-center text-sm text-muted-foreground"
                data-testid="instagram-trial-reel-unavailable"
            >
                {{ $t('posts.form.instagram.trial_reel.no_collaborators') }}
            </p>
            <Popover v-else :open="trialCheckOpen">
                <PopoverAnchor :reference="trialAnchor" as-child>
                    <div>
                        <RadioGroup
                            :model-value="trialGraduation"
                            :disabled="disabled"
                            orientation="horizontal"
                            :aria-label="$t('posts.form.instagram.trial_reel.label')"
                            class="flex min-h-8 flex-wrap items-center gap-x-5 gap-y-2"
                            data-testid="instagram-trial-reel"
                            @update:model-value="selectTrial(String($event))"
                        >
                            <label
                                v-for="option in TRIAL_OPTIONS"
                                :key="option.value"
                                :ref="(element) => rememberTrialOption(option.value, element)"
                                class="flex cursor-pointer items-center gap-2 text-sm text-foreground has-disabled:cursor-not-allowed has-disabled:opacity-50"
                            >
                                <RadioGroupItem
                                    :value="option.value"
                                    :data-testid="`instagram-trial-reel-${option.value || 'off'}`"
                                />
                                <span data-single-line>{{ $t(option.labelKey) }}</span>
                            </label>
                        </RadioGroup>
                    </div>
                </PopoverAnchor>
                <PopoverContent
                    side="top"
                    class="w-80 space-y-3"
                    data-testid="instagram-trial-reel-check"
                    @escape-key-down="cancelTrialCheck"
                    @pointer-down-outside="cancelTrialCheck"
                >
                    <TrialReelIllustration />
                    <p class="text-sm font-semibold">
                        {{ $t('posts.form.instagram.trial_reel.check_title') }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{ $t('posts.form.instagram.trial_reel.check_body') }}
                    </p>
                    <div class="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            data-testid="instagram-trial-reel-check-no"
                            @click="cancelTrialCheck"
                        >
                            <span data-single-line>{{
                                $t('posts.form.instagram.trial_reel.not_checked')
                            }}</span>
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            data-testid="instagram-trial-reel-check-yes"
                            @click="confirmTrialCheck"
                        >
                            <span data-single-line>{{
                                $t('posts.form.instagram.trial_reel.checked')
                            }}</span>
                        </Button>
                    </div>
                </PopoverContent>
            </Popover>
            <p
                v-if="trialHint && collaborators.length === 0"
                class="text-[13px] text-muted-foreground"
                data-testid="instagram-trial-reel-hint"
            >
                {{ $t(trialHint) }}
            </p>
            <InputError :message="trialError" />
        </SettingsRow>

        <SettingsRow v-if="isReel" :label="$t('posts.form.instagram.share_to_feed')">
            <template #label>
                <span data-single-line>{{
                    $t('posts.form.instagram.share_to_feed')
                }}</span>
            </template>
            <div class="flex min-h-8 items-center">
                <Switch
                    v-model="shareToFeed"
                    data-testid="instagram-share-to-feed"
                    :disabled="disabled"
                    :aria-label="$t('posts.form.instagram.share_to_feed')"
                />
            </div>
        </SettingsRow>
    </SettingsSection>
</template>
