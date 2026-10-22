<?php

declare(strict_types=1);

namespace OCA\AppleSync\Tests\Unit\Sync;

use OCA\AppleSync\Db\SyncState;
use OCA\AppleSync\ICloud\Photos\PhotosClient;
use OCA\AppleSync\Sync\Engine\PhotosSync;
use OCA\AppleSync\Sync\SyncBudget;
use OCA\AppleSync\Sync\SyncEngine;
use OCP\IConfig;

class PhotosSyncTest extends EngineTestCase {
	protected function setUp(): void {
		parent::setUp();
		$this->icloud->addPhoto('1', 'IMG_0001.HEIC', '2024-05-01T10:00:00Z', 'photo-1', 'video-1');
		$this->icloud->addPhoto('2', 'IMG_0002.HEIC', '2024-05-31T23:30:00Z', 'photo-2');
		$this->icloud->addPhoto('3', 'IMG_0001.HEIC', '2024-05-20T08:00:00Z', 'another camera');
	}

	protected function engine(): SyncEngine {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturn('Europe/Berlin');
		$http = $this->http();
		return new PhotosSync($this->accounts(), new PhotosClient($http), $http, $this->files, $this->mirror(), $this->items, $config, $this->time());
	}

	public function testLibraryIsImportedByMonthInTheUsersTimeZone(): void {
		$state = $this->state(SyncState::SERVICE_PHOTOS);
		$this->assertTrue($this->sync($state));

		$this->assertSame([
			'/iCloud Photos/2024/05/IMG_0001.HEIC',
			'/iCloud Photos/2024/05/IMG_0001.MOV',
			'/iCloud Photos/2024/05/IMG_0001_M3.HEIC',
			'/iCloud Photos/2024/06/IMG_0002.HEIC',
		], $this->files->files('/iCloud Photos'), '23:30 UTC on May 31 is June 1 in Berlin; same names get a suffix');
		$this->assertSame('video-1', $this->files->content('/iCloud Photos/2024/05/IMG_0001.MOV'));
		$this->assertSame(strtotime('2024-05-01T10:00:00Z'), $this->files->nodes['/iCloud Photos/2024/05/IMG_0001.HEIC']['mtime']);
	}

	public function testChangesAfterTheImportComeFromTheChangeFeed(): void {
		$state = $this->state(SyncState::SERVICE_PHOTOS);
		$this->sync($state);
		$queries = $this->requests('POST /ckdatabasews/database/1/com.apple.photos.cloud/production/private/records/query');

		$this->icloud->addPhoto('4', 'IMG_0004.JPG', '2025-01-02T12:00:00Z', 'photo-4');
		$this->assertTrue($this->sync($state));
		$this->assertSame('photo-4', $this->files->content('/iCloud Photos/2025/01/IMG_0004.JPG'));
		$this->assertSame($queries, $this->requests('POST /ckdatabasews/database/1/com.apple.photos.cloud/production/private/records/query'));
		$this->assertSame(5, $this->files->downloads, 'nothing else was downloaded again');
	}

	public function testDeletedPhotosStayUnlessMirroring(): void {
		$state = $this->state(SyncState::SERVICE_PHOTOS);
		$this->sync($state);
		$this->icloud->deletePhoto('2');
		$this->sync($state);
		$this->assertNotNull($this->files->content('/iCloud Photos/2024/06/IMG_0002.HEIC'));

		$mirror = $this->state(SyncState::SERVICE_PHOTOS, ['mirrorDeletions' => true], '/Mirror');
		$this->sync($mirror);
		$this->icloud->deletePhoto('1');
		$this->icloud->expungePhoto('3');
		$this->sync($mirror);
		$this->assertSame([], $this->files->files('/Mirror'));
		$this->assertContains('/Mirror/2024/05/IMG_0001.MOV', $this->files->trash, 'the Live Photo video goes too');
	}

	public function testExpiredSyncTokenFallsBackToAFullPassWithoutDownloads(): void {
		$state = $this->state(SyncState::SERVICE_PHOTOS);
		$this->sync($state);
		$downloads = $this->files->downloads;

		$query = 'POST /ckdatabasews/database/1/com.apple.photos.cloud/production/private/records/query';
		$queries = $this->requests($query);

		$this->icloud->expireSyncTokens();
		$this->assertTrue($this->sync($state));
		$this->assertGreaterThan($queries, $this->requests($query), 'the library was listed again');
		$this->assertSame($downloads, $this->files->downloads);
		$this->assertSame('changes', $state->getCursorArray()['mode']);
	}

	public function testExistingFilesAreTakenOverInsteadOfDownloaded(): void {
		$this->files->write(self::USER, '/iCloud Photos/2024/06/IMG_0002.HEIC', 'photo-2');
		$this->sync($this->state(SyncState::SERVICE_PHOTOS));
		$this->assertSame(4, $this->files->downloads, 'one write by the test, three by the sync');
		$this->assertNotContains('/iCloud Photos/2024/06/IMG_0002_M2.HEIC', $this->files->files('/iCloud Photos'));
	}

	public function testImportResumesAcrossRuns(): void {
		for ($i = 10; $i < 40; $i++) {
			$this->icloud->addPhoto((string)$i, "IMG_00$i.JPG", '2023-02-01T00:00:00Z', "photo-$i");
		}
		$state = $this->state(SyncState::SERVICE_PHOTOS);
		$runs = 0;
		do {
			$runs++;
			$done = $this->sync($state, new SyncBudget(INF, 10));
		} while (!$done && $runs < 100);

		$this->assertTrue($done);
		$this->assertGreaterThan(10, $runs);
		$this->assertCount(34, $this->files->files('/iCloud Photos'));
		$this->assertSame(34, $this->requests('GET /content/'), 'each file is downloaded once');
	}
}
