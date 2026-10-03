<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    IconBrandDiscord,
    IconBrandGithub,
    IconBulb,
    IconCheck,
    IconCoin,
    IconHelpCircle,
    IconLayoutGrid,
    IconLifebuoy,
    IconMessageChatbot,
    IconDeviceDesktop,
    IconLanguage,
    IconLogout,
    IconMoon,
    IconPlus,
    IconSettings,
    IconSun,
    IconUsers,
} from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import PreferencesController from '@/actions/App/Http/Controllers/App/Settings/PreferencesController';
import { updateLanguage } from '@/actions/App/Http/Controllers/App/Settings/ProfileController';
import { Avatar } from '@/components/ui/avatar';
import { buttonVariants } from '@/components/ui/button';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuPortal,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
} from '@/components/ui/dropdown-menu';
import { clearAllComposerAutosaves } from '@/composables/useComposerAutosave';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import { useWorkspaceLimit } from '@/composables/useWorkspaceLimit';
import { openSupportChat } from '@/lib/supportChat';
import { cn } from '@/lib/utils';
import posthog from '@/posthog';
import { applyTheme, type Theme } from '@/preferences';
import { logout } from '@/routes';
import { members } from '@/routes/app';
import { index as billingIndex } from '@/routes/app/billing';
import { edit as profileEdit } from '@/routes/app/profile';
import { channels as channelsSettings } from '@/routes/app/workspace';
import { switchMethod } from '@/routes/app/workspaces';
import type { Language, User } from '@/types';
import type { SidebarChannel } from '@/types/channel';

interface Workspace {
    id: string;
    name: string;
    logo_url: string | null;
}

const props = defineProps<{
    user: User;
    currentWorkspace: Workspace | null;
    workspaces: Workspace[];
    canCreateWorkspace: boolean;
}>();

const emit = defineEmits<{
    upgradeRequired: [];
}>();

const page = usePage();
const { canManageBilling, canManageTeam, canManageWorkspace } =
    useWorkspaceAbilities();
const { createOrUpgrade } = useWorkspaceLimit(() => props.workspaces.length);
const selfHosted = computed(() => Boolean(page.props.selfHosted));
const showBilling = computed(
    () => canManageBilling.value && !selfHosted.value,
);
const showChannels = computed(() => canManageWorkspace.value);
const planName = computed(() =>
    showBilling.value ? (page.props.auth.plan?.name ?? null) : null,
);
const channelCount = computed(
    () => ((page.props.channels as SidebarChannel[] | undefined) ?? []).length,
);
const languages = computed<Language[]>(
    () => page.props.languages as Language[],
);
const currentLanguage = computed(() =>
    languages.value?.find((language) => language.code === page.props.locale),
);

const switchLanguage = (code: string): void => {
    router.put(updateLanguage.url(), { locale: code });
};

const themes = [
    { value: 'light', icon: IconSun },
    { value: 'dark', icon: IconMoon },
    { value: 'system', icon: IconDeviceDesktop },
] as const satisfies readonly { value: Theme; icon: unknown }[];
const currentTheme = ref<Theme>(props.user.theme);
const currentThemeOption = computed(
    () =>
        themes.find((theme) => theme.value === currentTheme.value) ??
        themes[2],
);

const switchTheme = (theme: Theme): void => {
    if (theme === currentTheme.value) {
        return;
    }

    currentTheme.value = theme;
    applyTheme(theme);
    router.patch(
        PreferencesController.update.url(),
        { theme },
        { preserveScroll: true, preserveState: true },
    );
};

const switchWorkspace = (workspaceId: string): void => {
    if (workspaceId === props.currentWorkspace?.id) {
        return;
    }

    router.post(
        switchMethod.url(workspaceId),
        {},
        {
            preserveScroll: true,
        },
    );
};

const handleCreateWorkspace = (): void => {
    createOrUpgrade(() => emit('upgradeRequired'));
};

const handleLogout = (): void => {
    posthog.reset();
    clearAllComposerAutosaves();
    router.flushAll();
};

const showChatSupport = computed(() => page.props.selfHosted === false);

