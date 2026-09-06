import { applyAccordion } from '../../assets/js/accordion.js';

(function ($, Drupal) {
  "use strict";

  Drupal.behaviors.initAccordions = {
    attach: function (context, settings) {
      applyAccordion('.accordion, .dotted-line');
    }
  };
})(jQuery, Drupal);
