<script setup lang="ts">
import { computed } from 'vue';

interface MemberMap {
    [id: string]: string;
}

const props = defineProps<{
    body: string;
    members?: MemberMap;
}>();

interface Segment {
    type: 'text' | 'mention';
    value: string;
    userId?: string;
}

const segments = computed<Segment[]>(() => {
    const re = /@\[([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})\]/g;
    const result: Segment[] = [];
    const text = props.body ?? '';
    let lastIndex = 0;
    let match: RegExpExecArray | null;

    while ((match = re.exec(text)) !== null) {
        if (match.index > lastIndex) {
            result.push({ type: 'text', value: text.slice(lastIndex, match.index) });
        }
        const userId = match[1];
        const name = props.members?.[userId] ?? 'someone';
        result.push({ type: 'mention', value: `@${name}`, userId });
        lastIndex = match.index + match[0].length;
    }

    if (lastIndex < text.length) {
        result.push({ type: 'text', value: text.slice(lastIndex) });
    }

    return result;
});
</script>

<template>
    <p class="mt-0.5 whitespace-pre-wrap break-words text-sm">
        <template v-for="(seg, i) in segments" :key="i">
            <span
                v-if="seg.type === 'mention'"
                class="inline-block rounded-md bg-primary/10 px-1.5 text-primary font-medium"
            >{{ seg.value }}</span>
            <template v-else>{{ seg.value }}</template>
        </template>
    </p>
</template>
