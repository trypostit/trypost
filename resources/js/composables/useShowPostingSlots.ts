import type { Ref } from 'vue';

import { useUserStorage } from '@/composables/useBrowserStorage';

export const useShowPostingSlots = (): {
    showSlots: Ref<boolean>;
    setShowSlots: (value: boolean) => void;
} => {
    const showSlots = useUserStorage('publish.showSlots', true);

    const setShowSlots = (value: boolean): void => {
        showSlots.value = value;
    };

    return { showSlots, setShowSlots };
};
