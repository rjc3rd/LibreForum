<?php
// Who may do what. Roles: owner and moderator (together "staff") and member. A muted member can read
// but not write. These are checked again inside the functions that change things, not just by the pages.

declare(strict_types=1);

function lf_is_owner(?array $member): bool
{
    return $member !== null && $member['role'] === 'owner' && $member['status'] === 'active';
}

function lf_is_staff(?array $member): bool
{
    return $member !== null && in_array($member['role'], ['owner', 'moderator'], true) && $member['status'] === 'active';
}

// Someone who has picked a username and isn't muted or removed.
function lf_can_write(?array $member): bool
{
    return $member !== null && $member['status'] === 'active' && (string) $member['username'] !== '';
}

function lf_can_start_thread(?array $member, array $category): bool
{
    return lf_can_write($member) && (!$category['staff_only'] || lf_is_staff($member));
}

// Replying: staff can always reply, everyone else only in threads that aren't locked.
function lf_can_reply(?array $member, array $thread): bool
{
    return lf_can_write($member) && (!$thread['locked'] || lf_is_staff($member));
}
