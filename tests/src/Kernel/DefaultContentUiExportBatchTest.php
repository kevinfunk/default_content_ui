<?php

namespace Drupal\Tests\default_content_ui\Kernel;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\default_content_ui\Batch\ExportBatch;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests ExportBatch's batch-completion messaging.
 *
 * @group default_content_ui
 */
class DefaultContentUiExportBatchTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['default_content_ui', 'system', 'user', 'file'];

  /**
   * Tests that a compress() failure produces a real error message.
   *
   * ExportBatch::compress() previously had no else branch when
   * ZipArchive::open() failed, so $results['error'] was never set —
   * finished() then saw neither a populated 'download_archive' nor a
   * failed $success (Drupal's batch engine never threw), so the admin
   * got no message at all. This exercises finished() directly with the
   * $results shape compress() now produces on that failure, since a real
   * ZipArchive::open() failure isn't reliably triggerable from a test.
   */
  public function testFinishedReportsErrorWhenArchiveCouldNotBeOpened() {
    ExportBatch::finished(TRUE, ['error' => 'Could not open Zip archive for writing.'], []);

    $errors = \Drupal::messenger()->messagesByType(MessengerInterface::TYPE_ERROR);
    $this->assertNotEmpty($errors, 'An error message is shown when the archive could not be created.');
    $this->assertStringContainsString('Could not open Zip archive for writing.', (string) reset($errors));
  }

  /**
   * Tests that a successful export does not also report an error.
   */
  public function testFinishedReportsSuccessWhenArchiveIsReady() {
    ExportBatch::finished(TRUE, ['download_archive' => 'default_content_export_test.zip', 'count' => 1], []);

    $this->assertEmpty(\Drupal::messenger()->messagesByType(MessengerInterface::TYPE_ERROR));
  }

}
