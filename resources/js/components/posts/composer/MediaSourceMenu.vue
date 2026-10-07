<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import {
    IconBrandGoogleDrive,
    IconBrandGooglePhotos,
    IconBrandUnsplash,
    IconCircleLetterCFilled,
    IconPhotoPlus,
    IconUpload,
} from '@tabler/icons-vue';
import { getActiveLanguage, trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, ref, type Component } from 'vue';
import { toast } from 'vue-sonner';

import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
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

withDefaults(
    defineProps<{
        testIdPrefix: string;
        disabled?: boolean;
        disabledReason?: string;
    }>(),
    { disabled: false, disabledReason: undefined },
);

const emit = defineEmits<{
    (event: 'import-started', payload: { importId: string; label: string }): void;
    (event: 'open-unsplash'): void;
    (event: 'upload'): void;
}>();

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

const menuOpen = ref(false);

const preload = (options: MediaSourceOption[]): void =>
    options.forEach((option) => preloaders[option.source]?.(option));

const preloadSources = (): void => {
    preload(sources.value);
};

const onMenuOpenChange = (open: boolean): void => {
    if (open) {
        preloadSources();
    }
};

const requestUpload = (): void => {
    emit('upload');
};

const pick = (option: MediaSourceOption): void => {
    void handlers[option.source]?.(option, startImport);
};

const pickCanvaPreset = (
    option: MediaSourceOption,
    preset: string,
): void => {
    void designInCanva(option, preset);
};
</script>

<template>
    <TooltipProvider v-if="disabled" :delay-duration="200">
        <Tooltip>
            <TooltipTrigger as-child>
                <span
                    class="flex size-8 cursor-not-allowed items-center justify-center text-subtle-foreground"
                    :aria-label="disabledReason"
                    :data-testid="`${testIdPrefix}-media-source-disabled`"
                >
                    <IconPhotoPlus class="size-4" />
                </span>
            </TooltipTrigger>
            <TooltipContent>{{ disabledReason }}</TooltipContent>
        </Tooltip>
    </TooltipProvider>
    <DropdownMenu
        v-else
        v-model:open="menuOpen"
        @update:open="onMenuOpenChange"
    >
        <DropdownMenuTrigger as-child>
            <button
                type="button"
                :data-testid="`${testIdPrefix}-media-source-menu`"
                :aria-label="$t('posts.edit.add_media')"
                :title="$t('posts.edit.add_media')"
                class="flex size-8 items-center justify-center rounded-lg text-foreground transition-control hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring data-[state=open]:bg-accent"
                @pointerenter="preloadSources"
                @focus="preloadSources"
            >
                <IconPhotoPlus class="size-4" />
            </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent
            side="top"
            align="start"
            :side-offset="8"
            class="min-w-48"
            :data-testid="`${testIdPrefix}-media-source-list`"
        >
            <DropdownMenuItem
                data-testid="media-source-upload"
                @select="requestUpload"
            >
                <IconUpload class="text-foreground" />
                {{ $t('posts.composer.media_sources.upload') }}
            </DropdownMenuItem>
            <DropdownMenuSeparator v-if="sources.length" />
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
</template>
