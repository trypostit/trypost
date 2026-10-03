<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import {
    IconBrandGoogleDrive,
    IconBrandGooglePhotos,
    IconBrandUnsplash,
    IconChevronDown,
    IconCircleLetterCFilled,
    IconCloudUpload,
} from '@tabler/icons-vue';
import { getActiveLanguage, trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, ref, type Component } from 'vue';
import { toast } from 'vue-sonner';

import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    type MediaImportPayload,
    useMediaImport,
} from '@/composables/useMediaImport';
import { defaultCanvaPreset, openCanva } from '@/lib/mediaSources/canva';
import {
    type GoogleDrivePick,
    pickFromGoogleDrive,
    preloadGoogleDrive,
} from '@/lib/mediaSources/googleDrive';
import {
    type GooglePhotosPick,
    pickFromGooglePhotos,
} from '@/lib/mediaSources/googlePhotos';
import type { MediaSourceOption } from '@/types';

type SourceKey = MediaSourceOption['source'];

defineProps<{
    testIdPrefix: string;
}>();

const emit = defineEmits<{
    (event: 'import-started', payload: { importId: string; label: string }): void;
    (event: 'open-unsplash'): void;
}>();

const STORAGE_KEY = 'trypost.composer.mediaSource';

const ICONS: Record<SourceKey, Component> = {
    canva: IconCircleLetterCFilled,
    google_drive: IconBrandGoogleDrive,
    google_photos: IconBrandGooglePhotos,
    unsplash: IconBrandUnsplash,
};

const page = usePage();
const mediaImport = useMediaImport();

const startImport = async (
    option: MediaSourceOption,
    payload: MediaImportPayload,
): Promise<void> => {
    const importIds = await mediaImport.run(payload);
    importIds.forEach((importId) =>
        emit('import-started', { importId, label: option.label }),
    );
};

const popupWaits = new AbortController();

onBeforeUnmount(() => popupWaits.abort());

const designInCanva = async (
    option: MediaSourceOption,
    preset: string | undefined,
): Promise<void> => {
    if (!preset) {
        return;
    }

    const result = await openCanva(preset, popupWaits.signal);
    if (result) {
        emit('import-started', {
            importId: result.importId,
            label: option.label,
        });
    }
};

const toastPickFailure = (): void => {
    toast.error(trans('posts.composer.media_sources.errors.import_failed'));
};

/** SDKs a source needs before its click, loaded when the menu is reached. */
const preloaders: Partial<
    Record<SourceKey, (option: MediaSourceOption) => void>
> = {
    google_drive: (option) => preloadGoogleDrive(option.config),
};

/** One `pick` per provider; a source without one is not offered. */
const handlers: Partial<
    Record<
        SourceKey,
        (
            option: MediaSourceOption,
            start: typeof startImport,
        ) => Promise<void> | void
    >
> = {
    canva: (option) =>
        designInCanva(option, defaultCanvaPreset(option.presets)?.value),
    google_drive: async (option, start) => {
        let picked: GoogleDrivePick | null;

        try {
            picked = await pickFromGoogleDrive(
                option.config,
                getActiveLanguage(),
                page.props.mediaUploadLimits?.heic ?? false,
                popupWaits.signal,
            );
        } catch {
            toastPickFailure();

            return;
        }

        if (!picked) return;

        await start(option, {
            source: option.source,
            access_token: picked.accessToken,
            file: picked.file,
        });
    },
    google_photos: async (option, start) => {
        let picked: GooglePhotosPick | null;

        try {
            picked = await pickFromGooglePhotos(popupWaits.signal);
        } catch {
            toastPickFailure();

            return;
        }

        if (!picked) return;

        await start(option, {
            source: option.source,
            session_id: picked.sessionId,
        });
    },
    unsplash: () => emit('open-unsplash'),
};

const sources = computed<MediaSourceOption[]>(() =>
    (page.props.mediaSources?.menu ?? []).filter(
        (option) => handlers[option.source] !== undefined,
    ),
);

const readLastUsed = (): string | null => {
    try {
        return window.localStorage.getItem(STORAGE_KEY);
    } catch {
        return null;
    }
};

const lastUsed = ref<string | null>(readLastUsed());

const main = computed<MediaSourceOption | undefined>(
    () =>
        sources.value.find((option) => option.source === lastUsed.value) ??
        (sources.value.length === 1 ? sources.value[0] : undefined),
);

const menuOpen = ref(false);

