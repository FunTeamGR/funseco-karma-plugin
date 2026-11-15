<?php

return [
    'voting' => [
        // Allow '+++' ... '---' typed in chat, not only the /+++ ... /--- commands.
        'public' => true,
        // Finishes of a track a player needs before voting on it (0 = none).
        'require_finish' => 1,
        // Tell players who haven't voted what someone else thinks of the track.
        'show_opinions' => true,
    ],

    'display' => [
        // Chat after a vote and with /karma: the karma value and the player's own vote.
        'show_karma' => true,
        'show_votes' => true,
        // Add vote counts and percentages to those messages.
        'show_details' => false,
        // Show every player the karma message when a track starts.
        'show_at_start' => false,
        // Ask players who haven't voted at the end of the race (reminder window).
        'remind_at_score' => true,
    ],

    // 'default': 0..100 (fantastic 100, beautiful 80, good 60, bad 40, poor 20, waste 0)
    // 'rasp':    sum of votes (+3 .. -3)
    'calculation' => 'default',

    // Shared karma at mania-karma.com (the owner's XAseco setup). Votes are always kept
    // locally too; when the service is unreachable only local karma is shown.
    'sync' => [
        'enabled'  => env('KARMA_SYNC', true),
        'auth_url' => env('KARMA_AUTH_URL', 'http://worldwide.mania-karma.com/api/tmforever-trackmania-v4.php'),
        'login'    => env('KARMA_LOGIN', env('TM_MASTER_LOGIN')),
        'nation'   => env('KARMA_NATION', 'GRC'),
        'zone'     => env('KARMA_ZONE', ''),
        // Copy players' global votes into the local table when they have none locally.
        'copy_global_votes' => true,
        // After a failure, wait this long before authenticating again.
        'retry_minutes' => 10,
    ],

    'messages' => [
        'message'        => '{#server}> {#karma}Karma of {#highlite}{1}{#karma} is {#highlite}{2}',
        'your_vote'      => '{#karma}, you think this Track is {#highlite}{1}{#karma}. Command {#highlite}{2}',
        'not_voted'      => '{#karma}, you have {#highlite}not yet voted!',
        'details'        => '{#server}> {#karma}Fantastic: {#highlite}{2}%{#karma} ($n{#highlite}{3}{#karma}$m), Beautiful: {#highlite}{4}%{#karma} ($n{#highlite}{5}{#karma}$m), Good: {#highlite}{6}%{#karma} ($n{#highlite}{7}{#karma}$m), Bad: {#highlite}{8}%{#karma} ($n{#highlite}{9}{#karma}$m), Poor: {#highlite}{10}%{#karma} ($n{#highlite}{11}{#karma}$m), Waste: {#highlite}{12}%{#karma} ($n{#highlite}{13}{#karma}$m)',
        'done'           => '{#server}> {#karma}Vote successful for Track {#highlite}{1}{#karma}!',
        'change'         => '{#server}> {#karma}Vote changed for Track {#highlite}{1}{#karma}!',
        'voted'          => '{#server}> {#karma}You have already voted for this Track',
        'require_finish' => '{#server}> {#error}You need to finish this Track at least {#highlite}$I {1}{#error} time{2} before being able to vote!',
        'no_public'      => '{#server}> {#error}Public karma votes disabled, use $i{#highlite}{1}{#error} instead!',
        'opinion'        => '{#server}>> {#highlite}[{1}]{#server} thinks this Track is {#highlite}{2}{#server}! What do you think?',
        'reminder'       => 'How did you like this Track?',
        'help'           => '{#server}You can vote with:{br}  {#highlite}/+++{#server} for fantastic{br}  {#highlite}/++{#server}   for beautiful{br}  {#highlite}/+{#server}     for good{br}  {#highlite}/-{#server}      for bad{br}  {#highlite}/--{#server}     for poor{br}  {#highlite}/---{#server}    for waste{br}{br}{#server}Enter {#highlite}/karma{#server} to see the Karma of the current Track, {#highlite}/karma details{#server} for the detailed Karma.',
    ],

    // Vote levels: score => name (as players see them).
    'levels' => [3 => 'fantastic', 2 => 'beautiful', 1 => 'good', -1 => 'bad', -2 => 'poor', -3 => 'waste'],
];
