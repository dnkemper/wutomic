(function ($, Drupal, once) {

  // Shim $.trim for jQuery 4+ (removed in jQ4, needed by Chosen 1.8.x).
  if (typeof $.trim !== 'function') {
    $.trim = function (text) {
      return text == null ? '' : (text + '').trim();
    };
  }

  Drupal.behaviors.selectChosen = {
    attach: function (context) {
      $(once('selectChosen', "select[multiple='multiple']", context)).each(function () {
        $(this).chosen({
          placeholder_text_multiple: '- Select -',
          width: '100%',
        });
      });
    },
  };
})(jQuery, Drupal, once);
