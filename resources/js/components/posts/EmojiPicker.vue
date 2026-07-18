<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed, nextTick, onBeforeUnmount, ref, useTemplateRef, watch } from 'vue';

import { Input } from '@/components/ui/input';
import { CATEGORY_ICON, EMOJIS, EMOJI_CATEGORIES, type Emoji, type EmojiCategory } from '@/data/emojis';

const RECENTS_KEY = 'trypost.emoji.recents';
const RECENTS_MAX = 24;

const emit = defineEmits<{
    select: [emoji: string];
}>();

const search = ref('');
const activeCategory = ref<EmojiCategory | 'recent'>('smileys');
const scrollEl = useTemplateRef<HTMLDivElement>('scrollEl');
const headerRefs = ref<Record<string, HTMLElement | null>>({});
const setHeaderRef = (key: string) => (el: unknown) => {
    headerRefs.value[key] = el instanceof HTMLElement ? el : null;
};

const readRecents = (): string[] => {
    try {
        const raw = localStorage.getItem(RECENTS_KEY);
        if (!raw) return [];
        const parsed = JSON.parse(raw);
        return Array.isArray(parsed) ? parsed.filter((c) => typeof c === 'string') : [];
    } catch {
        return [];
    }
};

const recents = ref<string[]>(readRecents());

const recentEmojis = computed<Emoji[]>(() => {
    const map = new Map(EMOJIS.map((e) => [e.c, e]));
    return recents.value
        .map((c) => map.get(c))
        .filter((e): e is Emoji => Boolean(e));
});

const grouped = computed<Record<EmojiCategory, Emoji[]>>(() => {
    const result: Record<EmojiCategory, Emoji[]> = {
        smileys: [],
        people: [],
        nature: [],
        food: [],
        activities: [],
        travel: [],
        objects: [],
        symbols: [],
        flags: [],
    };
    for (const emoji of EMOJIS) {
        result[emoji.g].push(emoji);
    }
    return result;
});

const searchResults = computed<Emoji[]>(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return [];
    const tokens = q.split(/\s+/).filter(Boolean);
    return EMOJIS.filter((e) => {
        const haystack = `${e.n} ${e.k}`.toLowerCase();
        return tokens.every((t) => haystack.includes(t));
    }).slice(0, 200);
});

const isSearching = computed(() => search.value.trim().length > 0);

const categoryLabel = (category: EmojiCategory | 'recent'): string =>
    trans(`posts.edit.emoji_picker.${category}`);

const persistRecents = () => {
    try {
        localStorage.setItem(RECENTS_KEY, JSON.stringify(recents.value));
    } catch {
        // localStorage may be unavailable; safe to ignore.
    }
};

const onPick = (emoji: Emoji) => {
    emit('select', emoji.c);
    const next = [emoji.c, ...recents.value.filter((c) => c !== emoji.c)].slice(0, RECENTS_MAX);
    recents.value = next;
    persistRecents();
};

const scrollToCategory = (category: EmojiCategory | 'recent') => {
    const target = headerRefs.value[category];
    const container = scrollEl.value;
    if (!target || !container) return;
    container.scrollTo({ top: target.offsetTop - 4, behavior: 'smooth' });
    activeCategory.value = category;
};

const onScroll = () => {
    const container = scrollEl.value;
    if (!container || isSearching.value) return;
    const top = container.scrollTop + 8;
    let current: EmojiCategory | 'recent' = recentEmojis.value.length > 0 ? 'recent' : 'smileys';
    for (const category of EMOJI_CATEGORIES) {
        const header = headerRefs.value[category];
        if (header && header.offsetTop <= top) {
            current = category;
        }
    }
    activeCategory.value = current;
};

watch(search, async () => {
    await nextTick();
    if (scrollEl.value) scrollEl.value.scrollTop = 0;
});

onBeforeUnmount(() => {
    headerRefs.value = {};
});
</script>

