<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { IconBookmarks, IconPencil, IconSparkles } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

import { index as templatesIndex } from '@/actions/App/Http/Controllers/App/PostTemplateController';
import PageHeader from '@/components/PageHeader.vue';
import AiPostWizard from '@/components/posts/create/AiPostWizard.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { store as storePost } from '@/routes/app/posts';

interface SocialAccount {
    id: string;
    platform: string;
    display_name: string;
    username: string;
    display_label: string;
    avatar_url: string | null;
}

interface AiTemplate {
    key: string;
    name: string;
    description: string;
    preview: string;
    needs_account: boolean;
    supported_formats: string[];
}

interface Props {
    /** ISO date (YYYY-MM-DD). When set, the manual "start from scratch" path
     *  pre-schedules the new post on this date. */
    date?: string | null;
    socialAccounts: SocialAccount[];
    templates: AiTemplate[];
}

const props = withDefaults(defineProps<Props>(), {
    date: null,
});

type View = 'choice' | 'ai';

const view = ref<View>('choice');
const submitting = ref(false);

const aiHeader = ref<{ title: string; description: string } | null>(null);

const hasConnectedAccounts = computed(() => props.socialAccounts.length > 0);

const startFromScratch = () => {
    if (submitting.value) return;
    submitting.value = true;
    const url = props.date ? storePost.url({ query: { date: props.date } }) : storePost.url();
    router.post(url, {}, {
        onFinish: () => {
            submitting.value = false;
        },
    });
};

const pageTitle = computed(() => trans('posts.create.title'));

const stepHeader = computed(() => {
    if (view.value === 'ai' && aiHeader.value) return aiHeader.value;
    return {
        title: trans('posts.create.title'),
        description: trans('posts.create.description'),
    };
});
</script>

<template>
    <Head :title="pageTitle" />

    <AppLayout>
        <div class="flex h-full flex-1 flex-col p-4">
            <div class="mx-auto flex w-full max-w-2xl flex-col gap-6">
                <PageHeader :title="stepHeader.title" :description="stepHeader.description" />

                <!-- Choice screen -->
                <template v-if="view === 'choice'">
                    <div class="grid gap-4 sm:grid-cols-3">
                        <button
                            type="button"
                            class="group flex flex-col items-start gap-4 rounded-2xl border-2 border-foreground bg-card p-5 text-left shadow-2xs transition-all hover:-translate-y-0.5 hover:shadow-md disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:translate-y-0 disabled:hover:shadow-2xs"
                            :disabled="submitting"
                            @click="startFromScratch"
                        >
                            <div class="inline-flex size-12 -rotate-2 items-center justify-center rounded-2xl border-2 border-foreground bg-violet-200 shadow-2xs transition-transform group-hover:rotate-0">
                                <IconPencil class="size-6 text-foreground" stroke-width="2" />
                            </div>
                            <div class="space-y-1">
                                <p class="text-base font-bold text-foreground">{{ $t('posts.create.scratch_title') }}</p>
                                <p class="text-xs leading-relaxed text-foreground/70">
                                    {{ $t('posts.create.scratch_description') }}
                                </p>
                            </div>
                        </button>

                        <button
                            type="button"
                            class="group flex flex-col items-start gap-4 rounded-2xl border-2 border-foreground bg-card p-5 text-left shadow-2xs transition-all hover:-translate-y-0.5 hover:shadow-md disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:translate-y-0 disabled:hover:shadow-2xs"
                            :disabled="!hasConnectedAccounts"
                            @click="view = 'ai'"
                        >
                            <div class="inline-flex size-12 rotate-1 items-center justify-center rounded-2xl border-2 border-foreground bg-amber-200 shadow-2xs transition-transform group-hover:rotate-0">
                                <IconSparkles class="size-6 text-foreground" stroke-width="2" />
                            </div>
                            <div class="space-y-1">
                                <p class="text-base font-bold text-foreground">{{ $t('posts.create.ai_title') }}</p>
                                <p class="text-xs leading-relaxed text-foreground/70">
                                    <template v-if="!hasConnectedAccounts">
                                        {{ $t('posts.create.steps.connect_first') }}
                                    </template>
                                    <template v-else>
                                        {{ $t('posts.create.ai_description') }}
                                    </template>
                                </p>
                            </div>
                        </button>

                        <Link
                            :href="templatesIndex.url({ query: { date: props.date } })"
                            class="group flex flex-col items-start gap-4 rounded-2xl border-2 border-foreground bg-card p-5 text-left shadow-2xs transition-all hover:-translate-y-0.5 hover:shadow-md"
                        >
                            <div class="inline-flex size-12 -rotate-1 items-center justify-center rounded-2xl border-2 border-foreground bg-emerald-200 shadow-2xs transition-transform group-hover:rotate-0">
                                <IconBookmarks class="size-6 text-foreground" stroke-width="2" />
                            </div>
                            <div class="space-y-1">
                                <p class="text-base font-bold text-foreground">{{ $t('posts.create.template_title') }}</p>
                                <p class="text-xs leading-relaxed text-foreground/70">
                                    {{ $t('posts.create.template_description') }}
                                </p>
                            </div>
                        </Link>
                    </div>
                </template>

                <!-- AI flow -->
                <AiPostWizard
                    v-else-if="view === 'ai'"
                    :social-accounts="socialAccounts"
                    :templates="templates"
                    :date="props.date"
                    @update:step-header="aiHeader = $event"
                    @cancel="view = 'choice'; aiHeader = null"
                />
            </div>
        </div>
    </AppLayout>
</template>
