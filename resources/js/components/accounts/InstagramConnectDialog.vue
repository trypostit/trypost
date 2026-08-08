<script setup lang="ts">
import { IconBrandFacebook, IconBrandInstagram } from '@tabler/icons-vue';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Platform } from '@/types/platform';

const open = defineModel<boolean>('open', { required: true });

const props = withDefaults(
    defineProps<{
        methods?: string[];
    }>(),
    {
        methods: () => [Platform.Instagram, Platform.InstagramFacebook],
    },
);

const emit = defineEmits<{
    select: [method: string];
}>();

const choose = (method: string) => {
    open.value = false;
    emit('select', method);
};

const showsStandalone = () => props.methods.includes(Platform.Instagram);
const showsFacebook = () => props.methods.includes(Platform.InstagramFacebook);
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md" dusk="instagram-connect-dialog">
            <DialogHeader>
                <div class="flex items-start gap-3">
                    <img
                        src="/images/accounts/instagram.png"
                        alt="Instagram"
                        class="size-10 rounded-lg"
                    />
                    <div class="text-left">
                        <DialogTitle>{{
                            $t('accounts.instagram_connect.title')
                        }}</DialogTitle>
                        <DialogDescription>{{
                            $t('accounts.instagram_connect.description')
                        }}</DialogDescription>
                    </div>
                </div>
            </DialogHeader>

            <div class="grid gap-3 py-2">
                <Button
                    v-if="showsStandalone()"
                    variant="outline"
                    class="h-auto justify-start gap-3 px-4 py-3 text-left whitespace-normal"
                    dusk="instagram-connect-standalone"
                    @click="choose(Platform.Instagram)"
                >
                    <span
                        class="inline-flex size-9 shrink-0 items-center justify-center rounded-lg bg-pink-100 text-pink-600 dark:bg-pink-900 dark:text-pink-300"
                    >
                        <IconBrandInstagram class="size-5" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-semibold text-foreground">{{
                            $t('accounts.instagram_connect.standalone_title')
                        }}</span>
                        <span class="mt-0.5 block text-xs font-normal text-muted-foreground">{{
                            $t('accounts.instagram_connect.standalone_description')
                        }}</span>
                    </span>
                </Button>

                <Button
                    v-if="showsFacebook()"
                    variant="outline"
                    class="h-auto justify-start gap-3 px-4 py-3 text-left whitespace-normal"
                    dusk="instagram-connect-facebook"
                    @click="choose(Platform.InstagramFacebook)"
                >
                    <span
                        class="inline-flex size-9 shrink-0 items-center justify-center rounded-lg bg-sky-100 text-sky-600 dark:bg-sky-900 dark:text-sky-300"
                    >
                        <IconBrandFacebook class="size-5" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-semibold text-foreground">{{
                            $t('accounts.instagram_connect.facebook_title')
                        }}</span>
                        <span class="mt-0.5 block text-xs font-normal text-muted-foreground">{{
                            $t('accounts.instagram_connect.facebook_description')
                        }}</span>
                    </span>
                </Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
