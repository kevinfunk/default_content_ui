<?php

namespace Drupal\Tests\default_content_ui\Kernel;

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests default_content_ui_uninstall()'s temporary file cleanup.
 *
 * Export batches can leave a downloadable archive sitting in temporary://
 * until it's downloaded or core's own cron sweep clears it — up to 6 hours
 * by default. hook_uninstall() clears the module's own leftovers
 * immediately instead of waiting on that.
 *
 * @group default_content_ui
 */
class DefaultContentUiUninstallTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['default_content_ui', 'system', 'user', 'file'];

  /**
   * Tests that only this module's own leftover exports are removed.
   */
  public function testUninstallRemovesOwnLeftoversOnly() {
    $file_system = \Drupal::service('file_system');

    $own_zip = 'temporary://default_content_export_' . $this->randomMachineName() . '.zip';
    $file_system->saveData('fake zip contents', $own_zip, FileExists::Replace);

    $own_folder = 'temporary://default_content_export_' . $this->randomMachineName();
    $file_system->prepareDirectory($own_folder, FileSystemInterface::CREATE_DIRECTORY);
    $file_system->saveData('leftover', $own_folder . '/leftover.yml', FileExists::Replace);

    $unrelated_file = 'temporary://' . $this->randomMachineName() . '.txt';
    $file_system->saveData('not ours', $unrelated_file, FileExists::Replace);

    \Drupal::moduleHandler()->loadInclude('default_content_ui', 'install');
    default_content_ui_uninstall();

    $this->assertFileDoesNotExist($file_system->realpath($own_zip));
    $this->assertDirectoryDoesNotExist($file_system->realpath($own_folder));
    $this->assertFileExists($file_system->realpath($unrelated_file));
  }

}
