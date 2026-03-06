<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Google\Client as GoogleClient;
use Google\Service\Sheets;
use Google\Service\Sheets\ValueRange;

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

    public function getRunStatus($runId)
    {
        $token = env('APIFY_API_TOKEN');

        if (!$token) {
            return response()->json([
                'error' => 'Missing APIFY_API_TOKEN'
            ], 500);
        }

        $response = Http::withToken($token)
            ->get("https://api.apify.com/v2/actor-runs/{$runId}");

        if (!$response->successful()) {
            return response()->json([
                'error' => 'Failed to fetch run status',
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ], 500);
        }

        return response()->json([
            'message' => 'Run status fetched',
            'apify' => $response->json(),
        ]);
    }

    public function getDatasetResults(Request $request, $datasetId)
    {
        $token = env('APIFY_API_TOKEN');

        if (!$token) {
            return response()->json([
                'error' => 'Missing APIFY_API_TOKEN'
            ], 500);
        }

        $limit = $request->query('limit', 100);
        $offset = $request->query('offset', 0);

        $response = Http::withToken($token)
            ->get("https://api.apify.com/v2/datasets/{$datasetId}/items", [
                'clean' => 'true',
                'format' => 'json',
                'limit' => $limit,
                'offset' => $offset,
            ]);

        if (!$response->successful()) {
            return response()->json([
                'error' => 'Failed to fetch dataset results',
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ], 500);
        }

        return response()->json([
            'message' => 'Dataset results fetched',
            'datasetId' => $datasetId,
            'count' => is_array($response->json()) ? count($response->json()) : 0,
            'items' => $response->json(),
        ]);
    }

    public function importDatasetToSheet(Request $request)
    {
        $token = env('APIFY_API_TOKEN');
        $sheetId = env('GOOGLE_SHEET_ID');
        $serviceAccountJson = env('GOOGLE_SERVICE_ACCOUNT_JSON');

        if (!$token || !$sheetId || !$serviceAccountJson) {
            return response()->json([
                'error' => 'Missing required env variables'
            ], 500);
        }

        $datasetId = $request->input('datasetId');
        $sheetName = $request->input('sheetName', 'Influencer_Master');

        if (!$datasetId) {
            return response()->json([
                'error' => 'datasetId is required'
            ], 422);
        }

        // Fetch dataset items
        $response = Http::withToken($token)
            ->get("https://api.apify.com/v2/datasets/{$datasetId}/items", [
                'clean' => 'true',
                'format' => 'json',
            ]);

        if (!$response->successful()) {
            return response()->json([
                'error' => 'Failed to fetch dataset results'
            ], 500);
        }

        $items = $response->json();

        if (!is_array($items) || count($items) === 0) {
            return response()->json([
                'message' => 'No items found in dataset'
            ]);
        }

        // Setup Google client
        $client = new GoogleClient();
        $client->setAuthConfig(json_decode($serviceAccountJson, true));
        $client->addScope(Sheets::SPREADSHEETS);

        $sheets = new Sheets($client);

        $rows = [];

        foreach ($items as $item) {

            $handle = data_get($item, 'authorMeta.name', '');

            if ($handle && !str_starts_with($handle, '@')) {
                $handle = '@' . $handle;
            }

            $rows[] = [
                'TikTok',
                $handle,
                data_get($item, 'text', ''),
                data_get($item, 'playCount', ''),
                data_get($item, 'diggCount', ''),
                data_get($item, 'commentCount', ''),
                data_get($item, 'shareCount', ''),
                data_get($item, 'collectCount', ''),
                data_get($item, 'createTimeISO', ''),
                data_get($item, 'webVideoUrl', ''),
                now()->toDateTimeString(),
            ];
        }

        $body = new ValueRange([
            'values' => $rows
        ]);

        $params = [
            'valueInputOption' => 'RAW'
        ];

        $result = $sheets->spreadsheets_values->append(
            $sheetId,
            "{$sheetName}!A1",
            $body,
            $params
        );

        return response()->json([
            'message' => 'Dataset imported to Google Sheet',
            'datasetId' => $datasetId,
            'importedRows' => count($rows)
        ]);
    }
}
