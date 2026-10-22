<?php

declare(strict_types=1);

namespace OCA\AppleSync\Tests\Support;

/**
 * An in-memory iCloud for tests: CardDAV, CalDAV, iCloud Drive (drivews,
 * docws) and iCloud Photos (CloudKit), plus the content servers. The unit
 * tests call handle() directly; the e2e stack serves it with `php -S`
 * (tests/e2e/fake-apple.php) and keeps the state in a JSON file.
 *
 * URL layout below $base: /carddav, /caldav, /drivews, /docws, /ckdatabasews, /content.
 * The CalDAV entry point answers with a redirect, like Apple's partition hosts.
 * DAV requests need Basic auth with any Apple ID and APP_PASSWORD.
 */
final class FakeICloud {
	public const DSID = '1000000001';
	public const APP_PASSWORD = 'abcd-efgh-ijkl-mnop';
	public const DRIVE_ROOT = 'FOLDER::com.apple.CloudDocs::root';
	private const PHOTOS_DB = '/ckdatabasews/database/1/com.apple.photos.cloud/production/private';

	/** @var array<string, string> uid => vCard */
	public array $contacts = [];
	/** @var array<string, array{name: string, color: string, events: array<string, string>}> */
	public array $calendars = [];
	/** @var array<string, array> drivewsid => item */
	public array $drive = [];
	/** @var array<string, array> photo id => photo */
	public array $photos = [];
	/** @var array<string, int> record name => change version of its deletion */
	public array $tombstones = [];
	public int $version = 1;
	/** Sync tokens older than this are refused, as when CloudKit expires them. */
	public int $oldestToken = 0;
	/** @var list<string> "METHOD /path" of each request */
	public array $log = [];

	public function __construct(
		public string $base,
	) {
		$this->drive[self::DRIVE_ROOT] = ['type' => 'FOLDER', 'name' => 'root', 'parent' => null, 'etag' => 'root'];
	}

	// ---------------------------------------------------------------- data

	public function addContact(string $uid, string $name, array $extra = []): void {
		$lines = ['BEGIN:VCARD', 'VERSION:3.0', 'PRODID:-//Apple Inc.//iCloud Web Address Book//EN', "N:$name;;;;", "FN:$name", "UID:$uid", ...$extra, 'END:VCARD'];
		$this->contacts[$uid] = implode("\r\n", $lines) . "\r\n";
	}

	/**
	 * @param list<string> $memberUids
	 */
	public function addGroup(string $uid, string $name, array $memberUids): void {
		$members = array_map(static fn (string $member): string => "X-ADDRESSBOOKSERVER-MEMBER:urn:uuid:$member", $memberUids);
		$this->addContact($uid, $name, ['X-ADDRESSBOOKSERVER-KIND:group', ...$members]);
	}

	public function addCalendar(string $id, string $name, string $color = '#1BADF8FF'): void {
		$this->calendars[$id] = ['name' => $name, 'color' => $color, 'events' => []];
	}

	public function addEvent(string $calendar, string $uid, string $summary, string $start = '20261101T100000Z'): void {
		$this->calendars[$calendar]['events'][$uid] = implode("\r\n", [
			'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Apple Inc.//iCloud//EN', 'BEGIN:VEVENT',
			"UID:$uid", 'DTSTAMP:20261020T000000Z', "DTSTART:$start", 'DURATION:PT1H', "SUMMARY:$summary",
			'END:VEVENT', 'END:VCALENDAR',
		]) . "\r\n";
	}

	public function addFolder(string $id, string $name, string $parent = self::DRIVE_ROOT, string $type = 'FOLDER'): string {
		$drivewsid = 'FOLDER::com.apple.CloudDocs::' . $id;
		$this->drive[$drivewsid] = ['type' => $type, 'name' => $name, 'parent' => $parent, 'etag' => 'e1', 'docwsid' => $id];
		return $drivewsid;
	}

	public function addFile(string $id, string $name, string $extension, string $content, string $parent = self::DRIVE_ROOT): string {
		$drivewsid = 'FILE::com.apple.CloudDocs::' . $id;
		$this->drive[$drivewsid] = [
			'type' => 'FILE', 'name' => $name, 'extension' => $extension, 'parent' => $parent, 'docwsid' => $id,
			'content' => $content, 'etag' => substr(md5($content), 0, 8), 'dateModified' => '2026-10-01T12:00:00Z',
		];
		return $drivewsid;
	}

	public function changeFile(string $drivewsid, string $content): void {
		$this->drive[$drivewsid]['content'] = $content;
		$this->drive[$drivewsid]['etag'] = substr(md5($content), 0, 8);
	}

