(function (Drupal, once) {
  Drupal.behaviors.defaultContentUiMappingUnsavedChanges = {
    attach: function (context) {
      once('dcu-mapping-unsaved-changes', '.dcu-mapping-settings-form', context).forEach(function (form) {
        const messenger = new Drupal.Message();
        const messageId = 'dcu-mapping-unsaved-changes';
        let isDirty = false;

        function markDirty() {
          if (isDirty) {
            return;
          }
          isDirty = true;
          messenger.add(Drupal.t('You have unsaved changes.'), {
            id: messageId,
            type: 'warning'
          });
        }

        form.addEventListener('change', markDirty);
        form.addEventListener('input', markDirty);

        form.addEventListener('submit', function () {
          isDirty = false;
          if (messenger.select(messageId)) {
            messenger.remove(messageId);
          }
        });
      });
    }
  };
})(Drupal, once);
