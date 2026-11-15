<?php

/*
 * Widget defaults merged into config('manialinks.karma'); config/manialinks.php of the app wins.
 * Karma widget: pinned where the owner's mania_karma.xml had it, above local records.
 * The merge is shallow (top-level keys only): an app override of `gamemode` (or `reminder`)
 * replaces that whole subtree, so it must carry every gamemode, not just the changed one.
 */
return [
    'gamemode' => [
        'rounds' => ['pos_x' => 49.2, 'pos_y' => 32.86],
        'time_attack' => ['pos_x' => 49.2, 'pos_y' => 32.86],
        'team' => ['pos_x' => 49.2, 'pos_y' => 32.86],
        'laps' => ['pos_x' => 49.2, 'pos_y' => 27.36],
        'stunts' => ['pos_x' => 49.2, 'pos_y' => 17.5],
        'cup' => ['pos_x' => 49.2, 'pos_y' => 32.86],
    ],
    'reminder' => ['pos_x' => -41, 'pos_y' => -23.35],
];
