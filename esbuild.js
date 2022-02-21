const esbuild = require('esbuild');
const sassPlugin = require('esbuild-plugin-sass');

esbuild.build({
    logLevel: 'info',
    entryPoints: ['js/app.js', 'js/charts.js'],
    bundle: true,
    outdir: 'web/build',
    sourcemap: process.argv.includes('--dev'),
    watch: process.argv.includes('--dev'),
    minify: !process.argv.includes('--dev'),
    loader: {
        '.gif':'file',
        '.eot':'file',
        '.ttf':'file',
        '.svg':'file',
        '.woff':'file',
        '.woff2':'file',
    },
    target: ['chrome58', 'firefox57', 'safari11', 'edge95'],
    plugins: [sassPlugin()],
})
.catch(() => process.exit(1));
