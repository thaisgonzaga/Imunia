import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vueDevTools from 'vite-plugin-vue-devtools'

// https://vite.dev/config/
export default defineConfig({
  plugins: [
    vue(),
    vueDevTools(),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    port: Number(process.env.PORT) || 5173,
    strictPort: false,
    proxy: {
      '/api': {
        target: 'http://localhost',
        changeOrigin: true,
      },
      // O modo SPA do Sanctum exige que o navegador veja frontend e backend
      // como a mesma origem: a rota do cookie CSRF passa pelo mesmo proxy.
      '/sanctum': {
        target: 'http://localhost',
        changeOrigin: true,
      },
    },
  },
})
