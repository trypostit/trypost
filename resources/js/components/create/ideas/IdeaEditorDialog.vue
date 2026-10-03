<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import {
    IconCheck,
    IconChevronDown,
    IconMoodSmile,
    IconSearch,
    IconSparkles,
    IconLayoutCards,
    IconTag,
    IconX,
} from '@tabler/icons-vue';
import { computed, nextTick, ref, watch } from 'vue';

import WritingAssistantPanel from '@/components/ai/WritingAssistantPanel.vue';
import MediaTray from '@/components/media/MediaTray.vue';
import UnsplashDialog from '@/components/media/UnsplashDialog.vue';
import MediaEditorDialog, {
    type MediaEditChange,
} from '@/components/posts/composer/MediaEditorDialog.vue';
import MediaSourceMenu from '@/components/posts/composer/MediaSourceMenu.vue';
import EmojiPicker from '@/components/posts/EmojiPicker.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { openPostComposer } from '@/composables/useGlobalPostComposer';
import { useMediaEditSwap, withMediaAdded } from '@/composables/useMediaEditSwap';
import {
    type MediaImportStarted,
    useMediaImport,
} from '@/composables/useMediaImport';
import { useMediaUpload } from '@/composables/useMediaUpload';
import { editorTabsFor, rulesFor } from '@/lib/mediaEditor';
import { isGooglePickerOpen } from '@/lib/mediaSources/googleDrive';
import { store, update } from '@/routes/app/create/ideas';
import type { MediaUploadLimits, SharedData } from '@/types';
import {
    columnKey,
    type IdeaEditorState,
    type IdeaLabel,
    type IdeaStage,
} from '@/types/idea';
import type { MediaItem } from '@/types/media';

const props = defineProps<{
    editor: IdeaEditorState;
    stages: IdeaStage[];
    labels: IdeaLabel[];
    closeUrl: string;
    listReload: { only: string[]; reset: string[] };
}>();

const idea = props.editor.mode === 'edit' ? props.editor.idea : null;

const form = useForm({
    title: idea?.title ?? '',
    body: idea?.body ?? '',
    idea_stage_id: idea
        ? idea.idea_stage_id
        : props.editor.mode === 'create'
          ? props.editor.idea_stage_id
          : null,
    label_ids: [...(idea?.label_ids ?? [])],
    media_ids: [] as string[],
});

const media = ref<MediaItem[]>([...(idea?.media ?? [])]);
const submittedMediaIds = ref<string[]>([]);

const page = usePage<SharedData>();
const mediaUploadLimits = (): MediaUploadLimits =>
    page.props.mediaUploadLimits ?? {
        max_bytes: { image: 0, video: 0, document: 0 },
        extensions: { image: [], video: [], document: [] },
        upload_retention_hours: 0,
        heic: false,
    };
const appendMedia = (
    item: MediaItem,
    _key?: string,
    replaces: string | null = null,
): void => {
    media.value = withMediaAdded(media.value, item, replaces);
};
const uploader = useMediaUpload({
    limits: mediaUploadLimits,
    onReady: appendMedia,
});
const unsplashOpen = ref(false);

const openUnsplash = (): void => {
    unsplashOpen.value = true;
};

const mediaImport = useMediaImport();
const onImportStarted = (started: MediaImportStarted): void =>
    mediaImport.track(uploader, started);
const {
    uploading: editUploading,
    failed: editFailed,
    swap: swapEditedMedia,
} = useMediaEditSwap();
const editing = ref(false);
const editTarget = ref<{ indexes: number[]; initialIndex: number } | null>(
    null,
);
const editItems = computed(() =>
    (editTarget.value?.indexes ?? []).flatMap((index) =>
        media.value[index] ? [media.value[index]] : [],
    ),
);
const openEditor = (index: number): void => {
    if (editUploading.value) return;
    const rules = rulesFor([]);
    const indexes = media.value.flatMap((item, position) =>
        editorTabsFor(item, rules).length > 0 ? [position] : [],
    );
    if (!indexes.includes(index)) return;
    editFailed.value = false;
    editTarget.value = { indexes, initialIndex: indexes.indexOf(index) };
    editing.value = true;
};
const onMediaEdited = async (changes: MediaEditChange[]): Promise<void> => {
    const target = editTarget.value;
    if (!target) return;
    await swapEditedMedia({
        changes,
        indexes: target.indexes,
        items: () => media.value,
        write: (items) => {
            media.value = items;
        },
    });
    editTarget.value = null;
};
const mediaBlocked = computed(
    () => uploader.busy.value || uploader.failed.value || editUploading.value,
);

