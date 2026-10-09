<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ConnectLayout from '@/layouts/ConnectLayout.vue';
import { store as storeVk } from '@/routes/app/social/vk';

const props = withDefaults(
    defineProps<{
        backUrl: string;
        communityToken?: boolean;
    }>(),
    { communityToken: false },
);

const form = useForm<{ access_token: string; community: string }>({
    access_token: '',
    community: '',
});

const onSubmit = (): void => {
    form.post(storeVk.url());
};
</script>

<template>
    <ConnectLayout
        :title="$t('accounts.vk.title')"
        platform="vk"
        :close-url="backUrl"
    >
        <div class="flex flex-col gap-6">
            <div class="flex flex-col gap-2">
                <h1
                    class="text-xl leading-tight font-medium text-foreground"
                    data-testid="connect-title"
                >
                    {{ $t('accounts.vk.title') }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{ $t('accounts.vk.description') }}
                </p>
            </div>

            <form class="flex flex-col gap-4" @submit.prevent="onSubmit">
                <div class="grid gap-2">
                    <Label for="access_token">{{
                        $t('accounts.vk.access_token')
                    }}</Label>
                    <Input
                        id="access_token"
                        v-model="form.access_token"
                        type="password"
                        autocomplete="off"
                        autofocus
                        :placeholder="$t('accounts.vk.access_token_placeholder')"
                        :aria-invalid="Boolean(form.errors.access_token)"
                        data-testid="vk-access-token"
                    />
                    <!-- eslint-disable-next-line vue/no-v-html — the hint carries bold/em markup from the lang file -->
                    <p
                        class="text-xs text-muted-foreground"
                        v-html="$t('accounts.vk.access_token_hint')"
                    />
                    <InputError :message="form.errors.access_token" />
                </div>

                <div v-if="props.communityToken" class="grid gap-2">
                    <Label for="community">{{
                        $t('accounts.vk.community')
                    }}</Label>
                    <Input
                        id="community"
                        v-model="form.community"
                        type="text"
                        autocomplete="off"
                        :placeholder="$t('accounts.vk.community_placeholder')"
                        :aria-invalid="Boolean(form.errors.community)"
                        data-testid="vk-community"
                    />
                    <p class="text-xs text-muted-foreground">
                        {{ $t('accounts.vk.community_hint') }}
                    </p>
                    <InputError :message="form.errors.community" />
                </div>

                <Button
                    type="submit"
                    :disabled="form.processing"
                    data-testid="vk-submit"
                >
                    {{
                        form.processing
                            ? $t('accounts.vk.submitting')
                            : $t('accounts.vk.submit')
                    }}
                </Button>
            </form>
        </div>
    </ConnectLayout>
</template>
