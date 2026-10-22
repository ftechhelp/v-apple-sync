<?php

declare(strict_types=1);

namespace OCA\AppleSync\Tests\Unit\Sync;

use OCA\AppleSync\Db\SyncState;
use OCA\AppleSync\ICloud\Drive\DriveClient;
use OCA\AppleSync\Sync\Engine\DriveSync;
use OCA\AppleSync\Sync\SyncBudget;
use OCA\AppleSync\Sync\SyncEngine;

class DriveSyncTest extends EngineTestCase {
	private string $notes;
	private string $todo;

	protected function setUp(): void {
		parent::setUp();
		$this->notes = $this->icloud->addFolder('notes', 'Notes');
		$deep = $this->icloud->addFolder('deep', 'Deep', $this->notes);
		$this->todo = $this->icloud->addFile('todo', 'todo', 'txt', 'buy milk', $this->notes);
		$this->icloud->addFile('readme', 'Read me', 'md', '# hello');
		$this->icloud->addFile('empty', 'empty', 'txt', '');
		$this->icloud->addFile('odd', 'bad:name', 'txt', 'x', $deep);
		$this->icloud->addFolder('pages', 'Pages', type: 'APP_LIBRARY');
	}

	protected function engine(): SyncEngine {
		$http = $this->http();
		return new DriveSync($this->accounts(), new DriveClient($http), $http, $this->files, $this->mirror(), $this->items, $this->time());
	}

	public function testDriveIsMirroredIntoTheTargetFolder(): void {
		$state = $this->state(SyncState::SERVICE_DRIVE);
		$this->assertTrue($this->sync($state));

		$this->assertSame([
			'/iCloud Drive/Notes/Deep/bad-name.txt',
			'/iCloud Drive/Notes/todo.txt',
			'/iCloud Drive/Read me.md',
			'/iCloud Drive/empty.txt',
		], $this->files->files('/iCloud Drive'));
		$this->assertSame('buy milk', $this->files->content('/iCloud Drive/Notes/todo.txt'));
		$this->assertSame(strtotime('2026-10-01T12:00:00Z'), $this->files->nodes['/iCloud Drive/Notes/todo.txt']['mtime']);
		$this->assertArrayHasKey('/iCloud Drive/Pages', $this->files->nodes, 'app folders are walked too');
		$this->assertSame(3, $this->requests('GET /content/'), 'empty files need no download');
		$this->assertSame(4, $this->items->countSynced(self::USER, SyncState::SERVICE_DRIVE), 'folders are not counted');
	}

	public function testSecondRunDownloadsNothing(): void {
		$state = $this->state(SyncState::SERVICE_DRIVE);
		$this->sync($state);
		$downloads = $this->files->downloads;

		$this->assertTrue($this->sync($state));
		$this->assertSame($downloads, $this->files->downloads);
	}

	public function testChangedFileIsDownloadedAgain(): void {
		$state = $this->state(SyncState::SERVICE_DRIVE);
		$this->sync($state);
		$this->icloud->changeFile($this->todo, 'buy oat milk');

		$this->sync($state);
		$this->assertSame('buy oat milk', $this->files->content('/iCloud Drive/Notes/todo.txt'));
	}

	public function testRenameAndMoveAreFollowedWithoutDownloading(): void {
		$state = $this->state(SyncState::SERVICE_DRIVE);
		$this->sync($state);
		$downloads = $this->files->downloads;

		$this->icloud->moveItem($this->todo, \OCA\AppleSync\Tests\Support\FakeICloud::DRIVE_ROOT, 'shopping');
		$this->icloud->moveItem($this->notes, \OCA\AppleSync\Tests\Support\FakeICloud::DRIVE_ROOT, 'Notizen');
		$this->sync($state);

		$this->assertSame($downloads, $this->files->downloads);
		$this->assertSame('buy milk', $this->files->content('/iCloud Drive/shopping.txt'));
		$this->assertSame('x', $this->files->content('/iCloud Drive/Notizen/Deep/bad-name.txt'));
		$this->assertArrayNotHasKey('/iCloud Drive/Notes', $this->files->nodes);
	}

	public function testDeletedFilesStayUnlessMirroring(): void {
		$state = $this->state(SyncState::SERVICE_DRIVE);
		$this->sync($state);
		$this->icloud->deleteItem($this->notes);
		$this->sync($state);
		$this->assertNotNull($this->files->content('/iCloud Drive/Notes/todo.txt'));
		$this->assertSame(0, count(array_filter($this->items->rows, static fn ($item): bool => str_contains($item->getRemoteId(), 'todo'))), 'but they are forgotten');

		$mirror = $this->state(SyncState::SERVICE_DRIVE, ['mirrorDeletions' => true], '/Mirror');
		$this->icloud->addFile('gone', 'gone', 'txt', 'soon gone');
		$this->sync($mirror);
		$this->icloud->deleteItem('FILE::com.apple.CloudDocs::gone');
		$this->sync($mirror);
		$this->assertNull($this->files->content('/Mirror/gone.txt'));
		$this->assertContains('/Mirror/gone.txt', $this->files->trash);
	}

	public function testNewTargetFolderMovesWhatIsThere(): void {
		$state = $this->state(SyncState::SERVICE_DRIVE);
		$this->sync($state);
		$downloads = $this->files->downloads;

		$state->setTarget('/Backups/iCloud');
		$state->setSyncCursor(null);
		$this->sync($state);
		$this->assertSame($downloads, $this->files->downloads);
		$this->assertSame('buy milk', $this->files->content('/Backups/iCloud/Notes/todo.txt'));
	}

	public function testWalkResumesAcrossRuns(): void {
		$state = $this->state(SyncState::SERVICE_DRIVE);
		$runs = 0;
		do {
			$runs++;
			$done = $this->sync($state, new SyncBudget(INF, 1));
		} while (!$done && $runs < 50);

		$this->assertTrue($done);
		$this->assertGreaterThanOrEqual(3, $runs, 'one download per run');
		$this->assertCount(4, $this->files->files('/iCloud Drive'));
		$this->assertSame(3, $this->requests('GET /content/'), 'nothing is downloaded twice');
	}

	public function testByteBudgetDefersTheNextFile(): void {
		$state = $this->state(SyncState::SERVICE_DRIVE);
		$this->assertFalse($this->sync($state, new SyncBudget(INF, 3)));
		$this->assertCount(1, $this->files->files('/iCloud Drive'), 'the first file always fits');
		$this->assertTrue($this->sync($state));
		$this->assertCount(4, $this->files->files('/iCloud Drive'));
	}
}