	public function moveItem(string $drivewsid, string $parent, ?string $name = null): void {
		$this->drive[$drivewsid]['parent'] = $parent;
		if ($name !== null) {
			$this->drive[$drivewsid]['name'] = $name;
		}
	}

	public function deleteItem(string $drivewsid): void {
		foreach ($this->drive as $id => $item) {
			if (($item['parent'] ?? null) === $drivewsid) {
				$this->deleteItem($id);
			}
		}
		unset($this->drive[$drivewsid]);
	}

	/**
	 * @param string $date ISO date the photo was taken
	 */
	public function addPhoto(string $id, string $fileName, string $date, string $content, ?string $video = null): void {
		$this->photos[$id] = [
			'fileName' => $fileName, 'date' => strtotime($date) * 1000, 'content' => $content, 'video' => $video,
			'deleted' => false, 'hidden' => false, 'changedAt' => ++$this->version,
		];
	}

	/** Move to Recently Deleted (the asset is updated with isDeleted=1). */
	public function deletePhoto(string $id): void {
		$this->photos[$id]['deleted'] = true;
		$this->photos[$id]['changedAt'] = ++$this->version;
	}

	/** Gone for good: CloudKit reports tombstones. */
	public function expungePhoto(string $id): void {
		unset($this->photos[$id]);
		$this->tombstones['A-' . $id] = ++$this->version;
		$this->tombstones['M-' . $id] = $this->version;
	}

	public function expireSyncTokens(): void {
		$this->oldestToken = ++$this->version; // tokens handed out from now on stay valid
	}

	public function toArray(): array {
		return get_object_vars($this);
	}

	public static function fromArray(array $data): self {
		$fake = new self((string)$data['base']);
		foreach ($data as $key => $value) {
			$fake->{$key} = $value;
		}
		return $fake;
	}

	// ---------------------------------------------------------------- HTTP

	/**
	 * @param array<string, string> $headers lower-case names
	 * @return array{0: int, 1: array<string, string>, 2: string}
	 */
	public function handle(string $method, string $path, array $query, array $headers, string $body): array {
		$this->log[] = "$method $path";
		if (str_starts_with($path, '/carddav') || str_starts_with($path, '/caldav')) {
			if (!$this->authorized($headers['authorization'] ?? '')) {
				return [401, ['WWW-Authenticate' => 'Basic realm="iCloud"'], ''];
			}
			return str_starts_with($path, '/carddav') ? $this->cardDav($method, $path, $body) : $this->calDav($method, $path, $body);
		}
		if ($method === 'POST' && $path === '/drivews/retrieveItemDetailsInFolders') {
			return self::json($this->driveFolders(json_decode($body, true) ?? []));
		}
		if ($method === 'GET' && preg_match('#^/docws/ws/[^/]+/download/by_id$#', $path)) {
			$id = 'FILE::com.apple.CloudDocs::' . ($query['document_id'] ?? '');
			return isset($this->drive[$id])
				? self::json(['data_token' => ['url' => $this->base . '/content/drive/' . rawurlencode($query['document_id'])]])
				: self::json(['error' => 'not found'], 404);
		}
		if ($method === 'GET' && preg_match('#^/content/drive/(.+)$#', $path, $m)) {
			$item = $this->drive['FILE::com.apple.CloudDocs::' . rawurldecode($m[1])] ?? null;
			return $item === null ? [404, [], ''] : [200, ['Content-Type' => 'application/octet-stream'], $item['content']];
		}
		if ($method === 'GET' && preg_match('#^/content/photos/([^/]+)/(original|video)$#', $path, $m)) {
			$photo = $this->photos[$m[1]] ?? null;
			$content = $photo === null ? null : ($m[2] === 'original' ? $photo['content'] : $photo['video']);
			return $content === null ? [404, [], ''] : [200, ['Content-Type' => 'application/octet-stream'], $content];
		}
		if ($method === 'POST' && str_starts_with($path, self::PHOTOS_DB . '/')) {
			return $this->cloudKit(substr($path, strlen(self::PHOTOS_DB)), json_decode($body, true) ?? []);
		}
		return [404, [], "fake iCloud: no route for $method $path"];
	}

	private function authorized(string $header): bool {
		$decoded = base64_decode(preg_replace('/^Basic\s+/i', '', $header) ?? '', true);
		return $decoded !== false && str_ends_with($decoded, ':' . self::APP_PASSWORD);
	}

	// ---------------------------------------------------------------- CardDAV

