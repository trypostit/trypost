import { ref, type Ref } from 'vue';

const STORAGE_KEY = 'publish.showSlots';

const readShowSlots = (): boolean => {
    try {
        return window.localStorage.getItem(STORAGE_KEY) !== 'false';
    } catch {
        return true;
    }
};

export const useShowPostingSlots = (): {
    showSlots: Ref<boolean>;
    setShowSlots: (value: boolean) => void;
} => {
    const showSlots = ref(readShowSlots());

    const setShowSlots = (value: boolean): void => {
        showSlots.value = value;

        try {
            window.localStorage.setItem(STORAGE_KEY, String(value));
        } catch {
            return;
        }
    };

    return { showSlots, setShowSlots };
};
