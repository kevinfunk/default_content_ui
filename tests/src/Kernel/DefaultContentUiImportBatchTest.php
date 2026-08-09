<?php

namespace Drupal\Tests\default_content_ui\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\DefaultContent\Finder;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\default_content_ui\Batch\ImportBatch;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;

/**
 * Tests ImportBatch's batch-completion messaging and watchdog logging.
 *
 * @group default_content_ui
 */
class DefaultContentUiImportBatchTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'default_content_ui',
    'default_content_ui_mapping',
    'default_content_ui_pre_import_test',
    'system',
    'user',
    'file',
    'dblog',
    'node',
    'field',
    'text',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('dblog', ['watchdog']);
  }

  /**
   * Tests that an import failure is shown to the admin via messenger.
   */
  public function testFinishedReportsErrorMessage() {
    ImportBatch::finished(TRUE, ['error' => 'Malformed inline YAML string.'], []);

    $errors = \Drupal::messenger()->messagesByType(MessengerInterface::TYPE_ERROR);
    $this->assertNotEmpty($errors, 'An error message is shown when the import fails.');
    $this->assertStringContainsString('Malformed inline YAML string.', (string) reset($errors));
  }

  /**
   * Tests that an import failure is also logged to watchdog.
   *
   * Previously only shown via a messenger error, which leaves no
   * server-side record once the admin dismisses it or if it's ever
   * triggered outside an interactive session.
   */
  public function testFinishedLogsErrorToWatchdog() {
    ImportBatch::finished(TRUE, ['error' => 'Malformed inline YAML string.'], []);

    $log = \Drupal::database()->select('watchdog', 'w')
      ->fields('w', ['message', 'variables'])
      ->condition('type', 'default_content_ui')
      ->execute()
      ->fetchAll();

    $this->assertCount(1, $log, 'The import failure is recorded in the log.');
    $variables = unserialize($log[0]->variables, ['allowed_classes' => FALSE]);
    $this->assertStringContainsString('Malformed inline YAML string.', $variables['@error']);
  }

  /**
   * Tests that a successful import does not also log an error.
   */
  public function testFinishedDoesNotLogOnSuccess() {
    ImportBatch::finished(TRUE, ['imported' => TRUE], []);

    $log = \Drupal::database()->select('watchdog', 'w')
      ->fields('w', ['message'])
      ->condition('type', 'default_content_ui')
      ->execute()
      ->fetchAll();

    $this->assertCount(0, $log, 'A successful import must not be logged as an error.');
  }

  /**
   * Invokes the protected resolveArchiveRoot() via reflection.
   */
  protected function resolveArchiveRoot(string $folder): string {
    $method = new \ReflectionMethod(ImportBatch::class, 'resolveArchiveRoot');
    return $method->invoke(NULL, $folder, \Drupal::entityTypeManager());
  }

  /**
   * Creates a folder containing exactly one named subfolder.
   */
  protected function createFolderWithOneSubdir(string $subdir_name): string {
    $file_system = \Drupal::service('file_system');
    $root = 'temporary://dcu_archive_root_' . $this->randomMachineName();
    $subdir = $root . '/' . $subdir_name;
    $file_system->prepareDirectory($subdir, FileSystemInterface::CREATE_DIRECTORY);
    return $file_system->realpath($root);
  }

  /**
   * Tests that a genuine wrapper folder (an arbitrary name) is unwrapped.
   */
  public function testResolveArchiveRootUnwrapsGenericWrapperFolder() {
    $root = $this->createFolderWithOneSubdir('export_2026_08_03_random_name');

    $this->assertSame($root . '/export_2026_08_03_random_name', $this->resolveArchiveRoot($root));
  }

  /**
   * Tests that a lone 'content' folder is NOT treated as a wrapper.
   *
   * 'content' is this module's own export shape — unwrapping into it
   * would hide any sibling folder (like 'components/') alongside it.
   */
  public function testResolveArchiveRootDoesNotUnwrapContentFolder() {
    $root = $this->createFolderWithOneSubdir('content');

    $this->assertSame($root, $this->resolveArchiveRoot($root));
  }

  /**
   * Tests that a lone 'components' folder is NOT treated as a wrapper.
   */
  public function testResolveArchiveRootDoesNotUnwrapComponentsFolder() {
    $root = $this->createFolderWithOneSubdir('components');

    $this->assertSame($root, $this->resolveArchiveRoot($root));
  }

  /**
   * Tests that a lone real content-entity-type folder isn't unwrapped.
   *
   * An archive with only one entity type (e.g. just 'node') is a
   * legitimate, complete top level, not an accidental wrapper.
   */
  public function testResolveArchiveRootDoesNotUnwrapRealEntityTypeFolder() {
    $root = $this->createFolderWithOneSubdir('node');

    $this->assertSame($root, $this->resolveArchiveRoot($root));
  }

  /**
   * Invokes the protected scanForUnknownFields() via reflection.
   */
  protected function scanForUnknownFields(Finder $finder): array {
    $method = new \ReflectionMethod(ImportBatch::class, 'scanForUnknownFields');
    return $method->invoke(NULL, $finder);
  }

  /**
   * Writes one entity's default-content YAML fixture and returns its folder.
   */
  protected function writeContentFixture(array $data): string {
    $dir = 'temporary://dcu_scan_' . $this->randomMachineName();
    $file_system = \Drupal::service('file_system');
    $file_system->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY);

    $uuid = $data['_meta']['uuid'];
    $file_system->saveData(Yaml::encode($data), "$dir/$uuid.yml");

    return $file_system->realpath($dir);
  }

  /**
   * Tests that an unrecognized field name is reported.
   */
  public function testScanForUnknownFieldsReportsUnrecognizedField() {
    $dir = $this->writeContentFixture([
      '_meta' => [
        'entity_type' => 'user',
        'uuid' => $this->container->get('uuid')->generate(),
        'default_langcode' => 'en',
      ],
      'default' => [
        'name' => [['value' => 'Test User']],
        'mail_address' => [['value' => 'test@example.com']],
      ],
    ]);

    $warnings = $this->scanForUnknownFields(new Finder($dir));

    $this->assertCount(1, $warnings, 'Exactly one unknown field is reported.');
    $this->assertStringContainsString("Field 'mail_address'", (string) $warnings[0]);
  }

  /**
   * Tests that only genuinely unrecognized fields are reported.
   */
  public function testScanForUnknownFieldsIgnoresRealFields() {
    $dir = $this->writeContentFixture([
      '_meta' => [
        'entity_type' => 'user',
        'uuid' => $this->container->get('uuid')->generate(),
        'default_langcode' => 'en',
      ],
      'default' => [
        'name' => [['value' => 'Test User']],
        'mail' => [['value' => 'test@example.com']],
      ],
    ]);

    $this->assertSame([], $this->scanForUnknownFields(new Finder($dir)));
  }

  /**
   * Tests that the same unknown field across entities is reported once.
   */
  public function testScanForUnknownFieldsDedupesAcrossEntities() {
    $file_system = \Drupal::service('file_system');
    $dir = 'temporary://dcu_scan_' . $this->randomMachineName();
    $file_system->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY);

    foreach ([1, 2] as $i) {
      $uuid = $this->container->get('uuid')->generate();
      $data = [
        '_meta' => [
          'entity_type' => 'user',
          'uuid' => $uuid,
          'default_langcode' => 'en',
        ],
        'default' => [
          'mail_address' => [['value' => "user$i@example.com"]],
        ],
      ];
      $file_system->saveData(Yaml::encode($data), "$dir/$uuid.yml");
    }

    $warnings = $this->scanForUnknownFields(new Finder($file_system->realpath($dir)));

    $this->assertCount(1, $warnings, 'The same unknown field on the same bundle is only reported once.');
  }

  /**
   * Tests that a field already renamed by a configured mapping is not flagged.
   *
   * The scan dispatches the same PreEntityImportEvent the real import does,
   * so a configured Field Mapping rule renames the field before it's
   * checked, same as it would during a real import.
   */
  public function testScanForUnknownFieldsRespectsConfiguredMapping() {
    $this->config('default_content_ui_mapping.settings')
      ->set('mappings', [
        ['source' => 'mail_address', 'target' => 'mail'],
      ])
      ->save();

    $dir = $this->writeContentFixture([
      '_meta' => [
        'entity_type' => 'user',
        'uuid' => $this->container->get('uuid')->generate(),
        'default_langcode' => 'en',
      ],
      'default' => [
        'mail_address' => [['value' => 'test@example.com']],
      ],
    ]);

    $this->assertSame([], $this->scanForUnknownFields(new Finder($dir)), 'A field already fixed by a configured mapping rule is not reported as unknown.');
  }

  /**
   * Tests that scanOrImport() does not invoke the hook when issues are found.
   *
   * An implementation of that hook (e.g. canvas_component_manager's) can
   * actually import content as a real, permanent side effect — invoking it
   * while there are field issues still to review would mean the report
   * silently made real changes to the site before the admin ever saw it.
   */
  public function testScanOrImportDoesNotInvokeHookWhenIssuesFound() {
    $folder = $this->writeContentFixture([
      '_meta' => [
        'entity_type' => 'user',
        'uuid' => $this->container->get('uuid')->generate(),
        'default_langcode' => 'en',
      ],
      'default' => [
        'mail_address' => [['value' => 'test@example.com']],
      ],
    ]);

    $context = [];
    ImportBatch::scanOrImport($folder, $context);

    $this->assertNotEmpty($context['results']['field_warnings'], 'A field issue must actually be found for this test to be meaningful.');
    $this->assertSame(
      0,
      \Drupal::state()->get('default_content_ui_pre_import_test.invocations', 0),
      'scanOrImport() must not invoke hook_default_content_ui_pre_import() while field issues remain unreviewed.'
    );
  }

  /**
   * Tests that scanOrImport() imports and invokes the hook when clean.
   *
   * There's nothing to review when the scan finds no issues, so it
   * imports immediately instead of stopping for confirmation — meaning
   * the pre-import hook fires here too, exactly as a real import would.
   */
  public function testScanOrImportInvokesHookWhenClean() {
    $file_system = \Drupal::service('file_system');
    $folder = 'temporary://dcu_scan_folder_' . $this->randomMachineName();
    $content_dir = $folder . '/content';
    $file_system->prepareDirectory($content_dir, FileSystemInterface::CREATE_DIRECTORY);
    $folder = $file_system->realpath($folder);

    $context = [];
    ImportBatch::scanOrImport($folder, $context);

    $this->assertSame([], $context['results']['field_warnings'], 'This scenario must actually be clean for the test to be meaningful.');
    $this->assertTrue($context['results']['imported'] ?? FALSE, 'A clean scan must import immediately.');
    $this->assertSame(
      1,
      \Drupal::state()->get('default_content_ui_pre_import_test.invocations', 0),
      'A clean scanOrImport() must still invoke hook_default_content_ui_pre_import() exactly once.'
    );
  }

  /**
   * Tests that a real import() still invokes the pre-import hook.
   *
   * The counterpart to the scanOrImport() tests above — proves the hook is
   * genuinely still wired up for a real import, not just removed
   * everywhere.
   */
  public function testImportInvokesPreImportHook() {
    $file_system = \Drupal::service('file_system');
    $folder = 'temporary://dcu_import_folder_' . $this->randomMachineName();
    $content_dir = $folder . '/content';
    $file_system->prepareDirectory($content_dir, FileSystemInterface::CREATE_DIRECTORY);
    $folder = $file_system->realpath($folder);

    $context = [];
    ImportBatch::import($folder, $context);

    $this->assertSame(
      1,
      \Drupal::state()->get('default_content_ui_pre_import_test.invocations', 0),
      'A real import() must still invoke hook_default_content_ui_pre_import() exactly once.'
    );
  }

  /**
   * Tests that a later entity's failure rolls back an earlier success.
   *
   * Importer::importContent() has no per-entity transaction of its own, so
   * without wrapping the whole call in one, an entity that validates fine
   * would already be permanently saved by the time a later entity in the
   * same archive fails — leaving a half-imported archive behind. This
   * proves import() rolls back everything it touched instead.
   */
  public function testFailedEntityRollsBackEarlierSuccessInSameImport() {
    $this->installEntitySchema('user');
    $this->installConfig(['user']);

    // Importer::importContent() runs as this site's administrator, which
    // it resolves by loading uid 1 if no user holds an admin role.
    User::create(['uid' => 1, 'name' => 'admin'])->save();

    $file_system = \Drupal::service('file_system');
    $dir = 'temporary://dcu_rollback_' . $this->randomMachineName();
    $file_system->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY);

    // Sorted first by Finder (no declared dependency between the two, so
    // they're ordered by UUID), and valid on its own — this is the entity
    // whose survival would prove a partial import occurred.
    $good_uuid = '00000000-0000-4000-8000-000000000001';
    $file_system->saveData(Yaml::encode([
      '_meta' => [
        'entity_type' => 'user',
        'uuid' => $good_uuid,
        'default_langcode' => 'en',
      ],
      'default' => [
        'name' => [['value' => 'Good User']],
      ],
    ]), "$dir/$good_uuid.yml");

    // Sorted after the entity above, and fails validation: no such role.
    $bad_uuid = 'ffffffff-ffff-4fff-8fff-ffffffffffff';
    $file_system->saveData(Yaml::encode([
      '_meta' => [
        'entity_type' => 'user',
        'uuid' => $bad_uuid,
        'default_langcode' => 'en',
      ],
      'default' => [
        'name' => [['value' => 'Bad User']],
        'roles' => [['target_id' => 'nonexistent_role']],
      ],
    ]), "$dir/$bad_uuid.yml");

    $context = [];
    ImportBatch::import($file_system->realpath($dir), $context);

    $this->assertNotEmpty($context['results']['error'], 'The bad entity must actually fail for this test to be meaningful.');
    $this->assertEmpty(
      \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['uuid' => $good_uuid]),
      'A later failure must roll back an earlier, otherwise-valid entity from the same import.'
    );
  }

  /**
   * Tests that a failed import discards messages added during the attempt.
   *
   * The pre-import hook can report its own work as done (e.g.
   * canvas_component_manager's "Imported N Code components" message)
   * before the rest of the import later fails and rolls back — that
   * message lives in the session, not the database, so the rollback alone
   * doesn't retract it.
   */
  public function testFailedImportDiscardsMessagesAddedDuringTheAttempt() {
    $this->installEntitySchema('user');
    $this->installConfig(['user']);
    User::create(['uid' => 1, 'name' => 'admin'])->save();

    \Drupal::messenger()->addStatus('Pre-existing message.');

    $bad_uuid = 'ffffffff-ffff-4fff-8fff-ffffffffffff';
    $folder = $this->writeContentFixture([
      '_meta' => [
        'entity_type' => 'user',
        'uuid' => $bad_uuid,
        'default_langcode' => 'en',
      ],
      'default' => [
        'name' => [['value' => 'Bad User']],
        'roles' => [['target_id' => 'nonexistent_role']],
      ],
    ]);

    $context = [];
    ImportBatch::import($folder, $context);

    $this->assertNotEmpty($context['results']['error'], 'The entity must actually fail for this test to be meaningful.');

    $all_text = array_map('strval', array_merge(...array_values(\Drupal::messenger()->all())));
    $this->assertNotContains(
      'Imported 1 fake component.',
      $all_text,
      'A status message added by the pre-import hook must be discarded when the import it described was rolled back.'
    );
    $this->assertContains(
      'Pre-existing message.',
      $all_text,
      'Messages that existed before the failed attempt must not be discarded.'
    );
  }

}
