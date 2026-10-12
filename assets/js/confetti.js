/**
 * confetti.js — a few seconds of paper confetti on a full-screen canvas.
 * Deliberately dependency free and tiny.
 */

const COLORS = ['#f43f5e', '#f59e0b', '#10b981', '#0ea5e9', '#8b5cf6', '#ec4899', '#facc15'];

export function confettiBurst({ duration = 2600, count = 140 } = {}) {
  if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

  const canvas = document.createElement('canvas');
  canvas.className = 'confetti-canvas';
  document.body.appendChild(canvas);
  const ctx = canvas.getContext('2d');
  let w = (canvas.width = window.innerWidth);
  let h = (canvas.height = window.innerHeight);

  const onResize = () => {
    w = canvas.width = window.innerWidth;
    h = canvas.height = window.innerHeight;
  };
  window.addEventListener('resize', onResize);

  const pieces = Array.from({ length: count }, () => ({
    x: Math.random() * w,
    y: -20 - Math.random() * h * 0.4,
    r: 6 + Math.random() * 8,
    vy: 2.2 + Math.random() * 3.4,
    vx: -1.6 + Math.random() * 3.2,
    rot: Math.random() * Math.PI,
    vr: -0.12 + Math.random() * 0.24,
    color: COLORS[(Math.random() * COLORS.length) | 0],
  }));

  const started = performance.now();
  let raf = 0;

  const frame = (now) => {
    const age = now - started;
    ctx.clearRect(0, 0, w, h);
    for (const p of pieces) {
      p.x += p.vx;
      p.y += p.vy;
      p.rot += p.vr;
      if (p.y > h + 30) {
        p.y = -20;
        p.x = Math.random() * w;
      }
      ctx.save();
      ctx.translate(p.x, p.y);
      ctx.rotate(p.rot);
      ctx.fillStyle = p.color;
      ctx.globalAlpha = age > duration - 600 ? Math.max(0, (duration - age) / 600) : 1;
      ctx.fillRect(-p.r / 2, -p.r / 2, p.r, p.r * 0.62);
      ctx.restore();
    }
    if (age < duration) {
      raf = requestAnimationFrame(frame);
    } else {
      cancelAnimationFrame(raf);
      window.removeEventListener('resize', onResize);
      canvas.remove();
    }
  };
  raf = requestAnimationFrame(frame);
}
