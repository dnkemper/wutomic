/**
 * @file
 * AJAX handler for the Ampersand newsletter subscribe block.
 *
 * Intercepts the form POST and submits to Emma via fetch().
 * Falls back to standard form submission if JS fails or CORS blocks.
 */
(function (Drupal) {
  'use strict';

  Drupal.behaviors.subscribeBlock = {
    attach: function (context) {
      const forms = once('subscribe-block', '.subscribe-block__form', context);

      forms.forEach(function (form) {
        form.addEventListener('submit', function (e) {
          e.preventDefault();

          const input = form.querySelector('.subscribe-block__input');
          const button = form.querySelector('.subscribe-block__submit');
          const messages = form.querySelector('.subscribe-block__messages');
          const email = input.value.trim();

          // Clear previous messages.
          messages.textContent = '';
          messages.className = 'subscribe-block__messages';

          // Basic email validation.
          if (!email || !email.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
            messages.textContent = 'Please enter a valid email address.';
            messages.classList.add('subscribe-block__messages--error');
            input.focus();
            return;
          }

          // Disable form while submitting.
          input.disabled = true;
          button.disabled = true;
          button.classList.add('subscribe-block__submit--loading');

          var formData = new FormData(form);

          fetch(form.action, {
            method: 'POST',
            body: formData,
          })
            .then(function (response) {
              if (response.ok) {
                // Emma returns HTML on success; treat any 2xx as success.
                messages.textContent = 'Thanks! A confirmation email is on its way.';
                messages.classList.add('subscribe-block__messages--success');
                input.value = '';
              }
              else {
                throw new Error('Signup request failed.');
              }
            })
            .catch(function () {
              // If CORS blocks the fetch, fall back to native form submit.
              // This will navigate the user to Emma's hosted thank-you page.
              form.removeEventListener('submit', arguments.callee);
              form.submit();
            })
            .finally(function () {
              input.disabled = false;
              button.disabled = false;
              button.classList.remove('subscribe-block__submit--loading');
            });
        });
      });
    },
  };
})(Drupal);
