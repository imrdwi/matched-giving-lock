<?php

namespace App\Http\Controllers;

use App\Donations\RecordsDonations;
use App\Models\MatchingPool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DonationController extends Controller
{
    public function store(Request $request, MatchingPool $pool, RecordsDonations $donations): JsonResponse
    {
        $validated = $request->validate([
            'amount_cents' => ['required', 'integer', 'min:1'],
        ]);

        $donation = $donations->record($pool, $validated['amount_cents']);

        return response()->json([
            'id' => $donation->id,
            'amount_cents' => $donation->amount_cents,
            'matched_cents' => $donation->matched_cents,
        ], 201);
    }
}