form.transform((data) => ({
    ...data,
    media_ids: media.value.map((item) => item.id),
}));
const open = ref(true);
const assistantOpen = ref(false);

const closeAssistant = (): void => {
    assistantOpen.value = false;
};

const openAssistant = (): void => {
    assistantOpen.value = true;
};

const toggleAssistant = (): void => {
    assistantOpen.value = !assistantOpen.value;
};

const stageOpen = ref(false);
const stageSearch = ref('');
const labelsOpen = ref(false);
const labelSearch = ref('');
const emojiOpen = ref(false);
const body = ref<HTMLTextAreaElement | null>(null);

const isEmpty = computed(
    () =>
        !form.title.trim() && !form.body.trim() && media.value.length === 0,
);

const currentStage = computed(
    () =>
        props.stages.find((stage) => stage.id === form.idea_stage_id) ?? null,
);

const filteredStages = computed(() => {
    const query = stageSearch.value.trim().toLocaleLowerCase();

    return query
        ? props.stages.filter((stage) =>
              stage.name.toLocaleLowerCase().includes(query),
          )
        : props.stages;
});

const selectedLabels = computed(() =>
    props.labels.filter((label) => form.label_ids.includes(label.id)),
);

const filteredLabels = computed(() => {
    const query = labelSearch.value.trim().toLocaleLowerCase();

    return query
        ? props.labels.filter((label) =>
              label.name.toLocaleLowerCase().includes(query),
          )
        : props.labels;
});

watch(stageOpen, (isOpen) => {
    if (!isOpen) {
        stageSearch.value = '';
    }
});

watch(labelsOpen, (isOpen) => {
    if (!isOpen) {
        labelSearch.value = '';
    }
});

const close = (changed = false): void => {
    open.value = false;
    router.visit(props.closeUrl, {
        preserveState: true,
        preserveScroll: true,
        only: changed ? ['editor', ...props.listReload.only] : ['editor'],
        reset: changed ? props.listReload.reset : [],
    });
};

const onOpenChange = (value: boolean): void => {
    if (!value) {
        close();
    }
};

const selectStage = (stageId: string | null): void => {
    form.idea_stage_id = stageId;
    stageOpen.value = false;
};

const focusOption = (event: KeyboardEvent, step: number): void => {
    const options = [
        ...(event.currentTarget as HTMLElement).querySelectorAll<HTMLElement>(
            '[role="option"]',
        ),
    ];
    const index = options.indexOf(document.activeElement as HTMLElement);

    options[(index + step + options.length) % options.length]?.focus();
};

const toggleLabel = (id: string): void => {
    form.label_ids = form.label_ids.includes(id)
        ? form.label_ids.filter((labelId) => labelId !== id)
        : [...form.label_ids, id];
};

const insertEmoji = (emoji: string): void => {
    const element = body.value;
    const start = element?.selectionStart ?? form.body.length;
    const end = element?.selectionEnd ?? form.body.length;

    form.body = `${form.body.slice(0, start)}${emoji}${form.body.slice(end)}`;
    emojiOpen.value = false;

    void nextTick(() => {
        element?.focus();
        element?.setSelectionRange(
            start + emoji.length,
            start + emoji.length,
        );
    });
};

const insertIntoBody = (text: string): void => {
    form.body = form.body.trim() ? `${form.body}\n\n${text}` : text;
};

