#!/bin/bash

set -e

mkdir -p $HOME/.composer
docker run --rm --interactive --tty   --volume $PWD:/app   --volume ${COMPOSER_HOME:-$HOME/.composer}:/tmp composer install