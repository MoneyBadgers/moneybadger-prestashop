#!/bin/bash
pushd .

rm -rf dist/moneybadger
mkdir -p dist/moneybadger

cp -r controllers dist/moneybadger
cp -r views dist/moneybadger
cp *.php dist/moneybadger
cp README.md dist/moneybadger
cp LICENSE dist/moneybadger
cp logo.png dist/moneybadger

cd dist
zip -r moneybadger-prestashop.zip moneybadger

popd