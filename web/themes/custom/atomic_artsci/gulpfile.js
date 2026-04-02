/**
 * @file
 * Gulpfile for theme compilation.
 */
const { src, dest, parallel, series, watch } = require('gulp');

const gulpSass = require('gulp-sass')(require('sass'));
const postcss = require('gulp-postcss');
const autoprefixer = require('autoprefixer');
const cssnano = require('cssnano');
const glob = require('gulp-sass-glob');
const mode = require('gulp-mode')();
const fs = require('fs').promises;
const path = require('path');

const paths = {
  src: `${__dirname}/scss/**/*.scss`,
  dest: `${__dirname}/assets`,
  node: `../../../../node_modules/`,
};

const brandIconSrc = `${paths.node}@artsci/brand-icons`;

const iconSets = [
  'black',
  'two-color',
];

// Clean.
function clean() {
  return import('del').then(module => {
    return module.deleteAsync([
      `${paths.dest}/css/**`,
    ]);
  });
}

async function copyIcons() {
  await Promise.all(
    iconSets.map(dir =>
      fs.mkdir(path.join(__dirname, '/assets/icons/brand/', dir), { recursive: true })
    )
  );

  const files = await fs.readdir(path.join(brandIconSrc, 'icons'));

  await Promise.all(files.map(async (filename) => {
    const sourcePath = path.join(brandIconSrc, 'icons', filename);
    const dir = filename.endsWith('-two-color.svg')
      ? iconSets[1] : iconSets[0];
    const destinationPath = path.join(__dirname, '/assets/icons/brand/' + dir, filename);

    let svg = await fs.readFile(sourcePath, 'utf-8');
    svg = modifySvg(svg);
    await fs.writeFile(destinationPath, svg);
  }));
}

function modifySvg(svg) {
  svg = svg
    .replace(/<text[^>]*>.*?<\/text>/gs, '')
    .replace(/viewBox="[^"]*"/, 'viewBox="-10 -10 70 70"')
    .replace(/(width|height)=["']\d+['"]/g, '')
    .replace('<svg', '<svg width="70" height="70"');

  if (!svg.includes('fill="white"')) {
    svg = svg.replace(
      /(<svg[^>]*>)/,
      '$1<rect x="-10" y="-10" width="70" height="70" fill="white"/>'
    );
  }

  svg = svg
    .replace(/\s+stroke-(?=[\s/>])/g, '')
    .replace(/\s+stroke-width=["']\d+['"]/g, '')
    .replace(/(<(?:path|ellipse)[^>]*?)(\s*\/>)/g, '$1 stroke-width="0"$2')
    .replace(/\s+/g, ' ');

  return svg;
}

function css() {
  const postcssPlugins = [autoprefixer()];
  if (mode.production()) {
    postcssPlugins.push(cssnano());
  }

  // return src(paths.src, { sourcemaps: mode.development() })
  return src(paths.src, { sourcemaps: true })

    .pipe(glob())
    .pipe(gulpSass({
      includePaths: ['./node_modules'],
      silenceDeprecations: ['legacy-js-api'],
    }).on('error', gulpSass.logError))
    .pipe(postcss(postcssPlugins))
    .pipe(dest(`${paths.dest}/css`, { sourcemaps: '.' }));
    // .pipe(dest(`${paths.dest}/css`, { sourcemaps: mode.development() ? '.' : false }));
}

function watchFiles() {
  watch(paths.src, css);
}

const compile = series(clean, css);

exports.css = css;
exports.default = compile;
exports.watch = series(compile, watchFiles);
