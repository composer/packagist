(async () => {
    const esbuild = require('esbuild');
    const { sassPlugin } = require('esbuild-sass-plugin');

    const dev = process.argv.includes('--dev');
    const options = {
        logLevel: 'info',
        entryPoints: ['js/app.js', 'js/charts.js', 'js/package_downloads.js'],
        bundle: true,
        outdir: 'web/build',
        sourcemap: dev,
        minify: !dev,
        metafile: process.argv.includes('--analyze'),
        loader: {
            '.eot':'file',
            '.ttf':'file',
            '.svg':'file',
            '.woff':'file',
            '.woff2':'file',
        },
        target: ['chrome60', 'firefox60', 'safari14.1', 'edge95'],
        plugins: [sassPlugin()],
    };

    if (dev) {
        const ctx = await esbuild.context(options);
        await ctx.watch();
        return;
    }

    const result = await esbuild.build(options);

    if (process.argv.includes('--analyze')) {
        const text = await esbuild.analyzeMetafile(result.metafile)
        console.log(text);
    }
})().catch((e) => console.error(e) || process.exit(1));