	private function cardDav(string $method, string $path, string $body): array {
		$home = '/carddav/' . self::DSID . '/carddavhome/';
		$book = $home . 'card/';
		if ($method === 'PROPFIND' && $path === '/carddav/') {
			return self::multiStatus([self::response('/carddav/', '<d:current-user-principal><d:href>/carddav/' . self::DSID . '/principal/</d:href></d:current-user-principal>')]);
		}
		if ($method === 'PROPFIND' && $path === '/carddav/' . self::DSID . '/principal/') {
			return self::multiStatus([self::response($path, '<card:addressbook-home-set><d:href>' . $this->base . $home . '</d:href></card:addressbook-home-set>')]);
		}
		if ($method === 'PROPFIND' && $path === $home) {
			return self::multiStatus([
				self::response($home, '<d:resourcetype><d:collection/></d:resourcetype>'),
				self::response($book, '<d:resourcetype><d:collection/><card:addressbook/></d:resourcetype><d:displayname>Card</d:displayname><cs:getctag>' . $this->contactsCtag() . '</cs:getctag>'),
			]);
		}
		if ($method === 'PROPFIND' && $path === $book) {
			$responses = [self::response($book, '<d:resourcetype><d:collection/><card:addressbook/></d:resourcetype>')];
			foreach ($this->contacts as $uid => $card) {
				$responses[] = self::response($book . $uid . '.vcf', '<d:getetag>"' . md5($card) . '"</d:getetag><d:resourcetype/>');
			}
			return self::multiStatus($responses);
		}
		if ($method === 'REPORT' && $path === $book) {
			$responses = [];
			foreach (self::hrefs($body) as $href) {
				$card = $this->contacts[basename($href, '.vcf')] ?? null;
				$responses[] = $card === null
					? '<d:response><d:href>' . $href . '</d:href><d:status>HTTP/1.1 404 Not Found</d:status></d:response>'
					: self::response($href, '<d:getetag>"' . md5($card) . '"</d:getetag><card:address-data>' . htmlspecialchars($card, ENT_XML1) . '</card:address-data>');
			}
			return self::multiStatus($responses);
		}
		return [404, [], ''];
	}

	private function contactsCtag(): string {
		return 'ctag-' . md5(implode('', array_map('md5', $this->contacts)));
	}

	// ---------------------------------------------------------------- CalDAV

	private function calDav(string $method, string $path, string $body): array {
		$home = '/caldav/' . self::DSID . '/calendars/';
		if ($method === 'PROPFIND' && $path === '/caldav/') {
			return [301, ['Location' => $this->base . '/caldav/p01/'], ''];
		}
		if ($method === 'PROPFIND' && $path === '/caldav/p01/') {
			return self::multiStatus([self::response($path, '<d:current-user-principal><d:href>/caldav/' . self::DSID . '/principal/</d:href></d:current-user-principal>')]);
		}
		if ($method === 'PROPFIND' && $path === '/caldav/' . self::DSID . '/principal/') {
			return self::multiStatus([self::response($path, '<cal:calendar-home-set><d:href>' . $this->base . $home . '</d:href></cal:calendar-home-set>')]);
		}
		if ($method === 'PROPFIND' && $path === $home) {
			$responses = [
				self::response($home, '<d:resourcetype><d:collection/></d:resourcetype>'),
				self::response($home . 'inbox/', '<d:resourcetype><d:collection/><cal:schedule-inbox/></d:resourcetype>'),
				self::response($home . 'tasks/', '<d:resourcetype><d:collection/><cal:calendar/></d:resourcetype><d:displayname>Reminders</d:displayname><cal:supported-calendar-component-set><cal:comp name="VTODO"/></cal:supported-calendar-component-set>'),
			];
			foreach ($this->calendars as $id => $calendar) {
				$responses[] = self::response($home . $id . '/', '<d:resourcetype><d:collection/><cal:calendar/></d:resourcetype>'
					. '<d:displayname>' . htmlspecialchars($calendar['name'], ENT_XML1) . '</d:displayname>'
					. '<cs:getctag>ctag-' . md5(implode('', array_map('md5', $calendar['events']))) . '</cs:getctag>'
					. '<cal:supported-calendar-component-set><cal:comp name="VEVENT"/></cal:supported-calendar-component-set>'
					. '<ical:calendar-color>' . $calendar['color'] . '</ical:calendar-color>');
			}
			return self::multiStatus($responses);
		}
		if (preg_match('#^' . preg_quote($home, '#') . '([^/]+)/$#', $path, $m) && isset($this->calendars[$m[1]])) {
			$events = $this->calendars[$m[1]]['events'];
			$responses = [];
			if ($method === 'PROPFIND') {
				$responses[] = self::response($path, '<d:resourcetype><d:collection/><cal:calendar/></d:resourcetype>');
				foreach ($events as $uid => $ics) {
					$responses[] = self::response($path . $uid . '.ics', '<d:getetag>"' . md5($ics) . '"</d:getetag><d:resourcetype/>');
				}
				return self::multiStatus($responses);
			}
			if ($method === 'REPORT') {
				foreach (self::hrefs($body) as $href) {
					$ics = $events[basename($href, '.ics')] ?? null;
					if ($ics !== null) {
						$responses[] = self::response($href, '<d:getetag>"' . md5($ics) . '"</d:getetag><cal:calendar-data>' . htmlspecialchars($ics, ENT_XML1) . '</cal:calendar-data>');
					}
				}
				return self::multiStatus($responses);
			}
		}
		return [404, [], ''];
	}

