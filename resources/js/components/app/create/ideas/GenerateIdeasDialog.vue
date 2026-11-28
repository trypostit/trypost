<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { IconLoader2, IconRefresh, IconSparkles, IconX } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { extractErrorMessage } from '@/lib/app/httpError';
import { generate } from '@/routes/app/create/ideas';
import type { IdeaDraft } from '@/types/app/idea';

const emit = defineEmits<{
    use: [idea: IdeaDraft];
}>();

const open = ref(false);
const error = ref('');
const idea = ref<IdeaDraft | null>(null);
let requestId = 0;

const http = useHttp<
    { business: string; audience: string; notes: string },
    IdeaDraft
>({
    business: '',
    audience: '',
    notes: '',
});

watch(open, (isOpen) => {
    requestId++;
    http.cancel();

    if (!isOpen) {
        return;
    }

    error.value = '';
    idea.value = null;
    http.business = '';
    http.audience = '';
    http.notes = '';
    http.clearErrors();
});

const closeDialog = (): void => {
    open.value = false;
};

onBeforeUnmount(() => {
    http.cancel();
});

const canSubmit = computed(
    () =>
        !http.processing &&
        http.business.trim().length > 0 &&
        http.audience.trim().length > 0,
);

const submit = async (): Promise<void> => {
    if (!canSubmit.value) {
        return;
    }

    error.value = '';
    http.cancel();
    const currentRequest = ++requestId;

    try {
        const result = await http.post(generate.url());

        if (currentRequest !== requestId) {
            return;
        }

        if (!result) {
            error.value =
                Object.values(http.errors)[0] ??
                trans('create.ideas.errors.generate_failed');

            return;
        }

        idea.value = result;
    } catch (exception) {
        if (currentRequest !== requestId) {
            return;
        }

        error.value =
            extractErrorMessage(exception) ??
            trans('create.ideas.errors.generate_failed');
    }
};

