import { defineConfig } from 'eslint/config';
import globals from 'globals';
import js from '@eslint/js';

export default defineConfig([
    {
        ignores: ['assets/vendor/'],
    },
    {
        files: ['assets/**/*.js'],
        languageOptions: {
            globals: globals.browser,
            sourceType: 'module',
        },
        plugins: { js },
        extends: ['js/recommended'],
    },
]);