	/**
	 * @param list<string> $responses
	 */
	private static function multiStatus(array $responses): array {
		return [207, ['Content-Type' => 'application/xml; charset=utf-8'],
			'<?xml version="1.0" encoding="UTF-8"?><d:multistatus xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav" '
			. 'xmlns:cal="urn:ietf:params:xml:ns:caldav" xmlns:cs="http://calendarserver.org/ns/" xmlns:ical="http://apple.com/ns/ical/">'
			. implode('', $responses) . '</d:multistatus>'];
	}

	private static function response(string $href, string $props): string {
		return '<d:response><d:href>' . $href . '</d:href><d:propstat><d:prop>' . $props . '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>';
	}

	/**
	 * @return list<string>
	 */
	private static function hrefs(string $body): array {
		preg_match_all('#<d:href>([^<]+)</d:href>#', $body, $m);
		return array_map(static fn (string $href): string => html_entity_decode($href, ENT_XML1), $m[1]);
	}

	// ---------------------------------------------------------------- Drive

	private function driveFolders(array $request): array {
		$folders = [];
		foreach ($request as $entry) {
			$id = (string)($entry['drivewsid'] ?? '');
			$folder = $this->drive[$id] ?? null;
			if ($folder === null || $folder['type'] === 'FILE') {
				$folders[] = ['drivewsid' => $id, 'status' => 'ID_INVALID'];
				continue;
			}
			$items = [];
			foreach ($this->drive as $childId => $child) {
				if (($child['parent'] ?? null) === $id) {
					$items[] = $this->driveItem($childId, $child);
				}
			}
			$folders[] = $this->driveItem($id, $folder) + ['items' => $items, 'numberOfItems' => count($items)];
		}
		return $folders;
	}

	private function driveItem(string $id, array $item): array {
		$data = [
			'drivewsid' => $id, 'docwsid' => $item['docwsid'] ?? 'root', 'zone' => 'com.apple.CloudDocs',
			'name' => $item['name'], 'etag' => $item['etag'], 'type' => $item['type'],
		];
		if ($item['type'] === 'FILE') {
			$data += ['extension' => $item['extension'], 'size' => strlen($item['content']), 'dateModified' => $item['dateModified']];
		}
		return $data;
	}

	// ---------------------------------------------------------------- Photos

