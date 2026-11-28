<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { store as blockStore } from '@/routes/admin/users/block';

const props = defineProps<{
    userId: string;
    userName: string;
}>();

const open = defineModel<boolean>('open', { default: false });

const form = useForm({ reason: '' });

const closeDialog = (): void => {
    open.value = false;
};

const submit = (): void => {
    form.post(blockStore.url(props.userId), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            closeDialog();
        },
    });
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            data-testid="block-user-dialog"
            class="gap-0 p-0 sm:max-w-lg"
        >
            <form @submit.prevent="submit">
                <div class="flex flex-col gap-1.5 px-8 pt-7 pb-5">
                    <DialogTitle class="font-sans text-base font-emphasis">
                        Block {{ userName }}
                    </DialogTitle>
                    <DialogDescription class="text-sm text-muted-foreground">
                        They are signed out now, cannot sign in or use the API
                        and MCP, and the scheduled posts of the account they own
                        stay on hold until you unblock them.
                    </DialogDescription>
                </div>

                <div class="flex flex-col gap-2 px-8 pb-6">
                    <Label for="block-user-reason">Reason</Label>
                    <Textarea
                        id="block-user-reason"
                        v-model="form.reason"
                        placeholder="Why are you blocking this user?"
                        rows="3"
                        data-testid="block-user-reason"
                    />
                    <InputError :message="form.errors.reason" />
                </div>

                <DialogFooter class="mx-2 mb-2">
                    <Button
                        variant="ghost"
                        type="button"
                        data-testid="block-user-cancel"
                        @click="closeDialog"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        variant="destructive"
                        data-testid="block-user-submit"
                        :loading="form.processing"
                    >
                        Block user
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
