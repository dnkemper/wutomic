/**
 * @file
 * oEmbed Thumbnail click-to-play functionality.
 *
 * Replaces the thumbnail with the actual oEmbed iframe when clicked.
 */
(function (Drupal, once) {

  'use strict';

  Drupal.behaviors.oembedThumbnail = {
    attach: function (context) {
      const wrappers = once('oembed-thumbnail', '[data-oembed-thumbnail]', context);

      wrappers.forEach(function (wrapper) {
        const poster = wrapper.querySelector('[data-oembed-poster]');
        const videoContainer = wrapper.querySelector('[data-oembed-video]');
        const iframeTemplate = wrapper.querySelector('[data-oembed-iframe-template]');
        const playButton = wrapper.querySelector('[data-oembed-play-btn]');
        const videoControls = wrapper.querySelector('.video-controls');

        if (!poster || !videoContainer || !iframeTemplate) {
          console.warn('oEmbed thumbnail: Missing required elements', {
            poster: !!poster,
            videoContainer: !!videoContainer,
            iframeTemplate: !!iframeTemplate
          });
          return;
        }

        /**
         * Load the video iframe and hide the poster.
         */
        function loadVideo() {
          // Clone the iframe from the template.
          const iframe = iframeTemplate.content.cloneNode(true);

          // Insert the iframe into the video container.
          videoContainer.appendChild(iframe);

          // Hide the poster and show the video.
          poster.style.display = 'none';
          videoContainer.style.display = 'block';

          // Hide the play button controls.
          if (videoControls) {
            videoControls.style.display = 'none';
          }

          // Add loaded class for styling.
          wrapper.classList.add('oembed-thumbnail--loaded');
        }

        // Handle click on the play button.
        if (playButton) {
          playButton.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            loadVideo();
          });

          // Handle keyboard interaction.
          playButton.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
              e.preventDefault();
              loadVideo();
            }
          });
        }

        // Also handle click on the poster image itself.
        poster.addEventListener('click', function (e) {
          e.preventDefault();
          loadVideo();
        });
      });
    }
  };

})(Drupal, once);
