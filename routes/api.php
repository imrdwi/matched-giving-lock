<?php

use App\Http\Controllers\DonationController;
use Illuminate\Support\Facades\Route;

Route::post('/matching-pools/{pool}/donations', [DonationController::class, 'store']);
