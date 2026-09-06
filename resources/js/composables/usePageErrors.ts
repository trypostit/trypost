import { usePage } from '@inertiajs/vue3';
import { computed, type ComputedRef } from 'vue';

/**
 * Reactive access to Inertia's page-level validation errors, keyed by field
 * path (e.g. `scheduled_at`, `platforms.0.content_type`). Returns an empty
 * object when no errors are present so consumers can index without null
 * checks. Cast to `Record<string, string>` because the app only ever flashes
 * single string messages (no nested error bags).
 */
export const usePageErrors = (): ComputedRef<Record<string, string>> => {
    const page = usePage();
    return computed(() => (page.props.errors ?? {}) as Record<string, string>);
};
