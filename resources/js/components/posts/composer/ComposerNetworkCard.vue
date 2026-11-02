<script setup lang="ts">
import { computed, nextTick, ref, useSlots, watch } from 'vue';

import PlatformLogo from '@/components/PlatformLogo.vue';
import ContentTypeRadioGroup from '@/components/posts/editor/ContentTypeRadioGroup.vue';
import type { ContentTypeOption } from '@/composables/usePlatformLogo';
import { autosizeTextarea } from '@/lib/autosizeTextarea';
import { MEDIALESS_CONTENT_TYPES } from '@/lib/medialessContentTypes';
import { CAPTIONLESS_CONTENT_TYPES } from '@/types/content-type';

const props = withDefaults(
    defineProps<{
        platform?: string | null;
        testIdPrefix: string;
        captionTestId: string;
        typeTestIdPrefix?: string;
        content: string;
        contentTypeOptions?: ContentTypeOption[];
        contentType?: string;
        hasMedia?: boolean;
        captionCollapsed?: boolean;
        /** Index of the reply being edited; its toolbar slot receives the card toolbar. */
        activeReply?: number;
        /** Shift+Enter in the caption opens the first post of a thread. */
        threadable?: boolean;
        /** A thread follows the caption, so it sizes to its text instead of filling the card. */
        threaded?: boolean;
        disabled?: boolean;
    }>(),
    {
        platform: null,
        typeTestIdPrefix: '',
        contentTypeOptions: () => [],
        contentType: '',
        hasMedia: false,
        captionCollapsed: false,
        activeReply: -1,
        threadable: false,
        threaded: false,
        disabled: false,
    },
);

const emit = defineEmits<{
    'update:content': [value: string];
    'update:contentType': [value: string];
    paste: [event: ClipboardEvent];
    drop: [event: DragEvent];
    'open-templates': [];
    'expand-caption': [];
    'start-thread': [];
    'next-post': [];
}>();

const caption = ref<HTMLTextAreaElement | null>(null);

watch(
    () => [props.threaded, props.content, props.captionCollapsed] as const,
    async ([threaded]) => {
        await nextTick();

        if (caption.value) {
            caption.value.style.height = '';
        }

        if (threaded) {
            autosizeTextarea(caption.value);
        }
    },
    { immediate: true },
);

const onCaptionInput = (event: Event): void => {
    const textarea = event.target as HTMLTextAreaElement;

    if (props.threaded) {
        autosizeTextarea(textarea);
    }

    emit('update:content', textarea.value);
};

const onCaptionKeydown = (event: KeyboardEvent): void => {
    const textarea = event.target as HTMLTextAreaElement;

    if (
        props.threaded &&
        event.key === 'ArrowDown' &&
        textarea.selectionStart === textarea.selectionEnd &&
        textarea.selectionEnd === textarea.value.length
    ) {
        event.preventDefault();
        emit('next-post');

        return;
    }

    if (
        props.threadable &&
        event.key === 'Enter' &&
        event.shiftKey &&
        !event.isComposing
    ) {
        event.preventDefault();
        emit('start-thread');
    }
};

watch(
    () => props.captionCollapsed,
    async (collapsed, wasCollapsed) => {
        if (collapsed || !wasCollapsed) {
            return;
        }

        await nextTick();
        autosizeTextarea(caption.value);
        caption.value?.focus();
        caption.value?.setSelectionRange(
            caption.value.value.length,
            caption.value.value.length,
        );
    },
);

const slots = useSlots();
const captionId = computed(() => `${props.testIdPrefix}-caption`);
const hintId = computed(() => `${props.testIdPrefix}-templates-hint`);
const rootToolbarTarget = computed(() => `${props.testIdPrefix}-root-toolbar`);
const replyToolbarTarget = computed(
    () => `${props.testIdPrefix}-reply-toolbar-${props.activeReply}`,
);
const showsCaption = computed(
    () => !CAPTIONLESS_CONTENT_TYPES.has(props.contentType),
);
const showsMedia = computed(
    () => props.hasMedia || !MEDIALESS_CONTENT_TYPES.has(props.contentType),
);
const showsHeader = computed(
    () => props.contentTypeOptions.length > 0 || Boolean(slots.header),
);
</script>

