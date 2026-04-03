(function () {
  window.sr = ScrollReveal({
    scale: 1,
    distance: '8px',
    viewFactor: 0.2,
    duration: 400,
  });
  if (sr.isSupported()) {
    document.documentElement.classList.add('sr');
  }
})();