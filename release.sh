#!/bin/bash

set -eo pipefail

src_image_base=$1
dst_image=$2
other_tag=$3
variants=("allinone" "nginx" "phponly")

function publish_image() {
    src_version="$1"

    for variant in "${variants[@]}"; do
        if [ "$variant" == "allinone" ]; then
            postfix=""
        else
            postfix="-$variant"
        fi

        src_image="$src_image_base-$variant"
        src_tag="${src_version}"
        for version in "$@"; do
            dst_tag="${version}$postfix"
            echo "copy $src_image:$src_tag => $dst_image:$dst_tag"
            skopeo copy "docker://$src_image:$src_tag" "docker://$dst_image:$dst_tag"
        done
    done
}

HUMHUB_VERSION=$(cat versions.txt)
MAJOR_VERSION=$(echo "$HUMHUB_VERSION" | cut -d'.' -f1)
MINOR_VERSION=$(echo "$HUMHUB_VERSION" | cut -d'.' -f2)
HUMHUB_MACRO_VERSION=${MAJOR_VERSION}.${MINOR_VERSION}

# publish the image under the minor-version pattern
publish_image "${HUMHUB_VERSION}" "${HUMHUB_MACRO_VERSION}" ${other_tag}

# ensure a 0 exit code if we get here
exit 0
