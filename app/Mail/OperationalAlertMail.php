<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OperationalAlertMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public array $alert) {}

    public function envelope(): Envelope
    {
        $severity = strtoupper((string) ($this->alert['severity'] ?? 'WARNING'));
        $message = (string) ($this->alert['message'] ?? 'Operational alert');

        return new Envelope(subject: "[{$severity}] Social CORE: {$message}");
    }

    public function content(): Content
    {
        $service = e((string) ($this->alert['service'] ?? 'social-core-api'));
        $environment = e((string) ($this->alert['environment'] ?? 'unknown'));
        $eventType = e((string) ($this->alert['event_type'] ?? 'unknown'));
        $message = e((string) ($this->alert['message'] ?? 'Operational alert'));
        $occurredAt = e((string) ($this->alert['occurred_at'] ?? ''));
        $payload = e((string) json_encode($this->alert['payload'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return new Content(htmlString: <<<HTML
<div style="font-family:Inter,Arial,sans-serif;line-height:1.5;color:#111827;max-width:680px">
  <h1 style="font-size:20px;margin:0 0 12px">{$message}</h1>
  <p style="margin:0 0 16px;color:#4b5563">{$service} · {$environment} · {$occurredAt}</p>
  <p style="margin:0 0 8px"><strong>Event:</strong> {$eventType}</p>
  <pre style="white-space:pre-wrap;overflow-wrap:anywhere;background:#f3f4f6;border-radius:8px;padding:14px;font-size:12px">{$payload}</pre>
</div>
HTML);
    }
}
