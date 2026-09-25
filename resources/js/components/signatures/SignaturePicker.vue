<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { IconPencil, IconPlus } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import SignatureForm from '@/components/signatures/SignatureForm.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    store as signaturesStore,
    update as signaturesUpdate,
} from '@/routes/app/signatures';

interface Signature {
    id: string;
    name: string;
    content: string;
}

const props = defineProps<{ signatures: Signature[] }>();
const emit = defineEmits<{
    select: [signature: Signature];
    saved: [signature: Signature];
}>();

const search = ref('');
const editing = ref<Signature | null>(null);
const view = ref<'list' | 'create' | 'edit'>('list');
const form = useHttp({ name: '', content: '' });
const saveError = ref(false);

const filtered = computed(() =>
    props.signatures.filter((signature) =>
        `${signature.name} ${signature.content}`
            .toLocaleLowerCase()
            .includes(search.value.trim().toLocaleLowerCase()),
    ),
);

const startCreate = (): void => {
    form.name = '';
    form.content = '';
    form.clearErrors();
    saveError.value = false;
    editing.value = null;
    view.value = 'create';
};

const startEdit = (signature: Signature): void => {
    form.name = signature.name;
    form.content = signature.content;
    form.clearErrors();
    saveError.value = false;
    editing.value = signature;
    view.value = 'edit';
};

const save = async (): Promise<void> => {
    saveError.value = false;
    try {
        const signature = (
            view.value === 'edit' && editing.value
                ? await form.put(signaturesUpdate.url(editing.value.id))
                : await form.post(signaturesStore.url())
        ) as Signature | undefined;
        if (signature) {
            emit('saved', signature);
            search.value = '';
            view.value = 'list';
        }
    } catch {
        saveError.value = true;
    }
};
</script>

<template>
    <div
        class="w-80 max-w-[calc(100vw-2rem)] p-3"
        data-testid="composer-signatures-popover"
    >
        <template v-if="view === 'list'">
            <div class="mb-3 flex items-center justify-between gap-2">
                <h3 class="text-sm font-semibold">
                    {{ $t('signatures.title') }}
                </h3>
                <Button
                    type="button"
                    size="icon-sm"
                    variant="outline"
                    :aria-label="$t('signatures.new')"
                    data-testid="composer-create-signature"
                    @click="startCreate"
                >
                    <IconPlus class="size-4" />
                </Button>
            </div>
            <Input
                v-model="search"
                :placeholder="$t('posts.edit.signatures_modal.search')"
                class="mb-2"
            />
            <div class="max-h-64 space-y-1 overflow-y-auto">
                <p
                    v-if="filtered.length === 0"
                    class="px-2 py-3 text-sm text-muted-foreground"
                >
                    {{ $t('posts.edit.signatures_modal.no_results') }}
                </p>
                <div
                    v-for="signature in filtered"
                    :key="signature.id"
                    class="group flex items-center rounded-md hover:bg-muted"
                >
                    <button
                        type="button"
                        class="min-w-0 flex-1 px-2 py-2 text-left"
                        @click="emit('select', signature)"
                    >
                        <span class="block truncate text-sm font-medium">{{
                            signature.name
                        }}</span>
                        <span
                            class="block truncate text-xs text-muted-foreground"
                            >{{ signature.content }}</span
                        >
                    </button>
                    <button
                        type="button"
                        class="mr-1 flex size-8 shrink-0 items-center justify-center rounded-md text-muted-foreground hover:bg-background hover:text-foreground"
                        :aria-label="$t('signatures.actions.edit')"
                        @click="startEdit(signature)"
                    >
                        <IconPencil class="size-4" />
                    </button>
                </div>
            </div>
        </template>
        <template v-else>
            <h3 class="mb-3 text-sm font-semibold">
                {{ $t(`signatures.${view}.title`) }}
            </h3>
            <SignatureForm
                v-model:name="form.name"
                v-model:content="form.content"
                :mode="view"
                id-prefix="composer-signature"
                compact
                :errors="form.errors"
                :processing="form.processing"
                @submit="save"
                @cancel="view = 'list'"
            />
            <p
                v-if="saveError"
                role="alert"
                class="mt-2 text-sm text-destructive"
            >
                {{ $t('signatures.save_failed') }}
            </p>
        </template>
    </div>
</template>
