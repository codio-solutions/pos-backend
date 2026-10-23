<?php

use App\Models\Visit;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('visits:privatize-photos {--apply : Copy, verify and remove public originals}', function () {
    $public = Storage::disk('public');
    $private = Storage::disk('visits');
    $count = 0;
    foreach (Visit::query()->cursor() as $visit) {
        foreach ([$visit->doctor_photo_path, $visit->building_photo_path] as $path) {
            if (! $path || ! $public->exists($path)) {
                continue;
            }
            if (! preg_match('~^visits/[a-zA-Z0-9_.-]+$~', $path)) {
                throw new RuntimeException('Unexpected photo path; manual review required.');
            }
            $source = realpath($public->path($path));
            $root = realpath($public->path('visits'));
            if (! $source || ! $root || ! str_starts_with($source, $root.DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('Photo path escaped expected directory.');
            }
            $count++;
            if ($this->option('apply')) {
                $bytes = $public->get($path);
                if ($private->exists($path) && ! hash_equals(hash('sha256', $bytes), hash('sha256', $private->get($path)))) {
                    throw new RuntimeException('Private file differs from public file; manual review required.');
                }
                $private->put($path, $bytes);
                if (! hash_equals(hash('sha256', $bytes), hash('sha256', $private->get($path)))) {
                    throw new RuntimeException('Photo verification failed.');
                }
                $public->delete($path);
            }
        }
    }
    $this->info(($this->option('apply') ? 'Privatized' : 'Would privatize').' '.$count.' photo files.');
})->purpose('Move legacy proof photos to private storage; dry-run unless --apply is supplied');

Artisan::command('security:check-config', function () {
    $failures = [];
    if (config('app.env') === 'production') {
        if (config('app.debug')) {
            $failures[] = 'APP_DEBUG must be false.';
        }
        foreach (['app.url', 'services.frontend.url'] as $key) {
            if (! str_starts_with((string) config($key), 'https://')) {
                $failures[] = $key.' must use HTTPS.';
            }
        }
    }
    if (strlen(trim((string) config('services.website.webhook_secret'))) < 32) {
        $failures[] = 'Webhook disabled: configure WEBSITE_WEBHOOK_SECRET with at least 32 random characters.';
    }
    foreach ($failures as $failure) {
        $this->error($failure);
    }
    if (! $failures) {
        $this->info('Configuration checks passed.');
    }

    return $failures ? 1 : 0;
})->purpose('Validate security-sensitive deployment configuration without displaying secrets');
