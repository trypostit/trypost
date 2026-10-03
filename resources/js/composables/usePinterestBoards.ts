import { reactive } from 'vue';

import type { PinterestBoard, PinterestBoardsPayload } from '@/types';

const fetchedBoards = reactive<Record<string, PinterestBoardsPayload>>({});

export const usePinterestBoards = () => {
    const boardsFor = (
        accountId: string,
        initial: PinterestBoardsPayload,
    ): PinterestBoardsPayload => fetchedBoards[accountId] ?? initial;

    const replaceBoards = (
        accountId: string,
        payload: PinterestBoardsPayload,
    ): void => {
        fetchedBoards[accountId] = payload;
    };

    const addBoard = (
        accountId: string,
        initial: PinterestBoardsPayload,
        board: PinterestBoard,
    ): void => {
        const current = boardsFor(accountId, initial);

        fetchedBoards[accountId] = {
            ...current,
            boards: [
                ...current.boards.filter((item) => item.id !== board.id),
                board,
            ],
        };
    };

    return { boardsFor, replaceBoards, addBoard };
};
