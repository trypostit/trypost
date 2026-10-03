import { ref } from 'vue';

const isOpen = ref(false);

export const useCommandPalette = () => ({
    isOpen,
    open: (): void => {
        isOpen.value = true;
    },
    close: (): void => {
        isOpen.value = false;
    },
    toggle: (): void => {
        isOpen.value = !isOpen.value;
    },
});
