(async () => {
    const esbuild = require('esbuild');
    const sassPlugin = require('esbuild-plugin-sass');

    const result = await esbuild.build({
        logLevel: 'info',
        entryPoints: ['js/app.js', 'js/charts.js'],
        bundle: true,
        outdir: 'web/build',
        sourcemap: process.argv.includes('--dev'),
        watch: process.argv.includes('--dev'),
        minify: !process.argv.includes('--dev'),
        metafile: process.argv.includes('--analyze'),
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

    if (process.argv.includes('--analyze')) {
        const text = await esbuild.analyzeMetafile(result.metafile)
        console.log(text);
    }
})().catch(() => process.exit(1));
