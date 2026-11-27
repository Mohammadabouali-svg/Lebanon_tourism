<?php

namespace App\Enums;

/*
| What an admin did, as recorded in `audit_events.action` (Exercise 12, D25).
|
| The values are an API contract (the admin page filters and labels by them)
| and are mirrored by the CHECK constraint `audit_events_action_valid`. A new
| case needs a new migration that replaces that constraint (invariant 17).
| `<subject>.<what happened>`, so the list reads well when sorted.
*/
enum AuditAction: string
{
    /** An admin created another admin account. Subject: the new admin. */
    case AdminCreated = 'admin.created';

    /** An operator's profile became approved. Subject: the operator user. */
    case OperatorApproved = 'operator.approved';

    /** An operator's profile became suspended. Subject: the operator user. */
    case OperatorSuspended = 'operator.suspended';

    /** A tour's status (or its moderation lock) changed. Subject: the tour. */
    case TourStatusChanged = 'tour.status_changed';

    /** An account was suspended. Subject: the user. */
    case UserSuspended = 'user.suspended';

    /** A suspension was lifted. Subject: the user. */
    case UserUnsuspended = 'user.unsuspended';

    /** Credit was added to a wallet. Subject: the wallet's owner. */
    case WalletCredited = 'wallet.credited';
}
