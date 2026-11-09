// Carosello: frecce, pallini e autoplay. Con effect "slide" l'indice viene dallo scroll; con "fade" dalla classe is-active.
const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

for (const root of document.querySelectorAll('.nc-slider')) {
  const track = root.querySelector('.nc-slider__track');
  const slides = [...root.querySelectorAll('.nc-slider__slide')];
  if (!track || slides.length === 0) {
    continue;
  }

  const prev = root.querySelector('.nc-slider__prev');
  const next = root.querySelector('.nc-slider__next');
  const toggle = root.querySelector('.nc-slider__toggle');
  const dots = [...root.querySelectorAll('.nc-slider__dot')];
  const fade = root.dataset.effect === 'fade';
  const loop = root.dataset.loop !== 'false';
  const pauseOnHover = root.dataset.pauseOnHover !== 'false';
  const autoplay = Math.min(30, Math.max(0, parseInt(root.dataset.autoplay, 10) || 0));
  const last = slides.length - 1;
  const behavior = reducedMotion ? 'auto' : 'smooth';

  let current = Math.max(0, slides.findIndex((slide) => slide.classList.contains('is-active')));
  let playing = autoplay > 0 && !reducedMotion;
  let held = false;
  let timer = 0;
  let frame = 0;

  const setActive = (index) => {
    current = index;
    slides.forEach((slide, n) => slide.classList.toggle('is-active', n === index));
    dots.forEach((dot, n) => {
      const on = n === index;
      dot.classList.toggle('is-active', on);
      if (on) {
        dot.setAttribute('aria-current', 'true');
      } else {
        dot.removeAttribute('aria-current');
      }
    });
    if (prev) {
      prev.disabled = !loop && index === 0;
    }
    if (next) {
      next.disabled = !loop && index === last;
    }
  };

  const goTo = (index) => {
    setActive(index);
    if (!fade) {
      const offset = slides[index].getBoundingClientRect().left - track.getBoundingClientRect().left;
      track.scrollTo({ left: track.scrollLeft + offset, behavior });
    }
  };

  const step = (direction) => {
    const index = current + direction;
    if (loop) {
      goTo((index + slides.length) % slides.length);
    } else if (index >= 0 && index <= last) {
      goTo(index);
    }
  };

  const updateToggle = () => {
    if (!toggle) {
      return;
    }
    toggle.setAttribute('aria-label', playing ? toggle.dataset.labelPause : toggle.dataset.labelPlay);
    toggle.querySelector('span').textContent = playing ? '❚❚' : '▶';
  };

  const sync = () => {
    clearTimeout(timer);
    if (playing && !held && autoplay > 0) {
      timer = setTimeout(tick, autoplay * 1000);
    }
  };

  // Senza loop l'autoplay fa un giro, torna alla prima slide e si ferma.
  const tick = () => {
    if (!loop && current === last) {
      goTo(0);
      playing = false;
      updateToggle();
      return;
    }
    step(1);
    sync();
  };

  if (!fade) {
    track.addEventListener('scroll', () => {
      if (frame) {
        return;
      }
      frame = requestAnimationFrame(() => {
        frame = 0;
        const left = track.getBoundingClientRect().left;
        let nearest = 0;
        let best = Infinity;
        slides.forEach((slide, n) => {
          const distance = Math.abs(slide.getBoundingClientRect().left - left);
          if (distance < best) {
            best = distance;
            nearest = n;
          }
        });
        if (nearest !== current) {
          setActive(nearest);
        }
      });
    }, { passive: true });
  }

  if (prev) {
    prev.addEventListener('click', () => step(-1));
  }
  if (next) {
    next.addEventListener('click', () => step(1));
  }
  dots.forEach((dot, n) => dot.addEventListener('click', () => goTo(n)));

  if (toggle) {
    toggle.addEventListener('click', () => {
      playing = !playing;
      updateToggle();
      sync();
    });
  }

  if (autoplay > 0 && pauseOnHover) {
    const hold = (value) => {
      held = value;
      sync();
    };
    root.addEventListener('pointerenter', () => hold(true));
    root.addEventListener('pointerleave', () => hold(false));
    root.addEventListener('focusin', () => hold(true));
    root.addEventListener('focusout', (event) => {
      if (!root.contains(event.relatedTarget)) {
        hold(false);
      }
    });
  }

  setActive(current);
  updateToggle();
  sync();
}
