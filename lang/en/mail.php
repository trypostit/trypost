<?php

return [
    'mentioned' => [
        'subject' => ':name mentioned you on TryPost',
        'title' => ':name mentioned you',
        'intro' => ':name mentioned you in a post comment.',
        'cta' => 'View comment',
    ],

    'workspace_connections_disconnected' => [
        'subject' => '{1} :count account needs to be reconnected in :workspace|[2,*] :count accounts need to be reconnected in :workspace',
        'title' => 'Accounts Need Reconnection',
        'intro' => 'The following social accounts in your <strong>:workspace</strong> workspace have been disconnected and need to be reconnected:',
        'reasons_title' => 'This may have happened because:',
        'reason_expired' => 'Access tokens expired',
        'reason_revoked' => 'You revoked access to TryPost on the platform',
        'reason_changed' => 'The platform changed their authentication requirements',
        'reconnect_cta' => 'Please reconnect these accounts to continue scheduling and publishing posts.',
        'button' => 'Reconnect Accounts',
    ],

    'post_at_risk' => [
        'subject' => '{1} :count post is at risk in :workspace|[2,*] :count posts are at risk in :workspace',
        'title' => 'Posts May Fail to Publish',
        'intro' => 'The following social accounts in your <strong>:workspace</strong> workspace need to be reconnected before these scheduled posts can publish:',
        'reconnect_cta' => 'Please reconnect these accounts now to avoid missing your scheduled posts.',
        'button' => 'Reconnect Accounts',
    ],
];
