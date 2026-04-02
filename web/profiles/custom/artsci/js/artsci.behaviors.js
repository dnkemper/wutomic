/**
 * @file
 * Artsci global scripts. Attached to every page.
 */

(function ($, Drupal, once) {
    Drupal.behaviors.artsci = {
      attach: function (context, setting) {
        $(once('artsci', 'body', context)).each(function () {
          console.log(
            'This is a Artsci',
            setting.artsci.version,
            'site.',
            'For more information, please visit https://artsci.artsci.edu.'
          );
        });
      }
    };
  })(jQuery, Drupal, once);
