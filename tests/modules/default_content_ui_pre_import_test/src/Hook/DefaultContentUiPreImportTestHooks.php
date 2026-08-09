<?php

namespace Drupal\default_content_ui_pre_import_test\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\State\StateInterface;

/**
 * Records invocations of hook_default_content_ui_pre_import() for testing.
 */
class DefaultContentUiPreImportTestHooks {

  public function __construct(
    protected StateInterface $state,
    protected MessengerInterface $messenger,
  ) {}

  /**
   * Implements hook_default_content_ui_pre_import().
   *
   * Also reports a status message unconditionally, mirroring how a real
   * implementation (e.g. canvas_component_manager's) reports its own
   * synchronous work as soon as it finishes — before the rest of the
   * import has had a chance to fail.
   */
  #[Hook('default_content_ui_pre_import')]
  public function defaultContentUiPreImport($folder): void {
    $invocations = $this->state->get('default_content_ui_pre_import_test.invocations', 0);
    $this->state->set('default_content_ui_pre_import_test.invocations', $invocations + 1);

    $this->messenger->addStatus('Imported 1 fake component.');
  }

}
