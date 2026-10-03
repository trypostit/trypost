<script setup lang="ts">
import {
    IconCheck,
    IconChevronDown,
    IconLayersSubtract,
} from '@tabler/icons-vue';

import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { CalendarStatus } from '@/types/publish';

const STATUSES: readonly CalendarStatus[] = [
    'all',
    'drafts',
    'scheduled',
    'sent',
];

const status = defineModel<CalendarStatus>({ required: true });

const selectStatus = (option: CalendarStatus): void => {
    status.value = option;
};

</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                class="shrink-0 data-[state=open]:bg-accent"
                :aria-label="$t('calendar.status.label')"
                data-testid="calendar-status-filter"
            >
                <IconLayersSubtract class="size-4 text-muted-foreground" />
                {{ $t(`calendar.status.${status}`) }}
                <IconChevronDown class="size-4 text-muted-foreground" />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="start">
            <DropdownMenuItem
                v-for="option in STATUSES"
                :key="option"
                :data-testid="`calendar-status-${option}`"
                @click="selectStatus(option)"
            >
                <IconCheck
                    class="size-4"
                    :class="status === option ? '' : 'invisible'"
                />
                {{ $t(`calendar.status.${option}`) }}
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
