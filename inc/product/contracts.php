<?php

declare(strict_types=1);

const FC_CREW_STATUSES = ['ACTIVE', 'ARCHIVED'];
const FC_CREW_ROLES = ['OWNER', 'MEMBER'];
const FC_CREW_MEMBERSHIP_STATUSES = ['ACTIVE', 'LEFT', 'REMOVED'];

const FC_CHALLENGE_LIFECYCLES = [
    'DRAFT',
    'FORMING_CREW',
    'BASELINE',
    'READY_TO_LAUNCH',
    'LIVE',
    'FINAL_WEEK_LIVE',
    'RESULTS_UNDER_REVIEW',
    'COMPLETED',
];

const FC_CHALLENGE_OPERATIONAL_STATES = ['NORMAL', 'NEEDS_ATTENTION'];
const FC_CHALLENGE_PARTICIPATION_STATUSES = ['ACTIVE', 'WITHDRAWN', 'REMOVED'];
const FC_CHALLENGE_ENTRY_KINDS = ['STANDARD', 'LATE'];
const FC_CHALLENGE_RULE_VERSION_STATUSES = ['DRAFT', 'PUBLISHED'];
const FC_CERTIFIED_SCORING_STANDARD = 'BODY_COMPOSITION_V1_0';

function fc_product_contract_value(string $value, array $allowed, string $label): string
{
    $value = strtoupper(trim($value));
    if (!in_array($value, $allowed, true)) {
        throw new InvalidArgumentException(sprintf('Invalid %s: %s', $label, $value));
    }

    return $value;
}

function fc_challenge_lifecycle_label(string $status, string $operationalState = 'NORMAL'): string
{
    if (strtoupper($operationalState) === 'NEEDS_ATTENTION') {
        return 'Needs Attention';
    }

    return match (strtoupper($status)) {
        'DRAFT' => 'Draft',
        'FORMING_CREW' => 'Forming Crew',
        'BASELINE' => 'Baseline',
        'READY_TO_LAUNCH' => 'Ready to Launch',
        'LIVE' => 'Live',
        'FINAL_WEEK_LIVE' => 'Final Week — Live',
        'RESULTS_UNDER_REVIEW' => 'Challenge Complete — Results Under Review',
        'COMPLETED' => 'Completed Challenge',
        default => 'Challenge',
    };
}

function fc_weekday_label(int $day): string
{
    return match ($day) {
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        default => throw new InvalidArgumentException('Weekly check-in day must be between 0 and 6.'),
    };
}

function fc_product_timezone_is_valid(string $timezone): bool
{
    return in_array($timezone, DateTimeZone::listIdentifiers(), true);
}
