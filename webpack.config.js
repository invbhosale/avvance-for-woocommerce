// webpack.config.js
const { merge } = require('webpack-merge');
const base = require('@wordpress/scripts/config/webpack.config');

module.exports = merge(base, {
  externals: {
    // Map the ESM import to the browser global provided by Woo.
    '@woocommerce/blocks-registry': ['wc', 'wcBlocksRegistry'],
  },
});
