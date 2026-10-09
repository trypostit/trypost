import { computed, type ComputedRef } from 'vue';

import { usePageErrors } from '@/composables/usePageErrors';

/**
 * The validation error of one meta field of a settings panel. A panel in a
 * batch (composer create, repurpose destinations) is reported under
 * `destinations.{platformIndex}.meta.*`; an edit has one post and reports
 * `meta.*`.
 */
export const useMetaErrors = (
    platformIndex: () => number,
): ((field: string) => ComputedRef<string | undefined>) => {
    const errors = usePageErrors();

    return (field) =>
        computed(
            () =>
                errors.value[`destinations.${platformIndex()}.meta.${field}`] ??
                errors.value[`meta.${field}`],
        );
};
