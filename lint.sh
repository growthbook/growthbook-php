#!/bin/bash

set -e

docker run --rm --interactive --tty   --volume $PWD:/app   --volume ${COMPOSER_HOME:-$HOME/.composer}:/tmp composer run lint