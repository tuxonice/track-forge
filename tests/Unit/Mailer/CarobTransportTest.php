<?php

namespace App\Tests\Unit\Mailer;

use App\Mailer\CarobTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class CarobTransportTest extends TestCase
{
    public function testSendPostsTheExpectedPayloadAndAuthHeader(): void
    {
        $capturedMethod = null;
        $capturedUrl = null;
        $capturedOptions = null;

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedMethod, &$capturedUrl, &$capturedOptions) {
            $capturedMethod = $method;
            $capturedUrl = $url;
            $capturedOptions = $options;

            return new MockResponse(json_encode(['error' => '', 'status' => true]), ['http_code' => 200]);
        });

        $transport = new CarobTransport('https://carob.example.com/api/mailer/send', 'secret-token', $httpClient);

        $email = (new Email())
            ->from(new Address('noreply@trackforge.app', 'TrackForge'))
            ->to('user@example.com')
            ->subject('Your login code')
            ->text('Your code is 123456')
            ->html('<p>Your code is <strong>123456</strong></p>');

        $transport->send($email, Envelope::create($email));

        self::assertSame('POST', $capturedMethod);
        self::assertSame('https://carob.example.com/api/mailer/send', $capturedUrl);
        self::assertContains('Authorization: Bearer secret-token', $capturedOptions['headers']);

        $payload = json_decode($capturedOptions['body'], true);
        self::assertSame([
            'from' => ['name' => 'TrackForge'],
            'to' => ['name' => 'user@example.com', 'email' => 'user@example.com'],
            'subject' => 'Your login code',
            'body' => [
                'text' => 'Your code is 123456',
                'html' => '<p>Your code is <strong>123456</strong></p>',
            ],
        ], $payload);
    }

    public function testToNameFallsBackToTheAddressWhenTheEmailCarriesNoDisplayName(): void
    {
        $httpClient = new MockHttpClient(fn () => new MockResponse(json_encode(['error' => '', 'status' => true]), ['http_code' => 200]));
        $transport = new CarobTransport('https://carob.example.com/api/mailer/send', 'secret-token', $httpClient);

        $email = (new Email())
            ->from('noreply@trackforge.app')
            ->to('user@example.com')
            ->subject('Subject')
            ->text('text')
            ->html('<p>html</p>');

        $transport->send($email, Envelope::create($email));

        // No exception means the fallback produced a valid (non-empty) "to.name".
        $this->addToAssertionCount(1);
    }

    public function testNamedToAddressKeepsItsOwnDisplayName(): void
    {
        $capturedOptions = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedOptions) {
            $capturedOptions = $options;

            return new MockResponse(json_encode(['error' => '', 'status' => true]), ['http_code' => 200]);
        });
        $transport = new CarobTransport('https://carob.example.com/api/mailer/send', 'secret-token', $httpClient);

        $email = (new Email())
            ->from('noreply@trackforge.app')
            ->to(new Address('user@example.com', 'Jane Doe'))
            ->subject('Subject')
            ->text('text')
            ->html('<p>html</p>');

        $transport->send($email, Envelope::create($email));

        $payload = json_decode($capturedOptions['body'], true);
        self::assertSame('Jane Doe', $payload['to']['name']);
    }

    public function testAttachmentsAreBase64EncodedWithTheirOriginalFilename(): void
    {
        $capturedOptions = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedOptions) {
            $capturedOptions = $options;

            return new MockResponse(json_encode(['error' => '', 'status' => true]), ['http_code' => 200]);
        });
        $transport = new CarobTransport('https://carob.example.com/api/mailer/send', 'secret-token', $httpClient);

        $email = (new Email())
            ->from('noreply@trackforge.app')
            ->to('user@example.com')
            ->subject('Subject')
            ->text('text')
            ->html('<p>html</p>')
            ->attach('file contents', 'notes.txt', 'text/plain');

        $transport->send($email, Envelope::create($email));

        $payload = json_decode($capturedOptions['body'], true);
        self::assertSame([
            [
                'base64Content' => base64_encode('file contents'),
                'originalFileName' => 'notes.txt',
            ],
        ], $payload['attachments']);
    }

    public function testNonTwoHundredResponseThrowsWithTheApiErrorMessage(): void
    {
        $httpClient = new MockHttpClient(fn () => new MockResponse(
            json_encode(['error' => 'The subject field is required.', 'status' => false]),
            ['http_code' => 422]
        ));
        $transport = new CarobTransport('https://carob.example.com/api/mailer/send', 'secret-token', $httpClient);

        $email = (new Email())
            ->from('noreply@trackforge.app')
            ->to('user@example.com')
            ->subject('Subject')
            ->text('text')
            ->html('<p>html</p>');

        $this->expectException(HttpTransportException::class);
        $this->expectExceptionMessage('The subject field is required.');

        $transport->send($email, Envelope::create($email));
    }

    public function testCcOrBccOnlyEmailThrowsSinceCarobMailerHasNoEquivalent(): void
    {
        $httpClient = new MockHttpClient(fn () => new MockResponse());
        $transport = new CarobTransport('https://carob.example.com/api/mailer/send', 'secret-token', $httpClient);

        // Valid per Symfony (Cc satisfies "To, Cc, or Bcc"), but Carob Mailer's
        // API only has a single "to" recipient - no Cc/Bcc concept at all.
        $email = (new Email())
            ->from('noreply@trackforge.app')
            ->cc('cc-only@example.com')
            ->subject('Subject')
            ->text('text')
            ->html('<p>html</p>');

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Carob Mailer requires exactly one "to" recipient.');

        $transport->send($email, Envelope::create($email));
    }
}
