<?php

declare(strict_types=1);

namespace OCA\AppleSync\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The Apple ID used for CardDAV/CalDAV. It is kept apart from the web
 * sign-in's Apple ID so either can be connected or disconnected alone.
 */
class Version1001Date20261021000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$table = $schema->getTable('apple_sync_accounts');
		if ($table->hasColumn('dav_apple_id')) {
			return null;
		}
		$table->addColumn('dav_apple_id', Types::STRING, ['notnull' => false, 'length' => 255]);
		return $schema;
	}
}
