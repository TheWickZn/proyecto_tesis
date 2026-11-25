import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

export default defineConfig({
  base: './',
  publicDir: false,
  plugins: [react()],
  build: {
    manifest: true,
    outDir: '../public/app',
    emptyOutDir: true,
    rollupOptions: {
      input: './src/main.jsx',
    },
  },
  server: {
    host: '0.0.0.0',
    port: 5173,
    proxy: {
      '/api': {
        target: 'http://localhost/sistema_tesis',
        changeOrigin: true,
      },
      '/empresa': {
        target: 'http://localhost/sistema_tesis',
        changeOrigin: true,
      },
    },
  },
})