const save = (): void => {
    if (isEmpty.value || form.processing || mediaBlocked.value) {
        return;
    }

    submittedMediaIds.value = media.value.map((item) => item.id);

    const options = {
        preserveScroll: true,
        preserveState: true,
        only: ['editor'],
        onSuccess: () => close(true),
    };

    if (idea) {
        form.put(update.url(idea.id), options);
    } else {
        form.post(store.url(), options);
    }
};

const createPost = (): void => {
    if (isEmpty.value || mediaBlocked.value) {
        return;
    }

    const draft = {
        content: form.body.trim() ? form.body : form.title,
        media: [...media.value],
        scheduled_at: null,
        label_ids: [...form.label_ids],
    };

    open.value = false;
    router.visit(props.closeUrl, {
        preserveState: true,
        preserveScroll: true,
        only: ['editor'],
        onSuccess: () => openPostComposer({ draft }),
    });
};

const mediaErrorKey = /^media_ids\.(\d+)$/;

const mediaErrors = computed<Record<number, string>>(() => {
    const byId = new Map<string, string>();
    for (const [key, message] of Object.entries(
        form.errors as Record<string, string>,
    )) {
        const match = mediaErrorKey.exec(key);
        const id = match ? submittedMediaIds.value[Number(match[1])] : undefined;
        if (id && !byId.has(id)) byId.set(id, message);
    }

    return Object.fromEntries(
        media.value.flatMap((item, index) => {
            const message = byId.get(item.id);

            return message ? [[index, message]] : [];
        }),
    );
});

const errors = computed(() =>
    Object.entries(form.errors as Record<string, string>)
        .filter(([key]) => !mediaErrorKey.test(key))
        .map(([, message]) => message),
);

const iconButtonClass =
    'flex size-8 items-center justify-center rounded-lg text-foreground transition-control hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring disabled:pointer-events-none disabled:opacity-50';
</script>

