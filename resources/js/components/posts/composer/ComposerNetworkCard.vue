<script setup lang="ts">
import { computed, useSlots } from 'vue';

import PlatformLogo from '@/components/PlatformLogo.vue';
import ContentTypeRadioGroup from '@/components/posts/editor/ContentTypeRadioGroup.vue';
import type { ContentTypeOption } from '@/composables/usePlatformLogo';
import {
    CAPTIONLESS_CONTENT_TYPES,
    MEDIALESS_CONTENT_TYPES,
} from '@/types/content-type';

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
        disabled?: boolean;
    }>(),
    {
        platform: null,
        typeTestIdPrefix: '',
        contentTypeOptions: () => [],
        contentType: '',
        hasMedia: false,
        captionCollapsed: false,
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
}>();

const slots = useSlots();
const captionId = computed(() => `${props.testIdPrefix}-caption`);
const hintId = computed(() => `${props.testIdPrefix}-templates-hint`);
const showsCaption = computed(
    () => !CAPTIONLESS_CONTENT_TYPES.has(props.contentType),
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
            class="shrink-0"
        />
        <div class="flex min-w-0 flex-1 flex-col gap-4">
            <div
                v-if="platform && showsHeader"
                class="flex min-h-6 flex-wrap items-center gap-3"
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
            <template v-if="showsCaption">
                <button
                    v-if="captionCollapsed"
                    type="button"
                    class="truncate px-[9px] text-start text-sm text-muted-foreground"
                    :data-testid="`${testIdPrefix}-caption-collapsed`"
                    @click="emit('expand-caption')"
                >
                    {{ content }}
                </button>
                <div v-else class="relative flex min-h-40 flex-1 flex-col">
                    <label class="sr-only" :for="captionId">{{
                        $t('posts.composer.content_label')
                    }}</label>
                    <textarea
                        :id="captionId"
                        :value="content"
                        :data-testid="captionTestId"
                        :aria-describedby="content ? undefined : hintId"
                        class="min-h-40 w-full flex-1 resize-none bg-transparent px-[9px] pt-0.5 pb-1 text-sm outline-none"
                        @input="
                            emit(
                                'update:content',
                                ($event.target as HTMLTextAreaElement).value,
                            )
                        "
                        @paste="emit('paste', $event)"
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
            </template>
            <slot name="replies" />
            <div>
                <slot
                    v-if="hasMedia || !MEDIALESS_CONTENT_TYPES.has(contentType)"
                    name="media"
                />
                <slot name="toolbar" />
            </div>
            <slot name="settings" />
        </div>
    </div>
</template>
