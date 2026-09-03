<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(DemoTransportSeeder::class);

        $company = Company::query()->where('slug', 'mufambi-express')->firstOrFail();
        $accounts = [
            ['name' => 'Legacy Demo Passenger', 'email' => 'test@example.com', 'role' => 'passenger', 'company_id' => null, 'password' => 'password'],
            ['name' => 'Demo Passenger', 'email' => 'passenger@mufambi.test', 'role' => 'passenger', 'company_id' => null],
            ['name' => 'Demo Administrator', 'email' => 'admin@mufambi.test', 'role' => 'super_administrator', 'company_id' => null],
            ['name' => 'Demo Operator', 'email' => 'operator@mufambi.test', 'role' => 'company_administrator', 'company_id' => $company->id],
            ['name' => 'Demo Driver', 'email' => 'driver@mufambi.test', 'role' => 'driver', 'company_id' => $company->id],
            ['name' => 'Demo Agent', 'email' => 'agent@mufambi.test', 'role' => 'booking_clerk', 'company_id' => $company->id],
        ];

        foreach ($accounts as $account) {
            $user = User::query()->updateOrCreate(
                ['email' => $account['email']],
                $account + [
                    'password' => Hash::make($account['password'] ?? 'MufambiDemo123!'),
                    'email_verified_at' => now(),
                    'status' => 'active',
                ],
            );
            $user->syncRoles([$account['role']]);
        }
    }
}