const useIdea = (): void => {
    if (!idea.value) {
        return;
    }

    const chosen = idea.value;
    open.value = false;
    emit('use', chosen);
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button
                variant="ghost"
                class="bg-primary-subtle text-primary-text hover:bg-primary-subtle/80 max-sm:w-8 max-sm:px-0"
                :aria-label="$t('create.ideas.generate.title')"
                data-testid="ideas-generate"
            >
                <IconSparkles class="size-4" />
                <span class="max-sm:sr-only">{{
                    $t('create.ideas.generate.title')
                }}</span>
            </Button>
        </DialogTrigger>
        <DialogContent
            :show-close-button="false"
            class="top-0 left-0 flex h-dvh max-h-dvh w-screen max-w-none translate-x-0 translate-y-0 flex-col gap-0 overflow-hidden rounded-none p-0 sm:top-1/2 sm:left-1/2 sm:h-auto sm:max-h-[85dvh] sm:w-full sm:max-w-lg sm:translate-x-[-50%] sm:translate-y-[-50%] sm:gap-4 sm:overflow-y-auto sm:rounded-2xl sm:px-6 sm:pt-6 sm:pb-4"
            data-testid="ideas-generate-dialog"
        >
            <DialogHeader
                class="shrink-0 px-6 pt-6 pe-14 pb-4 text-left sm:p-0"
            >
                <DialogTitle>{{
                    $t('create.ideas.generate.title')
                }}</DialogTitle>
                <DialogDescription v-if="!idea">{{
                    $t('create.ideas.generate.intro')
                }}</DialogDescription>
            </DialogHeader>
            <DialogClose
                class="absolute top-4 end-4 z-20 flex size-8 items-center justify-center rounded-lg text-foreground transition-control hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring sm:hidden"
                :aria-label="$t('common.close')"
                data-testid="ideas-generate-close"
            >
                <IconX class="size-4" />
            </DialogClose>

            <div
                v-if="idea"
                class="flex min-h-0 flex-1 flex-col sm:flex-none sm:gap-4"
            >
                <div
                    class="min-h-0 flex-1 space-y-4 overflow-y-auto px-6 sm:flex-none sm:overflow-visible sm:px-0"
                >
                    <div
                        class="space-y-2 rounded-lg border bg-muted/40 p-4 sm:max-h-[50vh] sm:overflow-y-auto"
                        data-testid="ideas-generate-result"
                    >
                        <p
                            class="font-semibold break-words"
                            data-testid="ideas-generate-result-title"
                        >
                            {{ idea.title }}
                        </p>
                        <p
                            v-if="idea.body"
                            class="text-sm break-words whitespace-pre-line text-muted-foreground"
                            data-testid="ideas-generate-result-body"
                        >
                            {{ idea.body }}
                        </p>
                    </div>

                    <p
                        v-if="error"
                        role="alert"
                        class="text-sm text-destructive"
                        data-testid="ideas-generate-error"
                    >
                        {{ error }}
                    </p>

                    <p class="text-xs text-muted-foreground">
                        {{ $t('posts.composer.assistant_disclaimer') }}
                    </p>
                </div>

                <DialogFooter class="mx-2 mb-2 shrink-0 sm:-mx-4 sm:-mb-2">
                    <Button
                        type="button"
                        variant="outline"
                        data-testid="ideas-generate-cancel"
                        @click="closeDialog"
                    >
                        {{ $t('common.cancel') }}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="http.processing"
                        :aria-label="$t('create.ideas.generate.again')"
                        data-testid="ideas-generate-again"
                        @click="submit"
                    >
                        <IconLoader2
                            v-if="http.processing"
                            class="size-4 animate-spin"
                        />
                        <IconRefresh v-else class="size-4" />
                        <span class="max-sm:sr-only">{{
                            $t('create.ideas.generate.again')
                        }}</span>
                    </Button>
                    <Button
                        type="button"
                        :disabled="http.processing"
                        data-testid="ideas-generate-use"
                        @click="useIdea"
                    >
                        {{ $t('create.ideas.generate.use') }}
                    </Button>
                </DialogFooter>
            </div>

            <form
                v-else
                class="flex min-h-0 flex-1 flex-col sm:flex-none sm:gap-4"
                @submit.prevent="submit"
            >
                <div
                    class="min-h-0 flex-1 space-y-4 overflow-y-auto px-6 sm:flex-none sm:overflow-visible sm:px-0"
                >
                    <div class="space-y-1.5">
                        <label
                            for="ideas-generate-audience"
                            class="block text-sm font-medium"
                        >
                            {{ $t('create.ideas.generate.audience_label') }}
                        </label>
                        <Textarea
                            id="ideas-generate-audience"
                            v-model="http.audience"
                            class="min-h-16 bg-background"
                            :placeholder="
                                $t('create.ideas.generate.audience_placeholder')
                            "
                            :disabled="http.processing"
                            data-testid="ideas-generate-audience"
                        />
                    </div>

                    <div class="space-y-1.5">
                        <label
                            for="ideas-generate-business"
                            class="block text-sm font-medium"
                        >
                            {{ $t('create.ideas.generate.business_label') }}
                        </label>
                        <Textarea
                            id="ideas-generate-business"
                            v-model="http.business"
                            class="min-h-16 bg-background"
                            :placeholder="
                                $t('create.ideas.generate.business_placeholder')
                            "
                            :disabled="http.processing"
                            data-testid="ideas-generate-business"
                        />
                    </div>

                    <div class="space-y-1.5">
                        <label
                            for="ideas-generate-notes"
                            class="block text-sm font-medium"
                        >
                            {{ $t('create.ideas.generate.notes_label') }}
                        </label>
                        <Textarea
                            id="ideas-generate-notes"
                            v-model="http.notes"
                            class="min-h-16 bg-background"
                            :placeholder="
                                $t('create.ideas.generate.notes_placeholder')
                            "
                            :disabled="http.processing"
                            data-testid="ideas-generate-notes"
                        />
                    </div>

                    <p
                        v-if="error"
                        role="alert"
                        class="text-sm text-destructive"
                        data-testid="ideas-generate-error"
                    >
                        {{ error }}
                    </p>

                    <p class="text-xs text-muted-foreground">
                        {{ $t('posts.composer.assistant_disclaimer') }}
                    </p>
                </div>

                <DialogFooter class="mx-2 mb-2 shrink-0 sm:-mx-4 sm:-mb-2">
                    <Button
                        type="button"
                        variant="outline"
                        data-testid="ideas-generate-cancel"
                        @click="closeDialog"
                    >
                        {{ $t('common.cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        :disabled="!canSubmit"
                        data-testid="ideas-generate-submit"
                    >
                        <IconLoader2
                            v-if="http.processing"
                            class="size-4 animate-spin"
                        />
                        <IconSparkles v-else class="size-4" />
                        {{ $t('create.ideas.generate.submit') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
