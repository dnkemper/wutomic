// For conditionally checking for the environment property, see: https://github.com/postcss/postcss-cli
module.exports = (ctx) => ({
  plugins: {
    autoprefixer: {},
    cssnano: ctx.env === 'minifycss' ? { preset: 'default', } : false,
  },
});
