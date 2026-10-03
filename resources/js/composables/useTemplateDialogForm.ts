import type { FormDataType } from '@inertiajs/core';
import { useForm, useHttp } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';

import type { PostTemplate } from '@/types/template';

export type TemplateDialogMode = 'page' | 'local';

/**
 * Shared state of the template editor and duplicate dialogs: `page` mode
 * submits an Inertia visit through `form`, `local` mode (the composer panel)
 * sends the same data as JSON and resolves with the saved template, or null
 * when validation failed or the request errored.
 */
export const useTemplateDialogForm = <TData extends FormDataType<TData>>(options: {
    mode: TemplateDialogMode;
    initial: TData;
    onClose: () => void;
}) => {
    const form = useForm<TData>({ ...options.initial });
    const http = useHttp<TData, PostTemplate>({ ...options.initial });

    const open = ref(true);

    const processing = computed(() =>
        options.mode === 'page' ? form.processing : http.processing,
    );

    const errors = computed(() =>
        Object.values(
            (options.mode === 'page' ? form.errors : http.errors) as Record<
                string,
                string
            >,
        ),
    );

    const close = (): void => {
        open.value = false;
        options.onClose();
    };

    const onOpenChange = (value: boolean): void => {
        if (!value) {
            close();
        }
    };

    const sendLocal = async (
        method: 'post' | 'put',
        url: string,
    ): Promise<PostTemplate | null> => {
        Object.assign(http, form.data());

        try {
            return (await http[method](url)) ?? null;
        } catch {
            toast.error(trans('create.templates.errors.request_failed'));

            return null;
        }
    };

    return { form, open, processing, errors, close, onOpenChange, sendLocal };
};
