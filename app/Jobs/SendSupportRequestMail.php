<?php

namespace App\Jobs;

use App\Mail\SupportRequestMail;
use App\Models\SupportRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

class SendSupportRequestMail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public function __construct(public string $supportRequestId) {}

    public function handle(): void
    {
        $supportRequest = SupportRequest::query()->find($this->supportRequestId);
        if (! $supportRequest || $supportRequest->status === 'sent') {
            return;
        }

        $supportRequest->increment('delivery_attempts');
        $inbox = trim((string) config('support.inbox_email'));
        if ($inbox === '') {
            throw new RuntimeException('Support inbox is not configured.');
        }

        $workspaceName = (string) DB::table('workspaces')->where('id', $supportRequest->workspace_id)->value('name');
        Mail::to($inbox)->send(new SupportRequestMail([
            'reference' => $supportRequest->reference,
            'category' => $supportRequest->category,
            'subject' => $supportRequest->subject,
            'message' => $supportRequest->message,
            'page' => $supportRequest->page,
            'email' => $supportRequest->email,
            'workspace_id' => $supportRequest->workspace_id,
            'workspace_name' => $workspaceName,
        ]));

        $supportRequest->forceFill([
            'status' => 'sent',
            'last_delivery_error' => null,
            'sent_at' => now(),
        ])->save();
    }

    public function failed(Throwable $exception): void
    {
        SupportRequest::query()->whereKey($this->supportRequestId)->update([
            'status' => 'failed',
            'last_delivery_error' => mb_substr($exception->getMessage(), 0, 2000),
            'updated_at' => now(),
        ]);
    }
}
