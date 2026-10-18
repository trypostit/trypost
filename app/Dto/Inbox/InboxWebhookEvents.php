<?php

declare(strict_types=1);

namespace App\Dto\Inbox;

final readonly class InboxWebhookEvents
{
    /**
     * @param  list<IncomingMessage>  $messages
     * @param  list<string>  $removedIds
     * @param  list<InboxReaction>  $reactions
     * @param  list<string>  $hiddenIds  comments the channel hid on the network
     * @param  list<string>  $shownIds  comments the channel showed again
     */
    public function __construct(
        public array $messages = [],
        public array $removedIds = [],
        public array $reactions = [],
        public array $hiddenIds = [],
        public array $shownIds = [],
    ) {}
}
