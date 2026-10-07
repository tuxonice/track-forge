<?php

namespace App\Tests\Unit\Mailer;

use App\Mailer\CarobTransport;
use App\Mailer\CarobTransportFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\IncompleteDsnException;
use Symfony\Component\Mailer\Transport\Dsn;

class CarobTransportFactoryTest extends TestCase
{
    public function testSupportsOnlyTheCarobApiScheme(): void
    {
        $factory = new CarobTransportFactory();

        self::assertTrue($factory->supports(Dsn::fromString('carob+api://token@carob.example.com')));
        self::assertFalse($factory->supports(Dsn::fromString('smtp://mailpit:1025')));
    }

    public function testCreateBuildsTheDefaultEndpointFromHostAndPort(): void
    {
        $factory = new CarobTransportFactory();

        $transport = $factory->create(Dsn::fromString('carob+api://token@carob.example.com:8080'));

        self::assertInstanceOf(CarobTransport::class, $transport);
        self::assertSame('carob+api://https://carob.example.com:8080/api/mailer/send', (string) $transport);
    }

    public function testCreateHonorsHttpSchemeAndPathOptions(): void
    {
        $factory = new CarobTransportFactory();

        $transport = $factory->create(Dsn::fromString('carob+api://token@carob.local?http_scheme=http&path=/mailer/send'));

        self::assertSame('carob+api://http://carob.local/mailer/send', (string) $transport);
    }

    public function testCreateWithoutAUserThrows(): void
    {
        $factory = new CarobTransportFactory();

        $this->expectException(IncompleteDsnException::class);

        $factory->create(Dsn::fromString('carob+api://carob.example.com'));
    }
}
