<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import {
    IconCheck,
    IconChevronDown,
    IconLayoutGrid,
    IconLoader2,
} from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import {
    index as boardsIndex,
    store as boardsStore,
} from '@/actions/App/Http/Controllers/App/PinterestBoardController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { usePinterestBoards } from '@/composables/usePinterestBoards';
import type { PinterestBoard, PinterestBoardsPayload } from '@/types';

const props = withDefaults(
    defineProps<{
        accountId: string;
        username?: string | null;
        initialBoards: PinterestBoardsPayload;
        triggerId?: string;
        disabled?: boolean;
        invalid?: boolean;
    }>(),
    {
        username: null,
        triggerId: undefined,
        disabled: false,
        invalid: false,
    },
);

const boardId = defineModel<string | null>({ default: null });

const open = ref(false);
const search = ref('');

const { boardsFor, replaceBoards, addBoard } = usePinterestBoards();

const payload = computed(() =>
    boardsFor(props.accountId, props.initialBoards),
);
const boards = computed(() => payload.value.boards);

const selectedBoard = computed<PinterestBoard | undefined>(() =>
    boards.value.find((board) => board.id === boardId.value),
);

const filteredBoards = computed(() => {
    const query = search.value.trim().toLocaleLowerCase();

    return query
        ? boards.value.filter((board) =>
              board.name.toLocaleLowerCase().includes(query),
          )
        : boards.value;
});

const refreshHttp = useHttp<Record<string, never>, PinterestBoardsPayload>(
    {},
);
const createHttp = useHttp<{ name: string }, PinterestBoard>({ name: '' });
const refreshFailed = ref(false);
const createFailed = ref(false);

const refresh = async () => {
    if (refreshHttp.processing) {
        return;
    }

    refreshFailed.value = false;

    try {
        const response = await refreshHttp.get(
            boardsIndex.url(props.accountId),
        );

        if (response) {
            replaceBoards(props.accountId, response);
        }
    } catch {
        refreshFailed.value = true;
    }
};

const pick = (board: PinterestBoard) => {
    boardId.value = board.id;
    open.value = false;
};

