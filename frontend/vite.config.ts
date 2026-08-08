import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  base: './',
  build: {
    outDir: '../public',
    emptyOutDir: false,
    sourcemap: false
  },
  server: {
    proxy: { '/api.php': 'https://email.axr.ar' }
  }
});
