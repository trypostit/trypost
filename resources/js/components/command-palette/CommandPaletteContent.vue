<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import {
    IconArrowDown,
    IconArrowUp,
    IconBulb,
    IconCalendarEvent,
    IconTrendingUp,
    IconCornerDownLeft,
    IconFileText,
    IconPlus,
    IconRepeat,
    IconRss,
    IconSearch,
    IconSettings,
    IconUserPlus,
} from '@tabler/icons-vue';
import { injectListboxRootContext } from 'reka-ui';
import { computed, onMounted } from 'vue';

import CommandPaletteItem from '@/components/command-palette/CommandPaletteItem.vue';
import {
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandList,
    useCommand,
} from '@/components/ui/command';
import { Kbd } from '@/components/ui/kbd';
import { orderChannels } from '@/composables/useChannelOrder';
import { useConnectChannelDialog } from '@/composables/useConnectChannelDialog';
import { openPostComposer } from '@/composables/useGlobalPostComposer';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import { useSettingsNavigation } from '@/composables/useSettingsNavigation';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import { toUrl } from '@/lib/utils';
import { insights as insightsIndex } from '@/routes/app';
import { insights, publish } from '@/routes/app/channels';
import { index as feedsIndex } from '@/routes/app/create/feeds';
import { create as createIdea, index as ideasIndex } from '@/routes/app/create/ideas';
import { index as templatesIndex } from '@/routes/app/create/templates';
import { index as postsIndex } from '@/routes/app/posts';
import { index as repurposes } from '@/routes/app/repurposes';
import { channelName, type SidebarChannel } from '@/types/channel';
import type {
    CommandPaletteEntry,
    CommandPaletteGroup,
    CommandPaletteTitle,
} from '@/types/command-palette';

const RECENT_LIMIT = 3;

const isMacPlatform = /Mac|iPhone|iPad/.test(navigator.platform);

const props = defineProps<{
    recentIds: string[];
    inviteMember: () => void;
}>();

const emit = defineEmits<{
    select: [entry: CommandPaletteEntry];
}>();

const page = usePage();
const { filterState } = useCommand();
const listbox = injectListboxRootContext();
const settingsNavigation = useSettingsNavigation();
const { open: openConnectDialog } = useConnectChannelDialog();
const {
    canCreatePost,
    canManageRepurposes,
    canManageAccounts,
    canManageTeam,
} = useWorkspaceAbilities();

const hasWorkspace = computed(() => Boolean(page.props.auth?.currentWorkspace));
const searching = computed(() => filterState.search.trim() !== '');

const channels = computed<SidebarChannel[]>(() =>
    hasWorkspace.value
        ? orderChannels((page.props.channels as SidebarChannel[] | undefined) ?? [])
        : [],
);

const visit = (url: string) => (): void => {
    router.visit(url);
};

const settingsHome = computed(
    () => {
        const href = settingsNavigation.value[0]?.items[0]?.href;

        return href ? toUrl(href) : null;
    },
);

const quickActions = computed<CommandPaletteEntry[]>(() => {
    if (!hasWorkspace.value) {
        return [];
    }

    return [
        ...(canCreatePost.value
            ? [
                  {
                      id: 'action-create-post',
                      group: 'actions' as const,
                      title: { key: 'command_palette.actions.create_post' },
                      subtitle: { key: 'command_palette.actions.create_post_description' },
                      icon: IconPlus,
                      run: () => openPostComposer(),
                  },
                  {
                      id: 'action-create-idea',
                      group: 'actions' as const,
                      title: { key: 'command_palette.actions.create_idea' },
                      subtitle: { key: 'command_palette.actions.create_idea_description' },
                      icon: IconBulb,
                      run: visit(createIdea.url()),
                  },
              ]
            : []),
        ...(canManageTeam.value
            ? [
                  {
                      id: 'action-invite-member',
                      group: 'actions' as const,
                      title: { key: 'command_palette.actions.invite_member' },
                      subtitle: { key: 'command_palette.actions.invite_member_description' },
                      icon: IconUserPlus,
                      run: props.inviteMember,
                  },
              ]
            : []),
    ];
});

const createPath = (tab: string): CommandPaletteTitle => ({
    path: [{ key: 'sidebar.create' }, { key: `create.tabs.${tab}` }],
});

