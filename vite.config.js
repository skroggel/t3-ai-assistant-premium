import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    define: {
        'process.env.NODE_ENV': JSON.stringify('production'),
    },
    build: {
        lib: {
            entry: 'Resources/Private/Frontend/Vue/ai-assistant-premium.js',
            formats: ['iife'],
            name: 'AiAssistantPremiumElements',
            fileName: () => 'JavaScript/ai-assistant-premium.js',
            cssFileName: 'Css/t3-ai-assistant-premium-frontend',
        },
        // combine all css in ONE file
        cssCodeSplit: false,
        outDir: 'Resources/Public',
        emptyOutDir: false,
    },
    plugins: [
        // treat as SingleFileComponent while compiling (only while compiling!)
        vue({
            customElement: false,
        }),
    ],
});
