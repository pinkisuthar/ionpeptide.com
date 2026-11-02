const path = require('path');
const wpConfig = require('@wordpress/scripts/config/webpack.config');
const WooCommerceDependencyExtractionWebpackPlugin = require('@woocommerce/dependency-extraction-webpack-plugin');

const baseConfig = {
    ...wpConfig,
    plugins: [
        ...wpConfig.plugins.filter((plugin) => plugin.constructor.name !== 'DependencyExtractionWebpackPlugin')
    ]
};

const blocksConfig = {
    ...baseConfig,
    plugins: [
        ...baseConfig.plugins,
        new WooCommerceDependencyExtractionWebpackPlugin({})
    ]
};

const sourcesConfig = {
    core: {
        entries: {
            'pdf-editor/blocks': path.join(__dirname, 'includes/pdf-builder/editor/src/index.js'),
            'pdf-editor/templates': path.join(__dirname, 'includes/pdf-builder/editor/src/pdf-templates/index.js'),
            'wc-blocks/vat-ssn-block/editor': path.join(__dirname, 'includes/blocks/woocommerce/src/vat-ssn/index.js'),
            'wc-blocks/vat-ssn-block/frontend': path.join(__dirname, 'includes/blocks/woocommerce/src/vat-ssn/frontend.js'),
            'wc-blocks/electronic-fields-block/editor': path.join(__dirname, 'includes/blocks/woocommerce/src/electronic-fields/index.js'),
            'wc-blocks/electronic-fields-block/frontend': path.join(__dirname, 'includes/blocks/woocommerce/src/electronic-fields/frontend.js'),
            'wc-blocks/invoice-type-block/editor': path.join(__dirname, 'includes/blocks/woocommerce/src/invoice-type/index.js'),
            'wc-blocks/invoice-type-block/frontend': path.join(__dirname, 'includes/blocks/woocommerce/src/invoice-type/frontend.js'),
            'wc-blocks/document-type-block/editor': path.join(__dirname, 'includes/blocks/woocommerce/src/document-type/index.js'),
            'wc-blocks/document-type-block/frontend': path.join(__dirname, 'includes/blocks/woocommerce/src/document-type/frontend.js')
        },
        config: {
            ...blocksConfig,
            output: {
                filename: "./[name]/index.js",
                path: path.join(__dirname, 'assets/js/dist')
            }
        }
    }
}

module.exports = Object.keys(sourcesConfig).map(configName => {
    const data = sourcesConfig[configName];
    const {config, entries} = data;

    return {
        ...config,
        entry: entries
    }
});
