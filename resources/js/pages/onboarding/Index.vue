<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { IconCheck, IconCopy } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, nextTick, ref, watch } from 'vue';

import NetworkConnectGrid, {
    type AvailablePlatform,
    type ConnectedAccount,
} from '@/components/accounts/NetworkConnectGrid.vue';
import McpPrimarySetup from '@/components/mcp/McpPrimarySetup.vue';
import OnboardingStepCard from '@/components/onboarding/OnboardingStepCard.vue';
import { Button } from '@/components/ui/button';
import { useWorkspaceEcho } from '@/composables/echo/useWorkspaceEcho';
import AppLayout from '@/layouts/AppLayout.vue';
import { copyToClipboard } from '@/lib/utils';
import { calendar } from '@/routes/app';
import { complete } from '@/routes/app/onboarding';
import { skip as skipMcpRoute } from '@/routes/app/onboarding/mcp';
import { create as createPost } from '@/routes/app/posts';

interface OnboardingStatus {
    mcp_connected: boolean;
    social_connected: boolean;
    first_post_created: boolean;
    skipped_steps: string[];
    all_complete: boolean;
    show_residual: boolean;
    completed_at: string | null;
    dismissed_at: string | null;
}

const props = defineProps<{
    status: OnboardingStatus;
    canSkipSteps: boolean;
    canManageAccounts: boolean;
    canCreatePost: boolean;
    mcpUrl: string;
    samplePrompt: string;
    platforms: AvailablePlatform[];
    accounts: ConnectedAccount[];
}>();

const page = usePage();
const firstName = computed(() => {
    const name = (page.props.auth.user?.name ?? '').trim();

    return name === '' ? '' : (name.split(/\s+/)[0] ?? '');
});
const socialConnectedElsewhere = computed(
    () =>
        props.status.social_connected &&
        !props.accounts.some((account) => account.status === 'connected'),
);

const skipMcpForm = useForm({});
const completeForm = useForm({});

const onboardingReloadOnly = ['status', 'accounts', 'onboardingResidual'];

useWorkspaceEcho('.onboarding.status.updated', () => {
    router.reload({ only: onboardingReloadOnly });
});

watch(
    () => props.status.dismissed_at,
    (dismissedAt) => {
        if (dismissedAt) {
            router.visit(calendar.url());
        }
    },
);

const copySamplePrompt = (): void => {
    copyToClipboard(props.samplePrompt, trans('onboarding.first_post.copied'));
};

const isStepSkipped = (step: string): boolean =>
    props.status.skipped_steps.includes(step);

const skipMcp = (): void => {
    if (!skipMcpForm.processing) {
        skipMcpForm.submit(skipMcpRoute());
    }
};

const continueToTryPost = (): void => {
    if (props.status.all_complete && !completeForm.processing) {
        completeForm.submit(complete());
    }
};

// Bring the ready section into view when the last step completes via Echo.
const readySection = ref<HTMLElement | null>(null);

watch(
    () => props.status.all_complete,
    async (complete) => {
        if (!complete) {
            return;
        }

        await nextTick();
        const section = readySection.value;

        if (!section) {
            return;
        }

        section.focus({ preventScroll: true });
        section.scrollIntoView({
            behavior: window.matchMedia('(prefers-reduced-motion: reduce)')
                .matches
                ? 'auto'
                : 'smooth',
            block: 'center',
        });
    },
    { immediate: true },
);
</script>

<template>
    <Head :title="$t('onboarding.title')" />

    <AppLayout>
        <div
            class="mx-auto flex h-full w-full max-w-5xl flex-1 flex-col gap-6 px-4 py-6 sm:px-6 sm:py-10"
        >
            <div
                class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
            >
                <div class="min-w-0 space-y-1.5">
                    <h1
                        class="text-xl font-bold text-foreground sm:text-2xl"
                        data-testid="onboarding-welcome"
                    >
                        <template v-if="firstName">
                            {{ $t('onboarding.welcome', { name: firstName }) }}
                        </template>
                        <template v-else>
                            {{ $t('onboarding.welcome_anonymous') }}
                        </template>
                    </h1>
                    <p class="text-sm text-muted-foreground sm:text-base">
                        {{ $t('onboarding.description') }}
                    </p>
                </div>
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
                            v-if="canCreatePost"
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
                                @click="skipMcp"
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
                        :reload-only="onboardingReloadOnly"
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
                            {{ samplePrompt }}
                        </p>
                    </div>

                    <div
                        class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-center"
                    >
                        <Button
                            type="button"
                            data-testid="copy-sample-prompt"
                            @click="copySamplePrompt"
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

            <section
                v-if="status.all_complete"
                ref="readySection"
                tabindex="-1"
                class="flex flex-col items-center gap-4 rounded-2xl border-2 border-foreground bg-violet-100 p-8 text-center shadow-2xs"
                data-testid="onboarding-ready"
            >
                <span
                    class="inline-flex size-12 items-center justify-center rounded-full border-2 border-foreground bg-emerald-200 text-emerald-800 shadow-2xs"
                >
                    <IconCheck class="size-6" stroke-width="3" />
                </span>
                <div>
                    <h2 class="text-xl font-bold">
                        {{ $t('onboarding.ready.title') }}
                    </h2>
                    <p class="mt-1 text-sm text-foreground/70">
                        {{ $t('onboarding.ready.description') }}
                    </p>
                </div>

                <Button
                    type="button"
                    size="lg"
                    :disabled="completeForm.processing"
                    data-testid="onboarding-continue"
                    @click="continueToTryPost"
                >
                    {{ $t('onboarding.continue') }}
                </Button>
            </section>
        </div>
    </AppLayout>
</template>
