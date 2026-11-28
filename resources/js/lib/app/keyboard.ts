export const isShiftEnter = (event: KeyboardEvent): boolean =>
    event.key === 'Enter' && event.shiftKey && !event.isComposing;