const statusTheme = computed(() =>
    currentTheme.value === 'dark' ||
    (currentTheme.value === 'system' &&
        window.matchMedia('(prefers-color-scheme: dark)').matches)
        ? 'dark'
        : 'light',
);

const supportLinks = [
    {
        key: 'help-center',
        label: 'sidebar.support.help_center',
        href: 'https://docs.trypost.it',
        icon: IconLifebuoy,
    },
    {
        key: 'discord',
        label: 'sidebar.support.discord',
        href: 'https://trypost.it/discord',
        icon: IconBrandDiscord,
    },
    {
        key: 'feature-requests',
        label: 'sidebar.support.feature_requests',
        href: 'https://github.com/orgs/trypostit/discussions/categories/feature-requests',
        icon: IconBulb,
    },
    {
        key: 'github',
        label: 'sidebar.support.github',
        href: 'https://github.com/trypostit',
        icon: IconBrandGithub,
    },
];
</script>

<template>
    <div class="flex flex-col gap-3 px-2 pt-2 pb-2">
        <div class="flex min-w-0 items-center gap-3">
            <Avatar
                :src="user.photo_url"
                :name="user.name"
                class="size-10 shrink-0 rounded-lg"
                fallback-class="text-sm bg-primary-subtle text-primary-text font-bold"
                data-testid="sidebar-menu-avatar"
            />
            <div class="grid min-w-0 gap-0.5">
                <span
                    class="truncate text-sm leading-5 font-semibold text-foreground"
                    data-testid="sidebar-menu-name"
                >
                    {{ user.name }}
                </span>
                <span
                    class="truncate text-xs text-muted-foreground"
                    data-testid="sidebar-menu-plan"
                >
                    <template v-if="planName">{{ planName }} · </template>
                    {{ $tChoice('sidebar.channels_count', channelCount) }}
                </span>
            </div>
        </div>
        <DropdownMenuItem
            v-if="canManageTeam"
            :as-child="true"
            :class="
                cn(
                    buttonVariants({ variant: 'outline' }),
                    'w-full justify-center gap-2 py-0',
                )
            "
        >
            <Link
                :href="members.url()"
                prefetch
                data-testid="sidebar-menu-manage-team"
            >
                <IconUsers class="size-4 text-foreground" />
                {{ $t('sidebar.manage_team') }}
            </Link>
        </DropdownMenuItem>
    </div>

    <DropdownMenuSeparator />

    <DropdownMenuLabel
        class="px-2 py-1.5 text-xs font-medium tracking-normal text-muted-foreground normal-case"
        data-testid="sidebar-menu-section-workspace"
    >
        {{ $t('settings.sidebar.groups.workspace') }}
    </DropdownMenuLabel>
    <DropdownMenuGroup>
        <DropdownMenuSub>
            <DropdownMenuSubTrigger data-testid="sidebar-workspaces-trigger">
                <Avatar
                    :src="currentWorkspace?.logo_url ?? null"
                    :name="currentWorkspace?.name ?? ''"
                    class="size-4 shrink-0 rounded-sm"
                    fallback-class="text-[8px] bg-primary-subtle text-primary-text font-bold"
                />
                <span class="min-w-0 truncate">{{ currentWorkspace?.name }}</span>
            </DropdownMenuSubTrigger>
            <DropdownMenuPortal>
                <DropdownMenuSubContent class="w-64">
                    <DropdownMenuLabel
                        class="px-2 py-1.5 text-xs font-medium tracking-normal text-muted-foreground normal-case"
                    >
                        {{ $t('sidebar.workspaces') }}
                    </DropdownMenuLabel>
                    <DropdownMenuItem
                        v-for="workspace in workspaces"
                        :key="workspace.id"
                        class="gap-2"
                        :data-testid="`sidebar-workspace-${workspace.id}`"
                        @click="switchWorkspace(workspace.id)"
                    >
                        <Avatar
                            :src="workspace.logo_url"
                            :name="workspace.name"
                            class="h-6 w-6 shrink-0 rounded-md border border-border"
                            fallback-class="text-[10px] bg-primary-subtle text-primary-text font-bold"
                        />
                        <span class="min-w-0 flex-1 truncate">{{
                            workspace.name
                        }}</span>
                        <IconCheck
                            v-if="workspace.id === currentWorkspace?.id"
                            class="size-4 shrink-0 text-foreground"
                            stroke-width="2.5"
                        />
                    </DropdownMenuItem>
                    <template v-if="canCreateWorkspace">
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            data-testid="sidebar-create-workspace"
                            @click="handleCreateWorkspace"
                        >
                            <IconPlus class="size-4" />
                            {{ $t('sidebar.create_workspace') }}
                        </DropdownMenuItem>
                    </template>
                </DropdownMenuSubContent>
            </DropdownMenuPortal>
        </DropdownMenuSub>
        <DropdownMenuItem :as-child="true">
            <Link
                class="block w-full cursor-pointer"
                :href="profileEdit.url()"
                prefetch
                data-testid="sidebar-menu-settings"
            >
                <IconSettings class="size-4" />
                {{ $t('sidebar.settings') }}
            </Link>
        </DropdownMenuItem>
        <DropdownMenuItem v-if="showChannels" :as-child="true">
            <Link
                class="block w-full cursor-pointer"
                :href="channelsSettings.url()"
                prefetch
                data-testid="sidebar-menu-channels"
            >
                <IconLayoutGrid class="size-4" />
                {{ $t('sidebar.channels') }}
            </Link>
        </DropdownMenuItem>
        <DropdownMenuItem v-if="showBilling" :as-child="true">
            <Link
                class="block w-full cursor-pointer"
                :href="billingIndex.url()"
                prefetch
                data-testid="sidebar-menu-billing"
            >
                <IconCoin class="size-4" />
                {{ $t('sidebar.plans_billing') }}
            </Link>
        </DropdownMenuItem>
    </DropdownMenuGroup>

    <DropdownMenuSeparator />

    <DropdownMenuLabel
        class="px-2 py-1.5 text-xs font-medium tracking-normal text-muted-foreground normal-case"
        data-testid="sidebar-menu-section-preferences"
    >
        {{ $t('settings.sidebar.items.preferences') }}
    </DropdownMenuLabel>
    <DropdownMenuGroup>
        <DropdownMenuSub v-if="languages && languages.length > 1">
            <DropdownMenuSubTrigger data-testid="sidebar-language-trigger">
                <img
                    v-if="currentLanguage"
                    :src="currentLanguage.flag"
                    :alt="currentLanguage.name"
                    class="h-3.5 w-5 shrink-0 rounded-xs object-cover"
                />
                <IconLanguage v-else />
                {{
                    $t('sidebar.language', {
                        name: currentLanguage?.name ?? 'English',
                    })
                }}
            </DropdownMenuSubTrigger>
            <DropdownMenuPortal>
                <DropdownMenuSubContent>
                    <DropdownMenuItem
                        v-for="language in languages"
                        :key="language.code"
                        :class="
                            language.code === currentLanguage?.code
                                ? 'bg-accent'
                                : ''
                        "
                        :data-testid="`sidebar-language-${language.code}`"
                        @click="switchLanguage(language.code)"
                    >
                        <img
                            :src="language.flag"
                            :alt="language.name"
                            class="h-3.5 w-5 shrink-0 rounded-xs object-cover"
                        />
                        {{ language.name }}
                        <IconCheck
                            v-if="language.code === currentLanguage?.code"
                            class="ms-auto size-4 shrink-0 text-foreground"
                            stroke-width="2.5"
                        />
                    </DropdownMenuItem>
                </DropdownMenuSubContent>
            </DropdownMenuPortal>
        </DropdownMenuSub>
        <DropdownMenuSub>
            <DropdownMenuSubTrigger data-testid="sidebar-theme-trigger">
                <component :is="currentThemeOption.icon" />
                {{
                    $t('sidebar.theme', {
                        name: $t(
                            `settings.preferences.theme.${currentThemeOption.value}`,
                        ),
                    })
                }}
            </DropdownMenuSubTrigger>
            <DropdownMenuPortal>
                <DropdownMenuSubContent>
                    <DropdownMenuItem
                        v-for="theme in themes"
                        :key="theme.value"
                        :class="theme.value === currentTheme ? 'bg-accent' : ''"
                        :data-testid="`sidebar-theme-${theme.value}`"
                        @click="switchTheme(theme.value)"
                    >
                        <component :is="theme.icon" />
                        {{ $t(`settings.preferences.theme.${theme.value}`) }}
                        <IconCheck
                            v-if="theme.value === currentTheme"
                            class="ms-auto size-4 shrink-0 text-foreground"
                            stroke-width="2.5"
                        />
                    </DropdownMenuItem>
                </DropdownMenuSubContent>
            </DropdownMenuPortal>
        </DropdownMenuSub>
    </DropdownMenuGroup>

    <DropdownMenuSeparator />

    <DropdownMenuGroup>
        <DropdownMenuSub>
            <DropdownMenuSubTrigger data-testid="sidebar-support-trigger">
                <IconHelpCircle />
                {{ $t('sidebar.support.menu') }}
            </DropdownMenuSubTrigger>
            <DropdownMenuPortal>
                <DropdownMenuSubContent class="w-64">
                    <DropdownMenuLabel
                        class="px-2 py-1.5 text-xs font-medium tracking-normal text-muted-foreground normal-case"
                        data-testid="sidebar-support-help-title"
                    >
                        {{ $t('sidebar.help') }}
                    </DropdownMenuLabel>
                    <DropdownMenuItem
                        v-for="link in supportLinks.slice(0, 1)"
                        :key="link.key"
                        as-child
                    >
                        <a
                            :href="link.href"
                            target="_blank"
                            rel="noopener noreferrer"
                            :data-testid="`sidebar-support-${link.key}`"
                        >
                            <component :is="link.icon" />
                            {{ $t(link.label) }}
                        </a>
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-if="showChatSupport"
                        data-testid="sidebar-support-chat"
                        @click="openSupportChat"
                    >
                        <IconMessageChatbot />
                        {{ $t('sidebar.support.chat') }}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuLabel
                        class="px-2 py-1.5 text-xs font-medium tracking-normal text-muted-foreground normal-case"
                        data-testid="sidebar-support-community-title"
                    >
                        {{ $t('sidebar.support.community') }}
                    </DropdownMenuLabel>
                    <DropdownMenuItem
                        v-for="link in supportLinks.slice(1)"
                        :key="link.key"
                        as-child
                    >
                        <a
                            :href="link.href"
                            target="_blank"
                            rel="noopener noreferrer"
                            :data-testid="`sidebar-support-${link.key}`"
                        >
                            <component :is="link.icon" />
                            {{ $t(link.label) }}
                        </a>
                    </DropdownMenuItem>
                    <template v-if="showChatSupport">
                        <DropdownMenuSeparator />
                        <DropdownMenuLabel
                            class="px-2 py-1.5 text-xs font-medium tracking-normal text-muted-foreground normal-case"
                            data-testid="sidebar-support-status-title"
                        >
                            {{ $t('sidebar.support.status') }}
                        </DropdownMenuLabel>
                        <DropdownMenuItem as-child>
                            <a
                                href="https://status.trypost.it"
                                target="_blank"
                                rel="noopener noreferrer"
                                :aria-label="$t('sidebar.support.status')"
                                data-testid="sidebar-support-status"
                            >
                                <iframe
                                    :src="`https://status.trypost.it/badge?theme=${statusTheme}`"
                                    :title="$t('sidebar.support.status')"
                                    width="250"
                                    height="30"
                                    frameborder="0"
                                    scrolling="no"
                                    tabindex="-1"
                                    class="pointer-events-none"
                                    style="color-scheme: normal"
                                />
                            </a>
                        </DropdownMenuItem>
                    </template>
                </DropdownMenuSubContent>
            </DropdownMenuPortal>
        </DropdownMenuSub>
    </DropdownMenuGroup>


    <DropdownMenuSeparator />

    <DropdownMenuItem :as-child="true">
        <Link
            class="block w-full cursor-pointer"
            :href="logout()"
            as="button"
            data-test="logout-button"
            data-testid="logout-button"
            @click="handleLogout"
        >
            <IconLogout class="size-4" />
            {{ $t('sidebar.log_out') }}
        </Link>
    </DropdownMenuItem>
</template>
