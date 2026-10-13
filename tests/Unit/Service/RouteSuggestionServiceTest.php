<?php

namespace App\Tests\Unit\Service;

use App\Entity\Track;
use App\Entity\User;
use App\Exception\RouteSuggestionException;
use App\Gps\GpsTrack;
use App\Gps\Route\CrossingDetector;
use App\Gps\Route\RouteFinder;
use App\Gps\Route\TrackGraphBuilder;
use App\Service\FileUploaderService;
use App\Service\RouteSuggestionService;
use PHPUnit\Framework\TestCase;

class RouteSuggestionServiceTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../Fixtures/gpx/';

    private string $dir;
    private FileUploaderService $uploader;
    private User $user;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/tf-suggest-' . uniqid();
        $this->uploader = new FileUploaderService($this->dir);
        $this->user = new User('a@example.com');
    }

    protected function tearDown(): void
    {
        $this->uploader->deleteDirectory($this->dir);
    }

    private function service(int $maxTracks = 6): RouteSuggestionService
    {
        return new RouteSuggestionService(
            new GpsTrack(),
            $this->uploader,
            new CrossingDetector(25.0),
            new TrackGraphBuilder(),
            new RouteFinder(5, 100000),
            $maxTracks,
        );
    }

    private function track(string $name, string $contents): Track
    {
        $filename = uniqid() . '.gpx';
        $this->uploader->storeContents($this->user, $contents, $filename);

        return new Track($this->user, $name, $filename);
    }

    private function fixtureTrack(string $name, string $fixture): Track
    {
        return $this->track($name, (string) file_get_contents(self::FIXTURES . $fixture));
    }

    public function testCrossingTracksProduceRankedSuggestions(): void
    {
        $routes = $this->service()->suggest([
            $this->fixtureTrack('A', 'crossing-a.gpx'),
            $this->fixtureTrack('B', 'crossing-b.gpx'),
        ]);

        self::assertCount(4, $routes);
        self::assertGreaterThan($routes[3]->distance, $routes[0]->distance);
        self::assertSame([0, 1], $routes[0]->trackIndexes);
    }

    public function testTracksThatNeverCrossProduceNoSuggestions(): void
    {
        $routes = $this->service()->suggest([
            $this->fixtureTrack('A', 'crossing-a.gpx'),
            $this->fixtureTrack('Far', 'no-crossing.gpx'),
        ]);

        self::assertSame([], $routes);
    }

    public function testFewerThanTwoTracksIsRejected(): void
    {
        $this->expectException(RouteSuggestionException::class);
        $this->expectExceptionMessage('route.error.too_few_tracks');

        $this->service()->suggest([$this->fixtureTrack('A', 'crossing-a.gpx')]);
    }

    public function testMoreThanTheMaximumNumberOfTracksIsRejected(): void
    {
        $this->expectException(RouteSuggestionException::class);
        $this->expectExceptionMessage('route.error.too_many_tracks');

        $this->service(2)->suggest([
            $this->fixtureTrack('A', 'crossing-a.gpx'),
            $this->fixtureTrack('B', 'crossing-b.gpx'),
            $this->fixtureTrack('C', 'no-crossing.gpx'),
        ]);
    }

    public function testAMissingFileIsReportedWithTheTrackName(): void
    {
        $missing = new Track($this->user, 'Ghost', 'nope.gpx');

        try {
            $this->service()->suggest([$this->fixtureTrack('A', 'crossing-a.gpx'), $missing]);
            self::fail('Expected an exception');
        } catch (RouteSuggestionException $e) {
            self::assertSame('route.error.track_file_missing', $e->getMessage());
            self::assertSame(['%name%' => 'Ghost'], $e->getParameters());
        }
    }

    public function testACorruptStoredFileIsReportedAsUnreadable(): void
    {
        $corrupt = $this->track('Broken', "\x00\x01 definitely not gpx <<<");

        try {
            $this->service()->suggest([$this->fixtureTrack('A', 'crossing-a.gpx'), $corrupt]);
            self::fail('Expected an exception');
        } catch (RouteSuggestionException $e) {
            self::assertSame('route.error.track_unreadable', $e->getMessage());
            self::assertSame(['%name%' => 'Broken'], $e->getParameters());
        }
    }

    public function testATrackOverThePointCapIsRejected(): void
    {
        $points = '';
        for ($i = 0; $i <= 20000; $i++) {
            $points .= sprintf('<trkpt lat="0" lon="%.7f"><ele>0</ele></trkpt>', $i * 0.000001);
        }
        $big = $this->track('Big', '<?xml version="1.0"?><gpx version="1.1" creator="t" xmlns="http://www.topografix.com/GPX/1/1"><trk><trkseg>' . $points . '</trkseg></trk></gpx>');

        $this->expectException(RouteSuggestionException::class);
        $this->expectExceptionMessage('route.error.track_too_large');

        $this->service()->suggest([$this->fixtureTrack('A', 'crossing-a.gpx'), $big]);
    }

    public function testATrackWithFewerThanTwoDistinctPointsIsRejected(): void
    {
        $dot = $this->track('Dot', '<?xml version="1.0"?><gpx version="1.1" creator="t" xmlns="http://www.topografix.com/GPX/1/1"><trk><trkseg><trkpt lat="0" lon="0"/><trkpt lat="0" lon="0"/></trkseg></trk></gpx>');

        $this->expectException(RouteSuggestionException::class);
        $this->expectExceptionMessage('route.error.track_too_short');

        $this->service()->suggest([$this->fixtureTrack('A', 'crossing-a.gpx'), $dot]);
    }
}
