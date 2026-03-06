<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ApifyController extends Controller
{
    public function runTikTokHashtagActor(Request $request)
    {
        $token = env('APIFY_API_TOKEN');
        $actorId = env('APIFY_TIKTOK_HASHTAG_ACTOR_ID');

        if (!$token || !$actorId) {
            return response()->json([
                'error' => 'Missing Apify env vars'
            ], 500);
        }

        // Pass-through input:
        // Whatever Lovable sends in JSON gets forwarded to the actor.
        $input = $request->all();

        $response = Http::withToken($token)
            ->post("https://api.apify.com/v2/acts/{$actorId}/runs", $input);

        if (!$response->successful()) {
            return response()->json([
                'error' => 'Apify run failed',
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ], 500);
        }

        return response()->json([
            'message' => 'Actor started',
            'apify' => $response->json(),
        ]);
    }
}
