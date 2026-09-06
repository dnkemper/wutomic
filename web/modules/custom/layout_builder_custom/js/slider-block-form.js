(function (Drupal, once) {

  'use strict';
  Drupal.behaviors.sliderExtend = {
    attach: function (context) {
      // Key on the media library widget, not the tracker. Inserting media
      // replaces the widget element via AJAX, so once() re-runs here and we
      // re-bind a fresh observer each time (the same reason the media-style
      // behavior below survives an upload). The tracker is a sibling in the
      // subform and is NOT replaced, so binding directly to it would leave the
      // observer watching a detached node after the first upload, and the
      // tracker would never flip to the selected bundle.
      once('slider-media-type', '.media-library-widget', context).forEach(function (mediaField) {
        // The tracker lives alongside this widget in the same slide subform.
        const slide = mediaField.closest('.paragraphs-subform') || mediaField.closest('form');
        const tracker = slide ? slide.querySelector('[data-media-type-tracker]') : null;
        if (!tracker) return;

        // Resolve the selected media's bundle and write it into the tracker so
        // the slide link's #states can react.
        const updateMediaType = function () {
          const selectedItem = mediaField.querySelector('.media-library-item[data-media-library-item-delta]');

          if (!selectedItem) {
            tracker.value = '';
            tracker.dispatchEvent(new Event('change', { bubbles: true }));
            return;
          }

          const mediaId = mediaField.querySelector('input[type="hidden"][name*="target_id"]')?.value ||
                          mediaField.querySelector('input[type="hidden"][name*="selection"]')?.value;
          if (!mediaId) return;

          fetch(Drupal.url('layout-builder-custom/media-type/' + mediaId))
            .then(response => response.json())
            .then(data => {
              tracker.value = data.bundle || '';
              tracker.dispatchEvent(new Event('change', { bubbles: true }));
            })
            .catch(() => {
              tracker.value = '';
              tracker.dispatchEvent(new Event('change', { bubbles: true }));
            });
        };

        // Catch in-place selection changes (e.g. remove) on this widget.
        const observer = new MutationObserver(function () {
          updateMediaType();
        });
        observer.observe(mediaField, { childList: true, subtree: true });

        // Initial check on attach (runs again whenever the widget is replaced).
        updateMediaType();
      });
    }
  };

  // Behaviors for slider video and background options.
  Drupal.behaviors.sliderMediaStyles = {
    attach: function (context) {
      // Video autoplay handling
      // We target the .media-library-widget class because there are not a lot of
      // good choices for classes/IDs to target in the inline block form.
      once('media-form-attach', '.media-library-widget', context).forEach(function (element) {
        // Check that we can access the next field.
        const checkbox_wrapper = context.querySelector('div[data-drupal-selector$="autoplay-wrapper"]');
        if (checkbox_wrapper) {
          // Check if the referenced media is a video.
          const mediaTypeVideo = context.querySelector('.media--video');

          if (mediaTypeVideo) {
            // Show the autoplay field.
            checkbox_wrapper.classList.remove('js-hide');
            checkbox_wrapper.removeAttribute('tabindex');
            checkbox_wrapper.removeAttribute('aria-hidden');
          } else {
            // Hide the autoplay field.
            checkbox_wrapper.classList.add('js-hide');
            checkbox_wrapper.tabIndex = -1;
            checkbox_wrapper.setAttribute('aria-hidden', 'true');
          }
        }
      });

      // Media style fields and overlay handling.
      once('media-style-handler', '.media-library-widget', context).forEach(function (widgetEl) {
        const form = widgetEl.closest('form');
        if (!form) return;

        const mediaOverlay = form.querySelector('select[name="layout_builder_style_media_overlay"]');
        const gradientMidpointRadios = form.querySelectorAll('input[name="settings[block_form][field_styles_gradient_midpoint]"]');

        // Media style field wrappers (duplicates in the media_group container).
        const mediaPositionWrapper = form.querySelector('[name="layout_builder_style_card_media_position_duplicate"]')?.closest('.form-item, .form-wrapper');
        const mediaFormatWrapper = form.querySelector('[name="layout_builder_style_media_format_duplicate"]')?.closest('.form-item, .form-wrapper');
        const mediaSizeWrapper = form.querySelector('[name="layout_builder_style_media_size_duplicate"]')?.closest('.form-item, .form-wrapper');

        // Card background field and its dependencies.
        const cardBackgroundWrapper = form.querySelector('select[name="layout_builder_style_card_card_background"]')?.closest('.form-item, .form-wrapper');
        const defaultStyleCheckboxes = form.querySelectorAll('input[name^="layout_builder_style_default"]');

        // True when ANY slide has a selected media item. The format/size/
        // position selectors are block-level (one set for every slide), so they
        // appear as soon as at least one slide carries media.
        const anySlideHasMedia = function () {
          return !!form.querySelector('[data-drupal-selector*="field-artsci-slide-image"] .media-library-item[data-media-library-item-delta]');
        };

        // Helper to show/hide an element.
        function setElementVisibility(element, visible) {
          if (!element) return;
          
          if (visible) {
            element.classList.remove('js-hide');
            element.removeAttribute('tabindex');
            element.removeAttribute('aria-hidden');
          } else {
            element.classList.add('js-hide');
            element.tabIndex = -1;
            element.setAttribute('aria-hidden', 'true');
          }
        }

        // Handle media style fields visibility based on media selection.
        function handleMediaStyleFieldsVisibility() {
          const hasMediaValue = anySlideHasMedia();

          setElementVisibility(mediaPositionWrapper, hasMediaValue);
          setElementVisibility(mediaFormatWrapper, hasMediaValue);
          setElementVisibility(mediaSizeWrapper, hasMediaValue);
        }

        // Handle card background visibility based on offset content and media selection.
        function handleCardBackgroundVisibility() {
          if (!cardBackgroundWrapper) return;

          // Check if card_offset_content is selected.
          const offsetContentCheckbox = form.querySelector(
            'input[name^="layout_builder_style_default"][value="card_offset_content"]'
          );
          const isOffsetContentSelected = offsetContentCheckbox && offsetContentCheckbox.checked;

          // Check if any slide has media.
          const hasMediaValue = anySlideHasMedia();

          setElementVisibility(cardBackgroundWrapper, isOffsetContentSelected && hasMediaValue);
        }

        // Handle media overlay changes to auto-set gradient midpoint.
        function handleMediaOverlayChange() {
          if (mediaOverlay && gradientMidpointRadios.length > 0) {
            const overlayValue = mediaOverlay.value;

            // Check if any radio is currently selected.
            const currentlySelected = context.querySelector(
              'input[name="settings[block_form][field_styles_gradient_midpoint]"]:checked',
            );

            // Only auto-set if no option is currently selected.
            if (!currentlySelected && overlayValue) {
              let midpointValue = "40%";

              if (overlayValue === "media_overlay_left_to_right") {
                midpointValue = "70%";
              }

              // Find and select the radio button.
              const targetRadio = context.querySelector(
                `input[name="settings[block_form][field_styles_gradient_midpoint]"][value="${midpointValue}"]`,
              );
              if (targetRadio) {
                targetRadio.checked = true;
              }
            } else if (!overlayValue) {
              // Clear midpoint when overlay is cleared.
              gradientMidpointRadios.forEach(function (radio) {
                radio.checked = false;
              });
            }
          }
        }

        // Bind change event to media overlay dropdown.
        if (mediaOverlay) {
          mediaOverlay.addEventListener("change", handleMediaOverlayChange);
        }

        // Bind change event to default style checkboxes for card background visibility.
        defaultStyleCheckboxes.forEach(function (checkbox) {
          checkbox.addEventListener('change', handleCardBackgroundVisibility);
        });

        // Watch this slide's media widget. Each slide widget gets its own
        // observer and they all recompute the shared any-slide visibility, so
        // adding or clearing media on any slide updates the block-level
        // selectors.
        const mediaObserver = new MutationObserver(function () {
          handleMediaStyleFieldsVisibility();
          handleCardBackgroundVisibility();
        });

        mediaObserver.observe(widgetEl, {
          childList: true,
          subtree: true,
        });

        // Initial checks.
        handleMediaOverlayChange();
        handleMediaStyleFieldsVisibility();
        handleCardBackgroundVisibility();
      });
    },
  };

})(Drupal, once);
