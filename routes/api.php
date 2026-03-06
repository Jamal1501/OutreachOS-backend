use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ApifyController;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'loveframes-outreach-api'
    ]);
});

Route::post('/apify/run', [ApifyController::class, 'runTikTokHashtagActor']);
