<script setup lang="ts">
import {
    IconArrowLeft,
    IconBook,
    IconCheck,
    IconChevronDown,
    IconPhoto,
} from '@tabler/icons-vue';
import { computed } from 'vue';

import PlatformLogo from '@/components/PlatformLogo.vue';
import { Button } from '@/components/ui/button';
import { DialogDescription, DialogTitle } from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    getContentTypeOptions,
    getPlatformLabel,
    hasMultipleContentTypes,
} from '@/composables/usePlatformLogo';
import { DOCS_URL, mediaLimitsDocsUrl, platformGuideDocsUrl } from '@/lib/docs';
import { MediaType } from '@/lib/mediaType';
import { Platform, type PlatformValue } from '@/types/platform';
import type { AvailablePlatform } from '@/types/social-account';

const props = defineProps<{
    platform: AvailablePlatform;
    platforms: AvailablePlatform[];
}>();

const emit = defineEmits<{
    back: [];
    connect: [platform: string];
}>();

const WORKS_WELL_WITH: Record<string, PlatformValue[]> = {
    [Platform.Bluesky]: [Platform.X, Platform.Threads, Platform.Mastodon],
    [Platform.Discord]: [Platform.Telegram, Platform.X],
    [Platform.Facebook]: [Platform.Instagram, Platform.LinkedIn, Platform.YouTube],
    [Platform.GoogleBusiness]: [Platform.Facebook, Platform.Instagram],
    [Platform.Instagram]: [Platform.Pinterest, Platform.TikTok, Platform.YouTube],
    [Platform.LinkedIn]: [Platform.X, Platform.Facebook],
    [Platform.Mastodon]: [Platform.Bluesky, Platform.X, Platform.LinkedIn],
    [Platform.Pinterest]: [Platform.Instagram, Platform.TikTok],
    [Platform.Telegram]: [Platform.Discord, Platform.X],
    [Platform.Threads]: [Platform.Instagram, Platform.Mastodon, Platform.Bluesky],
    [Platform.TikTok]: [Platform.YouTube, Platform.Instagram],
    [Platform.X]: [Platform.Threads, Platform.Bluesky, Platform.LinkedIn],
    [Platform.YouTube]: [Platform.TikTok, Platform.Instagram, Platform.Facebook],
};

const MEDIA_FEATURE_KEYS: Record<MediaType, string> = {
    [MediaType.Image]: 'channels.details.features.image',
    [MediaType.Video]: 'channels.details.features.video',
    [MediaType.Document]: 'channels.details.features.document',
};

const value = computed(() => props.platform.value);
const networkName = computed(() => getPlatformLabel(value.value));
const copyKey = computed(() => `channels.details.networks.${value.value}`);

const featureKeys = computed<string[]>(() => [
    ...(props.platform.text_only ? ['channels.details.features.text'] : []),
    ...(props.platform.media_types ?? []).map((type) => MEDIA_FEATURE_KEYS[type]),
    ...(hasMultipleContentTypes(value.value)
        ? getContentTypeOptions(value.value).map((option) => option.labelKey)
        : []),
    ...(props.platform.analytics ? ['channels.details.features.analytics'] : []),
]);

const siblings = computed(() =>
    (WORKS_WELL_WITH[value.value] ?? []).filter((sibling) =>
        props.platforms.some((platform) => platform.value === sibling),
    ),
);

const guideUrl = computed(() => platformGuideDocsUrl(value.value));
const mediaUrl = computed(() => mediaLimitsDocsUrl(value.value));
</script>