<template>
    <div
        class="flex min-h-64 gap-3 rounded-xl border border-border bg-card"
        :class="platform ? 'p-3' : 'p-4'"
        @dragover.prevent
        @drop.prevent="emit('drop', $event)"
    >
        <PlatformLogo
            v-if="platform"
            :platform="platform"
            :size="24"
            class="sticky top-0 z-20 shrink-0 self-start"
            :data-testid="`${testIdPrefix}-logo`"
        />
        <div class="flex min-w-0 flex-1 flex-col gap-4">
            <div
                v-if="platform && showsHeader"
                class="sticky top-0 z-10 flex min-h-6 flex-wrap items-center gap-3 bg-card shadow-[0_-0.75rem_0_var(--color-card),-2.25rem_0_0_var(--color-card),-2.25rem_-0.75rem_0_var(--color-card)] rtl:shadow-[0_-0.75rem_0_var(--color-card),2.25rem_0_0_var(--color-card),2.25rem_-0.75rem_0_var(--color-card)]"
                :data-testid="`${testIdPrefix}-header`"
            >
                <ContentTypeRadioGroup
                    v-if="contentTypeOptions.length > 0"
                    :options="contentTypeOptions"
                    :model-value="contentType"
                    :test-id-prefix="typeTestIdPrefix"
                    :disabled="disabled"
                    @update:model-value="emit('update:contentType', $event)"
                />
                <slot name="header" />
            </div>
            <slot name="warnings" />
            <div
                v-if="showsCaption"
                class="relative flex flex-col"
                :class="{ 'flex-1': !threaded && !captionCollapsed }"
            >
                <span
                    v-if="threaded && platform"
                    aria-hidden="true"
                    class="pointer-events-none absolute -start-[25px] top-7 -bottom-3 w-0.5 rounded-full bg-border"
                    :data-testid="`${testIdPrefix}-thread-connector`"
                />
                <button
                    v-if="captionCollapsed"
                    type="button"
                    class="px-[9px] text-start text-sm break-words whitespace-pre-line text-muted-foreground"
                    :data-testid="`${testIdPrefix}-caption-collapsed`"
                    @click="emit('expand-caption')"
                >
                    {{ content }}
                </button>
                <div
                    v-else
                    class="relative flex flex-col"
                    :class="threaded ? '' : 'min-h-40 flex-1'"
                >
                    <label class="sr-only" :for="captionId">{{
                        $t('posts.composer.content_label')
                    }}</label>
                    <textarea
                        :id="captionId"
                        ref="caption"
                        :rows="threaded ? 1 : undefined"
                        :value="content"
                        :data-testid="captionTestId"
                        :aria-describedby="content ? undefined : hintId"
                        class="w-full resize-none bg-transparent px-[9px] pt-0.5 pb-1 text-sm outline-none"
                        :class="
                            threaded
                                ? 'overflow-hidden'
                                : 'min-h-40 flex-1'
                        "
                        @input="onCaptionInput"
                        @paste="emit('paste', $event)"
                        @keydown="onCaptionKeydown"
                    />
                    <p
                        v-if="!content"
                        :id="hintId"
                        class="pointer-events-none absolute start-0 top-0 px-[9px] pt-0.5 text-sm text-subtle-foreground"
                    >
                        {{ $t('create.templates.panel.inspire_prefix') }}
                        <button
                            type="button"
                            class="pointer-events-auto cursor-pointer font-medium text-primary-text underline-offset-2 hover:underline"
                            :data-testid="
                                platform
                                    ? `${testIdPrefix}-templates-inspire`
                                    : 'composer-templates-inspire'
                            "
                            @click="emit('open-templates')"
                        >
                            {{ $t('create.templates.panel.inspire_link') }}
                        </button>
                    </p>
                </div>
                <div
                    v-if="threaded && !captionCollapsed && $slots.attachment"
                    class="pt-2"
                >
                    <slot name="attachment" />
                </div>
                <slot v-if="threaded" name="post-media" />
                <div
                    v-if="threaded && !captionCollapsed"
                    :id="rootToolbarTarget"
                    class="px-[9px]"
                />
            </div>
            <slot name="replies" />
            <div>
                <slot v-if="!threaded" name="attachment" />
                <slot v-if="showsMedia" name="media" />
                <Teleport
                    v-if="threaded"
                    defer
                    :to="`#${captionCollapsed ? replyToolbarTarget : rootToolbarTarget}`"
                >
                    <slot name="toolbar" />
                </Teleport>
                <slot v-else name="toolbar" />
            </div>
            <slot name="settings" />
        </div>
    </div>
</template>
