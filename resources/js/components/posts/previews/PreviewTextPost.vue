<script setup lang="ts">
import { IconWorld } from '@tabler/icons-vue';
import { computed, toRef } from 'vue';

import LinkCard from '@/components/posts/previews/LinkCard.vue';
import PreviewAvatar from '@/components/posts/previews/PreviewAvatar.vue';
import PreviewMedia from '@/components/posts/previews/PreviewMedia.vue';
import PreviewText from '@/components/posts/previews/PreviewText.vue';
import { useLinkCard } from '@/composables/useLinkCard';
import type { MediaItem } from '@/types/media';

import type {
    PreviewAccount,
    PreviewAction,
    PreviewMediaLayout,
} from './types';

const props = withDefaults(
    defineProps<{
        account: PreviewAccount;
        content: string;
        media: MediaItem[];
        variant?: 'thread' | 'stacked';
        name?: string;
        handle?: string;
        caption?: string;
        captionGlobe?: boolean;
        avatarClass?: string;
        avatarSquare?: boolean;
        contentTestid?: string;
        linkTone?: 'brand' | 'info';
        hostOnly?: boolean;
        tags?: 'link' | 'bare' | 'plain' | 'muted';
        textClass?: string;
        truncate?: number | null;
        moreKey?: string;
        moreBelow?: boolean;
        mediaLayout?: PreviewMediaLayout;
        mediaAspect?: number | null;
        muteBadge?: boolean;
        linkCard?: boolean;
        linkUrl?: (text: string) => string | null;
        linkCardOptions?: Record<string, boolean>;
        links?: boolean;
        actions?: PreviewAction[];
        trailingActions?: PreviewAction[];
        actionsStyle?: 'spread' | 'start' | 'inline-labels' | 'column-labels';
        actionClass?: string;
        threadPosition?: 'first' | 'middle' | 'last' | null;
        concealed?: boolean;
    }>(),
    {
        variant: 'thread',
        name: undefined,
        handle: undefined,
        caption: undefined,
        captionGlobe: false,
        avatarClass: undefined,
        avatarSquare: false,
        contentTestid: undefined,
        linkTone: 'brand',
        hostOnly: false,
        tags: 'link',
        textClass: undefined,
        truncate: null,
        moreKey: 'posts.composer.preview.more',
        moreBelow: false,
        mediaLayout: 'grid',
        mediaAspect: null,
        muteBadge: false,
        linkCard: false,
        linkUrl: undefined,
        linkCardOptions: () => ({}),
        links: true,
        actions: () => [],
        trailingActions: () => [],
        actionsStyle: 'spread',
        actionClass: 'text-muted-foreground',
        threadPosition: null,
        concealed: false,
    },
);

const { card, loading } = useLinkCard(
    toRef(props, 'content'),
    toRef(props, 'media'),
    props.linkUrl,
);

const isStacked = computed((): boolean => props.variant === 'stacked');

const isTruncated = computed(
    (): boolean =>
        props.truncate !== null && props.content.length > props.truncate,
);

const visibleContent = computed((): string =>
    isTruncated.value
        ? props.content.slice(0, props.truncate ?? undefined).trimEnd()
        : props.content,
);

const subtitle = computed((): string =>
    [props.handle, props.caption].filter(Boolean).join(' · '),
);

const hasActions = computed(
    (): boolean => props.actions.length > 0 || props.trailingActions.length > 0,
);
</script>

