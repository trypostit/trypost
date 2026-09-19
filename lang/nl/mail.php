<?php

declare(strict_types=1);

return [

    'layout' => [
        'tagline' => 'Open-source tool om social media in te plannen',
        'manage_notifications' => 'Meldingen beheren',
        'signoff' => 'Met vriendelijke groet,',
        'team' => 'Het TryPost-team',
    ],

    'account_disconnected' => [
        'subject' => 'Je :platform-account in :workspace moet opnieuw worden verbonden',
        'title' => 'Je :platform-account moet opnieuw worden verbonden',
        'preview' => 'Verbind je :platform-account in :workspace opnieuw om posts te blijven inplannen.',
        'heading' => 'Account losgekoppeld',
        'intro' => 'Je <strong>:platform</strong>-account <strong>:account</strong> is losgekoppeld van de werkruimte <strong>:workspace</strong>.',
        'reasons_title' => 'Dit kan zijn gebeurd omdat:',
        'reason_expired' => 'Je toegangstoken is verlopen',
        'reason_revoked' => 'Je hebt de toegang van TryPost ingetrokken',
        'reason_error' => 'Er was een authenticatiefout',
        'reconnect_cta' => 'Verbind je account opnieuw om te blijven inplannen en publiceren.',
        'button' => 'Account opnieuw verbinden',
    ],

    'email_verification' => [
        'subject' => 'Bevestig je e-mailadres',
        'preview' => 'Bevestig je e-mailadres.',
        'greeting' => 'Hallo :name,',
        'body' => 'Bevestig je e-mailadres via de knop hieronder:',
        'button' => 'E-mailadres bevestigen',
        'ignore' => 'Als je geen account hebt aangemaakt, kun je deze e-mail negeren.',
    ],

    'mentioned_in_comment' => [
        'subject' => ':name heeft je genoemd op TryPost',
        'title' => ':name heeft je genoemd',
        'intro' => ':name heeft je genoemd in een reactie op een post.',
        'button' => 'Reactie bekijken',
    ],

    'password_reset' => [
        'subject' => 'Stel je wachtwoord opnieuw in',
        'preview' => 'Stel je wachtwoord opnieuw in.',
        'greeting' => 'Hallo :name,',
        'body' => 'We hebben een verzoek ontvangen om je wachtwoord opnieuw in te stellen. Klik op de knop om een nieuw wachtwoord te maken:',
        'button' => 'Wachtwoord opnieuw instellen',
        'expiry' => 'Deze link verloopt over 60 minuten. Als je hier niet om hebt gevraagd, kun je deze e-mail negeren.',
    ],

    'post_at_risk' => [
        'subject' => '{1} :count post loopt risico in :workspace|[0,*] :count posts lopen risico in :workspace',
        'title' => 'Posts kunnen mislukken',
        'heading' => 'Posts kunnen mislukken',
        'intro' => 'De volgende accounts in de werkruimte :workspace moeten opnieuw worden verbonden voordat deze ingeplande posts kunnen worden gepubliceerd:',
        'posts_label' => '{1} :count post ingepland: :times UTC|[0,*] :count posts ingepland: :times UTC',
        'reconnect_cta' => 'Verbind deze accounts nu opnieuw zodat je ingeplande posts niet worden gemist.',
        'button' => 'Accounts opnieuw verbinden',
    ],

    'post_publish_failed' => [
        'subject' => 'Je post in :workspace kon niet worden gepubliceerd',
        'title' => 'Je post kon niet worden gepubliceerd',
        'preview' => 'Een of meer platforms konden niet publiceren.',
        'heading' => 'Je post kon niet worden gepubliceerd',
        'body' => 'Je ingeplande post in de werkruimte :workspace kon op een of meer platforms niet worden gepubliceerd.',
        'platforms_title' => 'Mislukte platforms:',
        'button' => 'Post bekijken',
    ],

    'post_published' => [
        'subject' => 'Je post is gepubliceerd in :workspace',
        'title' => 'Je post is gepubliceerd',
        'preview' => 'Je post is succesvol gepubliceerd.',
        'heading' => 'Je post is gepubliceerd',
        'body' => 'Je post in de werkruimte :workspace is succesvol gepubliceerd.',
        'platforms_title' => 'Gepubliceerd op:',
        'view_post' => 'Post bekijken',
        'button' => 'Post bekijken',
    ],

    'webhook_paused' => [
        'subject' => 'Webhook gepauzeerd: :endpoint',
        'title' => 'Webhook gepauzeerd na herhaalde fouten',
        'preview' => 'We hebben een webhook gepauzeerd na 5 opeenvolgende afleverfouten.',
        'heading' => 'Webhook gepauzeerd na herhaalde fouten',
        'body' => 'We hebben de webhook op :endpoint gepauzeerd na 5 opeenvolgende afleverfouten. Controleer het endpoint en schakel het weer in op de webhookdetailpagina.',
        'button' => 'Webhook bekijken',
    ],

    'workspace_connections_disconnected' => [
        'subject' => '{1} :count account moet opnieuw worden gekoppeld in :workspace|[0,*] :count accounts moeten opnieuw worden gekoppeld in :workspace',
        'title' => 'Accounts moeten opnieuw worden gekoppeld',
        'heading' => 'Accounts moeten opnieuw worden gekoppeld',
        'intro' => 'De volgende social accounts in je workspace <strong>:workspace</strong> zijn losgekoppeld en moeten opnieuw worden gekoppeld:',
        'reasons_title' => 'Dit kan gebeurd zijn omdat:',
        'reason_expired' => 'Toegangstokens zijn verlopen',
        'reason_revoked' => 'Je hebt de toegang van TryPost op het platform ingetrokken',
        'reason_changed' => 'Het platform heeft zijn authenticatievereisten gewijzigd',
        'reconnect_cta' => 'Koppel deze accounts opnieuw om posts te blijven plannen en publiceren.',
        'button' => 'Accounts opnieuw koppelen',
    ],

    'workspace_invite' => [
        'subject' => 'Je bent uitgenodigd voor :account',
        'title' => 'Je bent uitgenodigd voor :account',
        'preview' => 'Je bent uitgenodigd voor :account',
        'heading' => 'Je bent uitgenodigd!',
        'intro' => 'Je bent uitgenodigd om samen te werken in de werkruimte <strong>:account</strong>.',
        'role' => 'Je bent uitgenodigd als <strong>:role</strong>.',
        'button' => 'Uitnodiging accepteren',
        'expiry' => 'Deze uitnodiging verloopt over 7 dagen.',
    ],

];
