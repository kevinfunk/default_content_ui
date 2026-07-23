<?php

namespace Drupal\Tests\default_content_ui\Kernel;

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
  protected static $modules = ['default_content_ui', 'system', 'user', 'file', 'dblog'];

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

}
