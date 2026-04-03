import { applyClickA11y } from './click-a11y.js';

(function ($, Drupal) {
  "use strict";

  Drupal.behaviors.clickA11y = {
    attach: function (context, settings) {
      applyClickA11y('.click-container:not([data-artsci-no-link])');
    }
  };
})(jQuery, Drupal);