const navigation = computed<CommandPaletteEntry[]>(() => {
    const entries: CommandPaletteEntry[] = hasWorkspace.value
        ? [
              {
                  id: 'nav-publish',
                  group: 'navigation',
                  title: { key: 'sidebar.groups.posts' },
                  icon: IconCalendarEvent,
                  run: visit(postsIndex.url()),
              },
              ...(canCreatePost.value
                  ? [
                        {
                            id: 'nav-ideas',
                            group: 'navigation' as const,
                            title: createPath('ideas'),
                            icon: IconBulb,
                            run: visit(ideasIndex.url()),
                        },
                        {
                            id: 'nav-templates',
                            group: 'navigation' as const,
                            title: createPath('templates'),
                            icon: IconFileText,
                            run: visit(templatesIndex.url()),
                        },
                        {
                            id: 'nav-feeds',
                            group: 'navigation' as const,
                            title: createPath('feeds'),
                            icon: IconRss,
                            run: visit(feedsIndex.url()),
                        },
                    ]
                  : []),
              {
                  id: 'nav-insights',
                  group: 'navigation',
                  title: { key: 'channels.insights' },
                  icon: IconTrendingUp,
                  run: visit(insightsIndex.url()),
              },
              ...(canManageRepurposes.value
                  ? [
                        {
                            id: 'nav-repurposes',
                            group: 'navigation' as const,
                            title: { key: 'sidebar.repurposes' },
                            icon: IconRepeat,
                            run: visit(repurposes.url()),
                        },
                    ]
                  : []),
          ]
        : [];

    if (settingsHome.value) {
        entries.push({
            id: 'nav-settings',
            group: 'navigation',
            title: { key: 'command_palette.navigation.settings' },
            subtitle: { key: 'command_palette.navigation.settings_description' },
            icon: IconSettings,
            run: visit(settingsHome.value),
        });
    }

    return entries;
});

const channelEntries = computed<CommandPaletteEntry[]>(() =>
    channels.value.map((channel) => ({
        id: `channel-${channel.id}`,
        group: 'channels',
        title: { text: channelName(channel) },
        subtitle: { text: getPlatformLabel(channel.platform) },
        channel,
        count: channel.scheduled_posts_count,
        run: visit(publish.url(channel.id)),
    })),
);

const connectEntries = computed<CommandPaletteEntry[]>(() =>
    hasWorkspace.value && canManageAccounts.value
        ? [
              {
                  id: 'action-connect-channel',
                  group: 'connect',
                  title: { key: 'command_palette.actions.connect_channel' },
                  subtitle: { key: 'command_palette.actions.connect_channel_description' },
                  icon: IconPlus,
                  run: () => openConnectDialog(),
              },
          ]
        : [],
);

const settingsEntries = computed<CommandPaletteEntry[]>(() =>
    settingsNavigation.value.flatMap((group) =>
        group.items.map((item) => ({
            id: `settings-${item.name}`,
            group: 'settings' as const,
            title: {
                path: [
                    { key: 'command_palette.navigation.settings' },
                    { key: `settings.sidebar.items.${item.name}` },
                ],
            },
            icon: item.icon,
            run: visit(toUrl(item.href)),
        })),
    ),
);

const insightEntries = computed<CommandPaletteEntry[]>(() =>
    channels.value.map((channel) => ({
        id: `insights-${channel.id}`,
        group: 'insights',
        title: {
            path: [{ text: channelName(channel) }, { key: 'channels.insights' }],
        },
        subtitle: { text: getPlatformLabel(channel.platform) },
        channel,
        run: visit(insights.url(channel.id)),
    })),
);

const allEntries = computed<CommandPaletteEntry[]>(() => [
    ...quickActions.value,
    ...navigation.value,
    ...channelEntries.value,
    ...connectEntries.value,
    ...settingsEntries.value,
    ...insightEntries.value,
]);

const recentEntries = computed<CommandPaletteEntry[]>(() =>
    props.recentIds
        .map((id) => allEntries.value.find((entry) => entry.id === id))
        .filter((entry): entry is CommandPaletteEntry => entry !== undefined)
        .slice(0, RECENT_LIMIT),
);