<template>
    <Dialog :open="open" @update:open="onOpenChange">
        <DialogContent
            class="top-0 left-0 flex h-dvh max-h-dvh w-screen max-w-none translate-x-0 translate-y-0 flex-col gap-0 overflow-hidden rounded-none p-0 sm:top-1/2 sm:left-1/2 sm:h-[min(850px,calc(100dvh-3rem))] sm:w-auto sm:max-w-[calc(100vw-3rem)] sm:-translate-x-1/2 sm:-translate-y-1/2 sm:flex-row sm:rounded-2xl"
            :aria-describedby="undefined"
            data-testid="idea-editor"
            :disable-outside-pointer-events="!isGooglePickerOpen"
            @interact-outside="isGooglePickerOpen && $event.preventDefault()"
        >
            <aside
                v-if="assistantOpen"
                class="flex max-h-[45dvh] min-h-0 shrink-0 flex-col border-b bg-muted sm:max-h-none sm:w-[370px] sm:border-e sm:border-b-0"
                data-testid="idea-editor-assistant"
            >
                <div
                    class="flex shrink-0 items-center justify-between gap-2 px-6 pt-6 pb-4"
                >
                    <h3 class="text-base leading-5 font-medium">
                        {{ $t('posts.composer.assistant_title') }}
                    </h3>
                    <button
                        type="button"
                        :class="iconButtonClass"
                        :aria-label="$t('common.close')"
                        data-testid="idea-editor-assistant-close"
                        @click="closeAssistant"
                    >
                        <IconX class="size-4" />
                    </button>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto px-6 pb-6">
                    <WritingAssistantPanel
                        :content="form.body"
                        :channel="null"
                        @insert="insertIntoBody"
                        @replace="(text: string) => (form.body = text)"
                    />
                </div>
            </aside>

            <section
                class="flex min-h-0 w-full flex-1 flex-col sm:w-[632px] sm:flex-none"
            >
                <header
                    class="flex shrink-0 flex-wrap items-center gap-2 px-6 pt-6 pe-14 pb-4"
                >
                    <DialogTitle class="me-auto">
                        {{
                            idea
                                ? $t('create.ideas.editor.edit_title')
                                : $t('create.ideas.editor.create_title')
                        }}
                    </DialogTitle>

                    <Popover v-model:open="stageOpen">
                        <PopoverTrigger as-child>
                            <Button
                                type="button"
                                variant="outline"
                                class="max-w-48"
                                :aria-label="$t('create.ideas.editor.stage')"
                                data-testid="idea-editor-stage"
                            >
                                <IconLayoutCards class="size-4" />
                                <span class="truncate">{{
                                    currentStage?.name ??
                                    $t('create.ideas.unassigned')
                                }}</span>
                                <IconChevronDown
                                    class="size-4 shrink-0 text-muted-foreground"
                                />
                            </Button>
                        </PopoverTrigger>
                        <PopoverContent class="w-64 p-3" align="end">
                            <div class="relative mb-2">
                                <IconSearch
                                    class="pointer-events-none absolute top-1/2 left-2 size-4 -translate-y-1/2 text-muted-foreground"
                                />
                                <input
                                    v-model="stageSearch"
                                    type="search"
                                    :aria-label="$t('create.ideas.stages_search')"
                                    :placeholder="
                                        $t('create.ideas.stages_search')
                                    "
                                    class="h-8 w-full rounded-lg border border-input bg-background pr-2 pl-8 text-sm transition-control outline-none placeholder:text-subtle-foreground focus-visible:border-primary-text"
                                />
                            </div>
                            <div
                                role="listbox"
                                :aria-label="$t('create.ideas.editor.stage')"
                                class="max-h-60 space-y-0.5 overflow-y-auto"
                                @keydown.down.prevent="focusOption($event, 1)"
                                @keydown.up.prevent="focusOption($event, -1)"
                            >
                                <button
                                    v-for="option in [
                                        null,
                                        ...filteredStages.map(
                                            (stage) => stage.id,
                                        ),
                                    ]"
                                    :key="columnKey(option)"
                                    type="button"
                                    role="option"
                                    :aria-selected="
                                        form.idea_stage_id === option
                                    "
                                    :data-testid="`idea-editor-stage-option-${columnKey(option)}`"
                                    class="flex h-8 w-full items-center gap-2 rounded-md px-2 text-left text-sm transition-control hover:bg-accent focus-visible:bg-accent focus-visible:outline-none"
                                    @click="selectStage(option)"
                                >
                                    <span class="min-w-0 flex-1 truncate">{{
                                        option === null
                                            ? $t('create.ideas.unassigned')
                                            : stages.find(
                                                  (stage) =>
                                                      stage.id === option,
                                              )?.name
                                    }}</span>
                                    <IconCheck
                                        v-if="form.idea_stage_id === option"
                                        class="size-4 shrink-0"
                                    />
                                </button>
                            </div>
                        </PopoverContent>
                    </Popover>

                    <Popover v-model:open="labelsOpen">
                        <PopoverTrigger as-child>
                            <Button
                                type="button"
                                variant="outline"
                                class="max-w-56"
                                data-testid="idea-editor-labels"
                            >
                                <IconTag class="size-4 shrink-0" />
                                <span v-if="!selectedLabels.length">{{
                                    $t('create.ideas.editor.labels')
                                }}</span>
                                <span
                                    v-else
                                    class="flex min-w-0 items-center gap-1.5 overflow-hidden"
                                >
                                    <span
                                        v-for="label in selectedLabels.slice(
                                            0,
                                            2,
                                        )"
                                        :key="label.id"
                                        class="flex min-w-0 items-center gap-1"
                                    >
                                        <span
                                            class="size-2 shrink-0 rounded-full"
                                            :style="{
                                                backgroundColor: label.color,
                                            }"
                                        />
                                        <span class="truncate">{{
                                            label.name
                                        }}</span>
                                    </span>
                                    <span
                                        v-if="selectedLabels.length > 2"
                                        class="shrink-0 text-muted-foreground"
                                        >+{{ selectedLabels.length - 2 }}</span
                                    >
                                </span>
                                <IconChevronDown
                                    class="size-4 shrink-0 text-muted-foreground"
                                />
                            </Button>
                        </PopoverTrigger>
                        <PopoverContent class="w-72 p-3" align="end">
                            <div class="relative mb-2">
                                <IconSearch
                                    class="pointer-events-none absolute top-1/2 left-2 size-4 -translate-y-1/2 text-muted-foreground"
                                />
                                <input
                                    v-model="labelSearch"
                                    type="search"
                                    :aria-label="
                                        $t('posts.label_search_placeholder')
                                    "
                                    :placeholder="
                                        $t('posts.label_search_placeholder')
                                    "
                                    class="h-8 w-full rounded-lg border border-input bg-background pr-2 pl-8 text-sm transition-control outline-none placeholder:text-subtle-foreground focus-visible:border-primary-text"
                                />
                            </div>
                            <p
                                v-if="!filteredLabels.length"
                                class="px-2 py-3 text-sm text-muted-foreground"
                            >
                                {{ $t('posts.no_labels') }}
                            </p>
                            <div
                                role="listbox"
                                aria-multiselectable="true"
                                :aria-label="$t('create.ideas.editor.labels')"
                                class="max-h-60 space-y-0.5 overflow-y-auto"
                                @keydown.down.prevent="focusOption($event, 1)"
                                @keydown.up.prevent="focusOption($event, -1)"
                            >
                                <button
                                    v-for="label in filteredLabels"
                                    :key="label.id"
                                    type="button"
                                    role="option"
                                    :data-testid="`idea-editor-label-${label.id}`"
                                    :aria-selected="
                                        form.label_ids.includes(label.id)
                                    "
                                    class="flex h-8 w-full items-center gap-2 rounded-md px-2 text-left text-sm transition-control hover:bg-accent focus-visible:bg-accent focus-visible:outline-none"
                                    @click.stop="toggleLabel(label.id)"
                                >
                                    <span
                                        class="flex size-4 shrink-0 items-center justify-center rounded-sm border"
                                        :class="
                                            form.label_ids.includes(label.id)
                                                ? 'border-primary-strong bg-primary-strong text-primary-strong-foreground'
                                                : 'border-input'
                                        "
                                    >
                                        <IconCheck
                                            v-if="
                                                form.label_ids.includes(
                                                    label.id,
                                                )
                                            "
                                            class="size-3"
                                        />
                                    </span>
                                    <span
                                        class="size-2.5 shrink-0 rounded-full"
                                        :style="{
                                            backgroundColor: label.color,
                                        }"
                                    />
                                    <span class="truncate">{{
                                        label.name
                                    }}</span>
                                </button>
                            </div>
                        </PopoverContent>
                    </Popover>
                </header>

                <div class="flex min-h-0 flex-1 flex-col overflow-y-auto px-6">
                    <input
                        v-model="form.title"
                        type="text"
                        data-testid="idea-editor-title"
                        :aria-label="$t('create.ideas.editor.title_placeholder')"
                        :placeholder="
                            $t('create.ideas.editor.title_placeholder')
                        "
                        class="w-full bg-transparent py-1 text-lg font-semibold outline-none placeholder:text-subtle-foreground"
                    />
                    <div class="relative mt-2 flex min-h-40 flex-1 flex-col">
                        <textarea
                            ref="body"
                            v-model="form.body"
                            data-testid="idea-editor-body"
                            :aria-label="
                                $t('create.ideas.editor.body_placeholder')
                            "
                            class="min-h-40 w-full flex-1 resize-none bg-transparent py-1 text-sm outline-none"
                        />
                        <div
                            v-if="!form.body"
                            class="pointer-events-none absolute inset-x-0 top-1 flex flex-wrap items-center gap-1.5 text-sm text-subtle-foreground"
                        >
                            <span>{{
                                $t('create.ideas.editor.body_placeholder')
                            }}</span>
                            <button
                                type="button"
                                class="pointer-events-auto inline-flex h-6 items-center gap-1 rounded-md bg-primary-subtle px-2 text-xs font-medium text-primary-text transition-control hover:brightness-95"
                                data-testid="idea-editor-use-assistant"
                                @click="openAssistant"
                            >
                                <IconSparkles class="size-3.5" />
                                {{ $t('create.ideas.editor.use_assistant') }}
                            </button>
                        </div>
                    </div>

                    <MediaTray
                        v-model:items="media"
                        class="py-4"
                        test-id-prefix="idea"
                        :limits="mediaUploadLimits()"
                        :uploader="uploader"
                        :disabled="form.processing || editUploading"
                        :item-errors="mediaErrors"
                        :content-types="[]"
                        @edit="openEditor($event.index)"
                        @import-started="onImportStarted"
                    />
                    <p
                        v-if="editFailed"
                        role="alert"
                        class="pb-3 text-sm text-destructive"
                        data-testid="idea-editor-crop-error"
                    >
                        {{ $t('posts.composer.crop_upload_failed') }}
                    </p>

                    <div
                        v-if="errors.length"
                        role="alert"
                        class="space-y-1 pb-3 text-sm text-destructive"
                        data-testid="idea-editor-error"
                    >
                        <p v-for="message in errors" :key="message">
                            {{ message }}
                        </p>
                    </div>
                    <p
                        v-if="uploader.failed.value"
                        role="alert"
                        class="pb-3 text-sm text-destructive"
                        data-testid="idea-editor-upload-blocked"
                    >
                        {{ $t('posts.composer.upload_blocked') }}
                    </p>
                </div>

                <div class="flex shrink-0 items-center gap-1 px-5 py-2">
                    <MediaSourceMenu
                        test-id-prefix="idea-editor"
                        @import-started="onImportStarted"
                        @open-unsplash="openUnsplash"
                    />
                    <span
                        class="mx-1 h-6 w-px bg-border"
                        aria-hidden="true"
                    />
                    <Popover v-model:open="emojiOpen">
                        <PopoverTrigger as-child>
                            <button
                                type="button"
                                :class="iconButtonClass"
                                :aria-label="$t('posts.edit.emoji_picker.search')"
                                :title="$t('posts.edit.emoji_picker.search')"
                                data-testid="idea-editor-emoji"
                            >
                                <IconMoodSmile class="size-4" />
                            </button>
                        </PopoverTrigger>
                        <PopoverContent class="w-auto p-0" align="start">
                            <EmojiPicker @select="insertEmoji" />
                        </PopoverContent>
                    </Popover>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        :aria-pressed="assistantOpen"
                        data-testid="idea-editor-ai"
                        @click="toggleAssistant"
                    >
                        <IconSparkles class="size-4" />
                        {{ $t('posts.composer.assistant_title') }}
                    </Button>
                </div>

                <DialogFooter class="shrink-0 border-t px-6 py-4">
                    <Button
                        type="button"
                        variant="ghost"
                        data-testid="idea-editor-cancel"
                        @click="close()"
                    >
                        {{ $t('create.ideas.cancel') }}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        data-testid="idea-editor-create-post"
                        :disabled="isEmpty || mediaBlocked"
                        @click="createPost"
                    >
                        {{ $t('create.ideas.editor.create_post') }}
                    </Button>
                    <Button
                        type="button"
                        data-testid="idea-editor-save"
                        :disabled="isEmpty || form.processing || mediaBlocked"
                        @click="save"
                    >
                        {{ $t('create.ideas.editor.save') }}
                    </Button>
                </DialogFooter>
            </section>
        </DialogContent>
    </Dialog>
    <MediaEditorDialog
        v-model:open="editing"
        :items="editItems"
        :initial-index="editTarget?.initialIndex ?? 0"
        :content-types="[]"
        @apply="onMediaEdited"
    />
    <UnsplashDialog v-model:open="unsplashOpen" @picked="appendMedia" />
</template>
