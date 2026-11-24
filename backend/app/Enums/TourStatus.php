<?php

namespace App\Enums;

enum TourStatus: string
{
    /** Being written by the operator, invisible to the public. */
    case Draft = 'draft';

    /** Visible and bookable (if it has free spots and has not started). */
    case Published = 'published';

    /** Hidden by the operator or an admin. Existing bookings stay valid. */
    case Archived = 'archived';
}
