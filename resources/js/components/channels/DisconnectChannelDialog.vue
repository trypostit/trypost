<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import { disconnect } from '@/routes/app/channels';
import { accountTypeKey, type ConnectedAccount } from '@/types/social-account';

const props = defineProps<{
    keyword: string;
}>();

const emit = defineEmits<{
    refresh: [channel: ConnectedAccount];
}>();

const channel = ref<ConnectedAccount | null>(null);
const isOpen = ref(false);
const processing = ref(false);
const confirmation = ref('');

const name = computed(() =>
    channel.value ? channel.value.display_name || channel.value.username : '',
);

const typeKey = computed(() =>
    channel.value ? accountTypeKey(channel.value) : null,
);

const isConfirmed = computed(() => confirmation.value.trim() === props.keyword);

const open = (target: ConnectedAccount): void => {
    channel.value = target;
    confirmation.value = '';
    processing.value = false;
    isOpen.value = true;
};

const close = (): void => {
    isOpen.value = false;
    confirmation.value = '';
};

const refresh = (): void => {
    if (!channel.value) {
        return;
    }

    const target = channel.value;

    close();
    emit('refresh', target);
};

const submit = (): void => {
    if (!channel.value || !isConfirmed.value || processing.value) {
        return;
    }

    processing.value = true;

    router.delete(disconnect.url(channel.value.id), {
        preserveScroll: true,
        onSuccess: close,
        onFinish: () => {
            processing.value = false;
        },
    });
};

defineExpose({ open, close });
</script>

<template>
    <Dialog :open="isOpen" @update:open="(value) => (value ? null : close())">
        <DialogContent
            v-if="channel"
            :show-close-button="false"
            class="gap-0 p-0 sm:max-w-[495px]"
            data-testid="confirm-delete-modal"
        >
            <form @submit.prevent="submit">
                <div class="flex flex-col gap-4 px-8 pt-7 pb-6">
                    <DialogTitle
                        class="font-sans text-base font-emphasis"
                        data-testid="disconnect-channel-title"
                    >
                        {{
                            $t('channels.disconnect_modal.title_named', {
                                name,
                            })
                        }}
                    </DialogTitle>

                    <div
                        class="flex items-center gap-3 rounded-xl border border-border px-4 py-3.5"
                        data-testid="disconnect-channel-card"
                    >
                        <ChannelAvatar
                            :platform="channel.platform"
                            :src="channel.avatar_url"
                            :name="name"
                            :size="32"
                            ring="background"
                            avatar-class="rounded-md"
                        />
                        <div class="min-w-0">
                            <p
                                class="truncate text-sm leading-5 font-emphasis text-foreground"
                            >
                                {{ name }}
                            </p>
                            <p
                                class="truncate text-sm leading-5 text-muted-foreground"
                            >
                                {{
                                    typeKey
                                        ? $t(typeKey)
                                        : getPlatformLabel(channel.platform)
                                }}
                            </p>
                        </div>
                    </div>

                    <DialogDescription
                        class="text-sm leading-relaxed text-foreground"
                        data-testid="confirm-delete-description"
                    >
                        {{ $t('channels.disconnect_modal.description') }}
                        <strong class="font-emphasis">{{
                            $t('channels.disconnect_modal.irreversible')
                        }}</strong>
                    </DialogDescription>

                    <p class="text-sm leading-relaxed text-foreground">
                        {{ $t('channels.disconnect_modal.refresh_before') }}
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            class="mx-0.5 align-baseline font-emphasis"
                            data-testid="disconnect-channel-refresh"
                            @click="refresh"
                        >
                            {{ $t('channels.refresh_connection') }}
                        </Button>
                        {{ $t('channels.disconnect_modal.refresh_after') }}
                    </p>

                    <label class="mt-2 flex flex-col gap-2">
                        <span class="text-sm font-emphasis text-foreground">{{
                            $t('channels.disconnect_modal.type_to_confirm', {
                                keyword,
                            })
                        }}</span>
                        <Input
                            v-model="confirmation"
                            :placeholder="keyword"
                            autocomplete="off"
                            autofocus
                            data-testid="confirm-delete-input"
                        />
                    </label>
                </div>

                <div
                    class="flex items-center justify-end gap-2 border-t border-border px-8 py-4"
                >
                    <Button
                        type="button"
                        variant="ghost"
                        data-testid="confirm-delete-cancel"
                        @click="close"
                    >
                        {{ $t('channels.disconnect_modal.cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="processing || !isConfirmed"
                        data-testid="confirm-delete-action"
                    >
                        {{ $t('channels.disconnect_modal.title') }}
                    </Button>
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
