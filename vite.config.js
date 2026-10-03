import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [vue()],
    define: {
        'process.env.NODE_ENV': JSON.stringify('production'),
    },
    build: {
        lib: {
            entry: 'Resources/Private/Frontend/Vue/ai-assistant-premium.js',
            formats: ['iife'],
            name: 'AiAssistantPremiumElements',
            fileName: () => 'ai-assistant-premium.js',
        },
        outDir: 'Resources/Public/JavaScript',
        emptyOutDir: false,
    },
});
