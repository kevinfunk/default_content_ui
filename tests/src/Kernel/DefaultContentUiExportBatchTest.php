<?php

namespace Drupal\Tests\default_content_ui\Kernel;

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\default_content_ui\Batch\ExportBatch;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;

/**
 * Tests ExportBatch's batch-completion messaging and per-item resilience.
 *
 * @group default_content_ui
 */
class DefaultContentUiExportBatchTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['default_content_ui', 'system', 'user', 'file', 'node', 'field', 'text', 'dblog'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installConfig(['node', 'field']);
    $this->installSchema('dblog', ['watchdog']);
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
  }

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
   * Tests that an overall export failure is also logged to watchdog.
   *
   * Previously only shown via a messenger error, which leaves no
   * server-side record once the admin dismisses it or if it's ever
   * triggered outside an interactive session.
   */
  public function testFinishedLogsErrorToWatchdog() {
    ExportBatch::finished(TRUE, ['error' => 'Could not open Zip archive for writing.'], []);

    $log = \Drupal::database()->select('watchdog', 'w')
      ->fields('w', ['message', 'variables'])
      ->condition('type', 'default_content_ui')
      ->execute()
      ->fetchAll();

    $this->assertCount(1, $log, 'The export failure is recorded in the log.');
    $variables = unserialize($log[0]->variables, ['allowed_classes' => FALSE]);
    $this->assertStringContainsString('Could not open Zip archive for writing.', $variables['@error']);
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
    \Drupal::service('file_system')->saveData('', 'temporary://' . $filename, FileExists::Replace);

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
    \Drupal::service('file_system')->saveData('', 'temporary://' . $filename, FileExists::Replace);

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

  /**
   * Tests that compress() refuses to produce a download when count is 0.
   *
   * Previously compress() zipped up whatever was in the folder (empty,
   * on a total export failure) and always set 'download_archive' as
   * long as ZipArchive::open() itself succeeded — finished() then saw a
   * populated 'download_archive' and no 'error', so it reported success
   * and auto-downloaded a broken/empty archive instead of surfacing the
   * failure that was otherwise only visible in the watchdog log.
   */
  public function testCompressRefusesToProduceDownloadWhenNothingExported() {
    $folder = 'temporary://dcu_test_empty_export_' . $this->randomMachineName();
    \Drupal::service('file_system')->prepareDirectory($folder, FileSystemInterface::CREATE_DIRECTORY);

    $context = ['results' => ['count' => 0]];
    ExportBatch::compress($folder, $context);

    $this->assertArrayHasKey('error', $context['results'], 'A zero-count export must be surfaced as an error.');
    $this->assertEmpty($context['results']['download_archive'] ?? NULL, 'No download must be offered when nothing was exported.');
  }

  /**
   * Tests that a partial export failure produces a warning message.
   *
   * Previously the only trace of a partial failure (some, but not all,
   * selected entities failed) was a watchdog log entry — the admin saw
   * only the success message for whatever did export, with no
   * indication anything was silently skipped.
   */
  public function testFinishedWarnsAboutPartialFailure() {
    ExportBatch::finished(TRUE, [
      'download_archive' => 'default_content_export_partial_test.zip',
      'count' => 3,
      'attempted' => 5,
    ], []);

    $warnings = \Drupal::messenger()->messagesByType(MessengerInterface::TYPE_WARNING);
    $this->assertNotEmpty($warnings, 'A warning is shown when some selected items failed to export.');
    $this->assertStringContainsString('2 of 5', (string) reset($warnings));
  }

  /**
   * Tests that a fully successful export does not also report a warning.
   */
  public function testFinishedDoesNotWarnWhenAllSucceeded() {
    ExportBatch::finished(TRUE, [
      'download_archive' => 'default_content_export_full_test.zip',
      'count' => 3,
      'attempted' => 3,
    ], []);

    $this->assertEmpty(\Drupal::messenger()->messagesByType(MessengerInterface::TYPE_WARNING));
  }

  /**
   * Tests that a per-entity export failure doesn't abort the whole chunk.
   *
   * ExportBatch::export() previously had no try/catch around the export call,
   * unlike ImportBatch::import()'s equivalent core-API call, so one bad
   * entity would propagate an uncaught exception and abort exporting the
   * rest of the chunk (and any later chunks). Pointing the destination at
   * a path that is itself a plain file (not a directory) reliably
   * reproduces a real Exporter\DirectoryNotReadyException without needing
   * to fabricate a broken entity.
   */
  public function testExportCatchesFailureAndAdvancesCursor() {
    $node = Node::create(['type' => 'page', 'title' => 'Test']);
    $node->save();

    $blocked_folder = 'temporary://dcu_test_blocked_dir';
    \Drupal::service('file_system')->saveData('not a directory', $blocked_folder, FileExists::Replace);

    $context = [];
    ExportBatch::export('node', $blocked_folder, 'entity', $context);

    // Reaching this line at all proves the exception was caught rather
    // than propagated as an uncaught fatal.
    $this->assertSame(
      $node->id(),
      $context['sandbox']['current_id'],
      'The cursor must advance past a failing entity, or the next batch call would retry it forever.'
    );
    $this->assertSame(0, $context['results']['count'] ?? 0, 'A failed export must not be counted as succeeded.');
  }

  /**
   * Tests that export() correctly processes entities across multiple chunks.
   *
   * ExportBatch::export() processes up to 10 entities per call, tracking
   * a cursor ($context['sandbox']['current_id']) across repeated calls — every
   * existing test exports 3 or fewer entities, so this multi-call
   * pagination logic (and the finished-ratio calculation) has never
   * actually been exercised past a single chunk. A cursor bug here would
   * silently drop or duplicate entities on any real-world bulk export.
   */
  public function testExportProcessesAllEntitiesAcrossMultipleChunks() {
    $expected_uuids = [];
    for ($i = 0; $i < 25; $i++) {
      $node = Node::create(['type' => 'page', 'title' => "Node $i"]);
      $node->save();
      $expected_uuids[] = $node->uuid();
    }
    sort($expected_uuids);

    $folder = 'temporary://dcu_test_multi_chunk_' . $this->randomMachineName();
    \Drupal::service('file_system')->prepareDirectory($folder, FileSystemInterface::CREATE_DIRECTORY);

    $context = [];
    $calls = 0;
    do {
      ExportBatch::export('node', $folder, 'entity', $context);
      $calls++;
    } while (empty($context['finished']) || $context['finished'] < 1);

    // With a chunk size of 10, exporting 25 entities must take multiple
    // calls — proving this test actually exercises the multi-chunk
    // cursor, not just a single pass.
    $this->assertGreaterThan(1, $calls);
    $this->assertSame(25, $context['results']['count']);

    $exported_uuids = [];
    foreach (glob(\Drupal::service('file_system')->realpath($folder) . '/node/*.yml') as $file) {
      $exported_uuids[] = basename($file, '.yml');
    }
    sort($exported_uuids);

    $this->assertSame($expected_uuids, $exported_uuids, 'Every entity must be exported exactly once, with none dropped or duplicated.');
  }

}
