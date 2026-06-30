<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WorkspaceInvitationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $workspaceName,
        public string $role,
        public string $inviteUrl,
        public string $expiresAt
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You have been invited to Social CORE'
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
        $inviteUrl = e($this->inviteUrl);
        $expiresAt = e($this->expiresAt);

        return <<<HTML
<div style="font-family:Inter,Arial,sans-serif;line-height:1.55;color:#111827;max-width:560px">
  <h1 style="font-size:22px;margin:0 0 12px">You have been invited to Social CORE</h1>
  <p style="margin:0 0 16px">You were invited to join <strong>{$workspaceName}</strong> as <strong>{$role}</strong>.</p>
  <p style="margin:0 0 20px">Use the same email address this invitation was sent to. Your workspace access will be applied automatically after you sign up or sign in.</p>
  <p style="margin:0 0 24px">
    <a href="{$inviteUrl}" style="display:inline-block;background:#111827;color:#ffffff;text-decoration:none;border-radius:8px;padding:10px 16px;font-weight:600">Open invitation</a>
  </p>
  <p style="font-size:13px;color:#6b7280;margin:0">This invitation expires {$expiresAt}.</p>
</div>
HTML;
    }
}
