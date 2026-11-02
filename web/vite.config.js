import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// La build va in web/dist con percorsi relativi: online la copia _deploy\carica.bat in apps/bollette-chiare.
// In sviluppo (npm run dev) le API arrivano dalla copia in XAMPP: http://localhost/gianlucadario/apps/bollette-chiare/api
const API = 'http://localhost/gianlucadario/apps/bollette-chiare';

export default defineConfig(({ command }) => {
  // Su questo PC NODE_ENV vale "development": senza questa riga la build conterrebbe React di sviluppo.
  if (command === 'build') process.env.NODE_ENV = 'production';
  return {
    base: './',
    plugins: [react()],
    build: { outDir: 'dist', emptyOutDir: true },
    server: {
      port: 5175,
      proxy: { '/api': { target: API, changeOrigin: true, rewrite: (p) => p } },
    },
  };
});
