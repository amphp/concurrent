#!/usr/bin/env bash

git clone https://github.com/krakjoe/pthreads;
pushd pthreads;
git checkout master;
phpize;
./configure;
make;
make install;
popd;
cp "$(php -r 'echo php_ini_loaded_file();')" /tmp
echo "extension=pthreads.so" >> "$(php -r 'echo php_ini_loaded_file();')"
