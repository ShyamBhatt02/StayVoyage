<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Process events/notifications inline so the seeded dashboard (notifications, invoices) is complete
        // even before a queue worker is started.
        config(['queue.default' => 'sync', 'mail.default' => 'log']);

        $this->copySeedImages();

        $this->call([
            RolesAndPermissionsSeeder::class,
            ContentSeeder::class,
            HotelSeeder::class,
            TourCatalogueSeeder::class,
            MembershipAndCouponSeeder::class,
            DemoDataSeeder::class,
        ]);

        Cache::flush();
        $this->command?->info('Seeded. Log in with test.customer@stayvoyage.test / test.admin@stayvoyage.test / test.partner@stayvoyage.test (password: '.env('SEED_TEST_PASSWORD', 'Password@123').')');
    }

    /** Copy the bundled free-licence photos (resources/seed-images) to the public disk. */
    private function copySeedImages(): void
    {
        $source = resource_path('seed-images');
        $disk = Storage::disk('public');
        foreach (File::allFiles($source) as $file) {
            $target = 'seed/'.str_replace('\\', '/', $file->getRelativePathname());
            if (! $disk->exists($target)) {
                $disk->put($target, File::get($file->getPathname()));
            }
        }
    }
}
