<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WorkspaceAccessGrantedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $workspaceName,
        public string $role,
        public string $workspaceUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You now have access to '.$this->workspaceName.' in Social CORE'
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->htmlBody(),
            text: null
        );
    }

    private function htmlBody(): string
    {
        $workspaceName = e($this->workspaceName);
        $role = e($this->role);
        $workspaceUrl = e($this->workspaceUrl);

        return <<<HTML
<div style="font-family:Inter,Arial,sans-serif;line-height:1.55;color:#111827;max-width:560px">
  <h1 style="font-size:22px;margin:0 0 12px">You now have access to {$workspaceName}</h1>
  <p style="margin:0 0 16px">A workspace owner added your existing Social CORE account as <strong>{$role}</strong>.</p>
  <p style="margin:0 0 24px">
    <a href="{$workspaceUrl}" style="display:inline-block;background:#111827;color:#ffffff;text-decoration:none;border-radius:8px;padding:10px 16px;font-weight:600">Open workspace</a>
  </p>
  <p style="font-size:13px;color:#6b7280;margin:0">Sign in with the email address that received this message. The workspace will be available in your workspace switcher.</p>
</div>
HTML;
    }
}
