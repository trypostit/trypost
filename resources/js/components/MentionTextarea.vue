<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { IconAt } from '@tabler/icons-vue';
import { nextTick, onBeforeUnmount, onMounted, ref, useTemplateRef, watch } from 'vue';

import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Textarea } from '@/components/ui/textarea';
import { getInitials } from '@/composables/useInitials';
import debounce from '@/debounce';
import { search as searchMembers } from '@/routes/app/workspace/members';

interface Member {
    id: string;
    name: string;
    email: string;
    avatar_url: string | null;
}

const props = withDefaults(
    defineProps<{
        modelValue: string;
        memberNames?: Record<string, string>;
        placeholder?: string;
        rows?: number;
        autofocus?: boolean;
        class?: string;
    }>(),
    {
        memberNames: () => ({}),
        placeholder: '',
        rows: 2,
        autofocus: false,
        class: '',
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
    enter: [event: KeyboardEvent];
    keydown: [event: KeyboardEvent];
    mention: [member: { id: string; name: string }];
}>();

const textareaWrapper = useTemplateRef<InstanceType<typeof Textarea>>('textareaWrapper');

const MARKER_RE = /@\[([0-9a-fA-F-]{36})\]/g;

const escapeRegex = (s: string) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/**
 * `displayText` is what the textarea shows (with names like "@João Silva").
 * `nameToId` maps each inserted display name back to the user_id so the marker
 * can be reconstructed when emitting modelValue. We only need to remember names
 * the component is responsible for — names already present in the incoming
 * modelValue (markers) are added on init from `memberNames`.
 */
const displayText = ref('');
const nameToId = ref<Record<string, string>>({});

let lastEmitted = '';

const buildMarkerValue = (text: string): string => {
    let out = text;
    for (const [name, id] of Object.entries(nameToId.value)) {
        if (!name) continue;
        const re = new RegExp(`@${escapeRegex(name)}(?!\\w)`, 'g');
        out = out.replace(re, `@[${id}]`);
    }
    return out;
};

const emitConverted = () => {
    const next = buildMarkerValue(displayText.value);
    lastEmitted = next;
    emit('update:modelValue', next);
};

const initFromMarkers = (raw: string) => {
    let text = raw;
    const nameMap = props.memberNames ?? {};
    const matches = [...raw.matchAll(MARKER_RE)];

    for (const match of matches) {
        const id = match[1];
        const name = nameMap[id];
        if (!name) continue;
        nameToId.value[name] = id;
        text = text.split(match[0]).join(`@${name}`);
    }

    displayText.value = text;
    lastEmitted = raw;
};

initFromMarkers(props.modelValue ?? '');

watch(
    () => props.modelValue,
    (next) => {
        if (next === lastEmitted) return;
        initFromMarkers(next ?? '');
    },
);

watch(
    () => props.memberNames,
    () => {
        if (displayText.value === '' && (props.modelValue ?? '') !== '') {
            initFromMarkers(props.modelValue ?? '');
        }
    },
    { deep: true },
);

const getRawTextarea = (): HTMLTextAreaElement | null => {
    const inst = textareaWrapper.value;
    if (!inst) return null;
    const root = (inst as unknown as { $el?: HTMLElement }).$el;
    if (root instanceof HTMLTextAreaElement) return root;
    return root?.querySelector('textarea') ?? null;
};

const open = ref(false);
const query = ref('');
const triggerStart = ref<number | null>(null);
const members = ref<Member[]>([]);
const loading = ref(false);
const activeIndex = ref(0);
const flipUp = ref(false);

const recomputeFlip = () => {
    const ta = getRawTextarea();
    if (!ta) return;
    const rect = ta.getBoundingClientRect();
    const viewportH = window.innerHeight || document.documentElement.clientHeight;
    flipUp.value = rect.bottom + 280 > viewportH;
};

const httpMembers = useHttp<Record<string, never>, Member[]>({});

const fetchMembers = async (term: string) => {
    loading.value = true;
    try {
        const result = await httpMembers.get(searchMembers.url({ query: { q: term } }));
        members.value = Array.isArray(result) ? result : [];
        activeIndex.value = 0;
    } catch {
        members.value = [];
    } finally {
        loading.value = false;
    }
};

const debouncedFetch = debounce((term: string) => {
    void fetchMembers(term);
}, 150);

const closePopover = () => {
    open.value = false;
    triggerStart.value = null;
    query.value = '';
};

const onInput = () => {
    const ta = getRawTextarea();
    if (!ta) return;

    emitConverted();

    const pos = ta.selectionStart ?? 0;
    const before = displayText.value.slice(0, pos);
    const match = before.match(/(?:^|\s)@([\w-]*)$/);

    if (!match) {
        if (open.value) closePopover();
        return;
    }

    const matchedQuery = match[1];
    triggerStart.value = pos - matchedQuery.length - 1;
    query.value = matchedQuery;

    if (!open.value) {
        recomputeFlip();
        open.value = true;
        void fetchMembers(matchedQuery);
    } else {
        debouncedFetch(matchedQuery);
    }
};

const insertMention = async (member: Member) => {
    const ta = getRawTextarea();
    if (!ta || triggerStart.value === null) return;

    const start = triggerStart.value;
    const pos = ta.selectionStart ?? displayText.value.length;
    const visible = `@${member.name} `;

    nameToId.value[member.name] = member.id;
    displayText.value =
        displayText.value.slice(0, start) + visible + displayText.value.slice(pos);
    closePopover();

    emit('mention', { id: member.id, name: member.name });

    await nextTick();
    emitConverted();

    const newPos = start + visible.length;
    ta.focus();
    ta.setSelectionRange(newPos, newPos);
};

const onKeydown = (event: KeyboardEvent) => {
    if (open.value && members.value.length > 0) {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            activeIndex.value = (activeIndex.value + 1) % members.value.length;
            return;
        }
        if (event.key === 'ArrowUp') {
            event.preventDefault();
            activeIndex.value = (activeIndex.value - 1 + members.value.length) % members.value.length;
            return;
        }
        if (event.key === 'Enter' || event.key === 'Tab') {
            event.preventDefault();
            void insertMention(members.value[activeIndex.value]);
            return;
        }
        if (event.key === 'Escape') {
            event.preventDefault();
            closePopover();
            return;
        }
    }

    if (event.key === 'Enter' && !event.shiftKey) {
        emit('enter', event);
    }

    emit('keydown', event);
};

