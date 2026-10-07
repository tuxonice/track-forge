<?php

namespace App\Mailer;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sends email through a Carob Mailer instance's HTTP API
 * (https://github.com/tuxonice/carob-mailer).
 *
 * The API has no "from" email field, only a display name - the sending
 * address is whatever the Carob Mailer instance itself is configured with,
 * so the Symfony Email's "from" address is ignored beyond its display name.
 * "to.name" is required by the API; when the Email's "to" address carries no
 * display name, the recipient's own email address is sent as the name.
 */
class CarobTransport extends AbstractTransport
{
    public function __construct(
        private readonly string $endpoint,
        #[\SensitiveParameter] private readonly string $apiToken,
        private readonly HttpClientInterface $client,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($dispatcher, $logger);
    }

    public function __toString(): string
    {
        return 'carob+api://' . $this->endpoint;
    }

    protected function doSend(SentMessage $message): void
    {
        $email = $message->getOriginalMessage();
        if (!$email instanceof Email) {
            throw new TransportException(\sprintf('"%s" only supports "%s" instances, "%s" given.', self::class, Email::class, get_debug_type($email)));
        }

        $to = $email->getTo()[0] ?? throw new TransportException('Carob Mailer requires exactly one "to" recipient.');
        $from = $email->getFrom()[0] ?? null;

        $payload = [
            'from' => [
                'name' => $from?->getName() ?? '',
            ],
            'to' => [
                'name' => '' !== $to->getName() ? $to->getName() : $to->getAddress(),
                'email' => $to->getAddress(),
            ],
            'subject' => $email->getSubject() ?? '',
            'body' => [
                'text' => $email->getTextBody() ?? '',
                'html' => $email->getHtmlBody() ?? '',
            ],
        ];

        $attachments = $this->buildAttachments($email);
        if ($attachments) {
            $payload['attachments'] = $attachments;
        }

        try {
            $response = $this->client->request('POST', $this->endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiToken,
                    'Accept' => 'application/json',
                ],
                'json' => $payload,
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (HttpExceptionInterface $e) {
            throw new TransportException(\sprintf('Could not reach Carob Mailer: %s', $e->getMessage()), 0, $e);
        }

        if ($statusCode >= 300) {
            $error = json_decode($content, true)['error'] ?? null;
            throw new HttpTransportException(\sprintf('Carob Mailer returned status %d%s.', $statusCode, $error ? ": $error" : ''), $response, $statusCode);
        }
    }

    /** @return list<array{base64Content: string, originalFileName: string}> */
    private function buildAttachments(Email $email): array
    {
        $attachments = [];
        foreach ($email->getAttachments() as $attachment) {
            if (!$attachment instanceof DataPart) {
                continue;
            }

            $attachments[] = [
                'base64Content' => base64_encode($attachment->getBody()),
                'originalFileName' => $attachment->getFilename() ?? 'attachment',
            ];
        }

        return $attachments;
    }
}