const rememberSource = (source: SourceKey): void => {
    lastUsed.value = source;
    try {
        window.localStorage.setItem(STORAGE_KEY, source);
    } catch {
        return;
    }
};

const preload = (options: MediaSourceOption[]): void =>
    options.forEach((option) => preloaders[option.source]?.(option));

const pick = (option: MediaSourceOption): void => {
    rememberSource(option.source);
    void handlers[option.source]?.(option, startImport);
};

const openMain = (): void => {
    if (main.value) {
        pick(main.value);

        return;
    }

    menuOpen.value = true;
};

const pickCanvaPreset = (
    option: MediaSourceOption,
    preset: string,
): void => {
    rememberSource(option.source);
    void designInCanva(option, preset);
};
</script>

<template>
    <div v-if="sources.length" class="flex items-center">
        <button
            type="button"
            :data-testid="`${testIdPrefix}-media-source-main`"
            :data-source="main?.source ?? 'none'"
            :aria-label="
                main
                    ? $t('posts.composer.media_sources.select_from', {
                          source: main.label,
                      })
                    : $t('posts.composer.media_sources.more')
            "
            :title="
                main
                    ? $t('posts.composer.media_sources.select_from', {
                          source: main.label,
                      })
                    : $t('posts.composer.media_sources.more')
            "
            :aria-haspopup="main ? undefined : 'menu'"
            class="flex size-8 items-center justify-center rounded-lg text-foreground transition-control hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
            @pointerenter="preload(main ? [main] : sources)"
            @focus="preload(main ? [main] : sources)"
            @click="openMain"
        >
            <component
                :is="main ? ICONS[main.source] : IconCloudUpload"
                class="size-4"
            />
        </button>
        <span class="mx-0.5 h-4 w-px bg-border" aria-hidden="true" />
        <DropdownMenu
            v-model:open="menuOpen"
            @update:open="$event && preload(sources)"
        >
            <DropdownMenuTrigger as-child>
                <button
                    type="button"
                    :data-testid="`${testIdPrefix}-media-source-menu`"
                    :aria-label="$t('posts.composer.media_sources.more')"
                    :title="$t('posts.composer.media_sources.more')"
                    class="flex h-8 w-6 items-center justify-center rounded-lg text-foreground transition-control hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring data-[state=open]:bg-accent"
                >
                    <IconChevronDown class="size-3.5" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                side="top"
                align="start"
                :side-offset="8"
                class="min-w-40"
                :data-testid="`${testIdPrefix}-media-source-list`"
            >
                <template v-for="option in sources" :key="option.source">
                    <DropdownMenuSub v-if="option.source === 'canva'">
                        <DropdownMenuSubTrigger
                            :data-testid="`media-source-${option.source}`"
                        >
                            <component
                                :is="ICONS[option.source]"
                                class="text-foreground"
                            />
                            {{ option.label }}
                        </DropdownMenuSubTrigger>
                        <DropdownMenuSubContent>
                            <DropdownMenuItem
                                v-for="preset in option.presets ?? []"
                                :key="preset.value"
                                :data-testid="`canva-preset-${preset.value}`"
                                @select="pickCanvaPreset(option, preset.value)"
                            >
                                <span class="flex flex-col">
                                    <span class="flex items-center gap-1.5">
                                        {{
                                            $t(
                                                `posts.composer.media_sources.canva_presets.${preset.value}`,
                                            )
                                        }}
                                        <span
                                            v-if="preset.is_default"
                                            class="text-xs text-muted-foreground"
                                            :data-testid="`canva-preset-${preset.value}-default`"
                                        >
                                            ({{
                                                $t(
                                                    'posts.composer.media_sources.canva_default',
                                                )
                                            }})
                                        </span>
                                    </span>
                                    <span
                                        class="text-xs text-muted-foreground tabular-nums"
                                    >
                                        {{ preset.width }} × {{ preset.height }}
                                    </span>
                                </span>
                            </DropdownMenuItem>
                        </DropdownMenuSubContent>
                    </DropdownMenuSub>
                    <DropdownMenuItem
                        v-else
                        :data-testid="`media-source-${option.source}`"
                        @select="pick(option)"
                    >
                        <component
                            :is="ICONS[option.source]"
                            class="text-foreground"
                        />
                        {{ option.label }}
                    </DropdownMenuItem>
                </template>
            </DropdownMenuContent>
        </DropdownMenu>
    </div>
</template>
