import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  test: { environment: 'jsdom', include: ['docs/verification/cloud-service-independent-20261007/service-denial-privacy.test.tsx'],
    setupFiles: ['resources/js/test/setup.ts'] },
});
