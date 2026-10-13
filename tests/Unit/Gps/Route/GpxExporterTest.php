<?php

namespace App\Tests\Unit\Gps\Route;

use App\Gps\GpsTrack;
use App\Gps\Route\GpxExporter;
use App\Gps\Route\RoutePoint;
use App\Gps\Route\SuggestedRoute;
use App\Validator\XmlValidator;
use PHPUnit\Framework\TestCase;

class GpxExporterTest extends TestCase
{
    private function route(): SuggestedRoute
    {
        return new SuggestedRoute([
            new RoutePoint(0.0, 0.0, 5.0),
            new RoutePoint(0.0, 0.001, 15.0),
            new RoutePoint(0.0, 0.002, 10.0),
        ], 222.4, [0, 1]);
    }

    public function testExportedXmlValidatesAgainstTheGpxSchema(): void
    {
        $xml = (new GpxExporter())->export($this->route(), 'Suggested route');

        $schema = (string) file_get_contents(__DIR__ . '/../../../../src/Schemas/gpx.xsd');
        self::assertTrue((new XmlValidator())->isValid($xml, $schema));
    }

    public function testExportedFileParsesBackThroughGpsTrackWithTheSamePointsAndName(): void
    {
        $xml = (new GpxExporter())->export($this->route(), 'Suggested route');
        $path = tempnam(sys_get_temp_dir(), 'gpx');
        self::assertNotFalse($path);
        file_put_contents($path, $xml);

        try {
            $track = new GpsTrack();
            $track->process($path);
        } finally {
            unlink($path);
        }

        self::assertSame('Suggested route', $track->getName());
        $points = $track->getRawPoints();
        self::assertCount(3, $points);
        self::assertSame(0.002, $points[2]->lon);
        self::assertSame(10.0, $points[2]->ele);
        self::assertNull($track->getRecordedAt());
    }
}
