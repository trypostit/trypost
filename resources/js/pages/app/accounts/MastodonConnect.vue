<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ConnectLayout from '@/layouts/ConnectLayout.vue';
import { authorize as authorizeMastodon } from '@/routes/app/social/mastodon';

defineProps<{ backUrl: string }>();

const form = useForm({ instance: 'https://mastodon.social' });

const onSubmit = (): void => {
    form.post(authorizeMastodon.url());
};
</script>

<template>
    <ConnectLayout
        :title="$t('accounts.mastodon.title')"
        platform="mastodon"
        :close-url="backUrl"
    >
        <div class="flex flex-col gap-6">
            <div class="flex flex-col gap-2">
                <h1
                    class="text-xl leading-tight font-medium text-foreground"
                    data-testid="connect-title"
                >
                    {{ $t('accounts.mastodon.title') }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{ $t('accounts.mastodon.description') }}
                </p>
            </div>

            <form class="flex flex-col gap-4" @submit.prevent="onSubmit">
                <div class="grid gap-2">
                    <Label for="instance">{{ $t('accounts.mastodon.instance_url') }}</Label>
                    <Input
                        id="instance"
                        v-model="form.instance"
                        type="url"
                        autofocus
                        :placeholder="$t('accounts.mastodon.instance_placeholder')"
                        :aria-invalid="Boolean(form.errors.instance)"
                        data-testid="mastodon-instance"
                    />
                    <InputError :message="form.errors.instance" />
                    <p class="text-sm text-muted-foreground" data-testid="mastodon-instance-hint">
                        {{ $t('accounts.mastodon.instance_hint') }}
                    </p>
                </div>

                <Button
                    type="submit"
                    class="mt-2 w-full"
                    :disabled="form.processing"
                    data-testid="mastodon-submit"
                >
                    {{
                        form.processing
                            ? $t('accounts.mastodon.submitting')
                            : $t('accounts.mastodon.submit')
                    }}
                </Button>
            </form>
        </div>
    </ConnectLayout>
</template>
