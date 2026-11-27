<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import {
    IconArrowLeft,
    IconFolder,
    IconLoader2,
    IconMovie,
    IconPhoto,
} from '@tabler/icons-vue';
import { computed, ref, watch } from 'vue';

import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { browse } from '@/routes/app/media/webdav';

interface WebdavEntry {
    type: 'folder' | 'file';
    name: string;
    path: string;
    mime?: string;
    size?: number;
    modified?: string | null;
}

interface WebdavListing {
    path: string;
    entries: WebdavEntry[];
}

const open = defineModel<boolean>('open', { required: true });

const props = defineProps<{
    label: string;
}>();

const emit = defineEmits<{
    (event: 'picked', paths: string[]): void;
}>();

const path = ref('');
const entries = ref<WebdavEntry[]>([]);
const selected = ref<string[]>([]);
const loading = ref(false);
const failed = ref(false);
let latestRequest = 0;

const browseHttp = useHttp<Record<string, never>, WebdavListing>({});

const breadcrumbs = computed(() => {
    const parts = path.value === '' ? [] : path.value.split('/');

    return parts.map((name, index) => ({
        name,
        path: parts.slice(0, index + 1).join('/'),
    }));
});

const load = async (target: string): Promise<void> => {
    const request = ++latestRequest;
    loading.value = true;
    failed.value = false;
    try {
        const response = await browseHttp.get(
            browse.url({ query: target === '' ? {} : { path: target } }),
        );
        if (request !== latestRequest) return;
        path.value = response?.path ?? target;
        entries.value = response?.entries ?? [];
    } catch {
        if (request !== latestRequest) return;
        entries.value = [];
        failed.value = true;
    } finally {
        if (request === latestRequest) loading.value = false;
    }
};

const up = (): void => {
    void load(path.value.split('/').slice(0, -1).join('/'));
};

const toggle = (entry: WebdavEntry, checked: boolean): void => {
    selected.value = checked
        ? [...selected.value, entry.path]
        : selected.value.filter((item) => item !== entry.path);
};

const formatSize = (bytes?: number): string => {
    if (!bytes) return '';
    const units = ['B', 'KB', 'MB', 'GB'];
    const exponent = Math.min(
        Math.floor(Math.log(bytes) / Math.log(1024)),
        units.length - 1,
    );

    return `${(bytes / 1024 ** exponent).toFixed(exponent === 0 ? 0 : 1)} ${units[exponent]}`;
};

const formatDate = (iso?: string | null): string =>
    iso ? new Date(iso).toLocaleDateString() : '';

const confirm = (): void => {
    if (selected.value.length === 0) return;
    emit('picked', [...selected.value]);
    open.value = false;
};

watch(
    open,
    (isOpen) => {
        if (!isOpen) return;
        selected.value = [];
        entries.value = [];
        void load('');
    },
    { immediate: true },
);
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="flex max-h-[85vh] flex-col sm:max-w-xl"
            data-testid="webdav-browser"
        >
            <DialogHeader>
                <DialogTitle>
                    {{
                        $t('posts.composer.media_sources.select_from', {
                            source: props.label,
                        })
                    }}
                </DialogTitle>
            </DialogHeader>

            <nav
                class="flex min-w-0 flex-wrap items-center gap-1 text-sm text-muted-foreground"
                data-testid="webdav-breadcrumbs"
            >
                <Button
                    v-if="path !== ''"
                    variant="ghost"
                    size="sm"
                    data-testid="webdav-up"
                    :aria-label="$t('posts.composer.media_sources.webdav.up')"
                    @click="up"
                >
                    <IconArrowLeft class="size-4" />
                </Button>
                <button
                    type="button"
                    class="hover:text-foreground"
                    @click="load('')"
                >
                    {{ $t('posts.composer.media_sources.webdav.root') }}
                </button>
                <template v-for="crumb in breadcrumbs" :key="crumb.path">
                    <span>/</span>
                    <button
                        type="button"
                        class="truncate hover:text-foreground"
                        @click="load(crumb.path)"
                    >
                        {{ crumb.name }}
                    </button>
                </template>
            </nav>

            <div
                class="-mx-1 min-h-48 overflow-y-auto px-1"
                :aria-busy="loading"
            >
                <div
                    v-if="loading"
                    class="flex items-center justify-center gap-2 py-10 text-sm text-muted-foreground"
                >
                    <IconLoader2 class="size-4 animate-spin" />
                    {{ $t('posts.composer.media_sources.webdav.loading') }}
                </div>
                <p
                    v-else-if="failed"
                    class="py-10 text-center text-sm text-destructive"
                    data-testid="webdav-error"
                >
                    {{ $t('posts.composer.media_sources.webdav.load_failed') }}
                </p>
                <p
                    v-else-if="entries.length === 0"
                    class="py-10 text-center text-sm text-muted-foreground"
                    data-testid="webdav-empty"
                >
                    {{ $t('posts.composer.media_sources.webdav.empty') }}
                </p>
                <ul v-else class="flex flex-col" data-testid="webdav-entries">
                    <li v-for="entry in entries" :key="entry.path">
                        <button
                            v-if="entry.type === 'folder'"
                            type="button"
                            class="flex w-full items-center gap-3 rounded-lg px-2 py-2 text-start text-sm hover:bg-accent"
                            :data-testid="`webdav-folder-${entry.name}`"
                            @click="load(entry.path)"
                        >
                            <IconFolder class="size-5 shrink-0" />
                            <span class="truncate">{{ entry.name }}</span>
                        </button>
                        <label
                            v-else
                            class="flex w-full cursor-pointer items-center gap-3 rounded-lg px-2 py-2 text-sm hover:bg-accent"
                            :data-testid="`webdav-file-${entry.name}`"
                        >
                            <Checkbox
                                :model-value="selected.includes(entry.path)"
                                @update:model-value="
                                    (checked) => toggle(entry, checked === true)
                                "
                            />
                            <IconMovie
                                v-if="entry.mime?.startsWith('video/')"
                                class="size-5 shrink-0 text-muted-foreground"
                            />
                            <IconPhoto
                                v-else
                                class="size-5 shrink-0 text-muted-foreground"
                            />
                            <span class="min-w-0 flex-1 truncate">{{
                                entry.name
                            }}</span>
                            <span
                                class="shrink-0 text-xs text-muted-foreground tabular-nums"
                            >
                                {{ formatSize(entry.size) }}
                                <template v-if="entry.modified">
                                    · {{ formatDate(entry.modified) }}
                                </template>
                            </span>
                        </label>
                    </li>
                </ul>
            </div>

            <DialogFooter>
                <Button
                    :disabled="selected.length === 0"
                    data-testid="webdav-add"
                    @click="confirm"
                >
                    {{
                        $t('posts.composer.media_sources.webdav.add', {
                            count: String(selected.length),
                        })
                    }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
