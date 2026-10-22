<?php

declare(strict_types=1);

/**
 * Fake Apple auth + setup endpoints for the end-to-end tests, served with
 * `php -S 0.0.0.0:8000 fake-apple.php`. The e2e Nextcloud points the app at it
 * through the `apple_sync_endpoints` system config value.
 *
 * Accounts: any Apple ID signs in, except wrong@example.com (bad password).
 * Codes: trusted device 123456, text message 654321.
 * Control: POST /__control/expire makes Apple forget the trust token and the
 * iCloud cookies (as after ~30 days); POST /__control/reset undoes it and puts
 * the iCloud data back to the seed below. POST /__control/icloud with
 * {"call": "addContact", "args": [...]} changes the data (see FakeICloud).
 * SRP proofs are not checked here; tests/Unit covers the SRP math.
 *
 * Everything else (CardDAV, CalDAV, Drive, Photos, downloads) is the shared
 * FakeICloud from tests/Support, with its state kept in a JSON file.
 */

require __DIR__ . '/FakeICloud.php';

use OCA\AppleSync\Tests\Support\FakeICloud;

const EXPIRED_FLAG = '/tmp/fake-apple-expired';
const ICLOUD_STATE = '/tmp/fake-icloud.json';
const ICLOUD_BASE = 'http://fake-apple:8000';
const ICLOUD_CALLS = [
	'addContact', 'addGroup', 'addCalendar', 'addEvent', 'addFolder', 'addFile', 'changeFile',
	'moveItem', 'deleteItem', 'addPhoto', 'deletePhoto', 'expungePhoto', 'expireSyncTokens',
];

function seed(): FakeICloud {
	$icloud = new FakeICloud(ICLOUD_BASE);
	$icloud->addContact('anna', 'Anna Apple');
	$icloud->addContact('bert', 'Bert Banana', ['CATEGORIES:Friends']);
	$icloud->addContact('carl', 'Carl Cherry');
	$icloud->addGroup('group-family', 'Family', ['anna', 'bert']);
	$icloud->addCalendar('home', 'Home', '#FF2968FF');
	$icloud->addCalendar('work', 'Work');
	$icloud->addEvent('home', 'dentist', 'Dentist');
	$icloud->addEvent('home', 'party', 'Party');
	$icloud->addEvent('work', 'standup', 'Standup');
	$notes = $icloud->addFolder('notes', 'Notes');
	$icloud->addFile('todo', 'todo', 'txt', 'buy milk', $notes);
	$icloud->addFile('readme', 'Read me', 'md', '# hello');
	$icloud->addPhoto('1', 'IMG_0001.HEIC', '2024-05-10T10:00:00Z', 'photo-1', 'video-1');
	$icloud->addPhoto('2', 'IMG_0002.JPG', '2025-03-15T12:00:00Z', 'photo-2');
	return $icloud;
}

function icloud(): FakeICloud {
	$state = @file_get_contents(ICLOUD_STATE);
	return $state === false ? seed() : FakeICloud::fromArray(json_decode($state, true));
}

function saveICloud(FakeICloud $icloud): void {
	$icloud->log = [];
	file_put_contents(ICLOUD_STATE, json_encode($icloud->toArray()), LOCK_EX);
}

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = json_decode(file_get_contents('php://input') ?: 'null', true) ?? [];

function reply(int $status, ?array $json = null, array $headers = []): never {
	http_response_code($status);
	foreach ($headers as $name => $value) {
		header("$name: $value", false);
	}
	if ($json !== null) {
		header('Content-Type: application/json');
		echo json_encode($json);
	}
	exit;
}

function sessionHeaders(): array {
	return ['scnt' => 'scnt-' . bin2hex(random_bytes(4)), 'X-Apple-ID-Session-Id' => 'sid-fake'];
}

function account(): array {
	return [
		'dsInfo' => ['dsid' => '1000000001', 'hsaVersion' => 2],
		'hsaChallengeRequired' => false,
		'hsaTrustedBrowser' => true,
		'webservices' => [
			'drivews' => ['url' => ICLOUD_BASE . '/drivews', 'status' => 'active'],
			'docws' => ['url' => ICLOUD_BASE . '/docws', 'status' => 'active'],
			'ckdatabasews' => ['url' => ICLOUD_BASE . '/ckdatabasews', 'status' => 'active'],
		],
	];
}

