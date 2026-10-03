<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { IconInfoCircle } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import PopupLayout from '@/layouts/PopupLayout.vue';
import { authorize as authorizeMastodon } from '@/routes/app/social/mastodon';

const form = useForm({ instance: 'https://mastodon.social' });

const onSubmit = () => form.post(authorizeMastodon.url());
</script>

<template>
    <PopupLayout :title="$t('accounts.mastodon.title')">
        <div class="max-w-md mx-auto">
            <div class="flex items-center gap-3 mb-6">
                <img src="/images/accounts/mastodon.png" alt="Mastodon" class="h-10 w-10" />
                <div>
                    <h1 class="font-heading text-xl font-medium tracking-tight">{{ $t('accounts.mastodon.title') }}</h1>
                    <p class="text-sm text-muted-foreground">{{ $t('accounts.mastodon.description') }}</p>
                </div>
            </div>

            <form @submit.prevent="onSubmit" class="space-y-4">
                <div class="space-y-2">
                    <Label for="instance">{{ $t('accounts.mastodon.instance_url') }}</Label>
                    <Input
                        id="instance"
                        v-model="form.instance"
                        type="url"
                        :placeholder="trans('accounts.mastodon.instance_placeholder')"
                        :class="{ 'border-destructive': form.errors.instance }"
                    />
                    <p v-if="form.errors.instance" class="text-sm text-destructive">
                        {{ form.errors.instance }}
                    </p>
                </div>

                <Alert>
                    <IconInfoCircle class="h-4 w-4" />
                    <AlertDescription class="inline">{{ $t('accounts.mastodon.instance_hint') }}</AlertDescription>
                </Alert>

                <Button type="submit" :disabled="form.processing" class="w-full">
                    {{ form.processing ? $t('accounts.mastodon.submitting') : $t('accounts.mastodon.submit') }}
                </Button>
            </form>
        </div>
    </PopupLayout>
</template>
