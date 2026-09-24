<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    IconBuildingCommunity,
    IconChevronRight,
    IconCreditCard,
    IconUser,
} from '@tabler/icons-vue';

import AppLayout from '@/layouts/AppLayout.vue';
import { edit as accountEdit } from '@/routes/app/account';
import { edit as profileEdit } from '@/routes/app/profile';
import { settings as workspaceSettings } from '@/routes/app/workspace';

interface Permissions {
    canManageProfile: boolean;
    canManageWorkspace: boolean;
    canManageAccount: boolean;
}

defineProps<{
    permissions: Permissions;
}>();
</script>

<template>
    <Head :title="$t('settings.hub.title')" />

    <AppLayout :title="$t('settings.hub.title')">
        <div class="mx-auto max-w-4xl space-y-8 px-4 py-8">
            <p class="text-sm text-muted-foreground">
                {{ $t('settings.hub.description') }}
            </p>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                <Link
                    v-if="permissions.canManageProfile"
                    :href="profileEdit().url"
                    class="group flex flex-col gap-4 rounded-2xl border border-border bg-card p-5 shadow-2xs transition-all hover:-translate-y-0.5 hover:shadow-md"
                >
                    <div class="flex items-start justify-between">
                        <div
                            class="inline-flex size-12 items-center justify-center rounded-xl border border-border bg-muted shadow-xs transition-colors group-hover:bg-amber-100"
                        >
                            <IconUser
                                class="size-6 text-foreground"
                                stroke-width="2"
                            />
                        </div>
                        <IconChevronRight
                            class="size-5 text-foreground/40 transition-transform group-hover:translate-x-0.5 group-hover:text-foreground"
                        />
                    </div>
                    <div>
                        <h2 class="text-lg font-bold text-foreground">
                            {{ $t('settings.hub.profile.title') }}
                        </h2>
                        <p class="mt-1 text-sm text-foreground/70">
                            {{ $t('settings.hub.profile.description') }}
                        </p>
                    </div>
                </Link>

                <Link
                    v-if="permissions.canManageWorkspace"
                    :href="workspaceSettings().url"
                    class="group flex flex-col gap-4 rounded-2xl border border-border bg-card p-5 shadow-2xs transition-all hover:-translate-y-0.5 hover:shadow-md"
                >
                    <div class="flex items-start justify-between">
                        <div
                            class="inline-flex size-12 items-center justify-center rounded-xl border border-border bg-amber-100 shadow-xs transition-colors group-hover:bg-amber-200"
                        >
                            <IconBuildingCommunity
                                class="size-6 text-foreground"
                                stroke-width="2"
                            />
                        </div>
                        <IconChevronRight
                            class="size-5 text-foreground/40 transition-transform group-hover:translate-x-0.5 group-hover:text-foreground"
                        />
                    </div>
                    <div>
                        <h2 class="text-lg font-bold text-foreground">
                            {{ $t('settings.hub.workspace.title') }}
                        </h2>
                        <p class="mt-1 text-sm text-foreground/70">
                            {{ $t('settings.hub.workspace.description') }}
                        </p>
                    </div>
                </Link>

                <Link
                    v-if="permissions.canManageAccount"
                    :href="accountEdit().url"
                    class="group flex flex-col gap-4 rounded-2xl border border-border bg-card p-5 shadow-2xs transition-all hover:-translate-y-0.5 hover:shadow-md"
                >
                    <div class="flex items-start justify-between">
                        <div
                            class="inline-flex size-12 items-center justify-center rounded-xl border border-border bg-muted shadow-xs transition-colors group-hover:bg-amber-100"
                        >
                            <IconCreditCard
                                class="size-6 text-foreground"
                                stroke-width="2"
                            />
                        </div>
                        <IconChevronRight
                            class="size-5 text-foreground/40 transition-transform group-hover:translate-x-0.5 group-hover:text-foreground"
                        />
                    </div>
                    <div>
                        <h2 class="text-lg font-bold text-foreground">
                            {{ $t('settings.hub.account.title') }}
                        </h2>
                        <p class="mt-1 text-sm text-foreground/70">
                            {{ $t('settings.hub.account.description') }}
                        </p>
                    </div>
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
