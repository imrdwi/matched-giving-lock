<?php

namespace App\Providers;

use App\Donations\LockedDonationRecorder;
use App\Donations\RecordsDonations;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(RecordsDonations::class, LockedDonationRecorder::class);
    }
}
