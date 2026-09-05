<?php

declare(strict_types=1);

return [
    'title' => 'Repurpose',
    'description' => 'Replicate videos you post outside TryPost to your other networks, automatically.',
    'new' => 'New repurpose',

    'flow' => [
        'no_destinations' => 'No destination yet',
    ],

    'formats' => [
        'reel' => 'Reels',
        'video' => 'Videos',
        'story' => 'Stories',
    ],

    'source' => [
        'title' => 'Source',
        'description' => 'TryPost watches this account for new videos of the format below.',
        'watch_label' => 'Watch for',
    ],

    'summary' => [
        'sentence' => 'Every new :format you post on :source is republished to :destinations.',
    ],

    'empty' => [
        'title' => 'No repurpose set up yet',
        'description' => 'Pick a starting point below. TryPost watches the account you choose and republishes every new video to the networks you pick.',
    ],

    'table' => [
        'flow' => 'Flow',
        'source' => 'Source',
        'destinations' => 'Destinations',
        'status' => 'Status',
        'published' => 'Replicated',
        'last_polled' => 'Last checked',
    ],

    'status' => [
        'draft' => 'Draft',
        'active' => 'Active',
        'paused' => 'Paused',
        'disabled' => 'Disabled',
    ],

    'templates' => [
        'use' => 'Use this template',
        'instagram_everywhere' => [
            'title' => 'Instagram everywhere',
            'description' => 'Post a Reel on Instagram and TryPost republishes it to TikTok, YouTube Shorts and Facebook.',
        ],
        'facebook_everywhere' => [
            'title' => 'Facebook everywhere',
            'description' => 'Post a video on your Facebook Page and TryPost republishes it to Instagram, TikTok and YouTube Shorts.',
        ],
    ],

    'create' => [
        'title' => 'New repurpose',
        'description' => 'Choose the account TryPost should watch. You pick the destinations on the next screen.',
        'source_label' => 'Source account',
        'source_placeholder' => 'Select an account',
        'no_accounts' => 'Connect an Instagram or Facebook account first. Only these can be a source, because they are the only networks that let us download the video.',
        'submit' => 'Create',
        'connect' => 'Connect an account',
    ],

    'show' => [
        'title' => 'Repurpose',
        'description' => 'Videos published on this account outside TryPost are replicated to the destinations below.',
    ],

    'tabs' => [
        'configuration' => 'Configuration',
        'activity' => 'Activity',
    ],

    'destinations' => [
        'title' => 'Destinations',
        'description' => 'Every new video from the source is published to each account you select here.',
        'hint' => 'Captions are adapted per network only when they exceed that network\'s limit.',
        'none_available' => 'No other account is connected in this workspace yet.',
        'save' => 'Save destinations',
        'publish_as' => 'Publish as',
    ],

    'status_card' => [
        'title' => 'Status',
        'activate' => 'Activate',
        'pause' => 'Pause',
        'resume' => 'Resume',
        'disable' => 'Disable',
        'watermark' => 'Watching since',
        'last_polled' => 'Last checked',
        'draft_hint' => 'Pick at least one destination, then activate. Only videos posted after you activate are replicated.',
        'active_hint' => 'TryPost checks this account regularly and replicates every new video.',
        'paused_hint' => 'Checks are on hold. Resuming picks up where it stopped, so nothing posted meanwhile is lost.',
        'disabled_hint' => 'Turned off. Activating again starts fresh: whatever you posted while it was off stays off.',
    ],

    'items' => [
        'source' => 'Original',
        'published_at' => 'Posted',
        'status' => 'Status',
        'detail' => 'Detail',
        'posts' => 'Replicated to',
        'view_original' => 'View original',
        'open_post' => 'Open post',
        'statuses' => [
            'pending' => 'Queued',
            'processing' => 'Processing',
            'published' => 'Replicated',
            'skipped' => 'Skipped',
            'failed' => 'Failed',
        ],
        'reasons' => [
            'published_via_trypost' => 'Already published through TryPost',
            'not_video' => 'Not a video',
            'media_url_missing' => 'The network did not share a downloadable file, usually because of copyrighted audio',
            'download_failed' => 'The video could not be downloaded',
            'post_creation_failed' => 'No destination was available',
        ],
    ],

    'danger' => [
        'title' => 'Delete this repurpose',
        'description' => 'Checks stop immediately. Posts already created stay in your calendar.',
        'delete' => 'Delete repurpose',
    ],

    'errors' => [
        'source_already_used' => 'This account already feeds another repurpose. Edit that one instead.',
        'destinations_required' => 'Pick at least one destination before activating.',
        'destination_needs_video' => 'That format cannot carry a video.',
    ],
];
