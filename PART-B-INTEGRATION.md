# Part B integration

These are proposed public paths relative to the configured `base_url`.

| Path | Access | Handler |
| --- | --- | --- |
| `manage/events.php?club_id=…` | President or `can_manage_events` | GET lists club events. POST calls `repeatClubEventNextWeek()` or `cancelClubEvent()`. |
| `manage/event.php?club_id=…&event_id=…` | President or `can_manage_events` | GET loads the existing event for that club or shows a blank form. POST validates via `clubEventFormData()` and calls `saveClubEvent()`. Omit `event_id` to create. |
| `host-event.php` | Signed-in student | POST calls `hostStudentEvent()` with the signed-in user ID. |
| `my-activities.php` | Signed-in user | GET calls `getMyActivities()` with the signed-in user ID. |
| `join-club.php?club_id=…` | Signed-in user | GET shows the current fee and DuitNow QR. POST calls `applyForClubMembership()`. |
| `manage/memberships.php?club_id=…` | President or `can_manage_memberships` | GET lists club applications. POST calls `reviewClubMembership()` for the selected pending application. |
| `manage/membership-receipt.php?club_id=…&membership_id=…` | President or `can_manage_memberships` | GET checks that the application belongs to the authorized club before streaming its receipt from private storage. |

## What Part B needs from A

- A login guard that supplies the authenticated user's ID and account type.
- A club permission guard that accepts a club ID and a permission key. The schema names the relevant flags `can_manage_events` and `can_manage_memberships`.
- CSRF token generation for the views and validation of their `csrf_token` POST field before mutation.
- Secure uploads for event covers and private membership receipts, with a way to remove a newly saved file when the corresponding database write fails. Keep an existing cover when an edit supplies no replacement. Repeated events can share a cover, so remove a replaced cover only after checking for other references.
- Shared header and footer, and a way to turn a club's stored DuitNow QR path into a public image URL.

After a successful POST, redirect to the relevant GET page. On a validation error, render the form again with the submitted text values and ask the user to reselect any upload.

## What Part B needs from C

- Use `canJoinEvent()` for the audience and registration window and `calculatePrice()` for the amount to charge. Supply an active membership result for the event's club, or false for student-hosted events. Charge the flat team fee once to the captain.
- In the sign-up transaction, lock the event row, recheck the signup window, count reserved spots, and insert the registration or team before releasing the lock. For individual events, count pending and approved registrations. For team events, count each team with a pending or approved reservation once. Reject duplicate participant registrations.
- Use the registration statuses and team relationships from `database/schema.sql` so My Activities can show the same sign-up status as the review screen.
- Apply the shared stylesheet to the Part B views.

## Local verification

Import `database/schema.sql` and then `database/seed.sql` into an empty `sportshub` database. The demo accounts in the seed use the password `password`. Paid membership applications need a club QR to be configured before testing their browser flow.

## Events dashboard adapter

The events dashboard is wired to a proposed adapter at `includes/part_b_security.php`.
The adapter returns four callables:

- `require_login(): array` supplies the authenticated user, including an integer `id` and `account_type` of `student` or `guest`, or ends the request.
- `require_club_permission(int $userId, int $clubId, string $permission): void` ends the request on denial.
- `csrf_token(): string` supplies the current token.
- `verify_csrf($submittedToken): void` ends the request on an invalid or missing token, including non-string values.

Without the adapter, the dashboard returns HTTP 503. Its create and edit links target the planned editor route.

The editor uses the same adapter. To enable cover uploads, also supply
`upload_event_cover(array $file): string`, which validates and stores a cover
under a fresh path, and `discard_event_cover(string $path): void`, which cleans
up that new file if saving fails. Upload validation errors intended for the
form should throw `InvalidArgumentException` or `DomainException`.

Student hosting uses the same cover upload callbacks as the club event editor.
My Activities uses only the authenticated user returned by the adapter.

Membership routes use the same security adapter. Paid applications and private
receipt delivery also need these callbacks:

- `public_qr_url(string $path): ?string` resolves a club's stored DuitNow QR to a public image URL.
- `upload_membership_receipt(array $file): string` validates an upload and returns a fresh private storage path.
- `discard_membership_receipt(string $path): void` removes a new receipt if the application fails.
- `stream_membership_receipt(string $path): void` resolves the stored path inside private receipt storage and streams it as an attachment, or returns 404 for an unavailable file.
