/* eslint-disable @typescript-eslint/no-require-imports */
/** @type {import('tailwindcss').Config} */
module.exports = {
  presets: [
    require('tailwindcss-preset-email'),
  ],
  content: [
    './components/**/*.html',
    './templates/**/*.html',
    './layouts/**/*.html',
  ],
  theme: {
    extend: {
      colors: {
        canvas: '#f7f6f3',
        surface: '#ffffff',
        foreground: '#292928',
        muted: '#f7f6f3',
        'muted-foreground': '#5a5a59',
        border: '#eae8e5',
        primary: '#ddd6fe',
        'primary-text': '#6d28d9',
        'success-subtle': '#d9f1d1',
        'success-text': '#337046',
        'critical-subtle': '#ffdbd6',
        'destructive-text': '#7f0f00',
      },
      fontFamily: {
        sans: ['Inter', 'Arial', 'Helvetica', 'sans-serif'],
        heading: ['Outfit', 'Inter', 'Arial', 'Helvetica', 'sans-serif'],
      },
    },
  },
}
