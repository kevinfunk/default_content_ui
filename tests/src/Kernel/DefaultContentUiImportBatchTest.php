<?php

namespace Drupal\Tests\default_content_ui\Kernel;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\default_content_ui\Batch\ImportBatch;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests ImportBatch's batch-completion messaging and watchdog logging.
 *
 * @group default_content_ui
 */
class DefaultContentUiImportBatchTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['default_content_ui', 'system', 'user', 'file', 'dblog', 'node', 'field', 'text'];

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

}
