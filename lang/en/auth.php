<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used during authentication for various
    | messages that we need to display to the user. You are free to modify
    | these language lines according to your application's requirements.
    |
    */

    'failed' => 'These credentials do not match our records.',
    'password' => 'The provided password is incorrect.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',

    'flash' => [
        'welcome' => 'Welcome to TryPost!',
        'welcome_trial' => 'Welcome to TryPost! Your trial has started.',
    ],

    'legal' => 'By continuing, you agree to our <a href=":terms_url" target="_blank">Terms of Service</a> and <a href=":privacy_url" target="_blank">Privacy Policy</a>.',

    'reviews' => [
        'eyebrow' => '5/5 on G2',
        'heading' => 'Loved by people who publish every day',
        'paulo_dantas' => [
            'role' => 'Founder, chatadv.com.br',
            'quote' => 'The simplicity of creating, organizing and distributing content across every social network. With MCP, we can use the AI we prefer, like Claude or ChatGPT, to create content and schedule it from there.',
        ],
        'diego' => [
            'role' => 'CEO, Globalfy.com',
            'quote' => 'I like how easy TryPost is to use. I can create a post directly in Claude, then use the MCP to publish it and schedule it for the future. I set it up in five minutes.',
        ],
        'luiz' => [
            'role' => 'Content Creator',
            'quote' => 'I really love how easy it is to connect my AI tools and agents and schedule my posts across 9 social media platforms in just a few minutes.',
        ],
        'pedro' => [
            'role' => 'Founder, templated.io',
            'quote' => 'Really easy to use and integrate. With the MCP I only need the interface to connect the social media accounts.',
        ],
        'paulo_castellano' => [
            'role' => 'Founder, changelogfy.com',
            'quote' => 'I really love the MCP integration because it lets me organize all my social media accounts from Claude or ChatGPT.',
        ],
    ],

    'or_continue_with' => 'Or continue with',
    'or_continue_with_email' => 'Or continue with email',
    'google_login' => 'Log in with Google',
    'google_signup' => 'Sign up with Google',
    'github_login' => 'Log in with GitHub',
    'github_signup' => 'Sign up with GitHub',
    'github_email_unavailable' => 'Unable to retrieve your email from GitHub. Make your GitHub email public or grant the email scope, then try again.',

    'login' => [
        'title' => 'Log in to your account',
        'description' => 'Enter your email and password below to log in',
        'page_title' => 'Log in',
        'email' => 'Email address',
        'password' => 'Password',
        'show_password' => 'Show password',
        'hide_password' => 'Hide password',
        'forgot_password' => 'Forgot password?',
        'remember_me' => 'Remember me',
        'submit' => 'Log in',
        'no_account' => "Don't have an account?",
        'sign_up' => 'Sign up',
    ],

    'register' => [
        'title' => 'Your whole social calendar, in one place',
        'description' => 'Create your account and start scheduling posts across every network.',
        'page_title' => 'Register',
        'signup_with_email' => 'Sign up with email',
        'name' => 'Name',
        'name_placeholder' => 'Full name',
        'email' => 'Email address',
        'password' => 'Password',
        'show_password' => 'Show password',
        'hide_password' => 'Hide password',
        'submit' => 'Create account',
        'has_account' => 'Already have an account?',
        'log_in' => 'Log in',
    ],

    'forgot_password' => [
        'title' => 'Forgot password',
        'description' => 'Enter your email to receive a password reset link',
        'page_title' => 'Forgot password',
        'email' => 'Email address',
        'submit' => 'Email password reset link',
        'return_to' => 'Or, return to',
        'log_in' => 'log in',
    ],

    'reset_password' => [
        'title' => 'Reset password',
        'description' => 'Please enter your new password below',
        'page_title' => 'Reset password',
        'email' => 'Email',
        'password' => 'Password',
        'confirm_password' => 'Confirm Password',
        'confirm_placeholder' => 'Confirm password',
        'submit' => 'Reset password',
    ],

    'verify_email' => [
        'title' => 'Verify email',
        'description' => 'Please verify your email address by clicking on the link we just emailed to you.',
        'page_title' => 'Email verification',
        'link_sent' => 'A new verification link has been sent to the email address you provided during registration.',
        'resend' => 'Resend verification email',
        'log_out' => 'Log out',
    ],

    'accept_invite' => [
        'page_title' => 'Accept Invite',
        'title' => "You've been invited!",
        'description' => "You've been invited to join the :workspace workspace.",
        'workspace' => 'Workspace',
        'your_role' => 'Your role',
        'email' => 'Email',
        'accept' => 'Accept Invite',
        'decline' => 'Decline Invite',
        'login_prompt' => 'Log in or create an account to accept this invite.',
        'log_in' => 'Log in',
        'create_account' => 'Create Account',
        'expired_title' => 'This invite is no longer valid',
        'expired_description' => 'The workspace for this invite was deleted. Ask the account owner for a new invite if you still need access.',
        'expired_action' => 'Go to home',
    ],

];
