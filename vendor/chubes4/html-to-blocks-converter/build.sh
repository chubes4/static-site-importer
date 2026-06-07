#!/bin/bash

set -e

PLUGIN_SLUG="html-to-blocks-converter"
BUILD_DIR="build"
PLUGIN_DIR="${BUILD_DIR}/${PLUGIN_SLUG}"

echo "Building ${PLUGIN_SLUG}..."

rm -rf "${BUILD_DIR}"
mkdir -p "${PLUGIN_DIR}"

cp -r includes "${PLUGIN_DIR}/"
cp composer.json "${PLUGIN_DIR}/"
cp html-to-blocks-converter.php "${PLUGIN_DIR}/"
cp library.php "${PLUGIN_DIR}/"
cp raw-handler.php "${PLUGIN_DIR}/"

cd "${BUILD_DIR}"
zip -r "${PLUGIN_SLUG}.zip" "${PLUGIN_SLUG}"
rm -rf "${PLUGIN_SLUG}"
cd ..

echo "Build complete: ${BUILD_DIR}/${PLUGIN_SLUG}.zip"
