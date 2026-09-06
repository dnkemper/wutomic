/**
 * @file
 * Academic calendar: print only the calendar view display.
 *
 * Rather than printing the whole page and hiding the rest with CSS (which
 * leaves the surrounding theme chrome and breaks pagination on long lists),
 * this clones just the view into an off-screen iframe sized like a printed
 * page, carries over the document's stylesheets so the calendar keeps its
 * styling, and prints that iframe.
 */
((Drupal, once) => {
  /**
   * Prints a single element in isolation via a temporary iframe.
   *
   * @param {HTMLElement} view
   *   The .academic-calendar view wrapper to print.
   */
  function printView(view) {
    const iframe = document.createElement('iframe');
    iframe.setAttribute('aria-hidden', 'true');
    // Off-screen, but sized like a real page. A zero-size iframe gives the
    // document a zero-width viewport, which collapses the columns and wraps
    // every word onto its own line, so a real width is required.
    iframe.style.position = 'fixed';
    iframe.style.top = '0';
    iframe.style.left = '-10000px';
    iframe.style.width = '8.5in';
    iframe.style.height = '11in';
    iframe.style.border = '0';
    document.body.appendChild(iframe);

    const doc = iframe.contentWindow.document;

    // Carry over the site's stylesheets so the printed calendar keeps its
    // fonts and styling. A <base> resolves any relative URLs against the site.
    let head = '<base href="' + window.location.origin + '/">';
    document
      .querySelectorAll('link[rel="stylesheet"], style')
      .forEach((node) => {
        head += node.outerHTML;
      });

    // Hide the interactive controls (print button, semester stepper, search):
    // they have no value on paper. The subscribe link, dates, titles and the
    // per-event Add to Calendar are kept, matching the production design.
    head +=
      '<style>' +
      'html,body{margin:0;padding:0;background:#fff;width:100%;}' +
      '.academic-calendar__bar--controls,' +
      '.academic-calendar__search{display:none !important;}' +
      'academic-calendar{width:100%;}' +
      '.academic-calendar span.add-to-calendar{display:flex !important;}' +
      '.views-row{width:100%;}' +
      '.layout-region-first{grid-area:unset !important;width: 33%;display:flex !important;}' +
      '.layout-region-second{grid-area:unset !important;width: 33%;display: flex !important;}' +
      '.layout-region-third{grid-area:unset !important;width: 25%;display: flex !important;}' +
      '.layout__spacing_container{display:flex !important;flex-direction:row-reverse;align-items:flex-start; grid-template-rows: 100px;flex-direction:row;flex-wrap:nowrap;gap:0;margin: 0;width:100%;}' +
      '</style>';

    doc.open();
    doc.write(
      '<!DOCTYPE html><html><head>' +
        head +
        '</head><body class="academic-calendar-print">' +
        view.outerHTML +
        '</body></html>',
    );
    doc.close();

    const trigger = () => {
      const win = iframe.contentWindow;
      win.focus();
      const fire = () => {
        win.print();
        window.setTimeout(() => iframe.remove(), 1000);
      };
      // Wait for web fonts so the serif dates render correctly.
      if (win.document.fonts && win.document.fonts.ready) {
        win.document.fonts.ready.then(fire).catch(fire);
      } else {
        window.setTimeout(fire, 400);
      }
    };

    if (iframe.contentWindow.document.readyState === 'complete') {
      window.setTimeout(trigger, 300);
    } else {
      iframe.addEventListener('load', () => window.setTimeout(trigger, 300));
    }
  }

  Drupal.behaviors.academicCalendarPrint = {
    attach(context) {
      once(
        'academic-calendar-print',
        '.js-academic-calendar-print',
        context,
      ).forEach((button) => {
        button.addEventListener('click', () => {
          const view = button.closest('.view-academic-calendar-of-events');
          if (view) {
            printView(view);
          } else {
            window.print();
          }
        });
      });
    },
  };
})(Drupal, once);
