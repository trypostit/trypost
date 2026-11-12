<script setup lang="ts">
import { IconClock } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import { Avatar } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
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
 * photo, name, whether they are an admin and their local time. The people
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
    props.people?.find(
        (candidate) => candidate.id === props.userId.toLowerCase(),
    ),
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
            align="start"
            class="w-72 rounded-xl p-4"
            data-testid="note-mention-card"
        >
            <div class="flex items-center gap-3">
                <Avatar
                    :src="person?.photo_url"
                    :name="person?.name ?? name"
                    class="size-12 shrink-0 rounded-lg text-sm"
                />
                <div class="min-w-0">
                    <p
                        class="truncate text-sm font-semibold text-foreground"
                        data-testid="note-mention-card-name"
                    >
                        {{ person?.name ?? name }}
                    </p>
                    <Badge
                        v-if="person?.is_admin"
                        variant="secondary"
                        class="mt-1"
                        data-testid="note-mention-card-admin"
                    >
                        {{ $t('notes.mention_card.admin') }}
                    </Badge>
                </div>
            </div>
            <p
                v-if="filled(localTime)"
                class="mt-3 flex items-center gap-2 text-sm text-muted-foreground"
                data-testid="note-mention-card-time"
            >
                <IconClock class="size-4 shrink-0" />
                {{ $t('notes.mention_card.local_time', { time: localTime }) }}
            </p>
        </HoverCardContent>
    </HoverCard>
</template>
