((Drupal, drupalSettings) => {
  // @todo remove this when https://github.com/artsci/artsci/issues/8430 is resolved.
  // Check if Leaflet is initialized.
  if (typeof L !== 'undefined' && document.querySelector('[id^="leaflet-map"]')) {
  Drupal.attachBehaviors(document);
}
})(Drupal, drupalSettings);