<template>
    <div
        class="flex min-h-0 flex-1 flex-col"
        data-testid="connect-details"
    >
        <Button
            variant="ghost"
            size="icon"
            class="absolute top-3 left-3 text-muted-foreground"
            :aria-label="$t('channels.dialog.back')"
            data-testid="connect-details-back"
            @click="emit('back')"
        >
            <IconArrowLeft class="size-4 rtl:rotate-180" />
        </Button>

        <div
            class="min-h-0 flex-1 overflow-y-auto bg-muted pt-12 sm:rounded-lg sm:pt-16"
        >
            <div
                class="mx-auto flex w-full max-w-[544px] flex-col gap-8 px-4 pb-8"
            >
                <header class="flex items-center gap-3">
                    <PlatformLogo :platform="value" size="sm" :title="null" />
                    <div class="flex min-w-0 flex-col">
                        <DialogTitle
                            class="font-sans text-sm leading-[21px] font-emphasis text-foreground"
                        >
                            {{ platform.label }}
                        </DialogTitle>
                        <DialogDescription
                            class="text-sm leading-[21px] text-muted-foreground"
                        >
                            {{ $t(`channels.platforms.${value}`) }}
                        </DialogDescription>
                    </div>
                </header>

                <section class="flex flex-col gap-3">
                    <h3 class="text-sm leading-[21px] font-medium text-foreground">
                        {{ $t('channels.details.why', { network: networkName }) }}
                    </h3>
                    <ul class="flex flex-col gap-3">
                        <li
                            v-for="index in 3"
                            :key="index"
                            class="flex gap-3 text-sm leading-[21px] text-foreground"
                        >
                            <IconCheck
                                class="mt-[2.5px] size-4 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <span>{{ $t(`${copyKey}.why_${index}`) }}</span>
                        </li>
                    </ul>
                </section>

                <figure
                    class="flex flex-col gap-3 rounded-lg bg-primary-subtle p-4"
                >
                    <blockquote class="text-sm leading-[21px] text-foreground">
                        {{ $t(`${copyKey}.tip`) }}
                    </blockquote>
                    <figcaption class="flex items-center gap-2">
                        <img
                            src="/images/reviews/paulo-castellano.jpg"
                            alt=""
                            class="size-8 rounded-lg object-cover"
                            loading="lazy"
                        />
                        <span
                            class="text-sm leading-[21px] font-medium text-foreground"
                            >{{ $t('channels.details.signature') }}</span
                        >
                    </figcaption>
                </figure>

                <div class="grid gap-6 sm:grid-cols-2 sm:gap-4">
                    <section class="flex flex-col gap-2">
                        <h3
                            class="text-sm leading-[21px] font-medium text-foreground"
                        >
                            {{ $t('channels.details.supported_features') }}
                        </h3>
                        <ul class="flex flex-wrap gap-1">
                            <li
                                v-for="key in featureKeys"
                                :key="key"
                                class="rounded-full bg-accent px-2 text-sm leading-6 text-foreground"
                            >
                                {{ $t(key) }}
                            </li>
                        </ul>
                    </section>
                    <section v-if="siblings.length" class="flex flex-col gap-2">
                        <h3
                            class="text-sm leading-[21px] font-medium text-foreground"
                        >
                            {{
                                $t('channels.details.works_well_with', {
                                    network: networkName,
                                })
                            }}
                        </h3>
                        <ul class="flex flex-wrap gap-1">
                            <li
                                v-for="sibling in siblings"
                                :key="sibling"
                                class="rounded-full bg-accent px-2 text-sm leading-6 text-foreground"
                            >
                                {{ getPlatformLabel(sibling) }}
                            </li>
                        </ul>
                    </section>
                </div>
            </div>
        </div>

        <footer
            class="flex items-center justify-between gap-3 border-t border-border bg-muted px-4 py-4 sm:mt-2 sm:rounded-lg sm:border-t-0 sm:px-6"
        >
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="ghost"
                        size="lg"
                        data-testid="connect-details-help"
                    >
                        {{ $t('channels.details.help') }}
                        <IconChevronDown class="size-4" aria-hidden="true" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start" side="top" class="w-72">
                    <DropdownMenuItem as-child>
                        <a
                            :href="guideUrl ?? DOCS_URL"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="cursor-pointer"
                            data-testid="connect-details-help-guide"
                        >
                            <IconBook class="size-4" aria-hidden="true" />
                            <span>{{
                                guideUrl
                                    ? $t('channels.details.help_guide', {
                                          network: networkName,
                                      })
                                    : $t('channels.details.help_docs')
                            }}</span>
                        </a>
                    </DropdownMenuItem>
                    <DropdownMenuItem v-if="guideUrl" as-child>
                        <a
                            :href="mediaUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="cursor-pointer"
                            data-testid="connect-details-help-media"
                        >
                            <IconPhoto class="size-4" aria-hidden="true" />
                            <span>{{
                                $t('channels.details.help_media', {
                                    network: networkName,
                                })
                            }}</span>
                        </a>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <Button
                size="lg"
                data-testid="connect-details-connect"
                @click="emit('connect', value)"
            >
                {{ $t('channels.details.connect', { network: networkName }) }}
            </Button>
        </footer>
    </div>
</template>
