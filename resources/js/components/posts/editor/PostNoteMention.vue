<script setup lang="ts">
import { IconClock } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import { Avatar } from '@/components/ui/avatar';
import {
    HoverCard,
    HoverCardContent,
    HoverCardTrigger,
} from '@/components/ui/hover-card';
import date from '@/date';
import dayjs from '@/dayjs';
import { filled, transform } from '@/lib/helpers';
import type { PostNoteMentionable } from '@/types/post-note';

/**
 * A mention in a note, as `@Name`. Hovering or focusing it shows who it is:
 * photo, name and their local time. The people
 * load the first time a card opens; someone no longer on the post shows by
 * name only.
 */
const props = defineProps<{
    name: string;
    userId: string;
    people: PostNoteMentionable[] | null;
}>();

const emit = defineEmits<{
    needPeople: [];
}>();

const openedAt = ref(dayjs.utc());

const person = computed(() =>
    props.people?.find((candidate) => candidate.id === props.userId),
);

const localTime = computed(() =>
    transform(person.value, (mentioned) =>
        date.formatTimeInTimezone(openedAt.value.format(), mentioned.timezone),
    ),
);

const onOpenChange = (open: boolean) => {
    if (!open) {
        return;
    }

    openedAt.value = dayjs.utc();

    if (props.people === null) {
        emit('needPeople');
    }
};
</script>

<template>
    <HoverCard :open-delay="300" :close-delay="100" @update:open="onOpenChange">
        <HoverCardTrigger as-child>
            <span
                tabindex="0"
                class="rounded-sm bg-primary-subtle px-0.5 font-medium text-foreground outline-none focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                data-testid="note-mention"
                >@{{ name }}</span
            >
        </HoverCardTrigger>
        <HoverCardContent
            side="top"
            align="start"
            class="w-auto max-w-72 min-w-56 rounded-xl border-0 p-3 shadow-md dark:border dark:border-border"
            data-testid="note-mention-card"
        >
            <div class="flex min-w-0 items-center gap-3">
                <Avatar
                    :src="person?.photo_url"
                    :name="person?.name ?? name"
                    class="size-10 shrink-0 rounded-lg"
                    fallback-class="bg-primary-subtle text-sm font-bold text-primary-text"
                />
                <div class="grid min-w-0">
                    <span
                        class="truncate text-sm leading-5 font-semibold text-foreground"
                        data-testid="note-mention-card-name"
                        >{{ person?.name ?? name }}</span
                    >
                    <span
                        v-if="filled(localTime)"
                        class="flex items-center gap-1 text-xs leading-4 text-muted-foreground"
                        data-testid="note-mention-card-time"
                    >
                        <IconClock class="size-3.5 shrink-0" />
                        {{
                            $t('notes.mention_card.local_time', {
                                time: localTime,
                            })
                        }}
                    </span>
                </div>
            </div>
        </HoverCardContent>
    </HoverCard>
</template>
