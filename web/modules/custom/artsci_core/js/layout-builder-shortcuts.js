/**
 * @file
 */
(function ($, Drupal) {

  Drupal.behaviors.layoutBuilderBlockDoubleClick = {
    attach: function(context) {
      Array.prototype.forEach.call(context.querySelectorAll('#layout-builder [data-layout-block-uuid]'), function (block) {
        $(block).on('dblclick', function(e) {
          e.preventDefault();
          e.stopPropagation();
          $(this).find('.contextual-links a[href^="/layout_builder/update/block/overrides"]').click();
        })
      });
    }
  }

})(jQuery, Drupal);
