export const openSupportChat = (): void => {
    const crisp = window.$crisp;

    if (!crisp) {
        return;
    }

    crisp.push(['do', 'chat:show']);
    crisp.push(['do', 'chat:open']);
};
