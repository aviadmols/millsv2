<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The OTP login code email (ARCHITECTURE.md §6). Platform-authored Blade — safe
 * to use Blade here (the strtr rule applies only to merchant-edited templates).
 */
class OtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $code,
        public int $ttlMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('otp.email.subject'));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.otp',
            with: ['code' => $this->code, 'ttlMinutes' => $this->ttlMinutes],
        );
    }
}
