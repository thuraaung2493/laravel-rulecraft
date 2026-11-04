/* Maintainer tool. NODE_PATH may point to an external tooling installation. */
const fs = require('node:fs');
const path = require('node:path');
const postcss = require('postcss');
const prefixer = require('postcss-prefix-selector');
const prefix = '.rulecraft-builder:where(.rc-theme-bootstrap)';
(async () => {
    const source = process.argv[2];
    if (!source) throw new Error('Usage: node tools/build-bootstrap.cjs bootstrap.css');
    const result = await postcss([
        prefixer({
            prefix,
            transform(scope, selector) {
                if (selector === ':root' || selector === '[data-bs-theme=light]') return scope;
                if (selector === '[data-bs-theme=dark]') return scope + '[data-bs-theme=dark]';
                if (selector === 'body') return scope;
                return scope + ' ' + selector;
            },
        }),
    ]).process(fs.readFileSync(source, 'utf8'), { from: undefined });
    fs.writeFileSync(path.join(__dirname, '../resources/builder/bootstrap.css'), result.css);
})().catch((error) => {
    process.stderr.write(error.message + '\n');
    process.exitCode = 1;
});
