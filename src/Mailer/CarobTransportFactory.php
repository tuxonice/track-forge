<?php

namespace App\Mailer;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * DSN: carob+api://API_TOKEN@HOST[:PORT][?http_scheme=https&path=/api/mailer/send]
 *
 * "path" defaults to "/api/mailer/send", matching Carob Mailer's Laravel
 * routing (routes/api.php registers "/mailer/send", and Laravel's
 * withRouting(api: ...) mounts api routes under "/api" by default) -
 * override it if a given instance is deployed differently (e.g. behind a
 * reverse proxy or subdomain that strips the "/api" prefix).
 *
 * Unlike Messenger's TransportFactoryInterface, Mailer's isn't
 * auto-tagged by FrameworkBundle (its built-in and vendor-bridge
 * factories are registered explicitly), so this needs the tag spelled
 * out here for mailer.transport_factory's tagged_iterator to find it.
 */
#[AutoconfigureTag('mailer.transport_factory')]
class CarobTransportFactory extends AbstractTransportFactory
{
    /** @return string[] */
    protected function getSupportedSchemes(): array
    {
        return ['carob+api'];
    }

    public function create(Dsn $dsn): TransportInterface
    {
        $scheme = $dsn->getOption('http_scheme', 'https');
        $host = $dsn->getHost();
        $port = $dsn->getPort();
        $path = $dsn->getOption('path', '/api/mailer/send');

        $endpoint = \sprintf('%s://%s%s%s', $scheme, $host, $port ? ':' . $port : '', $path);

        return new CarobTransport(
            $endpoint,
            $this->getUser($dsn),
            $this->client ?? HttpClient::create(),
            $this->dispatcher,
            $this->logger,
        );
    }
}
