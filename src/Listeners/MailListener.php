<?php

namespace Uptimex\Client\Listeners;

use Illuminate\Mail\Events\MessageSent;
use Symfony\Component\Mime\Address;
use Throwable;
use Uptimex\Client\Uptimex;

/**
 * Records `events_mail` rows from `Illuminate\Mail\Events\MessageSent`. The
 * mailable class is captured directly when present (Laravel 11.27+); older
 * versions fall back to "(unknown)".
 */
final class MailListener
{
    public function __construct(private readonly Uptimex $uptimex) {}

    public function handle(MessageSent $event): void
    {
        if (! $this->uptimex->isEnabled() || $this->uptimex->context() === null) {
            return;
        }

        // Laravel's `SentMessage` proxies to the Symfony `SentMessage` via
        // `__call`, which forwards `getOriginalMessage()` and other accessors.
        // We can't use `method_exists()` here (proxy methods aren't declared)
        // — call directly inside try/catch, with a property fallback for
        // older Laravel versions that exposed `$event->sent->message`.
        try {
            $email = $event->sent->getOriginalMessage();
        } catch (Throwable) {
            $email = $event->sent->message ?? null;
        }

        if ($email === null) {
            return;
        }

        $this->uptimex->record('mail', [
            'mailable_class' => $this->extractMailableClass($event, $email),
            'subject' => mb_substr((string) $email->getSubject(), 0, 255),
            'to' => $this->addressList($email->getTo() ?? []),
            'cc' => $this->addressList($email->getCc() ?? []),
            'bcc' => $this->addressList($email->getBcc() ?? []),
            'driver' => $event->data['transport'] ?? null,
            'attachments_count' => count($email->getAttachments() ?? []),
            'status' => 'sent',
        ]);
    }

    /**
     * Laravel 11.27+ writes the mailable class on the message via header
     * `X-Mailable-Class` (best-effort). Other versions stash it in
     * `$event->data['mailable']`. Fall back to "(unknown)" when neither.
     */
    private function extractMailableClass(MessageSent $event, mixed $email): string
    {
        if (isset($event->data['mailable']) && is_object($event->data['mailable'])) {
            return $event->data['mailable']::class;
        }

        if (is_object($email) && method_exists($email, 'getHeaders')) {
            $headers = $email->getHeaders();
            if ($headers->has('X-Mailable-Class')) {
                return (string) $headers->get('X-Mailable-Class')->getBodyAsString();
            }
        }

        return '(unknown)';
    }

    /**
     * @param  iterable<Address>  $addresses
     * @return list<string>
     */
    private function addressList(iterable $addresses): array
    {
        $out = [];
        foreach ($addresses as $address) {
            $out[] = $address->getAddress();
        }

        return $out;
    }
}
