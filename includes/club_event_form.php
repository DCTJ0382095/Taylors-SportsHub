<?php

function clubEventFormData(array $input, ?string $coverImagePath): array
{
    foreach ($input as $value) {
        if (!is_string($value)) {
            throw new InvalidArgumentException('Submit a single value per field.');
        }
    }

    $title = trim($input['title'] ?? '');
    $sport = trim($input['sport'] ?? '');
    $location = trim($input['location'] ?? '');
    if ($title === '' || $sport === '' || $location === '') {
        throw new InvalidArgumentException('Enter a title, sport, and venue.');
    }

    if (mb_strlen($title, 'UTF-8') > 150 || mb_strlen($sport, 'UTF-8') > 80
        || mb_strlen($location, 'UTF-8') > 150 || strlen($input['description'] ?? '') > 65535) {
        throw new InvalidArgumentException('Shorten the event details.');
    }

    $type = $input['event_type'] ?? '';
    $audience = $input['audience'] ?? '';
    $status = $input['status'] ?? '';
    if (!in_array($type, ['session', 'tournament'], true)
        || !in_array($audience, ['open', 'members_only'], true)
        || !in_array($status, ['draft', 'published'], true)) {
        throw new InvalidArgumentException('Choose an event type, audience, and save action.');
    }

    $startsAt = clubEventDate($input['starts_at'] ?? '');
    $endsAt = clubEventDate($input['ends_at'] ?? '');
    $closesAt = clubEventDate($input['registration_closes_at'] ?? '');
    if ($startsAt === null || ($endsAt !== null && $endsAt <= $startsAt)
        || ($closesAt !== null && $closesAt > $startsAt)) {
        throw new InvalidArgumentException('Check the start, end, and registration closing times.');
    }

    $isTeam = $type === 'tournament' && ($input['tournament_format'] ?? '') === 'team';
    $capacity = $isTeam ? null : clubEventCount($input['capacity'] ?? '', 2147483647);
    $minTeamSize = $isTeam ? clubEventCount($input['min_team_size'] ?? '', 65535) : null;
    $maxTeamSize = $isTeam ? clubEventCount($input['max_team_size'] ?? '', 65535) : null;
    $maxTeams = $isTeam ? clubEventCount($input['max_teams'] ?? '', 2147483647) : null;
    if ($isTeam && ($minTeamSize === null || $minTeamSize < 2 || $maxTeamSize === null
        || $maxTeamSize < $minTeamSize || $maxTeams === null)) {
        throw new InvalidArgumentException('Enter a valid roster size range and maximum team count.');
    }

    return [
        'title' => $title,
        'description' => trim($input['description'] ?? ''),
        'sport' => $sport,
        'location' => $location,
        'cover_image_path' => $coverImagePath,
        'event_type' => $type,
        'audience' => $audience,
        'allow_guests' => ($input['allow_guests'] ?? '') === '1' ? 1 : 0,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'capacity' => $capacity,
        'min_team_size' => $minTeamSize,
        'max_team_size' => $maxTeamSize,
        'max_teams' => $maxTeams,
        'team_fee' => $isTeam ? clubEventMoney($input['team_fee'] ?? '') : null,
        'registration_closes_at' => $closesAt,
        'member_price' => $isTeam ? '0.00' : clubEventMoney($input['member_price'] ?? '0'),
        'student_price' => $isTeam ? '0.00' : clubEventMoney($input['student_price'] ?? '0'),
        'guest_price' => $isTeam ? '0.00' : clubEventMoney($input['guest_price'] ?? '0'),
        'status' => $status,
    ];
}

function clubEventDate(string $value): ?string
{
    if ($value === '') {
        return null;
    }

    $format = 'Y-m-d\TH:i';
    $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
    if ($date === false || $date->format($format) !== $value) {
        throw new InvalidArgumentException('Enter a valid date and time.');
    }

    return $date->format('Y-m-d H:i:s');
}

function clubEventCount(string $value, int $max): ?int
{
    if ($value === '') {
        return null;
    }

    $count = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $max]]);
    if ($count === false) {
        throw new InvalidArgumentException('Enter a positive whole number within the allowed range.');
    }

    return $count;
}

function clubEventMoney(string $value): string
{
    $value = trim($value);
    if (!preg_match('/\A[0-9]{1,6}(?:\.[0-9]{1,2})?\z/', $value)) {
        throw new InvalidArgumentException('Enter a price from RM 0 to RM 999,999.99.');
    }

    return $value;
}
