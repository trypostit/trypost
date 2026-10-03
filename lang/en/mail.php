<?php

declare(strict_types=1);

return [

    'layout' => [
        'tagline' => 'Open-source social media scheduling tool',
        'manage_notifications' => 'Manage notifications',
        'signoff' => 'Best regards,',
        'team' => 'The TryPost Team',
    ],

    'account_disconnected' => [
        'subject' => 'Your :platform account in :workspace needs to be reconnected',
        'title' => 'Your :platform account needs to be reconnected',
        'preview' => 'Please reconnect your :platform account in :workspace to continue scheduling posts.',
        'heading' => 'Account Disconnected',
        'intro' => 'Your <strong>:platform</strong> account <strong>:account</strong> has been disconnected from the <strong>:workspace</strong> workspace.',
        'reasons_title' => 'This may have happened because:',
        'reason_expired' => 'Your access token expired',
        'reason_revoked' => 'You revoked access to TryPost',
        'reason_error' => 'There was an authentication error',
        'reconnect_cta' => 'Please reconnect your account to continue scheduling and publishing posts.',
        'button' => 'Reconnect Account',
    ],

    'email_verification' => [
        'subject' => 'Verify your email address',
        'preview' => 'Please verify your email address.',
        'greeting' => 'Hi :name,',
        'body' => 'Please confirm your email address by clicking the button below:',
        'button' => 'Verify Email Address',
        'ignore' => 'If you did not create an account, you can safely ignore this email.',
    ],

    'password_reset' => [
        'subject' => 'Reset your password',
        'preview' => 'Reset your password.',
        'greeting' => 'Hi :name,',
        'body' => 'We received a request to reset your password. Click the button below to create a new password:',
        'button' => 'Reset Password',
        'expiry' => 'This link expires in 60 minutes. If you did not request a password reset, you can safely ignore this email.',
    ],

    'post_at_risk' => [
        'subject' => '{1} :count post is at risk in :workspace|[0,*] :count posts are at risk in :workspace',
        'title' => 'Posts May Fail to Publish',
        'heading' => 'Posts May Fail to Publish',
        'intro' => 'The following social accounts in your :workspace workspace need to be reconnected before these scheduled posts can publish:',
        'posts_label' => '{1} :count post scheduled: :times (:timezone)|[0,*] :count posts scheduled: :times (:timezone)',
        'reconnect_cta' => 'Please reconnect these accounts now to avoid missing your scheduled posts.',
        'button' => 'Reconnect Accounts',
    ],

    'post_note_added' => [
        'subject' => ':author added a note to a post',
        'title' => 'New note from :author',
        'heading' => 'New note on a post',
        'body' => ':author added a note to a post in the :workspace workspace.',
        'post_title' => 'Post',
        'post_without_text' => 'This post has no text yet.',
        'button' => 'View note',
    ],
    'post_approval_requested' => [
        'subject' => ':name asked you to approve a post',
        'title' => 'A post needs your approval',
        'preview' => ':name asked for approval in :workspace.',
        'heading' => 'A post needs your approval',
        'body' => ':name (:email) asked for approval in the :workspace workspace.',
        'channels' => 'Channels',
        'requested_time' => 'Requested time',
        'next_queue_slot' => 'Next queue slot',
        'as_soon_as_approved' => 'As soon as it\'s approved',
        'post_without_text' => 'This post has no text yet.',
        'button' => 'View posts awaiting approval',
    ],

    'post_approved' => [
        'subject' => ':name approved your post',
        'title' => 'Your post was approved',
        'preview' => ':name approved your post in :workspace.',
        'heading' => 'Your post was approved',
        'body' => ':name approved your post in the :workspace workspace.',
        'channels' => 'Channels',
        'goes_out' => 'It will go out',
        'channel_time' => ':channel: :time',
        'publishing_now' => 'Publishing now',
        'button' => 'View in queue',
    ],

    'post_rejected' => [
        'subject' => ':name did not approve your post',
        'title' => 'Your post was not approved',
        'preview' => ':name moved your post back to drafts.',
        'heading' => 'Your post was not approved',
        'body' => ':name moved your post in the :workspace workspace back to drafts.',
        'channels' => 'Channels',
        'button' => 'View in drafts',
    ],

    'post_publish_failed' => [
        'subject' => 'Your post failed to publish in :workspace',
        'title' => 'Your post failed to publish',
        'preview' => 'One or more platforms failed to publish your post.',
        'heading' => 'Your post failed to publish',
        'body' => 'Your scheduled post in the :workspace workspace failed to publish on one or more platforms.',
        'platforms_title' => 'Failed platforms:',
        'button' => 'View Post',
    ],

    'post_published' => [
        'subject' => 'Your post was published in :workspace',
        'title' => 'Your post was published',
        'preview' => 'Your post has been published successfully.',
        'heading' => 'Your post was published',
        'body' => 'Your post in the :workspace workspace has been published successfully.',
        'platforms_title' => 'Published on:',
        'view_post' => 'View post',
        'button' => 'View Post',
    ],

    'webhook_paused' => [
        'subject' => 'Webhook paused: :endpoint',
        'title' => 'Webhook paused after repeated failures',
        'preview' => 'We paused a webhook after 5 consecutive delivery failures.',
        'heading' => 'Webhook paused after repeated failures',
        'body' => 'We paused the webhook at :endpoint after 5 consecutive delivery failures. Review the endpoint and re-enable it from the webhook details page.',
        'button' => 'View webhook',
    ],

    'workspace_connections_disconnected' => [
        'subject' => '{1} :count account needs to be reconnected in :workspace|[0,*] :count accounts need to be reconnected in :workspace',
        'title' => 'Accounts need reconnection',
        'heading' => 'Accounts need reconnection',
        'intro' => 'The following social accounts in your <strong>:workspace</strong> workspace have been disconnected and need to be reconnected:',
        'reasons_title' => 'This may have happened because:',
        'reason_expired' => 'Access tokens expired',
        'reason_revoked' => 'You revoked access to TryPost on the platform',
        'reason_changed' => 'The platform changed their authentication requirements',
        'reconnect_cta' => 'Please reconnect these accounts to continue scheduling and publishing posts.',
        'button' => 'Reconnect accounts',
    ],

    'workspace_invite' => [
        'subject' => 'You\'ve been invited to join :account',
        'title' => 'You\'ve been invited to join :account',
        'preview' => 'You\'ve been invited to join :account',
        'heading' => 'You\'ve been invited!',
        'intro' => 'You\'ve been invited to collaborate on the <strong>:account</strong> workspace.',
        'role' => 'You\'ve been invited as <strong>:role</strong>.',
        'roles' => ['admin' => 'Admin', 'member' => 'Member', 'needs_approval' => 'Member (posts need approval)'],
        'button' => 'Accept Invite',
        'expiry' => 'This invite expires in 7 days.',
    ],

];
