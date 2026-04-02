/**
 * @file
 * Artsci search CSE results functionality.
 */

(function ($, Drupal, drupalSettings, once) {

  'use strict';

  Drupal.artsciSearchResults = function() {
    let cseAttributes = {
      queryParameterName: 'terms',
    }

    if (drupalSettings.artsciSearch.cseScope === 1) {
      cseAttributes.as_sitesearch = window.location.hostname + drupalSettings.path.baseUrl;
    }

    google.search.cse.element.render({
        div: 'search-results',
        tag: 'search',
        attributes: cseAttributes
    });
  };

  // Attach artsciSearchResults behavior.
  Drupal.behaviors.artsciSearchResults = {
    attach: function(context, settings) {
      $(once('artsciSearchResults', 'body', context)).each(function() {
        window.__gcse = {
          parsetags: 'explicit',
          callback: Drupal.artsciSearchResults,
        };

        let cx = drupalSettings.artsciSearch.engineId;
        let gcse = document.createElement('script');
        gcse.type = 'text/javascript';
        gcse.async = true;
        gcse.src = 'https://cse.google.com/cse.js?cx=' + cx;
        let s = document.getElementsByTagName('script')[0];
        s.parentNode.insertBefore(gcse, s);
      });
    }
  };
})(jQuery, Drupal, drupalSettings, once);