const groups = computed<
    { key: CommandPaletteGroup | 'recent'; heading: string | null; entries: CommandPaletteEntry[] }[]
>(() =>
    [
        ...(searching.value
            ? []
            : [{ key: 'recent' as const, heading: 'command_palette.groups.recent', entries: recentEntries.value }]),
        { key: 'actions' as const, heading: 'command_palette.groups.quick_actions', entries: quickActions.value },
        { key: 'navigation' as const, heading: 'command_palette.groups.navigation', entries: navigation.value },
        { key: 'channels' as const, heading: 'command_palette.groups.channels', entries: channelEntries.value },
        { key: 'connect' as const, heading: null, entries: connectEntries.value },
        ...(searching.value
            ? [
                  { key: 'settings' as const, heading: 'command_palette.groups.settings', entries: settingsEntries.value },
                  { key: 'insights' as const, heading: 'command_palette.groups.insights', entries: insightEntries.value },
              ]
            : []),
    ].filter((group) => group.entries.length > 0),
);

onMounted(() => {
    listbox.highlightFirstItem();
});
</script>

<template>
    <CommandInput
        :placeholder="$t('command_palette.placeholder')"
        wrapper-class="h-14 gap-3 border-border-strong px-4"
        icon-class="size-[22px] text-foreground"
        class="h-14 py-0 text-sm"
        data-testid="command-palette-input"
    >
        <span class="hidden shrink-0 items-center gap-1.5 sm:flex" aria-hidden="true" data-testid="command-palette-shortcut">
            <Kbd class="h-4 min-w-4 rounded px-1 text-[11px]">{{ isMacPlatform ? '⌘' : 'Ctrl' }}</Kbd>
            <Kbd class="h-4 min-w-4 rounded px-1 text-[11px]">K</Kbd>
        </span>
    </CommandInput>

    <CommandList
        class="max-h-[min(400px,60dvh)] scroll-py-2 px-2 pb-2"
        data-testid="command-palette-list"
    >
        <CommandEmpty
            class="flex flex-col items-center gap-3 py-10 text-sm text-muted-foreground"
            data-testid="command-palette-empty"
        >
            <IconSearch class="size-9 text-muted-foreground" stroke-width="1.75" />
            <span>{{ $t('command_palette.empty', { query: filterState.search }) }}</span>
        </CommandEmpty>

        <CommandGroup
            v-for="group in groups"
            :key="group.key"
            :heading="group.heading ? $t(group.heading) : undefined"
            class="p-0 pt-3"
            heading-class="px-3 pt-0 pb-1 text-xs font-normal text-muted-foreground"
            :data-testid="`command-palette-group-${group.key}`"
        >
            <CommandPaletteItem
                v-for="entry in group.entries"
                :key="`${group.key}-${entry.id}`"
                :entry="entry"
                :value="`${group.key}:${entry.id}`"
                :test-id="
                    group.key === 'recent'
                        ? `command-palette-recent-${entry.id}`
                        : `command-palette-item-${entry.id}`
                "
                @select="emit('select', $event)"
            />
        </CommandGroup>
    </CommandList>

    <div
        class="flex h-[43px] shrink-0 items-center justify-end gap-6 border-t border-border-strong px-4 text-xs text-muted-foreground"
        data-testid="command-palette-footer"
    >
        <span class="flex items-center gap-1.5">
            <Kbd class="h-4 min-w-4 rounded px-0.5"><IconArrowUp class="size-3" /></Kbd>
            <Kbd class="h-4 min-w-4 rounded px-0.5"><IconArrowDown class="size-3" /></Kbd>
            <span class="ms-1">{{ $t('command_palette.footer.navigate') }}</span>
        </span>
        <span class="flex items-center gap-1.5">
            <Kbd class="h-4 min-w-4 rounded px-1"><IconCornerDownLeft class="size-3" /></Kbd>
            <span class="ms-1">{{ $t('command_palette.footer.select') }}</span>
        </span>
        <span class="flex items-center gap-1.5">
            <Kbd class="h-4 rounded px-1 text-[11px] text-foreground">Esc</Kbd>
            <span class="ms-1">{{ $t('command_palette.footer.close') }}</span>
        </span>
    </div>
</template>
