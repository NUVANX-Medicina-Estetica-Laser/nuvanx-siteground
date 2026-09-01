(function () {
  'use strict';

  function startHomeVideo() {
    var video = document.getElementById('nvx-home-hero-video');
    if (!video) return;

    var toggle = document.getElementById('nvx-hero-video-toggle');
    var hero = video.closest('.nvx-home-hero');

    var posterMobile = video.getAttribute('data-poster-mobile');
    if (posterMobile && window.matchMedia && window.matchMedia('(max-width: 768px)').matches) {
      video.setAttribute('poster', posterMobile);
    }

    function usePosterFallback() {
      video.pause();
      video.hidden = true;
      if (hero) hero.classList.add('is-video-poster');
      if (toggle) toggle.hidden = true;
    }

    // Media failures are owned here, not by inline onerror/style attributes.
    video.addEventListener('error', usePosterFallback);

    // A11y guard: respect user preferences for reduced motion.
    var prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (prefersReducedMotion) {
      usePosterFallback();
      return;
    }

    video.muted = true;
    video.playsInline = true;
    video.setAttribute('muted', '');
    video.setAttribute('playsinline', '');

    function tryPlay() {
      var p = video.play();
      if (p && typeof p.catch === 'function') {
        p.catch(usePosterFallback);
      }
    }

    function initVideo() {
      if (video.readyState >= 2) {
        tryPlay();
      } else {
        video.addEventListener('loadeddata', tryPlay, { once: true });
        video.load();
      }
    }

    if (typeof IntersectionObserver === 'function') {
      var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            initVideo();
          } else {
            video.pause();
          }
        });
      }, { rootMargin: '200px' });
      observer.observe(video);
    } else {
      initVideo();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', startHomeVideo);
  } else {
    startHomeVideo();
  }
})();
