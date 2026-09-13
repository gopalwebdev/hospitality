<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * The founding member of the product team.
 *
 * There is exactly one, and the account carries no password: signing in means
 * receiving a one-time code at this address.
 */
class AdminSeeder extends Seeder
{
    use WithoutModelEvents;

    public const string EMAIL = 'gopalwebdev@gmail.com';

    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => 'Gopal Narayanan',
                'email_verified_at' => now(),
                'is_admin' => true,
            ],
        );
    }
}
