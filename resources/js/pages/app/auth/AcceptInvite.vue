<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { login, register } from '@/routes';
import { home } from '@/routes/app';
import { accept, decline } from '@/routes/app/invites';
import { type SharedData } from '@/types';
import { type MemberAccess, memberAccessLabelKey } from '@/types/members';

const props = defineProps<{
    expired: boolean;
    invite: {
        id: string;
        email: string;
        access: MemberAccess;
        workspace: {
            id: string;
            name: string;
        };
    } | null;
}>();

const page = usePage<SharedData>();
const isLoggedIn = computed(() => Boolean(page.props.auth?.user));
const accessKey = computed(() =>
    props.invite ? memberAccessLabelKey(props.invite.access) : '',
);
</script>

<template>
    <Head :title="$t('auth.accept_invite.page_title')" />

    <AuthLayout
        :title="
            expired
                ? $t('auth.accept_invite.expired_title')
                : $t('auth.accept_invite.title')
        "
        :description="
            expired
                ? $t('auth.accept_invite.expired_description')
                : $t('auth.accept_invite.description', {
                      workspace: invite?.workspace.name ?? '',
                  })
        "
    >
        <div class="flex flex-col gap-6">
            <template v-if="!expired && invite">
                <dl
                    class="flex flex-col gap-2 rounded-xl border border-border bg-card p-4 text-sm"
                >
                    <div class="flex justify-between gap-3">
                        <dt class="shrink-0 text-muted-foreground">
                            {{ $t('auth.accept_invite.workspace') }}
                        </dt>
                        <dd class="min-w-0 truncate font-medium">
                            {{ invite.workspace.name }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="shrink-0 text-muted-foreground">
                            {{ $t('auth.accept_invite.your_role') }}
                        </dt>
                        <dd class="min-w-0 truncate font-medium">
                            {{ $t(accessKey) }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="shrink-0 text-muted-foreground">
                            {{ $t('auth.accept_invite.email') }}
                        </dt>
                        <dd class="min-w-0 truncate font-medium">
                            {{ invite.email }}
                        </dd>
                    </div>
                </dl>

                <div v-if="isLoggedIn" class="flex flex-col gap-2">
                    <Button as-child class="w-full">
                        <Link :href="accept.url(invite.id)" method="post">
                            {{ $t('auth.accept_invite.accept') }}
                        </Link>
                    </Button>
                    <Button
                        as-child
                        variant="outline"
                        class="w-full bg-card"
                    >
                        <Link :href="decline.url(invite.id)" method="post">
                            {{ $t('auth.accept_invite.decline') }}
                        </Link>
                    </Button>
                </div>

                <div v-else class="flex flex-col gap-2">
                    <p class="text-center text-sm text-muted-foreground">
                        {{ $t('auth.accept_invite.login_prompt') }}
                    </p>
                    <Button as-child class="w-full">
                        <Link
                            :href="
                                login({
                                    query: {
                                        invite: invite.id,
                                        email: invite.email,
                                    },
                                })
                            "
                        >
                            {{ $t('auth.accept_invite.log_in') }}
                        </Link>
                    </Button>
                    <Button
                        as-child
                        variant="outline"
                        class="w-full bg-card"
                    >
                        <Link
                            :href="
                                register({
                                    query: {
                                        invite: invite.id,
                                        email: invite.email,
                                    },
                                })
                            "
                        >
                            {{ $t('auth.accept_invite.create_account') }}
                        </Link>
                    </Button>
                </div>
            </template>

            <Button v-else as-child class="w-full">
                <Link :href="home()">
                    {{ $t('auth.accept_invite.expired_action') }}
                </Link>
            </Button>
        </div>
    </AuthLayout>
</template>