	private function cloudKit(string $operation, array $request): array {
		switch ($operation) {
			case '/records/query':
				$type = $request['query']['recordType'] ?? '';
				if ($type === 'CheckIndexingState') {
					return self::json(['records' => [['recordName' => 'state', 'recordType' => 'CheckIndexingState', 'fields' => ['state' => ['value' => 'FINISHED', 'type' => 'STRING']]]]]);
				}
				$rank = 0;
				foreach ($request['query']['filterBy'] ?? [] as $filter) {
					if ($filter['fieldName'] === 'startRank') {
						$rank = (int)$filter['fieldValue']['value'];
					}
				}
				$visible = array_filter($this->photos, static fn (array $photo): bool => !$photo['deleted'] && !$photo['hidden']);
				uasort($visible, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);
				$records = [];
				foreach (array_slice($visible, $rank, intdiv((int)($request['resultsLimit'] ?? 200), 2), true) as $id => $photo) {
					$records[] = $this->assetRecord((string)$id, $photo);
					$records[] = $this->masterRecord((string)$id, $photo);
				}
				return self::json(['records' => $records, 'syncToken' => 'v' . $this->version]);

			case '/changes/zone':
				$zone = $request['zones'][0] ?? [];
				$since = isset($zone['syncToken']) ? (int)substr((string)$zone['syncToken'], 1) : 0;
				if (isset($zone['syncToken']) && $since < $this->oldestToken) {
					return self::json(['zones' => [['zoneID' => $zone['zoneID'] ?? [], 'serverErrorCode' => 'BAD_REQUEST', 'reason' => 'Unknown sync continuation type']]]);
				}
				$changes = [];
				foreach ($this->photos as $id => $photo) {
					if ($photo['changedAt'] > $since) {
						$changes[] = [$photo['changedAt'], $this->assetRecord((string)$id, $photo)];
						$changes[] = [$photo['changedAt'], $this->masterRecord((string)$id, $photo)];
					}
				}
				foreach ($this->tombstones as $name => $at) {
					if ($at > $since) {
						$changes[] = [$at, ['recordName' => $name, 'deleted' => true]];
					}
				}
				usort($changes, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
				$limit = (int)($request['resultsLimit'] ?? 200);
				$page = array_slice($changes, 0, $limit);
				$more = count($changes) > $limit;
				$token = $more ? end($page)[0] : $this->version;
				return self::json(['zones' => [[
					'zoneID' => $zone['zoneID'] ?? [],
					'records' => array_column($page, 1),
					'syncToken' => 'v' . $token,
					'moreComing' => $more,
				]]]);

			case '/records/lookup':
				$records = [];
				foreach ($request['records'] ?? [] as $wanted) {
					$name = (string)($wanted['recordName'] ?? '');
					$id = substr($name, 2);
					$photo = $this->photos[$id] ?? null;
					if ($photo === null) {
						$records[] = ['recordName' => $name, 'serverErrorCode' => 'NOT_FOUND'];
					} else {
						$records[] = str_starts_with($name, 'A-') ? $this->assetRecord($id, $photo) : $this->masterRecord($id, $photo);
					}
				}
				return self::json(['records' => $records]);
		}
		return self::json(['serverErrorCode' => 'NOT_FOUND'], 404);
	}

	private function assetRecord(string $id, array $photo): array {
		return [
			'recordName' => 'A-' . $id,
			'recordType' => 'CPLAsset',
			'recordChangeTag' => 'a' . $photo['changedAt'],
			'fields' => [
				'masterRef' => ['type' => 'REFERENCE', 'value' => ['recordName' => 'M-' . $id, 'action' => 'DELETE_SELF']],
				'assetDate' => ['type' => 'TIMESTAMP', 'value' => $photo['date']],
				'addedDate' => ['type' => 'TIMESTAMP', 'value' => $photo['date']],
				'isDeleted' => ['type' => 'INT64', 'value' => $photo['deleted'] ? 1 : 0],
				'isHidden' => ['type' => 'INT64', 'value' => $photo['hidden'] ? 1 : 0],
			],
		];
	}

	private function masterRecord(string $id, array $photo): array {
		$fields = [
			'filenameEnc' => ['type' => 'ENCRYPTED_BYTES', 'value' => base64_encode($photo['fileName'])],
			'itemType' => ['type' => 'STRING', 'value' => 'public.heic'],
			'resOriginalFileType' => ['type' => 'STRING', 'value' => 'public.heic'],
			'resOriginalFingerprint' => ['type' => 'STRING', 'value' => 'fp-' . md5($photo['content'])],
			'resOriginalRes' => ['type' => 'ASSETID', 'value' => [
				'size' => strlen($photo['content']),
				'fileChecksum' => md5($photo['content']),
				'downloadURL' => $this->base . '/content/photos/' . rawurlencode($id) . '/original',
			]],
		];
		if ($photo['video'] !== null) {
			$fields['resOriginalVidComplFileType'] = ['type' => 'STRING', 'value' => 'com.apple.quicktime-movie'];
			$fields['resOriginalVidComplFingerprint'] = ['type' => 'STRING', 'value' => 'fpv-' . md5($photo['video'])];
			$fields['resOriginalVidComplRes'] = ['type' => 'ASSETID', 'value' => [
				'size' => strlen($photo['video']),
				'fileChecksum' => md5($photo['video']),
				'downloadURL' => $this->base . '/content/photos/' . rawurlencode($id) . '/video',
			]];
		}
		return ['recordName' => 'M-' . $id, 'recordType' => 'CPLMaster', 'recordChangeTag' => 'm' . $photo['changedAt'], 'fields' => $fields];
	}

	private static function json(array $data, int $status = 200): array {
		return [$status, ['Content-Type' => 'application/json'], json_encode($data, JSON_UNESCAPED_SLASHES)];
	}
}
