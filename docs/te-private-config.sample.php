<?php
/*
 * Copy this file to  ~/te-private/config.php  on the Bluehost account
 * (the folder that sits NEXT TO public_html, not inside it), then fill it in.
 * Never commit the real file. It holds the Microsoft app secret.
 */
return [
    // Microsoft Entra ID (Azure AD) app registration, single tenant.
    'tenant_id'     => 'PASTE-DIRECTORY-TENANT-ID',
    'client_id'     => 'PASTE-APPLICATION-CLIENT-ID',
    'client_secret' => 'PASTE-CLIENT-SECRET-VALUE',
    'redirect_uri'  => 'https://codebluetechexpress.com/api/auth/callback.php',

    // Mailbox that customer emails are sent from (appears in its Sent Items).
    'mail_sender'   => 'express@codebluetechnology.com',

    // Who is told when a new visit request arrives (leave [] for no alerts).
    'notify_to'     => ['express@codebluetechnology.com'],

    // Only these Microsoft 365 accounts may open the Schedule Manager.
    // If you leave this out, any account in your tenant can.
    'staff_emails'  => ['you@codebluetechnology.com'],

    // Hours a sign-in lasts before staff must sign in again (default 10).
    // 'session_hours' => 10,
];
