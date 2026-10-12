/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ['./index.php', './lib/**/*.php', './assets/js/**/*.js'],
  // Class names that are assembled from strings at runtime cannot be detected
  // by the content scanner, so they are listed explicitly.
  safelist: [
    'mark--outline',
    'mark--cross',
    'mark--reveal',
    'mark--link',
    'ink--fading',
    'flash-ok',
    'flash-bad',
    'is-picked',
    'is-active',
    'is-filled',
    'is-locked',
    'has-value',
    'pop',
    'shake',
    'is-used',
    'is-crossed',
    'is-solution',
    'is-bad',
    'is-drawn',
    'is-given',
    'is-on',
  ],
  theme: {
    extend: {},
  },
  plugins: [],
};