<template>
    <article
        class="text-[15px] leading-5"
        :class="[
            isStacked ? 'space-y-3 py-3' : 'flex gap-4 py-4 pr-4 pl-3',
            { 'relative overflow-hidden': threadPosition },
        ]"
    >
        <div :class="isStacked ? 'flex items-center gap-3 px-4' : 'contents'">
            <span class="relative h-fit shrink-0">
                <PreviewAvatar
                    :account="account"
                    :square="avatarSquare"
                    :class="avatarClass ?? (isStacked ? 'size-12' : 'size-8')"
                />
                <slot name="avatar-badge" />
                <span
                    v-if="threadPosition === 'middle' || threadPosition === 'last'"
                    aria-hidden="true"
                    class="absolute bottom-[calc(100%+4px)] left-1/2 h-screen w-0.5 -translate-x-1/2 bg-border"
                />
                <span
                    v-if="threadPosition === 'first' || threadPosition === 'middle'"
                    aria-hidden="true"
                    data-testid="preview-thread-connector"
                    class="absolute top-[calc(100%+4px)] left-1/2 h-screen w-0.5 -translate-x-1/2 bg-border"
                />
            </span>
            <div
                v-if="isStacked"
                class="min-w-0 flex-1"
                data-testid="preview-author"
            >
                <div class="flex items-center gap-1.5">
                    <span class="truncate font-semibold">{{
                        name ?? account.display_label
                    }}</span>
                    <slot name="badge" />
                </div>
                <div v-if="handle" class="truncate text-muted-foreground">
                    {{ handle }}
                </div>
                <div
                    v-if="caption"
                    class="flex items-center gap-1 text-[13px] leading-4 text-muted-foreground"
                >
                    {{ caption }}
                    <template v-if="captionGlobe">
                        · <IconWorld class="size-3.5" />
                    </template>
                </div>
            </div>
            <slot v-if="isStacked" name="aside" />
        </div>
        <div
            class="min-w-0 flex-1 space-y-3"
            :class="{ 'ms-14': isStacked && threadPosition }"
        >
            <div v-if="!isStacked" class="space-y-0.5">
                <div
                    class="flex min-w-0 items-baseline gap-1"
                    data-testid="preview-author"
                >
                    <span class="truncate font-semibold">{{
                        name ?? account.display_label
                    }}</span>
                    <span
                        v-if="subtitle"
                        class="min-w-0 truncate text-muted-foreground"
                        >{{ subtitle }}</span
                    >
                    <slot name="aside" />
                </div>
                <div
                    v-if="content && !concealed"
                    :data-testid="contentTestid"
                    :class="textClass"
                >
                    <PreviewText
                        :text="visibleContent"
                        :tone="linkTone"
                        :host-only="hostOnly"
                        :tags="tags"
                        :links="links"
                    />
                </div>
            </div>
            <div
                v-else-if="content && !concealed"
                class="px-4"
                :class="textClass"
                :data-testid="contentTestid"
            >
                <PreviewText
                    :text="visibleContent"
                    :tone="linkTone"
                    :host-only="hostOnly"
                    :tags="tags"
                    :links="links"
                /><template v-if="isTruncated && !moreBelow"
                    >…
                    <span class="text-muted-foreground">{{
                        $t(moreKey)
                    }}</span></template
                >
                <p
                    v-if="isTruncated && moreBelow"
                    class="text-right text-muted-foreground"
                >
                    …{{ $t(moreKey) }}
                </p>
            </div>
            <div v-if="$slots['before-media']" :class="{ 'px-4': isStacked }">
                <slot name="before-media" />
            </div>
            <PreviewMedia
                v-if="!concealed"
                :media="media"
                :layout="mediaLayout"
                :aspect="mediaAspect"
                :bleed="isStacked"
                :mute-badge="muteBadge"
            />
            <div
                v-if="linkCard && media.length === 0 && (loading || card)"
                :class="{ 'px-4': isStacked && !linkCardOptions.bleed }"
            >
                <div
                    v-if="loading"
                    class="h-24 animate-pulse rounded-xl border bg-muted"
                />
                <LinkCard
                    v-else-if="card"
                    :card="card"
                    v-bind="linkCardOptions"
                />
            </div>
            <div v-if="$slots.default" :class="{ 'px-4': isStacked }">
                <slot />
            </div>
            <div
                v-if="hasActions"
                class="flex items-center"
                :class="[
                    actionClass,
                    {
                        'px-4': isStacked && actionsStyle !== 'column-labels',
                        'pt-1': actionsStyle !== 'column-labels',
                        'justify-between': actionsStyle === 'spread',
                        'gap-6': actionsStyle === 'start',
                        'justify-around': actionsStyle.endsWith('labels'),
                        'mx-4 border-t pt-3 text-foreground':
                            actionsStyle === 'column-labels',
                    },
                ]"
                data-testid="preview-actions"
            >
                <PreviewAvatar
                    v-if="actionsStyle === 'column-labels'"
                    :account="account"
                    class="size-6 text-[10px]"
                />
                <span
                    v-for="(action, index) in actions"
                    :key="index"
                    class="flex items-center"
                    :class="
                        actionsStyle === 'column-labels'
                            ? 'flex-col gap-1 text-[13px] font-semibold'
                            : 'gap-2'
                    "
                >
                    <component
                        :is="action.icon"
                        :class="
                            actionsStyle === 'inline-labels'
                                ? 'size-5'
                                : 'size-[18px]'
                        "
                        stroke-width="1.75"
                    />
                    <span v-if="action.labelKey">{{
                        $t(action.labelKey)
                    }}</span>
                </span>
                <span
                    v-if="trailingActions.length"
                    class="flex gap-3"
                    :class="{ 'ml-auto': actionsStyle !== 'spread' }"
                >
                    <component
                        :is="action.icon"
                        v-for="(action, index) in trailingActions"
                        :key="index"
                        class="size-[18px]"
                        stroke-width="1.75"
                    />
                </span>
            </div>
        </div>
    </article>
</template>
