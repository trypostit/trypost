<script setup lang="ts">
import { Link, useHttp } from '@inertiajs/vue3';
import {
    IconClipboardText,
    IconPlus,
    IconSearch,
    IconSettings,
} from '@tabler/icons-vue';
import { computed, nextTick, ref, useTemplateRef } from 'vue';

import FilterEmptyState from '@/components/FilterEmptyState.vue';
import InboxTooltip from '@/components/inbox/InboxTooltip.vue';
import SavedReplyForm from '@/components/saved-replies/SavedReplyForm.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { useSavedReplies } from '@/composables/inbox/useSavedReplies';
import { type SavedReply, type SavedReplyPerson, fillSavedReply } from '@/lib/savedReplies';
import { filterBySearch } from '@/lib/search';
import {
    index as savedRepliesIndex,
    store as savedRepliesStore,
} from '@/routes/app/saved-replies';

const props = withDefaults(
    defineProps<{
        person: SavedReplyPerson;
        align?: 'start' | 'center' | 'end';
        testIdPrefix?: string;
    }>(),
    { align: 'end', testIdPrefix: 'saved-replies' },
);

const emit = defineEmits<{
    insert: [text: string];
    restoreFocus: [];
}>();

const { savedReplies, remember } = useSavedReplies();

const open = ref(false);
const view = ref<'list' | 'create'>('list');
const search = ref('');
const inserted = ref(false);
const saveError = ref(false);
const list = useTemplateRef<HTMLElement>('list');

const form = useHttp({ name: '', content: '' });

const filtered = computed(() =>
    filterBySearch(savedReplies.value, search.value, (savedReply) => [
        savedReply.name,
        savedReply.content,
    ]),
);

const items = (): HTMLElement[] =>
    Array.from(list.value?.querySelectorAll<HTMLElement>('[data-saved-reply-item]') ?? []);

const focusFirstItem = (event: Event): void => {
    event.preventDefault();

    nextTick(() => {
        items()[0]?.focus();
    });
};

const handleOpenChange = (value: boolean): void => {
    open.value = value;

    if (value) {
        view.value = 'list';
        search.value = '';
        inserted.value = false;
    }
};

const handleCloseAutoFocus = (event: Event): void => {
    if (!inserted.value) {
        return;
    }

    event.preventDefault();
    inserted.value = false;
    emit('restoreFocus');
};

const insert = (savedReply: SavedReply): void => {
    inserted.value = true;
    emit('insert', fillSavedReply(savedReply.content, props.person));
    open.value = false;
};

const moveFocus = (event: KeyboardEvent, step: -1 | 1): void => {
    const elements = items();
    const index = elements.indexOf(event.target as HTMLElement);

    if (index === -1) {
        return;
    }

    event.preventDefault();
    elements[(index + step + elements.length) % elements.length]?.focus();
};

const startCreate = (): void => {
    form.name = '';
    form.content = '';
    form.clearErrors();
    saveError.value = false;
    view.value = 'create';

    nextTick(() => {
        document.getElementById(`${props.testIdPrefix}-form-name`)?.focus();
    });
};

const showList = (): void => {
    view.value = 'list';
};

const save = async (): Promise<void> => {
    saveError.value = false;

    try {
        const savedReply = (await form.post(savedRepliesStore.url())) as SavedReply | undefined;

        if (savedReply) {
            remember({ id: savedReply.id, name: savedReply.name, content: savedReply.content });
            search.value = '';
            view.value = 'list';
        }
    } catch {
        saveError.value = !form.hasErrors;
    }
};
</script>

