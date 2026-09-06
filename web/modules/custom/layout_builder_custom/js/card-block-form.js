(function (Drupal, once) {

  'use strict';
  Drupal.behaviors.cardExtend = {
    attach: function (context, settings) {
      // Find the media type tracker input.
      const trackers = once('card-media-type', '[data-media-type-tracker]', context);
      
      trackers.forEach(function (tracker) {
        // Find the media library widget container.
        const form = tracker.closest('form');
        if (!form) return;

        const mediaField = form.querySelector('[data-drupal-selector*="field-artsci-card-image"]');
        if (!mediaField) return;

        // Function to update tracker based on selected media.
        const updateMediaType = function () {
          // Look for the selected media item's data.
          const selectedItem = mediaField.querySelector('.media-library-item[data-media-library-item-delta]');
          
          if (selectedItem) {
            // Try to get media type from data attribute or fetch via AJAX.
            const mediaId = mediaField.querySelector('input[type="hidden"][name*="target_id"]')?.value ||
                           mediaField.querySelector('input[type="hidden"][name*="selection"]')?.value;
            
            if (mediaId) {
              // Fetch media type via AJAX.
              fetch(Drupal.url('layout-builder-custom/media-type/' + mediaId))
                .then(response => response.json())
                .then(data => {
                  tracker.value = data.bundle || '';
                  // Trigger change event so #states updates.
                  tracker.dispatchEvent(new Event('change', { bubbles: true }));
                })
                .catch(() => {
                  tracker.value = '';
                  tracker.dispatchEvent(new Event('change', { bubbles: true }));
                });
            }
          } else {
            // No media selected.
            tracker.value = '';
            tracker.dispatchEvent(new Event('change', { bubbles: true }));
          }
        };

        // Watch for changes in the media library widget.
        const observer = new MutationObserver(function (mutations) {
          updateMediaType();
        });

        observer.observe(mediaField, {
          childList: true,
          subtree: true,
        });

        // Initial check.
        updateMediaType();
      });
    }
  };

  // Behaviors for card video and background options.
  Drupal.behaviors.cardMediaStyles = {
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
      once('media-style-handler', '.media-library-widget', context).forEach(function () {
        const mediaField = context.querySelector('[data-drupal-selector*="field-artsci-card-image"]');
        const mediaOverlay = context.querySelector('select[name="layout_builder_style_media_overlay"]');
        const gradientMidpointRadios = context.querySelectorAll('input[name="settings[block_form][field_styles_gradient_midpoint]"]');
        
        // Media style field wrappers (duplicates in the media_group container).
        const mediaPositionWrapper = context.querySelector('[name="layout_builder_style_card_media_position_duplicate"]')?.closest('.form-item, .form-wrapper');
        const mediaFormatWrapper = context.querySelector('[name="layout_builder_style_media_format_duplicate"]')?.closest('.form-item, .form-wrapper');
        const mediaSizeWrapper = context.querySelector('[name="layout_builder_style_media_size_duplicate"]')?.closest('.form-item, .form-wrapper');

        // Card background field and its dependencies.
        const cardBackgroundWrapper = context.querySelector('select[name="layout_builder_style_card_card_background"]')?.closest('.form-item, .form-wrapper');
        const defaultStyleCheckboxes = context.querySelectorAll('input[name^="layout_builder_style_default"]');

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
          const hasMediaValue = mediaField && mediaField.querySelector('.media-library-item[data-media-library-item-delta]');

          setElementVisibility(mediaPositionWrapper, hasMediaValue);
          setElementVisibility(mediaFormatWrapper, hasMediaValue);
          setElementVisibility(mediaSizeWrapper, hasMediaValue);
        }

        // Handle card background visibility based on offset content and media selection.
        function handleCardBackgroundVisibility() {
          if (!cardBackgroundWrapper) return;

          // Check if card_offset_content is selected.
          const offsetContentCheckbox = context.querySelector(
            'input[name^="layout_builder_style_default"][value="card_offset_content"]'
          );
          const isOffsetContentSelected = offsetContentCheckbox && offsetContentCheckbox.checked;

          // Check if media field has a value.
          const hasMediaValue = mediaField && mediaField.querySelector('.media-library-item[data-media-library-item-delta]');

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

        // Watch for changes in the media field.
        if (mediaField) {
          const mediaObserver = new MutationObserver(function () {
            handleMediaStyleFieldsVisibility();
            handleCardBackgroundVisibility();
          });

          mediaObserver.observe(mediaField, {
            childList: true,
            subtree: true,
          });
        }

        // Initial checks.
        handleMediaOverlayChange();
        handleMediaStyleFieldsVisibility();
        handleCardBackgroundVisibility();
      });
    },
  };

})(Drupal, once);
