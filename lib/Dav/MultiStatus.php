<?php

declare(strict_types=1);

namespace OCA\AppleSync\Dav;

use OCA\AppleSync\ICloud\Exception\ICloudException;

/**
 * Parser for WebDAV 207 Multi-Status bodies.
 */
final class MultiStatus {
	public const NS_DAV = 'DAV:';
	public const NS_CARDDAV = 'urn:ietf:params:xml:ns:carddav';
	public const NS_CALDAV = 'urn:ietf:params:xml:ns:caldav';
	public const NS_CS = 'http://calendarserver.org/ns/';
	public const NS_APPLE = 'http://apple.com/ns/ical/';

	/**
	 * @return list<array{href: string, status: int, props: array<string, \DOMElement>}>
	 *                                                                                    props hold only the properties
	 *                                                                                    reported with status 200, keyed in
	 *                                                                                    Clark notation ("{DAV:}getetag")
	 */
	public static function parse(string $xml): array {
		$doc = new \DOMDocument();
		if ($xml === '' || !@$doc->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) {
			throw new ICloudException('Apple sent an unreadable DAV response');
		}

		$responses = [];
		foreach (self::children($doc->documentElement, self::NS_DAV, 'response') as $response) {
			$href = self::childText($response, self::NS_DAV, 'href');
			if ($href === null) {
				continue;
			}
			$status = self::statusCode(self::childText($response, self::NS_DAV, 'status')) ?? 200;
			$props = [];
			foreach (self::children($response, self::NS_DAV, 'propstat') as $propstat) {
				if ((self::statusCode(self::childText($propstat, self::NS_DAV, 'status')) ?? 200) !== 200) {
					continue;
				}
				foreach (self::children($propstat, self::NS_DAV, 'prop') as $prop) {
					foreach ($prop->childNodes as $node) {
						if ($node instanceof \DOMElement) {
							$props['{' . $node->namespaceURI . '}' . $node->localName] = $node;
						}
					}
				}
			}
			$responses[] = ['href' => $href, 'status' => $status, 'props' => $props];
		}
		return $responses;
	}

	public static function text(?\DOMElement $element): ?string {
		if ($element === null) {
			return null;
		}
		$text = trim($element->textContent);
		return $text === '' ? null : $text;
	}

	/**
	 * First <d:href> inside a property such as current-user-principal.
	 */
	public static function href(?\DOMElement $element): ?string {
		return $element === null ? null : self::childText($element, self::NS_DAV, 'href');
	}

	/**
	 * Child element names in Clark notation, e.g. the types in <d:resourcetype>.
	 *
	 * @return list<string>
	 */
	public static function childNames(?\DOMElement $element): array {
		$names = [];
		foreach ($element?->childNodes ?? [] as $node) {
			if ($node instanceof \DOMElement) {
				$names[] = '{' . $node->namespaceURI . '}' . $node->localName;
			}
		}
		return $names;
	}

	/**
	 * Component names from <cal:supported-calendar-component-set>.
	 *
	 * @return list<string>
	 */
	public static function components(?\DOMElement $element): array {
		$names = [];
		foreach ($element === null ? [] : self::children($element, self::NS_CALDAV, 'comp') as $comp) {
			$names[] = strtoupper($comp->getAttribute('name'));
		}
		return $names;
	}

	/**
	 * @return list<\DOMElement>
	 */
	private static function children(\DOMElement $parent, string $ns, string $name): array {
		$found = [];
		foreach ($parent->childNodes as $node) {
			if ($node instanceof \DOMElement && $node->namespaceURI === $ns && $node->localName === $name) {
				$found[] = $node;
			}
		}
		return $found;
	}

	private static function childText(\DOMElement $parent, string $ns, string $name): ?string {
		return self::text(self::children($parent, $ns, $name)[0] ?? null);
	}

	private static function statusCode(?string $line): ?int {
		return $line !== null && preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $m) ? (int)$m[1] : null;
	}
}
