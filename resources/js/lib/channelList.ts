/**
 * The columns of the channels settings list (channel, time zone, posting
 * times, this week, actions), shared by its header and its rows so they line
 * up. The actions column has a fixed width: a row that offers Reconnect must
 * not shift the columns of the others.
 */
export const CHANNEL_LIST_COLUMNS =
    'md:grid-cols-[minmax(0,2.2fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1.6fr)_8.75rem]';