<template>
    <div class="flex w-[min(340px,calc(100vw-2rem))] flex-col overflow-hidden rounded-[10px] bg-card text-foreground">
        <div class="border-b-2 border-foreground/10 p-2">
            <Input
                v-model="search"
                type="search"
                :placeholder="trans('posts.edit.emoji_picker.search')"
                class="h-8 text-sm"
            />
        </div>

        <div
            ref="scrollEl"
            class="relative h-72 overflow-y-auto px-2 py-1"
            @scroll.passive="onScroll"
        >
            <template v-if="isSearching">
                <div
                    v-if="searchResults.length === 0"
                    class="flex h-full items-center justify-center px-4 text-center text-xs font-medium text-foreground/60"
                >
                    {{ trans('posts.edit.emoji_picker.empty') }}
                </div>
                <div v-else class="grid grid-cols-8 gap-0.5 py-1">
                    <button
                        v-for="emoji in searchResults"
                        :key="emoji.c"
                        type="button"
                        class="flex size-9 cursor-pointer items-center justify-center rounded-md text-xl transition-colors hover:bg-foreground/5 focus:bg-foreground/5 focus:outline-none"
                        :title="emoji.n"
                        :aria-label="emoji.n"
                        @click="onPick(emoji)"
                    >
                        {{ emoji.c }}
                    </button>
                </div>
            </template>

            <template v-else>
                <section v-if="recentEmojis.length > 0">
                    <h3
                        :ref="setHeaderRef('recent')"
                        class="sticky top-0 z-10 bg-card/95 px-1 py-1.5 text-[11px] font-black uppercase tracking-widest text-foreground/60 backdrop-blur"
                    >
                        {{ categoryLabel('recent') }}
                    </h3>
                    <div class="grid grid-cols-8 gap-0.5 pb-2">
                        <button
                            v-for="emoji in recentEmojis"
                            :key="`recent-${emoji.c}`"
                            type="button"
                            class="flex size-9 cursor-pointer items-center justify-center rounded-md text-xl transition-colors hover:bg-foreground/5 focus:bg-foreground/5 focus:outline-none"
                            :title="emoji.n"
                            :aria-label="emoji.n"
                            @click="onPick(emoji)"
                        >
                            {{ emoji.c }}
                        </button>
                    </div>
                </section>

                <section v-for="category in EMOJI_CATEGORIES" :key="category">
                    <h3
                        :ref="setHeaderRef(category)"
                        class="sticky top-0 z-10 bg-card/95 px-1 py-1.5 text-[11px] font-black uppercase tracking-widest text-foreground/60 backdrop-blur"
                    >
                        {{ categoryLabel(category) }}
                    </h3>
                    <div class="grid grid-cols-8 gap-0.5 pb-2">
                        <button
                            v-for="emoji in grouped[category]"
                            :key="emoji.c"
                            type="button"
                            class="flex size-9 cursor-pointer items-center justify-center rounded-md text-xl transition-colors hover:bg-foreground/5 focus:bg-foreground/5 focus:outline-none"
                            :title="emoji.n"
                            :aria-label="emoji.n"
                            @click="onPick(emoji)"
                        >
                            {{ emoji.c }}
                        </button>
                    </div>
                </section>
            </template>
        </div>

        <div class="flex items-center justify-between border-t-2 border-foreground/10 px-1 py-1">
            <button
                v-if="recentEmojis.length > 0"
                type="button"
                class="flex size-8 cursor-pointer items-center justify-center rounded-md text-base transition-colors hover:bg-foreground/5 focus:bg-foreground/5 focus:outline-none"
                :class="activeCategory === 'recent' && !isSearching ? 'bg-violet-100 ring-2 ring-foreground' : ''"
                :title="categoryLabel('recent')"
                :aria-label="categoryLabel('recent')"
                @click="scrollToCategory('recent')"
            >
                🕘
            </button>
            <button
                v-for="category in EMOJI_CATEGORIES"
                :key="category"
                type="button"
                class="flex size-8 cursor-pointer items-center justify-center rounded-md text-base transition-colors hover:bg-foreground/5 focus:bg-foreground/5 focus:outline-none"
                :class="activeCategory === category && !isSearching ? 'bg-violet-100 ring-2 ring-foreground' : ''"
                :title="categoryLabel(category)"
                :aria-label="categoryLabel(category)"
                @click="scrollToCategory(category)"
            >
                {{ CATEGORY_ICON[category] }}
            </button>
        </div>
    </div>
</template>
