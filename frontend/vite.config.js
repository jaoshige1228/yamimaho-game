import { defineConfig, loadEnv } from 'vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '');
  const api = env.VITE_API_URL || 'http://localhost';
  const assetVersion =
    env.VITE_PUBLIC_ASSET_VERSION ||
    (mode === 'production' ? String(Date.now()) : '');

  return {
    plugins: [vue()],
    define: {
      'import.meta.env.VITE_PUBLIC_ASSET_VERSION': JSON.stringify(assetVersion),
    },
    server: {
      port: 5173,
      proxy: {
        '/api': { target: api, changeOrigin: true },
      },
    },
    build: {
      outDir: 'dist',
      emptyOutDir: true,
    },
  };
});
