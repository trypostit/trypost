<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { login, register } from '@/routes';
import { accept, decline } from '@/routes/app/invites';
import { type SharedData } from '@/types';

const props = defineProps<{
    invite: {
        id: string;
        email: string;
        role: {
            value: string;
            label: string;
        };
        workspace: {
            id: string;
            name: string;
        } | null;
        account: {
            id: string;
            name: string;
        };
    };
}>();

const page = usePage<SharedData>();
const user = computed(() => page.props.auth?.user);
const isLoggedIn = computed(() => !!user.value);

const inviteUrl = computed(() => `/invites/${props.invite.id}`);
</script>

<template>
    <div class="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10">

        <Head :title="$t('auth.accept_invite.page_title')" />

        <div class="w-full max-w-md">
            <div class="flex flex-col gap-8">
                <div class="flex flex-col items-center gap-4">
                    <Link href="/" class="flex flex-col items-center gap-2 font-medium">
                        <img src="/images/trypost/logo-light.png" alt="TryPost" class="dark:hidden h-8 w-auto" />
                        <img src="/images/trypost/logo-dark.png" alt="TryPost" class="hidden dark:block h-8 w-auto" />
                    </Link>
                </div>

                <Card>
                    <CardHeader class="text-center">
                        <CardTitle class="text-xl">{{ $t('auth.accept_invite.title') }}</CardTitle>
                        <CardDescription>
                            {{ $t('auth.accept_invite.description', { workspace: invite.workspace?.name ?? invite.account.name }) }}
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-6">
                        <div class="rounded-lg bg-muted p-4 space-y-2">
                            <div v-if="invite.workspace" class="flex justify-between gap-3 text-sm">
                                <span class="shrink-0 text-muted-foreground">{{ $t('auth.accept_invite.workspace') }}</span>
                                <span class="min-w-0 truncate font-medium">{{ invite.workspace.name }}</span>
                            </div>
                            <div class="flex justify-between gap-3 text-sm">
                                <span class="shrink-0 text-muted-foreground">{{ $t('auth.accept_invite.your_role') }}</span>
                                <span class="min-w-0 truncate font-medium">{{ invite.role.label }}</span>
                            </div>
                            <div class="flex justify-between gap-3 text-sm">
                                <span class="shrink-0 text-muted-foreground">{{ $t('auth.accept_invite.email') }}</span>
                                <span class="min-w-0 truncate font-medium">{{ invite.email }}</span>
                            </div>
                        </div>

                        <!-- User is logged in - show Accept/Decline -->
                        <div v-if="isLoggedIn" class="flex flex-col gap-3">
                            <Button as-child size="lg" class="w-full">
                                <Link :href="accept.url(invite.id)" method="post">
                                    {{ $t('auth.accept_invite.accept') }}
                                </Link>
                            </Button>
                            <Button as-child variant="outline" size="lg" class="w-full">
                                <Link :href="decline.url(invite.id)" method="post">
                                    {{ $t('auth.accept_invite.decline') }}
                                </Link>
                            </Button>
                        </div>

                        <!-- User is not logged in - show Login/Register -->
                        <div v-else class="flex flex-col gap-3">
                            <p class="text-center text-sm text-muted-foreground">
                                {{ $t('auth.accept_invite.login_prompt') }}
                            </p>
                            <Button as-child size="lg" class="w-full">
                                <Link :href="login({ query: { redirect: inviteUrl, email: invite.email } })">
                                    {{ $t('auth.accept_invite.log_in') }}
                                </Link>
                            </Button>
                            <Button as-child variant="outline" size="lg" class="w-full">
                                <Link :href="register({ query: { redirect: inviteUrl, email: invite.email, invite: invite.id } })">
                                    {{ $t('auth.accept_invite.create_account') }}
                                </Link>
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    </div>
</template>