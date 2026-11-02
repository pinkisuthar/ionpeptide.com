<?php
/**
 * Restrict temporary account switching to administrators.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_filter('user_has_cap', function ($allcaps, $caps, $args, $user) {

    if (
        isset($args[0]) &&
        $args[0] === 'switch_to_user' &&
        !in_array('administrator', (array) $user->roles, true)
    ) {
        $allcaps['switch_users'] = false;
    }

    return $allcaps;

}, 9, 4);
