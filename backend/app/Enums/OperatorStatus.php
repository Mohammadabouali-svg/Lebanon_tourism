<?php

namespace App\Enums;

enum OperatorStatus: string
{
    /** Registered, waiting for an admin to review the company. */
    case Pending = 'pending';

    /** May publish tours. */
    case Approved = 'approved';

    /** Blocked by an admin. Their tours disappear from the public site. */
    case Suspended = 'suspended';
}
