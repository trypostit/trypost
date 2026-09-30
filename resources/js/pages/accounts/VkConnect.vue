<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { IconInfoCircle } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { getInitials } from '@/composables/useInitials';
import PopupLayout from '@/layouts/PopupLayout.vue';
import { store as storeVk } from '@/routes/app/social/vk';

interface VkTarget {
    owner_id: number;
    name: string;
    screen_name: string | null;
    photo: string | null;
    is_group: boolean;
}

const props = defineProps<{
    targets?: VkTarget[];
    communityToken?: boolean;
}>();

const form = useForm<{ access_token: string; owner_id: number | null; community: string }>({
    access_token: '',
    owner_id: null,
    community: '',
});

const hasTargets = computed(() => (props.targets ?? []).length > 0);

const onSubmit = () => form.post(storeVk.url());

const pickTarget = (target: VkTarget) => {
    form.owner_id = target.owner_id;
    form.post(storeVk.url());
};
</script>

<template>
    <PopupLayout :title="$t('accounts.vk.title')">
        <div class="max-w-md mx-auto">
            <div class="flex items-center gap-3 mb-6">
                <img src="/images/accounts/vk.png" alt="VK" class="h-10 w-10" />
                <div>
                    <h1 class="text-xl font-bold tracking-tight">{{ $t('accounts.vk.title') }}</h1>
                    <p class="text-sm text-muted-foreground">{{ $t('accounts.vk.description') }}</p>
                </div>
            </div>

            <!-- Step 2: pick the wall to publish to -->
            <div v-if="hasTargets" class="space-y-3">
                <Label>{{ $t('accounts.vk.pick_target') }}</Label>
                <button
                    v-for="target in props.targets"
                    :key="target.owner_id"
                    type="button"
                    :disabled="form.processing"
                    class="flex w-full items-center gap-3 rounded-lg border p-3 text-left transition-colors hover:bg-accent disabled:opacity-50"
                    @click="pickTarget(target)"
                >
                    <img
                        v-if="target.photo"
                        :src="target.photo"
                        :alt="target.name"
                        class="h-10 w-10 rounded-full object-cover"
                    />
                    <div
                        v-else
                        class="flex h-10 w-10 items-center justify-center rounded-full bg-[#0077FF] font-semibold text-white"
                    >
                        {{ getInitials(target.name) }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="truncate font-medium">{{ target.name }}</div>
                        <div class="text-sm text-muted-foreground">
                            {{ target.is_group ? $t('accounts.vk.target_group') : $t('accounts.vk.target_profile') }}
                        </div>
                    </div>
                </button>
                <p v-if="form.errors.owner_id" class="text-sm text-destructive">
                    {{ form.errors.owner_id }}
                </p>
            </div>

            <!-- Step 2 (community token): the community the key belongs to -->
            <form v-else-if="props.communityToken" @submit.prevent="onSubmit" class="space-y-4">
                <div class="space-y-2">
                    <Label for="community">{{ $t('accounts.vk.community') }}</Label>
                    <Input id="community" v-model="form.community"
                        :placeholder="trans('accounts.vk.community_placeholder')" :class="{ 'border-destructive': form.errors.community }"
                    />
                    <p v-if="form.errors.community" class="text-sm text-destructive">
                        {{ form.errors.community }}
                    </p>
                    <p v-if="form.errors.access_token" class="text-sm text-destructive">
                        {{ form.errors.access_token }}
                    </p>
                </div>

                <Alert>
                    <IconInfoCircle class="h-4 w-4" />
                    <AlertDescription class="inline">
                        {{ $t('accounts.vk.community_hint') }}
                    </AlertDescription>
                </Alert>

                <Button type="submit" :disabled="form.processing" class="w-full">
                    {{ form.processing ? $t('accounts.vk.submitting') : $t('accounts.vk.submit') }}
                </Button>
            </form>

            <!-- Step 1: access token -->
            <form v-else @submit.prevent="onSubmit" class="space-y-4">
                <div class="space-y-2">
                    <Label for="access_token">{{ $t('accounts.vk.access_token') }}</Label>
                    <Input id="access_token" v-model="form.access_token" type="password"
                        :placeholder="trans('accounts.vk.access_token_placeholder')" :class="{ 'border-destructive': form.errors.access_token }"
                    />
                    <p v-if="form.errors.access_token" class="text-sm text-destructive">
                        {{ form.errors.access_token }}
                    </p>
                </div>

                <Alert>
                    <IconInfoCircle class="h-4 w-4" />
                    <AlertDescription class="inline">
                        <span v-html="$t('accounts.vk.access_token_hint')" />
                    </AlertDescription>
                </Alert>

                <Button type="submit" :disabled="form.processing" class="w-full">
                    {{ form.processing ? $t('accounts.vk.submitting') : $t('accounts.vk.submit') }}
                </Button>
            </form>
        </div>
    </PopupLayout>
</template>
