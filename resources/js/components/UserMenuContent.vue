<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    IconLanguage,
    IconLogout,
    IconSettings,
    IconUser,
} from '@tabler/icons-vue';
import { computed } from 'vue';

import { updateLanguage } from '@/actions/App/Http/Controllers/App/Settings/ProfileController';
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
import UserInfo from '@/components/UserInfo.vue';
import posthog from '@/posthog';
import { logout } from '@/routes';
import { settings as settingsHub } from '@/routes/app';
import { edit } from '@/routes/app/profile';
import type { User } from '@/types';

interface Language {
    code: string;
    name: string;
}

type Props = {
    user: User;
};

defineProps<Props>();

const page = usePage();
const languages = computed<Language[]>(() => page.props.languages as Language[]);
const currentLanguage = computed(() => languages.value?.find((l: Language) => l.code === page.props.locale));

const switchLanguage = (code: string) => {
    router.put(updateLanguage.url(), { locale: code }, {
        onSuccess: () => window.location.reload(),
    });
};

const handleLogout = () => {
    posthog.reset();
    router.flushAll();
};
</script>

<template>
    <DropdownMenuLabel class="p-0 text-sm font-normal normal-case tracking-normal text-foreground">
        <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
            <UserInfo
                :user="user"
                :show-email="true"
                fallback-class="bg-violet-100 text-violet-700"
            />
        </div>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuItem :as-child="true">
            <Link class="block w-full cursor-pointer" :href="edit()" prefetch>
                <IconUser class="size-4" />
                {{ $t('sidebar.profile') }}
            </Link>
        </DropdownMenuItem>
        <DropdownMenuItem :as-child="true">
            <Link class="block w-full cursor-pointer" :href="settingsHub.url()" prefetch>
                <IconSettings class="size-4" />
                {{ $t('sidebar.settings') }}
            </Link>
        </DropdownMenuItem>
    </DropdownMenuGroup>
    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuSub v-if="languages && languages.length > 1">
            <DropdownMenuSubTrigger>
                <IconLanguage />
                {{ $t('sidebar.language', { name: currentLanguage?.name ?? 'English' }) }}
            </DropdownMenuSubTrigger>
            <DropdownMenuPortal>
                <DropdownMenuSubContent>
                    <DropdownMenuItem
                        v-for="language in languages"
                        :key="language.code"
                        :class="language.code === currentLanguage?.code ? 'bg-accent' : ''"
                        @click="switchLanguage(language.code)"
                    >
                        {{ language.name }}
                    </DropdownMenuItem>
                </DropdownMenuSubContent>
            </DropdownMenuPortal>
        </DropdownMenuSub>
    </DropdownMenuGroup>
    <DropdownMenuSeparator />
    <DropdownMenuItem :as-child="true">
        <Link
            class="block w-full cursor-pointer"
            :href="logout()"
            @click="handleLogout"
            as="button"
            data-test="logout-button"
        >
            <IconLogout class="size-4" />
            {{ $t('sidebar.log_out') }}
        </Link>
    </DropdownMenuItem>
</template>
