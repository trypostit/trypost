<script setup lang="ts">
import { IconChevronDown } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuPortal,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import type {
    OtherChannel,
    ScheduleGenerateAction,
} from '@/types/posting-schedule';

defineProps<{
    goalMet: boolean;
    otherChannels: OtherChannel[];
}>();

const emit = defineEmits<{
    generate: [action: ScheduleGenerateAction];
}>();

const menuOpen = ref(false);
const pending = ref<ScheduleGenerateAction | null>(null);

const requestGenerate = (action: ScheduleGenerateAction): void => {
    pending.value = action;
};

const copyFromChannel = (channelId: string): void => {
    menuOpen.value = false;
    requestGenerate({ kind: 'copy', from: channelId });
};

const confirmOpen = computed({
    get: () => pending.value !== null,
    set: (open: boolean) => {
        if (!open) {
            pending.value = null;
        }
    },
});

const closeConfirmDialog = (): void => {
    confirmOpen.value = false;
};

const confirmGenerate = (): void => {
    const action = pending.value;
    pending.value = null;

    if (action) {
        emit('generate', action);
    }
};
</script>

<template>
    <div class="flex shrink-0 self-start md:self-auto">
        <DropdownMenu v-model:open="menuOpen">
            <DropdownMenuTrigger as-child>
                <Button
                    type="button"
                    variant="outline"
                    class="data-[state=open]:bg-accent"
                    data-testid="schedule-generate"
                >
                    {{ $t('channels.settings_page.generate') }}
                    <IconChevronDown class="size-4 text-muted-foreground" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" class="w-[220px] px-2">
                <TooltipProvider :delay-duration="100">
                    <Tooltip :disabled="!goalMet">
                        <TooltipTrigger as-child>
                            <div data-testid="schedule-generate-goal-hint">
                                <DropdownMenuItem
                                    :disabled="goalMet"
                                    class="ps-3"
                                    data-testid="schedule-generate-goal"
                                    @select="requestGenerate({ kind: 'goal' })"
                                >
                                    {{ $t('channels.settings_page.generate_goal') }}
                                </DropdownMenuItem>
                            </div>
                        </TooltipTrigger>
                        <TooltipContent
                            side="top"
                            data-testid="schedule-generate-goal-tooltip"
                        >
                            {{
                                $t('channels.settings_page.generate_goal_disabled')
                            }}
                        </TooltipContent>
                    </Tooltip>
                </TooltipProvider>
                <DropdownMenuItem
                    class="ps-3"
                    data-testid="schedule-generate-recommended"
                    @select="requestGenerate({ kind: 'recommended' })"
                >
                    {{ $t('channels.settings_page.generate_recommended') }}
                </DropdownMenuItem>
                <DropdownMenuSub>
                    <DropdownMenuSubTrigger
                        :disabled="otherChannels.length === 0"
                        class="ps-3"
                        data-testid="schedule-generate-copy"
                    >
                        {{ $t('channels.settings_page.generate_copy') }}
                    </DropdownMenuSubTrigger>
                    <DropdownMenuPortal>
                        <DropdownMenuSubContent
                            class="w-64 p-0"
                            data-testid="schedule-generate-copy-menu"
                        >
                            <Command @keydown.stop>
                                <CommandInput
                                    :placeholder="
                                        $t('posts.channel_search_placeholder')
                                    "
                                    data-testid="schedule-generate-copy-search"
                                />
                                <CommandList class="max-h-64">
                                    <CommandEmpty>
                                        {{ $t('posts.no_channels') }}
                                    </CommandEmpty>
                                    <CommandGroup>
                                        <CommandItem
                                            v-for="other in otherChannels"
                                            :key="other.id"
                                            :value="`${other.display_name ?? ''} ${other.username} ${getPlatformLabel(other.platform)} ${other.id}`"
                                            class="gap-2.5"
                                            :data-testid="`schedule-generate-copy-${other.id}`"
                                            @select="copyFromChannel(other.id)"
                                        >
                                            <ChannelAvatar
                                                :platform="other.platform"
                                                :src="other.avatar_url"
                                                :verified="other.verified_badge"
                                                :name="
                                                    other.display_name ||
                                                    other.username
                                                "
                                                :size="24"
                                                shape="round"
                                                ring="popover"
                                            />
                                            <span class="flex min-w-0 flex-col">
                                                <span class="truncate">
                                                    {{
                                                        other.display_name ||
                                                        other.username
                                                    }}
                                                </span>
                                                <span
                                                    class="truncate text-xs text-muted-foreground"
                                                >
                                                    {{
                                                        getPlatformLabel(
                                                            other.platform,
                                                        )
                                                    }}
                                                </span>
                                            </span>
                                        </CommandItem>
                                    </CommandGroup>
                                </CommandList>
                            </Command>
                        </DropdownMenuSubContent>
                    </DropdownMenuPortal>
                </DropdownMenuSub>
            </DropdownMenuContent>
        </DropdownMenu>

        <Dialog v-model:open="confirmOpen">
            <DialogContent :show-close-button="false">
                <DialogHeader>
                    <DialogTitle>
                        {{ $t('channels.settings_page.generate_confirm_title') }}
                    </DialogTitle>
                    <DialogDescription>
                        {{
                            $t('channels.settings_page.generate_confirm_description')
                        }}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="closeConfirmDialog"
                    >
                        {{ $t('channels.settings_page.cancel') }}
                    </Button>
                    <Button
                        type="button"
                        data-testid="schedule-generate-confirm"
                        @click="confirmGenerate"
                    >
                        {{ $t('channels.settings_page.confirm') }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