const create = async () => {
    if (createHttp.processing) {
        return;
    }

    createFailed.value = false;

    try {
        const board = await createHttp.post(boardsStore.url(props.accountId));

        if (board) {
            addBoard(props.accountId, props.initialBoards, board);
            createHttp.reset();
            createHttp.name = '';
            search.value = '';
            pick(board);
        }
    } catch {
        createFailed.value = true;
    }
};
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <button
                :id="triggerId"
                type="button"
                role="combobox"
                :aria-expanded="open"
                :aria-invalid="invalid ? true : undefined"
                :disabled="disabled"
                data-testid="pinterest-board-trigger"
                class="flex min-h-8 w-full items-center justify-between gap-2 rounded-md border border-input bg-card px-1.5 py-1 text-left text-sm transition-[color,box-shadow] outline-none focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive"
            >
                <span
                    v-if="selectedBoard"
                    class="inline-flex min-w-0 items-center gap-1.5 rounded-md border border-border px-1.5 py-0.5"
                    data-testid="pinterest-board-selected"
                >
                    <img
                        v-if="selectedBoard.cover_url"
                        :src="selectedBoard.cover_url"
                        alt=""
                        class="size-5 shrink-0 rounded-sm object-cover"
                    />
                    <span
                        v-else
                        class="flex size-5 shrink-0 items-center justify-center rounded-sm bg-muted text-muted-foreground"
                    >
                        <IconLayoutGrid class="size-3" />
                    </span>
                    <span class="truncate text-foreground">{{
                        selectedBoard.name
                    }}</span>
                </span>
                <span v-else class="px-0.5 text-subtle-foreground">{{
                    $t('posts.form.pinterest.select_board')
                }}</span>
                <IconChevronDown class="size-4 shrink-0 text-muted-foreground" />
            </button>
        </PopoverTrigger>
        <PopoverContent
            align="start"
            class="w-[min(18rem,calc(100vw-2rem))] p-2"
            data-testid="pinterest-board-picker"
        >
            <Input
                v-model="search"
                type="search"
                :placeholder="$t('posts.form.pinterest.search_board')"
                :aria-label="$t('posts.form.pinterest.search_board')"
                data-testid="pinterest-board-search"
            />
            <div class="mt-2 flex items-center justify-between gap-2 px-1">
                <span class="truncate text-xs text-muted-foreground">{{
                    username ? `@${username}` : ''
                }}</span>
                <button
                    type="button"
                    class="inline-flex shrink-0 items-center gap-1 text-xs font-medium text-primary-text hover:text-primary-text-hover disabled:opacity-50"
                    :disabled="refreshHttp.processing"
                    data-testid="pinterest-boards-refresh"
                    @click="refresh"
                >
                    <IconLoader2
                        v-if="refreshHttp.processing"
                        class="size-3 animate-spin"
                    />
                    {{ $t('posts.form.pinterest.refresh_boards') }}
                </button>
            </div>
            <InputError
                class="px-1"
                :message="
                    refreshHttp.errors.boards ??
                    (refreshFailed
                        ? $t('posts.form.pinterest.boards_failed')
                        : undefined)
                "
            />
            <ul
                class="mt-1 max-h-56 overflow-y-auto"
                role="listbox"
                :aria-label="$t('posts.form.pinterest.pinning_to')"
            >
                <li
                    v-for="board in filteredBoards"
                    :key="board.id"
                    role="option"
                    :aria-selected="board.id === boardId"
                >
                    <button
                        type="button"
                        class="flex w-full items-center gap-2 rounded-md px-1.5 py-1.5 text-left text-sm hover:bg-accent"
                        :class="{ 'bg-accent': board.id === boardId }"
                        :data-testid="`pinterest-board-option-${board.id}`"
                        @click="pick(board)"
                    >
                        <IconCheck
                            class="size-4 shrink-0"
                            :class="
                                board.id === boardId
                                    ? 'text-foreground'
                                    : 'invisible'
                            "
                        />
                        <img
                            v-if="board.cover_url"
                            :src="board.cover_url"
                            alt=""
                            class="size-7 shrink-0 rounded-md object-cover"
                        />
                        <span
                            v-else
                            class="flex size-7 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground"
                        >
                            <IconLayoutGrid class="size-3.5" />
                        </span>
                        <span class="truncate">{{ board.name }}</span>
                    </button>
                </li>
            </ul>
            <p
                v-if="filteredBoards.length === 0"
                class="px-1 py-2 text-sm text-muted-foreground"
                data-testid="pinterest-boards-empty"
            >
                {{
                    boards.length
                        ? $t('posts.form.pinterest.no_board_found')
                        : $t('posts.form.pinterest.no_boards_yet')
                }}
            </p>
            <p
                v-if="payload.truncated"
                class="px-1 py-1 text-xs text-muted-foreground"
            >
                {{ $t('posts.form.pinterest.boards_truncated') }}
            </p>
            <form
                class="mt-2 border-t border-border pt-2"
                @submit.prevent="create"
            >
                <div class="flex items-center gap-2">
                    <Input
                        v-model="createHttp.name"
                        type="text"
                        :placeholder="
                            $t('posts.form.pinterest.new_board_placeholder')
                        "
                        :aria-label="
                            $t('posts.form.pinterest.new_board_placeholder')
                        "
                        :aria-invalid="
                            createHttp.errors.name || createFailed
                                ? true
                                : undefined
                        "
                        data-testid="pinterest-board-new-name"
                    />
                    <Button
                        type="submit"
                        class="shrink-0"
                        :disabled="createHttp.processing"
                        data-testid="pinterest-board-create"
                    >
                        <IconLoader2
                            v-if="createHttp.processing"
                            class="size-4 animate-spin"
                        />
                        {{ $t('posts.form.pinterest.create_board') }}
                    </Button>
                </div>
                <InputError
                    class="mt-1"
                    :message="
                        createHttp.errors.name ??
                        (createFailed
                            ? $t('posts.form.pinterest.boards_failed')
                            : undefined)
                    "
                    data-testid="pinterest-board-create-error"
                />
            </form>
        </PopoverContent>
    </Popover>
</template>
