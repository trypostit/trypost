<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import {
    IconBuilding,
    IconExternalLink,
    IconUser,
} from '@tabler/icons-vue';
import { computed } from 'vue';

import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import PopupLayout from '@/layouts/PopupLayout.vue';
import { select as selectLinkedIn } from '@/routes/app/social/linkedin';

interface Person {
    id: string;
    name: string;
    avatar: string | null;
    vanity_name: string | null;
}

interface Organization {
    id: string;
    name: string;
    vanity_name: string | null;
    logo: string | null;
}

const props = defineProps<{
    person: Person | null;
    organizations: Organization[];
}>();

const form = useForm({ type: 'person', organization_id: '' });

const isEmpty = computed(
    () => !props.person && props.organizations.length === 0,
);

const choosePerson = () => {
    form.type = 'person';
    form.organization_id = '';
    form.post(selectLinkedIn.url());
};

const chooseOrganization = (org: Organization) => {
    form.type = 'organization';
    form.organization_id = org.id;
    form.post(selectLinkedIn.url());
};

const openExternal = (url: string | null) => {
    if (url) {
        window.open(url, '_blank', 'noopener');
    }
};

const personUrl = (vanity: string | null): string | null =>
    vanity ? `https://www.linkedin.com/in/${vanity}` : null;

const organizationUrl = (vanity: string | null): string | null =>
    vanity ? `https://www.linkedin.com/company/${vanity}` : null;
</script>

<template>
    <PopupLayout :title="$t('accounts.linkedin.select_title')">
        <div class="flex flex-col gap-6">
            <div class="flex items-center gap-3">
                <img src="/images/accounts/linkedin.png" alt="LinkedIn" class="h-10 w-10" />
                <div>
                    <h1 class="text-xl font-bold tracking-tight">
                        {{ $t('accounts.linkedin.select_title') }}
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        {{ $t('accounts.linkedin.select_subtitle') }}
                    </p>
                </div>
            </div>

            <div v-if="isEmpty" class="py-12 text-center" dusk="linkedin-identity-empty">
                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-muted">
                    <IconBuilding class="h-7 w-7 text-muted-foreground" />
                </div>
                <h3 class="mt-4 text-lg font-semibold">{{ $t('accounts.linkedin.no_pages') }}</h3>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ $t('accounts.linkedin.no_pages_description') }}
                </p>
            </div>

            <div v-else class="grid gap-3">
                <div
                    v-if="person"
                    class="flex items-center gap-4 rounded-lg border bg-card p-4"
                    dusk="linkedin-identity-person"
                >
                    <Avatar class="h-12 w-12 shrink-0 rounded-lg">
                        <AvatarImage v-if="person.avatar" :src="person.avatar" class="object-cover" />
                        <AvatarFallback class="rounded-lg bg-blue-100 dark:bg-blue-900">
                            <IconUser class="h-6 w-6 text-blue-600 dark:text-blue-400" />
                        </AvatarFallback>
                    </Avatar>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <h3 class="truncate font-semibold">{{ person.name }}</h3>
                            <span
                                class="inline-flex shrink-0 items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground"
                            >
                                <IconUser class="h-3 w-3" />
                                {{ $t('accounts.linkedin.person_tag') }}
                            </span>
                        </div>
                        <p v-if="person.vanity_name" class="truncate text-sm text-muted-foreground">
                            linkedin.com/in/{{ person.vanity_name }}
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <Button
                            v-if="personUrl(person.vanity_name)"
                            variant="ghost"
                            size="sm"
                            @click="openExternal(personUrl(person.vanity_name))"
                        >
                            <IconExternalLink class="h-4 w-4" />
                            <span class="hidden sm:inline">{{ $t('accounts.linkedin.view') }}</span>
                        </Button>
                        <Button size="sm" dusk="choose-person" :disabled="form.processing" @click="choosePerson">
                            {{ $t('accounts.linkedin.choose') }}
                        </Button>
                    </div>
                </div>

                <div
                    v-for="org in organizations"
                    :key="org.id"
                    class="flex items-center gap-4 rounded-lg border bg-card p-4"
                    dusk="linkedin-identity-organization"
                >
                    <Avatar class="h-12 w-12 shrink-0 rounded-lg">
                        <AvatarImage v-if="org.logo" :src="org.logo" class="object-cover" />
                        <AvatarFallback class="rounded-lg bg-blue-100 dark:bg-blue-900">
                            <IconBuilding class="h-6 w-6 text-blue-600 dark:text-blue-400" />
                        </AvatarFallback>
                    </Avatar>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <h3 class="truncate font-semibold">{{ org.name }}</h3>
                            <span
                                class="inline-flex shrink-0 items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground"
                            >
                                <IconBuilding class="h-3 w-3" />
                                {{ $t('accounts.linkedin.organization_tag') }}
                            </span>
                        </div>
                        <p v-if="org.vanity_name" class="truncate text-sm text-muted-foreground">
                            linkedin.com/company/{{ org.vanity_name }}
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <Button
                            v-if="organizationUrl(org.vanity_name)"
                            variant="ghost"
                            size="sm"
                            @click="openExternal(organizationUrl(org.vanity_name))"
                        >
                            <IconExternalLink class="h-4 w-4" />
                            <span class="hidden sm:inline">{{ $t('accounts.linkedin.view') }}</span>
                        </Button>
                        <Button size="sm" dusk="choose-organization" :disabled="form.processing" @click="chooseOrganization(org)">
                            {{ $t('accounts.linkedin.choose') }}
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    </PopupLayout>
</template>