const onBlur = () => {
    setTimeout(() => closePopover(), 120);
};

onMounted(() => {
    if (props.autofocus) {
        getRawTextarea()?.focus();
    }
});

onBeforeUnmount(() => closePopover());
</script>

<template>
    <div class="relative w-full">
        <Textarea
            ref="textareaWrapper"
            v-model="displayText"
            :placeholder="placeholder"
            :rows="rows"
            :class="props.class"
            @input="onInput"
            @keydown="onKeydown"
            @blur="onBlur"
        />

        <div
            v-if="open"
            class="absolute z-50 w-full max-w-xs rounded-md border bg-popover text-popover-foreground shadow-md"
            :class="flipUp ? 'bottom-full mb-1' : 'top-full mt-1'"
        >
            <div v-if="loading" class="flex items-center gap-2 px-3 py-2 text-xs text-muted-foreground">
                <IconAt class="h-3.5 w-3.5" />
                Searching members…
            </div>

            <div v-else-if="members.length === 0" class="flex items-center gap-2 px-3 py-2 text-xs text-muted-foreground">
                <IconAt class="h-3.5 w-3.5" />
                No member matches "{{ query }}"
            </div>

            <ul v-else class="max-h-64 overflow-y-auto py-1">
                <li
                    v-for="(member, index) in members"
                    :key="member.id"
                    class="flex cursor-pointer items-center gap-2 px-3 py-1.5 text-sm transition-colors"
                    :class="index === activeIndex ? 'bg-muted' : 'hover:bg-muted/60'"
                    @mousedown.prevent="insertMention(member)"
                    @mouseenter="activeIndex = index"
                >
                    <Avatar class="h-6 w-6 shrink-0">
                        <AvatarImage v-if="member.avatar_url" :src="member.avatar_url" :alt="member.name" />
                        <AvatarFallback class="text-[10px]">{{ getInitials(member.name) }}</AvatarFallback>
                    </Avatar>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium">{{ member.name }}</p>
                        <p class="truncate text-xs text-muted-foreground">{{ member.email }}</p>
                    </div>
                </li>
            </ul>
        </div>
    </div>
</template>