<template>
    <Popover :open="open" @update:open="handleOpenChange">
        <PopoverTrigger as-child>
            <slot name="trigger" :label="$t('saved_replies.picker.open')" />
        </PopoverTrigger>
        <PopoverContent
            class="w-80 max-w-[calc(100vw-2rem)] p-3"
            :align="align"
            :data-testid="`${testIdPrefix}-popover`"
            @open-auto-focus="focusFirstItem"
            @close-auto-focus="handleCloseAutoFocus"
        >
            <template v-if="view === 'list'">
                <div class="mb-2 flex items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold text-foreground">
                        {{ $t('saved_replies.title') }}
                    </h3>
                    <div class="flex items-center gap-1">
                        <InboxTooltip :label="$t('saved_replies.picker.manage')">
                            <Button
                                as-child
                                size="icon-sm"
                                variant="ghost"
                                class="text-muted-foreground"
                            >
                                <Link
                                    :href="savedRepliesIndex.url()"
                                    :aria-label="$t('saved_replies.picker.manage')"
                                    :data-testid="`${testIdPrefix}-settings`"
                                >
                                    <IconSettings class="size-4" />
                                </Link>
                            </Button>
                        </InboxTooltip>
                        <InboxTooltip :label="$t('saved_replies.new')">
                            <Button
                                type="button"
                                size="icon-sm"
                                variant="ghost"
                                class="text-muted-foreground"
                                :aria-label="$t('saved_replies.new')"
                                :data-testid="`${testIdPrefix}-create`"
                                @click="startCreate"
                            >
                                <IconPlus class="size-4" />
                            </Button>
                        </InboxTooltip>
                    </div>
                </div>

                <FilterEmptyState
                    v-if="savedReplies.length === 0"
                    :icon="IconClipboardText"
                    :title="$t('saved_replies.empty_title')"
                    :description="$t('saved_replies.empty_description')"
                    :test-id="`${testIdPrefix}-empty`"
                >
                    <Button
                        type="button"
                        size="sm"
                        :data-testid="`${testIdPrefix}-empty-create`"
                        @click="startCreate"
                    >
                        <IconPlus class="size-4" />
                        {{ $t('saved_replies.new') }}
                    </Button>
                </FilterEmptyState>

                <template v-else>
                    <div class="relative mb-2">
                        <IconSearch
                            class="pointer-events-none absolute start-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <Input
                            v-model="search"
                            class="h-8 ps-8"
                            :placeholder="$t('saved_replies.picker.search')"
                            :aria-label="$t('saved_replies.search')"
                            :data-testid="`${testIdPrefix}-search`"
                        />
                    </div>
                    <p
                        v-if="filtered.length === 0"
                        class="px-1 py-3 text-sm text-muted-foreground"
                        :data-testid="`${testIdPrefix}-no-results`"
                    >
                        {{ $t('saved_replies.no_search_results') }}
                    </p>
                    <ul
                        v-else
                        ref="list"
                        class="-mx-1 flex max-h-64 flex-col gap-1.5 overflow-y-auto px-1 py-0.5"
                    >
                        <li v-for="savedReply in filtered" :key="savedReply.id">
                            <button
                                type="button"
                                data-saved-reply-item
                                class="group flex w-full items-center gap-2 rounded-lg border border-border bg-card px-3 py-2 text-start transition-control hover:bg-muted focus-visible:bg-muted focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                                :data-testid="`${testIdPrefix}-item-${savedReply.id}`"
                                @click="insert(savedReply)"
                                @keydown.down="moveFocus($event, 1)"
                                @keydown.up="moveFocus($event, -1)"
                            >
                                <span class="flex min-w-0 flex-1 flex-col">
                                    <span class="truncate text-sm font-emphasis text-foreground">{{
                                        savedReply.name
                                    }}</span>
                                    <span class="truncate text-xs text-muted-foreground">{{
                                        savedReply.content
                                    }}</span>
                                </span>
                                <span
                                    class="shrink-0 rounded-md bg-primary px-2 py-1 text-xs font-emphasis text-primary-foreground opacity-0 transition-opacity group-hover:opacity-100 group-focus-visible:opacity-100"
                                    aria-hidden="true"
                                    :data-testid="`${testIdPrefix}-insert-${savedReply.id}`"
                                    >{{ $t('saved_replies.picker.insert') }}</span
                                >
                            </button>
                        </li>
                    </ul>
                </template>
            </template>

            <template v-else>
                <h3 class="mb-3 text-sm font-semibold text-foreground">
                    {{ $t('saved_replies.create.title') }}
                </h3>
                <SavedReplyForm
                    v-model:name="form.name"
                    v-model:content="form.content"
                    mode="create"
                    :id-prefix="`${testIdPrefix}-form`"
                    compact
                    :errors="form.errors"
                    :processing="form.processing"
                    @submit="save"
                    @cancel="showList"
                />
                <p
                    v-if="saveError"
                    role="alert"
                    class="mt-2 text-sm text-destructive-text"
                >
                    {{ $t('saved_replies.save_failed') }}
                </p>
            </template>
        </PopoverContent>
    </Popover>
</template>
