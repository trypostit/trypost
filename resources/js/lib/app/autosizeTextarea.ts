/** Grows a textarea to fit its text, so stacked thread posts sit right under each other. */
export const autosizeTextarea = (textarea: HTMLTextAreaElement | null | undefined): void => {
    if (!textarea) {
        return;
    }

    textarea.style.height = 'auto';
    textarea.style.height = `${textarea.scrollHeight}px`;
};
