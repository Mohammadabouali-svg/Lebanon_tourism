<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/*
| Creates the very first administrator on a fresh production database:
|
|     docker compose exec php php artisan app:create-admin
|
| There is deliberately no web page that creates an admin without being
| logged in as one. The original project's add_admin.php did exactly that.
*/
#[Signature('app:create-admin')]
#[Description('Create an administrator account')]
class CreateAdmin extends Command
{
    public function handle(): int
    {
        $name = text('Name', required: true);
        $email = text('E-mail', required: true);
        $plainPassword = password('Password', required: true);

        $validator = Validator::make(
            ['email' => mb_strtolower(trim($email)), 'password' => $plainPassword],
            ['email' => ['email:rfc', 'unique:users,email'], 'password' => [Password::defaults()]],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $admin = new User(['name' => $name, 'email' => $email, 'password' => $plainPassword]);
        $admin->role = UserRole::Admin;
        $admin->email_verified_at = now();
        $admin->save();

        $this->components->info("Administrator {$admin->email} created.");

        return self::SUCCESS;
    }
}
