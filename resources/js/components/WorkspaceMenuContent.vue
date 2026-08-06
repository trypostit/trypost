<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    IconCheck,
    IconCreditCard,
    IconLanguage,
    IconLogout,
    IconPlus,
    IconSettings,
    IconUser,
} from '@tabler/icons-vue';
import { computed } from 'vue';

import { updateLanguage } from '@/actions/App/Http/Controllers/App/Settings/ProfileController';
import { Avatar } from '@/components/ui/avatar';
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
import { useWorkspaceRole } from '@/composables/useWorkspaceRole';
import posthog from '@/posthog';
import { logout } from '@/routes';
import { edit as accountEdit } from '@/routes/app/account';
import { edit as profileEdit } from '@/routes/app/profile';
import { settings as workspaceSettings } from '@/routes/app/workspace';
import {
    create as createWorkspaceRoute,
    switchMethod,
} from '@/routes/app/workspaces';
import type { User } from '@/types';

interface Workspace {
    id: string;
    name: string;
    logo_url: string | null;
}

interface Language {
    code: string;
    name: string;
}

const props = defineProps<{
    user: User;
    currentWorkspace: Workspace | null;
    workspaces: Workspace[];
    canCreateWorkspace: boolean;
}>();

const page = usePage();
const { canManageBilling, canManageWorkspace } = useWorkspaceRole();
const selfHosted = computed(() => Boolean(page.props.selfHosted));
const showAccountSettings = computed(
    () => canManageBilling.value && !selfHosted.value,
);
const showWorkspaceSettings = computed(() => canManageWorkspace.value);
const languages = computed<Language[]>(
    () => page.props.languages as Language[],
);
const currentLanguage = computed(() =>
    languages.value?.find((language) => language.code === page.props.locale),
);

const switchLanguage = (code: string): void => {
    router.put(
        updateLanguage.url(),
        { locale: code },
        {
            onSuccess: () => window.location.reload(),
        },
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
    router.visit(createWorkspaceRoute.url());
};

const handleLogout = (): void => {
    posthog.reset();
    router.flushAll();
};
</script>

<template>
    <DropdownMenuLabel
        class="px-2 py-1.5 text-xs font-medium tracking-normal text-muted-foreground normal-case"
    >
        {{ user.name }}
    </DropdownMenuLabel>

    <DropdownMenuGroup>
        <DropdownMenuItem :as-child="true">
            <Link
                class="block w-full cursor-pointer"
                :href="profileEdit.url()"
                prefetch
                data-testid="sidebar-menu-my-account"
            >
                <IconUser class="size-4" />
                {{ $t('sidebar.my_account') }}
            </Link>
        </DropdownMenuItem>
        <DropdownMenuItem v-if="showAccountSettings" :as-child="true">
            <Link
                class="block w-full cursor-pointer"
                :href="accountEdit.url()"
                prefetch
                data-testid="sidebar-menu-account-settings"
            >
                <IconCreditCard class="size-4" />
                {{ $t('sidebar.account_settings') }}
            </Link>
        </DropdownMenuItem>
        <DropdownMenuItem v-if="showWorkspaceSettings" :as-child="true">
            <Link
                class="block w-full cursor-pointer"
                :href="workspaceSettings.url()"
                prefetch
                data-testid="sidebar-menu-workspace-settings"
            >
                <IconSettings class="size-4" />
                {{ $t('sidebar.workspace_settings') }}
            </Link>
        </DropdownMenuItem>
    </DropdownMenuGroup>

    <DropdownMenuSeparator />

    <DropdownMenuGroup>
        <DropdownMenuSub v-if="languages && languages.length > 1">
            <DropdownMenuSubTrigger>
                <IconLanguage />
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
                        @click="switchLanguage(language.code)"
                    >
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
    </DropdownMenuGroup>

    <DropdownMenuSeparator />

    <DropdownMenuLabel
        class="px-2 py-1.5 text-xs font-medium tracking-normal text-muted-foreground normal-case"
    >
        {{ $t('sidebar.workspaces') }}
    </DropdownMenuLabel>

    <DropdownMenuGroup>
        <DropdownMenuItem
            v-for="workspace in workspaces"
            :key="workspace.id"
            class="gap-2"
            @click="switchWorkspace(workspace.id)"
        >
            <Avatar
                :src="workspace.logo_url"
                :name="workspace.name"
                class="h-6 w-6 shrink-0 rounded-md border-2 border-foreground"
                fallback-class="text-[10px] bg-violet-100 text-violet-700 font-bold"
            />
            <span class="min-w-0 flex-1 truncate">{{ workspace.name }}</span>
            <IconCheck
                v-if="workspace.id === currentWorkspace?.id"
                class="size-4 shrink-0 text-foreground"
                stroke-width="2.5"
            />
        </DropdownMenuItem>
        <DropdownMenuItem
            v-if="canCreateWorkspace"
            @click="handleCreateWorkspace"
        >
            <IconPlus class="size-4" />
            {{ $t('sidebar.create_workspace') }}
        </DropdownMenuItem>
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
