<?php

namespace Drupal\Tests\default_content_ui\Kernel;

use Drupal\Core\File\FileSystemInterface;
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

  /**
   * Tests that the auto-download message reflects the actual export count.
   *
   * ExportBatch::finished() builds this message and stores it under
   * 'default_content_ui_download_message'; pageAttachments() previously
   * ignored it and rebuilt a message from
   * 'default_content_ui_download_count'/'_label', which finished() never
   * set — so this wording never actually appeared. Exercised directly at
   * the Kernel level (rather than a real HTTP request) because the
   * intermediate page carrying this message is immediately auto-navigated
   * away from by the download meta refresh, so a Functional test can
   * never observe it.
   */
  public function testPageAttachmentsShowsCountMessage() {
    $filename = 'default_content_export_test_count.zip';
    \Drupal::service('file_system')->saveData('', 'temporary://' . $filename, FileSystemInterface::EXISTS_REPLACE);

    ExportBatch::finished(TRUE, ['download_archive' => $filename, 'count' => 2], []);

    $attachments = [];
    \Drupal::service('default_content_ui.hooks')->pageAttachments($attachments);

    $messages = \Drupal::messenger()->messagesByType(MessengerInterface::TYPE_STATUS);
    $this->assertNotEmpty($messages);
    $this->assertStringContainsString('The export archive for 2 items is downloading automatically.', (string) reset($messages));
  }

  /**
   * Tests that the auto-download message reflects a single entity's label.
   *
   * See the comment on testPageAttachmentsShowsCountMessage() — this
   * wording also never actually appeared before the fix.
   */
  public function testPageAttachmentsShowsSingleLabelMessage() {
    $filename = 'default_content_export_test_label.zip';
    \Drupal::service('file_system')->saveData('', 'temporary://' . $filename, FileSystemInterface::EXISTS_REPLACE);

    ExportBatch::finished(TRUE, [
      'download_archive' => $filename,
      'count' => 1,
      'single_label' => 'Test Page with Dependency',
    ], []);

    $attachments = [];
    \Drupal::service('default_content_ui.hooks')->pageAttachments($attachments);

    $messages = \Drupal::messenger()->messagesByType(MessengerInterface::TYPE_STATUS);
    $this->assertNotEmpty($messages);
    // %label is rendered wrapped in a placeholder <em> tag, so match on the
    // text alone rather than the exact rendered HTML.
    $rendered = (string) reset($messages);
    $this->assertStringContainsString('The export archive for', $rendered);
    $this->assertStringContainsString('Test Page with Dependency', $rendered);
    $this->assertStringContainsString('is downloading automatically.', $rendered);
  }

}
