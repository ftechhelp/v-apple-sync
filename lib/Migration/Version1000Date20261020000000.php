<?php

declare(strict_types=1);

namespace OCA\AppleSync\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20261020000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('apple_sync_accounts')) {
			$table = $schema->createTable('apple_sync_accounts');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('apple_id', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('web_state', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'disconnected']);
			$table->addColumn('dav_state', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'disconnected']);
			$table->addColumn('dsid', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('trust_expires_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('last_auth_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('last_error', Types::TEXT, ['notnull' => false]);
			// Encrypted with ICrypto.
			$table->addColumn('password_enc', Types::TEXT, ['notnull' => false]);
			$table->addColumn('app_password_enc', Types::TEXT, ['notnull' => false]);
			$table->addColumn('web_session_enc', Types::TEXT, ['notnull' => false]);
			$table->addColumn('pending_auth_enc', Types::TEXT, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['user_id'], 'apple_sync_acc_user');
		}

		if (!$schema->hasTable('apple_sync_state')) {
			$table = $schema->createTable('apple_sync_state');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('service', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->addColumn('enabled', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$table->addColumn('target', Types::STRING, ['notnull' => false, 'length' => 4000]);
			$table->addColumn('options', Types::TEXT, ['notnull' => false]);
			$table->addColumn('sync_cursor', Types::TEXT, ['notnull' => false]);
			$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'idle']);
			$table->addColumn('locked_until', Types::BIGINT, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$table->addColumn('next_run_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$table->addColumn('last_success_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('last_error', Types::TEXT, ['notnull' => false]);
			$table->addColumn('fail_count', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$table->addColumn('items_done', Types::BIGINT, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$table->addColumn('bytes_done', Types::BIGINT, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['user_id', 'service'], 'apple_sync_state_us');
		}

		if (!$schema->hasTable('apple_sync_items')) {
			$table = $schema->createTable('apple_sync_items');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('service', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->addColumn('collection', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
			$table->addColumn('remote_id', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('remote_etag', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('local_ref', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('local_container', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('local_path', Types::STRING, ['notnull' => false, 'length' => 4000]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['user_id', 'service', 'remote_id'], 'apple_sync_items_usr');
			$table->addIndex(['user_id', 'service', 'collection'], 'apple_sync_items_usc');
		}

		return $schema;
	}
}
