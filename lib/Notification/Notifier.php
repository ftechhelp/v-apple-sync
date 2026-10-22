<?php

declare(strict_types=1);

namespace OCA\AppleSync\Notification;

use OCA\AppleSync\AppInfo\Application;
use OCA\AppleSync\Service\NotificationService;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

class Notifier implements INotifier {
	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_ID)->t('Apple iCloud Sync');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}
		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$services = [
			'contacts' => $l->t('Contacts'),
			'calendars' => $l->t('Calendars'),
			'drive' => $l->t('iCloud Drive'),
			'photos' => $l->t('Photos'),
		];

		switch ($notification->getSubject()) {
			case NotificationService::REAUTH_REQUIRED:
				$notification->setParsedSubject($l->t('iCloud needs a new verification code'))
					->setParsedMessage($l->t('Apple asks for a two-factor code about once a month. Photos and iCloud Drive are paused until you reconnect.'));
				break;
			case NotificationService::DAV_REJECTED:
				$notification->setParsedSubject($l->t('Apple rejected the app-specific password'))
					->setParsedMessage($l->t('Contacts and calendars are paused. Create a new app-specific password and enter it in the settings.'));
				break;
			case NotificationService::SYNC_FAILED:
				$service = (string)($notification->getSubjectParameters()['service'] ?? '');
				$notification->setParsedSubject($l->t('Syncing %s from iCloud keeps failing', [$services[$service] ?? $service]))
					->setParsedMessage($l->t('Open the Apple iCloud settings to see the error.'));
				break;
			default:
				throw new UnknownNotificationException();
		}

		return $notification
			->setLink($this->urlGenerator->linkToRouteAbsolute('settings.PersonalSettings.index', ['section' => Application::APP_ID]))
			->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg')));
	}
}
