<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { IconCopy } from '@tabler/icons-vue';
import { computed, watch } from 'vue';

import NetworkConnectGrid, {
    type AvailablePlatform,
    type ConnectedAccount,
} from '@/components/accounts/NetworkConnectGrid.vue';
import McpPrimarySetup from '@/components/mcp/McpPrimarySetup.vue';
import OnboardingStepCard from '@/components/onboarding/OnboardingStepCard.vue';
import { Button } from '@/components/ui/button';
import { useOnboardingLiveReload } from '@/composables/useOnboardingLiveReload';
import AppLayout from '@/layouts/AppLayout.vue';
import { copyToClipboard } from '@/lib/utils';
import { complete } from '@/routes/app/onboarding';
import { skip as skipMcpRoute } from '@/routes/app/onboarding/mcp';
import { create as createPost } from '@/routes/app/posts';

interface OnboardingStatus {
    mcp_connected: boolean;
    social_connected: boolean;
    first_post_created: boolean;
    skipped_steps: string[];
    all_complete: boolean;
    show_progress: boolean;
    completed_at: string | null;
    dismissed_at: string | null;
}

const props = defineProps<{
    status: OnboardingStatus;
    canSkipSteps: boolean;
    canManageAccounts: boolean;
    canCreatePost: boolean;
    mcpUrl: string;
    platforms: AvailablePlatform[];
    accounts: ConnectedAccount[];
}>();

const page = usePage();
const firstName = computed(() => page.props.auth.user.first_name);
const skipMcpForm = useForm({});
const completeForm = useForm({});
const reloadOnly = ['status', 'accounts', 'onboardingProgress'];
let completeAttempts = 0;
const maxCompleteAttempts = 3;

const socialConnectedElsewhere = computed(
    () =>
        props.status.social_connected &&
        !props.accounts.some((account) => account.status === 'connected'),
);

// Keep listening until completion is stamped — all_complete alone is not enough
// (auto-complete POST can fail before completed_at lands).
useOnboardingLiveReload({
    only: reloadOnly,
    enabled: () =>
        !props.status.completed_at && !props.status.dismissed_at,
});

const stampCompletionIfReady = (): void => {
    if (
        ! props.status.all_complete ||
        props.status.completed_at ||
        completeForm.processing ||
        ! props.canSkipSteps ||
        completeAttempts >= maxCompleteAttempts
    ) {
        return;
    }

    completeAttempts += 1;

    completeForm.submit(complete(), {
        preserveScroll: true,
        onError: () => {
            window.setTimeout(() => stampCompletionIfReady(), 1000);
        },
    });
};

watch(
    () => [props.status.all_complete, props.status.completed_at] as const,
    () => stampCompletionIfReady(),
    { immediate: true },
);

const isStepSkipped = (step: string): boolean =>
    props.status.skipped_steps.includes(step);
</script>

<template>
    <Head :title="$t('onboarding.title')" />

    <AppLayout>
        <div
            class="mx-auto flex h-full w-full max-w-5xl flex-1 flex-col gap-6 px-4 py-6 sm:px-6 sm:py-10"
        >
            <div class="min-w-0 space-y-1.5">
                <h1
                    class="text-xl font-bold text-foreground sm:text-2xl"
                    data-testid="onboarding-welcome"
                >
                    {{ $t('onboarding.welcome', { name: firstName }) }}
                </h1>
                <p class="text-sm text-muted-foreground sm:text-base">
                    {{ $t('onboarding.description') }}
                </p>
            </div>

            <div class="grid gap-6">
                <OnboardingStepCard
                    :done="status.mcp_connected || isStepSkipped('mcp')"
                    :skipped="isStepSkipped('mcp') && !status.mcp_connected"
                    :step="1"
                    :title="$t('onboarding.mcp.title')"
                    :description="$t('onboarding.mcp.description')"
                    accent-class="bg-violet-100"
                    data-testid="onboarding-mcp"
                >
                    <div class="space-y-6">
                        <McpPrimarySetup
                            :mcp-url="mcpUrl"
                            :copied-message="$t('onboarding.mcp.copied')"
                        />

                        <div
                            v-if="
                                canSkipSteps &&
                                !status.mcp_connected &&
                                !isStepSkipped('mcp')
                            "
                            class="flex justify-end"
                        >
                            <button
                                type="button"
                                class="text-sm font-semibold text-muted-foreground underline-offset-4 transition-colors hover:text-foreground hover:underline"
                                :disabled="skipMcpForm.processing"
                                data-testid="onboarding-mcp-skip"
                                @click="skipMcpForm.submit(skipMcpRoute())"
                            >
                                {{ $t('onboarding.skip_step') }}
                            </button>
                        </div>
                    </div>
                </OnboardingStepCard>

                <OnboardingStepCard
                    :done="status.social_connected"
                    :step="2"
                    :title="$t('onboarding.social.title')"
                    :description="$t('onboarding.social.description')"
                    accent-class="bg-sky-100"
                    data-testid="onboarding-social"
                >
                    <p
                        v-if="socialConnectedElsewhere"
                        class="text-sm text-muted-foreground"
                        data-testid="onboarding-social-elsewhere"
                    >
                        {{ $t('onboarding.social.connected_elsewhere') }}
                    </p>
                    <NetworkConnectGrid
                        v-else-if="canManageAccounts"
                        :platforms="platforms"
                        :connected-accounts="accounts"
                        grid-class="grid-cols-2 sm:grid-cols-3 xl:grid-cols-5"
                        data-testid="onboarding-social-controls"
                    />
                </OnboardingStepCard>

                <OnboardingStepCard
                    :done="status.first_post_created"
                    :step="3"
                    :title="$t('onboarding.first_post.title')"
                    :description="$t('onboarding.first_post.description')"
                    accent-class="bg-amber-100"
                    data-testid="onboarding-first-post"
                >
                    <div
                        class="rounded-xl border-2 border-foreground bg-amber-50 p-5 shadow-2xs"
                    >
                        <p
                            class="text-xs font-black tracking-widest text-muted-foreground uppercase"
                        >
                            {{ $t('onboarding.first_post.prompt_label') }}
                        </p>
                        <p
                            class="mt-3 text-sm leading-7 text-foreground sm:text-base"
                        >
                            {{ $t('onboarding.first_post.sample_prompt') }}
                        </p>
                    </div>

                    <div
                        class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-center"
                    >
                        <Button
                            type="button"
                            data-testid="copy-sample-prompt"
                            @click="
                                copyToClipboard(
                                    $t('onboarding.first_post.sample_prompt'),
                                    $t('onboarding.first_post.copied'),
                                )
                            "
                        >
                            <IconCopy class="size-4" />
                            {{ $t('onboarding.first_post.copy_prompt') }}
                        </Button>
                        <span
                            v-if="canCreatePost"
                            class="text-xs font-semibold text-muted-foreground"
                        >
                            {{ $t('onboarding.first_post.or') }}
                        </span>
                        <Button v-if="canCreatePost" as-child variant="outline">
                            <Link
                                :href="createPost.url()"
                                data-testid="create-first-post"
                            >
                                {{ $t('onboarding.first_post.create_button') }}
                            </Link>
                        </Button>
                    </div>
                </OnboardingStepCard>
            </div>
        </div>
    </AppLayout>
</template>