$expired = file_exists(EXPIRED_FLAG);
$auth = '/appleauth/auth';
$setup = '/setup/ws/1';

switch ("$method $path") {
	case 'POST /__control/expire':
		touch(EXPIRED_FLAG);
		reply(204);
	case 'POST /__control/reset':
		@unlink(EXPIRED_FLAG);
		saveICloud(seed());
		reply(204);
	case 'POST /__control/icloud':
		if (!in_array($body['call'] ?? '', ICLOUD_CALLS, true)) {
			reply(400, ['error' => 'unknown call']);
		}
		$icloud = icloud();
		$icloud->{$body['call']}(...($body['args'] ?? []));
		saveICloud($icloud);
		reply(204);

	case "GET $auth/authorize/signin":
		http_response_code(200);
		foreach (sessionHeaders() as $k => $v) {
			header("$k: $v");
		}
		header('Set-Cookie: aasp=fake; Path=/; HttpOnly');
		echo '<html>sign in</html>';
		exit;
	case "POST $auth/federate":
		reply(200, ['hasSWP' => true], sessionHeaders());
	case "POST $auth/signin/init":
		reply(200, [
			'iteration' => 1000,
			'salt' => base64_encode(random_bytes(16)),
			'protocol' => 's2k',
			'b' => base64_encode(random_bytes(256)),
			'c' => 'c-' . bin2hex(random_bytes(4)),
		], sessionHeaders());
	case "POST $auth/signin/complete":
		if (($body['accountName'] ?? '') === 'wrong@example.com') {
			reply(401, ['serviceErrors' => [['code' => '-20101', 'message' => 'Your Apple ID or password was incorrect.']]]);
		}
		$trusted = in_array('trust-ok', $body['trustTokens'] ?? [], true) && !$expired;
		reply($trusted ? 200 : 409, ['authType' => 'hsa2'], sessionHeaders() + ['X-Apple-Session-Token' => 'session-' . bin2hex(random_bytes(4))]);

	case "PUT $auth/verify/trusteddevice/securitycode":
		reply(204, null, sessionHeaders());
	case "POST $auth/verify/trusteddevice/securitycode":
		if (($body['securityCode']['code'] ?? '') !== '123456') {
			reply(400, ['service_errors' => [['code' => '-21669', 'message' => 'Incorrect verification code.']]]);
		}
		reply(204, null, sessionHeaders() + ['X-Apple-Session-Token' => 'session-verified']);
	case "GET $auth":
		reply(200, ['trustedPhoneNumbers' => [
			['id' => 1, 'numberWithDialCode' => '+1 (•••) •••-••42'],
			['id' => 2, 'numberWithDialCode' => '+33 •• •• •• •• 17'],
		]], sessionHeaders());
	case "PUT $auth/verify/phone":
		reply(200, ['phoneNumber' => ['id' => $body['phoneNumber']['id'] ?? 0]], sessionHeaders());
	case "POST $auth/verify/phone/securitycode":
		if (($body['securityCode']['code'] ?? '') !== '654321') {
			reply(400, ['service_errors' => [['code' => '-21669', 'message' => 'Incorrect verification code.']]]);
		}
		reply(200, [], sessionHeaders() + ['X-Apple-Session-Token' => 'session-verified']);
	case "GET $auth/2sv/trust":
		@unlink(EXPIRED_FLAG);
		reply(204, null, ['X-Apple-TwoSV-Trust-Token' => 'trust-ok', 'X-Apple-Session-Token' => 'session-trusted']);

	case "POST $setup/accountLogin":
		reply(200, account(), ['Set-Cookie' => 'X-APPLE-WEBAUTH-TOKEN=v=2:t=fake; Path=/; HttpOnly']);
	case "POST $setup/validate":
		if ($expired || !isset($_COOKIE['X-APPLE-WEBAUTH-TOKEN'])) {
			reply(421, ['error' => 'Missing X-APPLE-WEBAUTH-TOKEN cookie']);
		}
		reply(200, account());
}

$headers = array_change_key_case(function_exists('getallheaders') ? getallheaders() : [], CASE_LOWER);
parse_str((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $query);
$icloud = icloud();
[$status, $responseHeaders, $responseBody] = $icloud->handle($method, $path, $query, $headers, file_get_contents('php://input') ?: '');
saveICloud($icloud);
http_response_code($status);
foreach ($responseHeaders as $name => $value) {
	header("$name: $value");
}
echo $responseBody;
