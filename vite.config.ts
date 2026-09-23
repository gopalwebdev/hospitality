import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import babel from '@rolldown/plugin-babel';
import tailwindcss from '@tailwindcss/vite';
import react, { reactCompilerPreset } from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { brotliCompressSync, constants, gzipSync } from 'node:zlib';
import type { Plugin } from 'vite';
import { defineConfig, lazyPlugins } from 'vite-plus';

/**
 * Every file under a build directory, walked from the top down.
 */
function builtFiles(directory: string): string[] {
    return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
        const path = join(directory, entry.name);

        return entry.isDirectory() ? builtFiles(path) : [path];
    });
}

/**
 * A Brotli and a gzip copy beside every built text asset.
 *
 * Compressed once, at build time and at Brotli's highest quality, rather than
 * by the server on every request. Whatever serves /build — nginx with
 * `brotli_static` and `gzip_static`, or the CDN in front of Laravel Cloud —
 * hands each browser the copy its Accept-Encoding asks for. Node's own zlib
 * does both, so this needs no dependency.
 */
function precompress(): Plugin {
    return {
        name: 'precompress-built-assets',
        apply: 'build',
        writeBundle(options) {
            if (options.dir === undefined) {
                return;
            }

            for (const file of builtFiles(options.dir)) {
                if (!/\.(?:css|html|js|json|mjs|svg|txt)$/.test(file)) {
                    continue;
                }

                const source = readFileSync(file);

                // Below a kilobyte a compressed copy saves less than it costs.
                if (source.length < 1024) {
                    continue;
                }

                writeFileSync(
                    `${file}.br`,
                    brotliCompressSync(source, {
                        params: {
                            [constants.BROTLI_PARAM_QUALITY]:
                                constants.BROTLI_MAX_QUALITY,
                            [constants.BROTLI_PARAM_SIZE_HINT]: source.length,
                        },
                    }),
                );

                writeFileSync(
                    `${file}.gz`,
                    gzipSync(source, { level: constants.Z_BEST_COMPRESSION }),
                );
            }
        },
    };
}

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            // One entry per app. Inertia loads the page component as its own
            // chunk on top of these, so a guest downloads the guest entry and
            // one page, and never a byte of the welcome page or of Filament.
            input: [
                'resources/css/app.css',
                'resources/js/app.tsx',
                'resources/js/guest.tsx',
            ],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        // ssr: false at the plugin's own level — config/inertia.php's
        // ssr.enabled only tells the PHP HttpGateway not to dispatch to an
        // SSR renderer for the response it builds; it says nothing to Vite.
        // Left at the plugin's default, @inertiajs/vite looks for an
        // resources/js/ssr.tsx-shaped entry on every dev boot and, finding
        // none, still opens the /__inertia_ssr dev endpoint and warms an SSR
        // module graph that this app never uses — real work, on every
        // `npm run dev`, for a server nothing ever asks. See .ai/rules/js.md.
        inertia({ ssr: false }),
        react(),
        babel({
            presets: [reactCompilerPreset()],
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
        precompress(),
    ]),
    server: {
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/vendor/**',
            ],
        },
    },
    test: {
        // Tests live outside resources/js/pages so Inertia never mistakes one
        // for a page component.
        include: ['resources/js/tests/**/*.test.{ts,tsx}'],
        environment: 'happy-dom',
        setupFiles: ['resources/js/tests/setup.ts'],
        restoreMocks: true,
    },
    lint: {
        ignorePatterns: [
            'vendor/**',
            'node_modules/**',
            'public/**',
            'tailwind.config.js',
            'resources/js/actions/**',
            'resources/js/components/ui/*',
            'resources/js/routes/**',
            'resources/js/wayfinder/**',
        ],
        options: {
            denyWarnings: true,
            typeAware: true,
        },
    },
    fmt: {
        printWidth: 80,
        tabWidth: 4,
        singleQuote: true,
        semi: true,
        singleAttributePerLine: false,
        htmlWhitespaceSensitivity: 'css',
        ignorePatterns: [
            // Generated by Laravel Boost; keep them exactly as the tool emits them.
            '.agents/**',
            '.ai/**',
            '.claude/**',
            '.cursor/**',
            '.junie/**',
            '.mcp.json',
            'AGENTS.md',
            'CLAUDE.md',
            'boost.json',
            '.github/**',
            'composer.json',
            // Build output and vendor-published assets are not ours to format.
            'public/**',
            'resources/js/components/ui/*',
            'resources/views/mail/*',
        ],
        sortTailwindcss: {
            functions: ['clsx', 'cn', 'cva'],
            entryPoint: 'resources/css/app.css',
        },
    },
});
