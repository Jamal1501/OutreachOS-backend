<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SupportRequestMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public array $supportRequest) {}

    public function envelope(): Envelope
    {
        $reference = str_replace(["\r", "\n"], '', (string) ($this->supportRequest['reference'] ?? 'SUPPORT'));
        $subject = str_replace(["\r", "\n"], '', (string) ($this->supportRequest['subject'] ?? 'Support request'));

        return new Envelope(subject: "[{$reference}] Social CORE: {$subject}");
    }

    public function content(): Content
    {
        $reference = e((string) ($this->supportRequest['reference'] ?? ''));
        $category = e((string) ($this->supportRequest['category'] ?? 'other'));
        $subject = e((string) ($this->supportRequest['subject'] ?? ''));
        $message = nl2br(e((string) ($this->supportRequest['message'] ?? '')));
        $email = e((string) ($this->supportRequest['email'] ?? ''));
        $workspace = e((string) ($this->supportRequest['workspace_name'] ?? ''));
        $workspaceId = e((string) ($this->supportRequest['workspace_id'] ?? ''));
        $page = e((string) ($this->supportRequest['page'] ?? ''));

        return new Content(htmlString: <<<HTML
<div style="font-family:Inter,Arial,sans-serif;line-height:1.55;color:#111827;max-width:680px">
  <h1 style="font-size:20px;margin:0 0 12px">{$subject}</h1>
  <p style="margin:0 0 16px;color:#4b5563"><strong>{$reference}</strong> · {$category}</p>
  <div style="background:#f3f4f6;border-radius:8px;padding:14px;margin:0 0 18px">{$message}</div>
  <p style="margin:4px 0"><strong>From:</strong> {$email}</p>
  <p style="margin:4px 0"><strong>Workspace:</strong> {$workspace} ({$workspaceId})</p>
  <p style="margin:4px 0"><strong>Page:</strong> {$page}</p>
</div>
HTML);
    }
}
