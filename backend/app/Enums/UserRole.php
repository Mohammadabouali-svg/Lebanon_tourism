<?php

namespace App\Enums;

/*
| PHP "backed enums" replace magic strings. Compare
|
|     if ($user->role === 'admin')        // a typo here fails silently
|     if ($user->role === UserRole::Admin) // a typo here is a fatal error
|
| Eloquent casts the `role` column to this enum automatically (see User::casts()).
*/
enum UserRole: string
{
    case Traveler = 'traveler';
    case Operator = 'operator';
    case Admin = 'admin';
}
